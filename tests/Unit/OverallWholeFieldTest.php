<?php
declare(strict_types=1);

namespace Tests\Unit;

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

    public function test_a_strong_second_is_ranked_above_the_winner_of_a_thinner_field(): void
    {
        $this->seedCycle();

        $o    = ResultRelease::overall(self::CYCLE);
        $names = array_column($o['contenders'], 'name');

        $this->assertContains('Strong second', $names,
            'the cycle-wide standing still excludes everybody who did not win their own '
            . 'category, so its second-strongest nominee is simply absent');

        $this->assertSame(
            ['Leader of the deep field', 'Strong second', 'Leader of the thin field'],
            $names);
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

    /** The winner is unchanged by widening the list — only the places below it move. */
    public function test_the_top_of_the_cycle_is_not_disturbed(): void
    {
        $this->seedCycle();

        $o = ResultRelease::overall(self::CYCLE);
        $this->assertSame('Leader of the deep field', $o['winner']['name']);
        $this->assertSame('Strong second', $o['runner_up']['name']);
        $this->assertSame($o['winner']['cpi'] - $o['runner_up']['cpi'], $o['margin']);
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
