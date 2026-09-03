<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The redesigned door screen: the things a browser found and a string match would not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE FOUR IN PARTICULAR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every assertion here is a fault that was actually in the shipped file, found by opening
 * the page in Chromium and using it. None of them would fail a test that asserted the
 * template contained the right words — each is a bug of ORDER or of CASCADE, where all the
 * right pieces are present and one of them undoes another.
 *
 * They are also all silent. Not one produces a console error, a failed request, or
 * anything an operator could describe beyond "it does not work".
 */
final class DoorScreenTest extends TestCase
{
    private function door(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/door.twig');
    }

    /**
     * The template with every comment removed.
     *
     * Not tidying — a correctness fix, and one this file learned the hard way three times.
     * Each assertion below searches for the exact string its own explanation has to NAME:
     * the handler comment says `closePad()`, the layout note says `.ag-mobnav`, the sweep
     * note says `color-mix()`. Scanning raw source makes the documentation fail the
     * assertion the code satisfies, and the fix people reach for is deleting the comment.
     */
    private function code(): string
    {
        return (string) preg_replace(
            ['~\{#.*?#\}~s', '~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $this->door());
    }

    private function css(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/door.css');
    }

    // ══ 1 · the keypad has to read the code before it clears it ══════════════

    /**
     * THE WORST OF THE FOUR. Closing the sheet empties the field, so a handler that closes
     * first and reads afterwards hands `check()` an empty string, and `check()` returns
     * without asking anything.
     *
     * The keypad looked completely normal: it opened, it accepted typing, the Check button
     * pressed, the sheet closed. It simply never checked. And it is the fallback every iOS
     * browser depends on — WebKit has no `BarcodeDetector` — so on a large share of the
     * phones at a Nigerian gala the door would have had no working way in at all.
     */
    public function test_the_keypad_reads_the_code_before_it_clears_the_field(): void
    {
        $s = $this->code();

        $at = strpos($s, "el.form.addEventListener('submit'");
        $this->assertNotFalse($at, 'the keypad submit handler moved; this test must follow it');

        $body = substr($s, $at, 520);

        $read  = strpos($body, 'var code = el.input.value');
        $close = strpos($body, 'closePad()');
        $check = strpos($body, 'check(code)');

        $this->assertNotFalse($read,  'the submitted code is not captured before the sheet closes');
        $this->assertNotFalse($close);
        $this->assertNotFalse($check, 'the handler no longer checks the captured code');

        $this->assertLessThan($close, $read,
            'the field is emptied before it is read, so the keypad checks an empty string '
            . 'and silently does nothing — on the one path every iOS phone depends on');

        $this->assertStringNotContainsString('check(el.input.value)', $body,
            'the handler reads the field after closing the sheet, which has just cleared it');
    }

    // ══ 2 · a question is not an arrival ═════════════════════════════════════

    /**
     * `ask` admits nobody, so nothing may be written to the arrivals log for it.
     *
     * It was, and worse than merely wrongly: `ask` has no word in the log's vocabulary, so
     * it fell through to the default and appeared as a REFUSAL. A steward glancing at the
     * list would have seen the person standing in front of them recorded as turned away,
     * seconds before admitting them.
     */
    public function test_a_party_question_is_not_written_to_the_arrivals_log(): void
    {
        $s = $this->door();

        $this->assertMatchesRegularExpression(
            "~if \(kindOf\(v\) !== 'ask'\)\s*\{\s*\n\s*remember\(v,~", $s,
            'an unanswered party question is being logged, and as a refusal — because `ask` '
            . 'has no word in the log vocabulary and the fallback is "no"');
    }

    // ══ 3 · `hidden` has to beat `display` ═══════════════════════════════════

    /**
     * The attribute is only `display:none` in the UA stylesheet, so any author `display`
     * beats it — and the arrivals sheet, the offline strip and the party row are all
     * `display:flex`.
     *
     * Every one of them was drawn as hidden and left a full-screen layer over the dock
     * swallowing taps: the sheet was invisible and the torch, the arrivals button and the
     * keypad were all dead. Nothing about the page looks wrong when this breaks, which is
     * why it needs a rule and not a convention.
     */
    public function test_the_hidden_attribute_beats_a_display_declaration(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('~\.dr \[hidden\]\s*\{\s*display:none\s*!important~', $css,
            'a hidden panel that declares its own display is still laid out, and still '
            . 'takes the taps meant for whatever is behind it');

        // The three that actually carry the risk, so this cannot pass by the rule existing
        // while the panels stop needing it.
        foreach (['.dr__log', '.dr__held', '.dr__party'] as $sel) {
            $this->assertMatchesRegularExpression(
                '~' . preg_quote($sel, '~') . '\s*\{[^}]*display:flex~', $css,
                $sel . ' no longer declares a display — check whether the rule above is '
                . 'still load-bearing before deleting it');
        }
    }

    // ══ 4 · the door is not the site ═════════════════════════════════════════

    /**
     * §the redesign's first decision. The door used to extend `layout/gates.twig`, so a
     * steward on a phone got the mobile tab bar under their thumb — Home, Pulse, Nominate,
     * Ranks, Menu — five ways to leave the queue, and the largest targets on the screen.
     */
    public function test_the_door_carries_none_of_the_site(): void
    {
        $s = $this->code();

        $this->assertStringNotContainsString("{% extends", $s,
            'the door is rendering inside the site layout again');
        $this->assertStringContainsString('<!doctype html>', $s,
            'the door is not a standalone document');

        // One stylesheet, and it is the door's own.
        preg_match_all('~<link[^>]+rel="stylesheet"[^>]*>~', $s, $m);
        $this->assertCount(2, $m[0],
            'the door loads a stylesheet it does not need — it should be its own plus the font');
        $this->assertStringContainsString('components/door.css', implode('', $m[0]));

        foreach (['ag-mobnav', 'ag-nav', 'gee.js', 'community-fab'] as $chrome) {
            $this->assertStringNotContainsString($chrome, $s,
                'the site chrome is back on the door: ' . $chrome);
        }
    }

    /**
     * Every control a thumb has to find is at least 44px.
     *
     * The dismiss and close marks are drawn at 28 and 32 — the design's sizes, and they
     * stay — so the TARGET is grown with a pseudo-element instead. A ring resized to 44
     * would be a different design; a 28px target is a different bug.
     */
    public function test_every_control_is_a_thumb_sized_target(): void
    {
        $css = $this->css();

        foreach (['.dr__circ' => 44, '.dr__arr' => 44] as $sel => $px) {
            $this->assertMatchesRegularExpression(
                '~' . preg_quote($sel, '~') . '\s*\{[^}]*height:' . $px . 'px~', $css,
                $sel . ' is under the 44px floor');
        }

        $this->assertMatchesRegularExpression(
            '~\.dr__x::after\s*\{[^}]*width:44px;\s*height:44px~', $css,
            'the 28px dismiss mark has a 28px target — it needs a grown hit area, not a '
            . 'bigger ring');

        // The party buttons and the keypad are the two places a mis-tap costs the most.
        $this->assertMatchesRegularExpression('~\.dr__party button\s*\{[^}]*min-height:52px~', $css);
        $this->assertMatchesRegularExpression('~\.dr__pad__i\s*\{[^}]*height:56px~', $css);
        $this->assertMatchesRegularExpression('~\.dr__pad__go\s*\{[^}]*height:56px~', $css);
    }

    /** The dock clears the home indicator rather than sitting under it. */
    public function test_the_dock_keeps_out_of_the_home_indicator(): void
    {
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $this->css(),
            'the thumb zone runs under the home indicator on every modern phone');
        $this->assertStringContainsString('viewport-fit=cover', $this->door(),
            'without this the safe-area inset is always zero and the padding above does nothing');
    }
}
