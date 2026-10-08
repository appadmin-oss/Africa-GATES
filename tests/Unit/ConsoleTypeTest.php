<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\ConsoleSurface;
use Tests\Support\PublicSurface;
use Tests\TestCase;

/**
 * THE ADMIN CONSOLE'S TYPE: ITS OWN CLOSED LADDER, NO CAPITALS, MONO ONLY FOR FIGURES.
 *
 * Decided 4 Oct 2026 (GAPS §8d, recorded in docs/handoff/PHASE-ADMIN.md): the console
 * follows ITS handoff's scale (README §10 — 11 · 11.5 · 12 · 12.5 · 13 · 13.5 · 14 · 15 ·
 * 17 · 18 · 22 · 24 · 26 · 30, plus 10.5 and 16 which its HTML draws in the shell), NOT
 * the public ladder — the owner asked for the public site's rules to be held strictly on
 * the public surface, and a dense operator console is not a reading surface. What the two
 * surfaces SHARE is held here exactly as MonoAndCaseTest holds it in public:
 *
 *   · no capitals — no `text-transform:uppercase|capitalize`, no small-caps;
 *   · monospace only for what is copied or counted (§10: "every figure, reference, time
 *     and key cap"), by a NAMING convention: a rule may set the mono face only if a class
 *     in its selector ends in a segment that says so — `n`, `num`, `badge`, `v`, `kbd`,
 *     `time`, `ref`, `code`;
 *   · type is a TOKEN: every size is a `var(--cn-fs-*)` step, never a literal, never
 *     vw/clamp, never em/% — so a size cannot leave the ladder without a token changing.
 *
 * Scope: Tests\Support\ConsoleSurface — the console's sheets and the templates rebuilt to
 * its design so far. Each stage adds the templates it rebuilds.
 */
final class ConsoleTypeTest extends TestCase
{
    /** px → the tokens.css step name suffix. */
    private const LADDER = [10.5, 11.0, 11.5, 12.0, 12.5, 13.0, 13.5, 14.0, 15.0, 16.0, 17.0, 18.0, 22.0, 24.0, 26.0, 30.0];

    private const MONO_OK = ['n', 'num', 'badge', 'v', 'kbd', 'time', 'ref', 'code'];

    /** @return array{type:list<string>, caps:list<string>, mono:list<string>} */
    public static function findings(array $files): array
    {
        $out = ['type' => [], 'caps' => [], 'mono' => []];
        foreach ($files as $rel => $src) {
            $twig = str_ends_with($rel, '.twig');
            $text = PublicSurface::strip($src, $twig);
            foreach (PublicSurface::rules($text, $twig) as [$sel, $body, $at]) {
                foreach (PublicSurface::declarations($body) as [$p, $v, $dAt]) {
                    $line = PublicSurface::lineAt($text, $at + $dAt);
                    $where = sprintf('%s:%d  %s { %s: %s }', $rel, $line, $sel, $p, $v);
                    if ($p === 'font-size' && !preg_match('~^var\(--cn-fs-[0-9-]+\)$~', $v)) $out['type'][] = $where;
                    if ($p === 'font' && $v !== 'inherit' && !preg_match('~(^|\s)var\(--cn-fs-[0-9-]+\)(/|\s|$)~', $v)) $out['type'][] = $where;
                    if ($p === 'text-transform' && preg_match('~uppercase|capitalize~i', $v)) $out['caps'][] = $where;
                    if (in_array($p, ['font-variant', 'font-variant-caps'], true) && preg_match('~small-caps|all-small|petite~i', $v)) $out['caps'][] = $where;
                    if (in_array($p, ['font', 'font-family'], true) && preg_match('~--cn-mono|JetBrains|monospace~i', $v)) {
                        if ($p === 'font-family' && $sel === ':root') continue;
                        if (!self::monoSelector($sel)) $out['mono'][] = $where;
                    }
                }
            }
        }
        return $out;
    }

