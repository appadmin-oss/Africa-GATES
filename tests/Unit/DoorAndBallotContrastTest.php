<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The contrast floors on the two screens where losing them costs the most.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE TWO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The DOOR is read outdoors, at night, one-handed, on a phone turned down to save battery,
 * by somebody who has never seen it before with a queue in front of them. It is the most
 * hostile viewing condition this platform has, and the one screen where a washed-out
 * hairline is not a nitpick.
 *
 * The BALLOT is read for hours at a stretch by judges deciding an award.
 *
 * ── WHY RATIOS AND NOT COLOURS ───────────────────────────────────────────────
 *
 * Same reasoning as {@see EventFlierThemeTest}: asserting a hex freezes a palette and
 * teaches nothing, while asserting the ratio says what the value is FOR. Two of these were
 * caught by computing them rather than looking at them — `.jc-find__kb` shipped at 3.39:1
 * against a 4.5 floor, and all three door controls carried a 1.43:1 border against a 3.0
 * floor, on the box that IS the door.
 */
final class DoorAndBallotContrastTest extends TestCase
{
    /** WCAG relative luminance. */
    private function lum(string $hex): float
    {
        $h = ltrim($hex, '#');
        $out = 0.0;
        foreach ([[0, 0.2126], [2, 0.7152], [4, 0.0722]] as [$i, $w]) {
            $c = hexdec(substr($h, $i, 2)) / 255;
            $out += $w * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }
        return $out;
    }

    private function ratio(string $a, string $b): float
    {
        $la = $this->lum($a);
        $lb = $this->lum($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
    }

    /** The colour of one declaration in one rule, read out of the template itself. */
    private function pick(string $file, string $rule, string $prop): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
        // Matched on the selector with optional space before the brace: the door writes
        // `.dr__v--dup  {`, and a test that missed it would have reported "rule not found"
        // rather than a contrast failure — which is why pick() fails loudly instead of
        // returning a default.
        $at = false;
        if (preg_match('/' . preg_quote(rtrim($rule, '{'), '/') . '\s*\{/', $css, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1];
        }
        $this->assertNotFalse($at, "rule not found: {$rule} — this test is now asserting nothing");

        $body = substr($css, $at, 420);

        // Anchored to the START of a declaration — `{` or `;` then optional space. Without
        // it, `color` matches inside `border-color`, and this test measured a BORDER against
        // a background and reported the door's "already in" verdict as failing when it was
        // the assertion that was wrong. A checker that reads the wrong declaration is worse
        // than none: it fails the honest colours and passes the dishonest ones.
        $this->assertSame(1, preg_match('/[{;]\s*' . preg_quote($prop, '/') . '\s*:[^;}]*?(#[0-9a-fA-F]{6})/',
                                        $body, $m),
            "no {$prop} declaration in {$rule}");

        return strtolower($m[1]);
    }

    private function assertFloor(float $got, float $need, string $what): void
    {
        $this->assertGreaterThanOrEqual($need, $got,
            sprintf('%s is %.2f:1 and owes %.1f:1', $what, $got, $need));
    }

    // ══ the door ═════════════════════════════════════════════════════════════

    /** The door's stylesheet — the colours left the template when the door did. */
    private function doorCss(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/door.css');
    }

    /**
     * `rgba(r,g,b,a)` or `#rrggbb` as [r, g, b, a].
     *
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function parse(string $v): array
    {
        $v = trim($v);
        if (preg_match('/^#([0-9a-f]{6})$/i', $v, $m) === 1) {
            return [(float) hexdec(substr($m[1], 0, 2)), (float) hexdec(substr($m[1], 2, 2)),
                    (float) hexdec(substr($m[1], 4, 2)), 1.0];
        }
        $this->assertSame(1, preg_match('/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+))?\s*\)/i', $v, $m),
            'not a colour: ' . $v);

        return [(float) $m[1], (float) $m[2], (float) $m[3], isset($m[4]) ? (float) $m[4] : 1.0];
    }

    /**
     * A translucent colour composited onto an opaque ground.
     *
     * THE WHOLE DOOR IS BUILT THIS WAY, which is why this helper exists at all. Every
     * hairline, every label and every mark on that screen is white or near-white at some
     * alpha over a dark frame, so reading the declared value tells you nothing about what
     * anybody sees: `rgba(255,255,255,.18)` looks like a colour with plenty of contrast
     * and lands at 1.51:1.
     *
     * @return array{0:float,1:float,2:float}
     */
    private function over(array $fg, array $ground): array
    {
        $a = $fg[3];

        return [$a * $fg[0] + (1 - $a) * $ground[0],
                $a * $fg[1] + (1 - $a) * $ground[1],
                $a * $fg[2] + (1 - $a) * $ground[2]];
    }

