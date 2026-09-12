<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Highlight-to-ask on a help article: select a sentence, ask about that sentence.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A general article answers the general case. The moment somebody doubts it, it is
 * always a SPECIFIC sentence — and their only route used to be: scroll to the
 * bottom, open the assistant, and retype from memory the bit they were unsure
 * about. Most people just left.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE PINS, AND HOW EACH ONE WAS FOUND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * All four failures below were found by driving a real Chromium with a real mouse
 * drag. None of them threw, none logged, and every one of them left a button on
 * screen that looked correct.
 *
 *   1. ANCHOR-ONLY CONTAINMENT. grab() tested only `anchorNode` — the end the drag
 *      STARTED at. A fast drag that began in a paragraph and overshot the end of
 *      the article was accepted, and the button then offered to ask about a
 *      passage whose tail was site furniture. The reproduction came back quoting
 *      "Nominations open — 2026 Cycle · live in …", which is the announcement bar.
 *
 *   2. NO VIEWPORT CLAMP. The pill sat at `r.top - 10` unconditionally, so a
 *      selection whose rect began above the fold placed it at `top:-95` —
 *      rendered, unclickable, pointing at nothing.
 *
 *   3. POSITION COMPUTED ONCE. It is `position:fixed` while the article scrolls
 *      underneath, so after a scroll the pill stayed put and pointed at whatever
 *      text had moved into its place.
 *
 *   4. NO CAP ON WHAT IS SENT. A 240-character cap, so the assistant receives a
 *      question and not a pasted essay.
 *
 * These are asserted structurally because the behaviour is browser behaviour — the
 * fix lives in an Alpine expression inside a Twig template, where neither PHPUnit
 * nor any linter this project runs can execute it. A structural check is weaker
 * than a browser run, and it is what stands between a future edit and shipping
 * bug 1 again silently.
 */
final class HighlightToAskTest extends TestCase
{
    private const ARTICLE = 'templates/pages/help-article.twig';

