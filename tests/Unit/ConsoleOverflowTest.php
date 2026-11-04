<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Nothing in the admin console scrolls sideways, and only <main> scrolls (README §10b).
 *
 * Measured across 72 console pages at 390, 834 and 1440 before this existed: tables capped
 * at the window's height gave every long list a second vertical scrollbar inside the page;
 * wide tables scrolled sideways on a tablet; the "also here" strip scrolled sideways on 25
 * pages; and a select sized to its longest option pushed two pages past the screen. These
 * hold the rules that fixed them, in the stylesheet and the script that apply them.
 */
final class ConsoleOverflowTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/console/console.css');
    }

    public function test_a_table_never_gets_its_own_vertical_scrollbar(): void
    {
        preg_match_all('~\.ad-table-wrap\s*\{([^}]*)\}~', $this->css(), $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $decl) {
            $this->assertStringNotContainsString('max-height', $decl, 'a capped table wrap is a scroller inside <main>');
            $this->assertDoesNotMatchRegularExpression('~overflow\s*:\s*auto~', $decl, 'overflow:auto scrolls both ways; the wrap may only scroll sideways');
        }
    }

    public function test_a_table_that_does_not_fit_becomes_cards_wherever_it_is(): void
    {
        $css = $this->css();
        // Keyed on the measured attribute, not on a viewport width: a tablet's wide table
        // used to stay a sideways strip because the cards only existed under 768px.
        $this->assertMatchesRegularExpression('~table\[data-stacked\] tr \{~', $css);
        $this->assertDoesNotMatchRegularExpression('~table\[data-stack\] tr \{~', $css);
        $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/console-ux.js');
        $this->assertStringContainsString("setAttribute('data-stacked'", $js);
        $this->assertMatchesRegularExpression('~t\.scrollWidth > room~', $js, 'stacking must be decided by measuring the table against its room');
    }

    public function test_the_also_here_strip_wraps_and_controls_cannot_outgrow_the_page(): void
    {
        $css = $this->css();
        preg_match('~\.cn-related \{([^}]*)\}~', $css, $m);
        $this->assertStringContainsString('flex-wrap: wrap', $m[1] ?? '');
        $this->assertStringNotContainsString('overflow-x', $m[1] ?? '');
        $this->assertMatchesRegularExpression('~\.cn-main :is\(select, [^{]*\{\s*max-width: 100%~', $css);
        $this->assertMatchesRegularExpression('~\.cn-main :is\(form, label, \.cn-head__actions > \*\)\s*\{\s*min-width: 0~', $css);
    }

    public function test_a_closed_rail_is_a_thin_icon_rail_not_nothing(): void
    {
        $css = $this->css();
        $this->assertStringNotContainsString('body[data-side="closed"] .cn-side { display: none; }', $css,
            'closing the rail hid it; it should shrink to icons');
        $this->assertMatchesRegularExpression('~body\[data-side="closed"\] \.cn-side \{ width: 52px;~', $css);
    }
}
