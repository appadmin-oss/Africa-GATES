<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Support\Html;

/**
 * Guards the stored-XSS fix: admin-authored rich text is rendered through
 * Html::sanitize (the `sanitize_html` Twig filter) instead of `|raw`.
 */
class HtmlSanitizeTest extends TestCase
{
    public function test_strips_script_and_event_handlers(): void
    {
        $out = Html::sanitize('<p onclick="evil()">Hi<script>alert(1)</script><img src="x" onerror="steal()"></p>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringContainsString('Hi', $out);
    }

    public function test_neutralises_javascript_urls_but_keeps_http(): void
    {
        $this->assertStringNotContainsString('javascript:', Html::sanitize('<a href="javascript:alert(1)">x</a>'));
        $this->assertStringContainsString('https://afrovanguard.org.ng', Html::sanitize('<a href="https://afrovanguard.org.ng">x</a>'));
    }

    public function test_preserves_allowed_rich_text(): void
    {
        $out = Html::sanitize('<p>Hello <strong>world</strong> and <em>peace</em>.</p><ul><li>one</li></ul>');
        $this->assertStringContainsString('<strong>world</strong>', $out);
        $this->assertStringContainsString('<li>one</li>', $out);
    }

    public function test_unwraps_unknown_tags_keeping_text(): void
    {
        $out = Html::sanitize('<div><marquee>keep this</marquee></div>');
        $this->assertStringContainsString('keep this', $out);
        $this->assertStringNotContainsString('<marquee', $out);
    }

    public function test_empty_input_is_safe(): void
    {
        $this->assertSame('', Html::sanitize(null));
        $this->assertSame('', Html::sanitize('   '));
    }
}
