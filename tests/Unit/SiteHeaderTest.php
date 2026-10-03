<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The site header: what it must contain, and the one thing it must not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * DESTROYED AND REBUILT WITH THE HEADER (Phase 2, 3 Oct 2026)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `partials/site-header.twig` was deleted and written again from SiteHeader.dc.html, and
 * this file was rebuilt with it — every rule the old file held, plus the three the destroy
 * left as prose in `docs/handoff/inventory/` (the green button, the hairline, the toolbar's
 * arrow keys), each watched failing before it was trusted (docs/handoff/PHASE-2.md).
 *
 * The header is the one component every page carries, which makes each of its faults a
 * fault on four hundred routes at once — and makes "open a page and look" the check that
 * misses the signed-in state you are not in. So the states are RENDERED here, both of them.
 */
final class SiteHeaderTest extends TestCase
{
    private const NAV = 'templates/partials/site-header.twig';

    private const MEMBER = ['user_id' => 1, 'user_name' => 'Chioma Obi'];

    private function code(): string
    {
        return ChromeRender::source(self::NAV);
    }

    /** @return array<string,list<string>> panel key => hrefs, read from the data list the header loops over */
    private function panels(): array
    {
        $out = [];
        $code = $this->code();
        preg_match_all("/\\{k:'(\\w+)', label:'[^']+', items:\\[(.*?)\\]\\}/s", $code, $m, PREG_SET_ORDER);
        foreach ($m as $p) {
            preg_match_all("/href:'([^']+)'/", $p[2], $h);
            $out[$p[1]] = $h[1];
        }
        return $out;
    }

    public function test_the_bar_carries_two_top_links_and_no_more(): void
    {
        // §18.5 rejects a bar carrying half a dozen section links. Counted from the DATA
        // LIST, because the trigger is declared once inside a loop that renders it twice.
        preg_match_all("/\\{k:'(\\w+)', label:'([^']+)'/", $this->code(), $m);
        $this->assertSame(['Participate', 'Explore'], $m[2],
            'the header has grown, lost or renamed a top-level panel');
    }

    public function test_each_panel_is_the_handoffs_six_exactly(): void
    {
        // §7.1, and the owner's decision of 3 Oct 2026: Explore is the handoff's six, and
        // Results is NOT in it. The previous header carried Results as a seventh so a
        // decided award stayed reachable by browsing (PublicResultsTest); the owner removed
        // it, and the reachability of /results is now the search index's and the rebuilt
        // footer's to owe (inventory/_partials.md, layout/footer.twig).
        $this->assertSame([
            'participate' => ['/nominate', '/vote', '/awards', '/giving', '/account/register', '/integrity'],
            'explore'     => ['/discover', '/pulse', '/events', '/legacy', '/blog', '/status'],
        ], $this->panels());
        $this->assertStringNotContainsString("href:'/results'", $this->code(),
            'Results is back in the header — the owner removed it (3 Oct 2026)');
    }

    public function test_every_destination_in_the_panels_is_a_real_page(): void
    {
        // Read from the Twig data list, which is the form a literal-href sweep cannot see.
        $hrefs = array_merge(...array_values($this->panels()));
        $this->assertCount(12, $hrefs, 'the panels lost items');

        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $missing = [];
        foreach (array_unique($hrefs) as $href) {
            $path = '/' . trim($href, '/');
            $last = '/' . substr($path, (int) strrpos($path, '/') + 1);
            if (!str_contains($routes, "'" . $path . "'") && !str_contains($routes, "'" . $last . "'")) {
                $missing[] = $href;
            }
        }
        $this->assertSame([], $missing, 'the header points at ' . implode(', ', $missing) . ' and nothing serves them');
    }

    public function test_there_is_no_green_button_in_either_signed_in_state(): void
    {
        // §6.1: green is THE primary action inside the page. A green pill in the chrome is
        // on screen beside every page's own call to action and wins. Rendered in BOTH
        // states, because the one you are not signed in as is the one nobody looks at.
        foreach (['signed out' => [], 'signed in' => self::MEMBER] as $state => $session) {
            $html = ChromeRender::html('/_dev/ui', $session);
            $this->assertMatchesRegularExpression('~<div class="ag-head"~', $html, "no header rendered ($state)");
            $head = substr($html, (int) strpos($html, '<div class="ag-head"'));
            $head = substr($head, 0, (int) strpos($head, '<main'));
            $this->assertStringNotContainsString('ag-btn--primary', $head, "a primary (green) button in the header ($state)");
            $this->assertStringContainsString($state === 'signed in' ? 'class="ag-head__av"' : 'class="ag-head__signin"', $head);
        }

        // And the two controls' rules paint nothing green — the signed-out pill once WAS.
        $css = ChromeRender::code('public/assets/css/components/chrome.css');
        foreach (ChromeRender::rules($css) as [$sel, $body]) {
            if (!preg_match('~\.ag-head__(signin|av)\b~', $sel)) continue;
            $this->assertDoesNotMatchRegularExpression('~--ag-green~', $body, "$sel is painted green");
        }
    }

