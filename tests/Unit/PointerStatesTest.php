<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\PublicSurface;
use Tests\TestCase;

/**
 * The public site under a pointer (owner, 27 Oct 2026: "a lot of styling and hover issues …
 * not very sleek like the console").
 *
 * A browser sweep of sixteen public pages found most chips, tabs and link cards drew exactly
 * the same under a pointer as at rest — the ballot's own Vote button among them — and the one
 * component, a chip, drawn five ways: 40 or 44px, 14 or 14.5, weight 500, 600 or 700 at rest.
 * Two things are held here:
 *
 *   A HOVER IS NEVER A SELECTION   The selected chip is a 2px ink outline (§6.1, §18.2). A
 *                                  hover that draws it, or any ink border, tells somebody
 *                                  pointing at a filter that they have already chosen it —
 *                                  which is what two pages' chips did.
 *   ONE CHIP                       Every page chip carries the base chip's numbers: 14.5,
 *                                  regular at rest, bold only when selected, 40px on desktop.
 *
 * The sweep itself (Playwright, hover each control and diff its computed style) is how the
 * gap was found; it needs a browser and a server, so what runs on every build is the rule.
 */
final class PointerStatesTest extends TestCase
{
    /** @return list<array{string,string,string,int}> [file, selector, body, line] for every :hover rule */
    private static function hovers(): array
    {
        $out = [];
        foreach (PublicSurface::files(false) as $rel => $abs) {
            if (!str_ends_with($rel, '.css')) continue;
            $src = PublicSurface::strip((string) file_get_contents($abs), false);
            foreach (PublicSurface::rules($src, false) as [$sel, $body, $at]) {
                if (str_contains($sel, ':hover')) $out[] = [$rel, $sel, $body, PublicSurface::lineAt($src, $at)];
            }
        }
        return $out;
    }

    public function test_no_hover_on_a_chip_or_tab_draws_what_selected_looks_like(): void
    {
        $bad = [];
        foreach (self::hovers() as [$rel, $sel, $body, $line]) {
            if (!preg_match('~chip|tab\b|tab[:\[.)]|__tab~', $sel)) continue;
            foreach (PublicSurface::declarations($body) as [$prop, $val]) {
                if (in_array($prop, ['border', 'border-color', 'outline', 'box-shadow'], true) && str_contains($val, '--ag-ink)')) {
                    $bad[] = "$rel:$line  $sel { $prop: $val }";
                }
            }
        }
        $this->assertSame([], $bad, "a hover drawing the selected chip's ink");
    }

    public function test_the_base_chip_answers_a_pointer_and_never_while_selected(): void
    {
        $css = (string) file_get_contents(PublicSurface::root() . '/public/assets/css/components.css');
        $this->assertSame(1, preg_match('~@media \(hover:hover\)\{\s*\.ag-chip:is\(:hover,\.is-hover\):not\(([^)]*)\)\{([^}]*)\}~', $css, $m),
            'the chip has a pointer state, for a pointer only');
        foreach (['[aria-pressed="true"]', '[aria-current="page"]', '[disabled]'] as $state) {
            $this->assertStringContainsString($state, $m[1], "and not while $state");
        }
        $this->assertStringContainsString('background:var(--ag-bar)', $m[2]);
    }

    public function test_every_page_chip_carries_the_base_chips_numbers(): void
    {
        $root = PublicSurface::root() . '/public/assets/css/components/';
        $chips = ['help.css' => '.hc a.hc__chip', 'events-index.css' => '.ei-chip', 'discover.css' => '.dv-tab'];
        foreach ($chips as $file => $sel) {
            $src = PublicSurface::strip((string) file_get_contents($root . $file), false);
            $rest = null; $desk = false;
            foreach (PublicSurface::rules($src, false) as [$s, $body]) {
                if ($s === $sel && $rest === null) $rest = $body;
                if ($s === $sel && str_contains($body, 'height:40px')) $desk = true;
            }
            $this->assertNotNull($rest, "$sel is found in $file");
            $this->assertStringContainsString('font-size:var(--ag-fs-14-5)', $rest, "$sel is set at 14.5");
            $this->assertStringContainsString('font-weight:500', $rest, "$sel is regular at rest");
            $this->assertTrue($desk, "$sel is 40px on desktop, as the base chip is");
        }
        $this->assertStringNotContainsString('.aw-chip{ font-size', (string) file_get_contents($root . 'awards.css'), 'the awards chip does not resize the base chip');
    }

    public function test_the_ballots_vote_button_deepens_under_a_pointer(): void
    {
        $css = (string) file_get_contents(PublicSurface::root() . '/public/assets/css/components/ballot.css');
        $this->assertMatchesRegularExpression('~\.vb-btn--go:not\(:disabled\):hover\{ background:var\(--ag-green-deep\) \}~', $css);
    }
}
