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
     * Profile-level CPI = mean of its linked nominees' final scores.
     * @param int[] $linkedFinals
     */
    public function profileRollup(array $linkedFinals): ?int
    {
        if (!$linkedFinals) {
            return null;
        }
        return (int) round(array_sum($linkedFinals) / count($linkedFinals));
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
