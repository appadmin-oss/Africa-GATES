<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two faults of the volunteer / event-lead shell on a phone, both silent and both measured.
 *
 * 1. The page scroller (`.sh7-main`) must be a containing block. Visually hidden labels and the
 *    phone's hidden page title are `position:absolute`; with no positioned ancestor they were
 *    placed against the DOCUMENT, past the bottom of a 100dvh shell, so the whole document
 *    grew a second scroll and dragged the header and tabs off screen.
 * 2. The phone tab bar is fixed and 64px tall, so a page's bottom padding must clear it. A later
 *    phone rule set it to 32px and every page's last button sat half under the bar, with the
 *    scroller unable to go further.
 *
 * Read from the stylesheet by CASCADE ORDER: the last `.sh7-page` padding inside the phone
 * container query is the one that wins, so it is the one asked about.
 */
final class ShellPhoneFitTest extends TestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/console/shell7.css');
    }

    public function test_the_scroller_contains_what_is_absolutely_positioned_inside_it(): void
    {
        $this->assertMatchesRegularExpression('/\.sh7-main\s*\{[^}]*position:\s*relative/', self::css(),
            'without it, a hidden label lands on the document and the shell scrolls twice');
    }

    public function test_the_last_phone_page_padding_clears_the_fixed_tab_bar(): void
    {
        $css = self::css();
        $this->assertMatchesRegularExpression('/\.sh7-phonetabs\s*\{[^}]*position:\s*fixed[^}]*height:\s*64px/', $css, 'the bar this rule is about');
        $at = strpos($css, '@container shell (max-width: 599px)');
        $this->assertNotFalse($at);
        preg_match_all('/\.sh7-page\s*\{[^}]*padding:\s*([^;}]+)/', substr($css, $at), $m);
        $this->assertNotEmpty($m[1], 'no phone padding found: the sweep is not reading the file');
        $last = trim((string) end($m[1]));
        $parts = preg_split('/\s+(?![^(]*\))/', $last) ?: [];
        $bottom = $parts[2] ?? $parts[0];
        $this->assertStringContainsString('64px', $bottom, "the page's bottom padding ($bottom) does not clear the 64px tab bar");
    }
}
