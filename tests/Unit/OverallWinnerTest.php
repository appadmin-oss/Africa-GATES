<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{JudgeRubric, ResultRelease};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ONE AWARD FOR THE WHOLE CYCLE, AND THE THING IT CANNOT FIX.
 *
 * Every award here was decided inside a category. An overall winner has to be drawn ACROSS
 * them, and that is where a CPI stops being straightforwardly comparable:
 *
 *   · the judge half is absolute — six out of ten is six out of ten in any field;
 *   · the community half is a share of THAT CATEGORY'S own leader.
 *
 * So leading a three-person category on fifty votes is a full community half, and coming a
 * close second in a fifty-thousand-vote category is not. There is no neutral denominator
 * available: normalising across the cycle instead simply inverts the bias and hands the
 * award to whoever stands in the most popular category, where a niche field could never win
 * it. Every option is a position rather than a calculation.
 *
 * The position taken is the conservative one — the same CPI, the same comparator, nothing
 * recomputed and no second score invented — with the figures that make the bias visible
 * handed back beside it. What this file holds is that those figures are actually there, and
 * that the overall award can never disagree with the category awards it is drawn from.
 */
final class OverallWinnerTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'overall-' . bin2hex(random_bytes(3)),
            'title' => 'Overall Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'judging',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
    }

    private function category(string $title, int $order = 1): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => strtolower(str_replace(' ', '-', $title)),
            'title' => $title, 'sort_order' => $order,
        ]);
    }

    private function nominee(int $cat, string $name, int $organic): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $organic, 'vote_count' => $organic,
        ]);
    }

    private function judge(string $name): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $name, 'is_active' => 1,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'programme_ids' => json_encode([$this->programmeId]),
        ]);
    }

    /** Quorum is two COMPLETE scorecards; both judges mark the same, so the average is $mark. */
    private function panel(int $cat, int $nominee, int $mark): void
    {
        static $n = 0;
        foreach ([$this->judge('J' . (++$n)), $this->judge('J' . (++$n))] as $j) {
            foreach (JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $cat,
                    'criterion_id' => (int) $c->id, 'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    // ══ the award ════════════════════════════════════════════════════════════

    public function test_the_overall_winner_is_the_strongest_category_winner(): void
    {
        $music = $this->category('Music', 1);
        $film  = $this->category('Film', 2);

        $ada = $this->nominee($music, 'Adaeze Nwankwo', 1000);
        $this->nominee($music, 'Runner in music', 400);
        $this->panel($music, $ada, 9);

        $tunde = $this->nominee($film, 'Tunde Cole', 1000);
        $this->nominee($film, 'Runner in film', 400);
        $this->panel($film, $tunde, 6);

        $o = ResultRelease::overall($this->cycleId);

        $this->assertSame('Adaeze Nwankwo', $o['winner']['name']);
        $this->assertSame('Music', $o['winner']['category']);
        $this->assertSame('Tunde Cole', $o['runner_up']['name']);
        $this->assertGreaterThan(0, $o['margin']);
    }

    /**
     * NOBODY WHO LOST THEIR OWN CATEGORY CAN HOLD THE OVERALL AWARD.
     *
     * An overall award that could go to a runner-up is not an overall award, it is a second
     * opinion — and the first question anybody would ask is why the person who beat them in
     * their own field is not holding this one.
     */
    public function test_only_category_winners_are_in_contention(): void
    {
        $music = $this->category('Music', 1);
        $film  = $this->category('Film', 2);

        // A film runner-up who out-scores the music winner on the raw index.
        $ada = $this->nominee($music, 'Adaeze Nwankwo', 1000);
        $this->panel($music, $ada, 5);

        $tunde  = $this->nominee($film, 'Tunde Cole', 1000);
        $strong = $this->nominee($film, 'Strong Runner', 900);
        $this->panel($film, $tunde, 10);
        $this->panel($film, $strong, 9);

        $o = ResultRelease::overall($this->cycleId);

        $names = array_column($o['contenders'], 'name');
        $this->assertNotContains('Strong Runner', $names,
            'somebody who did not win their own category was in contention for the overall');
        $this->assertSame(['Tunde Cole', 'Adaeze Nwankwo'], $names);
    }

    /** A cycle where nothing has been decided has no overall winner, and says so. */
    public function test_a_cycle_that_has_crowned_nobody_has_no_overall_winner(): void
    {
        $music = $this->category('Music', 1);
        $ada   = $this->nominee($music, 'Adaeze Nwankwo', 1000);
        // One judge — below quorum, so the category crowns nobody.
        $j = $this->judge('Solo');
        foreach (JudgeRubric::effective($this->programmeId) as $c) {
            if ((int) $c->is_active !== 1) continue;
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $j, 'nominee_id' => $ada, 'category_id' => $music,
                'criterion_id' => (int) $c->id, 'score' => 10,
                'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
            ]);
        }

        $o = ResultRelease::overall($this->cycleId);

        $this->assertNull($o['winner']);
        $this->assertSame([], $o['contenders']);
        $this->assertNull($o['margin']);
        $this->assertFalse($o['dead_heat']);
    }

    // ══ the caveat it must not hide ═══════════════════════════════════════════

    /**
     * THE BIAS IS REPORTED, BECAUSE IT CANNOT BE REMOVED.
     *
     * A winner from a two-person field and a winner from a large one arrive at this
     * comparison with different denominators behind their community halves. The screen has
     * to be able to say so, which means the field size and the cohort maximum have to travel
     * with each contender rather than be worked out again by the template.
     */
    public function test_every_contender_carries_the_figures_that_show_the_comparison_is_uneven(): void
    {
        $thin = $this->category('Thin field', 1);
        $wide = $this->category('Wide field', 2);

        $small = $this->nominee($thin, 'Small Field Winner', 50);
        $rival = $this->nominee($thin, 'Only Rival', 20);
        $this->panel($thin, $small, 8);
        $this->panel($thin, $rival, 5);
        // And somebody nobody has judged. `field` counts who was IN THE RUNNING — the
        // people the winner could actually have lost to — not who entered. A category
        // where one person cleared quorum has a field of one however long the entry list
        // was, and that is exactly the number an operator needs before publishing.
        // Ten votes, deliberately: the cohort is NOT narrowed by the quorum (below quorum
        // is pending, not out — see NomineeScoringService), so an unjudged entrant with a
        // big vote count would set the denominator here and this test would be about that
        // instead. It is held where it belongs, in the cohort tests.
        $this->nominee($thin, 'Never Judged', 10);

        $big = $this->nominee($wide, 'Wide Field Winner', 50000);
        foreach (['A', 'B', 'C', 'D'] as $i => $n) {
            $r = $this->nominee($wide, 'Rival ' . $n, 40000 - $i * 1000);
            $this->panel($wide, $r, 7);
        }
        $this->panel($wide, $big, 8);

        $o = ResultRelease::overall($this->cycleId);

        $by = [];
        foreach ($o['contenders'] as $c) $by[$c['category']] = $c;

        $this->assertSame(2, $by['Thin field']['field'],
            'the size of the field a winner actually beat is not reported — and an '
            . 'unjudged entrant must not pad it, because they could not have won');
        $this->assertSame(5, $by['Wide field']['field']);
        $this->assertSame(50, $by['Thin field']['cohort_max'],
            'the denominator behind this winner\'s community half is not reported');
        $this->assertSame(50000, $by['Wide field']['cohort_max']);

        $this->assertSame(2, $o['thinnest_field'],
            'nothing tells an operator the smallest field in the running');

        // And the bias is real rather than theoretical: both won on the same judge mark,
        // and fifty votes in a two-person field buys the same community half as fifty
        // thousand in a five-person one.
        $this->assertSame($by['Thin field']['community_points'],
                          $by['Wide field']['community_points'],
            'the fixture no longer demonstrates the thing the caveat warns about');
    }

    /**
     * A dead heat is NAMED rather than silently broken.
     *
     * The comparator falls back to the lower nominee id, which is deterministic and is not
     * a result — the same rule and the same reason as a dead heat inside a category.
     */
    public function test_a_dead_heat_for_the_overall_award_is_reported(): void
    {
        $music = $this->category('Music', 1);
        $film  = $this->category('Film', 2);

        foreach ([[$music, 'Adaeze Nwankwo'], [$film, 'Tunde Cole']] as [$cat, $name]) {
            $w = $this->nominee($cat, $name, 1000);
            $this->nominee($cat, 'Runner in ' . $cat, 400);
            $this->panel($cat, $w, 7);
        }

        $o = ResultRelease::overall($this->cycleId);

        $this->assertTrue($o['dead_heat'],
            'two winners level on index and on organic votes were separated silently');
        $this->assertSame(0, $o['margin']);
    }

    /**
     * ONE COMPARATOR ON THIS PLATFORM.
     *
     * The overall award must not be able to disagree with the category awards it is drawn
     * from. Asserted as an identity against `ResultRelease::order()` rather than by
     * re-listing an expected order, so it survives any later change to how a tie breaks.
     */
    public function test_the_overall_order_is_the_same_comparator_the_categories_use(): void
    {
        foreach ([['Music', 1000, 9], ['Film', 900, 9], ['Design', 1000, 6]] as $i => [$t, $v, $m]) {
            $cat = $this->category($t, $i + 1);
            $w   = $this->nominee($cat, $t . ' Winner', $v);
            $this->nominee($cat, $t . ' Runner', (int) ($v / 3));
            $this->panel($cat, $w, $m);
        }

        $o = ResultRelease::overall($this->cycleId);

        $mine = $o['contenders'];
        usort($mine, ResultRelease::order(...));

        $this->assertSame(array_column($mine, 'name'), array_column($o['contenders'], 'name'),
            'the overall award ranks its contenders differently from the categories');
    }
}
