<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Fraud risk-scoring engine.
 *
 * Signals are additive; the final score 0–100 maps to a decision:
 *   0–29  → allow      (green)
 *  30–59  → monitor    (yellow — vote recorded but flagged for review)
 *  60–79  → flag       (orange — admin alert)
 *  80–100 → block      (red — vote rejected before casting)
 *
 * All scoring is non-blocking unless the decision is 'block'. The goal is
 * to surface suspicious patterns for human review, not to refuse legitimate
 * voters who happen to share a network or device.
 *
 * SCOPE: this is a best-effort PRE-CAST screen, scored from already-committed
 * rows, so a simultaneous burst can race past it (each request sees pre-burst
 * counts). The hard guarantees live elsewhere — UNIQUE(voter_email_hash,
 * category_id) makes double-voting impossible, and CollusionService is the
 * authoritative POST-HOC detector that scans committed votes for rings the
 * live screen couldn't see. Treat a 'block' here as early rejection, not the
 * last line of defence.
 */
class FraudService
{
    // Score bands → decision (see class docblock). 'block' rejects before casting.
    private const THRESHOLDS = [
        'monitor' => 30,   // 30–59: recorded, passively watched
        'flag'    => 60,   // 60–79: recorded + surfaced in the admin review queue
        'block'   => 80,   // 80–100: rejected before the vote is cast
    ];

    // Per-signal risk contributions. (Removed three constants that were declared
    // but never computed — they only created the illusion of coverage.)
    private const SIGNALS = [
        'same_device_voted_category'  => 50,
        'many_categories_one_hour'    => 25,
        'rapid_otp_requests'          => 20,
        'high_ip_vote_density'        => 15,
        'vote_burst_pattern'          => 30,
        'missing_device'              => 25,
    ];

    public function __construct(
        private readonly ?LoggerInterface $log = null,
    ) {}

    /**
     * Score an incoming vote attempt before it is cast.
     * Returns ['score' => int, 'decision' => string, 'signals' => array].
     */
    public function scoreVoteAttempt(
        string $emailHash,
        string $ipHash,
        ?string $deviceHash,
        int    $nomineeId,
        int    $categoryId,
    ): array {
        $score   = 0;
        $signals = [];

        // 1. Same device already voted in THIS category today
        if ($deviceHash) {
            $deviceCatVotes = DB::table('gates_votes')
                ->where('device_hash', $deviceHash)
                ->where('category_id', $categoryId)
                ->where('voted_at', '>=', Carbon::now()->subDay()->toDateTimeString())
                ->count();
            if ($deviceCatVotes > 0) {
                $score += self::SIGNALS['same_device_voted_category'];
                $signals[] = "device_already_voted_category:{$deviceCatVotes}";
            }

            // 2. Same device voted many categories in last hour
            $deviceHourVotes = DB::table('gates_votes')
                ->where('device_hash', $deviceHash)
                ->where('voted_at', '>=', Carbon::now()->subHour()->toDateTimeString())
                ->count();
            if ($deviceHourVotes >= 3) {
                $score += self::SIGNALS['many_categories_one_hour'];
                $signals[] = "device_hour_votes:{$deviceHourVotes}";
            }
        } else {
            // No device fingerprint: a real browser produces one, so its absence is
            // a mild signal of an automated/hardened client.
            $score += self::SIGNALS['missing_device'];
            $signals[] = 'missing_device';

            // Dropping the device hash must NOT erase detection. Fall back to IP for
            // the same two concentration signals, but at CGNAT-tolerant thresholds
            // (>=5, vs >0 for a device) so a busy shared mobile/carrier IP with a few
            // legitimate voters isn't blocked — only a genuine single-IP ring is.
            $ipCatVotes = DB::table('gates_votes')
                ->where('ip_hash', $ipHash)
                ->where('category_id', $categoryId)
                ->where('voted_at', '>=', Carbon::now()->subDay()->toDateTimeString())
                ->count();
            if ($ipCatVotes >= 5) {
                $score += self::SIGNALS['same_device_voted_category']; // 50 — IP stands in for device
                $signals[] = "ip_already_voted_category:{$ipCatVotes}";
            }

            $ipHourVotes = DB::table('gates_votes')
                ->where('ip_hash', $ipHash)
                ->where('voted_at', '>=', Carbon::now()->subHour()->toDateTimeString())
                ->count();
            if ($ipHourVotes >= 5) {
                $score += self::SIGNALS['many_categories_one_hour']; // 25
                $signals[] = "ip_hour_votes:{$ipHourVotes}";
            }
        }

        // 3. IP vote density (many different emails from same IP in 24h)
        $ipVotes = DB::table('gates_votes')
            ->where('ip_hash', $ipHash)
            ->where('voted_at', '>=', Carbon::now()->subDay()->toDateTimeString())
            ->count();
        if ($ipVotes >= 10) {
            $score += self::SIGNALS['high_ip_vote_density'];
            $signals[] = "ip_daily_votes:{$ipVotes}";
        }
        if ($ipVotes >= 25) {
            $score += self::SIGNALS['vote_burst_pattern'];
            $signals[] = "ip_burst_pattern";
        }

        // 4. Many OTP requests from same email in last 10 minutes
        $otpBursts = DB::table('gates_otp_tokens')
            ->where('email_hash', $emailHash)
            ->where('purpose', 'vote')
            ->where('created_at', '>=', Carbon::now()->subMinutes(10)->toDateTimeString())
            ->count();
        if ($otpBursts >= 3) {
            $score += self::SIGNALS['rapid_otp_requests'];
            $signals[] = "otp_burst:{$otpBursts}";
        }

        $score   = min(100, $score);
        $decision = $this->decide($score);

        $this->log?->info('[fraud] scored', [
            'score'    => $score,
            'decision' => $decision,
            'signals'  => $signals,
        ]);

        return ['score' => $score, 'decision' => $decision, 'signals' => $signals];
    }

