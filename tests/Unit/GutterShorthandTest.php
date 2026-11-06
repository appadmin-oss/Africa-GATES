<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * AN ELEMENT THAT CARRIES THE PAGE GUTTER MUST NOT ZERO IT WITH A SHORTHAND.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SHAPE, WHICH IS INVISIBLE IN EITHER RULE ALONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every page here has one wrapper that owns the side gutter — `.rx`, `.hf-wrap`, `.vn`,
 * `.ed`, `.op` — declared once as `padding: 0 var(--ag-gutter)`. A sticky bar then puts a
 * second class on that same element to give it vertical room, and writes `padding: 12px 0`.
 *
 * The shorthand sets all four sides. So the bar's content runs flush to both screen edges
 * while every other line on the page sits inside a 17px margin — and at 430px the Search
 * button ended on the last pixel of the viewport and read as cut off. Neither rule is
 * wrong on its own; the fault is only visible if you know both are on one element.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS WORTH A TEST RATHER THAN A FIX
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It shipped on TWO pages in the same afternoon, written by the same hand, five minutes
 * apart. `padding: 12px 0` is the reflex spelling; `padding-block: 12px` is the one that
 * has to be remembered. A reflex is not something a code review catches reliably, and the
 * symptom — a control flush to the edge of a phone — is invisible on a desktop screenshot.
 *
 * The rule is narrow on purpose: plenty of elements legitimately write `padding: 12px 0`.
 * It fires only where the element ALSO carries a class that owns the gutter, which is the
 * only place the shorthand destroys something.
 */
final class GutterShorthandTest extends TestCase
{
    /** @return array<string,string> template path => its inline stylesheet */
    private function sheets(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates'));

        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;

            $body = (string) file_get_contents($f->getPathname());
            if (!preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $body, $m)) continue;

            // A CSS comment reaches no reader, and this file's own note quotes the broken
            // spelling — the trap this codebase has already recorded twice.
            $out[str_replace($root . '/', '', $f->getPathname())]
                = (string) preg_replace('!/\*.*?\*/!s', ' ', implode("\n", $m[1])) . '|||' . $body;
        }

        return $out;
    }

    /** Classes whose rule owns the page's side gutter. */
    private function gutterClasses(string $css): array
    {
        $out = [];

        foreach (explode('}', $css) as $chunk) {
            $at = strrpos($chunk, '{');
            if ($at === false) continue;

            $sel  = substr($chunk, 0, $at);
            $rule = substr($chunk, $at + 1);

            // `padding: <anything> var(--ag-gutter…)` — the horizontal component is the
            // gutter token, whatever the vertical one is.
            if (!preg_match('/padding\s*:[^;]*var\(\s*--ag-gutter/i', $rule)
                && !preg_match('/padding-inline\s*:[^;]*var\(\s*--ag-gutter/i', $rule)) continue;

            if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $sel, $cm)) {
                foreach ($cm[1] as $c) $out[$c] = true;
            }
        }

        return array_keys($out);
    }

    public function test_no_rule_zeroes_a_gutter_the_same_element_is_carrying(): void
    {
        $bad = [];

        foreach ($this->sheets() as $file => $both) {
            [$css, $markup] = explode('|||', $both, 2);

            $gutters = $this->gutterClasses($css);
            if ($gutters === []) continue;

            // Every class that shares an element with a gutter class, in this template.
            $companions = [];
            if (preg_match_all('/\bclass\s*=\s*"([^"{}]*)"/', $markup, $m)) {
                foreach ($m[1] as $list) {
                    $classes = array_values(array_filter(preg_split('/\s+/', trim($list))));
                    if (array_intersect($classes, $gutters) === []) continue;

                    foreach ($classes as $c) {
                        if (!in_array($c, $gutters, true)) $companions[$c] = true;
                    }
                }
            }

            if ($companions === []) continue;

            foreach (explode('}', $css) as $chunk) {
                $at = strrpos($chunk, '{');
                if ($at === false) continue;

                $sel  = trim(substr($chunk, 0, $at));
                $rule = substr($chunk, $at + 1);

                // A `padding` SHORTHAND — not padding-block, not padding-top — whose
                // horizontal component is a bare zero.
                if (!preg_match('/(?<!-)\bpadding\s*:\s*([^;]+)/i', $rule, $pm)) continue;

                // BOTH horizontal sides, because a four-value shorthand sets them
                // separately — top/right/bottom/left — and either one being zero destroys
                // the gutter on that edge. Reading only the fourth value looks right and
                // misses `padding: 12px 0 12px 8px`, which zeroes the RIGHT margin and
                // leaves the left one intact: a control flush to one screen edge only,
                // which is harder to spot than a bar flush to both.
                if (self::horizontal($pm[1]) === []) continue;

                foreach (array_keys($companions) as $c) {
                    if (!preg_match('/\.' . preg_quote($c, '/') . '\b/', $sel)) continue;

                    $bad[] = sprintf('%s: `%s { padding: %s }` zeroes the side gutter its '
                                   . 'own element carries — use padding-block',
                                     $file, $sel, trim($pm[1]));
                }
            }
        }

        $bad = array_values(array_unique($bad));
        sort($bad);

        $this->assertSame([], $bad, implode("\n  ", $bad));
    }

    /**
     * The horizontal sides of a `padding` shorthand that are a bare zero.
     *
     * CSS order is top / right / bottom / left. One value sets all four; two and three set
     * both horizontal sides from the SECOND; four set right from the second and left from
     * the FOURTH — separately, which is the case that matters.
     *
     * @return list<string> the zeroed sides, empty when neither is
     */
    private static function horizontal(string $value): array
    {
        $p = preg_split('/\s+/', trim($value)) ?: [];

        $sides = match (count($p)) {
            0 => [],
            1 => ['right' => $p[0], 'left' => $p[0]],
            2, 3 => ['right' => $p[1], 'left' => $p[1]],
            default => ['right' => $p[1], 'left' => $p[3]],
        };

        $zero = [];
        foreach ($sides as $name => $v) {
            if (preg_match('/^0[a-z%]*$/i', (string) $v)) $zero[] = $name;
        }

        return $zero;
    }

    public function test_the_sweep_reads_the_shorthand_the_way_css_does(): void
    {
        // Proving the part that decides every finding. Reading the wrong slot is how a
        // sweep like this invents findings or misses the only one that matters — and it
        // DID miss one: an earlier version took the fourth value alone, so
        // `padding: 12px 0 12px 8px` (right zeroed, left kept) passed.
        $this->assertSame(['right', 'left'], self::horizontal('0'));
        $this->assertSame(['right', 'left'], self::horizontal('12px 0'));
        $this->assertSame(['right', 'left'], self::horizontal('12px 0 8px'));
        $this->assertSame(['left'],          self::horizontal('12px 8px 8px 0'));
        $this->assertSame(['right'],         self::horizontal('12px 0 12px 8px'));
        $this->assertSame([],                self::horizontal('12px 16px'));
        $this->assertSame([],                self::horizontal('1rem 20px 2rem 20px'));
    }
}
