<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

/**
 * The floors this site owes, asked as rules rather than as a list of past fixes.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS ACTUALLY FAILING, AND WHY NONE OF IT LOOKED LIKE A BUG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * WCAG 2.2's four newest criteria are the ones teams miss, and this site missed three.
 *
 *   2.4.11 FOCUS NOT OBSCURED. `.ag-nav` is sticky at the top and `.ag-mobnav` is fixed at
 *   the bottom, so a keyboard user tabbing down a page had each newly-focused control
 *   scrolled to the viewport edge and then covered by one of them. The ring was behind the
 *   chrome. The guidance names sticky headers and cookie banners as the usual culprits and
 *   this site has both — the cookie notice shipped a week ago.
 *
 *   2.5.7 DRAGGING MOVEMENTS. The homepage globe rotated by drag and by nothing else. No
 *   keyboard path, no single-pointer path — so a head pointer, a switch, or any device
 *   that cannot express a drag path could not turn it.
 *
 *   2.5.8 TARGET SIZE. `a11y.css` raised targets to 44px under `pointer: coarse`, which is
 *   the AAA figure and right for a phone, and left the AA minimum of 24×24 unmet on every
 *   other input — an eye tracker, a trackpad used with a tremor, a touchscreen that
 *   reports itself as fine.
 *
 * And the one that is this repository's own signature shape: `--ag-pulse` `#e0245e` was
 * chosen as "the Pulse accent" with no floor attached, and it is a pass for a border and a
 * borderline case for a word — 4.58:1 on white, 4.42:1 on the Pulse's own `#fbfbfa`
 * ground, 4.08:1 on paper. It was a word on three public screens, and on exactly ONE of
 * them (`.pl-eyebrow`, the Pulse masthead) it was below the 4.5:1 floor. The other two sit
 * on white cards and passed by eight hundredths.
 *
 * All three use `--ag-live-ink` now, and the two that passed are said to have passed: a
 * value that is legible on a card and illegible on the page behind it is the reason a
 * colour needs a role rather than a hex, but it is not three failures and reporting it as
 * three would be the same overstatement this file exists to avoid.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE ARE ASSERTED THE WAY THEY ARE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * An enumeration of past failures is never a fix for the next one. So the colour sweep
 * asks "is a hue this site keeps for MEANING being used as a word without being lifted?"
 * rather than listing the three screens that did it, and it reads what a READER sees —
 * Twig comments stripped first, so the paragraph above a change may name the value it
 * removed without tripping the sweep documenting it.
 *
 * The structural three are asserted against the shipped CSS and JS. That is weaker than a
 * browser and is said plainly: it proves the mechanism is present, not that it works. What
 * it does buy is the thing that was actually missing — nothing at all was watching, so the
 * globe could go back to drag-only in one commit and nobody would know.
 */
