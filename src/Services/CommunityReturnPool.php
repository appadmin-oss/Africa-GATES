<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The target pool: the community return as the owner set it out (30 Oct 2026).
 *
 * ── THE RULE ─────────────────────────────────────────────────────────────────
 *
 *   · Every approved nominee in the edition has the SAME vote target
 *     (`community_return_target_votes`). Approved is enough to be counted.
 *   · The edition's share of every eligible contribution (`community_return_bps`) goes into
 *     ONE POOL. Each accrual is still written against the nominee whose supporters paid it,
 *     held LOCKED, so the ledger keeps saying where every kobo came from.
 *   · Members see one target for the whole edition — the target × the approved nominees —
 *     the votes so far against it, and the gap.
 *   · When voting has closed somebody SETTLES the pool. The nominees who reached the target
 *     share it BY THEIR VOTES, not equally; one alone takes all of it. Nobody reached it:
 *     no return, and the pool goes to the reserves.
 *   · Withdrawing needs a verified account (CommunityReturnWithdrawals::checks()).
 *
 * ── WHICH VOTES ──────────────────────────────────────────────────────────────
 *
 * Free and paid, both (owner). Counted from the vote ledger, weight by weight, never the
 * denormalised `vote_count`; fraud-flagged rows count for nothing; `bonus` rows (operator
 * grants and points spent) are not support the nominee gathered and do not count either.
 *
 * ── SETTLING IS ONE ACT, ON THE SAME LEDGER ──────────────────────────────────
 *
 * Settling writes adjustments, never edits: each nominee whose supporters fed the pool gives
 * their locked credit back to it ("Into the edition pool"), and each winner is credited their
 * share ("Share of the edition pool"). So every nominee's balance in the edition is exactly
 * their share, the cycle nets to what was paid out, and the reserves' part is recorded on the
 * settlement row. The split is by largest remainder: the shares add up to the pool to the kobo.
 * After settling, a refund is the platform's to absorb — it is never taken back off a winner
 * for a contribution made to somebody else.
 */
final class CommunityReturnPool
{
    /** The votes that count, by nominee, for every approved nominee of an edition. @return array<int,int> */
    public static function votes(int $cycleId): array
    {
        $out = [];
        foreach (self::nominees($cycleId) as $id => $_) $out[$id] = 0;
        if ($out === []) return [];
        try {
            foreach (DB::table('gates_votes')->whereIn('nominee_id', array_keys($out))->where('fraud_flag', 0)
                         ->whereIn('vote_type', ['standard', 'paid'])->groupBy('nominee_id')
                         ->selectRaw('nominee_id, SUM(weight) as n')->get() as $r) {
                $out[(int) $r->nominee_id] = (int) $r->n;
            }
        } catch (\Throwable) {}
        return $out;
    }

