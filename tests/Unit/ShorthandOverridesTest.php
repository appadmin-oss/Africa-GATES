<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A longhand killed by a later shorthand on the same selector.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS PREVENTS, AND WHY NEITHER RULE LOOKS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `margin-top: auto` was declared on `.nf__bar` beside `.nf__form`, at the top of
 * `components/nominate.css`. A hundred lines further down, the bar's own block —
 * the SAME selector, the SAME specificity, later in the file — set
 * `margin: var(--ag-sp-16) calc(var(--ag-gutter) * -1) 0`. The shorthand writes all
 * four edges, so the computed `margin-top` was `16px` and the `auto` never applied.
 *
 * Nothing about that is visible. Both rules are valid, both are about the same box,
 * and each reads correctly on its own — one says "push me to the bottom", the other
 * says "here is my geometry". There is no warning, no invalid property, no
 * unterminated anything. The only way to see it is to compute the style in a browser
 * and notice a value nobody wrote.
 *
 * ── AND IT SHOWED ON ONE SCREEN IN FOUR ─────────────────────────────────────
 *
 * The nomination flow has four steps and three of them overflow the viewport, where
 * `position: sticky` pins the bar to the floor and the auto margin is doing nothing
 * anyway. Evidence is the one short step. There the action bar floated at 559–644 of
 * an 844px viewport with two hundred pixels of empty ground beneath it — measured at
 * 390, 768 and 1440, where the gaps were 200, 377 and 261px.
 *
 * So the fault presented as "the Evidence step is broken", which is the wrong screen,
 * the wrong file and the wrong half of the rule. That shape — a cascade fault wearing
 * the costume of a layout bug on one page — is what makes it worth a sweep rather
 * than a memory.
 *
 * ── WHAT IT FOUND ON ITS FIRST RUN ──────────────────────────────────────────
 *
 * Three more, all in the legacy sheets and all of the same shape: a selector declared
 * in full two or three times as design generations stacked up, with a longhand in an
 * early copy that a later copy's shorthand quietly reset. `.ad-table-wrap`'s
 * `overflow-x`, `.face-tile`'s `transition-delay`, and three separate
 * `.p-hero h1 { margin-bottom }` declarations under a final `margin: 0 auto 1.25rem`.
 * Every one was inert — the computed value on `/opportunities` was `0px 88px 20px`
 * before the deletion and `0px 88px 20px` after — so they were removed rather than
 * excused. There is no exclusion list here on purpose: a sweep carrying an
 * enumeration of the instances that existed the day it was written is a sweep that
 * goes quiet exactly where the debt is.
 *
 * ── WHAT IT DELIBERATELY DOES NOT CLAIM ─────────────────────────────────────
 *
 * It compares within ONE FILE and within one at-rule context. A `.x` inside
 * `@media (min-width:768px)` overriding a `.x` outside it is the cascade being used
 * correctly, and so is a page sheet overriding a component sheet — both are the
 * point of having layers. It also cannot see specificity: `.a .x` and `.x` are
 * different selectors to it and are not compared, which is right, because the more
 * specific one wins wherever it is written.
 */
final class ShorthandOverridesTest extends TestCase
{
    /**
     * Every shorthand whose expansion can silently erase a longhand somebody wrote.
     *
     * Keyed by the shorthand; the values are the longhands it writes. Physical and
     * logical properties both, because `margin` resets `margin-block-start` exactly
     * as it resets `margin-top` and a sheet may spell either.
     */
    private const FAMILIES = [
        'margin' => [
            'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'margin-block', 'margin-inline', 'margin-block-start', 'margin-block-end',
            'margin-inline-start', 'margin-inline-end',
        ],
        'padding' => [
            'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'padding-block', 'padding-inline', 'padding-block-start', 'padding-block-end',
            'padding-inline-start', 'padding-inline-end',
        ],
        'inset'         => ['top', 'right', 'bottom', 'left', 'inset-block', 'inset-inline'],
        'border-radius' => [
            'border-top-left-radius', 'border-top-right-radius',
            'border-bottom-right-radius', 'border-bottom-left-radius',
            'border-start-start-radius', 'border-start-end-radius',
            'border-end-start-radius', 'border-end-end-radius',
        ],
        'border' => [
            'border-width', 'border-style', 'border-color',
            'border-top', 'border-right', 'border-bottom', 'border-left',
        ],
        'background' => [
            'background-color', 'background-image', 'background-position',
            'background-size', 'background-repeat', 'background-attachment',
            'background-clip', 'background-origin',
        ],
        // The reading size lives in the `font` shorthand across most of this tree —
        // `font:600 11px/1 var(--ag-font-mono)` — so a `font-size` set earlier on the
        // same selector is exactly the kind of thing that disappears here.
        'font' => [
            'font-size', 'font-weight', 'font-family', 'font-style',
            'font-variant', 'line-height', 'font-stretch',
        ],
        'flex'          => ['flex-grow', 'flex-shrink', 'flex-basis'],
        'gap'           => ['row-gap', 'column-gap'],
        'overflow'      => ['overflow-x', 'overflow-y'],
        'outline'       => ['outline-width', 'outline-style', 'outline-color'],
        'transition'    => [
            'transition-property', 'transition-duration',
            'transition-timing-function', 'transition-delay',
        ],
        'animation' => [
            'animation-name', 'animation-duration', 'animation-timing-function',
            'animation-delay', 'animation-iteration-count', 'animation-direction',
            'animation-fill-mode',
        ],
        'list-style'    => ['list-style-type', 'list-style-position', 'list-style-image'],
        'grid-area'     => [
            'grid-row', 'grid-column',
            'grid-row-start', 'grid-row-end', 'grid-column-start', 'grid-column-end',
        ],
        'place-items'   => ['align-items', 'justify-items'],
        'place-content' => ['align-content', 'justify-content'],
        'place-self'    => ['align-self', 'justify-self'],
        'columns'       => ['column-width', 'column-count'],
    ];

