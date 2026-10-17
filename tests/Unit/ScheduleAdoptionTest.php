<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\CronHealth;
use AfricaGates\Support\Maintenance;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The site taking over its own schedule when nothing else is running it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every automatic money decision on this platform happens in the maintenance run:
 * reconciliation confirming payments whose gateway callback was dropped, the refund
 * sweep returning money for votes that could not be minted, and the assistant
 * working the payment tickets. None of it happens on a web request.
 *
 * The opportunistic web-traffic fallback existed, and was off by default — "so it
 * never surprises a host that already has real cron". A correct default with a bad
 * outcome, because it assumes somebody configured cron. This platform is deployed by
 * uploading a zip through cPanel File Manager; adding a cron job is a separate
 * manual step on a different screen, and skipping it produces no symptom at all.
 * Pages serve, votes are cast, checkouts complete, and supporters who are owed money
 * are quietly not paid until they complain.
 *
 * So when the schedule has PROVABLY missed work, real cron is — whatever anybody
 * intended — not running, and the fallback surprises nobody by starting.
 *
 * These tests hold the four properties that make that safe rather than reckless:
 * an explicit refusal is final, a fresh install is left alone, adoption LATCHES so
 * it cannot oscillate, and a healthy schedule is never touched.
 */
final class ScheduleAdoptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'webcron_auto')->delete();
        DB::table('gates_cron_log')->where('job_name', 'maintenance')->delete();
    }

    private function setting(?string $value): void
    {
        DB::table('gates_settings')->where('key_name', 'webcron_auto')->delete();
        if ($value !== null) {
            DB::table('gates_settings')->insert(['key_name' => 'webcron_auto', 'value' => $value]);
        }
    }

    private function ranAt(string $when): void
    {
        DB::table('gates_cron_log')->insert([
            // 'success', not 'ok' — the table has a CHECK constraint.
            'job_name' => 'maintenance', 'status' => 'success', 'ran_at' => $when,
        ]);
    }

    /**
     * Make the installation look older than the grace period.
     *
     * Ages every source installAgeHours() consults, and asserts the result, so a
     * change to the fixtures fails here with a clear reason instead of quietly
     * turning the adoption tests into tests of a fresh install.
     */
    private function ageInstall(): void
    {
        $old = Carbon::now()->subDays(30)->toDateTimeString();
        foreach (['gates_award_cycles', 'gates_award_programmes', 'gates_admins'] as $t) {
            try { DB::table($t)->update(['created_at' => $old]); } catch (\Throwable) {}
        }
        if (DB::table('gates_award_cycles')->count() === 0) {
            DB::table('gates_award_programmes')->insertOrIgnore([
                'id' => 99, 'slug' => 'age-fixture', 'title' => 'Age fixture', 'created_at' => $old,
            ]);
            DB::table('gates_award_cycles')->insertOrIgnore([
                'id' => 991, 'programme_id' => 99, 'year' => 2026, 'created_at' => $old,
            ]);
        }
        $ref = new \ReflectionMethod(Maintenance::class, 'installAgeHours');
        $this->assertGreaterThan(CronHealth::STALE_HOURS, (float) $ref->invoke(null),
            'the fixture no longer looks like an old installation');
    }

    // ── an operator's decision is final ─────────────────────────────────────

    /**
     * An explicit off must win, forever. A fallback that cannot be switched off is a
     * worse fault than the one it fixes, and "not enabled" is NOT the same as "off"
     * — the first is an absence of a decision, the second is a decision.
     */
    public function test_an_explicit_refusal_is_never_overridden(): void
    {
        $this->ageInstall();

        foreach (['0', 'off', 'no', 'false', 'OFF'] as $no) {
            $this->setting($no);
            $this->assertFalse(Maintenance::shouldAdopt(),
                'webcron_auto=' . $no . ' was overridden');
            $this->assertTrue(Maintenance::autoRefused(), $no . ' should read as a refusal');
        }
    }

    /** Already on is not a case to adopt: there is nothing to decide. */
    public function test_it_does_not_adopt_when_already_enabled(): void
    {
        $this->ageInstall();
        $this->setting('1');

        $this->assertTrue(Maintenance::autoEnabled());
        $this->assertFalse(Maintenance::shouldAdopt());
    }

    // ── a fresh install is left alone ───────────────────────────────────────

    /**
     * Day zero: nothing has run because nothing has had its turn yet.
     *
     * Adopting here would be a race with the operator's first cron tick rather than
     * a diagnosis, and it would mean every new install silently switches on a
     * feature documented as off by default.
     */
    public function test_a_brand_new_install_is_not_adopted(): void
    {
        DB::table('gates_award_cycles')->update(['created_at' => Carbon::now()->toDateTimeString()]);

        $this->assertTrue(CronHealth::neverRun(), 'the fixture must have no runs');
        $this->assertFalse(Maintenance::shouldAdopt(),
            'a fresh install must be given time for its real cron to fire');
    }

    /**
     * But an installation that has been up for a month with no run at all has had
     * every chance. That is a missing cron job, not a slow start.
     */
    public function test_an_old_install_that_has_never_run_is_adopted(): void
    {
        $this->ageInstall();

        $this->assertTrue(CronHealth::neverRun());
        $this->assertTrue(Maintenance::shouldAdopt());
    }

    // ── stale versus healthy ────────────────────────────────────────────────

    public function test_a_stale_schedule_is_adopted(): void
    {
        $this->ageInstall();
        $this->ranAt(Carbon::now()->subHours(CronHealth::STALE_HOURS + 2)->toDateTimeString());

        $this->assertTrue(CronHealth::isStale());
        $this->assertTrue(Maintenance::shouldAdopt());
    }

    /**
     * A working cron must never be interfered with. This is the case the "off by
     * default" comment was protecting, and it still holds.
     */
    public function test_a_healthy_schedule_is_left_completely_alone(): void
    {
        $this->ageInstall();
        $this->ranAt(Carbon::now()->subMinutes(10)->toDateTimeString());

        $this->assertFalse(CronHealth::isStale());
        $this->assertFalse(Maintenance::shouldAdopt());
        $this->assertFalse(Maintenance::autoEnabled(), 'and nothing was switched on');
    }

    /**
     * A gap shorter than the threshold is a delay, not a stall. Free webcron tiers
     * are irregular and a quiet night is a real gap; adopting on a two-hour lull
     * would fight a cron that is working.
     */
    public function test_a_short_gap_is_a_delay_and_not_a_stall(): void
    {
        $this->ageInstall();
        $this->ranAt(Carbon::now()->subHours(CronHealth::STALE_HOURS - 1)->toDateTimeString());

        $this->assertFalse(Maintenance::shouldAdopt());
    }

    // ── the latch, which is what stops it oscillating ───────────────────────

    /**
     * THE BUG A NAIVE VERSION WOULD HAVE.
     *
     * Adopt on a stale schedule → run → the schedule is now fresh → un-adopt → go
     * stale again six hours later → adopt. That is a run every six hours wearing the
     * costume of a run every fifteen minutes, and every symptom of the original
     * fault survives it.
     *
     * So the decision is persisted the first time. This test proves the state after
     * adoption is one where autoEnabled() carries the schedule, and shouldAdopt()
     * has nothing left to decide.
     */
    public function test_adoption_latches_so_it_cannot_oscillate(): void
    {
        $this->ageInstall();
        $this->ranAt(Carbon::now()->subHours(CronHealth::STALE_HOURS + 2)->toDateTimeString());
        $this->assertTrue(Maintenance::shouldAdopt(), 'precondition');

        // What adoption does: persist the setting.
        $ref = new \ReflectionMethod(Maintenance::class, 'adoptWhenNothingElseRuns');
        $this->assertTrue($ref->invoke(null), 'it should have adopted');

        $this->assertTrue(Maintenance::autoEnabled(),
            'the decision was not persisted — the schedule will oscillate');

        // And now, with a fresh run recorded, the schedule keeps ticking on the
        // setting rather than needing to go stale again to be re-adopted.
        $this->ranAt(Carbon::now()->toDateTimeString());
        $this->assertFalse(CronHealth::isStale());
        $this->assertTrue(Maintenance::autoEnabled(),
            'a healthy schedule must not switch the fallback back off');
        $this->assertFalse(Maintenance::shouldAdopt(), 'nothing left to decide');
    }

    /** And having latched, an operator can still turn it off and be obeyed. */
    public function test_the_operator_can_still_switch_it_off_afterwards(): void
    {
        $this->ageInstall();
        $this->ranAt(Carbon::now()->subHours(CronHealth::STALE_HOURS + 2)->toDateTimeString());
        (new \ReflectionMethod(Maintenance::class, 'adoptWhenNothingElseRuns'))->invoke(null);
        $this->assertTrue(Maintenance::autoEnabled());

        $this->setting('off');

        $this->assertFalse(Maintenance::autoEnabled());
        $this->assertFalse(Maintenance::shouldAdopt(),
            'having adopted once, it must not re-adopt over an explicit off');
    }

    // ── and the reading of the setting itself ───────────────────────────────

    public function test_an_unset_setting_is_neither_on_nor_refused(): void
    {
        $this->setting(null);

        $this->assertFalse(Maintenance::autoEnabled());
        $this->assertFalse(Maintenance::autoRefused(),
            'unset must not read as a refusal, or the fallback could never engage');
    }

    public function test_the_setting_is_read_case_and_space_insensitively(): void
    {
        foreach ([' 1 ', 'On', 'YES', 'true'] as $yes) {
            $this->setting($yes);
            $this->assertTrue(Maintenance::autoEnabled(), var_export($yes, true) . ' should be on');
        }
    }
}
