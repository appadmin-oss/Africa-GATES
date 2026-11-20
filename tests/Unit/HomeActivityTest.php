<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\HomeBoard;
use AfricaGates\Admin\Support\TimeRange;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Home's activity card (redesigned 13 Oct 2026): one metric over the chosen window,
 * compared with the window before it.
 */
final class HomeActivityTest extends TestCase
{
    private function vote(int $at): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => bin2hex(random_bytes(8)),
            'voted_at' => date('Y-m-d H:i:s', $at),
        ]);
    }

    public function test_the_window_and_the_one_before_it_are_counted_from_the_same_column(): void
    {
        $now = time();
        foreach ([3600, 7200, 10800] as $ago) $this->vote($now - $ago);          // this day: 3
        foreach ([86400 + 3600, 86400 + 7200] as $ago) $this->vote($now - $ago); // the day before: 2
        $this->vote($now - 5 * 86400);                                           // neither

        $a = HomeBoard::activity(TimeRange::fromQuery(['range' => '24h'], '7d', $now), 'votes');
        $this->assertSame(3, $a['total']);
        $this->assertSame(2, $a['previous']);
        $this->assertSame(50, $a['delta'], '3 against 2 is half again');
        $this->assertSame(3, $a['series']['total'], 'the chart and the headline disagree');
        $this->assertFalse($a['capped']);
    }

    /** Against nothing a change is not a percentage; the page says "none before". */
    public function test_a_change_against_an_empty_window_has_no_percentage(): void
    {
        $this->vote(time() - 600);
        $a = HomeBoard::activity(TimeRange::fromQuery(['range' => '24h']), 'votes');
        $this->assertSame(0, $a['previous']);
        $this->assertNull($a['delta']);
    }

    public function test_an_unknown_metric_falls_back_to_votes_and_a_capped_window_says_so(): void
    {
        foreach ([60, 120, 180] as $ago) $this->vote(time() - $ago);
        $a = HomeBoard::activity(TimeRange::fromQuery(['range' => '24h']), 'drop table', 2);
        $this->assertSame('votes', $a['metric']);
        $this->assertSame(3, $a['total'], 'the total is exact even when the chart is capped');
        $this->assertTrue($a['capped']);
    }
}
