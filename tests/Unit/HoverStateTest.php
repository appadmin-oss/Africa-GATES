<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Hovering must never change an element's size (owner, 22 Oct 2026: "hover problems").
 *
 * The shell's link rule was `a:hover{ color }`, which out-ranks any `.x{ color }`. To win
 * it back, 166 rules were written as `.x, .x:hover{ …the whole base… }` — and that copy
 * then out-ranked the same element's breakpoint overrides (`@media{ .x{ height:50px } }`),
 * so under a pointer a button grew 4px, a card lost 80px of width and a row shrank: the
 * page jumped wherever the mouse went. No error, and every rule read correctly alone.
 *
 * Two halves, and both are held: the shell's rule carries no more weight than a bare `a`,
 * and no stylesheet copies a base rule onto its own `:hover`.
 */
final class HoverStateTest extends TestCase
{
    public function test_the_shells_link_hover_is_no_heavier_than_a_bare_link(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/shell.css');
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $this->assertDoesNotMatchRegularExpression('~(^|[\s,}])a:hover\s*\{~', $css,
            '`a:hover` out-ranks every classed link; write `a:where(:hover)`');
        $this->assertStringContainsString('a:where(:hover)', $css);
    }

    public function test_no_rule_copies_its_base_onto_its_own_hover(): void
    {
        $root = dirname(__DIR__, 2) . '/public/assets/css';
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
            $p = $f->getPathname();
            if (!str_ends_with($p, '.css') || preg_match('~/(vendor|dist)/|\.min\.~', $p)) continue;
            $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($p));
            preg_match_all('~([^{}]+)\{~', $css, $m);
            foreach ($m[1] as $sel) {
                $parts = array_map('trim', explode(',', $sel));
                foreach ($parts as $s) {
                    if (str_ends_with($s, ':hover') && in_array(substr($s, 0, -6), $parts, true)) {
                        $bad[] = str_replace($root . '/', '', $p) . ': ' . $s;
                    }
                }
            }
        }
        $this->assertSame([], $bad, "A base rule copied onto its own :hover out-ranks its breakpoint overrides:\n  "
            . implode("\n  ", $bad));
    }
}
