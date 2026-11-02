<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The nominee page's flow (owner, 2 Nov 2026: "improve the layout, density, flow and UX of the
 * nominee page"). What this holds, from the source because each is a question of ORDER:
 *   · below 1024 the ballot comes after the case for the person — who, where they stand, why,
 *     then the vote — and the bottom bar reaches it from anywhere;
 *   · from 1024 the ballot is its own sticky column, in view while the page is read (it sat
 *     under a 470px portrait, below the fold on a laptop);
 *   · the portrait sits with the name, in one block, so from 600 it is beside it;
 *   · spreading the word is one card with the empty state inside it, not a line and two links.
 */
final class NomineePageLayoutTest extends TestCase
{
    public function test_the_ballot_follows_the_case_on_a_phone_and_has_its_own_column_from_1024(): void
    {
        $t = ChromeRender::source('templates/pages/vote-nominee.twig');
        $why = strpos($t, 'id="vn-why"');
        $phone = strpos($t, 'vn__ballot vn__ballot--phone');
        $race = strpos($t, '<section class="vn-race"');
        $sup = strpos($t, 'id="vn-sup"');
        $this->assertNotFalse($why);
        $this->assertNotFalse($phone);
        $this->assertTrue($race < $why && $why < $phone && $phone < $sup, 'race, then why, then the ballot, then support');

        $main = strpos($t, '<div class="vn__main">');
        $rail = strpos($t, '<div class="vn__rail"><div class="vn__ballot vn__ballot--wide">');
        $this->assertNotFalse($rail, 'the wide ballot is not in its own column');
        $this->assertGreaterThan($main, $rail, 'the rail follows the main column');

        $top = strpos($t, '<div class="vn-top">');
        $this->assertTrue($top < strpos($t, '<div class="vn-photo">') && strpos($t, '<div class="vn-photo">') < strpos($t, '<header class="vn-head">'),
            'the portrait and the name are one block');
        $this->assertStringContainsString('<div class="vn-share">', $t);

        $css = ChromeRender::code('public/assets/css/components/ballot.css');
        $this->assertMatchesRegularExpression('~@media \(min-width:1024px\)\{[^@]*\.vn__rail\{ display:block; position:sticky~s', $css, 'the ballot is not sticky beside the page');
        $this->assertMatchesRegularExpression('~@media \(min-width:1024px\)\{[^@]*\.vn__ballot--phone\{ display:none \}~s', $css, 'two ballots on screen at once');
        $this->assertMatchesRegularExpression('~@media \(min-width:1024px\)\{[^@]*\.vn-bar\{ display:none \}~s', $css, 'the bottom bar must stay until the ballot has its own column');
    }
}
