<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\AssetBundle;
use Tests\TestCase;

/**
 * The Phase 1 page shell: only <main> scrolls, and the lock that makes that true is
 * the shell's alone.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE ARE WORTH A TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every rule here fails SILENTLY when it breaks, and in a way that reads as a bug in
 * somebody else's page:
 *
 *  · The handoff writes the overflow lock as `html,body{overflow:hidden}` for every
 *    page. Spelled that way in shell.css — which BOTH layouts load — it makes the
 *    ~85 templates still on layout/gates.twig unscrollable below the fold, and the
 *    report is "the events page is cut off", not "shell.css changed". The lock rides
 *    on `html.ag-shelled`, which only layout/shell.twig writes.
 *  · Two scroll listeners on one scroller is exactly what §9.1 forbids, and nothing
 *    looks wrong: both run, the page is merely doing twice the work per frame.
 *  · `offsetParent` is null for a `position:fixed` element, so a bottom-bar
 *    measurement written with it (as the snippet is) never sees the bars it exists to
 *    measure; `--ag-bottom-ui` sits at 0 and the Gee launcher lands on the tab bar.
 *  · chrome.js calls `AGShell.openSheet`. A rebuild of shell.js that drops a name
 *    from the object leaves the Menu dead on every phone page with one console line.
 *
 * Each test below was broken on purpose before it was trusted (docs/handoff/PHASE-1.md).
 */
