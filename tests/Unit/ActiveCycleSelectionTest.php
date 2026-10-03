<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\AwardService;

/**
 * The public "active programmes" view must surface a programme's CURRENT cycle
 * based on its status/recency — not a hard match on the calendar year, which
 * made an in-flight cycle vanish at the New Year boundary or when seeded ahead.
 */
class ActiveCycleSelectionTest extends TestCase
{
    public function test_active_cycle_shows_even_when_tagged_a_different_year(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1', 'is_active' => 1, 'sort_order' => 1]);
        // A live 'voting' cycle tagged NEXT year (seeded ahead / spanning New Year).
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y') + 1, 'status' => 'voting',
        ]);

        $progs = (new AwardService())->getActiveProgrammesWithStatus();

        $this->assertCount(1, $progs);
        $this->assertSame('voting', $progs[0]['cycle_status'], 'an active cycle must not be hidden by a year filter');
        $this->assertSame(1, (int) $progs[0]['cycle_id']);
    }

    public function test_prefers_in_flight_cycle_over_a_newer_idle_one(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1', 'is_active' => 1, 'sort_order' => 1]);
        // An older cycle is actively voting; a newer one is still 'upcoming'.
        DB::table('gates_award_cycles')->insert([
            ['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'),     'status' => 'voting'],
            ['id' => 2, 'programme_id' => 1, 'year' => (int) date('Y') + 1, 'status' => 'upcoming'],
        ]);

        $progs = (new AwardService())->getActiveProgrammesWithStatus();

        $this->assertSame('voting', $progs[0]['cycle_status'], 'the in-flight cycle should win over a newer idle one');
        $this->assertSame(1, (int) $progs[0]['cycle_id']);
    }
}
