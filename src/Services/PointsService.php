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
     * Take back the points a purchase awarded, once the money has gone back.
     *
     * ── WHY IT CLAWS BACK WHAT IT CAN RATHER THAN ALL OR NOTHING ─────────────
     *
     * {@see award()} refuses a delta that would take a balance negative, which is right for a
     * spend and wrong here: somebody who earned 50 points on an order, redeemed 40 of them for
     * a vote and then charged the order back would otherwise keep all 50, because the full
     * reversal cannot apply. Reversing the 10 that are still there is strictly better than
     * reversing nothing, and the shortfall is a fact for a person rather than a reason to do
     * nothing.
     *
     * Idempotent per reference: a second reversal for the same order writes no second entry,
     * so a duplicate `refund.processed` delivery cannot claw the same points twice.
     *
     * @return int points actually taken back (0 when there were none, or none left)
     */
    public static function reverseFromPurchase(int $userId, string $refType, string $refId): int
    {
        if ($userId < 1) return 0;
        try {
            $awarded = (int) (DB::table('gates_points_ledger')
                ->where('user_id', $userId)->where('ref_type', $refType)
                ->where('ref_id', (string) $refId)->where('delta', '>', 0)->sum('delta'));
            if ($awarded < 1) return 0;

            $taken = (int) abs((int) DB::table('gates_points_ledger')
                ->where('user_id', $userId)->where('ref_type', $refType)
                ->where('ref_id', (string) $refId)->where('delta', '<', 0)->sum('delta'));
            $owing = $awarded - $taken;
            if ($owing < 1) return 0;                       // already reversed

            // Only as far as the balance allows — see the note above.
            $take = min($owing, self::balance($userId));
            if ($take < 1) return 0;

            return self::award($userId, -$take, 'reverse.' . $refType, $refType, $refId,
                               'payment reversed') !== null ? $take : 0;
        } catch (\Throwable) {
            return 0;
        }
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
                if (!$cycle) {
                    return ['ok' => false, 'message' => 'Voting is not open for this category right now.'];
                }
                // Same COMPUTED-phase gate as an organic vote — the stored
                // status column is a cache, never the authority.
                try {
                    BallotGuard::assertVotable((int) $nom->category_id);
                } catch (PhaseError $e) {
                    return ['ok' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode];
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

    /**
     * The balance over time, as one point per day — for the chart on the account page.
     *
     * ── WHY THE SERIES IS BUILT HERE AND NOT IN THE TEMPLATE ─────────────────
     *
     * A chart drawn from `ledger()` in Twig would be drawn from the LAST 30 ROWS, which is
     * not a time series — it is however many events happened to fit, so a member with three
     * years of quiet history and one busy week gets a chart of that week labelled as their
     * account. The window has to be a window of TIME.
     *
     * ── AND WHY EVERY DAY IS PRESENT, INCLUDING THE EMPTY ONES ───────────────
     *
     * A balance is a running total: on a day with no transaction it is still whatever it was
     * yesterday. Plotting only the days that have rows spaces them evenly along the x-axis,
     * which draws a steady climb where there was one purchase and then eleven months of
     * nothing. So the gaps are filled forward, and the line is flat where nothing happened —
     * because nothing happening is the fact.
     *
     * The opening balance is derived by walking BACKWARDS from today's balance through the
     * deltas inside the window, rather than summing history: `balance_after` on the oldest
     * row in the window already includes everything before it, and re-summing a ledger is how
     * a rounding or a reversal quietly disagrees with the number printed beside it.
     *
     * @return list<array{date: string, balance: int, delta: int}> oldest first; [] when the
     *         member has no history at all
     */
    public static function series(int $userId, int $days = 90): array
    {
        $days = max(2, min(365, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        try {
            $rows = DB::table('gates_points_ledger')
                ->where('user_id', $userId)
                ->where('created_at', '>=', $from . ' 00:00:00')
                ->orderBy('id')
                ->get(['delta', 'balance_after', 'created_at'])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }

        $balance = self::balance($userId);
        if ($rows === [] ) {
            // Nothing moved in the window. A flat line at today's balance is still true, and
            // it is only worth drawing if there is a balance to be flat at — a member with
            // nothing gets the empty state instead of a chart of zero.
            if ($balance === 0) return [];
            return [
                ['date' => $from, 'balance' => $balance, 'delta' => 0],
                ['date' => date('Y-m-d'), 'balance' => $balance, 'delta' => 0],
            ];
        }

        // Closing balance and net movement for each day that actually has rows.
        $close = [];
        $moved = [];
        foreach ($rows as $r) {
            $d = substr((string) $r['created_at'], 0, 10);
            $close[$d] = (int) $r['balance_after'];
            $moved[$d] = ($moved[$d] ?? 0) + (int) $r['delta'];
        }

        // Where the window opened: today's balance, less everything that has moved inside it.
        $opening = $balance;
        foreach ($moved as $delta) { $opening -= $delta; }

        $out  = [];
        $held = $opening;
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
            if (isset($close[$d])) $held = $close[$d];
            $out[] = ['date' => $d, 'balance' => $held, 'delta' => (int) ($moved[$d] ?? 0)];
        }
        return $out;
    }

    private static function setting(string $key, ?string $default = null): ?string
    {
        try { $v = DB::table('gates_settings')->where('key_name', $key)->value('value'); }
        catch (\Throwable $e) { return $default; }
        return $v !== null ? (string) $v : $default;
    }
}
