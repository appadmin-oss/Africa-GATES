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
            // a mild signal of an automated/hardened client. This keeps the 'block'
            // gate reachable for a device-less ring instead of collapsing to <=65
            // just because the client omitted device_hash.
            $score += self::SIGNALS['missing_device'];
            $signals[] = 'missing_device';
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
     * Dashboard summary for the admin fraud panel.
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
                'top_ip_hashes'  => DB::table('gates_fraud_scores')
                    ->select('ip_hash', DB::raw('COUNT(*) as hits'))
                    ->where('created_at', '>=', Carbon::now()->subDay())
                    ->groupBy('ip_hash')->orderByDesc('hits')->limit(5)->get()->toArray(),
                'recent_flags'   => DB::table('gates_fraud_scores AS f')
                    ->join('gates_votes AS v', 'v.id', '=', 'f.vote_id')
                    ->join('gates_nominees AS n', 'n.id', '=', 'v.nominee_id')
                    ->select('f.*', 'n.name AS nominee_name', 'v.voted_at')
                    ->whereIn('f.decision', ['flag', 'block'])
                    ->orderByDesc('f.created_at')->limit(10)->get()->toArray(),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function decide(int $score): string
    {
        if ($score >= self::THRESHOLDS['block'])   return 'block';
        if ($score >= self::THRESHOLDS['flag'])    return 'flag';
        if ($score >= self::THRESHOLDS['monitor']) return 'monitor';
        return 'allow';
    }
}