    /** WCAG contrast between two opaque rgb triples. */
    private function rgbRatio(array $a, array $b): float
    {
        $l = function (array $c): float {
            $o = 0.0;
            foreach ([[$c[0], 0.2126], [$c[1], 0.7152], [$c[2], 0.0722]] as [$v, $w]) {
                $v /= 255;
                $o += $w * ($v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4);
            }
            return $o;
        };

        return round((max($l($a), $l($b)) + 0.05) / (min($l($a), $l($b)) + 0.05), 2);
    }

    /** The frame gradient's stops, which are every ground on this screen. */
    private const FRAME_MID = [17.0, 24.0, 24.0];   // #111818, where the slab sits
    private const FRAME_BOT = [8.0, 13.0, 14.0];    // #080d0e, where the dock and sheet sit

    /** One declaration out of one rule in door.css. */
    private function css(string $rule, string $prop): string
    {
        $css = $this->doorCss();
        $this->assertSame(1, preg_match('/' . preg_quote($rule, '/') . '\s*\{/', $css, $m, PREG_OFFSET_CAPTURE),
            "rule not found: {$rule} — this test is now asserting nothing");

        $body = substr($css, (int) $m[0][1], 600);
        $this->assertSame(1, preg_match('/[{;]\s*' . preg_quote($prop, '/') . '\s*:[^;}]*?((?:rgba?\([^)]*\))|#[0-9a-fA-F]{6})/',
                                        $body, $d),
            "no {$prop} declaration in {$rule}");

        return $d[1];
    }

    /**
     * THE ONE THAT MATTERS MOST, and it has caught this door twice.
     *
     * A text field is identified by its border alone — there is no label inside it to fall
     * back on — so 1.4.11's 3:1 applies to the boundary itself. The handoff specifies
     * `rgba(255,255,255,.18)` here, the same hairline it uses on the dock's circles, and
     * that measures 1.51:1 against the field's own fill. The circles are fine at .18
     * because a glyph identifies them; this is not.
     */
    public function test_the_typed_box_can_be_found_on_a_dimmed_screen(): void
    {
        $pad  = $this->over($this->parse('rgba(9,15,16,.86)'), self::FRAME_BOT);
        $fill = $this->over($this->parse($this->css('.dr__pad__i', 'background')), $pad);
        $edge = $this->over($this->parse($this->css('.dr__pad__i', 'border')), $pad);

        $this->assertFloor($this->rgbRatio($edge, $fill), 3.0,
            'the keypad border against the field it encloses');
        $this->assertFloor($this->rgbRatio($edge, $pad), 3.0,
            'the keypad border against the sheet behind it');
    }

    /**
     * The dock's controls, which the handoff draws with the SAME .18 hairline.
     *
     * Here that is legitimate and the reason is worth stating: a torch and a `123` keypad
     * button carry a glyph, and the glyph is what says a control is there. So the floor is
     * asserted on the glyph rather than on the ring — asserting the ring would force a
     * heavier border than the design wants for no accessibility gain.
     */
    public function test_the_other_door_controls_are_findable_too(): void
    {
        $glyph = $this->over($this->parse($this->css('.dr__circ', 'color')), self::FRAME_BOT);
        $this->assertFloor($this->rgbRatio($glyph, self::FRAME_BOT), 3.0, 'the dock glyphs');

        // The undo pill is text in a pill, so its text carries it — and it is the control
        // that reverses an admission, which is the last one that should be hard to read.
        $slab = $this->over($this->parse('rgba(9,15,16,.72)'), self::FRAME_MID);
        $undo = $this->over($this->parse($this->css('.dr__undo', 'color')), $slab);
        $this->assertFloor($this->rgbRatio($undo, $slab), 4.5, 'the take-it-back label');

        $armed = $this->over($this->parse($this->css('.dr__undo.is-armed', 'color')), $slab);
        $this->assertFloor($this->rgbRatio($armed, $slab), 4.5, 'the armed take-it-back label');
    }

