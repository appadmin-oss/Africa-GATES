<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AnalyticsService;
use AfricaGates\Services\EventService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * `gates_events` is read by something.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The table was written on four paths from the day it was added — a vote cast, a
 * milestone reached, a fraud score flagged, an OTP requested — and read by NOTHING. Rows
 * accumulated for the life of every install so a question could be answered, and nothing
 * ever asked it. `CODEBASE-INDEX.md` §17 records five of these; the closest twin is
 * `gates_status_log.components_json`, stored every fifteen minutes so the status page
 * could say "something broke on the 14th" and not which thing.
 *
 * A no-reader bug cannot be caught by testing the writer, which is why every one of those
 * five shipped. So the reader is what is asserted here, and it is asserted to return the
 * rows a writer actually wrote — a panel that renders an empty table on a full log would
 * pass a test of its own and be the same bug.
 */
final class EventLogReaderTest extends TestCase
{
    private function fire(): EventService
    {
        return new EventService();
    }

    // ════════════════════════════════════════════════════════════════════════

    /** Written, then read. The whole property, in one test. */
    public function test_what_is_dispatched_comes_back_out_of_the_reader(): void
    {
        $e = $this->fire();
        $e->voteCast(11, 3, 'email-hash-a', 'ip-a', 'device-a');
        $e->voteCast(12, 3, 'email-hash-b', 'ip-b', 'device-b');
        $e->otpRequested('email-hash-a', 11, 'ip-a');

        $report = AnalyticsService::platformEvents(30);
        $byName = array_column($report['rows'], null, 'name');

        $this->assertSame(3, $report['total']);
        $this->assertSame(2, $byName['vote.submitted']['count']);
        $this->assertSame(1, $byName['otp.requested']['count']);
        $this->assertNotSame('', $byName['vote.submitted']['last'], 'the last-seen stamp must survive');
    }

    /**
     * The actor and device counts are the reason this table earns its writes: a count of
     * votes is already on the analytics page, better, from the votes table. "Eleven OTP
     * requests and one vote from one device" is the sentence nothing else can make.
     */
    public function test_the_reader_separates_volume_from_the_people_behind_it(): void
    {
        $e = $this->fire();
        for ($i = 0; $i < 11; $i++) $e->otpRequested('one-email', 11, 'one-ip');

        $row = array_column(AnalyticsService::platformEvents(30)['rows'], null, 'name')['otp.requested'];

        $this->assertSame(11, $row['count'], 'volume');
        $this->assertSame(1, $row['actors'], 'from one person — which is the finding');
    }

    /** Busiest first: the row somebody opened this page about is the one with volume. */
    public function test_the_busiest_action_is_first(): void
    {
        $e = $this->fire();
        $e->fraudFlagged(1, 90, 'blocked');
        for ($i = 0; $i < 4; $i++) $e->voteCast($i, 1, 'h' . $i, 'ip', null);

        $rows = AnalyticsService::platformEvents(30)['rows'];

        $this->assertSame('vote.submitted', $rows[0]['name']);
    }

    /** Outside the window is outside the report. */
    public function test_the_window_is_honoured(): void
    {
        $this->fire()->voteCast(1, 1, 'h', 'ip', null);
        DB::table('gates_events')->update(['created_at' => date('Y-m-d H:i:s', strtotime('-90 days'))]);

        $this->assertSame(0, AnalyticsService::platformEvents(30)['total']);
    }

    /** An empty log is an instrumentation gap, not a crash. */
    public function test_an_empty_log_reports_nothing_rather_than_failing(): void
    {
        DB::table('gates_events')->delete();

        $this->assertSame(['rows' => [], 'total' => 0], AnalyticsService::platformEvents(30));
    }

    // ══ the guard that never guarded ═════════════════════════════════════════

    /**
     * The constructor's comment said "silently disable if the events table doesn't exist
     * yet" and the code discarded hasTable()'s return value, setting enabled = true
     * whenever the call did not throw. So on a database without the table every dispatch
     * went ahead and fell into its own silent catch — a guard describing behaviour it did
     * not have, in front of writes nobody could see failing.
     */
    public function test_the_service_disables_itself_when_the_table_is_missing(): void
    {
        DB::schema()->drop('gates_events');

        $svc = new EventService();
        $svc->voteCast(1, 1, 'h', 'ip', null);   // must not throw, and must not try

        $enabled = new \ReflectionProperty(EventService::class, 'enabled');
        $enabled->setAccessible(true);

        $this->assertFalse($enabled->getValue($svc),
            'hasTable() said no and the service carried on regardless');
    }

    // ══ one reader per table ═════════════════════════════════════════════════

    /**
     * `funnelReport()` read `gates_funnel_events`, which `AnalyticsService::ballotFunnel()`
     * already reads and renders — and it had no caller, so the duplicate was invisible.
     * Two readers of one table is how the two come to disagree about what a step means.
     */
    public function test_the_funnel_has_one_reader(): void
    {
        $this->assertFalse(method_exists(EventService::class, 'funnelReport'),
            'gates_funnel_events belongs to AnalyticsService::ballotFunnel()');
    }

    /**
     * The four emitters nothing called are gone rather than wired: registrations and
     * nominations are counted straight off the domain tables by audience() and
     * nominationFunnel(), which is a better count than a parallel log that can be
     * forgotten at a call site.
     */
    public function test_no_emitter_survives_without_a_caller(): void
    {
        foreach (['nominationReceived', 'nomineeApproved',
                  'registrationCompleted', 'shareClicked'] as $dead) {
            $this->assertFalse(method_exists(EventService::class, $dead),
                $dead . ' is declared and nothing calls it');
        }
    }

    /** And the panel that reads all this is actually on the page. */
    public function test_the_reader_is_rendered(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/analytics.twig');
        $ctl = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/AnalyticsController.php');

        $this->assertStringContainsString('platformEvents(', $ctl, 'nothing calls the reader');
        $this->assertStringContainsString('events.rows', $tpl, 'nothing renders it');
    }
}
