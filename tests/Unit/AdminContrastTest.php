<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The admin's own colour and target-size floors, checked arithmetically.
 *
 * A UX sweep of the admin found defects that every previous test was blind to, for the
 * same reason FormAccessibilityTest exists: they are properties of rendered colour, not
 * errors. The page compiles, the template is valid, and the text is unreadable.
 *
 *  • `--ad-text-mute` was #93a0b5 — 2.65:1 on white and 2.45:1 on the page ground, against
 *    the 4.5:1 WCAG 1.4.3 asks of body text. It is the `color` on ~184 rules: every table
 *    caption, field hint, timestamp and empty-state line in the admin wore it.
 *  • 62 inline uses carried a hard-coded FALLBACK of #8a9a9c (2.92:1). A fallback only
 *    applies when the variable is missing, so it was a trap rather than a live bug — but a
 *    trap that fires silently on any surface that forgets admin.css.
 *  • `.ad-btn--xs` was 22px tall. WCAG 2.5.8 sets the floor at 24×24 CSS px regardless of
 *    pointer type, so the coarse-pointer bump in a11y.css never covered a desktop mouse.
 *  • a11y.css reached only the public layout. The admin and the judge console — the two
 *    surfaces staff use daily, one of which moves money and one of which decides awards —
 *    loaded none of it. The judge console had no `.sr-only` definition at all, which is why
 *    a visually-hidden label written there would have rendered as visible text.
 *
 * These assertions are deliberately arithmetic rather than golden-value: a designer may
 * retune any of these colours, and the test should pass for any retune that stays legible
 * and fail for any that does not.
 */
class AdminContrastTest extends TestCase
{
    private const ADMIN_SURFACE = '#ffffff';   // --ad-surface, cards and table rows
    private const ADMIN_GROUND  = '#f4f6fa';   // --ad-bg, the page behind them
    private const SIDEBAR       = '#0b1220';   // .ad-side rail

    private static function css(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    /** Relative luminance, WCAG 2.x definition. */
    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $ch  = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $ch[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    }

    private static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Composite an alpha colour over an opaque ground, the way a browser does. */
    private static function over(string $fg, float $alpha, string $bg): string
    {
        $f = ltrim($fg, '#');
        $b = ltrim($bg, '#');
        $out = '';
        foreach ([0, 2, 4] as $i) {
            $out .= str_pad(dechex((int) round(
                hexdec(substr($f, $i, 2)) * $alpha + hexdec(substr($b, $i, 2)) * (1 - $alpha)
            )), 2, '0', STR_PAD_LEFT);
        }
        return '#' . $out;
    }

    private static function token(string $name): string
    {
        $css = self::css('public/assets/css/admin.css');
        self::assertSame(1, preg_match('/' . preg_quote($name, '/') . ':\s*(#[0-9a-fA-F]{6})/', $css, $m),
            "Expected a 6-digit hex for $name in admin.css");
        return strtolower($m[1]);
    }

    /**
     * REBUILT 4 Oct 2026 with the console. The `--ad-*` palette and admin.css this test
     * measured were destroyed; the console's colours are Support\Accent's console set, and
     * every word the console draws is measured on every ground in ConsolePaletteTest. This
     * keeps the original claim — body, secondary and muted text clear 4.5:1 on BOTH grounds
     * a console string sits on, the white card and the panel grey — by name.
     */
    public function test_admin_text_tokens_clear_aa_on_both_grounds(): void
    {
        foreach (['ink', 'grey-800', 'grey-600', 'grey-500'] as $name) {
            $hex = \AfricaGates\Support\Accent::consoleHex($name);
            foreach (['surface', 'fill-panel'] as $g) {
                $r = self::ratio($hex, \AfricaGates\Support\Accent::consoleHex($g));
                $this->assertGreaterThanOrEqual(4.5, round($r, 2), sprintf('%s is %.2f:1 on %s', $name, $r, $g));
            }
        }
    }

    /**
     * No inline `var(--ad-text-mute, …)` fallback may be a colour the token itself
     * would fail. A fallback that fails is a live defect on any surface that does not
     * load admin.css, and it silently outlives a fix to the token.
     */
    public function test_muted_token_fallbacks_are_not_worse_than_the_token(): void
    {
        $bad = [];
        foreach (self::twigAndCss() as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match_all('/var\(\s*--ad-text-mute\s*,\s*(#[0-9a-fA-F]{6})\s*\)/', $src, $m)) {
                continue;
            }
            foreach (array_unique($m[1]) as $hex) {
                $r = min(self::ratio($hex, self::ADMIN_SURFACE), self::ratio($hex, self::ADMIN_GROUND));
                if ($r < 4.5) {
                    $bad[] = sprintf('%s uses fallback %s (%.2f:1)', self::rel($file), $hex, $r);
                }
            }
        }
        $this->assertSame([], $bad, "Failing --ad-text-mute fallbacks:\n" . implode("\n", $bad));
    }

