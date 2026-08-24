<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Console\Commands\CycleAdvanceCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Judge quorum: a category's winners may only be crowned when nominees have
 * enough COMPLETE judge scorecards (default min_judges_per_nominee = 2, each
 * judge scoring every active criterion). A single judge — or partial scorecards
 * — must NOT decide a category, and under-quorum nominees are excluded from
 * ranking rather than treated as 0/10 on the expert half.
 */
class JudgeQuorumTest extends TestCase
{
    private function seedCycleReadyForResults(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'voting_open' => '2020-02-01 00:00:00', 'voting_close' => '2020-03-01 00:00:00',
            'results_date' => '2020-04-01 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
    }

    private function seedNominee(int $id, int $votes): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => 1, 'name' => 'N' . $id, 'status' => 'approved',
            'vote_count' => $votes, 'organic_vote_count' => $votes,
        ]);
    }

    private function activeCriteria(array $ids): void
    {
        // Once, BEFORE the loop. The shipped rubric is installed by a migration, so the
        // harness carries it exactly as a migrated production database does, and this test
        // declares the rubric under test — with pinned ids that would otherwise collide.
        DB::table('gates_judge_criteria')->delete();

        foreach ($ids as $cid) {
            DB::table('gates_judge_criteria')->insert(['id' => $cid, 'slug' => 'c' . $cid, 'label' => 'C' . $cid, 'weight' => 25, 'is_active' => 1]);
        }
    }

    private function score(int $judgeId, int $nomineeId, array $byCriterion): void
    {
        foreach ($byCriterion as $cid => $sc) {
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judgeId, 'nominee_id' => $nomineeId, 'category_id' => 1, 'criterion_id' => $cid, 'score' => $sc,
            ]);
        }
    }

    private function advance(): void
    {
        (new CommandTester(new CycleAdvanceCommand()))->execute([]);
    }

    public function test_single_complete_judge_is_below_quorum_so_no_winner(): void
    {
        $this->seedCycleReadyForResults();
        $this->seedNominee(1, 10);
        $this->activeCriteria([1]);
        $this->score(1, 1, [1 => 9]); // only ONE complete judge

        $this->advance();

        $this->assertSame('results', DB::table('gates_award_cycles')->where('id', 1)->value('status'));
        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 1)->value('status'),
            'a single judge must not be enough to crown a winner');
    }

    public function test_two_complete_judges_meet_quorum_and_promote_winner(): void
    {
        $this->seedCycleReadyForResults();
        $this->seedNominee(1, 10);
        $this->seedNominee(2, 4);
        $this->activeCriteria([1]);
        // Both nominees get two complete scorecards; nominee 1 scores higher.
        $this->score(1, 1, [1 => 9]); $this->score(2, 1, [1 => 9]);
        $this->score(1, 2, [1 => 3]); $this->score(2, 2, [1 => 3]);

        $this->advance();

        $this->assertSame('winner', DB::table('gates_nominees')->where('id', 1)->value('status'));
        $this->assertSame('runner_up', DB::table('gates_nominees')->where('id', 2)->value('status'));
    }

    public function test_partial_scorecards_do_not_count_toward_quorum(): void
    {
        $this->seedCycleReadyForResults();
        $this->seedNominee(1, 10);
        $this->activeCriteria([1, 2]); // TWO active criteria
        // Two judges, but each scored only ONE criterion → neither scorecard is complete.
        $this->score(1, 1, [1 => 9]);
        $this->score(2, 1, [2 => 9]);

        $this->advance();

        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 1)->value('status'),
            'partial scorecards must not satisfy the quorum');
    }
}
