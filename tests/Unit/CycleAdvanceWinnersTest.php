<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Console\Commands\CycleAdvanceCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * R1 regression: when a cycle reaches 'results', winners must be chosen by the
 * full Cultural Power Index (45% community + 55% judges) — NOT raw vote_count.
 * Both nominees meet the judge quorum (2 complete scorecards each), so the test
 * isolates CPI-vs-raw-votes rather than the quorum gate.
 *
 * Scenario in one category (organic votes; cohort max 10):
 *   • Nominee A: 10 votes, judges score 2  → CPI = 0.45*(10/10)+0.55*0.2 = 560
 *   • Nominee B:  2 votes, judges score 10 → CPI = 0.45*(2/10)+0.55*1.0 = 640
 * Under the old vote_count ordering A would win; under CPI, B must win.
 */
class CycleAdvanceWinnersTest extends TestCase
{
    private function seedScenario(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'nominations_open' => '2020-01-01 00:00:00',
            'voting_open' => '2020-02-01 00:00:00', 'voting_close' => '2020-03-01 00:00:00',
            'results_date' => '2020-04-01 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        // A: more votes, LOW judge scores. B: fewer votes, TOP judge scores.
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'A_HighVotes', 'status' => 'approved', 'vote_count' => 10, 'organic_vote_count' => 10]);
        DB::table('gates_nominees')->insert(['id' => 2, 'category_id' => 1, 'name' => 'B_HighJudge', 'status' => 'approved', 'vote_count' => 2, 'organic_vote_count' => 2]);
        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'J1', 'email' => 'j1@x.io', 'is_active' => 1],
            ['id' => 2, 'name' => 'J2', 'email' => 'j2@x.io', 'is_active' => 1],
        ]);
        // The shipped rubric is installed by a migration, so it is present in the
        // harness exactly as it is in a migrated production database. Cleared here
        // because this test DECLARES the rubric under test — and because these
        // fixtures pin criterion ids, which would collide with the seeded rows.
        DB::table('gates_judge_criteria')->delete();
        DB::table('gates_judge_criteria')->insert(['id' => 1, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 25, 'is_active' => 1]);
        // Two COMPLETE scorecards per nominee (single active criterion) → quorum met.
        DB::table('gates_judge_criteria_scores')->insert([
            ['judge_id' => 1, 'nominee_id' => 1, 'category_id' => 1, 'criterion_id' => 1, 'score' => 2],
            ['judge_id' => 2, 'nominee_id' => 1, 'category_id' => 1, 'criterion_id' => 1, 'score' => 2],
            ['judge_id' => 1, 'nominee_id' => 2, 'category_id' => 1, 'criterion_id' => 1, 'score' => 10],
            ['judge_id' => 2, 'nominee_id' => 2, 'category_id' => 1, 'criterion_id' => 1, 'score' => 10],
        ]);
    }

    public function test_winner_is_chosen_by_cpi_not_raw_votes(): void
    {
        $this->seedScenario();

        (new CommandTester(new CycleAdvanceCommand()))->execute([]);

        // Cycle advanced to results, and the higher-CPI nominee (fewer votes) won.
        $this->assertSame('results', DB::table('gates_award_cycles')->where('id', 1)->value('status'));
        $this->assertSame('winner', DB::table('gates_nominees')->where('id', 2)->value('status'), 'B (higher CPI) should win');
        $this->assertSame('runner_up', DB::table('gates_nominees')->where('id', 1)->value('status'), 'A (more votes, lower CPI) should be runner-up');
    }

    private function seedTie(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'voting_open' => '2020-02-01 00:00:00', 'voting_close' => '2020-03-01 00:00:00',
            'results_date' => '2020-04-01 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'J1', 'email' => 'j1@x.io', 'is_active' => 1],
            ['id' => 2, 'name' => 'J2', 'email' => 'j2@x.io', 'is_active' => 1],
        ]);
        // The shipped rubric is installed by a migration, so it is present in the
        // harness exactly as it is in a migrated production database. Cleared here
        // because this test DECLARES the rubric under test — and because these
        // fixtures pin criterion ids, which would collide with the seeded rows.
        DB::table('gates_judge_criteria')->delete();
        DB::table('gates_judge_criteria')->insert(['id' => 1, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 25, 'is_active' => 1]);
        // Three identical nominees: 10 organic votes + two complete scorecards of
        // 10 → CPI 1000 each (a quorum-meeting 3-way tie).
        foreach ([1, 2, 3] as $id) {
            DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => 1, 'name' => 'N' . $id, 'status' => 'approved', 'vote_count' => 10, 'organic_vote_count' => 10]);
            DB::table('gates_judge_criteria_scores')->insert([
                ['judge_id' => 1, 'nominee_id' => $id, 'category_id' => 1, 'criterion_id' => 1, 'score' => 10],
                ['judge_id' => 2, 'nominee_id' => $id, 'category_id' => 1, 'criterion_id' => 1, 'score' => 10],
            ]);
        }
    }

    public function test_tie_breaks_deterministically_by_lower_id(): void
    {
        $this->seedTie();
        (new CommandTester(new CycleAdvanceCommand()))->execute([]);

        // Equal CPI + equal votes → lowest id wins; only the top two are promoted.
        $this->assertSame('winner',    DB::table('gates_nominees')->where('id', 1)->value('status'));
        $this->assertSame('runner_up', DB::table('gates_nominees')->where('id', 2)->value('status'));
        $this->assertSame('approved',  DB::table('gates_nominees')->where('id', 3)->value('status'));
    }

    /**
     * ── MONEY MUST NOT DECIDE A TIE ──────────────────────────────────────────────
     *
     * The README's promise is that purchased votes can make a nominee look popular
     * but can never buy their Cultural Power Index, and PaidVoteService,
     * BonusVoteService and PointsService each keep `organic_vote_count` clean to
     * honour it. The promotion then broke ties on `vote_count` — the tally money DOES
     * move — so at the one moment the whole apparatus exists for, the buyer won.
     *
     * The scenario puts the two candidates on the SAME integer CPI while differing by
     * a single organic vote, which is exactly where the tiebreak bites:
     *
     *   • #3 SetsTheCohort — 1000 organic, NO scorecards. Not quorum-eligible so it
     *     cannot be promoted, but it still sets the cohort maximum the community
     *     component normalises over. That is what makes one vote worth 0.45 points
     *     instead of 450, and therefore what makes the tie possible at all.
     *   • #1 Buyer   — 4 organic, 5000 shown (4,996 purchased), judges 8 → CPI 442
     *   • #2 Organic — 5 organic, 5 shown,                      judges 8 → CPI 442
     *
     * Old ordering [cpi, vote_count]: 5000 > 5, the Buyer takes the award.
     * Correct ordering [cpi, organic]: 5 > 4, one real vote outranks 4,996 bought ones.
     */
    private function seedPaidTiebreak(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'voting_open' => '2020-02-01 00:00:00', 'voting_close' => '2020-03-01 00:00:00',
            'results_date' => '2020-04-01 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'J1', 'email' => 'j1@x.io', 'is_active' => 1],
            ['id' => 2, 'name' => 'J2', 'email' => 'j2@x.io', 'is_active' => 1],
        ]);
        // The shipped rubric is installed by a migration, so it is present in the
        // harness exactly as it is in a migrated production database. Cleared here
        // because this test DECLARES the rubric under test — and because these
        // fixtures pin criterion ids, which would collide with the seeded rows.
        DB::table('gates_judge_criteria')->delete();
        DB::table('gates_judge_criteria')->insert(['id' => 1, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 25, 'is_active' => 1]);

        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'Buyer',         'status' => 'approved', 'vote_count' => 5000, 'organic_vote_count' => 4],
            ['id' => 2, 'category_id' => 1, 'name' => 'Organic',       'status' => 'approved', 'vote_count' => 5,    'organic_vote_count' => 5],
            ['id' => 3, 'category_id' => 1, 'name' => 'SetsTheCohort', 'status' => 'approved', 'vote_count' => 1000, 'organic_vote_count' => 1000],
        ]);
        foreach ([1, 2] as $nomId) {
            DB::table('gates_judge_criteria_scores')->insert([
                ['judge_id' => 1, 'nominee_id' => $nomId, 'category_id' => 1, 'criterion_id' => 1, 'score' => 8],
                ['judge_id' => 2, 'nominee_id' => $nomId, 'category_id' => 1, 'criterion_id' => 1, 'score' => 8],
            ]);
        }
    }

    public function test_a_tie_is_broken_by_organic_votes_not_purchased_ones(): void
    {
        $this->seedPaidTiebreak();

        // The premise: the two candidates really are level on the published index.
        $scores = (new \AfricaGates\Services\NomineeScoringService())->scoreCategory(1);
        $this->assertSame($scores[1]['cpi_score'], $scores[2]['cpi_score'],
            'the scenario is only meaningful if the CPI genuinely ties');

        (new CommandTester(new CycleAdvanceCommand()))->execute([]);

        $this->assertSame('winner', DB::table('gates_nominees')->where('id', 2)->value('status'),
            'one real vote must outrank 4,996 purchased ones');
        $this->assertSame('runner_up', DB::table('gates_nominees')->where('id', 1)->value('status'));
        // The cohort-setter has no scorecards, so the quorum keeps it out of the
        // ranking however many votes it has.
        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 3)->value('status'));
    }

    /**
     * Purchased votes must not make a nominee PROMOTABLE either, not merely fail to
     * outrank. With no organic support and no judge marks a nominee scores 0, and the
     * old filter kept anyone whose `vote_count` was above zero — so a category the
     * panel scored at zero handed the award to whoever had spent money.
     */
    public function test_purchased_votes_alone_do_not_make_a_nominee_promotable(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'voting_open' => '2020-02-01 00:00:00', 'voting_close' => '2020-03-01 00:00:00',
            'results_date' => '2020-04-01 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'J1', 'email' => 'j1@x.io', 'is_active' => 1],
            ['id' => 2, 'name' => 'J2', 'email' => 'j2@x.io', 'is_active' => 1],
        ]);
        // The shipped rubric is installed by a migration, so it is present in the
        // harness exactly as it is in a migrated production database. Cleared here
        // because this test DECLARES the rubric under test — and because these
        // fixtures pin criterion ids, which would collide with the seeded rows.
        DB::table('gates_judge_criteria')->delete();
        DB::table('gates_judge_criteria')->insert(['id' => 1, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 25, 'is_active' => 1]);
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'BoughtOnly', 'status' => 'approved', 'vote_count' => 900, 'organic_vote_count' => 0],
            ['id' => 2, 'category_id' => 1, 'name' => 'Nothing',    'status' => 'approved', 'vote_count' => 0,   'organic_vote_count' => 0],
        ]);
        foreach ([1, 2] as $nomId) {
            DB::table('gates_judge_criteria_scores')->insert([
                ['judge_id' => 1, 'nominee_id' => $nomId, 'category_id' => 1, 'criterion_id' => 1, 'score' => 0],
                ['judge_id' => 2, 'nominee_id' => $nomId, 'category_id' => 1, 'criterion_id' => 1, 'score' => 0],
            ]);
        }

        (new CommandTester(new CycleAdvanceCommand()))->execute([]);

        $this->assertSame('results', DB::table('gates_award_cycles')->where('id', 1)->value('status'));
        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 1)->value('status'),
            'a nominee with nothing but purchased votes must not be promoted');
        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 2)->value('status'));
    }

    /** A genuine dead heat is reported, not resolved quietly by an id. */
    public function test_a_dead_heat_is_announced_in_the_run_log(): void
    {
        $this->seedTie();
        $t = new CommandTester(new CycleAdvanceCommand());
        $t->execute([]);

        $out = $t->getDisplay();
        $this->assertStringContainsString('DEAD HEAT', $out);
        $this->assertStringContainsString('needs a human', $out);
    }
}
