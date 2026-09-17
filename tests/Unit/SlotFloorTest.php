<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

/**
 * Every ink against every ground it may legally be drawn on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ENUMERATES PAIRS AND NOT VALUES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The previous sweep measured each value against the ground and stopped. That is a test of
 * a palette in isolation, and a palette is never used in isolation — and the moment one
 * new neutral was added it broke two inks that the old test still called passing.
 *
 * `surface-2` `#e8e5dd` is only a 1.29:1 step off the ground. That step consumes the
 * margin anything near 4.5 was relying on:
 *
 *     ink-soft   4.80 on ground  →  4.38 on surface-2   FAILS
 *     live ink   4.78 on ground  →  4.36 on surface-2   FAILS
 *
 * Both were "passing" the whole time. So the rule is now: **any ground a value is actually
 * drawn on is a ground this test must enumerate.** A neutral cleared at 4.80 on paper has
 * no margin left for a surface 1.24× darker.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FIGURES ARE MEASURED HERE, NOT COPIED FROM THE HANDOFF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff quotes its own ratios and 24 of 27 reproduce exactly. Three do not —
 * `ink-2` on `desk` (it claims 6.13, measures 6.52), and house ink on the `live` and
 * `caution` washes (12.6 and 12.7 claimed, 12.33 and 12.43 measured). None crosses a
 * floor, so nothing in the design changes; but a test that asserted the quoted numbers
 * would be asserting three wrong ones. Floors are asserted; quoted values are not.
 */
final class SlotFloorTest extends TestCase
{
    /** House ink, for every ink-on-wash measurement. */
    private const HOUSE_INK = '#10292c';

    // ══ the neutral ramp ═════════════════════════════════════════════════════

    public function test_each_neutral_clears_its_floor_on_every_ground_it_is_legal_on(): void
    {
        // The legality table from the handoff, as pairs. `mute` is absent from every row
        // because it is never a word — that is asserted separately below.
        $legal = [
            'ink'      => ['ground', 'card', 'surface-2', 'desk'],
            'ink-2'    => ['ground', 'card', 'surface-2', 'desk'],
            // NOT surface-2 (4.38) and NOT desk (3.88). This is the whole finding.
            'ink-soft' => ['ground', 'card'],
        ];

        foreach ($legal as $name => $grounds) {
            foreach ($grounds as $g) {
                $got = Contrast::ratio(Accent::neutral($name), Accent::grounds()[$g]);

                $this->assertGreaterThanOrEqual(Contrast::TEXT, round($got, 2), sprintf(
                    '%s on %s is %.2f:1 and it owes %.1f',
                    $name, $g, $got, Contrast::TEXT));
            }
        }
    }

    public function test_the_grounds_ink_soft_is_refused_on_are_refused_for_a_reason(): void
    {
        // Proving the exclusions above are real rather than cautious. If either of these
        // ever passes, `ink-soft` has been lightened or a ground has, and the legality
        // table needs rewriting rather than quietly widening.
        foreach (['surface-2', 'desk'] as $g) {
            $got = Contrast::ratio(Accent::neutral('ink-soft'), Accent::grounds()[$g]);

            $this->assertLessThan(Contrast::TEXT, $got, sprintf(
                'ink-soft now clears %.2f:1 on %s — the legality table says it cannot, and '
                . 'one of the two has changed', $got, $g));
        }
    }

    public function test_mute_is_never_a_word(): void
    {
        // 2.75:1 on the ground. It exists for disabled glyphs and decorative rules, and
        // the ramp is only safe because this one is fenced off.
        $this->assertFalse(Accent::neutralTakesText('mute'));
        $this->assertLessThan(Contrast::UI, Contrast::ratio(Accent::neutral('mute'), Accent::PAPER),
            'mute now clears the non-text floor — if that is deliberate, it is no longer mute');

        // And every neutral that DOES claim to take text actually can, on the ground.
        foreach (Accent::neutrals() as $name => $v) {
            if (!$v['text']) continue;
            $this->assertGreaterThanOrEqual(Contrast::TEXT,
                round(Contrast::ratio($v['hex'], Accent::PAPER), 2), $name);
        }
    }

