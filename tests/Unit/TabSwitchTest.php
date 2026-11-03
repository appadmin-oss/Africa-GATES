<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Switching tabs in a page does not wait for the network (owner, 3 Nov 2026: "switching
 * tabs in a page should not need to reload").
 *
 * A tab here is a link (`?tab=…`) the server renders, so every view has an address and
 * works without a script. That stays. What changed is when it is fetched: both navigation
 * scripts fetch a page's tabs while the reader is reading, so pressing one swaps from
 * memory. Measured on a 150ms link: ~950ms a tab on the public site, ~720ms in the console,
 * and a 2.3s FULL reload on every tab of the organisation console (which navigation skipped
 * entirely) — 20–40ms for all of them after.
 *
 * What this holds:
 *   · every tab bar says it is one (`data-tabs`), or its tabs are never fetched ahead;
 *   · tabs are fetched ahead, kept for going back to, lit at once when pressed, and swapped
 *     without a cross-fade, with the bar left where it was on screen;
 *   · the organisation console is swapped but never fetched ahead from OUTSIDE it, because a
 *     GET of /org opens the organisation's session;
 *   · a page fetched ahead neither shows nor spends a one-shot message — or a background
 *     fetch would be the page that ate "Saved";
 *   · Pulse's tabs draw different pages (they drew one page four ways: `view` was never set).
 */
final class TabSwitchTest extends TestCase
{
    /** Anchors marked current whose address is a tab, in templates with no `data-tabs`. @return list<string> */
    public static function unmarked(array $files): array
    {
        $out = [];
        foreach ($files as $name => $src) {
            $src = (string) preg_replace('~\{#.*?#\}~s', '', $src);
            if (str_contains($src, 'data-tabs')) continue;
            if (preg_match_all('~<a\b[^>]*href="[^"]*[?&]tab=[^>]*aria-current~s', $src, $m)) $out[] = $name;
        }
        return $out;
    }

    public function test_the_sweep_names_an_unmarked_bar(): void
    {
        $bad  = '<nav class="x-tabs"><a href="/x?tab=a"{% if t == "a" %} aria-current="page"{% endif %}>A</a></nav>';
        $good = '<nav class="x-tabs" data-tabs="x"><a href="/x?tab=a"{% if t == "a" %} aria-current="page"{% endif %}>A</a></nav>';
        $quiet = '{# <a href="/x?tab=a" aria-current="page"> #}<a href="/x?tab=a">A</a>';
        $this->assertSame(['bad'], self::unmarked(['bad' => $bad, 'good' => $good, 'quiet' => $quiet]));
    }

    public function test_every_tab_bar_says_it_is_one(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getPathname(), '.twig')) $files[substr($f->getPathname(), strlen($root) + 1)] = (string) file_get_contents($f->getPathname());
        }
        $this->assertSame([], self::unmarked($files), 'a tab bar without data-tabs is fetched on the click, not before it');
        // The bars whose links the sweep cannot read (the address is a variable) — pinned.
        foreach (['templates/pages/account/_frame.twig' => 'data-tabs="account" data-tabs-top', 'templates/pages/org/_frame.twig' => 'data-tabs="org" data-tabs-top',
                  'templates/pages/pulse.twig' => 'data-tabs="pulse"', 'templates/pages/awards/programme.twig' => 'data-tabs="award"',
                  'templates/pages/vote-program.twig' => 'data-tabs="vote"', 'templates/admin/requests.twig' => 'data-tabs="requests"',
                  'templates/admin/volunteers.twig' => 'data-tabs="volunteers"', 'templates/admin/alerts/index.twig' => 'data-tabs="alerts"'] as $f => $needle) {
            $this->assertStringContainsString($needle, $files[$f], $f);
        }
    }

    public function test_both_navigations_fetch_tabs_ahead_and_keep_them(): void
    {
        foreach (['public/assets/js/site-nav.js', 'public/assets/js/console-nav.js'] as $f) {
            $js = ChromeRender::code($f);
            $this->assertMatchesRegularExpression("~querySelectorAll\('\[data-tabs\]'\)~", $js, "$f: tabs are not fetched ahead");
            $this->assertStringContainsString('TAB_TTL = 60000', $js, "$f: a tab must be kept long enough to go back to");
            $this->assertStringContainsString('if (!c.tab || !fresh(c)) cache.delete(k);', $js, "$f: a tab used once is fetched again to go back to it");
            $this->assertMatchesRegularExpression('~if \(frugal\(\) \|\| stuck\) return;~', $js, "$f: Save-Data and 2G must fetch nothing ahead");
            $this->assertStringContainsString("a.setAttribute('aria-current', v);", $js, "$f: the pressed tab is not lit until the page arrives");
            $this->assertStringContainsString('else if (o.tab && keepTabs(o.tab))', $js, "$f: the bar jumps when the tab changes");
            $this->assertStringContainsString('if (o.tabBar) o.tab = tabState(o.tabBar, o.tabLink);', $js);
            $this->assertMatchesRegularExpression('~if \(swapped\) warmSoon\(\);~', $js, "$f: the next page's tabs are never fetched ahead");
        }
        $site = ChromeRender::code('public/assets/js/site-nav.js');
        $this->assertStringContainsString('!still && !o.tab', $site, 'a tab cross-fades: 250ms of the old tab nobody asked to see');
        // /org is swapped, but a GET of it opens a session — never fetched from outside it.
        $this->assertDoesNotMatchRegularExpression('~SKIP = /\^\\\\/\([^)]*\borg\|~', $site, 'every organisation tab is a full reload');
        $this->assertStringContainsString('if (GATED.test(u.pathname) && !GATED.test(location.pathname)) return true;', $site);
        // The console asks "leave without saving?" first; a tab lit before a "no" stays wrong.
        $console = ChromeRender::code('public/assets/js/console-nav.js');
        $this->assertLessThan(strpos($console, 'if (o.tabBar) o.tab = tabState'), strpos($console, 'Leave without saving them?'));
        $this->assertStringContainsString("x.classList.remove('is-on')", $console, 'a filter chip is painted by is-on too');
    }

    /** @return array{0:mixed,1:bool} the flash the page would draw, and whether it is still owed */
    private function flashFor(bool $ahead): array
    {
        $_SESSION['flash_ok'] = 'Saved.';
        if ($ahead) $_SERVER['HTTP_X_SITE_PREFETCH'] = 'prefetch';
        try {
            $b = new \DI\ContainerBuilder();
            $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
            $twig = $b->build()->get(\Slim\Views\Twig::class);
            $shown = $twig->getEnvironment()->getGlobals()['flash_ok'] ?? null;
            return [$shown, isset($_SESSION['flash_ok'])];
        } finally {
            unset($_SERVER['HTTP_X_SITE_PREFETCH'], $_SESSION['flash_ok']);
        }
    }

    public function test_a_page_fetched_ahead_neither_shows_nor_spends_a_message(): void
    {
        $this->assertSame([null, true], $this->flashFor(true), 'a background fetch ate the message');
        $this->assertSame(['Saved.', false], $this->flashFor(false), 'a page somebody looks at must show it and spend it');
    }

    public function test_pulse_draws_the_tab_it_was_asked_for(): void
    {
        $uid = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Ada Bello', 'email' => 'ada-' . bin2hex(random_bytes(4)) . '@example.test',
            'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $_SESSION['user_id'] = $_SESSION['member_id'] = $_SESSION['account_id'] = $uid;
        try {
            $b = new \DI\ContainerBuilder();
            $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
            $c = $b->build();
            $html = [];
            foreach (['', 'following', 'alerts', 'saved'] as $tab) {
                $req = (new ServerRequestFactory())->createServerRequest('GET', '/pulse' . ($tab ? '?tab=' . $tab : ''));
                $res = $c->get(\AfricaGates\Controllers\PulseController::class)->index($req->withQueryParams($tab ? ['tab' => $tab] : []), new Response());
                $html[$tab] = (string) $res->getBody();
            }
        } finally {
            unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['account_id']);
        }
        $current = static function (string $h): string {
            return preg_match('~<nav class="pl-tabs"[^>]*>(.*?)</nav>~s', $h, $m) && preg_match('~href="([^"]*)" aria-current="page"~', $m[1], $c) ? $c[1] : '';
        };
        $this->assertSame('/pulse', $current($html['']), 'no Pulse tab is marked current');
        $this->assertSame('/pulse?tab=following', $current($html['following']));
        $this->assertSame('/pulse?tab=alerts', $current($html['alerts']));
        $this->assertStringContainsString('class="pl-compose"', $html[''], 'the composer is never drawn');
        $this->assertStringContainsString('pl-alerts__head', $html['alerts'], 'the alerts tab draws the feed');
        $this->assertStringNotContainsString('pl-alerts__head', $html['']);
        $this->assertStringContainsString('class="pl-title"', $html['saved'], 'the saved tab has no heading');
    }
}
