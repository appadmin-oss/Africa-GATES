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
    public function nomineeScore(int $voteCount, int $cohortMaxVotes, ?float $judgeAvg0to10, float $communityWeight = 0.45, float $judgeWeight = 0.55): int
    {
        $max        = max(1, $cohortMaxVotes);
        $publicPart = min(1.0, $voteCount / $max);
        $judgeNorm  = $judgeAvg0to10 !== null ? $judgeAvg0to10 / 10.0 : 0.0;

        return (int) round(($communityWeight * $publicPart + $judgeWeight * $judgeNorm) * 1000);
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