    private function body(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::ARTICLE);
    }

    /** The value of the root `x-data` attribute. */
    private function xData(): string
    {
        $body = $this->body();
        $this->assertSame(1, preg_match('~\sx-data="([^"]*)"~s', $body, $m),
            'Could not read the root x-data attribute.');
        return $m[1];
    }

    // ── 1. both ends of the selection must be inside the answer ──────────────

    public function test_both_ends_of_the_selection_are_checked(): void
    {
        $x = $this->xData();

        $this->assertMatchesRegularExpression('~inProse\(\s*s\.anchorNode\s*\)~', $x,
            'The start of the selection must be inside the answer.');
        $this->assertMatchesRegularExpression('~inProse\(\s*s\.focusNode\s*\)~', $x,
            'The END must be checked too. Checking only the anchor accepted a drag that '
            . 'started in a paragraph and overshot into the page furniture, and the button '
            . 'then offered to ask about the announcement bar.');
    }

    public function test_containment_is_limited_to_the_answer_and_its_summary(): void
    {
        $this->assertMatchesRegularExpression("~closest\('\.ha-body, \.ha-sum'\)~", $this->xData(),
            'The rail, the nav and the feedback block are not the answer.');
    }

    // ── 2 + 3. the pill has to be on screen, and stay with the text ──────────

    public function test_the_pill_is_clamped_into_the_viewport(): void
    {
        $x = $this->xData();

        $this->assertMatchesRegularExpression('~innerWidth~', $x, 'The horizontal clamp needs the viewport width.');
        $this->assertMatchesRegularExpression('~innerHeight~', $x, 'The vertical checks need the viewport height.');
        $this->assertMatchesRegularExpression('~Math\.min\(.*Math\.max\(~s', $x,
            'selX must be clamped at both edges, or the pill hangs off a narrow screen.');
        $this->assertMatchesRegularExpression('~this\.below\s*=~', $x,
            'With no room above the selection the pill must flip below it, not render off-screen.');
    }

    public function test_the_flip_has_a_style_to_go_with_it(): void
    {
        $this->assertMatchesRegularExpression('~\.ha-sel\.is-below\s*\{[^}]*translate\(-50%,\s*0\)~', $this->body(),
            'below=true must actually change the transform; otherwise the state flips and '
            . 'the pill still renders above the selection, off-screen.');
        $this->assertMatchesRegularExpression("~:class=\"below && 'is-below'\"~", $this->body(),
            'The flag has to reach the element.');
    }

    public function test_the_pill_is_repositioned_on_scroll_and_resize(): void
    {
        $body = $this->body();

        $this->assertMatchesRegularExpression('~@scroll\.window\.passive="[^"]*place\(\)~', $body,
            'It is position:fixed while the article scrolls underneath. Without this the pill '
            . 'stays behind and ends up pointing at unrelated text.');
        $this->assertMatchesRegularExpression('~@resize\.window="[^"]*place\(\)~', $body,
            'A rotation changes every coordinate it was placed with.');
    }

    public function test_it_drops_the_pill_once_the_selection_leaves_the_viewport(): void
    {
        $this->assertMatchesRegularExpression('~r\.bottom\s*<\s*0\s*\|\|\s*r\.top\s*>\s*vh~', $this->xData(),
            'A pill pointing at text nobody can see is worse than no pill — it looks like it '
            . 'belongs to whatever is on screen now.');
    }

    // ── 4. what gets sent ────────────────────────────────────────────────────

    public function test_a_trivial_selection_is_not_a_question(): void
    {
        $this->assertMatchesRegularExpression('~text\.length\s*<\s*12~', $this->xData(),
            'A stray tap selects one word, and one word is not a question.');
    }

    public function test_the_passage_is_capped(): void
    {
        $this->assertMatchesRegularExpression('~text\.slice\(0,\s*240\)~', $this->xData(),
            'The assistant should get a question, not a pasted essay.');
    }

    public function test_the_button_hands_the_passage_over_as_a_parameter_the_assistant_reads(): void
    {
        $body = $this->body();

        $this->assertMatchesRegularExpression(
            "~/support/assistant\?q=' \+ encodeURIComponent\(sel\)~", $body,
            'The selected passage has to be URL-encoded into the handover.');

        // The other half of the pair. This whole feature was dead on arrival
        // because the assistant did not read `q` — see SupportHandoffLinksTest.
        $assistant = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/support-assistant.twig');
        $this->assertMatchesRegularExpression('~\.get\([\'"]q[\'"]\)~', $assistant,
            'The assistant must read ?q= or this button goes nowhere useful.');
    }

    // ── the hazard that has already broken this file once ────────────────────

    /**
     * No double quote anywhere inside the `x-data` attribute.
     *
     * `x-data="{…}"` is a double-quoted HTML attribute. One `"` inside it — even
     * inside a JS comment — terminates the attribute, and the whole Alpine
     * component dies silently: no composer, no progress bar, no pill. It has
     * happened here once already, from a JSDoc comment.
     */
    public function test_the_x_data_attribute_contains_no_double_quote(): void
    {
        $body = $this->body();

        $this->assertSame(1, preg_match('~\sx-data="~', $body),
            'Exactly one root x-data is expected on this page.');

        // Everything from `x-data="` to the end of the file, cut at the first `"`.
        $after = substr($body, (int) strpos($body, 'x-data="') + 8);
        $attr  = substr($after, 0, (int) strpos($after, '"'));

        $this->assertStringContainsString('grab()', $attr,
            'The attribute ended before grab(), which means a double quote terminated it '
            . 'early — that silently kills the entire Alpine component.');
    }

    /** Single quotes are the only string delimiter available in there. */
    public function test_selectors_inside_x_data_use_single_quotes(): void
    {
        $x = $this->xData();
        $this->assertStringNotContainsString('"', $x);
        $this->assertStringContainsString("'.ha-body, .ha-sum'", $x);
    }
}
