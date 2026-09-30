<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The site header: what it must contain, and the one thing it must not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HEADER IS THE ONE COMPONENT EVERY PAGE CARRIES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Which makes each of its faults a fault on four hundred routes at once, and makes the
 * usual way of checking one — open a page and look — the way that misses the state you
 * are not signed in as.
 *
 * Three things are pinned here and each has a reason outside taste:
 *
 * · NO GREEN BUTTON, IN EITHER SIGNED-IN STATE. REFERENCE §6.1 reserves green for THE
 *   primary action inside the page. A green pill in the chrome is on screen beside every
 *   page's own call to action and wins, so the page's real action becomes the second
 *   loudest thing on it — on every page. The phase file lists this as a Done-when line;
 *   the old header had `.ag-vote` in exactly that spot.
 *
 * · EVERY DESTINATION IN THE PANELS IS A REAL ROUTE. The panels are the only way into
 *   most of this site from most of it, and `SiteLinkIntegrityTest` sweeps hrefs across
 *   all templates — but the header's are written as a Twig data list, which is the form
 *   a literal-href sweep cannot see. `PublicResultsTest` had exactly that blind spot and
 *   it was found by breaking it.
 *
 * · THE SETTINGS PANEL IS THE SHARED PARTIAL. Four surfaces now offer Display & reading:
 *   this popover, the phone Quick settings sheet, the Menu's sub-view and the full
 *   block. Three of them include one file. A fourth copy of seven switches is how the
 *   halves of a setting come to disagree about whether it is on, and nothing on any
 *   screen shows the disagreement until somebody opens two of them.
 */
final class SiteHeaderTest extends TestCase
{
    /**
     * The header, which is a PARTIAL and no longer the legacy layout's whole chrome.
     *
     * It was `layout/nav.twig`, and that file was two things at once: the desktop
     * header and the phone's tab bar, Menu sheet and language prompt. The moment a
     * page on the redesign shell wanted a header, the bundle came with it — a tab bar
     * on a flow page, and a second `data-ag-menu-sheet` beside the one the shell
     * already mounts, where the opener finds the first and the Menu appears dead.
     */
    private const NAV = __DIR__ . '/../../templates/partials/site-header.twig';

    private function nav(): string
    {
        return (string) file_get_contents(self::NAV);
    }