    /**
     * EVERY verdict, against the one ground they all share.
     *
     * The redesign moved the colour out of the panel and into the frame's light, so the
     * slab is neutral glass and every state is drawn on the same background. That makes
     * this stronger than it was: one ground, nine states, and the kicker is 10px — which
     * is not large text by 1.4.3 and owes the full 4.5:1 rather than 3:1.
     */
    public function test_every_verdict_is_legible_against_its_own_ground(): void
    {
        $slab = $this->over($this->parse('rgba(9,15,16,.72)'), self::FRAME_MID);
        $css  = $this->doorCss();

        preg_match_all('/\.dr\[data-state="([a-z]+)"\][^{]*\{[^}]*--dr-dot:\s*([^;}]+)/', $css, $m,
                       PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(8, count($m),
            'the state palette is not where this test is looking, so it asserts nothing');

        foreach ($m as [$_, $state, $dot]) {
            $dot = trim($dot);
            // `honour` takes the event's own accent, whose floors DoorToneTest sweeps over
            // the whole hue wheel — there is no fixed colour here to measure.
            if (str_contains($dot, 'var(')) continue;

            $this->assertFloor($this->rgbRatio($this->over($this->parse($dot), $slab), $slab),
                4.5, 'the ' . $state . ' kicker and mark');
        }

        // And the three lines of type on the slab, which say what the light only hints.
        foreach (['.dr__word' => 'the verdict word',
                  '.dr__name' => 'the guest name',
                  '.dr__meta' => 'the detail line'] as $rule => $what) {
            $this->assertFloor(
                $this->rgbRatio($this->over($this->parse($this->css($rule, 'color')), $slab), $slab),
                4.5, $what);
        }
    }

    /**
     * A guest of honour is the one name drawn in the event's own colour.
     *
     * The ratio itself is swept across the whole accent space by {@see DoorToneTest}; what
     * is asserted here is that the door asks for the 4.5:1 value and not the 3:1 one. Those
     * are two different claims and the flier shipped the bug of confusing them.
     */
    public function test_the_guest_of_honour_panel_holds_on_its_dark_ground(): void
    {
        $this->assertSame('var(--dr-accent-text)', trim($this->cssRaw('.dr__name.is-honour', 'color')),
            'the honour name is painted with the 3:1 interface accent rather than the 4.5:1 text one');

        $this->assertStringContainsString('--dr-accent-soft', $this->doorCss(),
            'the sweep does not use the softened accent');
    }

    /** A declaration read verbatim, for the ones whose value is a var() reference. */
    private function cssRaw(string $rule, string $prop): string
    {
        $css = $this->doorCss();
        $this->assertSame(1, preg_match('/' . preg_quote($rule, '/') . '\s*\{([^}]*)\}/', $css, $m),
            "rule not found: {$rule}");
        $this->assertSame(1, preg_match('/(?:^|;)\s*' . preg_quote($prop, '/') . '\s*:\s*([^;]+)/', $m[1], $d),
            "no {$prop} in {$rule}");

        return $d[1];
    }

    // ══ the ballot ═══════════════════════════════════════════════════════════

    /** Small and quiet is a size decision, not a licence to drop below the text floor. */
    public function test_the_ballot_finder_text_clears_the_floor(): void
    {
        foreach ([
            '.jc-find__n{'    => 'the result count',
            '.jc-find__none{' => 'the no-matches line',
            '.jc-find__kb{'   => 'the keyboard hint',
        ] as $rule => $what) {
            $this->assertFloor($this->ratio($this->pick('judge/ballot.twig', $rule, 'color'), '#ffffff'),
                4.5, $what);
        }
    }

    /** Three near-identical buttons: both states have to be readable, not just the chosen one. */
    public function test_both_segment_states_are_readable(): void
    {
        $this->assertFloor($this->ratio($this->pick('judge/ballot.twig', '.jc-seg__b{', 'color'), '#ffffff'),
            4.5, 'an unselected filter');

        $on = $this->pick('judge/ballot.twig', '.jc-seg__b.is-on{', 'color');
        $bg = $this->pick('judge/ballot.twig', '.jc-seg__b.is-on{', 'background');
        $this->assertFloor($this->ratio($on, $bg), 4.5, 'the selected filter');
        $this->assertFloor($this->ratio($this->pick('judge/ballot.twig', '.jc-seg__b.is-on{', 'border-color'), $bg),
            3.0, "the selected filter's border");
    }
}