    /**
     * Persist the fraud score alongside the vote record.
     */
    public function record(
        ?int   $voteId,
        string $emailHash,
        string $ipHash,
        ?string $deviceHash,
        int    $score,
        string $decision,
        array  $signals,
    ): void {
        try {
            DB::table('gates_fraud_scores')->insert([
                'vote_id'     => $voteId,
                'email_hash'  => $emailHash,
                'ip_hash'     => $ipHash,
                'device_hash' => $deviceHash,
                'risk_score'  => $score,
                'signals'     => json_encode($signals),
                'decision'    => $decision,
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
            // Also stamp the vote row itself
            if ($voteId) {
                DB::table('gates_votes')->where('id', $voteId)->update([
                    'risk_score' => $score,
                    'fraud_flag' => ($score >= 60) ? 1 : 0,
                ]);
            }
        } catch (\Throwable $e) {
            $this->log?->error('[fraud] record failed: ' . $e->getMessage());
        }
    }

    /**
     * Everything the vote-fraud panel shows.
     *
     * ── THIS WAS DEAD CODE ───────────────────────────────────────────────────
     *
     * Written for an admin panel that was never built. Every vote on this platform has
     * been scored and stamped since fraud detection shipped, and the only place an
     * operator could see any of it was a raw table dump in the data registry. The class
     * docblock above still promises that 60–79 is "recorded + surfaced in the admin review
     * queue"; there was no queue.
     *
     * That is the gap, not the scoring. Collusion, judge anomalies and judge bias each have
     * a panel on the integrity page. Vote fraud — the oldest of the four and the first one
     * that page's own docblock lists — did not.
     */
    public function summary(): array
    {
        try {
            return [
                'flagged_today'  => DB::table('gates_fraud_scores')
                    ->where('decision', 'flag')
                    ->where('created_at', '>=', Carbon::now()->subDay())->count(),
                'blocked_today'  => DB::table('gates_fraud_scores')
                    ->where('decision', 'block')
                    ->where('created_at', '>=', Carbon::now()->subDay())->count(),
                'unreviewed'     => DB::table('gates_fraud_scores')
                    ->whereIn('decision', ['flag', 'block'])->where('reviewed', 0)->count(),
                'avg_score_24h'  => round((float)DB::table('gates_fraud_scores')
                    ->where('created_at', '>=', Carbon::now()->subDay())
                    ->avg('risk_score') ?? 0, 1),
                // Scored addresses, not busy ones. Without the decision filter this was
                // the five networks that voted most in a day — a university, an office,
                // a phone carrier's NAT — presented under a fraud heading.
                'top_ip_hashes'  => DB::table('gates_fraud_scores')
                    ->select('ip_hash', DB::raw('COUNT(*) as hits'))
                    ->where('created_at', '>=', Carbon::now()->subDay())
                    ->whereIn('decision', ['monitor', 'flag', 'block'])
                    ->groupBy('ip_hash')->orderByDesc('hits')->limit(5)->get()->toArray(),
                // LEFT JOIN, and it is the whole difference between this list being right
                // and being a lie. A BLOCKED attempt is rejected BEFORE the vote is cast,
                // so it has no vote row and `vote_id` is NULL — an inner join dropped every
                // one of them. The panel would have counted blocks in one number and shown
                // none of them in the list underneath, which reads as "we stopped five
                // things and cannot tell you what".
                'recent_flags'   => DB::table('gates_fraud_scores AS f')
                    ->leftJoin('gates_votes AS v', 'v.id', '=', 'f.vote_id')
                    ->leftJoin('gates_nominees AS n', 'n.id', '=', 'v.nominee_id')
                    ->select('f.*', 'n.name AS nominee_name', 'v.voted_at')
                    ->whereIn('f.decision', ['flag', 'block'])
                    ->orderByDesc('f.created_at')->limit(10)->get()->toArray(),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Mark scored attempts as looked at.
     *
     * The missing write. `reviewed` was read in exactly one place — the `unreviewed`
     * counter above — and set by nothing anywhere, so the queue could only ever grow and
     * the registry's "Reviewed" column was false on every row that had ever existed.
     *
     * Marking is NOT a verdict on the vote. A reviewed flag stays flagged, its risk score
     * is unchanged, and the vote it belongs to is untouched: this records that a person
     * looked, which is the only thing an unreviewed count can honestly mean. Withdrawing
     * a vote is a different act with a different audit trail
     * ({@see \AfricaGates\Services\BonusVoteService::clawback()} for the shape of one).
     *
     * @param  list<int> $ids
     * @return int how many rows this call actually changed
     */
    public function markReviewed(array $ids, int $adminId = 0): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) return 0;

        try {
            // `where reviewed = 0` so the count returned is what THIS call changed rather
            // than how many rows were named — two operators clearing the same queue should
            // not both be told they reviewed twelve things.
            $n = DB::table('gates_fraud_scores')
                ->whereIn('id', $ids)->where('reviewed', 0)
                ->update(['reviewed' => 1]);
        } catch (\Throwable $e) {
            $this->log?->error('[fraud] mark reviewed failed: ' . $e->getMessage());
            return 0;
        }

        if ($n > 0) {
            try {
                (new \AfricaGates\Admin\Services\AuditService())->record(
                    $adminId, 'fraud.reviewed', 'fraud_score', $ids[0] ?? 0,
                    ['ids' => $ids, 'marked' => $n]
                );
            } catch (\Throwable) { /* the review is recorded; the audit row is bookkeeping */ }
        }

        return (int) $n;
    }

    private function decide(int $score): string
    {
        if ($score >= self::THRESHOLDS['block'])   return 'block';
        if ($score >= self::THRESHOLDS['flag'])    return 'flag';
        if ($score >= self::THRESHOLDS['monitor']) return 'monitor';
        return 'allow';
    }
}
