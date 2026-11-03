<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Speculative;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * In-place navigation on the public site (site-nav.js, owner 2 Nov 2026: "prevent full
 * reload"). A page is swapped only when every script of its own is known to be safe to run
 * on every visit; otherwise it loads the ordinary way. What this holds:
 *
 *   · every public page script SAYS which it is — `rerun` (safe), `once` (a third party's
 *     loader), `shell` (the layout's own) or `reload` (a full load, on purpose);
 *   · a `rerun` script binds nothing that outlives its page except through agPage: no
 *     document, window or <main> listener, no interval, no top-level const/let (a second run
 *     would throw). <main> stays across a swap, so a scroll listener on it would pile up;
 *   · the overlays that stay (menu, settings, search, shortcuts, Gee, consent) are marked,
 *     and Gee's per-page mode travels with the page rather than forcing a full load;
 *   · a POST is never sent by script: a vote or a payment is always a full navigation;
 *   · a swapped page is counted as a visit, and its prefetch is not (and writes nothing).
 *
 * The sweep's detector is run over planted text first, so it is seen to name a break.
 */
final class SiteNavTest extends TestCase
{
    private const SKIP = '~^templates/(admin|judge|emails|shell/|volunteer|layout/(admin|auth|console|shell)|partials/(gee|csrf-fresh|instant-nav|a11y-head|seo-head))|^templates/pages/(dev-ui|events/door)\.twig$~';

    /** What a rerun-marked script must not do. @return list<string> */
    public static function offences(string $js): array
    {
        $out = [];
        foreach (preg_split('/\R/', $js) as $n => $line) {
            if (str_contains($line, 'window.agPage ||')) continue;          // the fallback itself
            $t = trim($line);
            if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
            if (preg_match("~\b(document|window|document\.body|document\.documentElement)\.addEventListener\(\s*(?!['\"]DOMContentLoaded)~", $line)) $out[] = ($n + 1) . ': a page-wide listener';
            if (preg_match('~(?<![\w.])main\.addEventListener\(~', $line)) $out[] = ($n + 1) . ': a listener on <main>, which outlives the page';
            if (preg_match('~(?<![\w.])setInterval\(~', $line)) $out[] = ($n + 1) . ': an interval';
            if (preg_match('~^(const|let) ~', $line)) $out[] = ($n + 1) . ': a top-level const/let';
        }
        return $out;
    }

    public function test_the_detector_names_what_it_exists_to_stop(): void
    {
        $bad = "(function(){\n  document.addEventListener('keydown', go);\n  main.addEventListener('scroll', f);\n  setInterval(tick, 1000);\n})();\nconst x = 1;\n";
        $this->assertCount(4, self::offences($bad));
        $ok = "(function(){\n  var pg = window.agPage || { every: function (f, ms) { return setInterval(f, ms); } };\n  pg.on(document, 'keydown', go);\n  document.addEventListener('DOMContentLoaded', boot);\n  el.addEventListener('click', f);\n  // window.addEventListener is described here\n})();\n";
        $this->assertSame([], self::offences($ok));
    }

    public function test_every_public_page_script_says_whether_it_may_run_again_and_rerun_ones_can(): void
    {
        $root = dirname(__DIR__, 2);
        $unmarked = $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if (!str_ends_with($rel, '.twig') || preg_match(self::SKIP, $rel)) continue;
            $src = (string) preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents($f->getPathname()));
            preg_match_all('~<script\b([^>]*)>~', $src, $m);
            foreach ($m[1] as $attrs) {
                if (preg_match('~type="(application/ld\+json|speculationrules|application/json|text/template)"~', $attrs)) continue;
                if (!preg_match('~data-nav="(rerun|once|shell|reload)"~', $attrs, $nav)) { $unmarked[] = "$rel: <script$attrs>"; continue; }
                if ($nav[1] === 'rerun' && preg_match("~asset\('(/assets/js/[^']+)'\)~", $attrs, $s)) {
                    foreach (self::offences((string) file_get_contents($root . '/public' . $s[1])) as $o) $bad[] = $s[1] . ':' . $o;
                }
            }
        }
        $this->assertSame([], $unmarked, 'a page script that does not say whether it may run again turns in-place navigation off for its page');
        $this->assertSame([], array_values(array_unique($bad)), 'a script marked rerun binds something that outlives its page — route it through agPage');
    }

    public function test_the_shell_its_overlays_and_its_scripts_are_marked(): void
    {
        $shell = ChromeRender::source('templates/layout/shell.twig');
        $this->assertMatchesRegularExpression('~<script nonce="\{\{ csp_nonce \}\}">window\.agPage=\{.*_reset:~', $shell, 'agPage must exist before any page script');
        $this->assertLessThan(strpos($shell, '</head>'), strpos($shell, 'window.agPage='));
        $this->assertStringContainsString("asset('/assets/js/site-nav.js') }}\" data-nav=\"shell\"", $shell);
        $this->assertStringContainsString('data-ag-layout="shell"', $shell);
        foreach (['shell', 'a11y', 'chrome', 'menu-sheet', 'header', 'search', 'consent', 'form-validate', 'gee'] as $js) {
            $this->assertMatchesRegularExpression("~asset\('/assets/js/$js\.js'\) \}\}\" data-nav=\"shell\"~", $shell, "$js.js would be run again on every page");
        }
        foreach (['menu-sheet' => ['menu-scrim', 'menu'], 'quick-settings' => ['quick-scrim', 'quick'], 'site-search' => ['search-scrim', 'search'], 'shortcuts' => ['kb-scrim', 'kb'], 'cookie-consent' => ['consent']] as $p => $keys) {
            foreach ($keys as $k) $this->assertStringContainsString('data-nav-keep="' . $k . '"', ChromeRender::source("templates/partials/$p.twig"), "$p is thrown away on every page");
        }
        $this->assertStringContainsString('data-nav-keep="gee" data-nav-sync="data-gee-default"', ChromeRender::source('templates/partials/gee.twig'), 'every move between the help pages and the site would be a full load');
    }

    /**
     * An element with a view-transition-name is a backdrop root: a backdrop-filter INSIDE it
     * blurs only what is inside it. Named all the time, the header's frosted bar and the mega
     * panel hanging from it drew as a flat tint — measured, a stripe pattern behind them came
     * through at full contrast (owner, 3 Nov 2026). So the chrome is named only while a
     * transition is running, and no element that holds a blurred descendant is named at rest.
     */
    public function test_naming_the_chrome_for_a_transition_does_not_cost_its_blur(): void
    {
        $root = dirname(__DIR__, 2) . '/public/assets/css';
        $named = $blurred = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (!str_ends_with($path, '.css') || preg_match('~/(vendor|min|dist)/~', $path)) continue;
            $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($path));
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER);
            foreach ($m as [, $sel, $body]) {
                $sel = trim($sel);
                if (preg_match('~view-transition-name\s*:\s*(?!none)~', $body) && !str_contains($sel, ':active-view-transition')) {
                    foreach (preg_split('~\s*,\s*~', $sel) as $one) if (preg_match('~^\.([\w-]+)$~', $one, $c)) $named[] = $c[1];
                }
                if (preg_match('~(?<!-)backdrop-filter\s*:\s*(?!none)~', $body) && preg_match('~^\.([\w-]+?)(?:__|--|\b)~', $sel, $c)) $blurred[] = trim($sel);
            }
        }
        // A named block whose own descendants (BEM children or children of its partials) blur.
        $bad = [];
        foreach (array_unique($named) as $block) {
            foreach ($blurred as $sel) if (str_starts_with($sel, '.' . $block . '__') || str_starts_with($sel, '.' . $block . ' ')) $bad[] = "$block holds $sel";
        }
        $this->assertSame([], $bad, 'a backdrop-filter inside an element named at rest blurs nothing');
        $shell = (string) file_get_contents($root . '/shell.css');
        foreach (['ag-head', 'ag-appbar', 'ag-tabbar'] as $b) {
            $this->assertStringContainsString("html:active-view-transition .$b{ view-transition-name:$b }", $shell, "$b must still be carried across a swap");
        }
        // The mega panel is not a BEM child of the header, so the sweep cannot see it: pinned.
        $this->assertDoesNotMatchRegularExpression('~^\s*\.ag-head\s*\{\s*view-transition-name~m', $shell, 'the mega panel and the header bar lose their blur');
    }

    public function test_a_post_is_never_sent_by_script_and_the_refusals_match_the_prefetch_list(): void
    {
        $js = ChromeRender::code('public/assets/js/site-nav.js');
        $this->assertMatchesRegularExpression("~if \(method !== 'get'\) return;~", $js, 'a vote or a payment must always be a full navigation');
        $this->assertStringNotContainsString("method: 'POST'", $js);
        foreach (['logout', 'account\/(login', 'claim', 'email', 'ads\/go', 'vote\/verify', 'n\/confirm', 'nominee\/start', 'admin', 'api'] as $needle) {
            $this->assertStringContainsString($needle, $js, "site-nav fetches a path whose GET does something: $needle");
        }
        $this->assertStringContainsString("headers['X-Site-Prefetch'] = 'prefetch'", $js);
        $this->assertStringContainsString("'X-Site-Nav': '1'", $js);
    }

    public function test_a_swap_is_a_visit_and_its_prefetch_is_not(): void
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', '/vote');
        $this->assertTrue(Speculative::is($r->withHeader('X-Site-Prefetch', 'prefetch')), 'a site-nav prefetch would write login_next and count views');
        $this->assertFalse(Speculative::is($r->withHeader('X-Site-Nav', '1')));
        $m = new \ReflectionMethod(\AfricaGates\Services\VisitTracker::class, 'isPageNavigation');
        $this->assertTrue($m->invoke(null, $r->withHeader('X-Site-Nav', '1')->withHeader('Sec-Fetch-Mode', 'cors')->withHeader('Sec-Fetch-Dest', 'empty')),
            'every page reached in place would vanish from the visits report');
        $this->assertFalse($m->invoke(null, $r->withHeader('Sec-Fetch-Mode', 'cors')->withHeader('Sec-Fetch-Dest', 'empty')));
    }
}
