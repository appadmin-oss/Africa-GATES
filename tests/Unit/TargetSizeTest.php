<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\PublicSurface;

/**
 * TOUCH TARGETS ON THE PUBLIC BASE AND CHROME: 44px, ONE OWNER'S EXCEPTION, AND A FLOOR
 * UNDER EVERYTHING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE, THE EXCEPTION, AND THE FLOOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REFERENCE §6.7 and §13: targets are ≥44×44 everywhere (WCAG 2.5.8 asks 24; the
 * handoff asks 44). REFERENCE's own §7.1 then draws the desktop header at 40 — the two
 * top links, the toolbar pill, the avatar and the Sign in pill — and the phone app bar's
 * avatar at 34 inside a 44px target. Phase 2 built §7.1 as written and listed it as
 * blocked: REFERENCE contradicted itself (PHASE-2.md deviation 15).
 *
 * **The owner answered on 3 Oct 2026: the header AS SPECIFIED.** That answer is recorded
 * here, by selector, in `OWNER_HEADER`, each with the §7.1 value it is held to — not as
 * a relaxed number, because a list of "at least 40" would let the next 40px control in
 * the header in on the same ticket. The app bar avatar needs no exception: it is 34px of
 * ink inside a 44px button, which is the 44 rule kept.
 *
 * Nothing else is relaxed. Every other interactive control the base components and the
 * chrome draw is held to 44, and the WCAG 2.5.8 floor of 24 holds under EVERYTHING,
 * exceptions included, unconditionally.
 *
 * ── WHAT THIS FOUND THAT THE OWNER HAS NOT RULED ON ──────────────────────────
 *
 * No test held 44 on the public surface before this one — `AccessibilityFloorTest` reads
 * `a11y.css`, which only the admin and judge consoles link — so writing it surfaced the
 * controls already shipped under 44 outside the header. They are NOT approved and are
 * not folded into the owner's exception: they are `OPEN`, each with where it came from,
 * held at exactly today's size so the list can only shrink, and reported for a decision
 * (PHASE-2.md §10). A new sub-44 control anywhere fails this file outright.
 *
 * ── HOW A CONTROL IS RECOGNISED ──────────────────────────────────────────────
 *
 * By markup, not by guessing from a class name: every class on an `<a>`, `<button>`,
 * `<select>`, `<input>`, `<textarea>` or `<summary>` in the chrome partials, the shell
 * and /_dev/ui (the specimen of every base component). A rule in `components.css` or
 * `components/chrome.css` whose selector ends on one of those classes, and declares a
 * px `height` or `min-height`, is a statement about that control's target. Read in px
 * at the 16px root; a control's box is px by design (tokens.css: a target is sized for a
 * finger, not for text). Not read: a size set by script, and the width of a control
 * whose height passes.
 */
final class TargetSizeTest extends TestCase
{
    public const FLOOR = 24;     // WCAG 2.2 SC 2.5.8, AA
    public const TARGET = 44;    // REFERENCE §6.7, §13

    /** The owner's exception (3 Oct 2026): the desktop header at REFERENCE §7.1's values. */
    public const OWNER_HEADER = [
        '.ag-head__top'    => [40, '§7.1: "Each is a 40px button" — Participate and Explore'],
        '.ag-tools__b'     => [34, '§7.1: the toolbar pill is 40px tall; its three controls are the DC\'s 34 inside it'],
        '.ag-head__av'     => [40, '§7.1: "the avatar (40px, ink)"'],
        '.ag-head__signin' => [40, '§7.1: "an outlined Sign in pill (40px, 1px ink)"'],
    ];

    /**
     * Shipped under 44 outside the header, NOT approved — awaiting the owner. Held at
     * exactly these sizes: the list may only shrink.
     */
    public const OPEN = [
        '.ag-pop__lang' => [40, 'Phase 2: the header language menu\'s rows, at the DC\'s 40 — not a §7.1 value'],
        '.ag-ss__esc'   => [28, 'Phase 2 deviation 15: the search palette\'s Esc key'],
        '.ag-kb__x'     => [36, 'Phase 2 deviation 15: the shortcuts dialog\'s close'],
        '.ag-chip'      => [40, 'Phase 1: a chip is 40 at ≥1024px ("the desktop pointer floor")'],
        '.ag-cs__go'    => [40, 'Phase 1: the collapsed search button is 40 at ≥1024px'],
        '.ag-switch'    => [28, 'Phase 1: the switch is a 28px button inside a 44px `.ag-switch-target` span'],
    ];

    private const SHEETS = ['public/assets/css/components.css', 'public/assets/css/components/chrome.css'];

    public function test_every_public_control_is_44_but_the_owners_header_exception(): void
    {
        $under = [];
        foreach (self::measure() as [$sel, $px, $where]) {
            if ($px >= self::TARGET) continue;
            $key = self::key($sel);
            if (isset(self::OWNER_HEADER[$key]) || isset(self::OPEN[$key])) continue;
            $under[] = sprintf('%s  %s is %spx', $where, $sel, $px);
        }
        $this->assertSame([], $under,
            "a public control is under REFERENCE §6.7's 44px, and only the owner's header exception may be:\n"
            . implode("\n", $under));
    }