    /** The template with `{# … #}` removed — a comment reaches nobody. */
    private function code(): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $this->nav());
    }

    public function test_there_is_no_green_button_in_either_signed_in_state(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/components.css');

        // Both controls that sit in that slot, read out of the stylesheet rather than
        // eyeballed: the avatar is ink, the signed-out pill is an ink OUTLINE.
        preg_match('/\.ag-head__signin\{([^}]*)\}/', $css, $signin);
        $this->assertNotEmpty($signin, 'the signed-out control has no rule');
        $this->assertStringNotContainsString('--ag-green', $signin[1],
            'the signed-out control is green; §6.1 keeps green for the primary action INSIDE the page');
        $this->assertStringContainsString('border:1px solid var(--ag-ink)', $signin[1],
            'the signed-out control must be an outlined pill, not a fill');

        preg_match('/\.ag-head__av\{([^}]*)\}/', $css, $av);
        $this->assertNotEmpty($av);
        $this->assertStringNotContainsString('--ag-green', $av[1]);

        // And the old green pill is gone rather than merely unlinked.
        $this->assertStringNotContainsString('ag-vote', $this->code());
    }

    public function test_the_bar_carries_two_top_links_and_no_more(): void
    {
        // §18.5 rejects a bar carrying search, Aa, language, cart, account and half a
        // dozen section links. Two panels is the whole of the primary navigation.
        //
        // COUNTED FROM THE DATA LIST, not from the attribute: the markup declares
        // `data-ag-mega-trigger` ONCE inside a loop that renders it twice, so counting
        // the attribute answers 1 — and the inline controller mentions it twice more as
        // a selector, which is how the naive count answered 3. A sweep that cannot tell
        // a declaration from a selector is the shape DeadTokenTest was built around.
        preg_match_all("/\\{k:'(\\w+)', label:'([^']+)'/", $this->code(), $m);

        $this->assertSame(['Participate', 'Explore'], $m[2],
            'the header has grown, lost or renamed a top-level panel');
    }

    public function test_every_destination_in_the_panels_is_a_real_page(): void
    {
        // Read from the Twig data list, which is what the header actually loops over.
        // A sweep matching `href="/x"` finds none of these — see the class docblock.
        preg_match_all("/href:'([^']+)'/", $this->code(), $m);
        $this->assertGreaterThanOrEqual(12, count($m[1]), 'the panels lost most of their items');

        $routes = (string) file_get_contents(__DIR__ . '/../../src/routes.php');
        $missing = [];
        foreach (array_unique($m[1]) as $href) {
            $path = '/' . trim($href, '/');
            // Declared relative to a group, so `/shop/cart` is `'/cart'` inside `/shop`.
            $last = '/' . substr($path, (int) strrpos($path, '/') + 1);
            if (!str_contains($routes, "'" . $path . "'") && !str_contains($routes, "'" . $last . "'")) {
                $missing[] = $href;
            }
        }

        $this->assertSame([], $missing,
            'the header points at ' . implode(', ', $missing) . ' and nothing serves them');
    }

    public function test_results_is_reachable_by_browsing(): void
    {
        // Not in either of §7.1's lists, and in the header anyway. `PublicResultsTest`
        // is the reason: the page was built, the Pulse and the emails linked it, and
        // nobody browsing the site could reach a decided award. See GAPS.md §9.8.
        $this->assertStringContainsString("href:'/results'", $this->code());
    }

    public function test_the_toolbar_holds_exactly_search_display_and_language(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('role="toolbar"', $code);
        $this->assertSame(3, substr_count($code, 'ag-tools__b'),
            'the toolbar pill is search, Aa and language — a fourth control belongs in a panel');
        $this->assertStringContainsString('data-ag-search-open', $code);
        $this->assertStringContainsString('data-ag-pop="aa"', $code);
        $this->assertStringContainsString('data-ag-pop="lang"', $code);

        // `role="toolbar"` is a claim about arrow-key navigation. A toolbar that only
        // answers Tab tells a screen-reader user to press keys that do nothing.
        $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/header.js');
        $this->assertStringContainsString('ArrowRight', $js);
        $this->assertStringContainsString("'[role=\"toolbar\"]'", $js);
    }

    public function test_the_display_popover_is_the_shared_partial_and_not_a_fourth_copy(): void
    {
        $this->assertStringContainsString("include 'partials/display-reading.twig'", $this->code());
        // Nothing in the header may spell a switch itself.
        $this->assertStringNotContainsString('data-ag-toggle=', $this->code());
    }

    public function test_the_language_menu_works_without_javascript(): void
    {
        $code = $this->code();

        // Links, so the middleware stores the choice on a plain navigation.
        $this->assertMatchesRegularExpression(
            '/<a class="ag-pop__lang"[^>]*href="\{\{ lang_url\(l\.code\) \}\}"/',
            $code
        );
        $this->assertStringNotContainsString('data-ag-pick-lang', $code);
    }

    public function test_each_sheet_is_mounted_by_the_layout_that_can_open_it(): void
    {
        $code  = $this->code();
        $shell = (string) preg_replace('/\{#.*?#\}/s', '',
            (string) file_get_contents(__DIR__ . '/../../templates/layout/shell.twig'));

        // The Menu is on both layouts: the tab bar opens it and both draw one. It lives
        // in the LEGACY CHROME file rather than the header partial, because the header
        // is tablet-and-desktop and the Menu is the phone's.
        $legacy = (string) preg_replace('/\{#.*?#\}/s', '',
            (string) file_get_contents(__DIR__ . '/../../templates/layout/nav.twig'));

        $this->assertSame(1, substr_count($legacy, 'partials/menu-sheet.twig'),
            'the Menu is mounted twice by the old layout');
        $this->assertSame(1, substr_count($shell, 'partials/menu-sheet.twig'),
            'the Menu is mounted twice by the shell');

        // Quick settings is on the SHELL ONLY, and that is the §18 rule rather than a
        // tidy-up: its one trigger is the phone app bar's avatar, and the old layout has
        // no app bar. Mounting it there put a dialog in ~180 pages that nothing on any of
        // them could open — every part complete except the way in. `ChromeReachabilityTest`
        // is the general form; this pins the instance that shipped.
        $this->assertSame(0, substr_count($legacy, 'partials/quick-settings.twig'),
            'the old layout mounts Quick settings and has no app bar to open it from');
        $this->assertSame(1, substr_count($shell, 'partials/quick-settings.twig'));

        // A converted page extends the shell and does not include nav.twig, so nothing
        // gets two Menus — two dialogs with one `data-ag-menu-sheet` between them means
        // the opener finds the first and the tab bar's button appears dead.
        $this->assertStringContainsString('partials/tab-bar.twig', $legacy);
    }

    public function test_no_id_appears_twice_in_one_document(): void
    {
        // ══════════════════════════════════════════════════════════════════════
        // THE FAULT IS A DUPLICATE ID, NOT A DUPLICATE MOUNT
        // ══════════════════════════════════════════════════════════════════════
        //
        // `getElementById` answers with the FIRST match, and a `for=` or an
        // `aria-labelledby` resolves the same way. So two copies of one id in a
        // document means every control the second copy wired belongs to the first —
        // silently. Measured twice in this work:
        //
        //   · the search palette, mounted by both the header and the legacy layout,
        //     put two `id="agsInput"` in the home page and stopped responding;
        //   · `display-reading.twig` is mounted by the header's Aa popover AND the
        //     Menu's sub-view, so pressing "Language" in the Menu moved focus into a
        //     panel that was not open.
        //
        // The second of those is a partial that SHOULD be mounted twice — three
        // surfaces share one set of switches on purpose, and splitting them is how
        // the halves of a setting come to disagree. What it may not do is carry a
        // fixed id, so it takes a `uid`. That is why this counts IDS and not mounts:
        // a mount rule would have forced the wrong fix.
        foreach (['layout/gates.twig', 'layout/shell.twig'] as $layout) {
            $ids = [];
            $this->collectIds($layout, $ids);

            $twice = [];
            foreach ($ids as $id => $n) if ($n > 1) $twice[] = $id . ' ×' . $n;

            $this->assertSame([], $twice,
                $layout . ' renders these ids more than once, so `getElementById`, every '
                . '`for=` and every `aria-labelledby` resolves to the first: ' . implode(', ', $twice));
        }
    }

    /**
     * Every literal `id="..."` a layout reaches, counted, following includes down.
     *
     * An id carrying `{{ }}` is skipped: it is per-mount by construction, which is the
     * fix this test asks for, and counting the template text would report it as a
     * duplicate of itself.
     *
     * @param array<string,int> $ids
     */
    private function collectIds(string $path, array &$ids, int $depth = 0): void
    {
        if ($depth > 12) return;
        $file = __DIR__ . '/../../templates/' . $path;
        if (!is_file($file)) return;

        $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));

        preg_match_all('/\bid="([^"]+)"/', $body, $m);
        foreach ($m[1] as $id) {
            if (str_contains($id, '{{')) continue;
            $ids[$id] = ($ids[$id] ?? 0) + 1;
        }

        preg_match_all("/\\{%-? *include '([^']+)'/", $body, $inc);
        foreach ($inc[1] as $f) $this->collectIds($f, $ids, $depth + 1);
    }

    public function test_a_shell_page_never_pulls_in_the_legacy_chrome_bundle(): void
    {
        // `layout/shell.twig` mounts the Menu and Quick settings itself. A page on it
        // that also includes `layout/nav.twig` gets the phone bundle a second time —
        // and a tab bar on a flow page, which §7.3 says has none.
        $bad = [];
        foreach (glob(__DIR__ . '/../../templates/pages/*.twig') ?: [] as $f) {
            $body = (string) file_get_contents($f);
            if (!str_contains($body, "extends 'layout/shell.twig'")) continue;
            if (str_contains($body, "include 'layout/nav.twig'")) $bad[] = basename($f);
        }

        $this->assertSame([], $bad,
            'these shell pages include the legacy chrome bundle, which mounts a second '
            . 'Menu sheet over the one the shell already drew: ' . implode(', ', $bad));
    }

    public function test_the_full_screen_overlay_menu_is_gone(): void
    {
        $code = $this->code();

        // A full-screen menu PAGE is in the anti-pattern list: it takes a navigation
        // step to leave and loses where you were. It is a bottom sheet now.
        $this->assertStringNotContainsString('id="agMenu"', $code);
        $this->assertStringNotContainsString('ag-mobnav', $code);
        $this->assertStringNotContainsString('ag-menu__group', $code);

        // And its stylesheet went with it, rather than staying to restyle the new sheet:
        // the old `.ag-menu` rule was `position:fixed; inset:0`, and the new one is a
        // sheet from `top:52px` — same class, opposite component, nothing in either file
        // to hint at the collision.
        $this->assertFileDoesNotExist(__DIR__ . '/../../public/assets/css/components/nav.css');
        $this->assertFileExists(__DIR__ . '/../../public/assets/css/components/vote-countdown.css',
            'the countdown rules lived in that file and must not have gone with it');
    }

    public function test_the_hairline_is_dropped_while_a_panel_is_open(): void
    {
        // The panel hangs from the bar with no gap (§7.1), so a rule between them cuts
        // it in half. Two files have to agree for this: the controller sets the class
        // and the stylesheet acts on it.
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/components.css');
        $this->assertStringContainsString('.ag-head.is-mega .ag-head__bar', $css);
        $this->assertStringContainsString("classList.toggle('is-mega'", $this->nav());
    }
}
