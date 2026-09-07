<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NomineeScoringService;
use AfricaGates\Services\ResultRelease;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE CYCLE'S STANDING IS EVERY NOMINEE IN IT, NOT ONE PER CATEGORY.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT USED TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see ResultRelease::overall()} took each category's WINNER and ranked those, arguing
 * that "an overall award which could go to somebody who did not win their own category is
 * not an overall award, it is a second opinion."
 *
 * Sound about an AWARD, wrong about a STANDING — and it produces a standing. On a real
 * released cycle it gave an overall second place of 89 votes and a third of 19, while
 *
 *     Dr. Adegboyega Aborode   1,536 votes · 8.0/10 · CPI 533 · second in his category
 *
 * did not appear at all: the highest panel mark in the cycle and its second-largest
 * tally, absent from "the best of the cycle" because of who else happened to enter
 * Academic Excellence. The list it did produce was the category winners in CPI order —
 * which the per-category tables already are, so it added nothing while excluding the one
 * thing it could have said.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE LINE THAT DOES NOT MOVE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A nominee below the judge quorum has no judge half — not withheld, ZERO — so their
 * figure is a community-only score wearing the same column as a full CPI. Widening the
 * list is exactly the change that would sweep those in, and one of them on the live cycle
 * has 691 votes, enough to sit mid-table among finished results. That is the comparison
 * this must never make, so it is asserted rather than assumed.
 */
final class OverallWholeFieldTest extends TestCase
{
    private const CYCLE = 1;

    /**
     * Two judges, and nothing added to the rubric.
     *
     * The harness ships four equally-weighted criteria, and a scorecard counts only if it
     * covers EVERY active one — a judge who scored a single criterion is dropped whole, so
     * a fixture that seeds its own "overall" criterion and scores that alone produces a
     * category where nobody is eligible and every assertion below fails for the wrong
     * reason. Score all of them at the same mark and the weighted average is that mark.
     */
    private function rubric(): void
    {
        foreach ([1, 2] as $j) {
            DB::table('gates_judges')->insertOrIgnore([
                'id' => $j, 'name' => 'Judge ' . $j, 'email' => 'j' . $j . '@x.test', 'is_active' => 1,
            ]);
        }
    }

    /** @return list<int> every criterion a complete scorecard has to cover */
    private function criteria(): array
    {
        return array_map('intval',
            DB::table('gates_judge_criteria')->where('is_active', 1)->pluck('id')->all());
    }

