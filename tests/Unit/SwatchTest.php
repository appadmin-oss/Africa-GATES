<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Swatch;
use Tests\TestCase;

/**
 * The colour swatch.
 *
 * A swatch is drawn by putting its value into a `style` attribute, which makes it one of only
 * two places in this codebase where an editor's typing is EXECUTED rather than displayed. So
 * the refusals below are the point of the class, not an edge case of it — and every one of
 * them must come back as "no swatch", which the callers render as the plain text label.
 */
final class SwatchTest extends TestCase
{
    // ══ 1. what it accepts ═══════════════════════════════════════════════════

    public function test_a_hex_colour_is_accepted_in_the_forms_people_type(): void
    {
        $this->assertSame(['#1B2A4A'], Swatch::colours('#1b2a4a'));
        $this->assertSame(['#1B2A4A'], Swatch::colours('  #1B2A4A '));
        $this->assertSame(['#1B2A4A'], Swatch::colours('1b2a4a'), 'a pasted hex with no hash');
        $this->assertSame(['#AABBCC'], Swatch::colours('#abc'), 'shorthand must expand');
    }

    public function test_two_colours_make_a_two_tone_swatch(): void
    {
        // Striped, panelled and trimmed goods are common, and one flat square misrepresents
        // them — which is a return, not a cosmetic complaint.
        $this->assertSame(['#1B2A4A', '#E8D8B7'], Swatch::colours('#1b2a4a/#e8d8b7'));
        $this->assertSame(['#1B2A4A', '#E8D8B7'], Swatch::colours('1b2a4a / e8d8b7'));
    }

    public function test_a_third_colour_is_dropped_rather_than_drawn(): void
    {
        // Somebody pasting a palette into the field. Rendering it would make one product's
        // buttons a different shape from every other product's.
        $this->assertSame(['#111111', '#222222'], Swatch::colours('#111/#222/#333/#444'));
    }

    // ══ 2. what it refuses ═══════════════════════════════════════════════════

    public function test_anything_that_is_not_a_colour_yields_no_swatch(): void
    {
        foreach ([
            '', '   ', 'navy', 'rgb(0,0,0)', '#12345', '#1234567', '#gggggg',
            'url(javascript:alert(1))',
            '#fff;background:url(//evil.test/x)',
            'expression(alert(1))',
            '#fff" onload="alert(1)',
            "#fff\n;color:red",
            '</style><script>alert(1)</script>',
            'var(--x)', 'currentColor', 'transparent',
        ] as $bad) {
            $this->assertSame([], Swatch::colours($bad), "accepted a non-colour: {$bad}");
            $this->assertFalse(Swatch::has($bad));
            $this->assertSame('', Swatch::css($bad), "emitted CSS for: {$bad}");
            $this->assertNull(Swatch::store($bad));
        }
        $this->assertSame([], Swatch::colours(null));
    }

    public function test_one_bad_half_of_a_pair_does_not_poison_the_other(): void
    {
        // The good half is kept and the bad half dropped, so a typo in the second colour
        // degrades to a single-colour swatch rather than to no swatch at all.
        $this->assertSame(['#1B2A4A'], Swatch::colours('#1b2a4a/not-a-colour'));
        $this->assertSame(['#E8D8B7'], Swatch::colours('nope/#e8d8b7'));
    }

    // ══ 3. what reaches the style attribute ══════════════════════════════════

    public function test_css_is_hex_or_a_fixed_gradient_and_nothing_else(): void
    {
        $this->assertSame('#1B2A4A', Swatch::css('#1b2a4a'));
        $this->assertSame(
            'linear-gradient(135deg,#1B2A4A 0 50%,#E8D8B7 50% 100%)',
            Swatch::css('#1b2a4a/#e8d8b7')
        );
    }

