<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{JudgeRubric, ResultRelease};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ONE AWARD FOR THE WHOLE CYCLE, AND WHAT IT COSTS TO MAKE IT COMPARABLE.
 *
 * Every award here was decided inside a category. An overall winner has to be drawn ACROSS
 * them, and a CPI is only comparable across categories if both halves are:
 *
 *   · the judge half is absolute — six out of ten is six out of ten in any field;
 *   · the community half USED TO BE a share of that category's own leader.
 *
 * So leading a three-person category on fifty votes was a full community half, and coming a
 * close second in a fifty-thousand-vote category was not — and the overall standing added
 * the two together as though they meant the same thing. The operator's word for that was
 * "cheating", and it is the right word for a number that does not move when the thing it
 * measures changes by a factor of a thousand.
 *
 * ── SO THE DENOMINATOR IS THE EDITION, AND THE OBJECTION TO THAT IS REAL ───
 *
 * This file used to argue that normalising across the cycle "simply inverts the bias and
 * hands the award to whoever stands in the most popular category, where a niche field could
 * never win it". That objection has not gone away and it is not answered — it is ACCEPTED,
 * with its eyes open. A niche category with little public backing now contributes very
 * little community credit to anybody in it, so its nominees reach the overall standing on
 * their panel mark and almost nothing else.
 *
 * That is the trade: the old rule let a small field buy a full community half, the new one
 * pays a small field a small community half. Only one of them can be true at once, and the
 * one that survives is the one under which a share means the same thing wherever it is
 * printed. {@see \AfricaGates\Services\NomineeScoringService::editionScale()}.
 *
 * `field` and `thinnest_field` still travel with every contender, because HOW MANY PEOPLE
 * somebody beat is a separate fact from how much support they had, and that one is still
 * uneven: a winner from a two-person field beat one rival. The screen has to be able to say
 * so.
 *
 * What this file holds is that those figures are actually there, that every category is
 * scored against one denominator, and that the overall award can never disagree with the
 * category awards it is drawn from.
 */
final class OverallWinnerTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // The community half is scaled by how deep the support in a category actually was
        // (CpiService::depth) — a leader on 89 votes no longer collects what a leader on
        // 1,955 collects. These fixtures use small counts to keep their arithmetic legible,
        // so the mark is set to 1: depth becomes 1.0 and the test is about the thing it is
        // about. The discount has its own tests in CpiServiceTest.
        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['community_full_credit_votes' => 1]);

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
     * EVERY NOMINEE WHO FINISHED, NOT ONE PER CATEGORY.
     *
     * ── WHAT THIS TEST USED TO ASSERT ────────────────────────────────────────
     *
     * That "Strong Runner" was NOT in contention — "an overall award that could go to a
     * runner-up is not an overall award, it is a second opinion, and the first question
     * anybody would ask is why the person who beat them in their own field is not holding
     * this one."
     *
     * ── WHY IT CHANGED ───────────────────────────────────────────────────────
     *
     * That argument is sound about an AWARD and wrong about a STANDING, and this produces
     * a standing. On a real released cycle the old rule gave an overall second place of 89
     * votes and a third of 19, while the nominee on 1,536 votes with the highest panel
     * mark in the whole cycle — second in the deepest category — did not appear at all.
     *
     * The list it produced was the category winners in CPI order, which the per-category
     * tables already are. It added nothing and excluded the one thing it could have said.
     *
     * Here that is Strong Runner, who out-scores the music winner: 900 votes and a 9 in a
     * field somebody took with 1,000 and a 10. Being narrowly beaten in a strong field
     * says more about a nominee than winning a weak one, and the standing now says it.
     * `won_category` marks the distinction so no screen presents them as a category winner.
     */
    public function test_the_standing_ranks_every_nominee_who_finished(): void
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
        $this->assertContains('Strong Runner', $names,
            'the standing still excludes everybody who did not win their own category, so '
            . 'the second-strongest nominee in the cycle is simply absent from it');
        $this->assertSame(['Tunde Cole', 'Strong Runner', 'Adaeze Nwankwo'], $names);

        // The award itself is unmoved — only the places below it fill in.
        $this->assertSame('Tunde Cole', $o['winner']['name']);

        $won = array_column($o['contenders'], 'won_category', 'name');
        $this->assertTrue($won['Tunde Cole']);
        $this->assertTrue($won['Adaeze Nwankwo']);
        $this->assertFalse($won['Strong Runner'],
            'a runner-up is being presented as having won their category');
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
     * ONE DENOMINATOR FOR THE WHOLE CYCLE — AND THE FIELD SIZE STILL REPORTED.
     *
     * Two winners on the same panel mark, one from a two-person field on 50 votes and one
     * from a five-person field on 50,000. They used to arrive here with 450 community
     * points each, because each had been measured against their own category's leader, and
     * this test asserted that equality as the bias the screen had to warn about.
     *
     * It is not a warning any more, it is the arithmetic: both are shares of 50,000, so the
     * thin winner's community half is 0 and the wide winner's is 450. The caveat that
     * remains is the one about the FIELD — beating one rival is not beating four — and that
     * is a different fact from how much support somebody had, so it still has to travel
     * with each contender rather than be worked out again by the template.
     */
    public function test_every_contender_is_scored_against_one_denominator(): void
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

        // Keyed by category, taking the WINNER of each. The standing lists every nominee
        // who finished, so a plain category => row map keeps whichever row happens to come
        // last — which is a runner-up, and this test is about what a winner's field looked
        // like.
        $by = [];
        foreach ($o['contenders'] as $c) {
            if ($c['won_category']) $by[$c['category']] = $c;
        }

        $this->assertSame(2, $by['Thin field']['field'],
            'the size of the field a winner actually beat is not reported — and an '
            . 'unjudged entrant must not pad it, because they could not have won');
        $this->assertSame(5, $by['Wide field']['field']);
        // THE SAME NUMBER IN BOTH CATEGORIES. This used to be 50 and 50,000 — each
        // category normalised to its own leader — which is what made the two community
        // halves below incomparable and the standing that adds them meaningless.
        $this->assertSame(50000, $by['Thin field']['cohort_max'],
            'the thin category is being normalised to its own leader again, so fifty '
            . 'votes and fifty thousand are about to be paid the same');
        $this->assertSame(50000, $by['Wide field']['cohort_max']);

        $this->assertSame(2, $o['thinnest_field'],
            'nothing tells an operator the smallest field in the running');

        // And the consequence, stated as a number rather than as a caveat: on the same
        // panel mark, fifty votes buys nothing and fifty thousand buys everything the
        // community half has to give.
        //
        // THAT CEILING IS 135 HERE, NOT 450, and deliberately so. This fixture writes
        // tallies with no `gates_votes` rows behind them — fifty thousand of them would be
        // a slow test for no gain — so nobody's supporters are countable anywhere in the
        // edition and the people term, which is 70% of the half, is not paid. See
        // CpiService::idealPart(): it used to hand the whole 450 to the leading tally in
        // that situation, which is one of the two faults this change removed.
        //
        // The property under test is untouched by that: both categories divide by ONE
        // denominator, so 50 against 50,000 is the same comparison whatever the ceiling.
        $this->assertSame(0,   $by['Thin field']['community_points']);
        $this->assertSame(135, $by['Wide field']['community_points']);
        $this->assertSame(440, $by['Thin field']['cpi']);
        $this->assertSame(575, $by['Wide field']['cpi']);
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
