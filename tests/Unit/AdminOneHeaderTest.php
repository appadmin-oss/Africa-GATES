<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The console tells you where you are exactly once.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WENT WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `admin/layout.twig` draws the page header — the eyebrow, the title, the actions and
 * the command-palette shortcut — and holds three blocks for a page to fill:
 * `topbar_eyebrow`, `topbar_title` and `topbar_actions`.
 *
 * The vendor stands screen ignored all three and rendered its own `.ad-topbar` at the
 * top of its content, so an operator arriving there was answered twice, in two type
 * treatments, one above the other. It is the third of the three competing navigation
 * systems the design audit found, and the only one visible on a single screen.
 *
 * A page that needs something the blocks cannot express is a gap in the layout, and
 * the fix for that is a block — not a second header underneath the first.
 */
final class AdminOneHeaderTest extends TestCase
{
    /** @return list<string> every admin page template, layout and partials excluded */
    private function pages(): array
    {
        $root = dirname(__DIR__, 2) . '/templates/admin/';
        $out  = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $rel = str_replace($root, '', $f->getPathname());

            // The layout OWNS the header; a partial is not a page.
            if ($rel === 'layout.twig' || str_starts_with($rel, 'partials/')) continue;
            if (str_starts_with(basename($rel), '_')) continue;

            $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    public function test_no_admin_page_draws_a_second_header(): void
    {
        $root = dirname(__DIR__, 2) . '/templates/admin/';
        $bad  = [];

        foreach ($this->pages() as $rel) {
            $src = (string) file_get_contents($root . $rel);
            // Comments are where the reason lives; a page explaining why it no longer has
            // one is not a page that has one.
            $src = (string) preg_replace('~\{#.*?#\}~s', ' ', $src);
            // The class also appears in stylesheets as a selector; what matters is markup.
            if (preg_match('~<[a-z]+[^>]*class="[^"]*\bad-topbar\b~i', $src)) $bad[] = $rel;
        }

        $this->assertSame([], $bad,
            "these draw a header the layout has already drawn:\n" . implode("\n", $bad));
    }

    /** And the stands screen fills the blocks instead, so nothing was simply deleted. */
    public function test_the_stands_screen_fills_the_layouts_blocks(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/stands/index.twig');

        foreach (['topbar_eyebrow', 'topbar_title', 'topbar_actions'] as $block) {
            $this->assertStringContainsString('{% block ' . $block . ' %}', $src,
                'the header content was removed rather than moved');
        }
        $this->assertStringContainsString('Allocation sheet (CSV)', $src,
            'the action that was in the second header did not survive the move');
    }

    /**
     * And every chart on that screen goes through the shared component.
     *
     * The page carried a second chart system — its own budget bar, its own mix bar, its
     * own floor plan, and its own copy of the eight-colour palette. None of the four had
     * the legend filtering, the keyboard readout or the table twin that `partials/viz.twig`
     * gives every chart on this platform for free.
     */
    public function test_the_stands_screen_has_no_second_chart_system(): void
    {
        $viz = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/stands/_viz.twig');

        foreach (['budget', 'mix', 'floorplan'] as $macro) {
            $this->assertStringNotContainsString('{% macro ' . $macro . '(',
                $viz, 'a bespoke ' . $macro . ' chart is back');
        }
        // `swatch` is not a chart — one pitch drawn to scale beside its name, with no
        // series in it and no Viz equivalent.
        $this->assertStringContainsString('{% macro swatch(', $viz);
    }
}