    /** The edition's approved nominees: id => name. @return array<int,string> */
    public static function nominees(int $cycleId): array
    {
        try {
            return DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)->whereIn('n.status', ['approved', 'winner', 'runner_up'])->whereNull('n.merged_into')
                ->orderBy('n.name')->pluck('n.name', 'n.id')->map(fn ($v) => (string) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Is this edition on the target pool? */
    public static function applies(int $cycleId): bool
    {
        return CommunityReturnService::modelForCycle($cycleId) === CommunityReturnService::MODEL_POOL;
    }

    public static function targetVotes(int $cycleId): int
    {
        return max(1, (int) (CommunityReturnMilestone::rulesForCycle($cycleId)['community_return_target_votes'] ?? 1000));
    }

    /** The settlement, once written. */
    public static function settlement(int $cycleId): ?object
    {
        try {
            return DB::table('gates_return_pools')->where('cycle_id', $cycleId)->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Where the edition stands: the target per nominee, the edition's combined target, the votes
     * so far, the gap, the pool, and every nominee's own figure.
     *
     * @return array{cycle_id:int, target:int, nominees:int, combined_target:int, combined_votes:int, gap:int, pct:int,
     *               pool_kobo:int, reached:int, settled:?object, rows:list<array{id:int,name:string,votes:int,reached:bool,to_go:int}>}
     */
    public static function progress(int $cycleId): array
    {
        $target = self::targetVotes($cycleId);
        $names = self::nominees($cycleId);
        $votes = self::votes($cycleId);
        $rows = [];
        foreach ($names as $id => $name) {
            $v = $votes[$id] ?? 0;
            $rows[] = ['id' => (int) $id, 'name' => $name, 'votes' => $v, 'reached' => $v >= $target, 'to_go' => max(0, $target - $v)];
        }
        usort($rows, static fn ($a, $b) => $b['votes'] <=> $a['votes'] ?: strcmp($a['name'], $b['name']));
        $combinedTarget = $target * count($rows);
        // Each nominee counts up to their own target: the bar is how close the edition is to
        // EVERYBODY reaching it, so one nominee's landslide cannot fill the bar for the rest.
        $combinedVotes = array_sum(array_map(static fn ($r) => min($r['votes'], $target), $rows));
        $settled = self::settlement($cycleId);
        return [
            'cycle_id' => $cycleId, 'target' => $target, 'nominees' => count($rows),
            'combined_target' => $combinedTarget, 'combined_votes' => $combinedVotes,
            'gap' => max(0, $combinedTarget - $combinedVotes),
            'pct' => $combinedTarget > 0 ? (int) min(100, floor(100 * $combinedVotes / $combinedTarget)) : 0,
            'pool_kobo' => $settled ? (int) $settled->pool_kobo : self::poolKobo($cycleId),
            'reached' => count(array_filter($rows, static fn ($r) => $r['reached'])),
            'settled' => $settled, 'rows' => $rows,
        ];
    }

    /** What the pool holds before settling: every accrual in the edition, net of reversals. */
    public static function poolKobo(int $cycleId): int
    {
        try {
            return max(0, (int) DB::table('gates_community_returns')->where('cycle_id', $cycleId)
                ->whereIn('entry_type', ['accrual', 'reversal'])->sum('amount_kobo'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Settle the pool: once, after voting has closed.
     *
     * @return array{ok:bool, code:string, message:string}
     */
    public static function settle(int $cycleId, int $adminId, string $note = ''): array
    {
        $no = static fn (string $c, string $m): array => ['ok' => false, 'code' => $c, 'message' => $m];
        if (!self::applies($cycleId)) return $no('NOT_POOL', 'This edition does not run the target pool.');
        if (self::settlement($cycleId)) return $no('ALREADY', 'This edition’s pool is already settled.');
        $cy = DB::table('gates_award_cycles')->where('id', $cycleId)->first();
        if (!$cy) return $no('NO_CYCLE', 'No such edition.');
        $closed = in_array((string) $cy->status, ['results', 'archived'], true)
            || (!empty($cy->voting_close) && (string) $cy->voting_close <= Carbon::now()->toDateTimeString());
        if (!$closed) return $no('OPEN', 'Voting is still open. Settle the pool once it has closed.');

        $p = self::progress($cycleId);
        $pool = $p['pool_kobo'];
        $winners = array_values(array_filter($p['rows'], static fn ($r) => $r['reached']));
        $shares = $winners && $pool > 0 ? self::split($pool, array_map(static fn ($r) => $r['votes'], $winners)) : [];
        $paid = array_sum($shares);
        $now = Carbon::now()->toDateTimeString();

        try {
            DB::transaction(function () use ($cycleId, $adminId, $note, $p, $pool, $winners, $shares, $paid, $now): void {
                // The claim first: a second press hits the unique key and writes nothing.
                DB::table('gates_return_pools')->insert(['cycle_id' => $cycleId, 'target_votes' => $p['target'], 'nominees' => $p['nominees'],
                    'pool_kobo' => $pool, 'winners' => count($winners), 'paid_kobo' => $paid, 'reserves_kobo' => $pool - $paid,
                    'settled_by' => $adminId > 0 ? $adminId : null, 'settled_at' => $now, 'note' => mb_substr(trim($note), 0, 400) ?: null]);

                // Every nominee whose supporters fed the pool gives their locked credit to it.
                $fed = DB::table('gates_community_returns')->where('cycle_id', $cycleId)->whereIn('entry_type', ['accrual', 'reversal'])->groupBy('nominee_id', 'profile_id')
                    ->selectRaw('nominee_id, profile_id, SUM(amount_kobo) as k')->get();
                foreach ($fed as $f) {
                    if ((int) $f->k === 0) continue;
                    DB::table('gates_community_returns')->insert(['nominee_id' => (int) $f->nominee_id, 'profile_id' => $f->profile_id, 'cycle_id' => $cycleId,
                        'entry_type' => 'adjustment', 'amount_kobo' => -1 * (int) $f->k, 'basis_kobo' => 0, 'rate_bps' => 0,
                        'note' => 'Into the edition pool', 'created_by' => $adminId > 0 ? $adminId : null, 'created_at' => $now]);
                }
                $total = array_sum(array_map(static fn ($r) => $r['votes'], $winners));
                foreach ($winners as $i => $w) {
                    if (($shares[$i] ?? 0) <= 0) continue;
                    $profile = DB::table('gates_nominees')->where('id', $w['id'])->value('profile_id');
                    DB::table('gates_community_returns')->insert(['nominee_id' => $w['id'], 'profile_id' => $profile, 'cycle_id' => $cycleId,
                        'entry_type' => 'adjustment', 'amount_kobo' => $shares[$i], 'basis_kobo' => 0, 'rate_bps' => 0,
                        'note' => 'Share of the edition pool: ' . number_format($w['votes']) . ' of the ' . number_format($total) . ' votes that reached the target',
                        'created_by' => $adminId > 0 ? $adminId : null, 'created_at' => $now]);
                }
            });
        } catch (\Throwable) {
            return $no('ALREADY', 'The pool has just been settled by somebody else.');
        }

        $naira = static fn (int $k): string => '₦' . CommunityReturnService::naira($k);
        return ['ok' => true, 'code' => 'SETTLED', 'message' => $winners
            ? 'Settled. ' . $naira($paid) . ' shared between ' . count($winners) . ' ' . (count($winners) === 1 ? 'nominee' : 'nominees') . ' by their votes.'
            : 'Settled. Nobody reached the target, so the ' . $naira($pool) . ' pool goes to the reserves.'];
    }

    /**
     * Split by weights, by largest remainder: the parts always add up to the whole.
     *
     * @param list<int> $weights
     * @return list<int>
     */
    public static function split(int $kobo, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) return array_fill(0, count($weights), 0);
        $parts = []; $rem = [];
        foreach ($weights as $i => $w) {
            $parts[$i] = intdiv($kobo * $w, $sum);
            $rem[$i] = ($kobo * $w) % $sum;
        }
        $left = $kobo - array_sum($parts);
        arsort($rem);
        foreach (array_keys($rem) as $i) { if ($left-- <= 0) break; $parts[$i]++; }
        ksort($parts);
        return array_values($parts);
    }

    /** One sentence for the console, the account page and /integrity. */
    public static function summary(array $rules): string
    {
        $pct = rtrim(rtrim(number_format(((int) ($rules['community_return_bps'] ?? 0)) / 100, 2), '0'), '.');
        return $pct . '% of every contribution goes into the edition’s pool. Every nominee has the same target of '
             . number_format(max(1, (int) ($rules['community_return_target_votes'] ?? 1000))) . ' votes; when voting closes, '
             . 'those who reached it share the pool by their votes. If nobody reaches it, the pool goes to the reserves.';
    }
}
