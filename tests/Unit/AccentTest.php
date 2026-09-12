<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

/**
 * The four colours this platform may mean something with, and the floors they owe.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS MEASURED, AND WHY IT EXPLAINS A SITE THAT FEELS COLOURLESS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The house style names one gold accent, `#f3b416`. On the house paper `#f0f2f2` it is
 * **1.65:1** — under the 3:1 a border owes and well under the 4.5:1 a word owes. So the
 * one colour the site was allowed to be was invisible wherever it was actually used: as a
 * hairline and as a mono micro-label. The palette was not missing; it was being applied in
 * the two places its value could never show.
 *
 * Also measured, and each its own finding: `--ag-gold` in `base/tokens.css` is a SECOND
 * gold (`#c9a24b`, 2.13:1) the house style does not mention; `--ag-green-light` is
 * 1.79:1; and `--ag-pulse` is 4.08:1 — a pass for a border, a FAIL for a word, and it was
 * a word on three public screens.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FLOORS ARE RE-DERIVED HERE, NOT COPIED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see Accent} stores constants, because re-deriving a fixed palette on every render is
 * arithmetic nobody asked for. This is the half that keeps them honest: it measures every
 * stored value against the real ground and fails naming the slot. Same discipline as
 * `HandbookTest` re-deriving the figures it prints.
 */
final class AccentTest extends TestCase
{
    public function test_every_value_that_owes_a_contrast_floor_clears_it(): void
    {
        foreach (Accent::all() as $v) {
            $floor = Accent::floor($v['slot']);
            if ($floor === null) continue;

            foreach ([Accent::PAPER, Accent::SURFACE] as $ground) {
                $got = Contrast::ratio($v['hex'], $ground);

                $this->assertGreaterThanOrEqual($floor, round($got, 2), sprintf(
                    "%s.%s is %s — %.2f:1 on %s, and it owes %.1f:1",
                    $v['role'], $v['slot'], $v['hex'], $got, $ground, $floor));
            }
        }
    }

    public function test_the_house_ink_is_readable_on_every_wash(): void
    {
        // A wash is the one place an accent may be loud, and the only way that is safe is
        // if words keep sitting on it. Asserted at the large floor and then some: a wash
        // carries body copy, a figure and a caption, not a heading alone.
        foreach (Accent::roles() as $role) {
            $wash = Accent::wash($role);
            $got  = Contrast::ratio('#10292c', $wash);

            $this->assertGreaterThanOrEqual(Contrast::TEXT, $got,
                "house ink on the {$role} wash is {$got}:1");

            // And it must read as a COLOUR rather than as a smudge — a wash within a
            // whisker of the paper is a wash nobody sees, which is the fault one level up
            // from the one this class exists for.
            $this->assertNotSame(strtolower(Accent::PAPER), strtolower($wash));
        }
    }

    public function test_the_ink_of_a_role_is_recognisably_the_same_colour_as_its_fill(): void
    {
        // Darkening for contrast is how an accessibility pass turns a palette to mud: lift
        // a gold far enough and it is a brown, and the page has complied its way out of
        // having an accent at all. Hue is held within a narrow band of the identity.
        foreach (Accent::roles() as $role) {
            $fillHue = $this->hue(Accent::fill($role));
            foreach (['edge', 'ink'] as $slot) {
                $got = $this->hue(Accent::of($role)[$slot]);
                $d   = min(abs($fillHue - $got), 360 - abs($fillHue - $got));

                $this->assertLessThanOrEqual(18.0, $d, sprintf(
                    '%s.%s has drifted %.1f° from the identity — it is no longer that colour',
                    $role, $slot, $d));
            }
        }
    }

    public function test_the_palette_the_page_receives_is_the_palette_the_tests_measured(): void
    {
        // The declaration and the assertion drifting apart is exactly how four golds got
        // into circulation. The layout reads Accent::css(); nothing types these again.
        $css = Accent::css();

        foreach (Accent::all() as $v) {
            $this->assertStringContainsString(
                '--ag-' . $v['role'] . '-' . $v['slot'] . ':' . $v['hex'] . ';', $css);
        }

        // Nothing but custom properties and hex digits reaches a <style> block.
        $this->assertMatchesRegularExpression('/^:root\{(--ag-[a-z]+-[a-z]+:#[0-9a-f]{6};)+\}$/', $css);
    }

    public function test_an_unknown_role_is_a_colour_and_not_a_blank(): void
    {
        // `var(--ag-typo-ink)` resolves to nothing and the element silently loses its
        // meaning, which is worse on a live page than the wrong accent.
        $this->assertSame(Accent::of(Accent::ACTION), Accent::of('honor'));   // US spelling
        $this->assertSame(Accent::of(Accent::ACTION), Accent::of(''));
        $this->assertSame(Accent::of(Accent::HONOUR), Accent::of('  HONOUR '));
    }

    public function test_every_role_says_what_it_is_allowed_to_mean(): void
    {
        // A role with no stated meaning becomes whichever colour somebody liked that day,
        // and the ceiling below stops being enforceable because nothing says what counts.
        foreach (Accent::roles() as $role) {
            $this->assertNotSame('', trim(Accent::means($role)), $role);
        }

        // Named specifically, because a red on a page about people must not be read as a
        // fault: this platform withholds and declines to measure far more often than it
        // errors, and a caveat dressed as an error reads as an accusation.
        $this->assertStringContainsString('not an error', Accent::means(Accent::CAUTION));
    }

    // ══ the ceiling, which is what keeps a payoff rare ════════════════════════

    public function test_no_screen_wears_more_than_two_accents_at_once(): void
    {
        // Restraint is the setup and colour is the payoff, so the payoff must be rare —
        // and rarity is not something a palette can hope for. A screen reaching for four
        // accents has none: every one of them is a background.
        $offenders = [];

        foreach ($this->publicTemplates() as $path => $body) {
            // Twig comments reach no reader, so a comment naming the roles — this file's
            // own explanation, or a note above a change — was never in scope. Same lesson
            // GlobeBandTest records: sweep what a READER sees.
            $seen = [];
            foreach (Accent::roles() as $role) {
                if (preg_match('/var\(\s*--ag-' . $role . '-/', (string) preg_replace('/\{#.*?#\}/s', '', $body))) {
                    $seen[] = $role;
                }
            }

            if (count($seen) > 2) $offenders[] = basename($path) . ': ' . implode(', ', $seen);
        }

        $this->assertSame([], $offenders,
            "these screens wear more than two accents, so they wear none:\n  "
            . implode("\n  ", $offenders));
    }

    /** @return array<string,string> */
    private function publicTemplates(): array
    {
        $dir = dirname(__DIR__, 2) . '/templates';
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;
            // The admin console is a dense tool used all day and has its own conventions;
            // this ceiling is about the public pages a visitor meets once.
            if (str_contains($f->getPathname(), '/admin/')) continue;

            $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
        }

        return $out;
    }

    private function hue(string $hex): float
    {
        $h = Contrast::hex($hex);
        $r = hexdec(substr($h, 0, 2)) / 255;
        $g = hexdec(substr($h, 2, 2)) / 255;
        $b = hexdec(substr($h, 4, 2)) / 255;

        $mx = max($r, $g, $b);
        $d  = $mx - min($r, $g, $b);
        if ($d == 0.0) return 0.0;

        return 60 * match (true) {
            $mx === $r => fmod((($g - $b) / $d) + ($g < $b ? 6 : 0), 6),
            $mx === $g => (($b - $r) / $d) + 2,
            default    => (($r - $g) / $d) + 4,
        };
    }
}
