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

    // ══ the categorical palette: identity, not meaning ═══════════════════════

    public function test_every_programme_hue_clears_the_same_floors_as_a_role(): void
    {
        // A categorical colour is not exempt from contrast because it carries no meaning.
        // The programme's name is printed IN its ink on both the hall and the archive.
        foreach (Accent::allProgrammeHues() as $v) {
            $floor = Accent::floor($v['slot']);
            if ($floor === null) continue;

            foreach ([Accent::PAPER, Accent::SURFACE] as $ground) {
                $got = Contrast::ratio($v['hex'], $ground);
                $this->assertGreaterThanOrEqual($floor, round($got, 2), sprintf(
                    '%s.%s is %s — %.2f:1 on %s, and it owes %.1f:1',
                    $v['role'], $v['slot'], $v['hex'], $got, $ground, $floor));
            }
        }
    }

    /**
     * THE ORDER IS THE ACCESSIBILITY DECISION, AND THIS IS WHAT IT BUYS.
     *
     * You cannot have six categorical hues that stay distinct for everybody: red-green is
     * exactly the axis a deuteranope loses, and `ochre` and `terracotta` simulate 1.7
     * apart — the same colour. So the list is ORDERED so consecutive assignments separate,
     * because a real deployment runs two or three programmes.
     *
     * Asserted on the first FOUR rather than on all six, deliberately. Demanding it of all
     * six would be a test that can only be satisfied by a palette of four, and the fifth
     * and sixth are real colours that are genuinely useful to most readers — the honest
     * position is that past four the hue stops being reliable and the NAME carries it,
     * which is true on every screen that prints one.
     */
    public function test_the_first_four_programmes_stay_apart_for_a_deuteranope(): void
    {
        $keys = array_slice(Accent::programmeHues(), 0, 4);
        $this->assertCount(4, $keys, 'the palette has shrunk below what the order protects');

        $worst = [INF, '', ''];
        foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i + 1) as $b) {
                $d = $this->deuteranopeDistance(
                    Accent::forProgrammeKey($a)['ink'], Accent::forProgrammeKey($b)['ink']);
                if ($d < $worst[0]) $worst = [$d, $a, $b];
            }
        }

        // 12 is comfortably above the point where two hues read as one; the shipped worst
        // pair among the first four is 15.5.
        $this->assertGreaterThan(12.0, $worst[0], sprintf(
            "'%s' and '%s' are %.1f apart under deuteranopia — two programmes would wear "
            . 'the same colour', $worst[1], $worst[2], $worst[0]));
    }

    public function test_no_programme_hue_can_be_mistaken_for_a_status(): void
    {
        // A programme wearing the caution red would read as a withheld award, and one
        // wearing the action green as something to press. The seeds avoid those bands;
        // this is what stops the next one being added into them.
        foreach (Accent::allProgrammeHues() as $v) {
            if ($v['slot'] !== 'fill') continue;

            foreach ([Accent::CAUTION, Accent::ACTION, Accent::LIVE] as $role) {
                $d = $this->hueGap($v['hex'], Accent::fill($role));
                $this->assertGreaterThan(22.0, $d, sprintf(
                    "the '%s' programme hue is %.1f° from the '%s' role — an identity that "
                    . 'reads as a status', $v['role'], $d, $role));
            }
        }
    }

    public function test_a_programme_keeps_its_colour_wherever_it_is_drawn(): void
    {
        // Derived from the programme's own id, never from its position in a list: a
        // colour that changed between the hall and the archive would be the opposite of
        // an identity. Same id, same answer, every time.
        $a = Accent::forProgramme(7);
        $this->assertSame($a, Accent::forProgramme(7));
        $this->assertStringContainsString($a['fill'], Accent::programmeStyle(7));

        // Distinct for the ids a real deployment has.
        $seen = [];
        foreach ([1, 2, 3, 4] as $id) $seen[] = Accent::forProgramme($id)['key'];
        $this->assertSame($seen, array_unique($seen), 'two early programmes share a hue');

        // And it never returns a blank, whatever arrives — a missing custom property
        // resolves to nothing and the element silently loses its identity.
        foreach ([0, -3, PHP_INT_MAX] as $odd) {
            $this->assertNotSame('', trim(Accent::forProgramme($odd)['fill']), (string) $odd);
        }
    }

    public function test_the_style_attribute_can_carry_nothing_but_colour(): void
    {
        // It is interpolated into a `style=` attribute on every card. The values come from
        // the constant table, so this is a guard against somebody later deriving one from
        // a programme's own typed field.
        foreach ([1, 2, 3, 4, 5, 6, 7] as $id) {
            $this->assertMatchesRegularExpression(
                '/^(--pg-[a-z]+:#[0-9a-f]{6};?)+$/', Accent::programmeStyle($id));
        }
    }

    /** Viénot/Brettel deuteranope simulation, in linear LMS-derived RGB. */
    private function deuteranopeDistance(string $a, string $b): float
    {
        $sim = static function (string $hex): array {
            $h   = Contrast::hex($hex);
            $lin = static fn (float $v): float =>
                ($v /= 255) <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

            $r = $lin((float) hexdec(substr($h, 0, 2)));
            $g = $lin((float) hexdec(substr($h, 2, 2)));
            $b = $lin((float) hexdec(substr($h, 4, 2)));

            $L = 17.8824 * $r + 43.5161 * $g + 4.11935 * $b;
            $S = 0.0299566 * $r + 0.184309 * $g + 1.46709 * $b;
            // The deuteranope's M is reconstructed from L and S — that is the whole
            // simulation: the middle cone's own response is simply not available.
            $M = 0.494207 * $L + 1.24827 * $S;

            return [
                0.080944 * $L - 0.130504 * $M + 0.116721 * $S,
                -0.0102485 * $L + 0.0540194 * $M - 0.113615 * $S,
                -0.000365294 * $L - 0.00412163 * $M + 0.693513 * $S,
            ];
        };

        $x = $sim($a);
        $y = $sim($b);

        return sqrt(($x[0] - $y[0]) ** 2 + ($x[1] - $y[1]) ** 2 + ($x[2] - $y[2]) ** 2) * 100;
    }

    private function hueGap(string $a, string $b): float
    {
        $d = abs($this->hue($a) - $this->hue($b));

        return min($d, 360 - $d);
    }
}