    private function category(int $id, string $title): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => self::CYCLE, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'judged',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => $id, 'cycle_id' => self::CYCLE, 'slug' => 'c' . $id, 'title' => $title,
        ]);
    }

    /** @param int|null $judged how many of the two judges filed a complete scorecard */
    private function nominee(int $id, int $categoryId, string $name, int $votes,
                             int $mark, int $judged = 2): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => $categoryId, 'name' => $name, 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => $votes, 'organic_vote_count' => 0,
        ]);
        for ($j = 1; $j <= $judged; $j++) {
            foreach ($this->criteria() as $cid) {
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $id, 'category_id' => $categoryId,
                    'criterion_id' => $cid, 'score' => $mark,
                ]);
            }
        }
    }

    /**
     * The shape of the real cycle: a deep category whose runner-up out-scores the winner
     * of a thin one, plus a big-tally nominee stuck below the quorum.
     */
    private function seedCycle(): void
    {
        $this->rubric();
        $this->category(10, 'Academic Excellence');
        $this->category(11, 'Social Development');

        $this->nominee(1, 10, 'Leader of the deep field',  1955, 8);
        $this->nominee(2, 10, 'Strong second',             1536, 8);
        $this->nominee(3, 10, 'Big tally, one scorecard',   691, 8, judged: 1);
        $this->nominee(4, 11, 'Leader of the thin field',     89, 8);
    }

    // ══ the whole field ══════════════════════════════════════════════════════

    /**
     * EVERY NOMINEE IS RANKED, AND A THIN FIELD'S LEADER NO LONGER TIES A DEEP ONE'S.
     *
     * The first half of that is the property this file was written for and it holds: the
     * cycle-wide standing contains a nominee who did not win their own category.
     *
     * ── AND THE SECOND HALF IS THE WHOLE POINT OF THE EDITION-WIDE SCALE ─────
     *
     * Both community terms are shares of a maximum, and that maximum is the largest held by
     * any nominee in the EDITION — not in the nominee's own category
     * ({@see \AfricaGates\Services\NomineeScoringService::editionScale()}). So a share means
     * the same thing wherever it appears, and this column is an addition of like with like:
     *
     *     Leader of the deep field   1,955 votes, panel 8.0   →  890   (1955/1955)
     *     Strong second              1,536 votes, panel 8.0   →  794   (1536/1955)
     *     Leader of the thin field      89 votes, panel 8.0   →  460   (  89/1955)
     *
     * Under the per-category scale the last two were 794 and 890: eighty-nine votes
     * out-ranking fifteen hundred, because each had been measured against a different
     * denominator and the overall standing then added the two together. The operator's word
     * for that was "cheating".
     *
     * ── WHAT IT COSTS, RECORDED RATHER THAN DISCOVERED ──────────────────────
     *
     * Leading a small category is no longer worth the full community half. In a category
     * whose whole field is small against the edition, every community half is small and the
     * differences between its nominees are smaller still — so the 550 the panel carries
     * decides that category very nearly on its own. That is intended: the community half
     * measures public backing, and where there was little public backing it should pay
     * little. It is asserted here so whoever revisits it is looking at the actual numbers.
     */
    public function test_a_thin_field_leader_is_measured_against_the_whole_edition(): void
    {
        $this->seedCycle();

        $o     = ResultRelease::overall(self::CYCLE);
        $names = array_column($o['contenders'], 'name');
        $byName = array_column($o['contenders'], 'cpi', 'name');

        $this->assertContains('Strong second', $names,
            'the cycle-wide standing still excludes everybody who did not win their own '
            . 'category, so its second-strongest nominee is simply absent');

        $this->assertSame(
            ['Leader of the deep field', 'Strong second', 'Leader of the thin field'],
            $names);

        $this->assertSame(890, $byName['Leader of the deep field']);
        $this->assertSame(794, $byName['Strong second']);
        $this->assertSame(460, $byName['Leader of the thin field'],
            'an 89-vote category leader is being paid as though 89 were the most anybody '
            . 'in the edition managed — which is the per-category denominator back');
    }

    /**
     * AND THE DENOMINATOR IS THE SAME NUMBER IN EVERY CATEGORY OF THE EDITION.
     *
     * The property, asserted directly rather than inferred from the scores above, because
     * it is the one thing the change consists of. A category whose own leader has 89 votes
     * is scored against 1,955, and the scorer says whose 1,955 it is — the scale-setter is
     * in another category now, so a screen scanning its own rows for the number would find
     * nobody and report the scale as unset.
     */
    public function test_every_category_is_scored_against_one_denominator(): void
    {
        $this->seedCycle();

        $scoring = new NomineeScoringService();
        $deep = $scoring->scoreCategory(10);
        $thin = $scoring->scoreCategory(11);

        $this->assertSame(1955, $deep[1]['cohort_max']);
        $this->assertSame(1955, $thin[4]['cohort_max'],
            'the thin category is still being normalised to its own leader');

        $this->assertSame('edition', $thin[4]['cohort_scope']);
        $this->assertSame('Leader of the deep field', $thin[4]['cohort_max_by']['name']);
        $this->assertSame(10, $thin[4]['cohort_max_by']['category_id'],
            'the scale-setter has to carry their own category, or the release screen '
            . 'cannot say where the denominator came from');
    }

    /** Two rows from one category is the point, not an accident. */
    public function test_one_category_may_hold_more_than_one_place(): void
    {
        $this->seedCycle();

        $cats = array_column(ResultRelease::overall(self::CYCLE)['contenders'], 'category');
        $this->assertSame(2, count(array_keys($cats, 'Academic Excellence', true)));
    }

    /**
     * AND EACH ROW SAYS WHETHER IT WON ITS OWN CATEGORY.
     *
     * Without it the table reads as a second set of category results that disagrees with
     * the first — a screen listing two Academic Excellence nominees, neither marked, looks
     * like the category was scored twice.
     */
    public function test_each_row_says_whether_it_also_won_its_category(): void
    {
        $this->seedCycle();

        $by = array_column(ResultRelease::overall(self::CYCLE)['contenders'], 'won_category', 'name');

        $this->assertTrue($by['Leader of the deep field']);
        $this->assertTrue($by['Leader of the thin field']);
        $this->assertFalse($by['Strong second'],
            'a runner-up is being presented as having won their category');
    }

    // ══ the line that does not move ══════════════════════════════════════════

    /**
     * A COMMUNITY-ONLY SCORE IS NOT A RESULT.
     *
     * 691 votes and one of two scorecards. Their judge half is zero rather than withheld,
     * so the figure is not a CPI and cannot be ranked against one — and it is big enough
     * to land mid-table if it were, which is what makes this worth pinning rather than
     * trusting.
     */
    public function test_a_nominee_below_the_judge_quorum_is_still_excluded(): void
    {
        $this->seedCycle();

        $o = ResultRelease::overall(self::CYCLE);

        $this->assertNotContains('Big tally, one scorecard', array_column($o['contenders'], 'name'),
            'widening the list swept in a community-only score, which now sits in the same '
            . 'column as finished results');

        foreach ($o['contenders'] as $c) {
            $this->assertFalse($c['provisional'], $c['name'] . ' is a provisional figure');
            $this->assertTrue($c['in_running'], $c['name'] . ' is not in the running');
        }
    }

    /**
     * The winner is unchanged by widening the list — only the places below it move.
     *
     * The runner-up is the deep field's SECOND, on 1,536 votes, ahead of the thin field's
     * leader on 89. Under the per-category denominator it was the other way round, and the
     * margin at the top of the cycle was nothing at all. A real margin between first and
     * second is the thing an operator most needs before an announcement, so both the order
     * and the size of the gap are asserted rather than tolerated.
     */
    public function test_the_top_of_the_cycle_is_not_disturbed(): void
    {
        $this->seedCycle();

        $o = ResultRelease::overall(self::CYCLE);
        $this->assertSame('Leader of the deep field', $o['winner']['name']);
        $this->assertSame('Strong second', $o['runner_up']['name'],
            'an 89-vote category leader is second in the cycle again');
        $this->assertSame($o['winner']['cpi'] - $o['runner_up']['cpi'], $o['margin']);
        $this->assertSame(96, $o['margin']);
    }

    /** Nothing scored, nothing to rank — and it says so rather than erroring. */
    public function test_a_cycle_that_has_crowned_nobody_returns_an_empty_standing(): void
    {
        $this->rubric();
        $this->category(10, 'Academic Excellence');
        $this->nominee(1, 10, 'One scorecard only', 400, 8, judged: 1);

        $o = ResultRelease::overall(self::CYCLE);
        $this->assertNull($o['winner']);
        $this->assertSame([], $o['contenders']);
    }
}
