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
                    'edition' => self::milestoneRow($m['target_kobo'], $m['measured_kobo'], $confirmed, $price, self::recentKobo($cycleId), $cycleId)];
        }

        if (!CommunityReturnLadder::isLadder($model) || !CommunityReturnService::active()) return null;
        $steps = CommunityReturnLadder::steps($rules, $model);
        // A ladder with nothing above its first step has no target to move towards: that is
        // flat in effect, whatever it is called.
        if (count($steps) < 2 && !($steps !== [] && $steps[0][0] > 0)) return null;

        if (CommunityReturnLadder::basis($rules, $model) === CommunityReturnLadder::BASIS_EDITION) {
            return ['kind' => self::KIND_LADDER, 'cycle_id' => $cycleId, 'scope' => 'edition', 'model' => $model,
                    'edition' => self::ladderRow($steps, CommunityReturnMilestone::measuredKobo($cycleId), $price, true, self::recentKobo($cycleId), $cycleId)];
        }
        $raised = self::raisedByNominee($cycleId, array_keys($nominees));
        $rows = [];
        foreach ($nominees as $id => $_) {
            [$sum, $recent] = $raised[(int) $id] ?? [0, 0];
            $rows[(int) $id] = self::ladderRow($steps, $sum, $price, false, $recent, $cycleId * 7919 + (int) $id);
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
        $pick = static fn (array $r): array => array_intersect_key($r, array_flip(['head', 'left', 'of', 'note', 'art', 'pct', 'reached']));
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
    private static function milestoneRow(int $targetKobo, int $raisedKobo, bool $confirmed, int $price, int $recentKobo, int $seed): array
    {
        $reached = $raisedKobo >= $targetKobo;
        $toGo = max(0, $targetKobo - $raisedKobo);
        $row = [
            'target_kobo' => $targetKobo, 'raised_kobo' => $raisedKobo, 'to_go_kobo' => $toGo, 'confirmed' => $confirmed,
            'votes_to_go' => self::votes($toGo, $price), 'reached' => $reached,
            'pct' => (int) min(100, floor(100 * $raisedKobo / $targetKobo)),
            'of' => self::of($raisedKobo, $targetKobo, $price),
        ];
        // The target is SAID IN VOTES (owner, 20 Nov 2026: "the target should be said in votes,
        // not money"): the naira milestone at the edition's vote price, the money raised as the
        // votes it bought. Naira only where there is no price to convert at.
        [$toGoU, $targetU, $raisedU] = self::units($targetKobo, $raisedKobo, $price);
        if ($confirmed) {
            $row += ['head' => Translator::t('Rewards unlocked!'), 'left' => Translator::t('Every vote now earns the full reward'),
                     'note' => self::pick([Translator::t('You did this. Thank you!'), Translator::t('What a community. Thank you!'),
                                           Translator::t('Every supporter made this happen!')], $seed),
                     'art' => self::art('gift', $seed)];
        } elseif ($reached) {
            $row += ['head' => Translator::t('Milestone smashed!'), 'left' => Translator::t('Rewards unlocking soon'),
                     'note' => self::pick([Translator::t('You did this. Thank you!'), Translator::t('Target crushed. What a team!'),
                                           Translator::t('Every vote got us here!')], $seed),
                     'art' => self::art('unlock', $seed)];
        } else {
            $row += ['head' => Translator::t('Unlock rewards for every nominee!'),
                     'left' => Translator::t('%n% to go', ['%n%' => self::say($toGoU, $price)]),
                     'note' => self::cheer($row['pct'], $toGoU, $targetU, $raisedU, self::unit($recentKobo, $price, false), $price, $seed),
                     'art' => self::stageArt($row['pct'], $seed)];
        }
        return $row;
    }

    /** @param list<array{0:int,1:int}> $steps @return array<string,mixed> */
    private static function ladderRow(array $steps, int $raisedKobo, int $price, bool $edition, int $recentKobo, int $seed): array
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
            $row['of'] = Translator::t('%r% so far', ['%r%' => self::say(self::unit($raisedKobo, $price, false), $price)]);
            $row['note'] = self::pick([Translator::t('You did this. Thank you!'), Translator::t('Top of the ladder. What a team!'),
                                       Translator::t('Maximum reward unlocked!')], $seed);
            $row['art'] = self::art('gift', $seed);
        } else {
            $row['head'] = $edition ? Translator::t('Power up every nominee’s reward!') : Translator::t('Power up this nominee’s reward!');
            // Short enough to stand alone on a list row, where it is the only words there are.
            [$toGoU, $targetU, $raisedU] = self::units($next[0], $raisedKobo, $price);
            $row['left'] = Translator::t('%n% to %p%%', ['%n%' => self::say($toGoU, $price), '%p%' => $row['share_next']]);
            $row['of'] = self::of($raisedKobo, $next[0], $price);
            $row['note'] = self::cheer($row['pct'], $toGoU, $targetU, $raisedU, self::unit($recentKobo, $price, false), $price, $seed);
            // A step on the ladder is a level-up: the coins at the start, the stage art after.
            $row['art'] = $row['pct'] < 25 ? self::art('coins', $seed) : self::stageArt($row['pct'], $seed);
        }
        return $row;
    }

    /**
     * The closing line, which MOVES (owner, 20 Nov 2026: "more dynamism"): several lines per
     * stage of the bar, lines built from the live figures — the money that came in this past
     * hour, the gap to the next quarter mark — and a rotation every minute, so a supporter who
     * comes back, or keeps the page open while the poll ticks, does not read the same sentence.
     */
    private static function cheer(int $pct, int $toGo, int $target, int $raised, int $recent, int $price, int $seed): string
    {
        $lines = match (true) {
            $pct >= 90 => [Translator::t('So close! One big push!'), Translator::t('The finish line is right there!'),
                           Translator::t('Almost there. Don’t stop now!'), Translator::t('This is it. Let’s finish strong!')],
            $pct >= 75 => [Translator::t('Three quarters done. Bring it home!'), Translator::t('The end is in sight. Keep pushing!'),
                           Translator::t('Huge progress! One more push!')],
            $pct >= 50 => [Translator::t('Halfway there! Keep it coming!'), Translator::t('Past halfway and climbing!'),
                           Translator::t('The second half starts now. Let’s go!')],
            $pct >= 25 => [Translator::t('Momentum is building. Keep going!'), Translator::t('A quarter done. On to halfway!'),
                           Translator::t('It’s moving! Every vote counts.')],
            $pct >= 5  => [Translator::t('We’re off! Every vote counts.'), Translator::t('Great start. Keep it rolling!'),
                           Translator::t('The climb has begun!')],
            default    => [Translator::t('Be one of the first to back it!'), Translator::t('Every big win starts with one vote!'),
                           Translator::t('Get it started. Your vote counts!')],
        };
        // Lines from the live figures, added only when they are true right now.
        // Every figure here is in the box's own unit: votes, or naira only where there is no
        // vote price (see units()).
        if ($recent > 0) {
            $lines[] = Translator::t('%n% in the last hour. Keep it rolling!', ['%n%' => self::say($recent, $price)]);
        }
        $mark = $pct < 25 ? 25 : ($pct < 50 ? 50 : ($pct < 75 ? 75 : 0));
        if ($mark > 0 && $target > 0) {
            $gap = (int) ceil($target * $mark / 100) - $raised;
            if ($gap > 0) {
                $lines[] = Translator::t($mark === 50 ? 'Just %n% to the halfway mark!' : ($mark === 25 ? 'Just %n% to the first quarter!' : 'Just %n% to the three-quarter mark!'),
                    ['%n%' => self::say($gap, $price)]);
            }
        } elseif ($toGo > 0) {
            $lines[] = Translator::t('Only %n% left. Let’s finish this!', ['%n%' => self::say($toGo, $price)]);
        }
        return self::pick($lines, $seed);
    }

    /** One of several, turning every minute and offset per edition/nominee so a list varies. */
    private static function pick(array $lines, int $seed): string
    {
        return $lines[(self::minute() + abs($seed)) % count($lines)];
    }

    /** The picture for where the bar stands: a rocket, a megaphone, a target, a trophy. */
    private static function stageArt(int $pct, int $seed): string
    {
        return self::art(match (true) { $pct >= 75 => 'trophy', $pct >= 50 => 'target', $pct >= 25 => 'megaphone', default => 'rocket' }, $seed);
    }

    /** The two illustration sets, alternating: the glossy app style and the house style. */
    public const ART_STYLES = ['bold', 'house'];
    public const ART_SUBJECTS = ['rocket', 'megaphone', 'target', 'trophy', 'unlock', 'gift', 'coins', 'ballot'];

    /**
     * A spark illustration's URL. A generated image dropped in beside the drawn one, with the
     * same name (`spark/rocket-bold.webp`, or `.png`), is used in its place — the drawn SVGs
     * are the defaults, and docs/SPARK-ART-PROMPTS.md has the prompts that make the others.
     */
    public static function art(string $subject, int $seed = 0, ?string $style = null): string
    {
        $dir = dirname(__DIR__, 2) . '/public/assets/img/spark/';
        $supplied = static function (string $st) use ($dir, $subject): ?string {
            foreach (['webp', 'png', 'jpg'] as $ext) {
                if (is_file($dir . "$subject-$st.$ext")) return "/assets/img/spark/$subject-$st.$ext";
            }
            return null;
        };
        if ($style !== null) return $supplied($style) ?? "/assets/img/spark/$subject-$style.svg";

        // A SUPPLIED PICTURE ALWAYS WINS (owner, 20 Nov 2026: "the assets I sent you are not
        // seen"). The two styles used to alternate every minute whatever was on disk, so the
        // owner's rocket gave way to the drawn one half the time. Now the drawings are only the
        // fallback: where the owner supplied a picture for this subject in either style, that
        // is what shows — alternating only between supplied pictures when both styles exist.
        $have = array_values(array_filter(array_map($supplied, self::ART_STYLES)));
        if ($have !== []) return $have[(self::minute() + abs($seed)) % count($have)];
        $style = self::ART_STYLES[(self::minute() + abs($seed)) % 2];
        return "/assets/img/spark/$subject-$style.svg";
    }

    /**
     * Words and a picture for the POOL's box, which counts votes rather than naira — so its
     * cheer has no money lines — but must not be the one box on the site that stands still.
     *
     * @return array{art:string, note:string}
     */
    public static function poolSpirit(int $pct, bool $reached, bool $settled, int $seed): array
    {
        if ($reached) return ['art' => self::art('unlock', $seed), 'note' => self::pick([Translator::t('Target reached! What a team!'), Translator::t('You did this. Thank you!')], $seed)];
        if ($settled) return ['art' => self::art('trophy', $seed), 'note' => Translator::t('Thank you to every supporter!')];
        return ['art' => self::stageArt($pct, $seed), 'note' => self::cheer($pct, 0, 0, 0, 0, 1, $seed)];
    }

    /**
     * A target, the money raised and the gap, in the box's unit: VOTES at the edition's price,
     * or kobo where there is no price. The target rounds UP and the raised figure DOWN, so the
     * gap never reads as closed before it is, and the three always add up on the screen.
     *
     * @return array{0:int,1:int,2:int} to go, target, raised
     */
    private static function units(int $targetKobo, int $raisedKobo, int $price): array
    {
        $t = self::unit($targetKobo, $price, true);
        $r = min($t, self::unit($raisedKobo, $price, false));
        return [max(0, $t - $r), $t, $r];
    }

    private static function unit(int $kobo, int $price, bool $up): int
    {
        if ($price < 1) return $kobo;
        $per = $price * 100;
        return $up ? intdiv($kobo + $per - 1, $per) : intdiv($kobo, $per);
    }

    /** "51,041 votes", "1 vote", "120K votes" — or naira where there is no vote price. */
    private static function say(int $units, int $price): string
    {
        if ($price < 1) return self::money($units, true);
        if ($units === 1) return Translator::t('1 vote');
        return Translator::t('%n% votes', ['%n%' => self::count($units)]);
    }

    /** "7,959 of 59,000 votes", or "₦795K of ₦5.9M raised" where there is no vote price. */
    private static function of(int $raisedKobo, int $targetKobo, int $price): string
    {
        if ($price < 1) {
            return Translator::t('%r% of %t% raised', ['%r%' => self::money($raisedKobo), '%t%' => self::money($targetKobo)]);
        }
        [, $t, $r] = self::units($targetKobo, $raisedKobo, $price);
        return Translator::t('%r% of %t% votes', ['%r%' => self::count($r), '%t%' => self::count($t)]);
    }

    /** A count as people say it: exact up to 99,999, then 120K, then 1.2M. */
    private static function count(int $n): string
    {
        if ($n < 100000) return number_format($n);
        if ($n < 1000000) return intdiv($n, 1000) . 'K';
        return rtrim(rtrim(number_format(floor($n / 100000) / 10, 1), '0'), '.') . 'M';
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
     * With the part of it that came in this past hour, in the same query, for the live cheer.
     *
     * @param  list<int> $ids
     * @return array<int,array{0:int,1:int}> raised, raised in the last hour
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
                         ->selectRaw('d.intent_nominee_id as nid, SUM(d.amount_naira) as n,'
                             . ' SUM(CASE WHEN d.created_at >= ? THEN d.amount_naira ELSE 0 END) as h', [self::hourAgo()])->get() as $r) {
                $out[(int) $r->nid] = [(int) round(((float) $r->n) * 100), (int) round(((float) $r->h) * 100)];
            }
        } catch (\Throwable) {}
        return $out;
    }

    /** The edition's eligible paid-vote money in the past hour, by the accrual's rule. */
    private static function recentKobo(int $cycleId): int
    {
        try {
            $naira = (float) DB::table('gates_donations as d')
                ->join('gates_nominees as n', 'n.id', '=', 'd.intent_nominee_id')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)
                ->where('d.tier', 'paid-vote')->where('d.status', 'confirmed')
                ->whereNull('d.refunded_at')->whereNull('d.refund_requested_at')
                ->where('d.created_at', '>=', self::hourAgo())
                ->whereNotExists(static function ($q) {
                    $q->select(DB::raw(1))->from('gates_votes as v')
                      ->whereColumn('v.donation_id', 'd.id')->where('v.fraud_flag', 1);
                })
                ->sum('d.amount_naira');
        } catch (\Throwable) {
            return 0;
        }
        return (int) round($naira * 100);
    }

    /** The rotation's clock: Carbon's, so a test can hold it still. */
    private static function minute(): int
    {
        return intdiv(\Illuminate\Support\Carbon::now()->getTimestamp(), 60);
    }

    private static function hourAgo(): string
    {
        return \Illuminate\Support\Carbon::now()->subHour()->toDateTimeString();
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
