<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Judge\Services\JudgeService;

/**
 * The judge home aggregates assignments, progress, an auditable activity trail,
 * a self-audit scoring profile, and conflict-of-interest recusals. These pin the
 * data layer behind that dashboard.
 */
class JudgeDashboardTest extends TestCase
{
    private function seedProgramme(int $progId, int $cycleId, string $status, int $catId, array $nomineeIds): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => $progId, 'slug' => 'p' . $progId, 'title' => 'Programme ' . $progId, 'sort_order' => $progId,
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => $cycleId, 'programme_id' => $progId, 'year' => 2026, 'status' => $status,
            'results_date' => Carbon::now()->addDays(10)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => $catId, 'cycle_id' => $cycleId, 'slug' => 'c' . $catId, 'title' => 'Category ' . $catId, 'sort_order' => 1,
        ]);
        foreach ($nomineeIds as $nid) {
            DB::table('gates_nominees')->insert([
                'id' => $nid, 'category_id' => $catId, 'name' => 'Nom ' . $nid, 'status' => 'approved', 'vote_count' => 0,
            ]);
        }

        // The panel judges the SHORTLIST, not the whole field, so a fixture without one
        // produces a locked ballot with nobody on it — and every count on this dashboard
        // would read zero for a reason unrelated to what is being tested.
        $this->publishShortlist($cycleId, $catId, $nomineeIds);
    }

    /** @param array<int,int> $weights criterionId => weight */
    private function seedCriteria(array $weights): void
    {
        // Once, BEFORE the loop. The shipped rubric is installed by a migration, so the
        // harness carries it exactly as a migrated production database does, and this test
        // declares the rubric under test — with pinned ids that would otherwise collide.
        DB::table('gates_judge_criteria')->delete();

        foreach ($weights as $id => $w) {
            DB::table('gates_judge_criteria')->insert([
                'id' => $id, 'programme_id' => null, 'slug' => 'cr' . $id, 'label' => 'Crit ' . $id,
                'weight' => $w, 'sort_order' => $id, 'is_active' => 1,
            ]);
        }
    }

    private function seedJudge(int $id, array $progIds): void
    {
        DB::table('gates_judges')->insert([
            'id' => $id, 'name' => 'Judge ' . $id, 'email' => 'j' . $id . '@x.io',
            'programme_ids' => json_encode($progIds), 'is_active' => 1,
        ]);
    }

    private function score(int $judgeId, int $nomineeId, int $catId, int $critId, int $score, ?string $at = null): void
    {
        $row = ['judge_id' => $judgeId, 'nominee_id' => $nomineeId, 'category_id' => $catId, 'criterion_id' => $critId, 'score' => $score];
        if ($at !== null) { $row['updated_at'] = $at; }
        DB::table('gates_judge_criteria_scores')->insert($row);
    }

    public function test_overview_aggregates_across_programmes(): void
    {
        $this->seedCriteria([1 => 50, 2 => 50]);
        $this->seedProgramme(1, 1, 'judging', 10, [101, 102]);
        $this->seedProgramme(2, 2, 'judging', 20, [201]);
        $this->seedJudge(7, [1, 2]);

        $this->score(7, 101, 10, 1, 8);
        $this->score(7, 101, 10, 2, 6);   // 101 fully scored
        $this->score(7, 201, 20, 1, 5);   // 201 partial (1 of 2)

        $o = (new JudgeService())->dashboard(7)['overview'];

        $this->assertSame(2, $o['programmes']);
        $this->assertSame(3, $o['total']);      // 2 + 1 scoreable nominees
        $this->assertSame(1, $o['scored']);     // only 101 complete
        $this->assertSame(2, $o['remaining']);
        $this->assertSame(2, $o['open']);       // both cycles judging, no COI
    }

    public function test_activity_orders_recent_first_with_weighted_avg(): void
    {
        $this->seedCriteria([1 => 50, 2 => 50]);
        $this->seedProgramme(1, 1, 'judging', 10, [101, 102]);
        $this->seedJudge(7, [1]);

        $this->score(7, 101, 10, 1, 10, '2026-01-01 10:00:00');
        $this->score(7, 101, 10, 2, 6,  '2026-01-01 10:00:00');
        $this->score(7, 102, 10, 1, 4,  '2026-02-01 10:00:00');  // more recent

        $act = (new JudgeService())->activity(7, 10);

        $this->assertSame(102, $act[0]['nominee_id']);          // Feb before Jan
        $this->assertSame(101, $act[1]['nominee_id']);
        $this->assertSame(8.0, $act[1]['avg']);                 // (10*50 + 6*50)/100
        $this->assertSame(2, $act[1]['criteria_scored']);
        $this->assertSame('Category 10', $act[1]['category']);
    }

    public function test_scoring_summary_distribution_and_range(): void
    {
        $this->seedCriteria([1 => 50, 2 => 50]);
        $this->seedProgramme(1, 1, 'judging', 10, [101]);
        $this->seedJudge(7, [1]);

        $this->score(7, 101, 10, 1, 2);    // low band
        $this->score(7, 101, 10, 2, 10);   // high band

        $s = (new JudgeService())->scoringSummary(7);

        $this->assertSame(2, $s['total_marks']);
        $this->assertSame(6.0, $s['avg']);       // (2+10)/2
        $this->assertSame(2, $s['min']);
        $this->assertSame(10, $s['max']);
        $this->assertSame(8, $s['range_used']);  // 10 - 2
        $this->assertSame(1, $s['bands']['low']);
        $this->assertSame(1, $s['bands']['high']);
        $this->assertSame(0, $s['bands']['mid']);
    }

    public function test_conflict_recusal_listed_and_excluded_from_open(): void
    {
        $this->seedCriteria([1 => 100]);
        $this->seedProgramme(1, 1, 'judging', 10, [101]);
        $this->seedJudge(7, [1]);

        (new JudgeService())->declareConflict(7, 1, 'Co-founder of a nominee organisation');
        $d = (new JudgeService())->dashboard(7);

        $this->assertCount(1, $d['conflicts']);
        $this->assertSame('Programme 1', $d['conflicts'][0]['programme']);
        $this->assertSame(0, $d['overview']['open']);          // recused → not open
        $this->assertNotNull($d['programmes'][0]['coi']);
        $this->assertFalse($d['programmes'][0]['judging_open']);
    }

    public function test_empty_judge_has_safe_zeroed_dashboard(): void
    {
        $this->seedJudge(7, []);
        $d = (new JudgeService())->dashboard(7);

        $this->assertSame(0, $d['overview']['programmes']);
        $this->assertSame(0, $d['overview']['total']);
        $this->assertSame([], $d['programmes']);
        $this->assertSame([], $d['activity']);
        $this->assertNull($d['summary']['avg']);
    }
}
