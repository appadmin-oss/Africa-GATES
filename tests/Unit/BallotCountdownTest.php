<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The nominee page's ballot says how long is LEFT in the last three days of voting (10 Nov
 * 2026), with the award page's own counter (awards.js: server seconds, never the phone's
 * clock — VoteCountdownTest holds that). Measured in a browser: 5 h out it read "05h 02m left"
 * and a minute later "05h 01m left"; 2 days out, days too; 10 days out, the date alone.
 */
final class BallotCountdownTest extends TestCase
{
    public function test_the_ballot_counts_down_in_the_last_three_days_with_the_shared_counter(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote-nominee.twig');
        $this->assertStringContainsString('{%- if _left > 0 and _left < 259200 %}', $tpl, 'three days, and never once closed');
        $this->assertMatchesRegularExpression('/class="vb__left" role="timer" dir="ltr" data-tick data-tick-left="\{\{ _left \}\}"/', $tpl);
        $this->assertStringContainsString('<b data-tick-h>', $tpl);
        $this->assertStringContainsString("asset('/assets/js/awards.js') }}\" data-nav=\"rerun\"", $tpl, 'the one counter, not a second copy');
    }
}
