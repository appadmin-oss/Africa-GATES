<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * NO FULL RELOAD WHERE A SWAP WILL DO (owner, 16 Nov 2026: "fix the full reload issues").
 *
 * site-nav.js swaps a page in place only when the next page has the SAME shell and every
 * script on it is safe to run again (SiteNavTest holds the scripts). Measured with a crawler
 * over every public link, signed out and signed in, two kinds of full load were left:
 *
 *   · PULSE AND THE HELP DESK had no Gee, so their shell signature differed from every other
 *     page's and site-nav refused to swap into or out of them — every visit a white page.
 *     Gee is now IN those pages, hidden (`data-gee-away`, synced on each swap).
 *   · SCRIPTS RELOADED AFTER AN ACTION — a confirmed vote, a ticket claimed or moved, a piece
 *     of nominee work saved, a passkey added — and the search box submitted with
 *     form.submit(), which fires no submit event, so site-nav never saw it. Each goes through
 *     `agNav.reload()` / `agNav.go()` now, with the plain reload only as the fallback.
 *
 * What stays a full load is deliberate and is not asserted against: a language change (the
 * shell is a different language), signing in, a payment gateway, a URL whose GET acts
 * (/vote/verify), and any POST.
 */
final class FullReloadTest extends TestCase
{
    /** Gee is in the page on Pulse and the support desk, hidden, so the shells match. */
    public function test_pulse_and_the_help_desk_share_the_shell(): void
    {
        foreach (['/pulse', '/support'] as $path) {
            $html = \Tests\Support\ChromeRender::html($path);
            $this->assertMatchesRegularExpression('~<div class="gee" data-gee [^>]*\sdata-gee-away\s[^>]*data-nav-keep="gee" data-nav-sync="data-gee-default data-gee-away"~', $html,
                $path . ' has no Gee in it, so site-nav cannot swap into or out of it');
        }
        $home = \Tests\Support\ChromeRender::html('/');
        $this->assertMatchesRegularExpression('~<div class="gee" data-gee [^>]*data-nav-keep="gee"~', $home);
        $this->assertDoesNotMatchRegularExpression('~<div class="gee" data-gee [^>]*\sdata-gee-away[\s>]~', $home, 'Gee hidden everywhere');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/gee.css');
        $this->assertStringContainsString('.gee[data-gee-away]{ display:none }', $css);
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/gee.js');
        $this->assertStringContainsString("if (root.hasAttribute('data-gee-away')) return;", $js, 'Gee can be opened, invisibly, on a page it is away from');
    }

    /** site-nav offers a reload in place, and no public script reloads the page without trying it first. */
    public function test_no_public_script_reloads_without_trying_the_swap(): void
    {
        $dir = dirname(__DIR__, 2) . '/public/assets/js';
        $nav = (string) file_get_contents($dir . '/site-nav.js');
        $this->assertStringContainsString("reload: function (hash) { go(location.pathname + location.search + (hash || ''), { replace: true, fresh: true, keep: true }); }", $nav);
        $this->assertStringContainsString('var used = o.fresh ? null : cached(key(u));', $nav, 'a reload answered from the cache shows the page as it was BEFORE the action');

        $offenders = [];
        foreach (glob($dir . '/*.js') as $f) {
            $name = basename($f);
            // The consoles have their own navigation; site-nav's own hard() is the fallback itself.
            if (preg_match('~^(admin|console|judge|review-|site-nav|shell7|lead|cn-)~', $name)) continue;
            foreach (preg_split('/\R/', (string) file_get_contents($f)) as $i => $line) {
                if (str_contains($line, 'location.reload()') && !str_contains($line, 'agNav')) {
                    // A fallback on the line below a guarded attempt is the fallback, not an offence.
                    $offenders[] = $name . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }
        // vote.js keeps its plain reload as the `else` of the agNav attempt, one line down.
        $offenders = array_values(array_filter($offenders, static fn ($o) => !str_contains($o, "else { location.href = location.pathname + '#ballot'; location.reload(); }")));
        $this->assertSame([], $offenders, "A script reloads the whole page after an action; use agNav.reload() with this as the fallback:\n" . implode("\n", $offenders));
    }

    /** The search box goes through site-nav: form.submit() fires no submit event, so it never could. */
    public function test_the_search_box_swaps_in_place(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/search.js');
        $this->assertStringContainsString('if (go) { if (window.agNav) window.agNav.go(go.href); else location.href = go.href; return; }', $js);
        $this->assertStringContainsString('window.agNav.go(u.pathname + u.search);', $js);
    }
}
