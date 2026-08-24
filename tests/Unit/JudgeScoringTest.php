<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Judge\Services\JudgeService;

/**
 * R6 regression: judge scores may only be written while the cycle is in the
 * 'judging' phase, and a judge who declared a conflict of interest for the
 * programme is recused (server-side, not just client sessionStorage).
 */
class JudgeScoringTest extends TestCase
{
    private function seed(string $cycleStatus, string $nomineeStatus = 'approved'): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => $cycleStatus]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'N1', 'status' => $nomineeStatus, 'vote_count' => 0]);
        DB::table('gates_judges')->insert(['id' => 1, 'name' => 'J1', 'email' => 'j1@x.io', 'programme_ids' => '[1]', 'is_active' => 1]);
        // The shipped rubric is installed by a migration, so it is present in the
        // harness exactly as it is in a migrated production database. Cleared here
        // because this test DECLARES the rubric under test — and because these
        // fixtures pin criterion ids, which would collide with the seeded rows.
        DB::table('gates_judge_criteria')->delete();
        DB::table('gates_judge_criteria')->insert(['id' => 1, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 25, 'is_active' => 1]);
    }

    /** A second programme the judge is NOT assigned to. */
    private function seedForeignProgramme(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 2, 'slug' => 'p2', 'title' => 'P2']);
        DB::table('gates_award_cycles')->insert(['id' => 2, 'programme_id' => 2, 'year' => (int) date('Y'), 'status' => 'judging']);
        DB::table('gates_award_categories')->insert(['id' => 2, 'cycle_id' => 2, 'slug' => 'c2', 'title' => 'C2']);
        DB::table('gates_nominees')->insert(['id' => 2, 'category_id' => 2, 'name' => 'N2', 'status' => 'approved', 'vote_count' => 0]);
    }

    public function test_score_blocked_outside_judging_window(): void
    {
        $this->seed('voting');
        $r = (new JudgeService())->saveScore(1, 1, [1 => 8]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('judging phase', $r['message']);
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->count());
    }

    public function test_score_allowed_during_judging(): void
    {
        $this->seed('judging');
        $r = (new JudgeService())->saveScore(1, 1, [1 => 8]);
        $this->assertTrue($r['ok']);
        $this->assertSame(8, (int) DB::table('gates_judge_criteria_scores')->where('nominee_id', 1)->value('score'));
    }

    public function test_score_blocked_after_conflict_declared(): void
    {
        $this->seed('judging');
        $svc = new JudgeService();
        $svc->declareConflict(1, 1, 'related to a nominee');
        $r = $svc->saveScore(1, 1, [1 => 8]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('conflict', strtolower($r['message']));
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->count());
    }

    public function test_cannot_score_nominee_in_unassigned_programme(): void
    {
        $this->seed('judging');           // judge 1 assigned to programme 1
        $this->seedForeignProgramme();    // nominee 2 lives in programme 2

        $r = (new JudgeService())->saveScore(1, 2, [1 => 9]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not assigned', strtolower($r['message']));
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->count());
    }

    public function test_unknown_criterion_is_ignored_not_inserted(): void
    {
        $this->seed('judging');
        // Criterion 1 is real; 999 is not part of this programme's rubric.
        $r = (new JudgeService())->saveScore(1, 1, [1 => 8, 999 => 5]);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['saved']);                              // only the valid one
        $this->assertSame(1, DB::table('gates_judge_criteria_scores')->count());
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->where('criterion_id', 999)->count());
    }

    public function test_cannot_score_non_ballot_nominee(): void
    {
        $this->seed('judging', 'pending'); // nominee not yet approved → not on the ballot

        $r = (new JudgeService())->saveScore(1, 1, [1 => 8]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not open for scoring', strtolower($r['message']));
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->count());
    }

    public function test_notes_are_length_capped(): void
    {
        $this->seed('judging');
        (new JudgeService())->saveScore(1, 1, [1 => 8], str_repeat('x', 6000));

        $stored = (string) DB::table('gates_judge_notes')->where('nominee_id', 1)->value('notes');
        $this->assertSame(5000, mb_strlen($stored));
    }
}
