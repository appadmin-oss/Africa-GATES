<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\DisplayThreshold;
use Tests\TestCase;

/**
 * The low-data table (LOW-DATA-RULES.md §3). Its first readers arrive with the giving and
 * Home rebuilds; until then this holds the contract they will rely on.
 */
final class DisplayThresholdTest extends TestCase
{
    public function test_the_handoffs_minimums_and_the_boundary(): void
    {
        $this->assertSame(25, DisplayThreshold::min('gifts_count'));
        $this->assertSame(50, DisplayThreshold::min('votes_public_total'));
        $this->assertTrue(DisplayThreshold::met('gifts_count', 25), 'the minimum itself is enough');
        $this->assertFalse(DisplayThreshold::met('gifts_count', 24));
    }

    /** A typo that answered 0 would show every block at every size. */
    public function test_an_unknown_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DisplayThreshold::min('gift_count');
    }

    public function test_large_figures_are_approximated(): void
    {
        $this->assertSame('999', DisplayThreshold::approx(999));
        $this->assertSame('1.2K', DisplayThreshold::approx(1240));
        $this->assertSame('1K', DisplayThreshold::approx(1000));
        $this->assertSame('1.25M', DisplayThreshold::approx(1_250_000));
    }
}
