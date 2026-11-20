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
        $pick = static fn (array $r): array => array_intersect_key($r, array_flip(['head', 'left', 'of', 'note', 'pct', 'reached']));
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

    /**
     * The edition's milestone, in words meant to MOVE people (owner, 20 Nov 2026: "too much
     * talk … it needs to be motivational"): a call to action, what is left, one bar, and one
     * short line. The mechanics (locked shares, confirmation) belong to the rules page and the
     * nominee's letter, not to the box a supporter glances at before voting.
     *
     * @return array<string,mixed>
     */
    private static function milestoneRow(int $targetKobo, int $raisedKobo, bool $confirmed, int $price): array
    {
        $reached = $raisedKobo >= $targetKobo;
        $toGo = max(0, $targetKobo - $raisedKobo);
        $row = [
            'target_kobo' => $targetKobo, 'raised_kobo' => $raisedKobo, 'to_go_kobo' => $toGo, 'confirmed' => $confirmed,
            'votes_to_go' => self::votes($toGo, $price), 'reached' => $reached,
            'pct' => (int) min(100, floor(100 * $raisedKobo / $targetKobo)),
            'of' => Translator::t('%r% of %t% raised', ['%r%' => self::money($raisedKobo), '%t%' => self::money($targetKobo)]),
        ];
        if ($confirmed) {
            $row += ['head' => Translator::t('Rewards unlocked!'), 'left' => Translator::t('Every vote now earns the full reward'),
                     'note' => Translator::t('You did this. Thank you!')];
        } elseif ($reached) {
            $row += ['head' => Translator::t('Milestone smashed!'), 'left' => Translator::t('Rewards unlocking soon'),
                     'note' => Translator::t('You did this. Thank you!')];
        } else {
            $row += ['head' => Translator::t('Unlock rewards for every nominee!'),
                     'left' => Translator::t('%n% to go', ['%n%' => self::money($toGo, true)]),
                     'note' => self::cheer($row['pct'])];
        }
        return $row;
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
            $row['head'] = Translator::t('Top reward unlocked!');
            $row['left'] = Translator::t('Earning the full %p%%', ['%p%' => $pctNow]);
            $row['of'] = Translator::t('%r% raised', ['%r%' => self::money($raisedKobo)]);
            $row['note'] = Translator::t('You did this. Thank you!');
        } else {
            $row['head'] = $edition ? Translator::t('Power up every nominee’s reward!') : Translator::t('Power up this nominee’s reward!');
            // Short enough to stand alone on a list row, where it is the only words there are.
            $row['left'] = Translator::t('%n% to %p%%', ['%n%' => self::money($toGo, true), '%p%' => $row['share_next']]);
            $row['of'] = Translator::t('%r% of %t% raised', ['%r%' => self::money($raisedKobo), '%t%' => self::money($next[0])]);
            $row['note'] = self::cheer($row['pct']);
        }
        return $row;
    }

    /**
     * The closing line, which MOVES with the bar so the box is never the same sentence twice
     * in a campaign: a start, momentum, halfway, the last push.
     */
    private static function cheer(int $pct): string
    {
        return match (true) {
            $pct >= 75 => Translator::t('So close! One big push!'),
            $pct >= 50 => Translator::t('Halfway there! Keep it coming!'),
            $pct >= 25 => Translator::t('Momentum is building. Keep going!'),
            default    => Translator::t('We’re off! Every vote counts.'),
        };
    }

    /**
     * Naira as people say it: exact below ₦10,000, then ₦796K, then ₦5.1M.
     *
     * Below ₦10,000 a gap ($up) rounds UP to the naira, so "₦0 to go" is never said of a gap
     * that is not closed.
     */
    private static function money(int $kobo, bool $up = false): string
    {
        $n = $up ? intdiv($kobo + 99, 100) : intdiv($kobo, 100);
        if ($n < 10000) return '₦' . number_format($n);
        // Above ₦10,000 ordinary rounding: it cannot reach ₦0, and rounding a ₦5,104,020 gap UP
        // to "₦5.2M" would overstate it by nearly ₦100,000.
        // An amount RAISED rounds DOWN, so it never reads as having reached its target early
        // ("₦50K of ₦50K raised" with ₦50 still to go).
        if ($n < 1000000) {
            $k = $up ? (int) round($n / 1000) : intdiv($n, 1000);
            return $k >= 1000 ? '₦1M' : '₦' . $k . 'K';
        }
        $m = $up ? round($n / 1000000, 1) : floor($n / 100000) / 10;
        return '₦' . rtrim(rtrim(number_format($m, 1), '0'), '.') . 'M';
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
