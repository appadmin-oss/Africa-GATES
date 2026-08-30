<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AnalyticsService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The arrivals report, and the rule that every stored column is read.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS FILE EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_visits` shipped with twelve columns and a report that selected five. `country`,
 * `referrer_host`, `ip_hash` and `converted_at` were written on every arrival and read by
 * nothing — four instances, in one commit, of the fault this codebase calls the most
 * expensive one available (docs/CODEBASE-INDEX.md §17).
 *
 * They cost nothing visible. The writes succeeded, the page looked complete, and the only
 * symptom was an operator who could not answer a question the data was already sitting
 * there to answer. With no shell on production there is no way to notice from the outside.
 *
 * So the rule is asserted rather than remembered: every column this table stores has to
 * turn up in the report. The structural half catches a column added and forgotten; the
 * behavioural half catches a column selected and then dropped on the floor, which the
 * structural half alone would miss.
 */
final class ArrivalsReportTest extends TestCase
{
    /**
     * Columns whose reader is not the report.
     *
     * `id` is the key. `visit_key` is read by {@see \AfricaGates\Services\VisitTracker::convert()},
     * which is its whole job — it is deliberately NOT in a report, because a table of
     * session-scoped keys on an admin screen is a thing to be copied. `created_at` is the
     * window every query here is bounded by.
     */
    private const NOT_THE_REPORTS_JOB = ['id', 'visit_key', 'created_at'];

    private function visit(array $over = []): void
    {
        static $n = 0;
        DB::table('gates_visits')->insert($over + [
            'visit_key'    => str_pad((string) ++$n, 32, 'x', STR_PAD_LEFT),
            'source'       => 'direct',
            'landing_path' => '/',
            'device'       => 'desktop',
            'created_at'   => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ══ THE RULE ════════════════════════════════════════════════════════════

    /**
     * Structural: nothing is stored that the report does not ask for.
     *
     * Read from the LIVE schema, not from a list typed out here — a hard-coded column list
     * is a second copy of the truth, and it would go stale on exactly the commit that adds
     * the next unread column.
     */
    public function test_every_column_the_table_stores_is_asked_for_by_the_report(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('gates_visits');
        $this->assertNotEmpty($columns);

        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Services/AnalyticsService.php');
        $from = (int) strpos($src, 'public static function arrivals');
        $body = substr($src, $from, (int) strpos($src, 'GEOGRAPHY') - $from);

        foreach ($columns as $col) {
            if (in_array($col, self::NOT_THE_REPORTS_JOB, true)) continue;

            $this->assertStringContainsString("'" . $col . "'", $body,
                'gates_visits.' . $col . ' is written on every arrival and the report never '
                . 'asks for it — the most expensive bug available in this codebase');
        }
    }

    /**
     * Behavioural: a column selected and then dropped on the floor still fails.
     *
     * The structural test above would pass on a report that fetched every column and threw
     * four of them away, which is exactly the shape the first cut of this could have taken.
     */
    public function test_each_stored_fact_reaches_the_report(): void
    {
        $this->visit([
            'source'         => 'flier',
            'medium'         => 'print',
            'campaign'       => 'gala26',
            'landing_path'   => '/events/gala',
            'referrer_host'  => 'l.facebook.com',
            'device'         => 'mobile',
            'country'        => 'NG',
            'ip_hash'        => str_repeat('a', 64),
            'converted_kind' => 'vote',
            'converted_at'   => Carbon::now()->toDateTimeString(),
        ]);

        $r = AnalyticsService::arrivals(30);

        $this->assertSame('flier', $r['sources'][0]['source']);
        $this->assertSame('gala26', $r['campaigns'][0]['campaign']);
        $this->assertSame('/events/gala', $r['landings'][0]['path']);
        $this->assertSame('l.facebook.com', $r['hosts'][0]['host'], 'referrer_host had no reader');
        $this->assertSame('print', $r['mediums'][0]['medium'], 'medium had no reader either');
        $this->assertSame('mobile', $r['devices'][0]['device']);
        $this->assertSame('NG', $r['countries'][0]['country'], 'country had no reader');
        $this->assertSame(1, $r['sources'][0]['networks'], 'ip_hash had no reader');
        $this->assertSame('vote', $r['kinds'][0]['kind']);
        $this->assertSame(100, $r['same_visit_pct'], 'converted_at had no reader');
    }

    // ══ THE HOST BEHIND A FOLDED SOURCE ═════════════════════════════════════

    /**
     * "facebook" is five hostnames, and the folded name cannot say which is sending people.
     *
     * The fold is right for the source column — a channel split five ways looks like five
     * small ones — but it destroys information the operator sometimes needs back.
     */
    public function test_the_hosts_behind_one_folded_source_are_still_separable(): void
    {
        $this->visit(['source' => 'facebook', 'referrer_host' => 'l.facebook.com']);
        $this->visit(['source' => 'facebook', 'referrer_host' => 'l.facebook.com']);
        $this->visit(['source' => 'facebook', 'referrer_host' => 'm.facebook.com']);

        $r = AnalyticsService::arrivals(30);

        $this->assertCount(1, $r['sources'], 'one channel, one row');
        $this->assertSame(3, $r['sources'][0]['visits']);
        $this->assertSame([
            ['host' => 'l.facebook.com', 'visits' => 2],
            ['host' => 'm.facebook.com', 'visits' => 1],
        ], $r['hosts']);
    }

    // ══ WHAT THE ip_hash CAN AND CANNOT SAY ═════════════════════════════════

    /**
     * Arrivals far above networks is one machine reloading a link.
     *
     * The one direction this figure is worth reading in — and the test says so, because
     * the opposite reading is wrong: the hash is daily-salted and mobile carriers here put
     * thousands of real people behind one address, so a high network count proves nothing
     * and a low one is only suggestive.
     */
    public function test_a_source_reloaded_from_one_machine_is_visible_as_such(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->visit(['source' => 'flier', 'ip_hash' => str_repeat('b', 64)]);
        }
        $this->visit(['source' => 'newsletter', 'ip_hash' => str_repeat('c', 64)]);
        $this->visit(['source' => 'newsletter', 'ip_hash' => str_repeat('d', 64)]);

        $r = AnalyticsService::arrivals(30);
        $by = [];
        foreach ($r['sources'] as $row) $by[$row['source']] = $row;

        $this->assertSame(8, $by['flier']['visits']);
        $this->assertSame(1, $by['flier']['networks'], 'eight arrivals, one machine');
        $this->assertSame(2, $by['newsletter']['networks']);
    }

    // ══ THE DAY THE FLIER WENT OUT ══════════════════════════════════════════

    /**
     * The motivating question was "the flier went out on Tuesday — did anything come of
     * it?", and the first cut of this report could not show Tuesday.
     */
    public function test_the_series_covers_the_whole_window_with_no_gaps(): void
    {
        $this->visit(['created_at' => Carbon::now()->subDays(3)->toDateTimeString()]);
        $this->visit(['created_at' => Carbon::now()->subDays(3)->toDateTimeString()]);

        $r = AnalyticsService::arrivals(7);

        $this->assertCount(7, $r['series'], 'a quiet day is a trough, not a day the line skips');
        $this->assertSame(2, $r['series'][3]['value'], 'three days ago, fourth of seven');
        $this->assertSame(0, $r['series'][6]['value']);
        $this->assertSame(array_column($r['series'], 'label'),
                          array_column($r['converted_series'], 'label'),
                          'both lines have to be plotted against the same days');
    }

    /**
     * A conversion is plotted on the day the visitor ARRIVED.
     *
     * Plotting it on the day it happened would credit Tuesday's flier to Thursday, and the
     * two lines would stop answering the same question.
     */
    public function test_a_conversion_is_counted_on_the_day_of_the_arrival(): void
    {
        $this->visit([
            'created_at'     => Carbon::now()->subDays(4)->toDateTimeString(),
            'converted_kind' => 'ticket',
            'converted_at'   => Carbon::now()->toDateTimeString(),
        ]);

        $r = AnalyticsService::arrivals(7);

        $this->assertSame(1, $r['converted_series'][2]['value'], 'four days ago');
        $this->assertSame(0, $r['converted_series'][6]['value'], 'not today, when it converted');
        $this->assertSame(0, $r['same_visit_pct'], 'and it was not the same visit');
    }

    public function test_the_previous_window_is_compared_against(): void
    {
        $this->visit(['created_at' => Carbon::now()->subDays(2)->toDateTimeString()]);
        $this->visit(['created_at' => Carbon::now()->subDays(2)->toDateTimeString()]);
        $this->visit(['created_at' => Carbon::now()->subDays(2)->toDateTimeString()]);
        $this->visit(['created_at' => Carbon::now()->subDays(9)->toDateTimeString()]);

        $r = AnalyticsService::arrivals(7);

        $this->assertSame(3, $r['total']);
        $this->assertSame(1, $r['prev_total']);
        $this->assertSame(200, $r['growth_pct']);
    }

    /** "Up 100%" from nothing is not a growth figure. */
    public function test_growth_against_an_empty_window_is_null_rather_than_infinite(): void
    {
        $this->visit();

        $this->assertNull(AnalyticsService::arrivals(7)['growth_pct']);
    }

    // ══ THE READINGS THAT MUST NOT COLLAPSE ═════════════════════════════════

    /**
     * "Nobody converted" and "everybody came back later" are opposite readings.
     *
     * Both render as 0% unless one of them is null, and an operator seeing 0% would
     * conclude the link works and the page does not — the exact reverse of the truth.
     */
    public function test_no_conversions_is_null_rather_than_nought_per_cent(): void
    {
        $this->visit();

        $this->assertNull(AnalyticsService::arrivals(30)['same_visit_pct']);
    }

    /**
     * Every nominee has their own page, and a share-card campaign lands on hundreds.
     *
     * Ranked exactly, that is hundreds of rows of one visit each and the real answer —
     * "most arrivals land on a nominee profile" — is not a row at all, because no single
     * page holds it.
     */
    public function test_hundreds_of_profile_pages_collapse_to_one_readable_row(): void
    {
        for ($i = 0; $i < 40; $i++) $this->visit(['landing_path' => '/nominee/person-' . $i]);
        $this->visit(['landing_path' => '/events']);

        $r = AnalyticsService::arrivals(30);

        $this->assertSame('/nominee/*', $r['sections'][0]['section']);
        $this->assertSame(40, $r['sections'][0]['visits']);
        $this->assertSame('/events', $r['sections'][1]['section'],
            'a one-segment path IS the page — collapsing it would invent a section of one');

        // The exact list is kept as well: an operator naming a specific page still needs it.
        $this->assertSame(1, $r['landings'][0]['visits'], 'exact paths are still there, just flat');
    }

    /** '/' in a list of paths reads as a missing value. */
    public function test_the_home_page_is_named_rather_than_left_as_a_slash(): void
    {
        $this->assertSame('/ (home)', AnalyticsService::section('/'));
        $this->assertSame('/ (home)', AnalyticsService::section(''));
        $this->assertSame('/awards', AnalyticsService::section('/awards'));
        $this->assertSame('/nominee/*', AnalyticsService::section('/nominee/ada-obi'));
        $this->assertSame('/events/*', AnalyticsService::section('/events/gala/tickets'));
    }

    public function test_a_landing_page_with_traffic_and_no_conversions_is_visible(): void
    {
        $this->visit(['landing_path' => '/dead-end']);
        $this->visit(['landing_path' => '/dead-end']);
        $this->visit(['landing_path' => '/works', 'converted_kind' => 'vote',
                      'converted_at' => Carbon::now()->toDateTimeString()]);

        $by = [];
        foreach (AnalyticsService::arrivals(30)['landings'] as $r) $by[$r['path']] = $r;

        $this->assertSame(0, $by['/dead-end']['rate'], 'a page problem, not a channel one');
        $this->assertSame(100, $by['/works']['rate']);
    }

    /** An empty table is a shape the screen can render, not a crash. */
    public function test_an_empty_window_returns_the_full_shape(): void
    {
        $r = AnalyticsService::arrivals(30);

        foreach (['total', 'converted', 'rate', 'prev_total', 'series', 'converted_series',
                  'sources', 'campaigns', 'landings', 'sections', 'mediums', 'hosts', 'devices',
                  'countries', 'kinds', 'tracking'] as $key) {
            $this->assertArrayHasKey($key, $r, $key . ' is missing when there is nothing to report');
        }
        $this->assertSame(0, $r['total']);
        $this->assertNull($r['same_visit_pct']);
    }
}
