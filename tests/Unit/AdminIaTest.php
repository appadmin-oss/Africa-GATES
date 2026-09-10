<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Support\AdminNav;
use Tests\TestCase;

/**
 * CAN AN ADMINISTRATOR ACTUALLY FIND EVERY PAGE THIS PLATFORM BUILT FOR THEM?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SECOND FILE AND NOT MORE OF AdminNavTest
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see AdminNavTest} holds the navigation's own invariants and holds them well: every
 * entry points at a registered route, no page appears twice, every item has a sprite
 * icon, no page crossed a permission gate during the restructure, the palette is
 * generated from the same tree.
 *
 * Every one of those reads NAV → ROUTES. Not one reads ROUTES → NAV.
 *
 * And that is the exact direction {@see AdminNav}'s own docblock says the class was
 * created to fix: "A new page could be built, routed and permissioned and still not
 * appear in the nav, because adding it there was a separate manual step nothing checked."
 * Moving the links into a class made the tree greppable; it did not make anything check
 * that the tree is complete. So the fault the class was built to prevent was still live,
 * and it had already happened again.
 *
 * `/admin/settings/providers` — "the page that asks every provider a real question", in
 * its own docblock — was a full admin page, routed, declaring `admin_page: 'settings'` so
 * the rail highlighted Settings while an operator stood on it, and NOTHING linked to it.
 * Not the nav, not the Settings page, and therefore not the Cmd+K palette either, which
 * is generated from the nav. It could be reached only by typing the URL.
 *
 * The page it happened to is the point. It exists because `AiCapability::$timeout` was
 * never read, every summary ran on a 6s default, and the status page read "0% answering"
 * for weeks with nothing to say which thing had broken. The one screen built to answer
 * that question could not be found by the person asking it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE QUESTION THIS FILE ASKS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Not "is every nav entry real?" but **is every admin page findable without knowing its
 * URL?** A page is findable if it is in the rail, or if a template links to it. Anything
 * else is furniture in a locked room.
 */
final class AdminIaTest extends TestCase
{
    /**
     * The admin route group's line span in `src/routes.php`.
     *
     * Located rather than hard-coded, and that is not fussiness: `$a` is the proxy
     * variable for the API group and the `/account` group as well as this one, so a naive
     * sweep for `$a->get(` reports `/api/v1/registry` as an unreachable admin page. The
     * first cut of this test did exactly that and produced thirteen false findings.
     *
     * @return array{0:int, 1:int}
     */
    private function adminGroupSpan(string $src): array
    {
        $lines = explode("\n", $src);
        $start = $end = -1;

        foreach ($lines as $i => $line) {
            if ($start < 0 && str_contains($line, "\$app->group('/admin'")) { $start = $i; continue; }
            // The group closes at the outer `})` that carries its middleware.
            if ($start >= 0 && preg_match('~^\s{0,5}\}\)\s*->add\(~', $line)) { $end = $i; break; }
        }

        $this->assertGreaterThan(-1, $start, "the /admin group must be findable in routes.php");
        $this->assertGreaterThan($start, $end, 'and its closing brace must be findable');

        return [$start, $end];
    }

    /**
     * Every GET destination inside the admin group, with its path resolved through any
     * nested group it sits in.
     *
     * Paths carrying a `{parameter}` are excluded: a nominee's detail page is reached from
     * a list and has no business in a rail. Everything without one is a destination
     * somebody has to be able to get to.
     *
     * @return array<string,string> path => handler
     */
    private function destinations(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        [$from, $to] = $this->adminGroupSpan($src);
        $lines = array_slice(explode("\n", $src), $from, $to - $from);

        $out = [];
        $group = null;

        foreach ($lines as $line) {
            if (preg_match("~\\\$a->group\('([^']+)'~", $line, $m)) { $group = $m[1]; continue; }
            // A nested group closes at its own indentation, which is deeper than the
            // admin group's own closing brace.
            if ($group !== null && preg_match('~^\s{8}\}\)~', $line)) { $group = null; }

            if (!preg_match("~\\\$([as])->get\s*\(\s*'([^']*)'\s*,\s*(.+)$~", $line, $m)) continue;
            [, $var, $path, $handler] = $m;
            if (str_contains($path, '{')) continue;

            $full = '/admin' . (($var === 's' && $group !== null) ? $group : '') . $path;
            $out[$full] = trim(preg_replace('~\s+~', ' ', rtrim($handler, " );\t")) ?? '');
        }

        return $out;
    }