    public function test_the_hairline_is_dropped_while_a_panel_is_open(): void
    {
        // The panel hangs from the bar with no gap; a rule between them cuts it in half.
        $css = ChromeRender::code('public/assets/css/components/chrome.css');
        $found = false;
        foreach (ChromeRender::rules($css) as [$sel, $body]) {
            if (str_contains($sel, '.ag-head.is-mega') && str_contains($sel, '.ag-head__bar')
                && preg_match('~border-bottom-color\s*:\s*transparent~', $body)) $found = true;
        }
        $this->assertTrue($found, 'nothing drops the bar\'s hairline while a panel is open');
        $js = ChromeRender::code('public/assets/js/header.js');
        $this->assertStringContainsString("classList.add('is-mega')", $js);
        $this->assertStringContainsString("classList.remove('is-mega')", $js);
    }

    public function test_the_toolbar_holds_exactly_search_display_and_language_and_answers_the_arrows(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('role="toolbar"', $code);
        $this->assertSame(3, preg_match_all('~class="ag-tools__b\b~', $code),
            'the toolbar pill is search, Aa and language — a fourth control belongs in a panel');
        $this->assertStringContainsString('data-ag-search-open', $code);
        $this->assertStringContainsString('data-ag-pop="aa"', $code);
        $this->assertStringContainsString('data-ag-pop="lang"', $code);

        // `role="toolbar"` is a claim about the arrow keys: one tab stop, ← → between the
        // three. The script half was lost with header.js on 3 Oct 2026 and the claim stood
        // unbacked; it is held again here, against the rebuilt header.js.
        $js = ChromeRender::code('public/assets/js/header.js');
        $this->assertStringContainsString('[data-ag-toolbar]', $js);
        $this->assertStringContainsString("'ArrowRight'", $js);
        $this->assertMatchesRegularExpression('~tabIndex\s*=\s*i === 0 \? 0 : -1~', $js, 'the toolbar is not one tab stop');
        $this->assertStringContainsString('data-ag-toolbar', $code);
    }

    public function test_the_display_popover_is_the_shared_partial_and_not_a_second_copy(): void
    {
        $this->assertStringContainsString("include 'partials/display-reading.twig'", $this->code());
        $this->assertStringNotContainsString('data-ag-toggle=', $this->code());
    }

    public function test_the_language_menu_works_without_javascript(): void
    {
        $this->assertMatchesRegularExpression(
            '/<a class="ag-pop__lang"[^>]*href="\{\{ lang_url\(l\.code\) \}\}"/',
            $this->code()
        );
        $this->assertStringNotContainsString('data-ag-pick-lang', $this->code());
    }

    public function test_every_trigger_returns_focus_and_closes_the_others(): void
    {
        // §7.1: "Opening one panel closes the other panels and popovers … focus returns to
        // the trigger." One controller for all of them, so two cannot be open at once.
        $js = ChromeRender::code('public/assets/js/header.js');
        $this->assertMatchesRegularExpression('~function open\(layer.*?if \(openLayer && openLayer !== layer\) close\(openLayer~s', $js);
        $this->assertMatchesRegularExpression("~if \\(returnFocus\\) layer\\.trigger\\.focus\\(\\)~", $js);
        $this->assertMatchesRegularExpression("~'Escape'.*?close\\(layer, true\\)~s", $js);
        $this->assertStringContainsString('[data-ag-mega-scrim]', $js);
    }

    public function test_each_sheet_is_mounted_by_the_layout_that_can_open_it(): void
    {
        $code  = $this->code();
        $shell = ChromeRender::source('templates/layout/shell.twig');

        foreach (['partials/menu-sheet.twig', 'partials/quick-settings.twig'] as $sheet) {
            $this->assertSame(0, substr_count($code, $sheet), "the header mounts $sheet, so a page draws two");
            $this->assertSame(1, substr_count($shell, $sheet), "the shell mounts $sheet other than once");
        }
        $this->assertSame(1, substr_count($shell, "'partials/site-header.twig'"), 'the shell must mount the header, once');
    }

    public function test_a_page_never_mounts_the_chrome_itself(): void
    {
        // The chrome is the LAYOUT's. A page that includes the header, the tab bar or a
        // sheet draws a second copy beside the shell's — two `data-ag-menu-sheet` dialogs,
        // and the opener finds the first, so the tab bar's Menu appears dead. This is the
        // rule `layout/nav.twig`'s destroyed guard held, re-expressed for the shell.
        $root = dirname(__DIR__, 2) . '/templates/pages';
        $chrome = ['site-header', 'app-bar', 'tab-bar', 'menu-sheet', 'quick-settings', 'site-search', 'shortcuts', 'lang-prompt'];
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($f->getPathname()));
            foreach ($chrome as $c) {
                if (str_contains($body, "partials/$c.twig")) $bad[] = basename($f->getPathname()) . " includes partials/$c.twig";
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_no_id_appears_twice_in_one_document(): void
    {
        // `getElementById`, every `for=` and every `aria-labelledby` resolve to the FIRST
        // match. Display & reading is mounted twice on purpose (Aa popover, Menu) and so
        // takes a `uid`; this counts ids rather than mounts, so it cannot force the wrong fix.
        // Measured on the RENDERED page, in both signed-in states.
        foreach (['signed out' => [], 'signed in' => self::MEMBER] as $state => $session) {
            $html = ChromeRender::html('/_dev/ui', $session);
            preg_match_all('/\sid="([^"]+)"/', $html, $m);
            $twice = array_keys(array_filter(array_count_values($m[1]), static fn (int $n): bool => $n > 1));
            $this->assertSame([], $twice, "ids rendered more than once ($state): " . implode(', ', $twice));
        }
    }
}
