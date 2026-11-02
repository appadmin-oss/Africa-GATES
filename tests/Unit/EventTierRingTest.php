<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The chosen ticket tier's moving ring (owner, 2 Nov 2026: Google's AI-mode glow, in the
 * tier's one colour rather than four). What this holds, each of which was a way to get it
 * wrong that renders without complaint:
 *   · the comet is drawn ONLY from the row's own `--tier-*` values — one colour, the one the
 *     organiser picked, never a palette of its own;
 *   · the face is `padding-box` over a `border-box` comet, so nothing reaches the words;
 *   · the halo's stacking context is the LIST: inside the row's own context a z-index:-1
 *     child paints above the row's background and the blur lay over the text (seen, fixed);
 *   · the 2px ring keeps the row's geometry — selecting a row must not move the ones below;
 *   · reduced motion, the OS's or the site's, gets the plain edge border and no halo.
 */
final class EventTierRingTest extends TestCase
{
    private function css(): string
    {
        return ChromeRender::code('public/assets/css/components/events.css');
    }

    public function test_the_chosen_tier_turns_a_one_colour_comet_round_its_rim(): void
    {
        $css = $this->css();
        $this->assertStringContainsString("@property --tier-a{ syntax:'<angle>'", $css, 'unregistered, the angle cannot interpolate and the ring stands still');
        $this->assertSame(1, preg_match('~@media \(prefers-reduced-motion:no-preference\)\{(.*?)\n\}~s', $css, $m), 'the ring runs outside the motion query');
        $block = $m[1];
        $this->assertMatchesRegularExpression('~\.ed-tier\[aria-checked="true"\]\{[^}]*padding-box, var\(--tier-comet\) border-box~s', $block);
        $this->assertMatchesRegularExpression('~animation:edTierTurn [0-9.]+s cubic-bezier\(~', $block, 'a linear turn ticks round like a clock');

        preg_match('~--tier-comet:conic-gradient\(from var\(--tier-a\),(.*?)\);~s', $block, $c);
        $this->assertNotEmpty($c, 'no comet');
        preg_match_all('~var\((--[a-z0-9-]+)\)~', $c[1], $v);
        $this->assertSame([], array_values(array_filter(array_unique($v[1]), fn($n) => !str_starts_with($n, '--tier-'))),
            'the comet is drawn in a colour that is not the tier\'s');
    }

    public function test_the_halo_sits_behind_the_face_and_selecting_moves_nothing(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('.ed-tiers{ isolation:isolate }', $css);
        $this->assertDoesNotMatchRegularExpression('~\.ed-tier\{[^}]*isolation:isolate~', $css, 'the halo would paint over the row\'s own face');
        // 1.5px border + 12/14 padding at rest; 2px + 11.5/13.5 when chosen.
        $this->assertMatchesRegularExpression('~\.ed-tier\{[^}]*padding:12px 14px[^}]*border:1\.5px solid~s', $css);
        $this->assertMatchesRegularExpression('~border:2px solid transparent; padding:11\.5px 13\.5px~', $css);
        $this->assertStringContainsString('html.ag-rm .ed-tier[aria-checked="true"]::before{ display:none }', $css);
    }
}