    /**
     * Destinations that are deliberately not places to navigate to.
     *
     * Every entry is a KIND with a reason, not a list of pages somebody could not be
     * bothered to link. A path landing here because it matches a pattern is a path this
     * test is not asking about; a real page added under one of these prefixes by accident
     * would be excused, which is why the patterns are narrow and the reasons are written
     * down.
     *
     * @return array<string,string> pattern => why
     */
    private const NOT_DESTINATIONS = [
        // Signing in cannot require being signed in.
        '~^/admin/(login|logout|magic|magic/consume)$~' => 'authentication',
        // A create form belongs to the list it is reached from, never to the rail. Putting
        // "New event" beside "Events" doubles the rail and says nothing.
        '~/new$~' => 'a create form, reached from its own index',
        // A file, not a page. There is nothing to navigate to and nothing to come back to.
        '~(\.csv|\.zip|/export)$~' => 'a download',
        // An HTML fragment or a JSON body for something already on screen.
        '~/(next|count|feed|alerts)$~' => 'a fragment or a poll endpoint',
        // `/admin` and `/admin/dashboard` are the same screen; the rail names one of them.
        '~^/admin\[?/?\]?$~' => 'the dashboard, which the rail reaches as /admin/dashboard',
    ];

    private function excuse(string $path): ?string
    {
        foreach (self::NOT_DESTINATIONS as $pattern => $why) {
            if (preg_match($pattern, $path)) return $why;
        }
        return null;
    }

    // ══ the sweep ════════════════════════════════════════════════════════════

    /**
     * EVERY ADMIN PAGE IS EITHER IN THE RAIL OR LINKED FROM A TEMPLATE.
     *
     * The assertion `AdminNavTest` has no equivalent of, in the direction that actually
     * loses pages. A page nothing links and the rail does not carry is a page an operator
     * finds by accident or never.
     *
     * Linked "from a template" is deliberately generous rather than "linked from a page
     * that is itself in the rail": sub-pages legitimately chain (shop orders → codes →
     * shipping), and a stricter rule would either force every leaf into the rail — which
     * is the twelve-accordion mistake `AdminNav` already measured and rejected — or need
     * a link graph, which would fail on the first link built by JavaScript.
     */
    public function test_every_admin_page_is_reachable_without_typing_a_url(): void
    {
        $root = dirname(__DIR__, 2);

        $nav = [];
        foreach (AdminNav::sections() as $s) {
            foreach ($s['items'] as $i) $nav[] = $i['href'];
        }

        // Everything a template or the admin's own script could link.
        $markup = '';
        foreach (new \RecursiveIteratorIterator(
                     new \RecursiveDirectoryIterator($root . '/templates')) as $f) {
            if ($f->isFile() && $f->getExtension() === 'twig') {
                $markup .= (string) file_get_contents($f->getPathname());
            }
        }
        $markup .= (string) file_get_contents($root . '/public/assets/js/admin.js');

        $unreachable = [];
        foreach ($this->destinations() as $path => $handler) {
            if ($this->excuse($path) !== null) continue;
            if (in_array($path, $nav, true)) continue;

            // A quote or a boundary either side, so `/admin/shop` does not count itself
            // reachable because `/admin/shop/orders` appears somewhere.
            if (preg_match('~["\']' . preg_quote($path, '~') . '["\'?#]~', $markup)) continue;

            $unreachable[$path] = $handler;
        }

        $this->assertSame([], $unreachable,
            "these admin pages can only be reached by typing the URL — put each one in "
            . "AdminNav, link it from the page it belongs under, or add its KIND to "
            . "NOT_DESTINATIONS with the reason:\n  "
            . implode("\n  ", array_map(
                static fn (string $p, string $h): string => $p . '  ' . $h,
                array_keys($unreachable), $unreachable)));
    }

