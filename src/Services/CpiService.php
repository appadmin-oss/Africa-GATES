<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Pure Cultural Power Index math — no DB, no I/O, fully unit-testable.
 *
 * Final weighting: 45% community votes (cohort-normalised) + 55% expert judges,
 * scaled to 0..1000. Extracted from CpiRecomputeCommand so the scoring can be
 * tested directly and the per-category normalisation fix lives in one place.
 */
class CpiService
{
    /** Tier ladder: [name, minimum inclusive score]. Highest first. */
    public const TIERS = [
        ['diamond', 850], ['platinum', 650], ['gold', 450],
        ['silver', 250], ['bronze', 100], ['unranked', 0],
    ];

    private const VERIFY = ['none' => 0, 'basic' => 40, 'verified' => 75, 'premium' => 100];

    /**
     * Final 0..1000 nominee score.
     *
     * @param int        $voteCount      this nominee's votes
     * @param int        $cohortMaxVotes the max votes in this nominee's COHORT
     *                                   (per-category) used to normalise the
     *                                   community component to 0..1
     * @param float|null $judgeAvg0to10  weighted judge average (0..10), or NULL when the
     *                                   panel has not reached quorum. Null contributes
     *                                   ZERO, not a renormalised community-only score:
     *                                   see NomineeScoringService::scoreCategory() for the
     *                                   measured reason, and read the result as provisional
     *                                   rather than as a rank.
     */
    /**
     * ══ WHY BOTH HALVES ARE CURVED ═══════════════════════════════════════════
     *
     * They were linear, and the index that produced did not discriminate. Measured on a
     * real category — four nominees on 1,955 / 1,536 / 398 / 126 votes with panel marks of
     * 7.9 / 8.0 / 7.6 / 7.6:
     *
     *     Ajayi     835      Aborode  794      Lawal  477      Makinde  414
     *
     * Last place, on 126 votes — six per cent of the leader's support — scored 414 out of
     * 1000. On the published ladder that is a `gold` tier. Nothing about the shape of that
     * field is visible in the numbers it produced, and a score that everybody clears is not
     * a measure of anything.
     *
     * Two causes, and each half has one:
     *
     *  · THE COMMUNITY HALF WAS A RAW SHARE. Six per cent of the leader returned six per
     *    cent of the weight, which sounds proportionate and is not: the interesting
     *    distinction in a field is between leading it and trailing it, and a linear share
     *    flattens that into a gentle slope.
     *
     *  · THE JUDGE HALF USED THE WHOLE 0–10 RANGE. Panels do not. A mark below about five
     *    is not awarded in practice, so the bottom half of the scale is dead and 7.6 —
     *    a respectable, unremarkable mark — returned seventy per cent of 550.
     *
     * So the share is raised to a power, and the mark is re-based on the range a panel
     * actually uses before being raised to a power of its own. Both exponents and the floor
     * are settings ({@see RuleEngine}), because the right steepness is a judgement about
     * this award rather than a fact about arithmetic — and the operator who has to defend a
     * number to a nominee is the one who should own it.
     *
     * The same category under the defaults:
     *
     *     Ajayi     693      Aborode  534      Lawal  225      Makinde  208
     *
     * The leader still holds the full community weight — they led the field, and no curve
     * should take that from them. What has changed is everybody else, and the ceiling: 900
     * now needs a field a nominee dominates AND a panel mark near the top of the scale,
     * which is what a score at that height ought to mean.
     *
     * ── WHAT THIS DOES NOT FIX ──────────────────────────────────────────────
     *
     * A category with three votes in it still hands its leader the full community weight,
     * because the share is relative by design. Making it absolute needs a target vote count
     * somebody chooses, and inventing one here silently would be worse than leaving it
     * named. {@see NomineeScoringService} is where a field-size term would go.
     *
     * @param int        $voteCount      this nominee's votes
     * @param int        $cohortMaxVotes the max votes in this nominee's COHORT
     *                                   (per-category) used to normalise the
     *                                   community component to 0..1
     * @param float|null $judgeAvg0to10  weighted judge average (0..10), or NULL when the
     *                                   panel has not reached quorum. Null contributes
     *                                   ZERO, not a renormalised community-only score:
     *                                   see NomineeScoringService::scoreCategory() for the
     *                                   measured reason, and read the result as provisional
     *                                   rather than as a rank.
     */
    public function nomineeScore(
        int $voteCount,
        int $cohortMaxVotes,
        ?float $judgeAvg0to10,
        float $communityWeight = 0.45,
        float $judgeWeight = 0.55,
        ?float $communityCurve = null,
        ?float $judgeFloor = null,
        ?float $judgeCurve = null,
        ?int $fullCredit = null,
        ?string $communityBasis = null
    ): int {
        $publicPart = self::communityPart($voteCount, $cohortMaxVotes, $communityCurve,
                                          $fullCredit, $communityBasis);
        $judgeNorm  = self::judgePart($judgeAvg0to10, $judgeFloor, $judgeCurve);

        return self::split($publicPart, $judgeNorm, $communityWeight, $judgeWeight)['cpi'];
    }

