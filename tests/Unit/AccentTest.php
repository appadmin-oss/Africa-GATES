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
    /*
     * ── WHAT MOVED OUT OF THIS FILE, AND WHY ─────────────────────────────────
     *
     * The floor checks, the wash checks and the emitted-sheet check now live in
     * `SlotFloorTest`, which enumerates PAIRS — every ink against every ground it can
     * actually be drawn on — rather than measuring each value against the ground alone.
     * That distinction is not academic: the moment `surface-2` was added, the version in
     * this file went on calling `ink-soft` and `live` ink passing while both had dropped
     * to 4.38 and 4.36 on a surface the site uses for every hover row.
     *
     * Two tests asserting the same thing with different rigour is worse than either alone,
     * so the weaker ones are gone rather than left to disagree. What stays is what
     * SlotFloorTest does not ask: hue drift, the per-screen role ceiling, the deuteranope
     * separation, and the behaviour of a role nobody defined.
     */


    public function test_the_ink_of_a_role_is_recognisably_the_same_colour_as_its_fill(): void
    {
        // Darkening for contrast is how an accessibility pass turns a palette to mud: lift
        // a gold far enough and it is a brown, and the page has complied its way out of
        // having an accent at all. Hue is held within a narrow band of the identity.
        foreach (Accent::roles() as $role) {
            // A fault has no hue by design — it is the ground inverted — so there is
            // nothing for it to drift from.
            if (Accent::inverted($role)) continue;

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

    /**
     * No screen wears more than two accents AS FIELDS.
     *
     * Restraint is the setup and colour is the payoff, so the payoff must be rare — and
     * rarity is not something a palette can hope for. A screen reaching for four accents
     * has none: every one of them is a background.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS COUNTS FIELDS AND NOT TOKENS, WHICH IS A NARROWING
     * ══════════════════════════════════════════════════════════════════════════
     *
     * It used to match any `--ag-<role>-*`, and on that reading /results wears three:
     * `live` on the chip for an award that is counting, `caution` on the OUTLINE of one
     * being withheld, and `action` on the focus ring. Two of those three are not colour a
     * reader can point at. An outline is a boundary and a focus ring is a keyboard
     * affordance, and forbidding them pushes a page towards two bad answers — FILLING the
     * withheld chip, which makes the absence of one result the loudest thing on a page of
     * good news, or dropping the focus ring, which is a WCAG failure traded for a palette
     * rule.
     *
     * So the ceiling is on `fill` and `wash` — the slots that produce a bounded coloured
     * AREA — and `edge` and `ink` are structure, exactly as `SlotFloorTest` already treats
     * them and as `components/tile.css` documents. How MUCH colour one page may spend is a
     * different question with a different answer per page type, and it is
     * {@see \Tests\Unit\ColourBudgetTest}'s, not this one's: tier 0 allows no field at
     * all, and a printed ticket legitimately needs three.
     *
     * This test keeps the thing a budget cannot express — that no screen, at any tier,
     * wears three different accents as fields at once, because that is a screen with no
     * accent rather than a screen over budget.
     */
    public function test_no_screen_wears_more_than_two_accents_at_once(): void
    {
        $offenders = [];

        foreach ($this->publicTemplates() as $path => $body) {
            // Twig comments reach no reader, so a comment naming the roles — this file's
            // own explanation, or a note above a change — was never in scope. Same lesson
            // GlobeBandTest records: sweep what a READER sees.
            $body = $this->withoutFocusRings($body);
            $alt  = $this->exclusiveRole($body);
            $seen = [];
            foreach (Accent::roles() as $role) {
                if ($role === $alt) continue;
                if (preg_match('/var\(\s*--ag-' . $role . '-(?:fill|wash)\b/',
                               (string) preg_replace('/\{#.*?#\}/s', '', $body))) {
                    $seen[] = $role;
                }
            }

            if (count($seen) > 2) $offenders[] = basename($path) . ': ' . implode(', ', $seen);
        }

        $this->assertSame([], $offenders,
            "these screens wear more than two accents as fields, so they wear none:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * And the narrowing is not a hole: an edge or an ink is still a ROLE, and a screen
     * reaching for every role it can name is still doing the thing this file forbids.
     *
     * Asserted separately at a higher ceiling, so the two questions stay apart. Three is
     * the number a real page reaches honestly — a live chip, a withheld outline and a
     * focus ring is /results, and all three are correct — and four is a page using colour
     * as its vocabulary.
     */
    public function test_no_screen_names_every_role_it_can_reach_for(): void
    {
        $offenders = [];

        foreach ($this->publicTemplates() as $path => $body) {
            $body = $this->withoutFocusRings($body);
            $alt  = $this->exclusiveRole($body);
            $seen = [];
            foreach (Accent::roles() as $role) {
                if ($role === $alt) continue;
                if (preg_match('/var\(\s*--ag-' . $role . '-/',
                               (string) preg_replace('/\{#.*?#\}/s', '', $body))) {
                    $seen[] = $role;
                }
            }

            if (count($seen) > 3) $offenders[] = basename($path) . ': ' . implode(', ', $seen);
        }

        $this->assertSame([], $offenders,
            "these screens reach for four or more roles, which is colour as a vocabulary:\n  "
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
    public function test_every_programme_hue_stays_apart_for_a_deuteranope(): void
    {
        // All THREE now, not the first four of five. Cutting ochre and moss — which shared
        // families with the honour gold and the action green — left a set small enough
        // that EVERY pair can be held rather than only the leading few. That is the
        // compensation for a shorter palette and it is worth stating: fewer hues, all of
        // them separable.
        $keys = Accent::programmeHues();
        $this->assertCount(3, $keys, 'the programme palette has changed size');

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
                if (Accent::inverted($role)) continue;
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
        // Three hues, so three distinct programmes; a fourth wraps to the first and its
        // printed name separates them — the same answer the sixth hue got.
        $seen = [];
        foreach ([1, 2, 3] as $id) $seen[] = Accent::forProgramme($id)['key'];
        $this->assertSame($seen, array_unique($seen), 'two early programmes share a hue');
        $this->assertSame(Accent::forProgramme(1)['key'], Accent::forProgramme(4)['key']);

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

    /**
     * The same body with its focus rules removed.
     *
     * A focus ring is `action` green and is the one non-CTA use of that role on this
     * platform. It is not an accent a reader can point at — it is a keyboard affordance,
     * present on every interactive element and visible only while one is focused — and
     * counting it against a palette ceiling pushes a page towards one of two bad answers:
     * dropping the ring, which trades a WCAG 2.4.7 failure for a palette rule, or
     * FILLING something else to stay under the count.
     *
     * It came up on the rebuilt edition page, which reaches for honour (its band),
     * caution (a withheld award's outline) and live (one still counting) — three correct
     * accents — and was reported as four because it also draws a focus ring.
     */
    private function withoutFocusRings(string $body): string
    {
        // The rule's whole block, brace to brace. `:focus-within` and `:focus-visible` are
        // both matched by the same prefix, and a selector list ("a:hover, a:focus-visible")
        // is deliberately included: those rules paint the same affordance.
        return (string) preg_replace('/[^{}\n][^{}]*:focus[a-z-]*[^{}]*\{[^{}]*\}/i', ' ', $body);
    }

    /**
     * NO ROLE ACCENT IN THE CHROME, AND `honour` LEAST OF ALL.
     *
     * Chrome is on every page, so a role spent there is spent on the privacy policy, the
     * refunds page and the cookie notice — every tier-0 screen that is supposed to ask
     * nothing. That is also why the budget excuses chrome from a page's count: the one
     * event it is allowed is the Register pill, which is `action` and is the site's single
     * standing call to do something.
     *
     * ── THE ONE THAT ACTUALLY SHIPPED ────────────────────────────────────────
     *
     * The two drop-down menus carried twenty-four 38px tiles, each an emoji on a pale
     * ground with its own hand-derived ink — forty-eight literal colours, none from the
     * palette. SEVEN of them were honour gold (`#fff8df` on `#7a5600`), behind a
     * Leaderboard link, a Shop link and a Create-an-account link.
     *
     * On this platform gold means an award has been decided for a named person. Spending
     * it on a menu row is the fastest available way to make it stop meaning that, and it
     * was doing so on every page of the site at once. The literals are caught by
     * `NoLiteralHexTest` now; this catches the same fault committed properly, through a
     * token.
     */
    public function test_the_chrome_spends_no_role_but_the_one_call_to_action(): void
    {
        $root = dirname(__DIR__, 2);
        $bad  = [];

        foreach (glob($root . '/templates/layout/*.twig') as $path) {
            $body = (string) preg_replace('/\{#.*?#\}/s', '',
                (string) file_get_contents($path));
            $body = $this->withoutFocusRings($body);

            foreach (Accent::roles() as $role) {
                // `action` is the Register pill, and `live` is the Pulse dot — the one
                // thing in the chrome that is true right now rather than decorative.
                if ($role === Accent::ACTION || $role === Accent::LIVE) continue;

                if (preg_match('/var\(\s*--ag-' . $role . '-/', $body)) {
                    $bad[] = basename($path) . ': ' . $role;
                }
            }
        }

        $this->assertSame([], $bad,
            "the chrome is spending a role accent, which spends it on every page of the "
          . "site including the ones that must ask nothing:\n  " . implode("\n  ", $bad));
    }

    /**
     * A role the page draws only in a state that excludes its others.
     *
     * Both ceilings here read a FILE, which cannot see that two branches of a template
     * never render together. A nominee's ballot carries a winner's laurel in gold and a
     * vote button in green, and an award that has been decided has closed its voting — so
     * a reader never sees both. Declared in the template and counted by
     * {@see \Tests\Unit\ColourBudgetTest}, which is where the list of pages allowed to
     * claim it lives.
     */
    private function exclusiveRole(string $body): string
    {
        return preg_match('/\{%-?\s*set\s+colour_alt\s*=\s*[\'"]([a-z]+)[\'"]\s*-?%\}/',
                          $body, $m) ? $m[1] : '';
    }
}
