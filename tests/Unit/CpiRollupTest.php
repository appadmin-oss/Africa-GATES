<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CpiService;
use Tests\TestCase;

/**
 * A PERSON'S STANDING MUST NOT FALL BECAUSE THE CULTURE RECOGNISED THEM AGAIN.
 *
 * The profile index was the MEAN of a person's nominee scores. So somebody with one
 * nomination at 900 scored 900; nominated a second time, in a category they were weaker
 * in, at 100, they scored 500 — four hundred points and three tiers, `diamond` down to
 * `gold`, for being put forward twice. Somebody nominated once in their strongest category
 * outranked them, and the play was to be nominated as little as possible.
 *
 * The replacement is the best result as a floor, with each further nomination closing a
 * fraction of what remains between it and 1000, in proportion to how good that further
 * result was.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTIES ARE THE TEST, NOT THE NUMBERS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The lift fraction is a judgement and somebody will want to move it. What must survive
 * any such move is held here as properties over generated input rather than as worked
 * examples: monotone in the set, monotone in each member, bounded by the ceiling, floored
 * at the best result, and independent of the order rows happen to come back in. A worked
 * example pins one arithmetic; a property pins the promise made to a nominee.
 */
final class CpiRollupTest extends TestCase
{
    private CpiService $cpi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cpi = new CpiService();
    }

    /** Deterministic, so a failure here is reproducible rather than a story about a seed. */
    private function sets(): \Generator
    {
        mt_srand(20260904);
        for ($i = 0; $i < 400; $i++) {
            $n   = mt_rand(1, 6);
            $set = [];
            for ($k = 0; $k < $n; $k++) $set[] = mt_rand(0, 1000);
            yield $set;
        }
    }

    // ══ the case that shipped ════════════════════════════════════════════════

    public function test_a_second_weaker_nomination_does_not_cost_a_person_their_standing(): void
    {
        $alone = $this->cpi->profileRollup([900]);
        $twice = $this->cpi->profileRollup([900, 100]);

        $this->assertSame(900, $alone);
        $this->assertGreaterThanOrEqual(900, (int) $twice,
            'being nominated a second time lowered a standing, which is the whole defect');
        $this->assertNotSame(500, $twice, 'this is the mean again');

        // And the tier moves with it. `diamond` starts at 850; the mean put this person in
        // `gold`, two rungs down, for a recognition they did not ask for.
        $this->assertSame('diamond', $this->cpi->tierFor((int) $twice));
        $this->assertSame('gold', $this->cpi->tierFor(500),
            'the tier ladder moved; the point of the case above was a three-rung fall');
    }

    /** One nomination is that nomination — nothing is added to a person standing alone. */
    public function test_a_single_nomination_is_its_own_score(): void
    {
        foreach ([0, 1, 250, 640, 1000] as $s) {
            $this->assertSame($s, $this->cpi->profileRollup([$s]));
        }
    }

    public function test_no_nominations_is_null_and_not_zero(): void
    {
        // Null is what sends the recompute to the baseline. Zero would publish a real
        // standing of nought for everybody who has never been nominated.
        $this->assertNull($this->cpi->profileRollup([]));
    }

    // ══ the properties ═══════════════════════════════════════════════════════

    /**
     * THE PROMISE: one more nomination never makes a person worse off.
     *
     * Over generated sets, and with the added result drawn across the whole range —
     * including a zero, which is the case that broke the mean hardest.
     */
    public function test_an_extra_nomination_never_lowers_the_score(): void
    {
        foreach ($this->sets() as $set) {
            $before = (int) $this->cpi->profileRollup($set);

            foreach ([0, 1, 300, 700, 1000] as $extra) {
                $after = (int) $this->cpi->profileRollup([...$set, $extra]);
                $this->assertGreaterThanOrEqual($before, $after,
                    'adding ' . $extra . ' to [' . implode(',', $set) . '] fell from '
                    . $before . ' to ' . $after);
            }
        }
    }

    /** And a BETTER further result is never worth less than a worse one. */
    public function test_a_stronger_further_nomination_is_never_worth_less(): void
    {
        foreach ($this->sets() as $set) {
            $low  = (int) $this->cpi->profileRollup([...$set, 200]);
            $high = (int) $this->cpi->profileRollup([...$set, 800]);
            $this->assertGreaterThanOrEqual($low, $high,
                'a stronger further nomination scored below a weaker one on ['
                . implode(',', $set) . ']');
        }
    }

    /**
     * Floored at the best result and bounded by the ceiling.
     *
     * The floor is what makes "your index is at least your strongest result" true. The
     * ceiling is what stops breadth alone reaching the top of the ladder: 1000 has to be
     * earned by a result, not accumulated by being nominated often.
     */
    public function test_the_score_sits_between_the_best_result_and_the_ceiling(): void
    {
        foreach ($this->sets() as $set) {
            $got = (int) $this->cpi->profileRollup($set);

            $this->assertGreaterThanOrEqual(max($set), $got,
                'below the best result on [' . implode(',', $set) . ']');
            $this->assertLessThanOrEqual(1000, $got,
                'past the ceiling on [' . implode(',', $set) . ']');
        }
    }

    /**
     * The order rows come back in is a database detail, not a fact about a person.
     *
     * `CpiRecomputeCommand` collects these per category as it walks them, so the order is
     * whatever the category loop produced. A rollup that depended on it would give the same
     * person different scores on different runs.
     */
    public function test_the_answer_does_not_depend_on_the_order_of_the_nominations(): void
    {
        foreach ($this->sets() as $set) {
            $straight = $this->cpi->profileRollup($set);

            $shuffled = $set;
            mt_srand(count($set));
            shuffle($shuffled);

            $this->assertSame($straight, $this->cpi->profileRollup($shuffled),
                'order changed the answer on [' . implode(',', $set) . ']');
        }
    }

    /**
     * A CORRUPT NOMINEE SCORE MUST NOT BREAK EITHER PROMISE.
     *
     * `nomineeScore()` cannot return outside 0–1000 today, so this guards a row rather
     * than a caller: a judge average stored above ten, a figure written by an older
     * migration, a column somebody backfilled. The two promises this rollup makes are
     * exactly the ones a stray value would break — the ceiling, and never subtracting —
     * and both would break silently, on a published standing.
     */
    public function test_a_score_outside_the_range_cannot_break_the_ceiling_or_the_floor(): void
    {
        // Above the ceiling: closes MORE than the distance remaining, and overshoots.
        $this->assertLessThanOrEqual(1000, (int) $this->cpi->profileRollup([900, 4000]),
            'a corrupt further result pushed a standing past the ceiling');

        // Below nothing: closes a NEGATIVE distance, and subtracts — which is the mean's
        // defect coming back through the other door.
        $this->assertGreaterThanOrEqual(900, (int) $this->cpi->profileRollup([900, -500]),
            'a negative further result lowered a standing');

        // And a corrupt BEST result is clamped to the ceiling rather than published
        // verbatim — "3,325 of 1000" on somebody's public profile is worse than the
        // corruption it reports.
        $this->assertSame(1000, (int) $this->cpi->profileRollup([4000, 500]));
    }

    /**
     * Breadth counts, and it does not overrun quality.
     *
     * The failure mode on the other side of the mean is an index anybody can climb by
     * collecting weak nominations. Four results at 400 stay below one at 600; it takes five
     * before they edge past, and five recognitions is not nothing.
     */
    public function test_breadth_is_rewarded_without_beating_a_better_single_result(): void
    {
        $this->assertLessThan($this->cpi->profileRollup([600]),
                              $this->cpi->profileRollup([400, 400, 400, 400]));

        $this->assertGreaterThan($this->cpi->profileRollup([400]),
                                 $this->cpi->profileRollup([400, 400]),
            'a second equal nomination added nothing at all');
    }
}
