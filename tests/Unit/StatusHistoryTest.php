<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SystemStatus;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * The record, and the four things a status page is expected to say about it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * `components_json` WAS WRITTEN EVERY FIFTEEN MINUTES AND READ BY NOTHING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `SystemStatus::record()` has stored the per-component state of every snapshot since the
 * log was created. Only the `overall` column was ever read — so the page could say
 * "something was wrong on the 14th" and not WHICH THING, while the answer sat in the next
 * column of the same row.
 *
 * That is the sixth instance of the pattern in `docs/CODEBASE-INDEX.md` §17 and the most
 * visible one: a per-component history bar is the single most recognisable element of a
 * status page, and the data for it was already on disk.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE OTHER HALF IS ABOUT NOT LYING WITH A NUMBER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A status page that publishes uptime has two standard ways to be dishonest, and both are
 * asserted against here:
 *
 *  · Counting time it could not measure as time it was up. The denominator is checks whose
 *    result is KNOWN — the same discipline `SitemapService` applies to a `lastmod` it cannot
 *    vouch for.
 *  · Rounding. One failure in twenty thousand checks is 99.995%, which rounds to a clean
 *    hundred and erases the outage the reader came here about. The figure is FLOORED.
 */
final class StatusHistoryTest extends TestCase
{
    private const NAMES = ['Voting and profiles', 'Payments', 'Email'];

