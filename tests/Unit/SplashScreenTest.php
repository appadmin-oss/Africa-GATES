<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The mobile entrance splash must never be the thing somebody is waiting on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * MEASURED, BEFORE THE FIX
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 5.4 SECONDS of covered page, on the first mobile view of every session. Not a
 * network stall: `animation:agExit .7s 4.9s` plus a JS `setTimeout(rm, 5900)` — a
 * fixed timer that ran to completion however fast the page had loaded.
 *
 * And it ran everywhere. `/vote/verify?ref=…` and `/support/assistant` both showed
 * it, so a supporter tapping a link from an email about money they had lost sat
 * through a logo animation for five seconds before they could read a word.
 *
 * ── WHY THE 4.9s WAS THERE, WHICH IS THE INTERESTING PART ────────────────────
 *
 * The reveal was SMIL inside the inline SVG, and a SMIL timeline starts on
 * DOCUMENT LOAD — after every subresource — while CSS animations start at first
 * render. Measured directly: `svg.getCurrentTime()` was still 0.00 when the CSS
 * clock had passed 1.0s. The long hold was absorbing that skew.
 *
 * It cannot be corrected from the SVG side: `pauseAnimations()` before the
 * timeline has begun is a no-op, so the reveal always started whenever the page
 * happened to finish loading. Compressing the timings alone produced a reveal in
 * the WRONG ORDER — the wordmark landed before the continent started drawing.
 *
 * So SMIL is gone. Every animation is CSS keyed on `.is-playing`, added on the
 * first rendered frame: one clock, one explicit start. Measured after: 1.28s from
 * first contentful paint, and unchanged on a throttled slow-3G profile, because
 * the duration no longer depends on the network at all.
 *
 * These assertions are structural because the behaviour is browser behaviour. Each
 * one names the specific regression it exists to stop.
 */
final class SplashScreenTest extends TestCase
{
    private const LAYOUT = 'templates/layout/gates.twig';
    private const CSS    = 'public/assets/css/components/loader.css';