    /**
     * THE INDEX AND ITS TWO HALVES, WORKED OUT ONCE.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * {@see ResultRelease::category()} used to compute the community half itself, from its
     * own copy of `weight × share × 1000`, and take the judge half as the remainder. That
     * agreed with this class for exactly as long as both were linear. The moment a curve
     * was put on the share, the release screen went on publishing a LINEAR community half
     * beside a curved index — so the two figures under a nominee's name no longer described
     * the number they were printed beside, and the judge half absorbed the difference:
     * two nominees on an identical 7.6 panel mark were shown 66 and 112.
     *
     * Nothing outside this class computes a part of a CPI now. The screen, the public page,
     * the share card and the feed all read the split it returns.
     *
     * THE JUDGE HALF TAKES THE ROUNDING, deliberately. `cpi` rounds the sum once, so two
     * independently rounded halves can differ from it by a point — and a split that does
     * not add up to the figure printed beside it defeats the only reason to publish the
     * working at all.
     *
     * @param float $publicPart already-curved community component, 0..1
     * @param float $judgeNorm  already-curved judge component, 0..1
     * @return array{community:int, judge:int, cpi:int}
     */
    public static function split(float $publicPart, float $judgeNorm,
                                 float $communityWeight, float $judgeWeight): array
    {
        $cpi  = (int) round(($communityWeight * $publicPart + $judgeWeight * $judgeNorm) * 1000);
        $comm = (int) round($communityWeight * $publicPart * 1000);

        return ['community' => $comm, 'judge' => $cpi - $comm, 'cpi' => $cpi];
    }

    /**
     * The community component of a nominee's index, 0..1, curved.
     *
     * Public so the scorer can hand the SAME number to {@see split()} that
     * {@see nomineeScore()} uses. Two spellings of one curve is the fault this whole
     * refactor exists to remove.
     */
    public static function communityPart(int $voteCount, int $cohortMaxVotes,
                                         ?float $curve = null, ?int $fullCredit = null,
                                         ?string $basis = null): float
    {
        if (($basis ?? self::BASIS_RELATIVE) === self::BASIS_ABSOLUTE) {
            // A nominee's OWN turnout, on the same curve as the depth discount, so the
            // two bases agree exactly for a category leader: at v = cohortMax the
            // relative share is 1 and this reduces to depth(v). Switching bases can
            // therefore never move a category's winner by itself.
            return self::depth($voteCount, $fullCredit);
        }

        $share = min(1.0, max(0.0, $voteCount / max(1, $cohortMaxVotes)));
        $rel   = $share ** self::clampCurve($curve ?? self::COMMUNITY_CURVE);

        return $rel * self::depth($cohortMaxVotes, $fullCredit);
    }

    /**
     * WHAT THE COMMUNITY HALF IS A SHARE OF.
     *
     * ══ THE FAULT THIS EXISTS TO ANSWER ═════════════════════════════════════
     *
     * `relative` measures a nominee against the leader of their OWN category. That is the
     * right question for deciding a category — being top of your field is what wins one —
     * and it is meaningless outside it. Three real rows, all on the same 0–1000 index:
     *
     *     Mr Aoyera Kayode John        19 votes, LEADER of a 19-vote field  → community 62
     *     Ogunyemi Olusola Titilope   691 votes, 35% of a 1,955-vote field  → community 56
     *     Amb. Ojewola Olawale        161 votes,  8% of the same field      → community  3
     *
     * Nineteen votes out-scoring six hundred and ninety-one. Both figures are correct and
     * neither is comparable to the other, because they are shares of different things. The
     * depth discount shrinks the small category's credit — 0.138 of full here — but the
     * share term still hands its leader the whole of that, while 8% squared is 0.68% of a
     * category that gets the whole discount waived.
     *
     * `absolute` measures a nominee against the full-credit mark itself, so the number
     * means the same thing in every category and can be ranked across them.
     *
     * ── WHY IT IS A SETTING AND NOT A CORRECTION ────────────────────────────
     *
     * Both are defensible and they answer different questions, so neither is a bug fix for
     * the other. More practically: results are PUBLISHED, and on this platform they are
     * printed onto physical awards. A cycle that has announced its standings must keep
     * them exactly, so the default is `relative` — today's behaviour, to the digit — and a
     * later cycle opts in. RuleEngine resolves it per programme and per cycle, which is
     * what makes that possible.
     *
     * ── WHAT CHANGES, MEASURED RATHER THAN ASSERTED ─────────────────────────
     *
     * No category winner and no overall position moved in either released cycle at a
     * full-credit mark of 2,000. What did move was one 3rd/4th place: 918 votes at 6.8
     * overtaking 398 votes at 7.6. That is the trade the switch makes — turnout against
     * panel mark — and it is the thing to look at before turning it on.
     *
     * Note the interaction, because it is not obvious: under `absolute` the full-credit
     * mark stops being a per-category discount and becomes the scale every nominee is
     * measured on, so everybody above it ties at full credit. At 1,000 both a 1,955-vote
     * and a 1,536-vote nominee collect the whole community half and the panel decides
     * between them — which DID flip a category winner. Raising the mark above the largest
     * real tally is what keeps turnout meaningful. {@see fullCredit()}.
     */
    public const BASIS_RELATIVE = 'relative';
    public const BASIS_ABSOLUTE = 'absolute';