    private static function monoSelector(string $sel): bool
    {
        foreach (preg_split('~\s*,\s*~', $sel) ?: [] as $one) {
            $ok = false;
            preg_match_all('~\.([A-Za-z0-9_-]+)~', $one, $m);
            foreach ($m[1] as $cls) {
                $parts = preg_split('~__|--|-~', $cls) ?: [];
                if (in_array(end($parts), self::MONO_OK, true)) $ok = true;
            }
            if (preg_match('~(^|\s)(kbd|code|samp|pre)\b~', $one)) $ok = true;
            if (!$ok) return false;
        }
        return true;
    }

    /** @return array<string,string> */
    private static function surface(): array
    {
        $out = [];
        foreach (ConsoleSurface::files() as $rel => $abs) {
            if ($rel === ConsoleSurface::CSS_DIR . 'tokens.css') continue;   // it DEFINES the steps
            $out[$rel] = (string) file_get_contents($abs);
        }
        return $out;
    }

    public function test_every_console_size_is_a_step_of_the_consoles_ladder(): void
    {
        $f = self::findings(self::surface());
        $this->assertSame([], $f['type'], "console type not written as a --cn-fs step:\n  " . implode("\n  ", $f['type']));
    }

    public function test_the_ladder_is_closed_and_each_step_is_its_name(): void
    {
        $css = (string) file_get_contents(PublicSurface::root() . '/' . ConsoleSurface::CSS_DIR . 'tokens.css');
        preg_match_all('~--cn-fs-([0-9]+(?:-5)?):\s*([0-9.]+)rem;~', $css, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'no type steps found');
        foreach ($m as [, $name, $rem]) {
            $px = (float) str_replace('-', '.', $name);
            $this->assertContains($px, self::LADDER, "--cn-fs-$name is not on README §10's ladder");
            $this->assertEqualsWithDelta($px, (float) $rem * 16, 0.0001, "--cn-fs-$name is not {$px}px");
        }
        $this->assertDoesNotMatchRegularExpression('~font-size[^;]*(vw|vh|clamp|cqw)~', $css);
    }

    public function test_no_capitals_and_mono_only_for_what_is_copied_or_counted(): void
    {
        $f = self::findings(self::surface());
        $this->assertSame([], $f['caps'], "capitals in the console:\n  " . implode("\n  ", $f['caps']));
        $this->assertSame([], $f['mono'], "monospace on something that is not a figure, reference, time or key:\n  " . implode("\n  ", $f['mono']));
    }

    public function test_the_detectors_name_what_they_exist_to_stop(): void
    {
        $f = self::findings([
            'a.css' => ".a{ font-size:13px }\n.b{ font:500 var(--cn-fs-13)/1 var(--cn-mono) }\n"
                     . ".c__n{ font:500 var(--cn-fs-13)/1 var(--cn-mono) }\n.d{ text-transform:uppercase }\n"
                     . ".e{ font-size:2vw }\n.f{ font-size:var(--cn-fs-14) }\n/* .g{ text-transform:uppercase } */\n",
            'b.twig' => "<span class=\"x\" style=\"font-family:var(--cn-mono)\">k</span>",
        ]);
        $type = implode("\n", $f['type']);
        $this->assertStringContainsString('a.css:1', $type, 'a literal px size');
        $this->assertStringContainsString('a.css:5', $type, 'a viewport size');
        $this->assertStringNotContainsString('a.css:6', $type);
        $mono = implode("\n", $f['mono']);
        $this->assertStringContainsString('a.css:2', $mono, 'mono on a label');
        $this->assertStringNotContainsString('a.css:3', $mono, 'mono on a count is right');
        $this->assertStringContainsString('b.twig:1', $mono, 'mono in an inline style');
        $caps = implode("\n", $f['caps']);
        $this->assertStringContainsString('a.css:4', $caps);
        $this->assertStringNotContainsString('a.css:7', $caps, 'a comment reaches nobody');
    }

    public function test_the_console_and_the_public_scope_do_not_overlap(): void
    {
        $public = array_keys(PublicSurface::files(true));
        foreach (array_keys(ConsoleSurface::files()) as $rel) {
            $this->assertNotContains($rel, $public, "$rel is swept by both surfaces' rules");
        }
        foreach (ConsoleSurface::REBUILT as $rel) $this->assertFileExists(PublicSurface::root() . '/' . $rel);
    }
}
