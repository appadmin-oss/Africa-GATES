<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\JudgeAnomalyService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Advisory judge-score anomaly detection. The statistics core ({@see
 * JudgeAnomalyService::detect()}) is pinned directly; one integration test
 * proves it wires through real scorecards + name attachment.
 */
class JudgeAnomalyServiceTest extends TestCase
{
    public function test_detects_a_harsh_outlier(): void
    {
        $flags = JudgeAnomalyService::detect([1 => [1 => 10.0, 2 => 10.0, 3 => 10.0, 4 => 4.0]]);
        $this->assertCount(1, $flags);
        $this->assertSame(4, $flags[0]['judge_id']);
        $this->assertSame('harsh', $flags[0]['direction']);
        $this->assertSame(1, $flags[0]['nominee_id']);
    }

    public function test_detects_a_generous_outlier(): void
    {
        $flags = JudgeAnomalyService::detect([7 => [1 => 5.0, 2 => 5.0, 3 => 5.0, 4 => 10.0]]);
        $this->assertCount(1, $flags);
        $this->assertSame('generous', $flags[0]['direction']);
    }

    public function test_z_based_flag_when_gap_is_moderate_but_significant(): void
    {
        // [8,8,8,8,5]: mean 7.4, sd 1.2 → the 5 is exactly 2σ out and 2.4 pts (> MIN_DEVIATION), so flagged.
        $flags = JudgeAnomalyService::detect([1 => [1 => 8.0, 2 => 8.0, 3 => 8.0, 4 => 8.0, 5 => 5.0]]);
        $this->assertCount(1, $flags);
        $this->assertSame(5, $flags[0]['judge_id']);
    }

    public function test_tight_panel_is_not_flagged(): void
    {
        // Everyone within a point — no outlier despite tiny spread.
        $this->assertSame([], JudgeAnomalyService::detect([1 => [1 => 7.0, 2 => 7.0, 3 => 7.0, 4 => 8.0]]));
    }

    public function test_small_panel_and_consensus_never_flag(): void
    {
        $this->assertSame([], JudgeAnomalyService::detect([1 => [1 => 3.0, 2 => 10.0]]));      // < MIN_PANEL
        $this->assertSame([], JudgeAnomalyService::detect([1 => [1 => 8.0, 2 => 8.0, 3 => 8.0]])); // consensus
    }

    public function test_forCycle_flags_a_real_outlier_with_names(): void
    {
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insert(['id' => 10, 'cycle_id' => 1, 'slug' => 'a', 'title' => 'Alpha', 'sort_order' => 1]);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_judge_criteria')->insert([['id' => 1, 'slug' => 'a', 'label' => 'A', 'weight' => 100, 'is_active' => 1]]);
        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'Fair One', 'email' => 'j1@e.com'],
            ['id' => 2, 'name' => 'Fair Two', 'email' => 'j2@e.com'],
            ['id' => 3, 'name' => 'Harsh Judge', 'email' => 'j3@e.com'],
        ]);
        // Panel 9, 9, 3 → mean 7, the 3 is 4 points out → harsh flag.
        DB::table('gates_judge_criteria_scores')->insert([
            ['judge_id' => 1, 'nominee_id' => 1, 'category_id' => 10, 'criterion_id' => 1, 'score' => 9],
            ['judge_id' => 2, 'nominee_id' => 1, 'category_id' => 10, 'criterion_id' => 1, 'score' => 9],
            ['judge_id' => 3, 'nominee_id' => 1, 'category_id' => 10, 'criterion_id' => 1, 'score' => 3],
        ]);

        $r = (new JudgeAnomalyService())->forCycle(1);
        $this->assertCount(1, $r['flags']);
        $this->assertSame('Harsh Judge', $r['flags'][0]['judge']);
        $this->assertSame('Ada Obi', $r['flags'][0]['nominee']);
        $this->assertSame('harsh', $r['flags'][0]['direction']);
        // Per-judge rollup names the outlier.
        $this->assertSame(3, $r['judges'][0]['judge_id']);
        $this->assertSame(1, $r['judges'][0]['flags']);
    }

    public function test_forCycle_clean_panel_yields_no_flags(): void
    {
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insert(['id' => 10, 'cycle_id' => 1, 'slug' => 'a', 'title' => 'Alpha', 'sort_order' => 1]);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_judge_criteria')->insert([['id' => 1, 'slug' => 'a', 'label' => 'A', 'weight' => 100, 'is_active' => 1]]);
        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'A', 'email' => 'a@e.com'],
            ['id' => 2, 'name' => 'B', 'email' => 'b@e.com'],
            ['id' => 3, 'name' => 'C', 'email' => 'c@e.com'],
        ]);
        foreach ([8, 8, 9] as $i => $s) {
            DB::table('gates_judge_criteria_scores')->insert(['judge_id' => $i + 1, 'nominee_id' => 1, 'category_id' => 10, 'criterion_id' => 1, 'score' => $s]);
        }
        $this->assertSame([], (new JudgeAnomalyService())->forCycle(1)['flags']);
    }
}
