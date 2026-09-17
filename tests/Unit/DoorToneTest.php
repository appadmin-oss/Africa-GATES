<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{DoorTone, EventTierPalette};
use Tests\TestCase;

/**
 * The event's accent, made readable on the door's dark frame.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS SWEEPS THE ACCENT SPACE INSTEAD OF CHECKING A FEW COLOURS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every fault `EventFlierThemeTest` has caught was on an accent nobody would have thought
 * to write down — `bold` collapsing three lines to one white on a mid-lightness saturated
 * hue, and pure `#0000FF` landing in the light band because HSL lightness says 0.50 and the
 * eye does not. So this asserts the CONTRAST FLOORS over a sampled hue wheel rather than
 * asserting colours, for the same reason and against the same class of surprise.
 *
 * The door makes that more pressing, not less. Every other surface resolves an accent for
 * PAPER; this one resolves it for a frame that runs `#2C3838` to `#080D0E`, so the whole
 * transformation runs the opposite way from the rest of the codebase and no other test
 * covers that direction.
 */
final class DoorToneTest extends TestCase
{
    /** The hue wheel at two saturations and three lightnesses, plus the greys. */
    private function sweep(): array
    {
        $out = [];
        for ($h = 0; $h < 360; $h += 15) {
            foreach ([0.35, 0.85] as $s) {
                foreach ([0.16, 0.5, 0.82] as $l) {
                    $out[] = EventTierPalette::fromHsl((float) $h, $s, $l);
                }
            }
        }

        // The ends, the primaries, and the platform's own four — the values an organiser
        // actually picks, alongside the ones that break maths.
        return array_merge($out, [
            '#000000', '#FFFFFF', '#808080', '#FF0000', '#00FF00', '#0000FF',
            '#F3B416', '#237B22', '#B4671A', '#3A5C73', '#10292C',
        ]);
    }

    // ══ the floors ═══════════════════════════════════════════════════════════

    /**
     * THE PROPERTY. Every accent an organiser can set clears its floor on the frame.
     *
     * 3:1 for the sweep line, the torch and the rule (WCAG 1.4.11) and 4.5:1 for a guest of
     * honour's name, which is 21px at weight 500 and therefore not large text.
     */
    public function test_every_accent_clears_its_floor_on_the_frame(): void
    {
        foreach ($this->sweep() as $seed) {
            $t = DoorTone::fromAccent($seed);

            $ui = EventTierPalette::contrast($t['accent'], DoorTone::FRAME_TOP);
            $this->assertGreaterThanOrEqual(DoorTone::UI_RATIO - 0.01, $ui,
                $seed . ' resolved a UI accent (' . $t['accent'] . ') that vanishes on the frame');

            $tx = EventTierPalette::contrast($t['accent_text'], DoorTone::FRAME_TOP);
            $this->assertGreaterThanOrEqual(DoorTone::TEXT_RATIO - 0.01, $tx,
                $seed . ' resolved an honour name colour (' . $t['accent_text']
                . ') a steward cannot read in the dark');
        }
    }

    /**
     * And the two are genuinely different claims, not one value published twice.
     *
     * The flier shipped the inverse of this: one value for both made a gold event's title
     * come out olive. If `accent_text` were ever just `accent`, a dark seed would light the
     * honour name at 3:1 and this is the assertion that would say so.
     */
    public function test_a_dark_accent_gets_a_lighter_colour_for_the_name_than_for_the_rule(): void
    {
        $t = DoorTone::fromAccent('#10292C');   // the ticket default: near-black teal

        $this->assertNotSame($t['accent'], $t['accent_text'],
            'the honour name is painted with the 3:1 UI value');

        $this->assertGreaterThan(
            EventTierPalette::contrast($t['accent'], DoorTone::FRAME_TOP),
            EventTierPalette::contrast($t['accent_text'], DoorTone::FRAME_TOP));
    }

    /** An accent already bright enough is left exactly as the organiser set it. */
    public function test_an_accent_that_already_clears_is_not_touched(): void
    {
        $t = DoorTone::fromAccent('#F3B416');

        $this->assertSame('#F3B416', $t['accent'], 'the platform gold was altered');
        $this->assertSame('#F3B416', $t['accent_text']);
    }

    /** The hue survives the lift — it must still be the organiser's colour. */
    public function test_lifting_keeps_the_hue(): void
    {
        foreach (['#10292C', '#237B22', '#B4671A', '#3A5C73', '#7A0F0F'] as $seed) {
            [$h] = EventTierPalette::toHsl($seed);
            [$h2] = EventTierPalette::toHsl(DoorTone::fromAccent($seed)['accent']);

            $gap = min(abs($h - $h2), 360 - abs($h - $h2));
            $this->assertLessThanOrEqual(2.0, $gap,
                $seed . ' came back a different colour rather than a lighter one');
        }
    }

    // ══ the inputs a settings box actually produces ═══════════════════════════

    /** Nothing usable falls back to the platform gold, which already clears both floors. */
    public function test_an_unusable_accent_falls_back_to_gold(): void
    {
        foreach (['', '   ', 'nonsense', '#12', 'rgb(1,2,3)', '#GGGGGG'] as $bad) {
            $this->assertSame(DoorTone::DEFAULT_ACCENT, DoorTone::fromAccent($bad)['accent'],
                'a door with an unreadable accent must look deliberate, not degraded');
        }

        $this->assertTrue(DoorTone::readable(DoorTone::DEFAULT_ACCENT, DoorTone::TEXT_RATIO),
            'the fallback itself does not clear the floor it exists to guarantee');
    }

    /** Three-character hex and a missing # are what a settings box really receives. */
    public function test_short_and_unprefixed_hex_are_understood(): void
    {
        $this->assertSame(DoorTone::fromAccent('#FFCC00'), DoorTone::fromAccent('#FC0'));
        $this->assertSame(DoorTone::fromAccent('#F3B416'), DoorTone::fromAccent('f3b416'));
    }

    /**
     * The sweep's mid stop is a resolved `rgba()`, never `color-mix()`.
     *
     * A browser that does not know `color-mix()` treats the whole gradient as invalid and
     * drops the background declaration with it — so the scan line would disappear silently
     * on exactly the older venue phones this page is written for.
     */
    public function test_the_soft_stop_is_a_plain_rgba(): void
    {
        $soft = DoorTone::fromAccent('#F3B416')['soft'];

        $this->assertMatchesRegularExpression('/^rgba\(\d+,\d+,\d+,[\d.]+\)$/', $soft);
        $this->assertStringNotContainsString('color-mix', $soft);
        $this->assertStringContainsString('243,180,22', $soft, 'the soft stop lost the hue');
    }

    /** It reads an event row, because that is how every caller has one. */
    public function test_it_resolves_from_an_event_row(): void
    {
        $this->assertSame(
            DoorTone::fromAccent('#237B22'),
            DoorTone::forEvent((object) ['ticket_accent' => '#237B22']));

        $this->assertSame(
            DoorTone::fromAccent('#237B22'),
            DoorTone::forEvent(['ticket_accent' => '#237B22']));

        // An event with no accent column at all still gets a door.
        $this->assertSame(DoorTone::DEFAULT_ACCENT, DoorTone::forEvent(null)['accent']);
    }
}