    /**
     * Midday, fixed.
     *
     * ── WHY THE CLOCK IS PINNED AND NOT LEFT TO RUN ──────────────────────────
     *
     * `SystemStatus` buckets snapshots BY CALENDAR DAY (`substr($s['at'], 0, 10)`) and the
     * assertions below read the LAST bucket, meaning "today". The fixtures place snapshots
     * up to 240 minutes back — so on the wall clock, every one of those assertions quietly
     * depends on the suite being run more than four hours after midnight.
     *
     * Run at 01:10 UTC and `snap(180, DEGRADED)` lands at 22:10 YESTERDAY. Today then holds
     * only the healthy 60-minute reading, `test_the_worst_state_of_a_day_is_the_days_state`
     * reads `operational`, and the failure says "averaging would turn a real outage into a
     * pale shade of green" — accusing the production code of a fault that is entirely in
     * the fixture. That is the expensive kind of red: it names code nobody touched.
     *
     * Midday gives twelve hours of headroom on either side of every offset in this file.
     */
    private const NOW = '2026-06-10 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        // `SystemStatus` reads `Carbon\Carbon::now()`, so this is the clock it sees.
        Carbon::setTestNow(Carbon::parse(self::NOW));
        DB::table('gates_status_log')->delete();
    }

    protected function tearDown(): void
    {
        // Unconditionally, and before the rollback: a frozen clock that escapes this file
        // is a time traveller loose in the rest of the suite, and the test it breaks will
        // be somewhere else entirely.
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * One recorded snapshot, $minutesAgo ago.
     *
     * Measured from the SAME clock the code under test reads. Writing `date()` here while
     * `SystemStatus` reads `Carbon::now()` is what made these assertions depend on the hour
     * the suite happened to be run at.
     *
     * @param array<string,string> $parts component name => state
     */
    private function snap(int $minutesAgo, string $overall, array $parts = []): void
    {
        DB::table('gates_status_log')->insert([
            'taken_at'        => Carbon::now()->subMinutes($minutesAgo)->toDateTimeString(),
            'overall'         => $overall,
            'components_json' => (string) json_encode(array_map(
                static fn (string $n, string $s): array => ['name' => $n, 'status' => $s],
                array_keys($parts), array_values($parts)
            )),
            'created_at'      => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** All three components at one state. */
    private function all(int $minutesAgo, string $state): void
    {
        $this->snap($minutesAgo, $state, array_fill_keys(self::NAMES, $state));
    }

    /** The pure arithmetic, reached directly — the property is a formula, not a query. */
    private function pct(int $ok, int $known): ?float
    {
        $m = new \ReflectionMethod(SystemStatus::class, 'pct');
        $m->setAccessible(true);
        return $m->invoke(null, $ok, $known);
    }

    // ══ the column nobody read ═══════════════════════════════════════════════

    /**
     * THE BUG. The history knew which component broke and the page could not say.
     */
    public function test_each_component_gets_its_own_history(): void
    {
        // Everything fine for two checks, then Payments alone goes down.
        $this->all(60, SystemStatus::OK);
        $this->all(45, SystemStatus::OK);
        $this->snap(30, SystemStatus::DOWN, [
            'Voting and profiles' => SystemStatus::OK,
            'Payments'            => SystemStatus::DOWN,
            'Email'               => SystemStatus::OK,
        ]);

        $c = SystemStatus::timeline()['components'];

        $this->assertArrayHasKey('Payments', $c);
        $this->assertArrayHasKey('Email', $c);

        $today = static fn (array $h): string => $h['days'][count($h['days']) - 1]['status'];

        $this->assertSame(SystemStatus::DOWN, $today($c['Payments']),
            'the log knew it was Payments; the page could only say "something"');
        $this->assertSame(SystemStatus::OK, $today($c['Email']),
            'and one broken component must not paint the rest of the board');
    }

    /** Worst wins within a day: a day with one broken hour is not a good day. */
    public function test_the_worst_state_of_a_day_is_the_days_state(): void
    {
        $this->all(240, SystemStatus::OK);
        $this->snap(180, SystemStatus::DEGRADED, ['Payments' => SystemStatus::DEGRADED]);
        $this->all(60, SystemStatus::OK);

        $days = SystemStatus::timeline()['components']['Payments']['days'];

        $this->assertSame(SystemStatus::DEGRADED, $days[count($days) - 1]['status'],
            'averaging would turn a real outage into a pale shade of green');
    }

    /** A component recorded under a name nothing serves any more is simply not drawn. */
    public function test_a_renamed_component_is_dropped_rather_than_misattributed(): void
    {
        $this->snap(30, SystemStatus::OK, ['A Name We No Longer Use' => SystemStatus::DOWN]);

        $c = SystemStatus::timeline()['components'];

        $this->assertArrayHasKey('A Name We No Longer Use', $c);
        // The template looks components up BY NAME, so an unmatched key renders nothing.
        // Attributing one component's history to another because the labels are adjacent
        // would be worse than a missing bar.
        $this->assertArrayNotHasKey('Payments', $c);
    }

    /** A snapshot written by a future version of this class is not counted as healthy. */
    public function test_an_unrecognised_state_is_dropped_not_defaulted(): void
    {
        $this->snap(30, SystemStatus::OK, ['Payments' => 'catastrophic']);

        $this->assertArrayNotHasKey('Payments', SystemStatus::timeline()['components'],
            'an unknown word must not silently become operational');
    }

    // ══ the percentage must not be able to lie ═══════════════════════════════

    /**
     * THE ROUNDING LIE. One failure in twenty thousand is not one hundred per cent.
     *
     * This is the single most common dishonesty on a status page, and it is invisible: the
     * page looks perfect precisely on the fortnight somebody is here to ask about an outage.
     */
    public function test_a_window_with_a_failure_can_never_read_as_a_clean_hundred(): void
    {
        $this->assertSame(99.99, $this->pct(19_999, 20_000));
        $this->assertSame(99.99, $this->pct(999_999, 1_000_000));
    }

    /** A perfect window still reads as a perfect window. */
    public function test_a_flawless_window_reads_as_a_hundred(): void
    {
        $this->assertSame(100.0, $this->pct(1_340, 1_340));
    }

    /** And it rounds DOWN, so the figure is never better than the evidence. */
    public function test_the_figure_is_floored_not_rounded(): void
    {
        // 99.899…% — a page that rounded would print 99.90.
        $this->assertSame(99.89, $this->pct(998, 999));
    }

    /** Nothing to divide means no number, not a zero and not a hundred. */
    public function test_no_checks_means_no_percentage(): void
    {
        $this->assertNull($this->pct(0, 0));
        $this->assertNull(SystemStatus::timeline()['uptime']['pct'],
            'a fresh installation must not claim a fortnight of anything');
    }

    /**
     * Time we could not measure is not time we were up.
     *
     * Putting UNKNOWN in either half of the fraction would be an invention. It is excluded
     * from both, and the sample count is published so the denominator is visible.
     */
    public function test_unmeasured_checks_are_in_neither_half_of_the_fraction(): void
    {
        $this->all(90, SystemStatus::OK);
        $this->all(75, SystemStatus::UNKNOWN);
        $this->all(60, SystemStatus::UNKNOWN);
        $this->all(45, SystemStatus::OK);

        $u = SystemStatus::timeline()['uptime'];

        $this->assertSame(2, $u['samples'], 'only the checks we can vouch for');
        $this->assertSame(100.0, $u['pct'],
            'both known checks were fine, and the two we could not read are not failures');
    }

    // ══ incidents ════════════════════════════════════════════════════════════

    /** Consecutive failing checks are ONE problem, with a duration. */
    public function test_a_run_of_failing_checks_is_a_single_incident(): void
    {
        $this->snap(120, SystemStatus::OK,       ['Payments' => SystemStatus::OK]);
        $this->snap(105, SystemStatus::DEGRADED, ['Payments' => SystemStatus::DEGRADED]);
        $this->snap(90,  SystemStatus::DEGRADED, ['Payments' => SystemStatus::DEGRADED]);
        $this->snap(75,  SystemStatus::DEGRADED, ['Payments' => SystemStatus::DEGRADED]);
        $this->snap(60,  SystemStatus::OK,       ['Payments' => SystemStatus::OK]);

        $inc = SystemStatus::timeline()['incidents'];

        $this->assertCount(1, $inc, 'three consecutive bad checks are one problem, not three');
        $this->assertSame('Payments', $inc[0]['name']);
        $this->assertSame(3, $inc[0]['checks']);
        $this->assertSame(30, $inc[0]['minutes']);
        $this->assertSame('30 min', $inc[0]['duration']);
        $this->assertFalse($inc[0]['ongoing'], 'it was seen to recover');
    }

    /** Two runs separated by a recovery are two problems. */
    public function test_a_recovery_between_two_runs_makes_two_incidents(): void
    {
        $this->snap(120, SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(105, SystemStatus::OK,   ['Payments' => SystemStatus::OK]);
        $this->snap(90,  SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(75,  SystemStatus::OK,   ['Payments' => SystemStatus::OK]);

        $this->assertCount(2, SystemStatus::timeline()['incidents']);
    }

    /** The worst state during a run is what the run is reported as. */
    public function test_a_wobble_that_became_an_outage_is_reported_as_an_outage(): void
    {
        $this->snap(90, SystemStatus::DEGRADED, ['Payments' => SystemStatus::DEGRADED]);
        $this->snap(75, SystemStatus::DOWN,     ['Payments' => SystemStatus::DOWN]);
        $this->snap(60, SystemStatus::OK,       ['Payments' => SystemStatus::OK]);

        $this->assertSame(SystemStatus::DOWN, SystemStatus::timeline()['incidents'][0]['status']);
    }

    /**
     * A GAP IN THE RECORD IS NOT AN INCIDENT.
     *
     * Cron missing a beat is not the platform breaking, and listing it as an outage would
     * manufacture incidents out of scheduling jitter — making the one list on the page that
     * names real faults worthless. The dashed cells in the bars are where the record has
     * holes; that is a different statement.
     */
    public function test_a_gap_in_the_record_is_not_reported_as_a_problem(): void
    {
        $this->snap(90, SystemStatus::UNKNOWN, ['Payments' => SystemStatus::UNKNOWN]);
        $this->snap(75, SystemStatus::UNKNOWN, ['Payments' => SystemStatus::UNKNOWN]);

        $this->assertSame([], SystemStatus::timeline()['incidents']);
    }

    /** And it closes a run rather than extending one: we stopped being able to see the fault. */
    public function test_an_unreadable_check_ends_a_run_rather_than_extending_it(): void
    {
        $this->snap(90, SystemStatus::DOWN,    ['Payments' => SystemStatus::DOWN]);
        $this->snap(75, SystemStatus::UNKNOWN, ['Payments' => SystemStatus::UNKNOWN]);
        $this->snap(60, SystemStatus::DOWN,    ['Payments' => SystemStatus::DOWN]);

        $inc = SystemStatus::timeline()['incidents'];

        $this->assertCount(2, $inc,
            'claiming one continuous outage across a blind spell asserts something we did not see');
    }

    /**
     * A fault seen once is reported as one check, not as a quarter of an hour.
     *
     * Rounding a single sample up to the sampling cadence is how a status page accumulates
     * downtime it never witnessed.
     */
    public function test_a_single_failing_check_is_not_inflated_into_a_duration(): void
    {
        $this->snap(90, SystemStatus::OK,       ['Payments' => SystemStatus::OK]);
        $this->snap(75, SystemStatus::DEGRADED, ['Payments' => SystemStatus::DEGRADED]);
        $this->snap(60, SystemStatus::OK,       ['Payments' => SystemStatus::OK]);

        $inc = SystemStatus::timeline()['incidents'][0];

        $this->assertSame(0, $inc['minutes']);
        $this->assertSame('seen once', $inc['duration']);
    }

    /** Still failing at the last check we have is said out loud. */
    public function test_a_problem_that_has_not_recovered_is_marked_ongoing(): void
    {
        $this->snap(45, SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(30, SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);

        $this->assertTrue(SystemStatus::timeline()['incidents'][0]['ongoing']);
    }

    /** Newest first: the thing somebody is here about happened recently. */
    public function test_incidents_are_newest_first(): void
    {
        $this->snap(600, SystemStatus::DOWN, ['Email' => SystemStatus::DOWN]);
        $this->snap(585, SystemStatus::OK,   ['Email' => SystemStatus::OK]);
        $this->snap(120, SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(105, SystemStatus::OK,   ['Payments' => SystemStatus::OK]);

        $inc = SystemStatus::timeline()['incidents'];

        $this->assertSame('Payments', $inc[0]['name']);
        $this->assertSame('Email', $inc[1]['name']);
    }

    /** Long faults read as hours, because "312 min" is not a sentence anybody parses. */
    public function test_a_long_fault_reads_in_hours(): void
    {
        $this->snap(400, SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(160, SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(145, SystemStatus::OK,   ['Payments' => SystemStatus::OK]);

        $this->assertSame('4 hr', SystemStatus::timeline()['incidents'][0]['duration']);
    }

    // ══ the page ═════════════════════════════════════════════════════════════

    private function render(): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $twig = $b->build()->get(Twig::class);

        $report = SystemStatus::report();
        $tl     = SystemStatus::timeline();

        return $twig->fetch('pages/status.twig', $report + [
            'history'           => $tl['days'],
            'history_note'      => SystemStatus::historyNote($tl['days']),
            'history_days'      => SystemStatus::HISTORY_DAYS,
            'component_history' => $tl['components'],
            'incidents'         => $tl['incidents'],
            'uptime'            => $tl['uptime'],
            'status_labels'     => SystemStatus::LABELS,
            'page_title'        => 'Is it working?',
            'gates_page'        => 'status',
            'has_hero'          => false,
        ]);
    }

    /** The bars reach the page, and a screen reader gets the summary rather than the cells. */
    public function test_the_page_draws_a_history_bar_per_component(): void
    {
        $this->all(60, SystemStatus::OK);
        $this->snap(30, SystemStatus::DEGRADED, [
            'Payments' => SystemStatus::DEGRADED, 'Email' => SystemStatus::OK,
        ]);

        $html = $this->render();

        $this->assertStringContainsString('stx-cell', $html, 'the per-component bars');
        $this->assertStringContainsString('Payments over the last 14 days', $html,
            'the cells are aria-hidden, so the group label has to carry the information');
        // Fourteen indistinguishable squares read aloud one by one convey nothing.
        $this->assertStringContainsString('stx-cell stx-f stx-f--degraded" aria-hidden="true"', $html);
    }

    /** The incident list reaches the page with its duration. */
    public function test_the_page_lists_recent_problems(): void
    {
        $this->snap(120, SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(90,  SystemStatus::DOWN, ['Payments' => SystemStatus::DOWN]);
        $this->snap(75,  SystemStatus::OK,   ['Payments' => SystemStatus::OK]);

        $html = $this->render();

        $this->assertStringContainsString('Recent problems', $html);
        $this->assertStringContainsString('30 min', $html, 'a problem with no duration is a rumour');
    }

    /** A clean fortnight says so, rather than showing an empty heading. */
    public function test_a_clean_window_says_nothing_went_wrong(): void
    {
        $this->all(60, SystemStatus::OK);

        $this->assertStringContainsString('Nothing was recorded as broken or slow',
            $this->render());
    }

    /** Four states, four fills, and a key — otherwise the fills mean nothing to a reader. */
    public function test_the_page_carries_a_legend(): void
    {
        $html = $this->render();

        foreach (['Working', 'Slower than usual', 'Not working', 'Not checked'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('stx-key', $html);
    }

    /** And it points at the machine-readable board, because monitors are readers too. */
    public function test_the_page_names_the_endpoint(): void
    {
        $this->assertStringContainsString('/status.json', $this->render());
    }

    // ══ the endpoint ═════════════════════════════════════════════════════════

    /**
     * The board as data.
     *
     * An uptime monitor cannot scrape a Twig template without breaking the next time
     * somebody moves a div, and with no shell on this host a machine-readable state is the
     * only thing an external watcher can act on.
     */
    public function test_the_payload_carries_a_stable_token_and_the_prose(): void
    {
        $this->all(30, SystemStatus::OK);

        $p = SystemStatus::payload();

        // A token to switch on, and words beside it. Renaming a LABEL must not break
        // somebody's alerting.
        $this->assertContains($p['status'],
            [SystemStatus::OK, SystemStatus::DEGRADED, SystemStatus::DOWN, SystemStatus::UNKNOWN]);
        $this->assertNotEmpty($p['description']);
        $this->assertNotEmpty($p['components']);
        // ISO, because a bare "2026-08-25 09:07:06" has no zone and every consumer would
        // guess a different one.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $p['checked_at']);

        foreach ($p['components'] as $c) {
            $this->assertArrayHasKey('status', $c);
            $this->assertArrayHasKey('detail', $c);
            $this->assertArrayHasKey('uptime', $c);
        }
    }

    /** With no record, the endpoint reports null uptime rather than inventing one. */
    public function test_the_payload_never_invents_an_uptime(): void
    {
        $p = SystemStatus::payload();

        $this->assertNull($p['uptime']['pct']);
        foreach ($p['components'] as $c) {
            $this->assertNull($c['uptime'],
                'a consumer alerting on uptime < 99 must not be handed a made-up number');
        }
    }

    /** It is JSON-encodable, which a nested Carbon or resource would quietly break. */
    public function test_the_payload_encodes(): void
    {
        $this->all(30, SystemStatus::OK);

        $json = json_encode(SystemStatus::payload());

        $this->assertIsString($json);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertIsArray(json_decode((string) $json, true));
    }

    /** The route exists, is uncached, and is readable from another origin. */
    public function test_the_endpoint_is_routed_and_uncached(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertStringContainsString("'/status.json'", $routes);
        $this->assertStringContainsString('SystemStatus::payload()', $routes);
        // A cached status board is the one artefact whose staleness is indistinguishable
        // from the outage it exists to report.
        $this->assertMatchesRegularExpression(
            '/status\.json.{0,1400}no-store/s', $routes);
    }
}
