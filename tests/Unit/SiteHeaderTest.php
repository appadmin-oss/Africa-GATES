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
    private const NAV = __DIR__ . '/../../templates/layout/nav.twig';

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

    public function test_the_phone_chrome_is_included_once_here_and_once_in_the_shell(): void
    {
        $code  = $this->code();
        $shell = (string) preg_replace('/\{#.*?#\}/s', '',
            (string) file_get_contents(__DIR__ . '/../../templates/layout/shell.twig'));

        foreach (['partials/menu-sheet.twig', 'partials/quick-settings.twig'] as $p) {
            $this->assertSame(1, substr_count($code, $p), "$p is included twice by the old layout");
            $this->assertSame(1, substr_count($shell, $p), "$p is included twice by the shell");
        }

        // A converted page extends the shell and does not include nav.twig, so nothing
        // gets two Menus — two dialogs with one `data-ag-menu-sheet` between them means
        // the opener finds the first and the tab bar's button appears dead.
        $this->assertStringContainsString('partials/tab-bar.twig', $code);
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