    /** The basis, normalised — anything unrecognised is today's behaviour, never a guess. */
    public static function basis(?string $raw): string
    {
        return trim((string) $raw) === self::BASIS_ABSOLUTE
            ? self::BASIS_ABSOLUTE : self::BASIS_RELATIVE;
    }

    /**
     * HOW MUCH COMMUNITY THERE WAS TO WIN, 0..1.
     *
     * ══ THE FAULT THIS CLOSES ═══════════════════════════════════════════════
     *
     * The community half was purely RELATIVE — a nominee's share of the leader of their own
     * category — so the leader of any category got the whole weight whatever their support
     * actually was. Two released categories, side by side on the operator's screen:
     *
     *     Ajayi Temitope Oluwarotimi   1,955 votes   community 450
     *     Idowu Olayemi Olubukunola       89 votes   community 450
     *
     * Eighty-nine votes and nearly two thousand, paid identically. The operator's word for
     * it was "rigged", and that is the right word: a number that does not move when the
     * thing it measures changes by a factor of twenty is not measuring it.
     *
     * ── AND THE ARGUMENT THE OTHER WAY, WHICH IS ALSO REAL ──────────────────
     *
     * The relative design was deliberate: "a nominee in a small field is not punished for
     * being in one." That is a fair principle and it has a limit — it protects somebody in
     * a small FIELD, and it was being used to protect somebody with almost no SUPPORT.
     * Those are different things, and only the second one is a credibility problem. This
     * scales on the depth of support in the category, not on how many nominees are in it,
     * so a two-horse race with real backing behind it is unaffected.
     *
     * ── THE SHAPE ───────────────────────────────────────────────────────────
     *
     * `min(1, cohortMax / fullCredit)`, square-rooted so it is a discount rather than a
     * cliff. At the default full-credit mark of 1,000 votes:
     *
     *     leader on 1,955 → capped at 1.00 → the full community weight
     *     leader on   500 → 0.71           → seventy-one per cent of it
     *     leader on    89 → 0.30           → thirty per cent of it
     *
     * A SETTING, because "what a full community mandate looks like" is a judgement about
     * this award — a continental prize and a school prize do not have the same number —
     * and the operator who defends a result should own it. Setting it to 1 restores the
     * old purely-relative behaviour exactly.
     */
    public static function depth(int $cohortMaxVotes, ?int $fullCredit = null): float
    {
        $target = max(1, $fullCredit ?? self::FULL_CREDIT_VOTES);

        // FLOORED AT ONE, exactly as the share's denominator is. Without it a cohort
        // maximum of zero — the guarded degenerate case the share already handles — made
        // depth zero and took the whole community half with it, so a category nobody had
        // voted in scored differently from one whose votes had not loaded yet.
        return sqrt(min(1.0, max(1, $cohortMaxVotes) / $target));
    }

    /**
     * The category-leader vote count at which the community half pays in full.
     *
     * Below it the whole category's community weight is discounted — nobody in a field
     * nobody voted in can earn what somebody in a field thousands voted in earns.
     */
    public const FULL_CREDIT_VOTES = 1000;

    /** The judge component, 0..1, re-based on the range a panel uses and curved. */
    public static function judgePart(?float $judgeAvg0to10, ?float $floor = null, ?float $curve = null): float
    {
        if ($judgeAvg0to10 === null) return 0.0;

        $f       = max(0.0, min(9.0, $floor ?? self::JUDGE_FLOOR));
        $span    = max(0.1, 10.0 - $f);
        $rebased = min(1.0, max(0.0, ($judgeAvg0to10 - $f) / $span));

        return $rebased ** self::clampCurve($curve ?? self::JUDGE_CURVE);
    }

    /**
     * How steeply the community share falls away from the leader. 1.0 is the old linear
     * behaviour; higher is steeper.
     */
    public const COMMUNITY_CURVE = 2.0;

