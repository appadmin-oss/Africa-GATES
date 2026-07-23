<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Voting-points economy. A signed-delta ledger (gates_points_ledger) is the source
 * of truth; gates_users.points is a cached running balance kept in lock-step inside
 * a transaction. Static (like {@see WebhookService}) so purchase hooks can award
 * points without DI.
 *
 * Two admin-configurable conversion rates (gates_settings):
 *   • points_per_1000_naira — points EARNED per ₦1,000 spent (money → points)
 *   • points_per_vote       — points needed to REDEEM one vote (points → votes)
 * Gated by `points_enabled` (off by default). Redemption is wired to the
 * CPI-excluded bonus-vote mechanism elsewhere, so points can never move judged rank.
 */
final class PointsService
{
    public const DEFAULT_PER_1000 = 50;   // 50 pts per ₦1,000 spent
    public const DEFAULT_PER_VOTE = 500;  // 500 pts = 1 vote

    public static function enabled(): bool
    {
        return self::setting('points_enabled') === '1';
    }

    /** Points earned per ₦1,000 spent. */
    public static function earnPer1000(): int
    {
        return max(0, (int) self::setting('points_per_1000_naira', (string) self::DEFAULT_PER_1000));
    }

    /** Points required to redeem one vote (never below 1). */
    public static function pointsPerVote(): int
    {
        return max(1, (int) self::setting('points_per_vote', (string) self::DEFAULT_PER_VOTE));
    }

    /** Points a ₦ purchase earns (floored). */
    public static function pointsForSpend(int $naira): int
    {
        return $naira > 0 ? (int) floor($naira / 1000 * self::earnPer1000()) : 0;
    }

    /** Whole votes $points can buy. */
    public static function votesForPoints(int $points): int
    {
        return (int) floor($points / self::pointsPerVote());
    }

    public static function balance(int $userId): int
    {
        try { return (int) (DB::table('gates_users')->where('id', $userId)->value('points') ?? 0); }
        catch (\Throwable $e) { return 0; }
    }

