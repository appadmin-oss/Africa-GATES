<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\MilestoneService;

/**
 * A vote (or a bonus redemption) can push a nominee past several milestone
 * thresholds at once. All newly-crossed milestones must be recorded, not just
 * the first — otherwise badges silently go missing on big jumps.
 */
class MilestoneServiceTest extends TestCase
{
    public function test_multi_threshold_jump_records_all_crossed_milestones(): void
    {
        // Count leapt from below 100 straight to 300 (crosses 100 AND 250).
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'N', 'status' => 'approved', 'vote_count' => 300,
        ]);

        (new MilestoneService())->checkAndNotify(1);

        $recorded = DB::table('gates_vote_milestones')->where('nominee_id', 1)->pluck('milestone')->all();
        $this->assertEqualsCanonicalizing([100, 250], array_map('intval', $recorded));
    }

    public function test_already_recorded_milestones_are_not_duplicated(): void
    {
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'N', 'status' => 'approved', 'vote_count' => 120,
        ]);
        $svc = new MilestoneService();
        $svc->checkAndNotify(1);   // records 100
        $svc->checkAndNotify(1);   // second call must not re-record or crash

        $this->assertSame(1, DB::table('gates_vote_milestones')->where('nominee_id', 1)->count());
    }
}
