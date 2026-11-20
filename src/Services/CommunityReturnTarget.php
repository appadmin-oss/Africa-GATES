<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Every MONEY TARGET an edition's community return carries, as the public pages show it.
 *
 * ── WHY ONE CLASS FOR SIX RULES ──────────────────────────────────────────────
 *
 * The vote target shipped for the target pool alone (CommunityReturnPool), so an edition on
 * any other rule drew nothing on the vote page, the vote list or the award page — while
 * three of the other five rules also turn on a figure in naira that supporters move with
 * every paid vote (owner, 20 Nov 2026: "it should work for all things that have a monetary
 * target"). Each rule's target is shown in ITS OWN terms, because the terms are the promise:
 *
 *   pool                 every nominee has the same vote target; those who reach it share
 *                        the pool. Delegated, unchanged, to CommunityReturnPool.
 *   milestone, and a     ONE target for the whole edition: its eligible paid-vote revenue
 *   custom ladder held   against the milestone. Reaching it lets an operator confirm, and
 *   locked               every nominee's share then unlocks. It is not a per-nominee goal,
 *                        so no row anywhere says a nominee "reached" it.
 *   tiered, and custom   the NEXT STEP of the ladder: "₦N more raises the share to 30%".
 *                        Per nominee where the ladder is measured on the nominee's own total,
 *                        for the edition where it is measured on the edition's.
 *
 * Threshold (qualifying support, counted in votes from capped supporters) and flat (one
 * share on everything) have no money target, and draw nothing here.
 *
 * ── THE MONEY IS THE MONEY THE RULE COUNTS ───────────────────────────────────
 *
 * Raised = confirmed paid-vote contributions, less refunded, refund-requested and
 * fraud-flagged — CommunityReturnMilestone::measuredKobo()'s rule and the ladder's
 * raisedBefore() rule, which are the same rule. A figure counted any other way would tell a
 * nominee they had crossed a step their next contribution is not priced at.
 *
 * "About N paid votes" is the gap at the edition's vote price (pinned with its rules, as the
 * pool's is), rounded up. Only paid votes raise money, and the words say "paid".
 *
 * ── THE SAME GATE AS THE POOL ────────────────────────────────────────────────
 *
 * Paid voting on (with no vote sales a money target is a promise of nothing) — or, for a
 * milestone, already confirmed, which is a record rather than a promise; a target above
 * zero; not the sandbox (DemoSeeder::liveAwardOnly over the edition's nominees).
 */
final class CommunityReturnTarget
{
    public const KIND_POOL      = 'pool';
    public const KIND_MILESTONE = 'milestone';
    public const KIND_LADDER    = 'ladder';

    /**
     * The edition's money target for public pages, or null where none should be drawn.
     *
     * Asked ONCE per edition per request; per-nominee figures are read out of the result
     * with standingIn(), so a list of sixty rows runs one grouped query, not sixty.
     *
     * @return array<string,mixed>|null
     */
    public static function forPublic(int $cycleId): ?array
    {
        if ($cycleId < 1) return null;
        $rules = CommunityReturnMilestone::rulesForCycle($cycleId);
        $model = CommunityReturnService::modelOf($rules);

        if ($model === CommunityReturnService::MODEL_POOL) {
            $p = CommunityReturnPool::forPublic($cycleId);
            return $p === null ? null : ['kind' => self::KIND_POOL, 'cycle_id' => $cycleId, 'scope' => 'nominee', 'pool' => $p];
        }

        $nominees = CommunityReturnPool::nominees($cycleId);
        if ($nominees === [] || !self::live(array_keys($nominees))) return null;
        $price = self::price($rules);

        if (CommunityReturnService::usesMilestone($rules)) {
            $m = CommunityReturnMilestone::progress($cycleId);
            $confirmed = $m['confirmed_at'] !== null;
            if ($m['target_kobo'] < 1 || (!$confirmed && !CommunityReturnService::active())) return null;
            return ['kind' => self::KIND_MILESTONE, 'cycle_id' => $cycleId, 'scope' => 'edition', 'model' => $model,
                    'edition' => self::milestoneRow($m['target_kobo'], $m['measured_kobo'], $confirmed, $price)];
        }

        if (!CommunityReturnLadder::isLadder($model) || !CommunityReturnService::active()) return null;
        $steps = CommunityReturnLadder::steps($rules, $model);
        // A ladder with nothing above its first step has no target to move towards: that is
        // flat in effect, whatever it is called.
        if (count($steps) < 2 && !($steps !== [] && $steps[0][0] > 0)) return null;

        if (CommunityReturnLadder::basis($rules, $model) === CommunityReturnLadder::BASIS_EDITION) {
            return ['kind' => self::KIND_LADDER, 'cycle_id' => $cycleId, 'scope' => 'edition', 'model' => $model,
                    'edition' => self::ladderRow($steps, CommunityReturnMilestone::measuredKobo($cycleId), $price, true)];
        }
        $raised = self::raisedByNominee($cycleId, array_keys($nominees));
        $rows = [];
        foreach ($nominees as $id => $_) {
            $rows[(int) $id] = self::ladderRow($steps, $raised[(int) $id] ?? 0, $price, false);
        }
        return ['kind' => self::KIND_LADDER, 'cycle_id' => $cycleId, 'scope' => 'nominee', 'model' => $model, 'rows' => $rows];
    }

    /**
     * One nominee's figure out of a forPublic() result: the pool's standing (unchanged shape,
     * `kind` added), the nominee's own ladder row, or null where the target is the edition's
     * and belongs on the page once rather than on every row.
     *
     * @param  array<string,mixed> $t a forPublic() result
     * @return array<string,mixed>|null
     */
    public static function standingIn(array $t, int $nomineeId): ?array
    {
        if (($t['kind'] ?? '') === self::KIND_POOL) {
            $s = CommunityReturnPool::standingIn($t['pool'], $nomineeId);
            return $s === null ? null : $s + ['kind' => self::KIND_POOL];
        }
        if (($t['scope'] ?? '') !== 'nominee') return null;
        $r = $t['rows'][$nomineeId] ?? null;
        return $r === null ? null : $r + ['kind' => (string) $t['kind']];
    }

    /** The edition-wide figure, where the target is the edition's. @return array<string,mixed>|null */
    public static function editionIn(?array $t): ?array
    {
        return $t !== null && ($t['scope'] ?? '') === 'edition' ? $t['edition'] + ['kind' => (string) $t['kind']] : null;
    }

    /**
     * The tallies poll's part, so every page counts down from the one cached request. The pool
     * keeps the shape vote.js has always read; the money kinds carry their words ready-made,
     * so the script decides nothing and the server's sentence is the only sentence.
     *
     * @return array<string,mixed>|null
     */
    public static function pollPayload(int $cycleId): ?array
    {
        $t = self::forPublic($cycleId);
        if ($t === null) return null;
        if ($t['kind'] === self::KIND_POOL) return CommunityReturnPool::pollPayload($cycleId);
        $pick = static fn (array $r): array => array_intersect_key($r, array_flip(['left', 'of', 'pct', 'reached']));
        $out = ['cycle_id' => $cycleId, 'kind' => $t['kind'], 'settled' => false, 'nominees' => [], 'edition' => null];
        if ($t['scope'] === 'edition') {
            $out['edition'] = $pick($t['edition']);
            $out['settled'] = !empty($t['edition']['confirmed']);
        } else {
            foreach ($t['rows'] as $id => $r) $out['nominees'][] = ['id' => (int) $id] + $pick($r);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function milestoneRow(int $targetKobo, int $raisedKobo, bool $confirmed, int $price): array
    {
        $reached = $raisedKobo >= $targetKobo;
        $toGo = max(0, $targetKobo - $raisedKobo);
        $of = Translator::t('₦%r% of ₦%t% raised', ['%r%' => self::n($raisedKobo), '%t%' => self::n($targetKobo)]);
        return [
            'target_kobo' => $targetKobo, 'raised_kobo' => $raisedKobo, 'to_go_kobo' => $toGo, 'confirmed' => $confirmed,
            'votes_to_go' => self::votes($toGo, $price), 'reached' => $reached,
            'pct' => (int) min(100, floor(100 * $raisedKobo / $targetKobo)),
            'head' => Translator::t('Edition milestone: ₦%t%', ['%t%' => self::n($targetKobo)]),
            'of' => $of,
            'left' => $confirmed ? Translator::t('Milestone confirmed')
                : ($reached ? Translator::t('Milestone reached, awaiting confirmation') : self::toGo($toGo, $price)),
            'note' => $confirmed
                ? Translator::t('The milestone has been confirmed: every nominee’s share is unlocked, and every eligible paid vote from then on earns the full share.')
                : Translator::t('One target for the whole edition, counted from paid votes. Until it is reached and confirmed, each nominee’s share is held locked; then every nominee’s share unlocks.'),
        ];
    }

    /** @param list<array{0:int,1:int}> $steps @return array<string,mixed> */
    private static function ladderRow(array $steps, int $raisedKobo, int $price, bool $edition): array
    {
        $now = CommunityReturnLadder::rateFor($steps, $raisedKobo);
        $next = null;
        foreach ($steps as [$from, $bps]) {
            if ($from > $raisedKobo && $bps > $now) { $next = [$from, $bps]; break; }
        }
        $toGo = $next ? max(0, $next[0] - $raisedKobo) : 0;
        $pctNow = CommunityReturnLadder::pct($now);
        $row = [
            'target_kobo' => $next ? $next[0] : $raisedKobo, 'raised_kobo' => $raisedKobo, 'to_go_kobo' => $toGo,
            'votes_to_go' => self::votes($toGo, $price), 'reached' => $next === null,
            'share_now' => $pctNow, 'share_next' => $next ? CommunityReturnLadder::pct($next[1]) : null,
            'pct' => $next ? (int) min(100, floor(100 * $raisedKobo / $next[0])) : 100,
        ];
        if ($next === null) {
            $row['head'] = Translator::t('Community return: %p%% share', ['%p%' => $pctNow]);
            $row['left'] = Translator::t('Top share reached');
            $row['of'] = Translator::t('₦%r% raised', ['%r%' => self::n($raisedKobo)]);
        } else {
            $row['head'] = Translator::t('Next step: ₦%t% for a %p%% share', ['%t%' => self::n($next[0]), '%p%' => $row['share_next']]);
            $row['left'] = self::toGo($toGo, $price);
            $row['of'] = Translator::t('₦%r% of ₦%t% raised', ['%r%' => self::n($raisedKobo), '%t%' => self::n($next[0])]);
        }
        $row['note'] = $edition
            ? Translator::t('The share rises with what the whole edition raises in paid votes; the share now is %p%%.', ['%p%' => $pctNow])
            : Translator::t('The share rises with what this nominee’s supporters raise in paid votes; the share now is %p%%.', ['%p%' => $pctNow]);
        return $row;
    }

    private static function toGo(int $toGoKobo, int $price): string
    {
        $v = self::votes($toGoKobo, $price);
        if ($v < 1) return Translator::t('₦%n% to go', ['%n%' => self::n($toGoKobo, true)]);
        return Translator::t($v === 1 ? '₦%n% to go · about 1 paid vote' : '₦%n% to go · about %v% paid votes',
            ['%n%' => self::n($toGoKobo, true), '%v%' => number_format($v)]);
    }

    /** Whole naira; a gap rounds UP, so "₦0 to go" is never said of a gap that is not closed. */
    private static function n(int $kobo, bool $up = false): string
    {
        return number_format($up ? intdiv($kobo + 99, 100) : intdiv($kobo, 100));
    }

    private static function votes(int $kobo, int $price): int
    {
        return $price > 0 && $kobo > 0 ? intdiv($kobo + $price * 100 - 1, $price * 100) : 0;
    }

    /** The edition's vote price: pinned with its rules where it was, today's otherwise. */
    private static function price(array $rules): int
    {
        $pinned = (int) ($rules['community_return_vote_price_naira'] ?? 0);
        return max(0, $pinned > 0 ? $pinned : PaidVoteService::pricePerVote());
    }

    /**
     * Eligible paid-vote money per nominee, in kobo — the ladder's own counting rule
     * (CommunityReturnLadder::raisedBefore), grouped, so a list is one query.
     *
     * @param  list<int> $ids
     * @return array<int,int>
     */
    private static function raisedByNominee(int $cycleId, array $ids): array
    {
        if ($ids === []) return [];
        $out = [];
        try {
            foreach (DB::table('gates_donations as d')
                         ->whereIn('d.intent_nominee_id', $ids)
                         ->where('d.tier', 'paid-vote')->where('d.status', 'confirmed')
                         ->whereNull('d.refunded_at')->whereNull('d.refund_requested_at')
                         ->whereNotExists(static function ($q) {
                             $q->select(DB::raw(1))->from('gates_votes as v')
                               ->whereColumn('v.donation_id', 'd.id')->where('v.fraud_flag', 1);
                         })
                         ->groupBy('d.intent_nominee_id')
                         ->selectRaw('d.intent_nominee_id as nid, SUM(d.amount_naira) as n')->get() as $r) {
                $out[(int) $r->nid] = (int) round(((float) $r->n) * 100);
            }
        } catch (\Throwable) {}
        return $out;
    }

    /** @param list<int> $ids */
    private static function live(array $ids): bool
    {
        try {
            return DemoSeeder::liveAwardOnly(DB::table('gates_nominees')->whereIn('gates_nominees.id', $ids))->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
