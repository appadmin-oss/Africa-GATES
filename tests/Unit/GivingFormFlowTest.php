<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * THE TWO-STEP GIVING FORM, AND THREE WAYS IT FAILED WITHOUT ANYTHING GOING WRONG.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SOURCE SWEEP AND NOT A RENDER TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * All three faults live in Alpine attributes and a component method — client behaviour on
 * a page whose server-rendered HTML is identical either way. A render test would pass over
 * every one of them, and there is no headless browser on this host and cannot be
 * ({@see \AfricaGates\Services\FlierRaster} for why).
 *
 * So the assertions are structural, and they are worth making because each fault is
 * invisible from the server, invisible in the console, and invisible to anybody testing
 * with a mouse:
 *
 *   1. The confirmation step said "One-time donation" unconditionally — so a donor who
 *      chose "Every month" one step earlier was told, on the step where they confirm what
 *      they are agreeing to, that it was a single gift. Immediately before a standing
 *      order was set up.
 *   2. Changing step left focus on an element that had just become `display:none`, which
 *      drops focus to the document. The flow works perfectly with a mouse and strands a
 *      keyboard or screen-reader user at the top of the page with no announcement — the
 *      split an accessibility pass is least likely to catch, because every individual
 *      control is fine.
 *   3. The voluntary gift to Africa GATES was in the button total and nowhere in the
 *      recap, so the first place a donor saw the real figure was the gateway's own screen.
 */
final class GivingFormFlowTest extends TestCase
{
    /** The template with Twig comments stripped — a comment reaches no browser. */
    private static function page(): string
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/donate.twig');

        return (string) preg_replace('~\{#.*?#\}~s', ' ', $src);
    }

    // ══ 1 · the recap tells the truth ════════════════════════════════════════

    /**
     * A DONOR CONFIRMING A MONTHLY GIFT IS NOT TOLD IT IS A ONE-OFF.
     *
     * The single worst copy fault available on this page: the platform had listened to the
     * choice and then told the donor it had not, on the step where they agree.
     */
    public function test_the_recap_states_the_frequency_that_was_chosen(): void
    {
        $src = self::page();

        $this->assertStringContainsString(
            "x-text=\"monthly ? 'Every month' : 'One-time donation'\"", $src,
            'the confirmation step names a frequency without reading the one the donor '
            . 'picked, so a monthly gift is confirmed as a single one');

        // And the old literal is gone rather than merely joined by the new one.
        $this->assertStringNotContainsString('<span>One-time donation</span>', $src,
            'the unconditional label is still rendered somewhere');
    }

    /** The tip is in the recap, not only in the button. */
    public function test_the_recap_carries_the_gift_to_the_platform_and_the_total(): void
    {
        $src = self::page();

        $this->assertStringContainsString('Gift to Africa GATES', $src,
            'the voluntary gift appears in the button total and nowhere a donor can check '
            . 'it, so the first sight of the real figure is the gateway');
        $this->assertStringContainsString('Total today', $src);
        $this->assertStringContainsString('x-text="ngn(value + tipAmount)"', $src);
    }

    // ══ 2 · the flow is completable without a mouse ══════════════════════════

    /**
     * CHANGING STEP MOVES FOCUS.
     *
     * `x-show` sets `display:none`, and a focused element that becomes `display:none` has
     * its focus dropped to the document. Advancing therefore left a keyboard user at the
     * top of the page with nothing said and no obvious way forward — while every control
     * on the page passed an audit individually.
     */
    public function test_advancing_and_returning_both_move_focus(): void
    {
        $src = self::page();

        // Through methods, not inline `step='details'`: the move has to happen everywhere
        // the step changes, and an inline assignment is the version somebody copies to a
        // third button without the focus call.
        $this->assertStringContainsString('@click="advance(', $src,
            'the step is advanced by assigning to `step` inline, so the focus move is not '
            . 'carried with it');
        $this->assertStringContainsString('@click="back()"', $src);

        $this->assertStringNotContainsString("step='details'\"", $src,
            'an inline step change bypasses the focus move');

        // The targets have to be focusable at all — a heading is not, by default.
        $this->assertStringContainsString('id="dnStep1" tabindex="-1"', $src);
        $this->assertStringContainsString('id="dnStep2" tabindex="-1"', $src);
    }

    /**
     * AND THE MOVE WAITS FOR THE ELEMENT TO EXIST.
     *
     * The target is still `display:none` at the moment of the click, and `focus()` on a
     * hidden element does nothing at all — silently. Without `$nextTick` this is a fix that
     * reads correct in the diff and changes nothing in the browser.
     */
    public function test_the_focus_move_waits_for_the_step_to_render(): void
    {
        $src = self::page();

        // ── BOUNDED, OR IT MATCHES THE OTHER METHOD'S TICK ──────────────────
        //
        // `.*?` under `/s` is non-greedy but unbounded, so `advance(min){ … $nextTick`
        // happily spans the whole of `advance()` and lands on `back()`'s tick instead —
        // and the assertion then passes with `advance()` having no tick at all. Caught by
        // mutating exactly that and watching this test stay green.
        //
        // The lookahead stops the match at the start of the other method.
        $this->assertMatchesRegularExpression(
            '~advance\(min\)\s*\{(?:(?!back\(\)).)*?\$nextTick~s', $src,
            'focus() on a display:none element does nothing, so the move has to wait a tick');
        $this->assertMatchesRegularExpression(
            '~back\(\)\s*\{(?:(?!advance\().)*?\$nextTick~s', $src);
    }

    /**
     * A FOCUS RING ON A HEADING NOBODY CLICKED IS A RING NOBODY WANTED.
     *
     * `tabindex="-1"` makes the heading focusable so the script can reach it; without the
     * `:focus-visible` split, every mouse user who advances sees an outline appear around a
     * heading for no reason they can explain.
     */
    public function test_the_focus_target_only_shows_a_ring_to_the_keyboard(): void
    {
        $src = self::page();

        $this->assertStringContainsString('h2[tabindex="-1"]:focus{ outline:none; }', $src);
        $this->assertStringContainsString('h2[tabindex="-1"]:focus-visible{', $src);
    }

    // ══ 3 · and the page still holds its own rules ═══════════════════════════

    /**
     * EVERY TOUCH TARGET CLEARS 44px.
     *
     * Checked on the declarations rather than by rendering, because the failure is a number
     * in a stylesheet and there is no browser here to measure one.
     */
    public function test_the_interactive_controls_declare_a_reachable_height(): void
    {
        $src = self::page();

        foreach (['.dn-cta', '.dn-amt', '.dn-freq__b'] as $sel) {
            $this->assertMatchesRegularExpression(
                '~' . preg_quote($sel, '~') . '\{[^}]*min-height:(4[4-9]|[5-9]\d|\d{3})px~s',
                $src,
                $sel . ' is a control somebody has to hit on a phone and declares no '
                . 'reachable height');
        }
    }

    /**
     * AND THE MONTHLY OPTION IS NEVER PRE-SELECTED.
     *
     * A page that arrives with "every month" already chosen is asking somebody to notice
     * and undo it — the same fault as a pre-ticked box, on the one control here that sets
     * up a recurring charge against somebody's card.
     */
    public function test_a_standing_order_is_never_the_default(): void
    {
        $src = self::page();

        $this->assertMatchesRegularExpression('~monthly:\s*false~', $src,
            'the form arrives with a monthly gift already chosen');
        $this->assertMatchesRegularExpression('~tipPct:\s*0~', $src,
            'the form arrives with a gift to the platform already chosen');
    }
}
