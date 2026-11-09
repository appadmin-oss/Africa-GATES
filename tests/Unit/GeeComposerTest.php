<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Gee's message box. It was a one-line input with a 1,500-character limit: past the first
 * forty characters a message scrolled sideways out of sight while it was written, there was
 * no new line, the limit was met only when typing stopped, the Send disc looked the same with
 * nothing to send, and the focus ring was drawn outside the box, where the full-screen sheet
 * clipped it against the screen's edges.
 *
 * Measured in a browser when this was written: 44px empty, 110px at four lines, capped at
 * 132px (five) and scrolling after, back to 44px once sent; "40 characters left" shown at
 * 960 of 1000. This file holds the wiring.
 */
final class GeeComposerTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_the_box_is_a_growing_textarea_whose_limit_is_said_before_it_bites(): void
    {
        $tpl = self::read('templates/partials/gee.twig');
        $this->assertMatchesRegularExpression('/<textarea class="gee__input" rows="1"[^>]*aria-describedby="gee-count"[^>]*data-gee-input>/', $tpl);
        $this->assertStringNotContainsString('<input class="gee__input"', $tpl, 'one line hides what is being written');
        $this->assertStringContainsString('id="gee-count" data-gee-count hidden', $tpl);

        $css = self::read('public/assets/css/components/gee.css');
        $this->assertMatchesRegularExpression('/\.gee__input\{[^}]*max-height:132px[^}]*resize:none/', $css, 'grows to five lines, then scrolls');
        $this->assertMatchesRegularExpression('/\.gee__input\{[^}]*font-size:var\(--ag-fs-16\)/', $css, 'under 16px, iOS zooms the page on focus');

        $js = self::read('public/assets/js/gee.js');
        $this->assertMatchesRegularExpression('/function syncSend\(\) \{[^}]*grow\(\); count\(\); \}/', $js, 'every change of the value resizes and recounts');
        $this->assertMatchesRegularExpression("/left <= 100/", $js);
    }

    public function test_enter_sends_shift_enter_is_a_line_and_an_input_method_is_never_interrupted(): void
    {
        $js = self::read('public/assets/js/gee.js');
        $this->assertSame(1, preg_match("/input\\.addEventListener\\('keydown', function \\(e\\) \\{(.*?)\\n  \\}\\);/s", $js, $m), 'the Enter handler');
        $this->assertStringContainsString("e.key !== 'Enter'", $m[1]);
        $this->assertStringContainsString('e.shiftKey', $m[1], 'Shift+Enter is a new line');
        $this->assertStringContainsString('e.isComposing', $m[1], 'Enter inside an input method picks a character; it must not send half a word');
        $this->assertStringContainsString('send(input.value)', $m[1]);
    }

    public function test_focus_is_drawn_inside_the_box_and_an_empty_send_looks_empty(): void
    {
        $css = self::read('public/assets/css/components/gee.css');
        $this->assertMatchesRegularExpression('/\.gee__composer:focus-within\{[^}]*border-color:var\(--ag-green\)/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.gee__composer:focus-within\{[^}]*outline-offset:\s*[1-9]/', $css,
            'a ring outside the box is clipped by the full-screen sheet');
        $this->assertMatchesRegularExpression('/\.gee__send:disabled \.gee__send-o\{[^}]*background:/', $css);
    }
}
