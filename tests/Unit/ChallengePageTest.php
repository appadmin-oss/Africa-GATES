<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeFlier;
use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * `/challenges` AND `/challenges/{slug}` — rebuilt 5 Oct 2026.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS PAGE WAS REBUILT WITHOUT A COMP, AND WHY IT WAS STILL 500ing
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The 3 Oct destroy removed 92 page templates and left their routes in place, which
 * is stated and deliberate: "Routes whose template is gone answer 500 until rebuilt."
 * Every other page names the phase that rebuilds it. `DESTROYED.md` gives these two
 * "no phase — ChallengePage.dc.html is not in the bundle (Q16)" — so there was no
 * comp and nobody coming, and `/challenges` went on answering 500 while the footer
 * and the Participate menu both linked to it.
 *
 * This file re-asserts the rules held by the guards destroyed with the page
 * (inventory: `pages--challenges--index.md`, `pages--challenges--show.md`), against
 * the new markup. Each was watched failing against a planted break before it was
 * trusted (CLAUDE.md, "Prove a new sweep FAILS").
 *
 * Rendered through the real router, so a template reading something the controller
 * stopped passing fails here rather than in production — which is the whole reason
 * the original 500s were invisible to a green suite.
 */
final class ChallengePageTest extends TestCase
{
    /** The four theme names, as the admin may set them. */
    private const THEMES = ['green', 'blue', 'gold', 'rose'];

