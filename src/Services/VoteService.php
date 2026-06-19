<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Vote casting — production-grade integrity guarantees:
 *  • Atomic transaction with row-level lock on the OTP token.
 *  • The OTP is only consumed on a SUCCESSFUL vote, so a transient failure
 *    (nominee unapproved, duplicate vote) never burns the user's code.
 *  • Per-token attempt cap to blunt brute force, on top of the IP rate-limit.
 *  • One vote per (email, category), enforced both in code and by a DB UNIQUE.
 */
class VoteService {
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly ?LoggerInterface $log = null) {}

    public function castVote(string $email, string $otp, int $nomineeId, int $awardId, string $ip = '', ?string $deviceHash = null, ?string $idempotencyKey = null): array {
        $eh = hash('sha256', strtolower(trim($email)));
        $th = hash('sha256', trim($otp));
        $ipHash = $ip !== '' ? hash('sha256', $ip) : null;

        // Idempotent replay: a retry carrying the same key returns the ORIGINAL
        // outcome rather than a confusing ALREADY_VOTED / INVALID_OTP (the first
        // attempt already consumed the code).
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            // Scope the replay to THIS voter: an attacker reusing someone else's
            // key must not be handed that voter's result — treat it as a new attempt.
            $prior = DB::table('gates_votes')->where('idempotency_key', $idempotencyKey)->where('voter_email_hash', $eh)->first();
            if ($prior) {
                return [
                    'success' => true, 'code' => 'VOTE_CAST', 'message' => 'Vote already recorded.',
                    'vote_id' => (int) $prior->id, 'category_id' => (int) $prior->category_id,
                    'new_count' => (int) DB::table('gates_nominees')->where('id', $prior->nominee_id)->value('vote_count'),
                    'new_rank' => null, 'days_left' => 0,
                ];
            }
        }

        $result = DB::transaction(function () use ($eh, $th, $nomineeId, $awardId, $ipHash, $deviceHash, $idempotencyKey) {
            // Latest live token for this email; lock it for the duration.
            $token = DB::table('gates_otp_tokens')
                ->where('email_hash', $eh)->where('purpose', 'vote')->where('is_used', 0)
                ->where('expires_at', '>', Carbon::now())
                ->orderBy('id', 'desc')->lockForUpdate()->first();

            if (!$token) return ['success' => false, 'code' => 'INVALID_OTP', 'message' => 'Invalid or expired code.'];

            // Count every verification attempt; cap brute force per token.
            DB::table('gates_otp_tokens')->where('id', $token->id)->increment('attempts');
            if (($token->attempts + 1) > self::MAX_ATTEMPTS) {
                DB::table('gates_otp_tokens')->where('id', $token->id)->update(['is_used' => 1]);
                return ['success' => false, 'code' => 'TOO_MANY_ATTEMPTS', 'message' => 'Too many attempts. Request a new code.'];
            }

            // Wrong code: do NOT consume the token (lets the real user retry).
            if (!hash_equals((string)$token->token_hash, $th)) {
                return ['success' => false, 'code' => 'INVALID_OTP', 'message' => 'Invalid or expired code.'];
            }

            // SECURITY: OTP must be bound to the exact nominee and award it was
            // issued for. Prevents requesting a code for Nominee A then voting Nominee B.
            if ((int)$token->nominee_id !== $nomineeId || (int)$token->award_id !== $awardId) {
                DB::table('gates_otp_tokens')->where('id', $token->id)->update(['is_used' => 1]);
                return ['success' => false, 'code' => 'NOMINEE_MISMATCH',
                        'message' => 'This code was issued for a different nominee. Please request a new code.'];
            }

            $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->where('status', 'approved')->first();
            if (!$nominee) return ['success' => false, 'code' => 'INVALID_NOMINEE', 'message' => 'Nominee not found.'];

            if (DB::table('gates_votes')->where('voter_email_hash', $eh)->where('category_id', $nominee->category_id)->exists()) {
                return ['success' => false, 'code' => 'ALREADY_VOTED', 'message' => 'You have already voted in this category.'];
            }

            // Voting must be OPEN for this nominee's cycle. Without this gate an
            // approved nominee is votable during nominations/judging/results too.
            $cycle = DB::table('gates_award_cycles AS cy')
                ->join('gates_award_categories AS c', 'c.cycle_id', '=', 'cy.id')
                ->where('c.id', $nominee->category_id)
                ->select('cy.status', 'cy.voting_close')->first();
            if (!$cycle || $cycle->status !== 'voting') {
                return ['success' => false, 'code' => 'VOTING_CLOSED',
                        'message' => 'Voting is not open for this category right now.'];
            }

            // All checks passed — record the vote and consume the code atomically.
            try {
                DB::table('gates_votes')->insert([
                    'nominee_id'       => $nomineeId,
                    'category_id'      => $nominee->category_id,
                    'voter_email_hash' => $eh,
                    'otp_token_id'     => $token->id,
                    'nominee_country'  => $nominee->country_code ?? null,
                    'ip_hash'          => $ipHash,
                    'device_hash'      => $deviceHash,
                    'idempotency_key'  => $idempotencyKey,
                    'voted_at'         => Carbon::now()->toDateTimeString(),
                ]);
            } catch (\Throwable $e) {
                return ['success' => false, 'code' => 'ALREADY_VOTED', 'message' => 'You have already voted in this category.'];
            }
            DB::table('gates_otp_tokens')->where('id', $token->id)->update(['is_used' => 1]);
            DB::table('gates_nominees')->where('id', $nomineeId)->increment('vote_count');

            $voteId   = DB::table('gates_votes')->where('voter_email_hash', $eh)->where('category_id', $nominee->category_id)->value('id');
            $newCount = (int)DB::table('gates_nominees')->where('id', $nomineeId)->value('vote_count');

            $newRank = (int)DB::table('gates_nominees')
                ->where('category_id', $nominee->category_id)
                ->where('status', 'approved')
                ->where('vote_count', '>', $newCount)
                ->count() + 1;

            $daysLeft = 0;
            if (!empty($cycle->voting_close)) {
                $daysLeft = max(0, (int)ceil((strtotime($cycle->voting_close) - time()) / 86400));
            }

            return [
                'success'     => true, 'code' => 'VOTE_CAST', 'message' => 'Vote recorded.',
                'vote_id'     => (int)$voteId,
                'category_id' => (int)$nominee->category_id,
                'new_count'   => $newCount,
                'new_rank'    => $newRank,
                'days_left'   => $daysLeft,
            ];
        });

        if ($result['success']) {
            $this->log?->info('[vote] cast', ['nominee' => $nomineeId, 'category' => $result['category_id'] ?? null]);
        } else {
            $this->log?->info('[vote] rejected', ['code' => $result['code'], 'nominee' => $nomineeId]);
        }
        return $result;
    }
}
