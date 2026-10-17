<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PointsService;
use AfricaGates\Support\Spark;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The balance line on the account page: the series behind it, and its geometry.
 *
 * A chart is the one thing on a page that can be wrong without looking wrong, so these are
 * about the two decisions that decide whether it tells the truth — what the x-axis is, and
 * where the y-axis starts.
 */
class PointsSeriesTest extends TestCase
{
    private function member(): int
    {
        return (int) DB::table('gates_users')->insertGetId([
            'name' => 'Adaeze Okonkwo', 'email' => 'a-' . bin2hex(random_bytes(4)) . '@example.test',
            'phone' => '08031234567', 'points' => 0, 'status' => 'active', 'email_verified' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 years')),
        ]);
    }

    /** @param list<array{0:string,1:int}> $events [when, delta] oldest first */
    private function history(int $uid, array $events): int
    {
        $bal = 0;
        foreach ($events as [$when, $delta]) {
            $bal += $delta;
            DB::table('gates_points_ledger')->insert([
                'user_id' => $uid, 'delta' => $delta, 'reason' => $delta > 0 ? 'earn.shop_order' : 'spend.vote',
                'balance_after' => $bal, 'created_at' => date('Y-m-d H:i:s', strtotime($when)),
            ]);
        }
        DB::table('gates_users')->where('id', $uid)->update(['points' => $bal]);
        return $bal;
    }

    // ─────────────────────────── the series ─────────────────────────────────

    public function test_the_window_is_days_and_not_rows(): void
    {
        // The failure this prevents: a chart built from "the last N ledger entries" is a
        // chart of however many events happened to fit, so three quiet years and one busy
        // week come out as a chart of that week labelled as the account.
        $uid = $this->member();
        $this->history($uid, [['-3 days', 100], ['-2 days', 100], ['-1 day', 100]]);

        $s = PointsService::series($uid, 30);
        $this->assertCount(30, $s, 'thirty days is thirty points, whatever happened in them');
        $this->assertSame(date('Y-m-d', strtotime('-29 days')), $s[0]['date']);
        $this->assertSame(date('Y-m-d'), $s[29]['date']);
    }

    public function test_a_day_with_nothing_in_it_holds_yesterdays_balance(): void
    {
        // A balance is a running total. Plotting only the days that have rows spaces them
        // evenly along the axis and draws a steady climb where there was one purchase and
        // then eleven months of nothing.
        $uid = $this->member();
        $this->history($uid, [['-20 days', 500]]);

        $s   = PointsService::series($uid, 30);
        $by  = array_column($s, 'balance', 'date');
        $this->assertSame(0,   $by[date('Y-m-d', strtotime('-25 days'))], 'before it arrived');
        $this->assertSame(500, $by[date('Y-m-d', strtotime('-20 days'))], 'the day it arrived');
        $this->assertSame(500, $by[date('Y-m-d', strtotime('-5 days'))],  'and every quiet day after');
        $this->assertSame(500, $by[date('Y-m-d')]);
    }

    public function test_the_opening_balance_accounts_for_history_before_the_window(): void
    {
        // The window opens mid-story. Starting it at zero would draw a member who has been
        // here two years as somebody who joined ninety days ago.
        $uid = $this->member();
        $this->history($uid, [['-200 days', 900], ['-10 days', 100]]);

        $s = PointsService::series($uid, 30);
        $this->assertSame(900,  $s[0]['balance'], 'what they already had when the window opened');
        $this->assertSame(1000, $s[count($s) - 1]['balance']);
    }

    public function test_the_days_deltas_are_summed_not_overwritten(): void
    {
        $uid = $this->member();
        $this->history($uid, [['-5 days 09:00', 200], ['-5 days 17:00', 300]]);

        $s  = PointsService::series($uid, 30);
        $by = array_column($s, 'delta', 'date');
        $this->assertSame(500, $by[date('Y-m-d', strtotime('-5 days'))], 'two entries on one day is one day');
    }