    /**
     * The smallest admin button must still be a legal target.
     *
     * These sit in row-action clusters — eleven on the nominees table alone — so the
     * 2.5.8 spacing exception does not rescue them: adjacent targets that close together
     * fail the undrawn-circle test.
     */
    public function test_smallest_admin_button_meets_target_size_minimum(): void
    {
        $css = self::css('public/assets/css/console/console.css');
        $this->assertSame(1, preg_match('/\.ad-btn--xs\s*\{[^}]*min-height:\s*(\d+)px/', $css, $m),
            '.ad-btn--xs must declare a min-height so the floor is explicit.');
        $this->assertGreaterThanOrEqual(24, (int) $m[1],
            'WCAG 2.5.8 sets 24×24 CSS px as the minimum target, for any pointer type.');
    }

    /**
     * The sidebar's group labels. The old rail was dark and its headings were measured
     * white-on-ink; the console's rail is `fill-panel` and the handoff draws its labels in
     * `grey-450` — 3.10:1, under the floor. The owner's rule is REPORT, never re-value, so
     * this holds that the failure is on the reported list rather than silently shipped.
     */
    public function test_sidebar_section_headings_clear_aa_on_the_rail(): void
    {
        $r = self::ratio(\AfricaGates\Support\Accent::consoleHex('grey-450'), \AfricaGates\Support\Accent::consoleHex('fill-panel'));
        if (round($r, 2) < 4.5) {
            $this->assertArrayHasKey('grey-450@fill-panel', \AfricaGates\Support\Accent::consoleReported(),
                sprintf('the rail labels are %.2f:1 and nobody has been told', $r));
        } else {
            $this->addToAssertionCount(1);
        }
        $css = self::css('public/assets/css/console/console.css');
        $this->assertMatchesRegularExpression('/\.cn-nav__label\s*\{[^}]*color:\s*var\(--cn-grey-450\)/', $css);
    }

    /**
     * The accessibility layer must reach every surface, not just the public site.
     *
     * a11y.css carries the touch-target minimums, the forced-colors (Windows High
     * Contrast) handling, the prefers-contrast strengthening, the aria-invalid field
     * styling and the canonical `.sr-only`. Shipping it on one layout of three meant
     * staff-facing surfaces silently opted out of all of it. (The public layout,
     * `layout/gates.twig`, left this list when it was destroyed; `layout/shell.twig`
     * does not load a11y.css yet — see docs/handoff/DESTROYED.md.)
     */
    public function test_every_layout_loads_the_accessibility_layer(): void
    {
        // The judge console still loads the shared layer.
        $this->assertStringContainsString('a11y.css', self::css('templates/judge/layout.twig'),
            'templates/judge/layout.twig must load a11y.css — it is the WCAG correction layer.');

        // The admin console carries the same corrections in its own sheet since the 4 Oct
        // 2026 rebuild (a11y.css is written against the public palette): targets under a
        // coarse pointer, forced colours, prefers-contrast, aria-invalid, and .sr-only.
        $this->assertStringContainsString("/assets/css/console/console.css", self::css('templates/admin/layout.twig'));
        $console = self::css('public/assets/css/console/console.css');
        foreach (['@media (pointer: coarse)', '@media (forced-colors: active)', '@media (prefers-contrast: more)',
                  '[aria-invalid="true"]', '.sr-only {', 'min-height: 44px'] as $rule) {
            $this->assertStringContainsString($rule, $console, "the console lost the accessibility rule: $rule");
        }
    }