    /**
     * AND THE INTEGRATIONS PAGE SPECIFICALLY, BECAUSE IT IS THE ONE THAT WAS LOST.
     *
     * Named rather than left to the sweep above. The sweep is the general rule and would
     * catch a regression, but this says WHICH page and WHY it matters — an operator asking
     * "is the email actually going out, is the AI answering, is the gateway up" needs one
     * screen, and for as long as that screen was unlinked the honest answer to every one
     * of those questions was unavailable to the person who needed it.
     */
    public function test_the_integrations_page_is_offered_from_settings(): void
    {
        $settings = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        $this->assertStringContainsString('href="/admin/settings/providers"', $settings,
            'Settings must offer the page that checks whether any of it works');
    }

    // ══ and the shape of the structure itself ════════════════════════════════

    /**
     * A SECTION AN OPERATOR CANNOT SCAN IS A SECTION THEY WILL SEARCH INSTEAD.
     *
     * `AdminNav` argues from NN/g's finding that hidden navigation roughly halves
     * discoverability, and resolves it with a hybrid: seven visible headings, the tail in
     * a sub-nav and a palette. That is sound, and it has an upper bound nobody wrote down
     * — a heading with twenty items under it is a list, not a grouping, and the sub-nav
     * that repeats it inside the page becomes the thing it was meant to relieve.
     *
     * Twelve is the ceiling here: the largest section today is eleven, so this leaves room
     * for one more without a decision and asks for one when the twelfth arrives. It is a
     * prompt to split or re-gate rather than a law about the number.
     */
    public function test_no_section_grows_past_scanning(): void
    {
        foreach (AdminNav::sections() as $s) {
            $this->assertLessThanOrEqual(12, count($s['items']),
                sprintf('"%s" has %d items. Past about a dozen a heading stops being a '
                      . 'grouping and becomes a list — split it, or move something to the '
                      . 'gate it actually belongs to.', $s['label'], count($s['items'])));
        }
    }

    /**
     * EVERY SECTION'S GATE IS A REAL PERMISSION.
     *
     * `AdminNavTest` asserts no page moved gate during the restructure, against a recorded
     * snapshot of the old mapping. That is a claim about one event. This is the standing
     * one: a section gated on a string `Permissions::MATRIX` does not define would be
     * either invisible to everybody or visible to everybody, depending on how the check
     * treats an unknown key, and both are silent.
     */
    public function test_every_section_gate_is_a_permission_the_model_defines(): void
    {
        // `MATRIX` is the permission model: section => the roles that may reach it.
        $known = array_keys(\AfricaGates\Admin\Support\Permissions::MATRIX);
        $this->assertNotEmpty($known, 'the permission model must define its sections');

        foreach (AdminNav::sections() as $s) {
            if ($s['gate'] === null) continue;      // always visible, deliberately
            $this->assertContains($s['gate'], $known,
                sprintf('"%s" is gated on "%s", which Permissions::MATRIX does not define',
                        $s['label'], $s['gate']));
        }
    }

    // ══ terminology: does the page call itself what the rail called it? ══════

    /**
     * THE RAIL AND THE PAGE AGREE ON WHAT A SCREEN IS CALLED.
     *
     * {@see AdminOneHeaderTest} holds "the console tells you where you are exactly once".
     * This is the other half of the same promise: told once, and told the SAME THING. An
     * operator who presses "Revenue" and lands on a page headed "Finance" has to work out
     * whether those are one screen or two, and the answer is not on the page.
     *
     * Two real disagreements, both since fixed: the rail said "Revenue" over a page titled
     * "Finance", and "Partner Orgs" over one titled "Partner organisations".
     *
     * Compared case-insensitively and ignoring `&`/`&amp;`, because the casing convention
     * is a separate rule with its own test below — one property per assertion, or a
     * failure does not say which thing broke.
     *
     * A page whose title is a Twig expression is skipped rather than guessed at: the
     * dashboard greets the administrator by name and the profile screen is headed by the
     * profile's own name, and neither is trying to be a section label.
     */
    public function test_the_rail_and_the_page_agree_on_a_screens_name(): void
    {
        $root = dirname(__DIR__, 2);

        $norm = static fn (string $v): string => strtolower(trim(str_replace(
            ['&amp;', '  '], ['&', ' '], preg_replace('~\s+~', ' ', $v) ?? '')));

        $wrong = [];
        foreach (AdminNav::sections() as $sec) {
            foreach ($sec['items'] as $item) {
                $tpl = $this->templateFor($item['href']);
                if ($tpl === null || !is_file($root . '/templates/' . $tpl)) continue;

                $body = (string) file_get_contents($root . '/templates/' . $tpl);
                if (!preg_match('~\{%\s*block topbar_title\s*%\}(.*?)\{%\s*endblock~s', $body, $m)) {
                    continue;                       // the layout's default; nothing claimed
                }
                $title = trim($m[1]);
                if (str_contains($title, '{{')) continue;   // a name, not a section label

                if ($norm($title) !== $norm($item['label'])) {
                    $wrong[] = sprintf('%s: rail says "%s", %s says "%s"',
                                       $item['page'], $item['label'], $tpl, $title);
                }
            }
        }

        $this->assertSame([], $wrong,
            "the rail and the page disagree about what these screens are called:\n  "
            . implode("\n  ", $wrong));
    }