    public function test_a_two_tone_swatch_has_a_hard_edge_rather_than_a_blend(): void
    {
        // A navy-and-cream shirt is navy and cream. A gradient between them shows a colour the
        // garment does not have, which is the same defect as getting the hex wrong.
        $css = Swatch::css('#000000/#FFFFFF');
        $this->assertStringContainsString('#000000 0 50%', $css);
        $this->assertStringContainsString('#FFFFFF 50% 100%', $css);
    }

    public function test_no_css_output_can_carry_anything_executable(): void
    {
        // Fuzzed rather than enumerated: what matters is that NOTHING gets through, not that
        // the specific payloads above are handled.
        foreach ([
            '#fff);background-image:url(//x.test/a.png', '#fff}body{display:none',
            '#ff0000/#00ff00);color:red', '#f00 !important', "#f00\t;x:y",
        ] as $hostile) {
            $css = Swatch::css($hostile);
            $this->assertMatchesRegularExpression(
                '/^(|#[0-9A-F]{6}|linear-gradient\(135deg,#[0-9A-F]{6} 0 50%,#[0-9A-F]{6} 50% 100%\))$/',
                $css,
                "css() produced something other than a colour for: {$hostile}"
            );
        }
    }

    // ══ 4. storage ═══════════════════════════════════════════════════════════

    public function test_storage_is_normalised_and_round_trips(): void
    {
        $this->assertSame('#1B2A4A', Swatch::store('  1b2a4a '));
        $this->assertSame('#1B2A4A/#E8D8B7', Swatch::store('#1B2A4A / #e8d8b7'));
        // Whatever is stored must read back as the same colours.
        foreach (['#abc', '#1b2a4a/#e8d8b7', '#FFF'] as $in) {
            $this->assertSame(Swatch::colours($in), Swatch::colours(Swatch::store($in)));
        }
    }

    public function test_no_swatch_is_stored_as_null_and_not_as_an_empty_string(): void
    {
        // One spelling of absence. Two is how a query filtering on one of them misses half
        // the rows.
        $this->assertNull(Swatch::store(''));
        $this->assertNull(Swatch::store('   '));
        $this->assertNull(Swatch::store('nonsense'));
    }

    // ══ 5. the tick's colour ═════════════════════════════════════════════════

    public function test_light_swatches_are_recognised_so_the_tick_stays_visible(): void
    {
        // The chosen swatch is marked with a tick INSIDE it, because a ring around a 44px
        // target is indistinguishable from a hover state. A white tick on cream is invisible.
        $this->assertTrue(Swatch::isLight('#EFE6D2'), 'cream needs a dark tick');
        $this->assertTrue(Swatch::isLight('#FFFFFF'));
        $this->assertTrue(Swatch::isLight('#FFE066'));
        $this->assertFalse(Swatch::isLight('#1B2A4A'), 'navy needs a white tick');
        $this->assertFalse(Swatch::isLight('#2F6B41'));
        $this->assertFalse(Swatch::isLight('#000000'));
    }

    public function test_brightness_is_perceptual_rather_than_a_channel_average(): void
    {
        // #00FF00 and #0000FF have the same mean and nothing like the same brightness. A naive
        // average puts a white tick on bright green, where it cannot be seen.
        $this->assertTrue(Swatch::isLight('#00FF00'));
        $this->assertFalse(Swatch::isLight('#0000FF'));
    }

    public function test_a_two_tone_swatch_is_judged_on_its_first_colour(): void
    {
        // Which is where the tick's own halo comes in — see the CSS note in item.twig. This
        // only fixes the class; the halo covers the case where the tick straddles both.
        $this->assertFalse(Swatch::isLight('#1B2A4A/#FFFFFF'));
        $this->assertTrue(Swatch::isLight('#FFFFFF/#1B2A4A'));
    }

    public function test_no_swatch_reports_light_because_the_button_is_the_pages_own_surface(): void
    {
        $this->assertTrue(Swatch::isLight(''));
        $this->assertTrue(Swatch::isLight(null));
        $this->assertTrue(Swatch::isLight('not a colour'));
    }
}