    /** `.sr-only` must resolve on every surface that uses it, or hidden labels become visible text. */
    public function test_sr_only_is_defined_wherever_it_is_used(): void
    {
        // Where the class is defined at all.
        $defined = [];
        foreach (['public/assets/css/a11y.css', 'public/assets/css/console/console.css'] as $sheet) {
            if (str_contains(self::css($sheet), '.sr-only')) {
                $defined[] = $sheet;
            }
        }
        $this->assertNotEmpty($defined, '.sr-only must be defined in at least one shipped sheet.');
        $this->assertStringContainsString('.sr-only {', self::css('public/assets/css/a11y.css'),
            'a11y.css is the canonical home for .sr-only; every layout loads it.');
    }

    /**
     * The greys retired from the public site must not come back.
     *
     * Six literal greys were carrying metadata and hint text on white cards at between
     * 2.3:1 and 4.4:1 — shop delivery notes, the account dashboard's "your email is fixed
     * here", event schedule bodies, message timestamps. They were replaced with
     * `--ag-soft`, which the token file already documents as the AA-safe secondary
     * ink, rather than with six new near-identical greys.
     *
     * a11y.css had already diagnosed two of them by name and corrected exactly two
     * selectors, which is how the other forty-odd survived. This asserts the diagnosis
     * was applied everywhere rather than at the two call sites somebody happened to hit.
     *
     * Dark grounds are exempt and excluded: the same family of light greens and golds is
     * used deliberately on the vote hero and the dark support prompt, where they are the
     * legible choice.
     */
    public function test_retired_low_contrast_greys_do_not_return(): void
    {
        $retired = ['#8a9a9c', '#92a6a7', '#9aa6a8', '#7f9293', '#7d8c8e', '#8a969a', '#6b7d7f', '#717a7e'];
        $re = '/color:\s*(' . implode('|', array_map('preg_quote', $retired)) . ')\b/i';

        $offenders = [];
        foreach (self::twigAndCss() as $file) {
            $rel = self::rel($file);
            // Admin and judge carry their own palettes, asserted by the token test above.
            if (str_contains($rel, '/admin/') || str_contains($rel, 'judge')) {
                continue;
            }
            if (preg_match_all($re, (string) file_get_contents($file), $m)) {
                foreach (array_unique($m[1]) as $hex) {
                    $offenders[] = "$rel uses $hex";
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These greys fail 4.5:1 on the light grounds they are used on. Use "
            . "var(--ag-soft,#626a6e) (5.14:1 at worst) instead:\n%s",
            implode("\n", $offenders)
        ));
    }

    /** The token those greys were replaced with must itself stay AA on every light ground. */
    public function test_ink_soft_token_is_aa_on_light_grounds(): void
    {
        // READ FROM Support\Accent, NOT FROM A STYLESHEET: it is the one place a colour
        // lives, so the value measured here is the value the page receives.
        $ink = \AfricaGates\Support\Accent::hex('soft');

        // Only the grounds it is LEGAL on, from Accent's own table. It measures 4.38 on
        // `tint` and is refused there — `SlotFloorTest` holds that the refusal is real.
        foreach (\AfricaGates\Support\Accent::words()['soft'] as $name) {
            $ground = \AfricaGates\Support\Accent::hex($name);
            $r = self::ratio(strtolower($ink), $ground);
            $this->assertGreaterThanOrEqual(4.5, $r, sprintf(
                'ink-soft (%s) is %.2f:1 on %s — it is the site-wide secondary ink and '
                . 'roughly sixty declarations depend on it clearing AA.', $ink, $r, $name
            ));
        }
    }

    /** @return list<string> */
    private static function twigAndCss(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];
        foreach (['templates', 'public/assets/css'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir"));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(twig|css)$/', $f->getFilename())) {
                    $out[] = $f->getPathname();
                }
            }
        }
        return $out;
    }

    private static function rel(string $abs): string
    {
        return str_replace(dirname(__DIR__, 2) . '/', '', $abs);
    }
}
