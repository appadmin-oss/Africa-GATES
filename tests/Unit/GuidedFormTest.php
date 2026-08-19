<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The guided form, after the fixes.
 *
 * ── WHY THESE ARE TESTED AGAINST THE TEMPLATE ────────────────────────────────
 *
 * Each of these is a defect that was invisible from the server: a fixed height, an emoji, an
 * instruction about our storage model. Nothing rendered wrong, no exception was thrown, and no
 * existing test could have noticed any of them. They are asserted here because the only place
 * they exist is in that file, and the only way they come back is by somebody editing it.
 */
final class GuidedFormTest extends TestCase
{
    private function tpl(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/my-work.twig');
    }

    private function body(): string
    {
        // Everything below the comment header, so a defect NAMED in a comment does not read as
        // the defect still being present.
        $t = $this->tpl();
        return (string) preg_replace('~\{#.*?#\}|/\*.*?\*/~s', '', $t);
    }

    public function test_the_conversation_is_no_longer_read_through_a_letterbox(): void
    {
        // 58vh is about four exchanges on a phone. A nominee ten answers in was scrolling
        // their own conversation inside a window while the page around it stayed still.
        $this->assertStringNotContainsString('max-height:58vh', $this->body());
    }

    public function test_the_wizard_has_a_map(): void
    {
        // "Question 4 of 11" with only Back and Next means somebody who remembers a detail for
        // question 9 has to press Next five times to reach it and five times to come back — so
        // they do not, and the detail is lost.
        $b = $this->body();
        $this->assertStringContainsString('mw__map', $b);
        $this->assertStringContainsString('Jump to a question', $b);
        $this->assertStringContainsString('answered(', $b);
    }

    public function test_the_map_has_a_reactive_dependency_or_it_would_never_move(): void
    {
        // answered() reads the DOM, and Alpine cannot know a plain <textarea> changed. Without
        // touching a reactive value the map would render once and then say "unanswered" beside
        // a question somebody had just finished — worse than no map at all.
        $b = $this->body();
        $this->assertStringContainsString('typed++', $b);
        $this->assertMatchesRegularExpression('~this\.typed;~', $b);
    }

    public function test_attaching_a_file_no_longer_asks_anybody_to_save_first(): void
    {
        // An instruction about our storage model, on the screen where somebody is already
        // least sure they are doing it right.
        $this->assertStringNotContainsString('Save this page, then attach', $this->body());
        $this->assertStringContainsString('attach(w,', $this->body());
    }

    public function test_an_oversize_file_is_offered_the_route_that_works(): void
    {
        // Never a bare refusal: the size that was sent, the limit that applies, and the link
        // route — which a judge can follow just as well.
        $this->assertStringContainsString('Paste a link to it above instead', $this->body());
    }

    public function test_there_are_no_emoji_left_on_the_page(): void
    {
        // Several rendered as an empty box on the Android builds this page is most read on,
        // and an emoji is not a label — a screen reader announces "paperclip", which is not
        // what the row means.
        $b = $this->body();
        foreach (['📝', '🎤', '🎙', '🔊', '🔈', '📎', '✓', '🛍'] as $glyph) {
            $this->assertStringNotContainsString($glyph, $b, "an emoji survived: {$glyph}");
        }
    }

    public function test_the_drawn_glyphs_replaced_them(): void
    {
        $t = $this->tpl();
        foreach (['mw__clip', 'mw__spk', 'mw__micglyph'] as $cls) {
            $this->assertStringContainsString('.' . $cls . '{', $t, "no style for {$cls}");
        }
    }

    public function test_the_alpine_scope_carries_no_apostrophe(): void
    {
        // The whole object is a single-quoted HTML attribute. ONE apostrophe truncates it and
        // every binding on the page silently stops existing — the page renders, nothing
        // throws, and the questionnaire is simply inert. It happened while these fixes were
        // being written, which is why this assertion exists rather than a comment.
        $t = $this->tpl();
        $at = strpos($t, "x-data='{");
        $this->assertNotFalse($at);
        $end = strpos($t, "'", $at + 9);
        $this->assertNotFalse($end);
        $body = substr($t, $at + 9, $end - $at - 9);
        $this->assertGreaterThan(5000, strlen($body),
            'the x-data attribute is truncated — something inside it closed the quote');
        $this->assertStringContainsString('declared_name', $t);
    }
}
