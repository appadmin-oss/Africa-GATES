<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\NomineeScoringService;

/**
 * judgeAveragesFor() is the ONLY place judge scores become a CPI input, so its
 * per-judge weighting + averaging is worth pinning directly.
 */
class NomineeScoringTest extends TestCase
{
    public function test_average_is_mean_across_judges_each_weighted_by_criterion(): void
    {
        DB::table('gates_judge_criteria')->insert([
            ['id' => 1, 'slug' => 'a', 'label' => 'A', 'weight' => 25, 'is_active' => 1],
            ['id' => 2, 'slug' => 'b', 'label' => 'B', 'weight' => 75, 'is_active' => 1],
        ]);
        // Judge 1: 8 & 8 → weighted 8.0.  Judge 2: 10 & 6 → 0.25*10 + 0.75*6 = 7.0.  Mean = 7.5.
        DB::table('gates_judge_criteria_scores')->insert([
            ['judge_id' => 1, 'nominee_id' => 1, 'category_id' => 1, 'criterion_id' => 1, 'score' => 8],
            ['judge_id' => 1, 'nominee_id' => 1, 'category_id' => 1, 'criterion_id' => 2, 'score' => 8],
            ['judge_id' => 2, 'nominee_id' => 1, 'category_id' => 1, 'criterion_id' => 1, 'score' => 10],
            ['judge_id' => 2, 'nominee_id' => 1, 'category_id' => 1, 'criterion_id' => 2, 'score' => 6],
        ]);

        $avg = (new NomineeScoringService())->judgeAveragesFor([1]);
        $this->assertEqualsWithDelta(7.5, $avg[1], 0.001);
    }

    public function test_nominee_with_no_scores_is_absent_from_the_map(): void
    {
        $avg = (new NomineeScoringService())->judgeAveragesFor([99]);
        $this->assertArrayNotHasKey(99, $avg);
    }

    public function test_zero_weight_criterion_is_treated_as_default_not_disabled(): void
    {
        // Pins the current `(int)weight ?: 25` behaviour: a 0-weight criterion counts
        // as weight 25 (a div-by-zero guard), it does NOT silently disable the criterion.
        // If this ever changes, the nominee would drop out of the map and this fails.
        DB::table('gates_judge_criteria')->insert([
            ['id' => 1, 'slug' => 'a', 'label' => 'A', 'weight' => 0, 'is_active' => 1],
        ]);
        DB::table('gates_judge_criteria_scores')->insert([
            ['judge_id' => 1, 'nominee_id' => 1, 'category_id' => 1, 'criterion_id' => 1, 'score' => 8],
        ]);

        $this->assertEqualsWithDelta(8.0, (new NomineeScoringService())->judgeAveragesFor([1])[1], 0.001);
    }
}
