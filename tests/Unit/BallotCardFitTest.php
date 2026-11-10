<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The ballot card beside a nominee's page (owner, 10 Nov 2026: "improve the UX" of it).
 *
 * Measured at 1280×800 before the change: the Vote button sat below the rail's visible part,
 * and before any scroll the rail's foot ran under the floating Ask Gee button, which then
 * covered the button. And the form asked for a phone number with no reason given, before
 * the email the code is sent to. Each rule below is the fix for one of those.
 */
final class BallotCardFitTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_the_vote_button_sticks_in_view_and_the_rail_stops_above_the_floating_ui(): void
    {
        $css = self::read('public/assets/css/components/ballot.css');
        $this->assertMatchesRegularExpression('/\.vn__rail \.vb-free \[data-step="form"\] > \.vb-btn--go,[^{]*\{[^}]*position:sticky; bottom:12px/', $css);
        $this->assertStringContainsString('.vn__rail .vb{ overflow:clip }', $css, 'overflow:hidden on the card would make the sticky button stick to the card, which never scrolls');
        $this->assertStringContainsString('max-height:calc(100dvh - 200px - var(--ag-bottom-ui, 0px))', $css, 'the rail stops above Gee and any bottom bar');
    }

    public function test_email_comes_before_phone_and_each_says_why_it_is_asked(): void
    {
        $tpl = self::read('templates/pages/vote-nominee.twig');
        $this->assertLessThan(strpos($tpl, 'vbPhone"'), strpos($tpl, 'vbEmail"'), 'the code goes to the email, so it is asked first');
        $this->assertStringContainsString('Your 6-digit code is sent here.', $tpl);
        $this->assertStringContainsString('Kept with your vote so each one can be checked. Never shown or shared.', $tpl);
    }
}