    /**
     * The template a rail entry actually opens, resolved through its ROUTE.
     *
     * The obvious way — scan every controller for a render call whose payload declares
     * this `admin_page` — is wrong, and wrong in a way that produced a false failure the
     * first time. Several templates legitimately declare the same key so the rail
     * highlights the right section from a SUB-page: `scorecard.twig` declares
     * `result-release`, the profile detail screen declares `profiles`. First-match-in-file-
     * order then compares a section label against a sub-page's title and reports a
     * disagreement that is not one.
     *
     * So the href is followed instead. The route table names a controller and a method,
     * and the render call inside THAT method is the page the rail opens. One answer, and
     * it is the right one by construction rather than by luck.
     */
    private function templateFor(string $href): ?string
    {
        $handler = $this->destinations()[$href] ?? null;
        if ($handler === null) return null;

        // `Foo\BarController::class.':method'` — take the short class name and the method.
        if (!preg_match("~([A-Za-z]+Controller)::class\s*\.\s*':([A-Za-z]+)'~", $handler, $m)) {
            return null;
        }
        [, $alias, $method] = $m;

        // Aliases in routes.php are `SettingsController as AdminSettingsController`.
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $class  = $alias;
        if (preg_match('~([A-Za-z]+Controller)\s+as\s+' . preg_quote($alias, '~') . '~', $routes, $am)) {
            $class = $am[1];
        }

        $file = dirname(__DIR__, 2) . '/src/Admin/Controllers/' . $class . '.php';
        if (!is_file($file)) return null;

        $body = (string) file_get_contents($file);
        $at   = strpos($body, 'function ' . $method . '(');
        if ($at === false) return null;

        // From the method's start to the next method declaration, so a render call in a
        // later method cannot be attributed to this one.
        $next = strpos($body, "\n    public function ", $at + 1);
        $slice = substr($body, $at, $next === false ? null : $next - $at);

        return preg_match("~'(admin/[a-z0-9/_-]+\.twig)'~", $slice, $tm) ? $tm[1] : null;
    }

    /**
     * ONE CASING CONVENTION IN THE RAIL, WHICH IS SENTENCE CASE.
     *
     * The rail carried both, 32 labels to 18 — "Judging Audit" directly above "Judging
     * rubric", "Shop Orders" beside "Shop products". Neither convention is better; having
     * two is what makes a list of fifty look assembled by different people, and it is the
     * cheapest thing on a console to get consistently right.
     *
     * Sentence case, because it was already the majority and because it matches the page
     * titles. Proper nouns are exempt by construction — only words AFTER the first are
     * checked, and a genuine proper noun there (a partner's name, a product) is a real
     * exception to add here with its reason rather than a rule to abandon.
     */
    public function test_every_rail_label_uses_one_casing_convention(): void
    {
        // Words that are capitalised because they are names or initialisms, not because
        // somebody Title Cased a phrase.
        $proper = ['AI', 'GATES', 'Africa'];

        $shouty = [];
        foreach (AdminNav::sections() as $sec) {
            foreach ($sec['items'] as $item) {
                $words = array_slice(explode(' ', $item['label']), 1);
                foreach ($words as $w) {
                    $w = trim($w, '&');
                    if ($w === '' || in_array($w, $proper, true)) continue;
                    if (ctype_upper($w[0])) {
                        $shouty[] = $item['label'] . '  (' . $w . ')';
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $shouty,
            "the rail mixes sentence case and Title Case. Sentence case is the convention "
            . "here; add a genuine proper noun to \$proper with its reason instead:\n  "
            . implode("\n  ", $shouty));
    }
}
