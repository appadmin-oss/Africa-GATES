<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{AwardService, BallotGuard, CyclePolicy};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CurrentCycleTest extends TestCase
{
    public function test_an_open_window_beats_previous_results_even_before_the_scheduler_runs(): void
    {
        DB::table('gates_award_programmes')->insert(['id'=>1, 'slug'=>'current-cycle', 'title'=>'Current', 'is_active'=>1]);
        DB::table('gates_award_cycles')->insert(['id'=>10, 'programme_id'=>1, 'year'=>(int)date('Y')-1, 'status'=>'results']);
        DB::table('gates_award_cycles')->insert(['id'=>11, 'programme_id'=>1, 'year'=>(int)date('Y'), 'status'=>'upcoming',
            'nominations_open'=>Carbon::now()->subDay()->toDateTimeString(),
            'nominations_close'=>Carbon::now()->addDays(5)->toDateTimeString()]);

        BallotGuard::assertNominable(1);
        $this->assertSame(11, (int)BallotGuard::currentCycleForProgramme(1)->id);
        $awards = new AwardService();
        $this->assertSame(11, (int)$awards->getProgrammeBySlug('current-cycle')['cycle']['id']);
        $this->assertSame(11, (int)$awards->getActiveProgrammesWithStatus()[0]['cycle_id']);
        $this->assertSame('upcoming', DB::table('gates_award_cycles')->where('id',11)->value('status'),
            'reads must not need to materialize the status to select the right edition');
    }

    public function test_a_future_edition_does_not_hide_an_existing_open_edition(): void
    {
        DB::table('gates_award_cycles')->insert(['id'=>10, 'programme_id'=>1, 'year'=>(int)date('Y'), 'status'=>'nominations']);
        DB::table('gates_award_cycles')->insert(['id'=>11, 'programme_id'=>1, 'year'=>(int)date('Y')+1, 'status'=>'upcoming',
            'nominations_open'=>Carbon::now()->addDays(30)->toDateTimeString()]);
        $this->assertSame(10, (int)BallotGuard::currentCycleForProgramme(1)->id);
    }
}
