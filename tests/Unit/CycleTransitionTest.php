<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Console\Commands\CycleAdvanceCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Phase 2 — cycle lifecycle hardening: forward auto-transitions are applied and
 * recorded in gates_cycle_transitions; backward (regressive) auto-transitions
 * from mis-edited dates are refused.
 */
class CycleTransitionTest extends TestCase
{
    private function seedCycle(int $id, string $status, array $dates): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(array_merge(
            ['id' => $id, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => $status],
            $dates
        ));
    }

    public function test_forward_transition_is_applied_and_logged(): void
    {
        // Dates compute 'voting' (voting open, close in the future) from a 'nominations' start.
        $this->seedCycle(1, 'nominations', [
            'nominations_open' => '2020-01-01 00:00:00',
            'voting_open'      => '2020-02-01 00:00:00',
            'voting_close'     => '2099-01-01 00:00:00',
        ]);

        (new CommandTester(new CycleAdvanceCommand()))->execute([]);

        $this->assertSame('voting', DB::table('gates_award_cycles')->where('id', 1)->value('status'));
        $row = DB::table('gates_cycle_transitions')->where('cycle_id', 1)->first();
        $this->assertNotNull($row, 'a transition row should be logged');
        $this->assertSame('nominations', $row->from_status);
        $this->assertSame('voting', $row->to_status);
    }

    public function test_advances_one_phase_and_never_skips_voting(): void
    {
        // The first cron run lands AFTER voting already closed, so the date-derived
        // target is 'judging'. The cycle must still pass THROUGH 'voting' (advance
        // one phase per run) rather than skip it — otherwise no vote could ever be
        // cast for that cycle.
        $this->seedCycle(3, 'nominations', [
            'nominations_open' => '2020-01-01 00:00:00',
            'voting_open'      => '2020-02-01 00:00:00',
            'voting_close'     => '2020-03-01 00:00:00',
        ]);

        (new CommandTester(new CycleAdvanceCommand()))->execute([]);

        $this->assertSame('voting', DB::table('gates_award_cycles')->where('id', 3)->value('status'),
            'must step into voting, not skip straight to judging');
    }

    public function test_backward_transition_is_skipped(): void
    {
        // Status is already 'results' but the only date defines a past nominations
        // window, so statusFor would compute 'nominations' (earlier). The cron must
        // NOT regress it, and must not log a transition.
        $this->seedCycle(2, 'results', ['nominations_open' => '2020-01-01 00:00:00']);

        (new CommandTester(new CycleAdvanceCommand()))->execute([]);

        $this->assertSame('results', DB::table('gates_award_cycles')->where('id', 2)->value('status'));
        $this->assertSame(0, DB::table('gates_cycle_transitions')->where('cycle_id', 2)->count());
    }
}