    private function challenge(array $x = []): string
    {
        static $n = 0; $n++;
        $slug = $x['slug'] ?? ('ch-' . $n);
        DB::table('gates_challenges')->insert($x + [
            'slug' => $slug, 'title' => 'Challenge ' . $n, 'kicker' => 'Nominate someone',
            'action' => 'nominate', 'target' => 10, 'mode' => 'first', 'cap' => 10,
            'prize_type' => 'cash_each', 'prize_amount' => 5000, 'prize_currency' => 'NGN',
            'theme' => 'green', 'flag' => 0, 'status' => 'open',
            'starts_at' => date('Y-m-d H:i:s', time() - 86400),
            'ends_at'   => date('Y-m-d H:i:s', time() + 864000),
            'published_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $slug;
    }

    /**
     * The challenge stylesheet WITH ITS COMMENTS STRIPPED.
     *
     * Not fastidiousness: this test read the raw file first and reported that the gold
     * theme drew green. The comment above the default block explains the cascade order
     * and, in doing so, writes `[data-theme="gold"]` — so the pattern below matched the
     * COMMENT, ran on to the next `{`, and parsed the default block as gold's.
     *
     * Exactly the trap this codebase has paid for twice: a sweep goes quiet, or lies,
     * in the files somebody documented. A comment reaches no browser, so it was never
     * in scope.
     */
    /**
     * `--ch-fill|edge|wash|solid` per theme, as the HEXES a browser receives.
     *
     * Resolved from `Accent::challengeTheme()` — the one table — through `Accent::value()`,
     * which is what `ag_accents()` emits. This used to parse `[data-theme]` blocks out of
     * `components/challenge.css`, and that parse reported the gold theme as green: the
     * comment above the default block explains the cascade and in doing so writes
     * `[data-theme="gold"]`, so the pattern matched the COMMENT and ran on to the next
     * block. A sweep going quiet, or lying, in the file somebody documented.
     *
     * There is nothing left to parse now, which is the better fix: the stylesheet names
     * no theme at all.
     *
     * @return array<string,array<string,string>>
     */
    private function themeHexes(): array
    {
        $out = [];
        foreach (self::THEMES as $name) {
            foreach (Accent::challengeTheme($name) as $slot => $token) {
                $out[$name][$slot] = strtolower(Accent::value($token));
            }
        }
        return $out;
    }

    // ── The rules the destroyed guards held ──────────────────────────────────

    public function test_each_themes_solid_holds_white_text(): void
    {
        // `--ch-solid` is NOT `--ch-fill`, and this is the whole reason it exists: gold's
        // fill is #f3b416, which holds white at 1.9:1, so a gold challenge's primary
        // action had white text nobody could read. Every theme's solid carries the
        // button's label, so every one owes 4.5:1 against white.
        //
        // Re-derived from the stylesheet and the live tokens rather than pinned to the
        // four numbers: a token's value may move, and the floor is the rule.
        foreach ($this->themeHexes() as $name => $slot) {
            $ratio = Contrast::ratio('#ffffff', $slot['solid']);
            $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf(
                "theme '%s': white on %s is %.2f:1, and it carries the primary action's label",
                $name, $slot['solid'], $ratio));
        }
    }

    public function test_the_flier_and_the_page_cannot_disagree_about_a_theme(): void
    {
        // "A blue challenge whose page is blue and whose flier is green is two products."
        //
        // This used to COMPARE two tables — four hexes in `ChallengeFlier::THEMES` against
        // four `[data-theme]` blocks in the stylesheet — and a passing comparison is a
        // weaker thing than one table: it says they agree today. `ChallengeFlier::themes()`
        // resolves `Accent::challengeTheme()` now, so the question is structural.
        $page = $this->themeHexes();

        foreach (ChallengeFlier::themes() as $name => $slots) {
            foreach (['fill', 'edge', 'solid'] as $slot) {
                $this->assertSame($page[$name][$slot], strtolower((string) $slots[$slot]), sprintf(
                    "theme '%s' slot '%s': the page draws %s and the flier draws %s",
                    $name, $slot, $page[$name][$slot], strtolower((string) $slots[$slot])));
            }
        }
    }

    public function test_the_stylesheet_names_no_theme_of_its_own(): void
    {
        // The second copy, refusing to come back. Four `[data-theme]` blocks here is also
        // what charged every page linking this sheet for four colour events — fairly, since
        // the index really does show four themes at once.
        $css = (string) preg_replace('~/\\*.*?\\*/~s', '', (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/challenge.css'));

        $this->assertStringNotContainsString('data-theme', $css,
            'the challenge stylesheet is defining themes again — they belong to '
          . 'Accent::challengeTheme(), which the flier also reads');
    }

    public function test_one_main_landmark(): void
    {
        // The layout already owns `<main id="main">`. This page once opened a second one
        // with the same id — two main landmarks, and a skip link with two targets.
        $slug = $this->challenge();
        $html = ChromeRender::html('/challenges/' . $slug);

        $this->assertSame(1, preg_match_all('~<main\b~i', $html),
            'the challenge page opens a second <main>');
    }

    public function test_the_sections_render_in_order(): void
    {
        // The page is a sequence: what it is, how to take part, what counts, what you
        // win, what is included, questions. A reader who scrolls past "how" to find
        // "what counts" is reading the page backwards.
        $slug = $this->challenge();
        $html = ChromeRender::html('/challenges/' . $slug);

        $marks = ['id="ch-h1"', 'id="h-how"', 'id="h-count"', 'id="h-win"', 'id="h-faq"'];
        $at    = -1;
        foreach ($marks as $mark) {
            $pos = strpos($html, $mark);
            $this->assertNotFalse($pos, "{$mark} is not on the page");
            $this->assertGreaterThan($at, $pos, "{$mark} renders out of order");
            $at = $pos;
        }
    }

    // ── The page does its job ────────────────────────────────────────────────

    public function test_the_index_lists_a_card_for_every_published_challenge(): void
    {
        $this->challenge(['slug' => 'alpha', 'title' => 'Alpha challenge']);
        $this->challenge(['slug' => 'beta',  'title' => 'Beta challenge', 'theme' => 'gold']);

        $html = ChromeRender::html('/challenges');

        $this->assertStringContainsString('Alpha challenge', $html);
        $this->assertStringContainsString('Beta challenge', $html);
        $this->assertStringContainsString('href="/challenges/alpha"', $html);
        // The theme arrives as resolved custom properties on the card, not as a
        // `data-theme` attribute the stylesheet has to interpret — see
        // Accent::challengeStyle(), and the sheet that no longer names a theme.
        $this->assertStringContainsString('--ch-solid:var(--ag-gold-ink)', $html,
            'the gold card did not receive its theme');
    }

    public function test_a_draft_or_cancelled_challenge_is_not_a_public_page(): void
    {
        // A draft is a page somebody is still writing; a cancelled one is an offer that
        // was withdrawn. Neither is public, and neither is a 410 — the slug may be reused
        // when the admin publishes.
        foreach (['draft', 'cancelled'] as $status) {
            $slug = $this->challenge(['status' => $status]);
            $this->assertSame(404, ChromeRender::page('/challenges/' . $slug)->getStatusCode(),
                "a {$status} challenge is reachable");
            $this->assertStringNotContainsString('/challenges/' . $slug,
                ChromeRender::html('/challenges'), "a {$status} challenge is listed");
        }
    }

    public function test_the_empty_list_says_when_rather_than_only_that_it_is_empty(): void
    {
        // "No challenges" alone reads as a broken page. The empty state says the
        // mechanism exists and is between runs, and points at what is coming.
        $html = ChromeRender::html('/challenges');

        $this->assertStringContainsString('No challenges are running just now', $html);
        $this->assertStringContainsString('/awards', $html);
    }

    public function test_the_page_carries_its_own_bottom_bar_and_the_shell_drops_the_tab_bar(): void
    {
        // Two fixed elements at `bottom:0` means one is under the other, and which one
        // depends on source order rather than on anything anybody decided. `flow_page`
        // suppresses the tab bar; `data-bottom-ui` is how shell.js measures this bar into
        // --ag-bottom-ui so Gee's launcher and the scroll padding clear it. A bar that
        // does not declare itself is a bar the floating help button sits on top of.
        $slug = $this->challenge();
        $html = ChromeRender::html('/challenges/' . $slug);

        $this->assertMatchesRegularExpression('~class="ag-actionbar[^"]*"[^>]*data-bottom-ui~', $html,
            'the phone action bar does not declare itself to shell.js');

        // ── `ag-tabbar`, NOT `tab-bar` ──────────────────────────────────────
        //
        // This read `assertStringNotContainsString('tab-bar', …)` first, after the
        // partial's FILENAME rather than the class it emits — and the rendered page
        // contains that string nowhere, so the assertion was vacuous. Deleting
        // `flow_page` and watching it stay green is how that was found.
        //
        // There must then be exactly ONE element declaring itself to shell.js: this
        // page's bar. Counted rather than merely absent, because the failure being
        // guarded is two bottom bars, not a missing one.
        $this->assertStringNotContainsString('ag-tabbar', $html,
            'the shell still renders its tab bar under this page\'s own fixed bar — '
          . 'two fixed elements at bottom:0, and source order decides which is reachable');

        // NOT "exactly one element declares data-bottom-ui". That was the first version
        // and it is wrong: the cookie notice declares one too, correctly, because while
        // it is shown it IS the bottom of the viewport — and it is dismissed, where a tab
        // bar is permanent. A count here fails on a partial that is behaving properly,
        // which teaches whoever hits it to loosen the test rather than read it.
        $this->assertStringContainsString('data-bottom-ui', $html);
    }

    public function test_the_shared_macros_this_page_uses_are_styled(): void
    {
        // `ui.twig` survived the destroy and `library.css` did not, so every macro on this
        // page emitted correct, correctly labelled markup that NOTHING styled — a <dl>, an
        // <ol> and a <ul> with no rules, which is not a failure anything reports.
        $lib = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/library.css');

        foreach (['ag-facts', 'ag-meter', 'ag-steps', 'ag-ticks', 'ag-notice', 'ag-faq',
                  'ag-between', 'ag-actionbar'] as $cls) {
            $this->assertStringContainsString('.' . $cls, $lib,
                "ui.twig emits .{$cls} and no stylesheet draws it");
        }
    }
}
