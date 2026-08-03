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

        foreach (['ag-loader__disc', 'ag-loader__land', 'ag-loader__isle'] as $part) {
            $this->assertMatchesRegularExpression(
                '~\.ag-loader\.is-playing\s+\.' . preg_quote($part, '~') . '\s*\{[^}]*animation~',
                $css, "{$part} must animate from .is-playing, not from first render.");
        }
        $this->assertMatchesRegularExpression('~\.ag-loader\.is-playing\s+\.ag-loader__word\s*\{[^}]*animation~',
            $css, 'The wordmark too, or it lands before the continent has drawn.');
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

        $this->assertSame(1, preg_match('~var\s+REVEAL=(\d+),\s*FADE=(\d+),\s*CAP=(\d+);~', $l, $m),
            'The three timings should stay together and readable.');
        [, $reveal, $fade, $cap] = $m;

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
        $this->assertMatchesRegularExpression('~TASK\.indexOf\(page\) === -1~', $l,
            'The list has to actually gate the decision.');
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
}