    /**
     * Apply a signed points change atomically: lock the user row, write the ledger
     * entry with the resulting balance, and update the cached balance. Returns the
     * new balance, or null on failure / insufficient balance (a spend that would go
     * negative is rejected, never partially applied).
     */
    public static function award(int $userId, int $delta, string $reason, ?string $refType = null, ?string $refId = null, ?string $note = null): ?int
    {
        if ($delta === 0) return self::balance($userId);
        try {
            return DB::transaction(function () use ($userId, $delta, $reason, $refType, $refId, $note) {
                $u = DB::table('gates_users')->where('id', $userId)->lockForUpdate()->first();
                if (!$u) return null;
                $newBal = (int) $u->points + $delta;
                if ($newBal < 0) return null; // insufficient balance for this spend
                DB::table('gates_users')->where('id', $userId)->update(['points' => $newBal]);
                DB::table('gates_points_ledger')->insert([
                    'user_id'      => $userId,
                    'delta'        => $delta,
                    'reason'       => $reason,
                    'ref_type'     => $refType,
                    'ref_id'       => $refId !== null ? (string) $refId : null,
                    'balance_after'=> $newBal,
                    'note'         => $note !== null ? mb_substr($note, 0, 200) : null,
                    'created_at'   => Carbon::now()->toDateTimeString(),
                ]);
                return $newBal;
            });
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Spend points (positive amount). True on success, false if balance is short. */
    public static function spend(int $userId, int $points, string $reason, ?string $refType = null, ?string $refId = null, ?string $note = null): bool
    {
        $points = abs($points);
        if ($points === 0) return true;
        return self::award($userId, -$points, $reason, $refType, $refId, $note) !== null;
    }

    /** Award points for a paid purchase (idempotent per ref). Returns points granted. */
    public static function earnFromPurchase(int $userId, int $naira, string $refType, string $refId): int
    {
        if (!self::enabled() || $userId < 1) return 0;
        // Idempotency: never double-credit the same order/ticket.
        try {
            $already = DB::table('gates_points_ledger')
                ->where('ref_type', $refType)->where('ref_id', (string) $refId)->where('delta', '>', 0)->exists();
            if ($already) return 0;
        } catch (\Throwable $e) { return 0; }
        $pts = self::pointsForSpend($naira);
        if ($pts < 1) return 0;
        return self::award($userId, $pts, 'earn.' . $refType, $refType, $refId, '₦' . number_format($naira) . ' purchase') !== null ? $pts : 0;
    }

    /**
     * Redeem points for ONE vote on a nominee. Atomic: spends `points_per_vote`
     * points (ledger + balance) and mints a CPI-EXCLUDED bonus vote (vote_type
     * 'bonus', weight 1) that bumps only the public tally — never organic_vote_count,
     * so redeemed votes can't move judged/CPI rank. Mirrors BonusVoteService's
     * guards (approved nominee, cycle in 'voting', shared paid-weight cap).
     *
     * @return array{ok:bool, message:string, new_balance?:int}
     */
    public static function redeemForVote(int $userId, int $nomineeId): array
    {
        if (!self::enabled())                  return ['ok' => false, 'message' => 'Voting points are not active right now.'];
        if ($userId < 1 || $nomineeId < 1)     return ['ok' => false, 'message' => 'Invalid request.'];
        $cost = self::pointsPerVote();
        try {
            return DB::transaction(function () use ($userId, $nomineeId, $cost) {
                $u = DB::table('gates_users')->where('id', $userId)->lockForUpdate()->first();
                if (!$u) return ['ok' => false, 'message' => 'Account not found.'];
                if ((int) $u->points < $cost) {
                    return ['ok' => false, 'message' => 'You need ' . number_format($cost) . ' points to redeem a vote — you have ' . number_format((int) $u->points) . '.'];
                }
                $nom = MergeService::notMerged(DB::table('gates_nominees')->where('id', $nomineeId)->where('status', 'approved'))->first();
                if (!$nom) return ['ok' => false, 'message' => 'That nominee is not open for voting.'];

                $cycle = DB::table('gates_award_cycles AS cy')
                    ->join('gates_award_categories AS c', 'c.cycle_id', '=', 'cy.id')
                    ->where('c.id', $nom->category_id)->select('cy.status', 'cy.id', 'cy.programme_id')->first();
                if (!$cycle || $cycle->status !== 'voting') {
                    return ['ok' => false, 'message' => 'Voting is not open for this category right now.'];
                }

                // Shared paid-weight cap (RuleEngine) so points can't swamp the community signal.
                $pct = (int) ((new RuleEngine())->effective((int) $cycle->programme_id, (int) $cycle->id)['max_paid_weight_pct'] ?? 50);
                $bonusSoFar = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)->where('vote_type', 'bonus')->sum('weight');
                $cap = max(10, (int) floor((int) $nom->organic_vote_count * $pct / 100));
                if ($bonusSoFar + 1 > $cap) {
                    return ['ok' => false, 'message' => 'Redeemed votes for this nominee are capped right now (protecting the community signal).'];
                }

                // Spend points.
                $newBal = (int) $u->points - $cost;
                DB::table('gates_users')->where('id', $userId)->update(['points' => $newBal]);
                DB::table('gates_points_ledger')->insert([
                    'user_id' => $userId, 'delta' => -$cost, 'reason' => 'spend.vote',
                    'ref_type' => 'nominee', 'ref_id' => (string) $nomineeId, 'balance_after' => $newBal,
                    'note' => 'Redeemed for 1 vote', 'created_at' => Carbon::now()->toDateTimeString(),
                ]);
                // Mint the CPI-excluded vote + bump the visible tally only.
                DB::table('gates_votes')->insert([
                    'nominee_id'       => $nomineeId,
                    'category_id'      => (int) $nom->category_id,
                    'voter_email_hash' => 'points:' . $userId . ':' . bin2hex(random_bytes(6)),
                    'nominee_country'  => $nom->country_code ?? null,
                    'vote_type'        => 'bonus',
                    'weight'           => 1,
                    'voted_at'         => Carbon::now()->toDateTimeString(),
                ]);
                DB::table('gates_nominees')->where('id', $nomineeId)->increment('vote_count', 1);
                return ['ok' => true, 'message' => 'Vote added — thank you for backing them!', 'new_balance' => $newBal];
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not redeem right now. Please try again.'];
        }
    }

    /** Lifetime totals for the account dashboard: earned, spent, current balance. */
    public static function summary(int $userId): array
    {
        try {
            $earned = (int) DB::table('gates_points_ledger')->where('user_id', $userId)->where('delta', '>', 0)->sum('delta');
            $spent  = (int) DB::table('gates_points_ledger')->where('user_id', $userId)->where('delta', '<', 0)->sum('delta');
            return ['earned' => $earned, 'spent' => abs($spent), 'balance' => self::balance($userId)];
        } catch (\Throwable $e) {
            return ['earned' => 0, 'spent' => 0, 'balance' => self::balance($userId)];
        }
    }

    /** Recent ledger entries, newest first. */
    public static function ledger(int $userId, int $limit = 25): array
    {
        try {
            return DB::table('gates_points_ledger')->where('user_id', $userId)
                ->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) { return []; }
    }

    private static function setting(string $key, ?string $default = null): ?string
    {
        try { $v = DB::table('gates_settings')->where('key_name', $key)->value('value'); }
        catch (\Throwable $e) { return $default; }
        return $v !== null ? (string) $v : $default;
    }
}
