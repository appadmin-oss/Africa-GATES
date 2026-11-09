<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The simple panel (owner, 9 Nov 2026: "make Gee's interface this simple", with a reference
 * of a name, two icons, a greeting as a message, quick replies on the right and a two-way
 * switch at the foot).
 *
 * The switch is the part with behaviour behind it. Before it, the help desk was reachable
 * only from a page that linked into it with `data-gee-mode="support"`, so somebody who opened
 * Gee from the launcher to ask about a payment found a guide with no way across. The rest is
 * layout and is held by the screenshots taken when it was built.
 */
final class GeeSimplePanelTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_both_halves_of_gee_are_one_tap_apart_and_the_switch_says_which_is_on(): void
    {
        $tpl = self::read('templates/partials/gee.twig');
        $this->assertMatchesRegularExpression('/data-gee-to="guide" aria-pressed="true"/', $tpl);
        $this->assertMatchesRegularExpression('/data-gee-to="support" aria-pressed="false"/', $tpl);

        $js = self::read('public/assets/js/gee.js');
        $this->assertSame(1, preg_match('/function setMode\(mode\) \{(.*?)\n  \}/s', $js, $m));
        $this->assertStringContainsString("qa('[data-gee-to]')", $m[1], 'every way into a mode (a page link, the switch, a reset) updates the switch');
        $this->assertStringContainsString("aria-pressed", $m[1]);
        $this->assertMatchesRegularExpression("/qa\\('\\[data-gee-to\\]'\\)\\.forEach\\(function \\(b\\) \\{\\s*b\\.addEventListener\\('click', function \\(\\) \\{\\s*setMode\\(b\\.getAttribute\\('data-gee-to'\\)\\)/", $js);
    }

    public function test_quick_replies_are_words_and_the_answer_does_not_raise_a_phone_keyboard(): void
    {
        $tpl = self::read('templates/partials/gee.twig');
        $this->assertMatchesRegularExpression('/<button type="button" class="gee__starter" data-gee-ask="\{\{ s\.t \}\}">\{\{ s\.t \}\}<\/button>/', $tpl);
        $this->assertMatchesRegularExpression('/<button type="button" class="gee__fix [^"]*" data-gee-fix="\{\{ f\.k \}\}" data-gee-ask="\{\{ f\.t \}\}">\{\{ f\.t \}\}<\/button>/', $tpl);

        $js = self::read('public/assets/js/gee.js');
        $this->assertDoesNotMatchRegularExpression('/busy\(false\); if \(isOpen\(\)\) input\.focus\(\);/', $js,
            'focusing the box after every answer raised the on-screen keyboard over the answer');
        $this->assertMatchesRegularExpression("/if \\(isOpen\\(\\) && window\\.matchMedia\\('\\(pointer: fine\\)'\\)\\.matches\\) input\\.focus\\(\\);/", $js);
    }
}
