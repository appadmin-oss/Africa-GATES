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

    /**
     * THE ONE THAT MATTERS MOST. The typed box is the door — the camera is the addition —
     * and a text field is identified by its border alone: there is no label inside it to
     * fall back on, so 1.4.11's 3:1 applies to the border itself.
     */
    public function test_the_typed_box_can_be_found_on_a_dimmed_screen(): void
    {
        $this->assertFloor(
            $this->ratio($this->pick('pages/events/door.twig', '.dr__form input{', 'border'), '#ffffff'),
            3.0, 'the door input border against the page');
    }

    /** The other two controls beside it, which shipped with the same hairline. */
    public function test_the_other_door_controls_are_findable_too(): void
    {
        foreach (['.dr__cam button{' => 'the camera button',
                  '.dr__undo__b{'    => 'the take-it-back button'] as $rule => $what) {
            $this->assertFloor(
                $this->ratio($this->pick('pages/events/door.twig', $rule, 'border'), '#ffffff'),
                3.0, $what . ' border');
        }
    }

    /**
     * The three verdicts. These are the whole purpose of the screen and they are read at
     * arm's length — the word carries the meaning, but the word has to be legible.
     */
    public function test_every_verdict_is_legible_against_its_own_ground(): void
    {
        foreach ([
            '.dr__v--admit{'  => 'admit',
            '.dr__v--dup{'    => 'already in',
            '.dr__v--refuse{' => 'refuse',
            '.dr__v--undone{' => 'taken back',
            '.dr__v--slow{'   => 'going too fast',
            '.dr__v--held{'   => 'recorded but not checked',
        ] as $rule => $what) {
            $fg = $this->pick('pages/events/door.twig', $rule, 'color');
            $bg = $this->pick('pages/events/door.twig', $rule, 'background');
            $this->assertFloor($this->ratio($fg, $bg), 4.5, 'the ' . $what . ' verdict');
        }
    }

    /** A guest of honour is the one verdict on a dark ground, in two colours. */
    public function test_the_guest_of_honour_panel_holds_on_its_dark_ground(): void
    {
        $bg = $this->pick('pages/events/door.twig', '.dr__v--honour{', 'background');
        foreach (['.dr__v--honour b{' => 'the word', '.dr__v--honour .dr__who{' => 'the name'] as $rule => $what) {
            $this->assertFloor($this->ratio($this->pick('pages/events/door.twig', $rule, 'color'), $bg),
                4.5, $what . ' on the honour panel');
        }
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