final class AccessibilityFloorTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,string> the shipped templates a visitor actually meets */
    private function publicTemplates(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root() . '/templates'));

        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;
            if (str_contains($f->getPathname(), '/admin/')) continue;
            if (str_contains($f->getPathname(), '/emails/')) continue;   // no CSS vars in mail

            // A Twig comment reaches no reader, so it was never in scope. Same lesson
            // GlobeBandTest records: sweep what a reader sees, not the file.
            $out[$f->getPathname()] = (string) preg_replace(
                '/\{#.*?#\}/s', '', (string) file_get_contents($f->getPathname()));
        }

        return $out;
    }

    // ══ the colour floor ═════════════════════════════════════════════════════

    /**
     * An accent used as a WORD on a ground this file can prove is light.
     *
     * ── WHAT A WIDER VERSION OF THIS DID, AND WHY IT IS NOT HERE ─────────────
     *
     * The first cut swept every `color:<accent>` in every public template and reported 36
     * findings. Checked one by one, essentially all of them were CORRECT code: gold on a
     * dark podium (`.lbp--1` sets a `#1d5b2a → #10292C` gradient), gold on a dark sticky
     * card (`.hm-how` sets `#10292C`), soft green in a `--dark` variant. Told to "fix"
     * those, somebody would have lifted a gold to `#7a5600` and made it nearly invisible
     * on the very surface it was chosen for.
     *
     * Three separate things defeat a general version, and each is real:
     *   · the ground is usually set by a BEM PARENT, and BEM NAMING DOES NOT ENCODE
     *     CONTAINMENT — `.vn-ballot__k` and `.vn-ballot__top` are both elements of
     *     `.vn-ballot`, and the second contains the first and is `#10292C`. Inferring
     *     ancestry from the name reported that light-green label as a failure on a white
     *     card. Proved, then dropped;
     *   · it is often an `rgba()` wash over a surface declared in another file entirely;
     *   · and section classes live in `main.css`, not beside the rule that uses them.
     *
     * So this asks only what CSS itself states: the block declares its own background, or
     * a DESCENDANT selector (`.pf-cheer .h`) names an ancestor that declares one. Zero
     * invented findings, and it still catches the shape that actually shipped — `#e0245e`
     * at 4.08:1 as a word on a white pill, on three public screens. A sweep that reports
     * correct code is worse than no sweep, because the next real finding lands in a list
     * nobody trusts.
     */
    public function test_no_accent_is_a_word_on_a_ground_that_is_provably_light(): void
    {
        // A CANDIDATE is a hue that fails on EITHER house ground; the FINDING is measured
        // against the ground actually proved for that rule. The distinction is not
        // pedantry: `#e0245e` is 4.58:1 on white and 4.08:1 on paper, so the same value is
        // a pass on a card and a failure on the page behind it — which is exactly why it
        // was chosen as "the Pulse accent" and never questioned.
        $hues = [];
        foreach (Accent::roles() as $role) {
            foreach (['fill', 'edge'] as $slot) {
                $hex = strtolower(Accent::of($role)[$slot]);
                if (Contrast::ratio($hex, Accent::SURFACE) < Contrast::TEXT
                    || Contrast::ratio($hex, Accent::PAPER) < Contrast::TEXT) {
                    $hues[$hex] = '--ag-' . $role . '-ink';
                }
            }
        }
        // The strays that are in no role table and were the drift that made one necessary.
        foreach (['#c9a24b' => '--ag-honour-ink', '#fbc329' => '--ag-honour-ink',
                  '#7fc87c' => '--ag-action-ink'] as $hex => $use) {
            $hues[$hex] = $use;
        }
        $this->assertNotSame([], $hues, 'nothing is being swept — the role table went empty');

        $offenders = [];
        foreach ($this->publicTemplates() as $path => $body) {
            foreach ($this->lightBlocks($body) as [$sel, $decl, $ground]) {
                foreach ($hues as $hex => $use) {
                    if (!preg_match('/(?<![-\w])color\s*:\s*' . preg_quote($hex, '/') . '\b/i', $decl)) {
                        continue;
                    }
                    $got = Contrast::ratio($hex, $ground);
                    if ($got >= Contrast::TEXT) continue;   // passes on THIS ground

                    $offenders[] = sprintf('%s  %s  color:%s is %.2f:1 on %s — use var(%s)',
                        basename($path), $sel, $hex, $got, $ground, $use);
                }
            }
        }

        $this->assertSame([], $offenders,
            "these words are below the 4.5:1 text floor on a background declared beside them:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * Blocks whose own background, or whose BEM parent's, is a light hex.
     *
     * @return list<array{0:string,1:string,2:string}> selector, declarations, ground
     */
    private function lightBlocks(string $css): array
    {
        // ── STRIP CSS COMMENTS FIRST, AND THAT IS NOT TIDYING ────────────────
        //
        // The selector is whatever precedes `{`, so a `/* … */` sitting directly above a
        // rule is captured as part of its selector — and the guard below then SKIPPED the
        // rule. Caught by mutation: putting an unlifted accent back as a word on a white
        // card produced no finding, because the comment explaining the fix was still
        // above it. A sweep that goes quiet in exactly the files somebody has documented
        // is worse than one that never ran.
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        $blocks = [];
        if (!preg_match_all('/([^{}@]+)\{([^{}]*)\}/s', $css, $m, PREG_SET_ORDER)) return [];

        foreach ($m as $b) {
            $sel = trim((string) preg_replace('/\s+/', ' ', $b[1]));
            if ($sel === '') continue;
            foreach (explode(',', $sel) as $one) {
                $one = trim($one);
                if ($one !== '') $blocks[] = [$one, $b[2]];
            }
        }

        // ── A SELECTOR CAN BE DECLARED TWICE, WITH DIFFERENT GROUNDS ─────────
        //
        // `.jg-chip` is a translucent chip on a dark hero at the top of judges.twig and a
        // white filter chip five lines later. Keyed by selector, the second overwrote the
        // first and `.jg-chip b` — light green, on the dark one — was reported as a
        // failure on white. The same shape `OneResolverPerSettingTest` records: keyed by
        // bare name, the last one parsed wins and invents a finding.
        //
        // So a selector whose declarations DISAGREE is unresolvable and is skipped. Only a
        // selector that says one thing everywhere it appears is evidence.
        $seen = [];
        foreach ($blocks as [$sel, $decl]) {
            if (!preg_match('/(?<![-\\w])background(?:-color)?\\s*:/i', $decl)) continue;
            $seen[$sel][$this->lightGround($decl) ?? '?'] = true;
        }

        $ground = [];
        foreach ($seen as $sel => $set) {
            if (count($set) !== 1) continue;                       // it disagrees with itself
            $only = array_key_first($set);
            if ($only !== '?') $ground[$sel] = (string) $only;
        }

        $out = [];
        foreach ($blocks as [$sel, $decl]) {
            $g = $ground[$sel] ?? null;

            // ── ANCESTRY, ONLY WHERE THE SELECTOR STATES IT ──────────────────
            //
            // `.pf-cheer .h` says in CSS's own terms that `.h` is inside `.pf-cheer`. That
            // is sound, and it is the shape that actually shipped: `#e0245e` at 4.08:1 as
            // a word on a white pill.
            //
            // BEM NAMING IS NOT, AND THIS WAS PROVED RATHER THAN ASSUMED. The obvious
            // extension — `.hm-how__k` is inside `.hm-how` — reported `.vn-ballot__k` as a
            // failure on a white card. It is light green on `.vn-ballot__top`, which is
            // `#10292C` and is a SIBLING BEM ELEMENT that happens to contain it. The
            // convention says both are elements of `.vn-ballot`; it says nothing about
            // which contains which, so the inference is unsound in general and was
            // dropped. `.vp-card__mono` was a genuine finding the same way and had to be
            // confirmed by reading the markup — the sweep cannot prove it and does not
            // claim it.
            if ($g === null) {
                foreach ($ground as $asel => $alight) {
                    if ($asel !== $sel && str_starts_with($sel, $asel . ' ')) { $g = $alight; break; }
                }
            }

            if ($g !== null) $out[] = [$sel, $decl, $g];
        }

        return $out;
    }

    /** The block's background as a light hex, or null when it cannot be resolved. */
    private function lightGround(string $decl): ?string
    {
        if (!preg_match('/(?<![-\w])background(?:-color)?\s*:([^;]*)/i', $decl, $m)) return null;

        $value = $m[1];
        // An rgba() wash sits over a surface declared somewhere else, so nothing here can
        // say what it lands on. Not a light ground; not a finding either.
        if (stripos($value, 'rgba') !== false || stripos($value, 'gradient') !== false) return null;

        if (!preg_match('/#[0-9a-fA-F]{3,6}\b/', $value, $hex)) {
            // `var(--ag-surface,#fff)` is resolvable through its own fallback, and is how
            // most of this codebase declares a white card.
            if (preg_match('/var\(\s*--ag-surface[^)]*\)/i', $value)) return '#ffffff';
            return null;
        }

        $h = Contrast::hex($hex[0]);
        if ($h === '') return null;

        // "Light" is where the 4.5:1 floor is the one that bites: anything a dark word
        // would sit on. A mid-tone is nobody's ground and is left alone.
        return Contrast::luminance('#' . $h) > 0.55 ? '#' . $h : null;
    }

    public function test_every_role_offers_an_ink_that_clears_the_floor(): void
    {
        // The other half: the sweep above tells somebody to use an ink, so an ink has to
        // exist and has to pass. AccentTest measures the palette; this is the one line that
        // makes the instruction above honest.
        foreach (Accent::roles() as $role) {
            $this->assertGreaterThanOrEqual(Contrast::TEXT,
                Contrast::ratio(Accent::ink($role), Accent::PAPER), $role);
        }
    }

    // ══ WCAG 2.2's newest three ══════════════════════════════════════════════

    public function test_a_focused_control_is_not_scrolled_under_the_sticky_chrome(): void
    {
        // SC 2.4.11. The browser honours scroll-padding whenever it scrolls a focused
        // element into view, so one declaration on the scroll container covers every
        // control on every page — including ones added later, which is the difference
        // between a rule and a list of fixes.
        $css = (string) file_get_contents($this->root() . '/public/assets/css/a11y.css');

        $this->assertMatchesRegularExpression('/scroll-padding-top\s*:/', $css,
            'the sticky nav covers whatever the keyboard just focused');
        $this->assertMatchesRegularExpression('/scroll-padding-bottom\s*:/', $css,
            'the fixed mobile tab bar covers whatever the keyboard just focused');

        // Read from the chrome's own token, not typed: a number here is wrong the first
        // time a nav grows a row, and silently — nothing looks broken, the focus is just
        // behind something.
        $this->assertStringContainsString('--ag-nav-h', $css);
        $this->assertStringContainsString('--ag-mobile-nav-h', $css);
    }

    public function test_the_globe_can_be_turned_without_a_drag(): void
    {
        // SC 2.5.7. Rotation was drag-only. A head pointer, a switch, or any device that
        // cannot express a drag path could not reach the far side of the sphere.
        $js = (string) file_get_contents($this->root() . '/public/assets/js/globe-band.js');

        $this->assertStringContainsString('pointerdown', $js,
            'the drag is gone, so this test is now asserting nothing');
        $this->assertMatchesRegularExpression('/ArrowLeft|ArrowRight/', $js,
            'the globe rotates by drag and by nothing else');

        // And the half that fixes 2.4.7 in the same component: a marker on the far side is
        // drawn at opacity 0 and stays in the tab order, so focusing one used to put the
        // ring on something invisible. Focus now turns the globe to it.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]focus[\'"]/', $js,
            'tabbing lands on markers that are drawn at zero opacity');
    }

    public function test_the_target_minimum_is_not_conditional_on_the_pointer(): void
    {
        // SC 2.5.8 is 24×24 and applies on every input. The 44px block is AAA and right
        // for a phone; it was the ONLY floor, so everything else was unheld.
        $css = (string) file_get_contents($this->root() . '/public/assets/css/a11y.css');

        // The 24px rule must sit outside any `@media`. Measured by cutting the file at the
        // first media query the 44px block lives in and requiring the floor before it —
        // a `str_contains` would pass on a rule nested inside exactly the query that made
        // it conditional in the first place.
        $coarse = strpos($css, '@media (pointer: coarse)');
        $this->assertNotFalse($coarse, 'the coarse-pointer block is gone');

        $unconditional = substr($css, 0, $coarse);
        $this->assertMatchesRegularExpression('/min-height:\s*24px/', $unconditional,
            'the 24px AA target floor is inside a media query, so it is not a floor');
    }

    public function test_motion_is_not_forced_on_anybody(): void
    {
        // Not new and not previously broken — held because the globe gained a keyboard
        // rotation in the same change, and an animation that ignores the preference is the
        // easiest thing to add without noticing.
        $js = (string) file_get_contents($this->root() . '/public/assets/js/globe-band.js');

        $this->assertStringContainsString('prefers-reduced-motion', $js);
        // The new easing honours it by snapping rather than by refusing to turn: a globe
        // that will not move for somebody who asked for less motion has taken the feature
        // away instead of the animation.
        $this->assertMatchesRegularExpression('/reduced\s*\?\s*1\s*:/', $js,
            'reduced motion stops the turn instead of making it instant');
    }

    // ══ the notice this codebase shipped a week ago ═══════════════════════════

    public function test_the_cookie_notice_cannot_hide_what_the_keyboard_is_on(): void
    {
        // The guidance names cookie banners by name under 2.4.11, and this one is
        // `position:fixed` at the bottom of every page in ask-first mode. The
        // scroll-padding above is what keeps a focused control clear of it — asserted
        // here separately because the notice is the element the criterion was written
        // about, and a future change to it must not quietly reintroduce the fault.
        $twig = (string) file_get_contents($this->root() . '/templates/partials/cookie-notice.twig');

        $this->assertStringContainsString('position:fixed', $twig,
            'the notice moved; check scroll-padding-bottom still matches what covers the page');

        // It must not trap or block: no overlay, nothing inert, and it is not a dialog.
        foreach (['aria-modal', 'role="dialog"', 'inert'] as $trap) {
            $this->assertStringNotContainsString($trap, $twig);
        }
    }
}