    /**
     * The panel mark below which the judge half is worth nothing.
     *
     * Not a pass mark for the nominee — it is a statement about the scale. Panels here do
     * not award below about five, so treating 0–5 as live range gave every judged nominee
     * a third of the judge weight for free.
     */
    public const JUDGE_FLOOR = 5.0;

    /** How steeply the judge half rises across the range above the floor. */
    public const JUDGE_CURVE = 1.5;

    /**
     * An exponent that cannot invert the measure.
     *
     * Below 1 the curve becomes generous rather than steep, which is a legitimate choice;
     * at or below 0 it stops being monotonic — every share would return 1.0 and the whole
     * community half would collapse to a constant, silently, from one bad setting.
     */
    private static function clampCurve(float $c): float
    {
        return max(0.1, min(6.0, $c));
    }

    /**
     * How much of the remaining distance to 1000 each FURTHER nomination closes.
     *
     * A quarter, so a second recognition is a real lift and a fifth is a small one. The
     * exact figure is a judgement; the two properties it has to preserve are not, and both
     * are held in CpiRollupTest: an extra nomination can never LOWER a standing, and no
     * amount of breadth can reach 1000 without a result that deserves it.
     */
    private const FURTHER_LIFT = 0.25;

    /**
     * Profile-level CPI: the best result, lifted by each further one.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * IT USED TO BE THE MEAN, AND THE MEAN PUNISHES BEING RECOGNISED TWICE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A profile with one nomination at 900 scored 900. Nominated a second time, in a
     * category they were weaker in, at 100 — they scored 500. The second nomination, which
     * nobody asked for and which is a recognition rather than a demerit, cost them four
     * hundred points and three tiers, from `diamond` to `gold`. Somebody nominated once in
     * their strongest category outranked them.
     *
     * That is not a rounding argument, it is backwards: an index of cultural power that
     * falls when the culture recognises you again is measuring something else. And it is
     * gameable in the one direction nobody wants — the play is to be nominated as little
     * as possible.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY NOT SIMPLY THE BEST RESULT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * It was the first candidate and it is nearly right: it can never punish breadth, and
     * "your index is your strongest result this year" is an answer anybody can give to a
     * nominee who asks. It throws away the thing the index is actually named for, though.
     * Somebody carried in four categories is not in the same position as somebody carried
     * in one, and a registry that ranks them identically has stopped ranking.
     *
     * So: the best result stands as the floor, and every further nomination closes a
     * fraction of what is left between it and 1000 — a fraction proportional to how good
     * that further result was. A weak second nomination adds almost nothing; a strong one
     * adds real ground; neither can ever subtract. Sorted first, so the arithmetic does
     * not depend on the order rows come back in.
     *
     * Worked, from the case above:  900 alone → 900.  900 with a 100 → 903.  900 with a
     * second 900 → 923.  Four 400s → 563, which still sits below a single 600.
     *
     * @param int[] $linkedFinals
     */
    public function profileRollup(array $linkedFinals): ?int
    {
        if (!$linkedFinals) {
            return null;
        }

        $sorted = $linkedFinals;
        rsort($sorted);

        // BOTH ends are clamped, and the seed matters as much as the lift. `nomineeScore()`
        // cannot return outside 0–1000 today, so this guards a row rather than a caller —
        // a judge average stored above ten, a column somebody backfilled — but the seed is
        // the best result, and an uncapped one is published verbatim: [900, 4000] came out
        // at 3325 "of 1000" with the lift alone clamped. The ceiling has to be a property
        // of this function, not of its inputs.
        $score = max(0.0, min(1000.0, (float) array_shift($sorted)));

        foreach ($sorted as $further) {
            // A further result cannot be worth negative ground — that is the mean's defect
            // coming back through the other door — and one above the ceiling would close
            // MORE than the distance remaining.
            $weight = max(0.0, min(1.0, (float) $further / 1000.0));
            $score += (1000.0 - $score) * $weight * self::FURTHER_LIFT;
        }

        return (int) round($score);
    }

    /**
     * Baseline for profiles not yet linked to any nominee:
     * 50% verification + 30% completeness + 20% reach (capped at 5000 views).
     */
    public function baselineScore(?string $verificationTier, float $completenessPct, int $viewCount): int
    {
        $score = 0.50 * ((self::VERIFY[$verificationTier ?? 'none'] ?? 0) / 100)
               + 0.30 * ($completenessPct / 100)
               + 0.20 * min(1.0, $viewCount / 5000);

        return (int) round($score * 1000);
    }

    public function tierFor(int $score): string
    {
        foreach (self::TIERS as [$name, $min]) {
            if ($score >= $min) {
                return $name;
            }
        }
        return 'unranked';
    }
}