    public function test_an_account_with_nothing_gets_no_chart_at_all(): void
    {
        // Not a flat line at zero — a chart of nothing is a stat tile's job, and the page
        // shows the tile alone. Distinguishing these is what stops a new member's first
        // screen being a graph of their absence.
        $this->assertSame([], PointsService::series($this->member(), 30));
    }

    public function test_a_quiet_window_over_a_real_balance_is_still_a_flat_line(): void
    {
        $uid = $this->member();
        $this->history($uid, [['-300 days', 750]]);

        $s = PointsService::series($uid, 30);
        $this->assertNotSame([], $s);
        foreach ($s as $p) $this->assertSame(750, $p['balance']);
    }

    // ─────────────────────────── the geometry ───────────────────────────────

    public function test_the_baseline_is_zero_so_the_area_cannot_lie(): void
    {
        // 1,010 → 1,030 scaled from the series minimum looks like a tripling. It is a
        // filled area, and the area is what a reader takes the magnitude from.
        $series = [
            ['date' => '2026-01-01', 'balance' => 1010, 'delta' => 0],
            ['date' => '2026-01-02', 'balance' => 1030, 'delta' => 20],
        ];
        $c = Spark::chart($series, 100.0, 100.0);

        $this->assertTrue($c['ok']);
        // Both points sit near the top of the plot because the scale runs from zero — a
        // truncated axis would put one at the floor and the other at the ceiling.
        $this->assertLessThan(35, $c['points'][0]['y'], 'y grows downwards; a high value is a small y');
        $this->assertLessThan(35, $c['points'][1]['y']);
        $this->assertLessThan(30, abs($c['points'][0]['y'] - $c['points'][1]['y']),
            'a 2% rise must not render as a third of the plot height');
        $this->assertSame(0, $c['grid'][2]['value'], 'the bottom gridline is zero');
    }

    public function test_the_top_gridline_is_a_round_number(): void
    {
        $series = [];
        foreach ([100, 900, 1437] as $i => $v) {
            $series[] = ['date' => '2026-01-0' . ($i + 1), 'balance' => $v, 'delta' => 0];
        }
        $c = Spark::chart($series);
        $this->assertSame(1500, $c['max'], 'an axis whose labels are arbitrary is an axis nobody reads');
        $this->assertSame('1.5k', $c['grid'][0]['label']);
    }

    public function test_the_area_closes_to_the_baseline(): void
    {
        $c = Spark::chart([
            ['date' => '2026-01-01', 'balance' => 10, 'delta' => 0],
            ['date' => '2026-01-02', 'balance' => 20, 'delta' => 10],
        ], 100.0, 50.0);
        $this->assertStringEndsWith('Z', $c['area'], 'an open path is a stroke, not an area');
        $this->assertStringContainsString('L100 50', $c['area'], 'down to the floor at the right');
        $this->assertStringContainsString('L0 50', $c['area'], 'and back along it');
    }

    public function test_fewer_than_two_points_is_not_a_chart(): void
    {
        foreach ([[], [['date' => '2026-01-01', 'balance' => 5, 'delta' => 5]]] as $thin) {
            $this->assertFalse(Spark::chart($thin)['ok'], 'one point is a dot, and a dot is a number');
        }
    }

    public function test_a_flat_series_at_zero_does_not_divide_by_zero(): void
    {
        $c = Spark::chart([
            ['date' => '2026-01-01', 'balance' => 0, 'delta' => 0],
            ['date' => '2026-01-02', 'balance' => 0, 'delta' => 0],
        ], 100.0, 50.0);
        $this->assertTrue($c['ok']);
        $this->assertSame(10, $c['max'], 'a scale still needs a top even when nothing reaches it');
        foreach ($c['points'] as $p) $this->assertSame(50.0, $p['y']);
    }

    public function test_the_change_reported_is_the_change_across_the_window(): void
    {
        $c = Spark::chart([
            ['date' => '2026-01-01', 'balance' => 900, 'delta' => 0],
            ['date' => '2026-01-02', 'balance' => 400, 'delta' => -500],
        ]);
        $this->assertSame(-500, $c['change']);
    }
}