    private function layout(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::LAYOUT);
    }

    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::CSS);
    }

    /**
     * The stylesheet with comments removed.
     *
     * Needed because the comments deliberately NAME the old broken rule so the next
     * editor understands why it went — and a test that forbids explaining a bug in
     * a comment is a worse test than the one it replaced.
     */
    private function cssCode(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $this->css());
    }

    /** The splash markup, so assertions do not match the rest of the page. */
    private function splash(): string
    {
        $l = $this->layout();
        $from = strpos($l, 'id="agLoader"');
        $this->assertNotFalse($from, 'The splash markup has moved.');
        return substr($l, $from, 8000);
    }

    // ── the skew that caused all of it ───────────────────────────────────────

    public function test_the_splash_contains_no_smil(): void
    {
        $splash = $this->splash();

        $this->assertStringNotContainsString('<animate', $splash,
            'A SMIL timeline starts on DOCUMENT LOAD, so its clock drifts from the CSS clock '
            . 'by however long the page takes to finish loading. That skew is what the original '
            . '4.9-second hold was hiding. Animate with CSS keyed on .is-playing instead.');
        $this->assertStringNotContainsString('<set ', $splash,
            'Same reason as <animate> — it runs on the SMIL clock.');
    }

    public function test_the_reveal_is_driven_by_the_playing_class(): void
    {
        $css = $this->cssCode();

        // `ag-loader__isle` was a second animated path; v5 puts Madagascar and the
        // mainland inside ONE `<g class="ag-loader__land">` and animates the group, so
        // the two can no longer rise a frame apart. The outlines themselves are
        // unchanged — see the case below, which is what holds that.
        foreach (['ag-loader__disc', 'ag-loader__land', 'ag-loader__africa', 'ag-loader__gates'] as $part) {
            $this->assertMatchesRegularExpression(
                '~\.ag-loader\.is-playing\s+\.' . preg_quote($part, '~') . '\s*\{[^}]*animation~',
                $css, "{$part} must animate from .is-playing, not from first render.");
        }
        $this->assertMatchesRegularExpression('~\.ag-loader\.is-playing\s+\.ag-loader__bar::after\s*\{[^}]*animation~',
            $css, 'The hairline too, or it is a static rule under a mark that is still arriving.');
    }

    /** Every reveal animation holds its end state. */
    public function test_no_animation_can_leave_the_logo_half_drawn(): void
    {
        $css = $this->cssCode();
        $this->assertSame(0, preg_match('~\.ag-loader\.is-playing[^{]*\{[^}]*animation:[^;}]*(?<!forwards)[;}]~', $css),
            'Without `forwards` a dropped frame can leave a partly drawn continent on screen.');
    }

    // ── it can never be the thing somebody waits on ──────────────────────────

    public function test_the_exit_is_not_a_fixed_delay_animation(): void
    {
        $this->assertStringNotContainsString('agExit', $this->cssCode(),
            'The fade was `animation:agExit .7s 4.9s`, which covered the page for 5.4s however '
            . 'fast it had loaded. It is now a class the script adds.');
        $this->assertMatchesRegularExpression('~\.ag-loader\.is-out\s*\{[^}]*opacity:0~', $this->cssCode());
    }

    public function test_there_is_a_hard_cap_measured_from_navigation(): void
    {
        $l = $this->layout();

        $this->assertSame(1, preg_match('~var\s+REVEAL=(\d+),\s*LEAVE=(\d+),\s*FADE=(\d+),\s*CAP=(\d+);~', $l, $m),
            'The four timings should stay together and readable.');
        [, $reveal, $leave, $fade, $cap] = $m;

        // The handoff states a total, and a total is the sum of its parts or it is a
        // number somebody typed. 1040 + 80 + 240 = 1360.
        $this->assertSame(1360, (int) $reveal + (int) $leave + (int) $fade,
            'the three phases no longer add up to the 1.36s the spec states');

        $this->assertLessThanOrEqual(1200, (int) $reveal,
            'The reveal is decoration. Anything beyond about a second is a toll on every '
            . 'first mobile visit, paid in the place people are least patient.');
        $this->assertLessThanOrEqual(600, (int) $fade);
        $this->assertLessThanOrEqual(3000, (int) $cap,
            'The cap is the promise that a slow page shows content rather than a logo.');
        $this->assertGreaterThan((int) $reveal, (int) $cap,
            'A cap below the reveal would cut the animation off every single time.');
    }

    public function test_the_reveal_starts_on_a_rendered_frame(): void
    {
        $this->assertMatchesRegularExpression('~requestAnimationFrame\(function\(\)\{\s*requestAnimationFrame\(play\)~',
            $this->layout(),
            'Starting on the second frame is what guarantees the first keyframe is actually '
            . 'rendered rather than skipped.');
    }

    // ── who never sees it ────────────────────────────────────────────────────

    /**
     * Nobody arriving with a problem watches an animation.
     *
     * Support, help, the account area, a payment and the proof page are places
     * people reach BECAUSE something is wrong or something is owed. A brand moment
     * there is an obstacle wearing a logo.
     */
    public function test_task_pages_never_show_the_splash(): void
    {
        $l = $this->layout();

        $this->assertSame(1, preg_match('~var TASK = \[([^\]]*)\];~', $l, $m),
            'The exempt list has moved or been removed.');
        $exempt = array_map(fn($s) => trim($s, " '\""), explode(',', $m[1]));

        foreach (['support', 'help', 'account', 'verify', 'pay', 'checkout'] as $page) {
            $this->assertContains($page, $exempt,
                "gates_page '{$page}' is somewhere people arrive with a problem.");
        }
        // The list has to actually gate the decision. It now does so through `task`, which
        // ORs it with the explicit `task_page` flag — so both halves are asserted rather
        // than just the shape of the old expression.
        $this->assertMatchesRegularExpression('~var task = .*TASK\.indexOf\(page\) !== -1~', $l,
            'The exempt list no longer feeds the decision.');
        $this->assertMatchesRegularExpression('~&&\s*!task\b~', $l,
            'The splash is no longer gated on `task`.');
    }

    /**
     * A page can opt out by name, not only by section.
     *
     * The list is keyed on `gates_page`, which is ALSO what highlights the navigation — so a
     * page that belongs to a section could not exempt itself without lying about which nav
     * item it sits under. The ticket page found it: it belongs to "events", and somebody was
     * standing at a door with a queue behind them watching a logo draw itself.
     */
    public function test_a_controller_can_exempt_a_page_explicitly(): void
    {
        $l = $this->layout();

        $this->assertStringContainsString('task_page', $l,
            'the explicit per-page exemption has been removed — a ticket page cannot opt out '
            . 'through the section list without changing which nav item it highlights');

        // And the ticket page — the case this exists for — must actually pass it.
        $ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/EventsController.php');
        $this->assertSame(2, preg_match_all("~'task_page'\s*=>\s*true~", $ctrl),
            'both ticket branches (found, and not-found) must be exempt');
    }

    public function test_it_stays_mobile_only_once_per_session_and_motion_safe(): void
    {
        $l = $this->layout();

        $this->assertStringContainsString("matchMedia('(max-width:879px)')", $l);
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: no-preference)')", $l);
        $this->assertStringContainsString("sessionStorage.getItem('ag_intro')", $l);
    }

    /**
     * A task page must not merely POSTPONE the intro to the next page.
     *
     * The once-per-session flag is stamped whatever we decided. Otherwise somebody
     * who lands on /support and then taps through to the leaderboard gets the
     * splash there instead — the animation follows them until it finds a page it is
     * allowed to play on, which is worse than showing it once up front.
     */
    public function test_the_session_flag_is_stamped_even_when_the_splash_is_skipped(): void
    {
        $this->assertMatchesRegularExpression("~if \(first\) sessionStorage\.setItem\('ag_intro','1'\);~",
            $this->layout(),
            'Stamp the flag outside the show/skip branch, or a skipped intro reappears on the '
            . 'next page the visitor opens.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // v5 — handoff-oct-2026 Part B
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * THE CAP HAS TO ACTUALLY CAP, AND THE SHIPPED SCRIPT'S DID NOT.
     *
     * The handoff's own exit script guarded the cap with
     *
     *   if (playing && performance.now() - started < REVEAL) return;
     *
     * so a cap firing on a reveal that had only just begun did NOTHING, and the only
     * remaining exit was the reveal's own timer. Measured on a throttled connection: the
     * reveal began at 2179ms and the loader left at 3581ms, against a spec that says
     * "Hard cap 2.2s" — and a slow connection is the only case a cap is for.
     *
     * That is the fault this whole file was written for, returning by a different route:
     * the splash becoming the thing somebody waits on. The cap fires unconditionally now.
     */
    public function test_the_cap_has_no_escape_for_a_reveal_in_progress(): void
    {
        $l = $this->layout();

        $cap = substr($l, strpos($l, 'CAP - performance.now()') - 300, 320);

        $this->assertStringNotContainsString('< REVEAL) return', $cap,
            'the cap yields to a reveal in progress — on a slow page the loader outlasts it, '
            . 'which is the one case a cap exists for');
        $this->assertMatchesRegularExpression('~setTimeout\(function\(\)\{\s*out\(true\)~', $l,
            'the cap must call the exit, not merely schedule a check that can decline');
    }

    /**
     * The CSS schedule is the spec, and a sum nobody checks is a comment.
     *
     * Each phase is `animation:<name> <duration> <delay>`; the last thing to finish is
     * the hairline at 0.20 + 0.72 = 0.92s, and the script's REVEAL (1040ms) has to be at
     * least that or the mark starts leaving while a line is still drawing under it.
     */
    public function test_the_drawn_phases_finish_before_the_mark_starts_leaving(): void
    {
        $css = $this->cssCode();

        preg_match_all('~\.ag-loader\.is-playing[^{]*\{\s*animation:\w+\s+([\d.]+)s(?:\s+([\d.]+)s)?~',
            $css, $m, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(5, count($m),
            'the five reveal phases (disc, continent, Africa, GATES, hairline) are not all keyed on .is-playing');

        $last = 0.0;
        foreach ($m as $phase) {
            $last = max($last, (float) $phase[1] + (float) ($phase[2] ?? 0));
        }

        $this->assertEqualsWithDelta(0.92, $last, 0.001,
            'the last phase no longer lands at 0.92s — the schedule in the spec has moved');

        preg_match('~var\s+REVEAL=(\d+)~', $this->layout(), $r);
        $this->assertGreaterThanOrEqual($last * 1000, (int) $r[1],
            'the mark starts leaving before the drawing has finished');
    }

    /**
     * THE CONTINENT IS NOT REDRAWN, and the handoff says so in as many words.
     *
     * Both outlines are traced and nobody has the source. A "tidy-up" that simplifies
     * them is a different Africa on the first thing anybody sees, and it is the kind of
     * change that reads as a formatting commit in a diff — which is exactly why it is
     * pinned by length rather than left to be noticed.
     */
    public function test_the_two_outlines_are_the_originals(): void
    {
        $l = $this->layout();

        preg_match('~<g class="ag-loader__land">(.*?)</g>~s', $l, $g);
        $this->assertNotEmpty($g, 'the two outlines are no longer grouped as ag-loader__land');

        preg_match_all('~<path d="([^"]+)"~', $g[1], $p);
        $this->assertCount(2, $p[1], 'there should be exactly two paths: the mainland and Madagascar');

        // The mainland starts at the Maghreb and Madagascar off Mozambique. Pinned as
        // prefixes rather than in full: a whole-path assertion is unreadable in a diff
        // and tells nobody which end moved.
        $this->assertStringStartsWith('M 427.0,237.0', $p[1][0], 'the mainland outline was redrawn');
        $this->assertStringStartsWith('M 1274.0,1000.0', $p[1][1], 'the Madagascar outline was redrawn');
        $this->assertGreaterThan(900, strlen($p[1][0]), 'the mainland outline has been simplified');
    }

    /** Restored from the back/forward cache, it goes at once rather than fading in. */
    public function test_a_bfcache_restore_removes_it_outright(): void
    {
        $this->assertMatchesRegularExpression(
            "~pageshow.*?e\.persisted\s*\)\s*rm\(\)~s", $this->layout(),
            'a page restored from bfcache is already drawn and being looked at — a splash '
            . 'fading in over it is the site appearing to reload something nobody left');
    }

    /** Two denials, not one: the gate decides, and the stylesheet refuses anyway. */
    public function test_reduced_motion_is_refused_twice(): void
    {
        $this->assertMatchesRegularExpression(
            '~@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\.ag-loader\{\s*display:none~',
            $this->cssCode(),
            'the stylesheet does not deny itself under reduced motion');

        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $this->layout(),
            'the head gate no longer checks motion, so the stylesheet is the only thing left');
    }
}