    public function test_the_owners_header_exception_is_exactly_section_7_1(): void
    {
        $seen = [];
        foreach (self::measure() as [$sel, $px, $where]) {
            $key = self::key($sel);
            if (!isset(self::OWNER_HEADER[$key])) continue;
            $seen[$key] = true;
            $this->assertSame((float) self::OWNER_HEADER[$key][0], $px,
                "$where: $sel is held to §7.1's " . self::OWNER_HEADER[$key][0] . 'px, not to "anything under 44"');
        }
        $this->assertSame(array_keys(self::OWNER_HEADER), array_keys($seen),
            'an exception names a header control the chrome no longer draws — delete it rather than keep a dead ticket');

        // The exception is the HEADER. Every selector in it lives in the header's block.
        foreach (array_keys(self::OWNER_HEADER) as $sel) {
            $this->assertMatchesRegularExpression('~^\.ag-(head|tools)__~', $sel, "$sel is not a header control");
        }
    }

    public function test_the_open_list_only_shrinks(): void
    {
        $found = [];
        foreach (self::measure() as [$sel, $px]) {
            $key = self::key($sel);
            if (isset(self::OPEN[$key]) && $px < self::TARGET) $found[$key] = min($found[$key] ?? INF, $px);
        }
        foreach (self::OPEN as $sel => [$px, $why]) {
            $this->assertArrayHasKey($sel, $found, "$sel is no longer under 44 — remove it from OPEN ($why)");
            $this->assertSame((float) $px, $found[$sel], "$sel moved to {$found[$sel]}px while awaiting a decision ($why)");
        }
    }

    /** WCAG 2.5.8: 24×24 under everything — the owner's exception and the open list included. */
    public function test_nothing_is_under_the_wcag_floor(): void
    {
        $measured = self::measure();
        $this->assertGreaterThan(20, count($measured), 'the sweep measured almost nothing — is it reading the sheets?');
        foreach ($measured as [$sel, $px, $where]) {
            $this->assertGreaterThanOrEqual(self::FLOOR, $px, "$where: $sel is {$px}px, under WCAG 2.5.8's 24");
        }
    }

    /** The app bar avatar is 34px of ink inside a 44px button — the 44 rule kept, no exception. */
    public function test_the_app_bar_avatar_sits_in_a_44px_target(): void
    {
        $bar = (string) file_get_contents(PublicSurface::root() . '/templates/partials/app-bar.twig');
        $this->assertMatchesRegularExpression('~<button\b[^>]*class="[^"]*\bag-appbar__icon\b[^"]*"[^>]*>\s*<span\b[^>]*\bag-appbar__av\b~s', $bar,
            'the avatar is no longer inside the 44px app-bar button');
        $sizes = [];
        foreach (self::measure() as [$sel, $px]) $sizes[self::key($sel)] = $px;
        $this->assertSame(44.0, $sizes['.ag-appbar__icon'] ?? null);
    }

    /**
     * Every (selector, px, where) the sheets declare for an interactive control.
     *
     * @return list<array{0:string,1:float,2:string}>
     */
    private static function measure(): array
    {
        $root = PublicSurface::root();
        $interactive = [];
        $files = array_merge(glob($root . '/templates/partials/*.twig') ?: [],
            [$root . '/templates/pages/dev-ui.twig', $root . '/templates/layout/shell.twig']);
        foreach ($files as $f) {
            $s = PublicSurface::strip((string) file_get_contents($f), true);
            preg_match_all('~<(?:a|button|select|input|textarea|summary)\b([^>]*)>~i', $s, $m);
            foreach ($m[1] as $attrs) {
                if (!preg_match('~\bclass\s*=\s*"([^"]*)"~', $attrs, $c)) continue;
                foreach (preg_split('~\s+~', trim($c[1])) ?: [] as $k) if ($k !== '') $interactive[$k] = true;
            }
        }

        $out = [];
        foreach (self::SHEETS as $rel) {
            $t = PublicSurface::strip((string) file_get_contents($root . '/' . $rel), false);
            foreach (PublicSurface::rules($t, false) as [$sel, $body, $at]) {
                $px = null;
                foreach (PublicSurface::declarations($body) as [$p, $v]) {
                    if (($p === 'height' || $p === 'min-height') && preg_match('~^([0-9.]+)px$~', trim($v), $mm)) {
                        $px = max($px ?? 0.0, (float) $mm[1]);
                    }
                }
                if ($px === null) continue;
                foreach (explode(',', $sel) as $one) {
                    $one = trim($one);
                    if ($one === '' || str_contains($one, '::')) continue;
                    $parts = preg_split('~\s+|>|\+|\~~', $one) ?: [];
                    preg_match_all('~\.([\w-]+)~', (string) end($parts), $cm);
                    if (!array_intersect($cm[1], array_keys($interactive))) continue;
                    $out[] = [$one, $px, sprintf('%s:%d', $rel, PublicSurface::lineAt($t, $at))];
                }
            }
        }
        return $out;
    }

    /** `.ag-tools__b:hover` and `.ag-chip[aria-selected]` are the control itself. */
    private static function key(string $sel): string
    {
        $parts = preg_split('~\s+|>|\+|\~~', trim($sel)) ?: [];
        preg_match('~^\.[\w-]+~', (string) end($parts), $m);
        return $m[0] ?? $sel;
    }
}