final class ShellLayoutTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private function read(string $rel): string
    {
        return (string) file_get_contents(self::ROOT . $rel);
    }

    /** CSS with comments blanked, so a rule quoted in a comment is never mistaken for one. */
    private function css(string $rel): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $this->read($rel));
    }

    /**
     * Innermost rules as [selector, body, enclosing at-rule or ''].
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function rules(string $css): array
    {
        $out = [];
        $stack = [];
        $buf = '';
        foreach (str_split($css) as $ch) {
            if ($ch === '{') {
                $stack[] = trim($buf);
                $buf = '';
                continue;
            }
            if ($ch === '}') {
                $sel = array_pop($stack);
                if ($sel !== null && !str_starts_with($sel, '@') && trim($buf) !== '') {
                    $out[] = [$sel, $buf, $stack ? (string) end($stack) : ''];
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        return $out;
    }

    /** The template with Twig comments removed — a comment reaches nobody. */
    private function twig(string $rel): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $this->read($rel));
    }

    // ── The lock ─────────────────────────────────────────────────────────────

    public function test_the_overflow_lock_belongs_to_the_shell_layout_alone(): void
    {
        $bad = [];
        foreach ($this->rules($this->css('public/assets/css/shell.css')) as [$sel, $body]) {
            if (!preg_match('~overflow\s*:\s*hidden~', $body)) continue;
            foreach (explode(',', $sel) as $one) {
                $one = trim($one);
                // A selector that reaches the root or the body locks the DOCUMENT.
                if (preg_match('~^(html|body)\b|\s(html|body)\b~', $one) && !str_contains($one, '.ag-shelled')) {
                    $bad[] = $one;
                }
            }
        }
        $this->assertSame([], $bad,
            'shell.css locks the document scroll for every page that loads it: '
            . implode(', ', $bad));

        preg_match('~<html\b[^>]*>~', $this->twig('templates/layout/shell.twig'), $shell);
        $this->assertNotEmpty($shell);
        $this->assertMatchesRegularExpression('~class="[^"]*\bag-shelled\b~', $shell[0],
            'layout/shell.twig must arm the lock on <html> in the markup the server sends');

        // The other half used to read layout/gates.twig and require it NOT to be armed.
        // That layout was destroyed with the old pages (docs/handoff/DESTROYED.md); the
        // selector sweep above still refuses a lock that reaches any other document.
    }

    public function test_only_main_scrolls_and_it_is_the_positioning_context(): void
    {
        $rules = $this->rules($this->css('public/assets/css/shell.css'));
        $find = static function (string $sel) use ($rules): string {
            $body = '';
            foreach ($rules as [$s, $b, $at]) {
                if ($at === '' && in_array($sel, array_map('trim', explode(',', $s)), true)) $body .= $b;
            }
            return (string) preg_replace('~\s+~', '', $body);
        };

        $shell = $find('.ag-shell');
        foreach (['height:100dvh', 'max-height:100%', 'overflow:hidden', 'flex-direction:column'] as $d) {
            $this->assertStringContainsString($d, $shell, ".ag-shell is missing {$d}");
        }
        $main = $find('.ag-main');
        foreach (['position:relative', 'flex:1', 'min-height:0', 'overflow-y:auto'] as $d) {
            $this->assertStringContainsString($d, $main, ".ag-main is missing {$d}");
        }

        $layout = $this->twig('templates/layout/shell.twig');
        $this->assertSame(1, preg_match_all('~<main\b~', $layout), 'the shell has exactly one <main>');
        $this->assertMatchesRegularExpression('~<main class="ag-main" id="main">~', $layout);
    }

    public function test_focus_is_a_three_pixel_green_ring_two_pixels_out(): void
    {
        $ok = false;
        foreach ($this->rules($this->css('public/assets/css/shell.css')) as [$sel, $body, $at]) {
            if ($at !== '' || !in_array(':focus-visible', array_map('trim', explode(',', $sel)), true)) continue;
            $b = (string) preg_replace('~\s+~', ' ', $body);
            $ok = str_contains($b, 'outline:3px solid var(--ag-green)') && str_contains($b, 'outline-offset:2px');
        }
        $this->assertTrue($ok, 'REFERENCE §13: a 3px --ag-green outline with a 2px offset');
    }

    public function test_important_lives_only_in_the_reduced_motion_blocks_and_the_hidden_rule(): void
    {
        $bad = [];
        foreach (['public/assets/css/shell.css', 'public/assets/css/components.css'] as $f) {
            foreach ($this->rules($this->css($f)) as [$sel, $body, $at]) {
                if (!str_contains($body, '!important')) continue;
                if (str_contains($at, 'prefers-reduced-motion')) continue;
                if (str_starts_with(trim($sel), 'html.ag-rm')) continue;
                if (trim($sel) === '[hidden]') continue;   // deviation, see PHASE-1.md
                $bad[] = "{$f}: {$sel}";
            }
        }
        $this->assertSame([], $bad, 'REFERENCE §4.6 allows !important only for reduced motion: ' . implode('; ', $bad));

        $shell = $this->css('public/assets/css/shell.css');
        $this->assertMatchesRegularExpression('~@media\s*\(prefers-reduced-motion:\s*reduce\)~', $shell);
        $this->assertStringContainsString('html.ag-rm', $shell, 'the in-app Reduce motion switch must be honoured too');
    }

    // ── shell.js ─────────────────────────────────────────────────────────────

    private function js(): string
    {
        // Comments out, so a call quoted in a comment is not counted as a listener.
        $js = $this->read('public/assets/js/shell.js');
        $js = (string) preg_replace('~/\*.*?\*/~s', '', $js);
        return (string) preg_replace('~^\s*//.*$~m', '', $js);
    }

    public function test_one_passive_scroll_listener_and_a_boolean_on_a_threshold_of_eight(): void
    {
        $js = $this->js();
        $this->assertSame(1, preg_match_all("~addEventListener\(\s*'scroll'~", $js),
            '§9.1: one passive scroll listener per scroller — subscribers share it');
        $this->assertMatchesRegularExpression("~addEventListener\(\s*'scroll',.*?\},\s*\{\s*passive:\s*true\s*\}\)~s", $js);
        $this->assertMatchesRegularExpression('~THRESHOLD\s*=\s*8\b~', $js);
        $this->assertMatchesRegularExpression('~if\s*\(\s*v\s*===\s*state\s*\)\s*return~', $js,
            'the scroll state must compare with the previous boolean before writing anything');
    }

    public function test_the_bottom_bar_measurement_sees_fixed_bars(): void
    {
        $js = $this->js();
        preg_match('~function trackBottomUI\(\)\s*\{(.*?)\n  \}~s', $js, $m);
        $this->assertNotEmpty($m, 'trackBottomUI() not found');
        $this->assertStringContainsString('getClientRects()', $m[1]);
        $this->assertStringNotContainsString('offsetParent', $m[1],
            'offsetParent is null for position:fixed — it excludes every bar it exists to measure');
        $this->assertStringContainsString("document.body.style.setProperty('--ag-bottom-ui'", $m[1],
            'REFERENCE §10: the height lives on <body>');
    }

    public function test_every_agshell_name_a_reader_uses_is_still_exported(): void
    {
        preg_match('~window\.AGShell\s*=\s*\{([^}]*)\}~', $this->js(), $m);
        $this->assertNotEmpty($m);
        preg_match_all('~(\w+)\s*:~', $m[1], $k);
        $exported = $k[1];

        $used = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . 'public/assets/js'));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!str_ends_with($f->getFilename(), '.js') || $f->getFilename() === 'shell.js') continue;
            preg_match_all('~AGShell\.(\w+)~', (string) file_get_contents($f->getPathname()), $u);
            foreach ($u[1] as $n) $used[$n] = $f->getFilename();
        }
        foreach (glob(self::ROOT . 'templates/{,*/,*/*/}*.twig', GLOB_BRACE) ?: [] as $f) {
            preg_match_all('~AGShell\.(\w+)~', (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($f)), $u);
            foreach ($u[1] as $n) $used[$n] = basename($f);
        }
        $this->assertArrayHasKey('openSheet', $used, 'chrome.js no longer opens its sheets through the shell?');

        $missing = [];
        foreach ($used as $n => $where) {
            if (!in_array($n, $exported, true)) $missing[] = "{$n} (read by {$where})";
        }
        $this->assertSame([], $missing, 'window.AGShell lost a name somebody calls: ' . implode(', ', $missing));
    }

    public function test_the_collapsing_search_keeps_the_accessibility_tree_honest(): void
    {
        $js = $this->js();
        preg_match('~function collapsingSearch\(main, block\)\s*\{(.*?)\n  \}~s', $js, $m);
        $this->assertNotEmpty($m);
        $body = $m[1];

        // §9.2 accessibility: whichever is hidden is aria-hidden AND out of the tab order.
        $this->assertStringContainsString("row.setAttribute('aria-hidden', String(!o))", $body);
        $this->assertStringContainsString('input.tabIndex = o ? 0 : -1', $body);
        $this->assertStringContainsString("wrap.setAttribute('aria-hidden', String(o))", $body);
        $this->assertStringContainsString('btn.tabIndex = o ? -1 : 0', $body);
        // The button moves focus to the input; Esc collapses and returns it to the button.
        $this->assertMatchesRegularExpression("~btn\.addEventListener\('click'.*?input\.focus~s", $body);
        $this->assertMatchesRegularExpression("~'Escape'.*?set\(false\).*?btn\.focus~s", $body);
        // States 4 and 6, and the 24px drift.
        $this->assertMatchesRegularExpression('~DRIFT\s*=\s*24\b~', $js);
        $this->assertMatchesRegularExpression('~y\s*<=\s*THRESHOLD\)\s*\{\s*set\(true\)~', $body);

        // And the CSS never reaches for display:none on the row (§9.2: "Never").
        foreach ($this->rules($this->css('public/assets/css/components.css')) as [$sel, $rb]) {
            if (str_contains($sel, 'ag-cs__')) {
                $this->assertDoesNotMatchRegularExpression('~display\s*:\s*none~', $rb, "{$sel} hides with display:none");
            }
        }
    }

    // ── Loading order ────────────────────────────────────────────────────────

    public function test_the_shell_loads_the_base_in_the_bundles_order(): void
    {
        preg_match_all("~asset\('/(assets/css/[^']+)'\)~", $this->twig('templates/layout/shell.twig'), $m);
        $shell = $m[1];
        // The base and nothing else: the rules once carved out of components.css
        // (chrome.css, library.css) were destroyed with the old pages, and a rebuilt
        // component sheet joins this list in the commit that rebuilds it.
        // Phase 2 added the chrome sheet after the base, which it specialises, and item 6
        // the cookie notice and preferences sheet after the chrome; Phase 4 the site footer
        // after those (it is chrome on every shell page); Phase 3 Gee after all of them.
        $want = ['assets/css/tokens.css', 'assets/css/shell.css', 'assets/css/components.css',
                 'assets/css/components/chrome.css',
                 // The Menu sheet, rebuilt whole out of chrome.css (4 Oct 2026, MENU-SHEET.md).
                 'assets/css/components/menu-sheet.css', 'assets/css/components/consent.css',
                 'assets/css/components/footer.css', 'assets/css/components/gee.css'];
        $this->assertSame($want, $shell,
            'the shell must load the base, in the bundle\'s order, and nothing destroyed');

        $list = AssetBundle::STYLESHEETS;
        $at = array_map(static fn (string $s): int => (int) array_search($s, $list, true), $shell);
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at, 'layout/shell.twig and AssetBundle::STYLESHEETS disagree about the cascade');
    }
}
