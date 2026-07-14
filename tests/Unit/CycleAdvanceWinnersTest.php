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
}