    public function test_the_ground_is_warm_and_nothing_crossed_a_floor_when_it_moved(): void
    {
        // The cost of the warm ground was stated rather than discovered: every ratio drops
        // about 2%, and `ink-soft` lands at 4.80 against a 4.5 requirement — the tightest
        // margin in the set, and the token that fails first if anybody lightens it.
        $this->assertSame('#f1efe9', strtolower(Accent::PAPER));

        $tightest = Contrast::ratio(Accent::neutral('ink-soft'), Accent::PAPER);
        $this->assertGreaterThanOrEqual(Contrast::TEXT, round($tightest, 2));
        $this->assertLessThan(5.2, $tightest,
            'ink-soft has drifted away from its margin — check nothing else moved with it');
    }

    // ══ the roles ════════════════════════════════════════════════════════════

    public function test_every_role_slot_clears_its_floor_on_the_ground_and_on_a_card(): void
    {
        foreach (Accent::roles() as $role) {
            if (Accent::inverted($role)) continue;   // measured the other way round, below

            foreach (['edge' => Contrast::UI, 'ink' => Contrast::TEXT] as $slot => $floor) {
                foreach (['ground', 'card'] as $g) {
                    $got = Contrast::ratio(Accent::of($role)[$slot], Accent::grounds()[$g]);

                    $this->assertGreaterThanOrEqual($floor, round($got, 2), sprintf(
                        '%s.%s on %s is %.2f:1 and it owes %.1f', $role, $slot, $g, $got, $floor));
                }
            }
        }
    }

    public function test_a_wash_holds_house_ink_well_above_the_text_floor(): void
    {
        // 12:1, not 4.5. A wash is a large field that body copy sits on, and a wash that
        // only just cleared the text floor would leave nothing for the metadata on it.
        foreach (Accent::roles() as $role) {
            if (Accent::inverted($role)) continue;

            $got = Contrast::ratio(self::HOUSE_INK, Accent::wash($role));
            $this->assertGreaterThanOrEqual(12.0, round($got, 2),
                sprintf('house ink on the %s wash is %.2f:1', $role, $got));
        }
    }

    /**
     * THE TILE, WHICH IS THE SYSTEM'S CENTRAL DEVICE — AND ITS ONE FAILURE.
     *
     * A role ink on its own wash IS the tile. Three of the four clear it; `live` does not,
     * at 4.44, and that is a stated exception rather than a bug: a live tile's label is
     * house `ink` and the DOT carries the hue.
     *
     * Asserted by name, both ways round, so neither half can quietly change: the three
     * that work must keep working, and the one that does not must keep being handled.
     */
    public function test_a_role_ink_sits_on_its_own_wash_except_the_one_that_cannot(): void
    {
        foreach ([Accent::HONOUR, Accent::CAUTION, Accent::ACTION] as $role) {
            $got = Contrast::ratio(Accent::ink($role), Accent::wash($role));
            $this->assertGreaterThanOrEqual(Contrast::TEXT, round($got, 2),
                sprintf('the %s tile no longer holds its own label (%.2f:1)', $role, $got));
        }

        $live = Contrast::ratio(Accent::ink(Accent::LIVE), Accent::wash(Accent::LIVE));
        $this->assertLessThan(Contrast::TEXT, $live, sprintf(
            'live ink now clears its own wash at %.2f:1. If that is real, the rule that a '
            . 'live tile labels in house ink can be retired — but retire it deliberately, '
            . 'because the dot carrying the hue is what makes the tile readable today', $live));
    }

    public function test_no_role_ink_may_sit_on_surface_2(): void
    {
        // Refused by name in the handoff, and this is why: the step that makes a hover row
        // visible is the step that eats a role ink's margin. On `surface-2` a role ink
        // becomes house ink.
        foreach (Accent::roles() as $role) {
            if (Accent::inverted($role)) continue;

            $got = Contrast::ratio(Accent::ink($role), Accent::grounds()['surface-2']);
            if ($got >= Contrast::TEXT) {
                // Not a failure for honour (5.6) or caution — the rule is a blanket one so
                // nobody has to check per role. Assert the two that genuinely cannot.
                $this->assertNotContains($role, [Accent::LIVE, Accent::ACTION],
                    sprintf('%s ink measures %.2f:1 on surface-2', $role, $got));
            }
        }

        $this->assertLessThan(Contrast::TEXT,
            Contrast::ratio(Accent::ink(Accent::LIVE), Accent::grounds()['surface-2']),
            'live ink on surface-2 was the second value the new neutral broke');
    }

    // ══ the role with no hue ═════════════════════════════════════════════════

