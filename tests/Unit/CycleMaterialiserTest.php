<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\CycleMaterialiser;
use AfricaGates\Support\Clock;

/**
 * The single lifecycle engine. Two guarantees matter most here, and neither
 * existed before:
 *
 *  1. EXACTLY ONCE. The transitions ledger's UNIQUE (cycle_id, to_status) makes
 *     the INSERT the claim, so two concurrent runs cannot both fire a phase's
 *     side effects. CronGuard deliberately fails open, so overlap is possible
 *     by design and the ledger is what makes it safe.
 *  2. STALE BACKLOG SUPPRESSION. A scheduler dead for a month must correct the
 *     standings without emailing every winner about a competition that ended
 *     long ago. State is always repaired; notifications are withheld.
 */
class CycleMaterialiserTest extends TestCase
{
    private function seedCycle(int $id, string $status, array $dates): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(array_merge(
            ['id' => $id, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => $status],
            $dates
        ));
    }

    private function storedStatus(int $id): string
    {
        return (string) DB::table('gates_award_cycles')->where('id', $id)->value('status');
    }

    public function test_a_phase_is_claimed_exactly_once_across_concurrent_runs(): void
    {
        $this->seedCycle(1, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2020-03-01 00:00:00',
            'voting_close'      => '2099-01-01 00:00:00',
        ]);

        // Two overlapping runs, as CronGuard's fail-open behaviour permits.
        $a = (new CycleMaterialiser())->run();
        $b = (new CycleMaterialiser())->run();

        // Each run advances exactly one phase; neither may re-claim the other's.
        $rows = DB::table('gates_cycle_transitions')->where('cycle_id', 1)
            ->orderBy('id')->pluck('to_status')->all();
        $this->assertSame(['shortlisting', 'voting'], $rows, 'each phase claimed once, in order');
        $this->assertSame(1, $a['changed']);
        $this->assertSame(1, $b['changed']);
    }

    public function test_a_replayed_phase_is_refused_and_fires_no_side_effects(): void
    {
        $this->seedCycle(2, 'nominations', ['nominations_open' => '2020-01-01 00:00:00']);

        // Pre-claim the next phase, as a crashed earlier run would have.
        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => 2, 'from_status' => 'nominations', 'to_status' => 'shortlisting',
            'reason' => 'pre-claimed', 'actor' => 'test',
        ]);
        DB::table('gates_award_cycles')->where('id', 2)->update([
            'nominations_close' => '2020-02-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame(0, $r['changed'], 'an already-claimed phase must not advance again');
        $this->assertSame('nominations', $this->storedStatus(2), 'and must not rewrite the column');
        $this->assertSame(1, DB::table('gates_cycle_transitions')->where('cycle_id', 2)->count(),
            'no duplicate ledger row');
    }

    public function test_the_ledger_records_the_declared_boundary_and_when_it_was_noticed(): void
    {
        // A computed phase change is not a write, so without this there is no
        // audit trail at all — and no way to tell "closed on time" from "closed
        // on time, but nobody looked for three weeks".
        $this->seedCycle(3, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2099-01-01 00:00:00',
        ]);

        (new CycleMaterialiser())->run();

        $row = DB::table('gates_cycle_transitions')->where('cycle_id', 3)->first();
        $this->assertNotNull($row);
        $this->assertSame('shortlisting', (string) $row->to_status);
        $this->assertStringStartsWith('2020-02-01', (string) $row->boundary_at, 'the declared date that caused it');
        $this->assertNotEmpty($row->observed_at, 'and when the system first noticed');
        $this->assertNotSame((string) $row->boundary_at, (string) $row->observed_at,
            'a long-overdue transition must show the gap, not hide it');
    }

    public function test_a_stale_transition_repairs_state_but_suppresses_announcements(): void
    {
        // Six years overdue: the platform must correct itself without
        // congratulating anyone about a competition that ended in 2020.
        $this->seedCycle(4, 'judging', [
            'voting_open'  => '2020-01-01 00:00:00',
            'voting_close' => '2020-02-01 00:00:00',
            'results_date' => '2020-03-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame('results', $this->storedStatus(4), 'state is still corrected');
        $this->assertSame(1, $r['suppressed'], 'but the announcement is withheld');

        $row = DB::table('gates_cycle_transitions')->where('cycle_id', 4)->first();
        $this->assertSame(0, (int) $row->notify, 'and the suppression is recorded, not silent');
        $this->assertStringContainsString('suppressed', (string) $row->reason);
    }

    public function test_a_timely_transition_still_announces(): void
    {
        $this->seedCycle(5, 'judging', [
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame('results', $this->storedStatus(5));
        $this->assertSame(0, $r['suppressed'], 'a result one day old is not stale');
        $this->assertSame(1, (int) DB::table('gates_cycle_transitions')->where('cycle_id', 5)->value('notify'));
    }

    public function test_the_grace_boundary_is_where_it_says_it_is(): void
    {
        $now = Carbon::parse('2026-07-26 12:00:00');
        $this->assertSame(0, CycleMaterialiser::daysLate('2026-07-27 12:00:00', $now), 'future is never late');
        $this->assertSame(0, CycleMaterialiser::daysLate(null, $now), 'absent is never late');
        $this->assertSame(7, CycleMaterialiser::daysLate('2026-07-19 12:00:00', $now));
        $this->assertSame(8, CycleMaterialiser::daysLate('2026-07-18 12:00:00', $now));
        $this->assertSame(7, CycleMaterialiser::ANNOUNCE_GRACE_DAYS, 'the documented grace window');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedCycle(6, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2099-01-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser(true))->run();

        $this->assertSame(1, $r['changed'], 'it reports what it would do');
        $this->assertSame('nominations', $this->storedStatus(6), 'but changes nothing');
        $this->assertSame(0, DB::table('gates_cycle_transitions')->where('cycle_id', 6)->count());
    }

    public function test_a_cycle_is_never_regressed_by_the_materialiser(): void
    {
        // A mistyped results_date must not un-announce published winners.
        $this->seedCycle(7, 'results', [
            'voting_open'  => '2020-01-01 00:00:00',
            'voting_close' => '2099-01-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame('results', $this->storedStatus(7));
        $this->assertSame(0, $r['changed']);
    }

    public function test_a_cycle_with_no_date_windows_is_left_alone(): void
    {
        $this->seedCycle(8, 'nominations', []);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame(0, $r['checked'], 'nothing to derive from, so nothing to manage');
        $this->assertSame('nominations', $this->storedStatus(8));
    }

    public function test_the_process_timezone_is_pinned(): void
    {
        // Every process must agree on what time it is, or cron and web requests
        // compute different phases from identical rows — permanently.
        $this->assertSame('UTC', Clock::boot(), 'the default must be UTC');
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame(date_default_timezone_get(), Clock::timezone());
    }

    public function test_an_invalid_configured_timezone_falls_back_rather_than_breaking(): void
    {
        $prev = $_ENV['APP_TIMEZONE'] ?? null;
        $_ENV['APP_TIMEZONE'] = 'Not/AZone';
        $this->assertSame('UTC', Clock::boot(), 'a typo must not leave the process on an arbitrary zone');

        $_ENV['APP_TIMEZONE'] = 'Africa/Lagos';
        $this->assertSame('Africa/Lagos', Clock::boot(), 'a valid IANA identifier is honoured');

        if ($prev === null) { unset($_ENV['APP_TIMEZONE']); } else { $_ENV['APP_TIMEZONE'] = $prev; }
        Clock::boot();
    }
}
