<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Support\TimeRange;
use AfricaGates\Admin\Support\TimeSeries;
use AfricaGates\Support\DisplayTime;
use Tests\TestCase;

/**
 * The console's range control and chart (owner's screenshots, 13 Oct 2026).
 *
 * The audit log, and every list after it, reads its window here — so these are the rules
 * that make "Last 7 days" the same span everywhere and a chart that cannot draw a quiet
 * afternoon as a busy one.
 */
final class ConsoleTimeRangeTest extends TestCase
{
    public function test_a_preset_is_its_own_length_ending_now(): void
    {
        $now = 1_760_000_000;
        $r = TimeRange::fromQuery(['range' => '7d'], '30d', $now);
        $this->assertSame([$now - 7 * 86400, $now, '7d'], [$r->from, $r->to, $r->preset]);
        // An unknown preset falls back to the page's default, never to everything.
        $this->assertSame('30d', TimeRange::fromQuery(['range' => 'forever'], '30d', $now)->preset);
    }

    /** `to=2026-10-12` must include the 12th; a bare date as an upper bound is its end. */
    public function test_a_bare_date_as_the_upper_bound_covers_that_whole_day(): void
    {
        $r = TimeRange::fromQuery(['from' => '2026-10-12', 'to' => '2026-10-12']);
        $this->assertSame(86399, $r->to - $r->from);
        // And it is read in the display zone, as everything an operator types is.
        $this->assertSame('2026-10-12 00:00', TimeRange::show($r->from, 'Y-m-d H:i'));
        $this->assertSame(DisplayTime::abbr(), TimeRange::zone());
    }

    public function test_the_arrows_step_by_the_windows_own_length_and_stop_at_now(): void
    {
        $now = 1_760_000_000;
        $r = TimeRange::fromQuery(['range' => '24h'], '7d', $now);
        $p = $r->previous();
        $this->assertSame([$now - 2 * 86400, $now - 86400], [$p->from, $p->to]);
        $this->assertNull($r->next($now), 'a window ending now has nothing after it');
        $n = $p->next($now);
        $this->assertSame([$now - 86400, $now], [$n->from, $n->to]);
    }

    /** Every bucket is drawn, zero or not, and only stamps inside the window count. */
    public function test_empty_buckets_are_drawn_and_outside_stamps_are_not_counted(): void
    {
        $r = TimeRange::between(1_760_000_000, 1_760_000_000 + 86400);
        $s = TimeSeries::bars([
            date('Y-m-d H:i:s', 1_760_000_000 + 60),
            date('Y-m-d H:i:s', 1_760_000_000 + 120),
            date('Y-m-d H:i:s', 1_760_000_000 - 3600), // before the window
            null, '',
        ], $r);

        $this->assertSame(3600, $s['step'], 'a day is drawn by the hour');
        $this->assertGreaterThanOrEqual(24, count($s['bars']));
        $this->assertSame(2, $s['total']);
        $this->assertSame(2, $s['max']);
        $this->assertGreaterThan(20, count(array_filter($s['bars'], fn ($b) => $b['n'] === 0)),
            'empty hours were dropped, so the chart compresses a quiet day');
        $this->assertSame(0, $s['bars'][0]['from'] % 3600 + (int) TimeRange::show($s['bars'][0]['from'], 'Z') % 3600,
            'bars start on the hour in the display zone');
    }

    /** A month is drawn by the day, so the chart stays readable at any window. */
    public function test_the_step_grows_with_the_window(): void
    {
        $this->assertSame(86400, TimeSeries::bars([], TimeRange::between(0, 30 * 86400))['step']);
        $this->assertLessThanOrEqual(56, count(TimeSeries::bars([], TimeRange::between(0, 365 * 86400))['bars']));
    }
}