    public function test_a_fault_is_the_ground_inverted_and_is_measured_that_way(): void
    {
        // Paper type on ink. It costs no new colour, cannot be confused with a withheld
        // award, and survives every form of colour vision deficiency because it is a
        // lightness difference and not a hue one.
        $this->assertTrue(Accent::inverted(Accent::FAULT));

        $got = Contrast::ratio(Accent::PAPER, Accent::wash(Accent::FAULT));
        $this->assertGreaterThanOrEqual(12.0, round($got, 2),
            sprintf('paper on the fault block is %.2f:1', $got));

        // And it must not have acquired a hue. Measured as saturation, not as a name.
        $h = Contrast::hex(Accent::fill(Accent::FAULT));
        $r = hexdec(substr($h, 0, 2)); $g = hexdec(substr($h, 2, 2)); $b = hexdec(substr($h, 4, 2));
        $this->assertLessThan(40, max($r, $g, $b) - min($r, $g, $b),
            'the fault role has grown a hue — the point of it is that it has none');
    }

    // ══ programme identity ═══════════════════════════════════════════════════

    public function test_the_programme_hues_that_were_cut_are_gone_and_stay_gone(): void
    {
        // ochre shared the honour gold's family — a programme wearing it on a results page
        // reads as a decided award. moss is 31° from the action green, so a moss spine
        // beside a green button asks the reader which green is the instruction.
        $this->assertSame(['indigo', 'teal', 'plum'], Accent::programmeHues());

        foreach (Accent::allProgrammeHues() as $v) {
            foreach (['#d58f16', '#7ab733'] as $cut) {
                $this->assertNotSame($cut, strtolower($v['hex']),
                    'a cut programme hue is back in the table');
            }
        }
    }

    public function test_the_stated_programme_assignment_is_what_the_code_gives(): void
    {
        // The designs were drawn against this mapping. A plain `% count` hands id 1 the
        // SECOND hue and silently contradicts every artboard.
        $this->assertSame('indigo', Accent::forProgramme(1)['key'], 'Incredible Principal Awards');
        $this->assertSame('teal',   Accent::forProgramme(2)['key'], 'African Creative Honours');
        $this->assertSame('plum',   Accent::forProgramme(3)['key']);
        $this->assertSame('indigo', Accent::forProgramme(4)['key'], 'a fourth wraps');
    }

    // ══ asking for a colour by meaning ═══════════════════════════════════════

    public function test_a_colour_is_reachable_by_what_it_means(): void
    {
        $this->assertSame(Accent::of(Accent::CAUTION), Accent::for('withheld'));
        $this->assertSame(Accent::of(Accent::LIVE),    Accent::for('voting-open'));
        $this->assertSame(Accent::of(Accent::HONOUR),  Accent::for('award-decided'));
        $this->assertSame(Accent::of(Accent::FAULT),   Accent::for('form-error'));
    }

    public function test_a_meaning_nobody_defined_stops_the_build(): void
    {
        // Deliberately the opposite of of()'s fallback. of() is asked for a role a template
        // already named, where a blank strips an element's meaning on a live page. for() is
        // asked at the point somebody is CHOOSING, and a page that says "withheld" in
        // caution red when it meant "form error" is worse than a page that fails.
        $this->expectException(\InvalidArgumentException::class);
        Accent::for('danger');
    }

    public function test_every_meaning_resolves_to_a_role_that_exists(): void
    {
        foreach (Accent::meanings() as $meaning => $role) {
            $this->assertContains($role, Accent::roles(), $meaning);
            $this->assertNotSame('', trim(Accent::for($meaning)['ink']));
        }
    }

    // ══ the emitted sheet ════════════════════════════════════════════════════

    public function test_the_page_receives_the_ramp_as_well_as_the_roles(): void
    {
        // The neutrals are emitted from here rather than written into base/tokens.css so
        // that every colour on the site has ONE source — which is the only way a
        // literal-hex sweep over the templates can ever be true.
        $css = Accent::css();

        $this->assertStringContainsString('--ag-ground:' . Accent::PAPER . ';', $css);
        foreach (Accent::neutrals() as $name => $v) {
            $this->assertStringContainsString('--ag-' . $name . ':' . $v['hex'] . ';', $css);
        }

        $this->assertStringContainsString('--ag-action-lip:#145213;', $css,
            'the press shade never reaches a button');

        // Still nothing but custom properties and hex digits.
        $this->assertMatchesRegularExpression('/^:root\{(--ag-[a-z0-9-]+:#[0-9a-f]{6};)+\}$/', $css);
    }
}