    public function test_no_longhand_is_erased_by_a_later_shorthand_on_the_same_selector(): void
    {
        $bad = [];

        foreach ($this->sheets() as $rel => $path) {
            // live[context][selector][property] = was it !important
            $live = [];

            foreach (self::rules((string) file_get_contents($path)) as [$ctx, $sel, $decls]) {
                foreach ($decls as [$prop, $important]) {
                    foreach (self::FAMILIES[$prop] ?? [] as $long) {
                        if (!array_key_exists($long, $live[$ctx][$sel] ?? [])) continue;
                        // `!important` outranks a later plain shorthand, so that
                        // longhand is still the one that applies.
                        if ($live[$ctx][$sel][$long] && !$important) continue;

                        $where = $ctx !== '' ? " inside `$ctx`" : '';
                        $bad[] = "$rel: `$sel`$where declares `$long`, and a later "
                               . "`$prop` on the same selector writes over it — the "
                               . '`' . $long . '` never applies.';
                    }
                    $live[$ctx][$sel][$prop] = $important;
                }
            }
        }

        $bad = array_values(array_unique($bad));
        $this->assertSame([], $bad, "\n" . implode("\n", $bad) . "\n");
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The parser, and the three things it has to get right
    // ══════════════════════════════════════════════════════════════════════════

    /** @return array<string,string> relative path => absolute path */
    private function sheets(): array
    {
        $root = realpath(__DIR__ . '/../../public/assets/css');
        $out  = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'css') continue;
            // Vendored code is somebody else's authoring and is not ours to correct.
            if (str_contains($f->getPathname(), '/vendor/')) continue;
            $out[ltrim(str_replace($root, '', $f->getPathname()), '/')] = $f->getPathname();
        }

        ksort($out);
        return $out;
    }

    /**
     * One sheet into ordered rules: [at-rule context, selector, [[property, !important], …]].
     *
     * Three things this has to do that a regex over `\{([^}]*)\}` does not:
     *
     * · STRIP COMMENTS FIRST. A `/* … *\/` above a rule becomes part of that rule's
     *   selector text otherwise, so every documented rule gets its own unique
     *   selector and is never compared with anything — the sweep goes silent in
     *   exactly the files somebody took the trouble to explain.
     *
     * · READ ONLY THE INNERMOST BLOCK. An at-rule opens a block, so a naive pass
     *   reads `@media (min-width:768px)` as a selector and `.nf{max-width:720px}` as
     *   its declaration text. The context is carried instead, so the same selector
     *   inside and outside a media query is two separate groups — which it is.
     *
     * · KEEP DECLARATION ORDER. `.x{ margin:0; margin-top:4px }` is correct authoring
     *   and `.x{ margin-top:4px; margin:0 }` is the same fault one line apart. A map
     *   of property => value loses the difference and reports the first as a finding
     *   while excusing the second.
     *
     * @return list<array{0:string,1:string,2:list<array{0:string,1:bool}>}>
     */
    private static function rules(string $css): array
    {
        $css   = (string) preg_replace('!/\*.*?\*/!s', '', $css);
        $out   = [];
        $stack = [];
        $buf   = '';

        for ($i = 0, $n = strlen($css); $i < $n; $i++) {
            $c = $css[$i];

            if ($c === '{') {
                $stack[] = trim((string) preg_replace('/\s+/', ' ', $buf));
                $buf = '';
                continue;
            }

            if ($c === '}') {
                $head = array_pop($stack);
                // `$head === ''` is a stray brace; a leading `@` is an at-rule, whose
                // own block holds rules rather than declarations.
                if ($head !== null && $head !== '' && $head[0] !== '@') {
                    $decls = [];
                    foreach (explode(';', $buf) as $seg) {
                        if (!str_contains($seg, ':')) continue;
                        [$prop, $value] = explode(':', $seg, 2);
                        $prop = strtolower(trim($prop));
                        // A custom property is not a shorthand and does not expand.
                        // It also shares BEM's modifier separator, so `--x` can be a
                        // selector fragment rather than a declaration name.
                        if ($prop === '' || str_starts_with($prop, '--')) continue;
                        if (!preg_match('/^[a-z-]+$/', $prop)) continue;
                        $decls[] = [$prop, str_contains(strtolower($value), '!important')];
                    }
                    if ($decls) {
                        $ctx = implode('|', array_filter(
                            $stack,
                            static fn (string $h): bool => $h !== '' && $h[0] === '@'
                        ));
                        $out[] = [$ctx, $head, $decls];
                    }
                }
                $buf = '';
                continue;
            }

            $buf .= $c;
        }

        return $out;
    }
}
