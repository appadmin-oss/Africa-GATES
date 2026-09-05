<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\CronHealth;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A schedule that has stopped must say so.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A MONEY TEST AND NOT AN OPS TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two things on this platform only ever happen inside the maintenance run:
 *
 *   • PaymentReconciler re-asks the gateway about pending payments and confirms
 *     the ones that really paid — the fix for a dropped browser callback.
 *   • RefundService returns money for votes that could not be minted.
 *
 * Neither runs on a web request. So when the schedule stops, supporters who paid
 * stop being credited and supporters who are owed stop being paid — and nothing
 * looks wrong. Pages serve. Votes are cast. Checkouts complete. The only symptom
 * is a complaint, weeks later, about money.
 *
 * The scheduler here is a free webcron on a host with no shell. Those get disabled
 * for repeated non-200s, expire, and get deleted by whoever set them up. A stall is
 * not a hypothetical.
 *
 * ── THE STRUCTURAL POINT ─────────────────────────────────────────────────────
 *
 * A stalled run cannot notice its own stall: if it can raise an alert, it ran. So
 * the detector lives outside the run, on admin page loads, and these tests pin the
 * two ways it could be useless — crying wolf so it gets ignored, or staying quiet
 * when it matters.
 */
final class CronHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_cron_log')->delete();
        DB::table('gates_settings')->where('key_name', 'cron_stall_alerted_at')->delete();
    }

    /** Record a completed maintenance run $hoursAgo. */
    private function ranHoursAgo(float $hours): void
    {
        DB::table('gates_cron_log')->insert([
            'job_name'   => 'maintenance',
            'status'     => 'success',
            'message'    => '[]',
            'runtime_ms' => 100,
            'ran_at'     => Carbon::now()->subMinutes((int) round($hours * 60))->toDateTimeString(),
        ]);
    }

    // ══ not crying wolf ══════════════════════════════════════════════════════

    /**
     * A NORMAL quiet night is not a stall.
     *
     * The schedule ticks every ~15 minutes, so a short threshold looks right and is
     * wrong: free webcron tiers are irregular and the traffic-driven fallback only
     * fires when somebody visits. A banner that fires at 03:00 every night is
     * ignored by the second week, and then it is worth nothing on the night it is
     * true.
     */
    public function test_a_recent_run_is_healthy(): void
    {
        $this->ranHoursAgo(0.5);
        $s = CronHealth::status();

        $this->assertTrue($s['ok']);
        $this->assertFalse($s['stale']);
        $this->assertNull($s['say'], 'Nothing to say when nothing is wrong.');
    }

    /** Just inside the window is still healthy — the boundary is not jittery. */
    public function test_just_under_the_threshold_is_still_healthy(): void
    {
        $this->ranHoursAgo(CronHealth::STALE_HOURS - 0.5);
        $this->assertTrue(CronHealth::status()['ok']);
    }

    /**
     * A future-dated row reads as "just ran", never as a stall.
     *
     * Server and database clocks disagree in practice, and a row a few minutes ahead
     * would otherwise compute a negative gap. Whatever that would round to, it must
     * not be an alarm — a clock skew is not a stopped scheduler.
     */
    public function test_a_clock_skew_is_never_reported_as_a_stall(): void
    {
        DB::table('gates_cron_log')->insert([
            'job_name' => 'maintenance', 'status' => 'success', 'message' => '[]',
            'runtime_ms' => 10,
            'ran_at' => Carbon::now()->addMinutes(20)->toDateTimeString(),
        ]);

        $s = CronHealth::status();
        $this->assertTrue($s['ok']);
        $this->assertSame(0.0, $s['hours']);
    }

    // ══ and not staying quiet ════════════════════════════════════════════════

    /**
     * THE CASE THIS EXISTS FOR. Past the threshold, work has provably been missed.
     *
     * The message must name the CONSEQUENCE, not the mechanism. "Cron stale" is not
     * something an operator can act on; "refunds are not being sent" is.
     */
    public function test_a_stalled_schedule_names_what_has_stopped(): void
    {
        $this->ranHoursAgo(CronHealth::STALE_HOURS + 2);
        $s = CronHealth::status();

        $this->assertFalse($s['ok']);
        $this->assertTrue($s['stale']);
        $this->assertStringContainsString('refunds', strtolower((string) $s['say']));
        $this->assertStringContainsString('reconcil', strtolower((string) $s['say']));
    }

    /** The threshold is late enough to be true: a refund retry ladder has been missed. */
    public function test_the_threshold_outlasts_the_refund_retry_ladder(): void
    {
        $this->assertGreaterThanOrEqual(6, CronHealth::STALE_HOURS,
            'Shorter than the 1h/6h/24h retry ladder and the banner fires on delays '
            . 'rather than on missed work.');
        $this->assertLessThanOrEqual(24, CronHealth::STALE_HOURS,
            'Longer than a day and a whole day of refunds can go unsent unnoticed.');
    }

    /**
     * NEVER RUN is a different message from STOPPED, and neither is silence.
     *
     * A fresh install has no rows. Reporting a "stall" on day zero teaches an
     * operator to dismiss this banner before it has ever been right — but saying
     * nothing at all is how a deployment runs for a month with no scheduler.
     */
    public function test_never_having_run_is_reported_as_setup_not_as_a_stall(): void
    {
        $s = CronHealth::status();

        $this->assertFalse($s['ok'], 'Silence here is how a platform runs for weeks with no scheduler.');
        $this->assertTrue($s['never']);
        $this->assertFalse($s['stale'], 'Never run is not the same fault as stopped running.');
        $this->assertNull($s['last']);
        $this->assertStringContainsString('never run', strtolower((string) $s['say']));
    }

    /** Only maintenance runs count — another job's row is not evidence. */
    public function test_another_jobs_run_does_not_count_as_maintenance(): void
    {
        DB::table('gates_cron_log')->insert([
            'job_name' => 'something-else', 'status' => 'success', 'message' => '[]',
            'runtime_ms' => 10, 'ran_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->assertTrue(CronHealth::status()['never'],
            'A different job ticking says nothing about whether refunds are running.');
    }

    /**
     * A FAILED run still counts as the schedule being alive.
     *
     * Deliberate: `run()` isolates tasks and records `error` when any one of them
     * failed, so a single broken task must not read as "the scheduler is dead". They
     * are different problems with different fixes, and the per-task failure is
     * already reported elsewhere. Conflating them would point somebody at their
     * webcron configuration over a bug in one job.
     */
    public function test_a_run_that_reported_failures_still_counts_as_alive(): void
    {
        DB::table('gates_cron_log')->insert([
            'job_name' => 'maintenance', 'status' => 'error', 'message' => '{"failures":{"cpi":"boom"}}',
            'runtime_ms' => 50, 'ran_at' => Carbon::now()->subMinutes(5)->toDateTimeString(),
        ]);

        $this->assertTrue(CronHealth::status()['ok'],
            'One failing task is not a stopped scheduler — different fault, different fix.');
    }

    // ══ the alert, and not a hundred copies of it ════════════════════════════

    /**
     * Checked on every admin page load, so it must claim before it sends.
     *
     * Without the claim this is one email per click. The refund sweep's daily-ceiling
     * alert had exactly this bug and sent the same paragraph a hundred times before
     * midnight, which is how a real alert becomes a mail filter.
     */
    public function test_the_stall_alert_is_claimed_once_not_once_per_page_load(): void
    {
        $this->ranHoursAgo(CronHealth::STALE_HOURS + 1);

        $this->assertTrue(CronHealth::claimAlert(), 'The first look should send.');
        $this->assertFalse(CronHealth::claimAlert(), 'The second should not.');
        $this->assertFalse(CronHealth::claimAlert());
    }

    /** A healthy schedule never claims an alert at all. */
    public function test_a_healthy_schedule_never_alerts(): void
    {
        $this->ranHoursAgo(0.25);
        $this->assertFalse(CronHealth::claimAlert());
    }

    /** After a day it may be raised again — a stall nobody fixed is still a stall. */
    public function test_a_stall_left_unfixed_is_raised_again_the_next_day(): void
    {
        $this->ranHoursAgo(CronHealth::STALE_HOURS + 1);
        $this->assertTrue(CronHealth::claimAlert());

        // Backdate the claim by more than the alert interval.
        DB::table('gates_settings')->where('key_name', 'cron_stall_alerted_at')
            ->update(['value' => Carbon::now()->subHours(25)->toDateTimeString()]);

        $this->assertTrue(CronHealth::claimAlert(),
            'A stall that has gone unfixed for a day deserves saying again.');
    }

    // ══ phrasing ════════════════════════════════════════════════════════════

    public function test_the_gap_is_phrased_for_a_person(): void
    {
        $this->assertSame('less than an hour', CronHealth::humanGap(0.4));
        $this->assertSame('1 hour',            CronHealth::humanGap(1.0));
        $this->assertSame('7 hours',           CronHealth::humanGap(7.2));
        $this->assertSame('3 days',            CronHealth::humanGap(72.0));
        $this->assertSame('an unknown time',   CronHealth::humanGap(null));
    }

    // ══ running, but nothing getting through ═════════════════════════════════

    private function failedHoursAgo(float $hours): void
    {
        DB::table('gates_cron_log')->insert([
            'job_name'   => 'maintenance',
            // 'error' is what Maintenance writes when ANY task inside the run failed —
            // a distinction it makes deliberately and this class used to discard.
            'status'     => 'error',
            'message'    => '{"ran":[],"failures":["queue"]}',
            'runtime_ms' => 100,
            'ran_at'     => Carbon::now()->subMinutes((int) round($hours * 60))->toDateTimeString(),
        ]);
    }

    /**
     * The state that used to read as healthy.
     *
     * A schedule ticking every fifteen minutes and failing the queue drain every time
     * reported "ran 12 minutes ago", in green, indefinitely — while the queue's own check
     * went degraded because its head was hours old. Two symptoms on the board and the one
     * component whose entire purpose is to explain them stayed silent.
     */
    public function test_a_schedule_that_never_completes_cleanly_is_not_healthy(): void
    {
        for ($h = 8.0; $h > 0; $h -= 0.25) $this->failedHoursAgo($h);

        $st = CronHealth::status();

        $this->assertFalse($st['stale'], 'it IS running — that is the point');
        $this->assertTrue($st['failing'], 'running and never completing is not healthy');
        $this->assertFalse($st['ok']);
        $this->assertStringContainsString('tasks inside it are failing', (string) $st['say']);
    }

    /** One bad tick is a blip. A page that cries about a blip is ignored on the day it matters. */
    public function test_a_single_failed_tick_is_not_reported_as_failing(): void
    {
        $this->ranHoursAgo(0.5);
        $this->failedHoursAgo(0.1);

        $st = CronHealth::status();

        $this->assertFalse($st['failing'], 'one failed tick after a clean one is a blip');
        $this->assertTrue($st['ok']);
    }

    /** A stall is reported as a stall, not as failing — they need different remedies. */
    public function test_a_stalled_schedule_is_not_also_reported_as_failing(): void
    {
        $this->ranHoursAgo(30);

        $st = CronHealth::status();

        $this->assertTrue($st['stale']);
        $this->assertFalse($st['failing'], 'a schedule that is not running cannot be "running but failing"');
    }

    /**
     * A schedule that has never once got through says so — but only once it has had a full
     * window to. The clock runs from the FIRST run, not from the last failed one, which is
     * what keeps {@see test_a_run_that_reported_failures_still_counts_as_alive} true: a
     * first tick five minutes ago that failed one task is a broken task, not a broken
     * schedule, and conflating them sends somebody to reconfigure a working webcron.
     */
    public function test_a_schedule_that_has_never_completed_cleanly_says_so(): void
    {
        $this->failedHoursAgo(9);      // it has had nine hours to succeed once
        $this->failedHoursAgo(0.2);

        $this->assertTrue(CronHealth::isFailing());
        $this->assertNull(CronHealth::lastCleanRunAt());
    }

    /** And it stays quiet while that window is still open. */
    public function test_a_young_install_failing_for_minutes_is_not_yet_reported(): void
    {
        $this->failedHoursAgo(0.2);

        $this->assertFalse(CronHealth::isFailing(),
            'five minutes of failure is a broken task, not a broken schedule');
    }

    /** The healthy case still reads healthy — the whole point of the blip rule above. */
    public function test_a_clean_recent_run_is_still_ok(): void
    {
        $this->ranHoursAgo(0.2);

        $st = CronHealth::status();

        $this->assertTrue($st['ok']);
        $this->assertFalse($st['failing']);
        $this->assertNotNull($st['clean']);
    }
}
