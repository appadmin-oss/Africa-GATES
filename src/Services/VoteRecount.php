<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Rebuild a nominee's vote counters from the ballots themselves.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE COUNTERS CAN BE WRONG AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_votes` is the ledger; `gates_nominees.vote_count` and `organic_vote_count` are
 * denormalised totals kept beside it so a leaderboard is one query instead of a join per
 * row. Every ordinary path maintains both — {@see VoteService} increments them together,
 * the paid and bonus paths bump only the public tally on purpose.
 *
 * What no path maintains is a total for votes that arrived any OTHER way: an import, a
 * restore, a row written before `organic_vote_count` existed at all. Its own migration
 * backfills — but only in the branch that ADDS the column, so on any database whose base
 * schema already declared it, the backfill has never run and never will.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THAT IS NOT A COSMETIC PROBLEM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `organic_vote_count` is the community half of the CPI. A nominee whose counter reads zero
 * against a real ledger contributes nothing from the community — and if that is true of the
 * whole field, the community weight is silently zero for the category and the panel decides
 * the award alone at 45% of the index doing none of the work.
 *
 * That is not hypothetical. A live cycle ran with four nominees on 1,536, 1,955, 126 and 398
 * votes, organic zero on all four, and the second-most-voted nominee placed second to the
 * best-judged one with nothing on any screen saying the community half had been dropped.
 *
 * The arithmetic here already existed, privately, inside {@see BonusVoteService} — reachable
 * only as a side effect of clawing back a donation. A repair with no route in is not a
 * repair, which is why this is public, category-scoped, and reports what it changed.
 */
final class VoteRecount
{
    /**
     * What the ledger says this nominee's counters should be.
     *
     * Read-only. Separated from {@see applyNominee()} so a screen can show an operator the
     * discrepancy before anything is written — a repair that runs before somebody has seen
     * what it will do is indistinguishable from a bug.
     *
     * @return array{vote_count:int, organic_vote_count:int}
     */
    public static function fromLedger(int $nomineeId): array
    {
        try {
            return [
                // WEIGHT, not row count. A paid pack is one row carrying many votes, and
                // counting rows would report a nominee's support as a fraction of itself.
                'vote_count'         => (int) DB::table('gates_votes')
                    ->where('nominee_id', $nomineeId)->sum('weight'),
                // `standard` is the only organic type. The bonus and paid types exist
                // precisely so they can be excluded here.
                'organic_vote_count' => (int) DB::table('gates_votes')
                    ->where('nominee_id', $nomineeId)->where('vote_type', 'standard')->sum('weight'),
            ];
        } catch (\Throwable) {
            return ['vote_count' => 0, 'organic_vote_count' => 0];
        }
    }

    /**
     * Write the ledger's answer onto one nominee. Returns what moved, or null if nothing did.
     *
     * REFUSES on a nominee with a stored total and no ballot rows at all: that is an absent
     * ledger rather than a drifted counter, and the stored number is then the only record
     * that the support ever existed. The refusal comes back in the return value carrying
     * `refused`, so the screen can say which nominees were left alone and why.
     *
     * @return array{nominee_id:int, name:string, was:array, now:array, refused?:string}|null
     */
    public static function applyNominee(int $nomineeId): ?array
    {
        try {
            $n = DB::table('gates_nominees')->where('id', $nomineeId)
                ->first(['id', 'name', 'vote_count', 'organic_vote_count']);
        } catch (\Throwable) {
            return null;
        }
        if (!$n) return null;

        $was = ['vote_count' => (int) $n->vote_count,
                'organic_vote_count' => (int) $n->organic_vote_count];
        $now = self::fromLedger($nomineeId);

        if ($was === $now) return null;

        // ══ AN ABSENT LEDGER IS NOT A DISCREPANCY ═══════════════════════════
        //
        // If there is not one ballot row for this nominee, the stored total is the ONLY
        // record that support ever existed — an import, a restore, a migration from
        // whatever ran before this platform. Writing the ledger's answer there does not
        // repair anything; it destroys the evidence, on a screen whose button says
        // "recount", to somebody trying to fix a scoring fault.
        //
        // A nominee with 1,536 votes and no ballots is a data problem to investigate, not
        // a counter to zero. So this refuses, and the refusal is REPORTED rather than
        // silent — an operator who presses recount and sees nothing happen has learned
        // something specific: the votes are not in the ledger at all.
        //
        // No stored total needs checking alongside it. Reaching this line means the stored
        // figures already disagree with the ledger, and with no rows the ledger's answer is
        // zero on both — so the stored total is non-zero by construction. A second clause
        // spelling that out would read like a guard and be one no test could ever fail.
        $rows = 0;
        try {
            $rows = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)->count();
        } catch (\Throwable) {
            return null;
        }

        if ($rows === 0) {
            return ['nominee_id' => (int) $n->id, 'name' => (string) $n->name,
                    'was' => $was, 'now' => $was, 'refused' => 'no ballots on record'];
        }

        try {
            DB::table('gates_nominees')->where('id', $nomineeId)->update($now);
        } catch (\Throwable) {
            return null;
        }

        return ['nominee_id' => (int) $n->id, 'name' => (string) $n->name,
                'was' => $was, 'now' => $now];
    }

    /**
     * Every nominee in one category.
     *
     * Category-scoped and not platform-wide, deliberately: this rewrites the numbers an
     * award is decided on, and an operator repairing the category in front of them should
     * not also be moving three others they have not looked at.
     *
     * @return array{checked:int, changed:list<array<string,mixed>>}
     */
    public static function category(int $categoryId): array
    {
        try {
            $ids = DB::table('gates_nominees')->where('category_id', $categoryId)
                ->pluck('id')->all();
        } catch (\Throwable) {
            return ['checked' => 0, 'changed' => []];
        }

        $changed = [];
        foreach ($ids as $id) {
            $moved = self::applyNominee((int) $id);
            if ($moved !== null) $changed[] = $moved;
        }

        return ['checked' => count($ids), 'changed' => $changed];
    }

    /**
     * Does this category's stored total disagree with its ledger anywhere?
     *
     * The question a release screen wants before it offers a repair, answered without
     * writing anything.
     */
    public static function categoryDrifts(int $categoryId): bool
    {
        try {
            $rows = DB::table('gates_nominees')->where('category_id', $categoryId)
                ->get(['id', 'vote_count', 'organic_vote_count']);
        } catch (\Throwable) {
            return false;
        }

        foreach ($rows as $r) {
            $now = self::fromLedger((int) $r->id);
            if ((int) $r->vote_count !== $now['vote_count']
                || (int) $r->organic_vote_count !== $now['organic_vote_count']) {
                return true;
            }
        }

        return false;
    }
}
