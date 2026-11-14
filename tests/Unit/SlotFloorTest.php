<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

/**
 * Every word against every ground it is drawn on — and the values the owner accepted
 * below a floor, held so they cannot quietly multiply.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ENUMERATES PAIRS AND NOT VALUES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A value is never drawn on "the" ground. The rule the previous palette taught survives
 * the rebuild intact: `soft` is 4.80 on the ground and 4.38 on `tint`, so any test that
 * measured it once, against the page, would call a caption on an icon tile passing. So
 * `Accent::words()` lists every ground each word token is drawn on, and every pair is
 * measured here on every run.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS RELAXED, BY WHOM, AND WHAT WAS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The owner decided "ship the handoff exactly" (GAPS §8 Q2, 2 Oct 2026). The handoff's
 * fills, lines and borders — the gold drawn as a line, `line`/`line-2`/`line-3`/`tint`,
 * the success and honour edges, the 1.48:1 outlined chip and input border, `mute` as
 * disabled text — are under the 3:1 a boundary owes, and are accepted rather than
 * lifted. The previous version of this test failed them; that rule is retired by the
 * owner's decision, not by drift.
 *
 * What is NOT relaxed is every token drawn as a word, at 4.5:1. If one of those fails,
 * the instruction is to leave the value alone and report it — so this test failing is
 * a finding for the owner, never a reason to edit a hex.
 */
final class SlotFloorTest extends TestCase
{
    public function test_every_word_clears_the_text_floor_on_every_ground_it_is_drawn_on(): void
    {
        $fails = [];

        foreach (Accent::words() as $word => $grounds) {
            foreach ($grounds as $g) {
                $got = Contrast::ratio(Accent::hex($word), Accent::hex($g));
                if (round($got, 2) < Contrast::TEXT) {
                    $fails[] = sprintf('%s on %s is %.2f:1', $word, $g, $got);
                }
            }
        }

        $this->assertSame([], $fails,
            "a word token is under 4.5:1 on a ground it is drawn on. Report it to the owner "
          . "— the palette ships exactly, so the value is not to be changed here:\n  "
          . implode("\n  ", $fails));
    }

    public function test_the_floors_the_owner_named_are_all_held(): void
    {
        // The list from the decision, by name, so a word token cannot drop out of
        // `words()` and stop being measured without this noticing.
        foreach (['ink', 'ink-2', 'soft', 'green-deep', 'gold-ink', 'live-ink', 'info',
                  'error', 'stock-low', 'stock-gone', 'surface'] as $word) {
            $this->assertArrayHasKey($word, Accent::words(), "$word is no longer measured as a word");
        }
        $this->assertContains('gold-wash', Accent::words()['gold-ink'], 'gold-ink is the text on the gold wash');
        $this->assertContains('green', Accent::words()['surface'], 'white on the primary green button');
    }

    public function test_the_grounds_soft_and_info_are_kept_off_are_kept_off_for_a_reason(): void
    {
        // Proving the exclusions are real rather than cautious. If either starts passing,
        // a value has moved and the table should be rewritten rather than quietly widened.
        foreach (['soft', 'info'] as $word) {
            $this->assertNotContains('tint', Accent::words()[$word]);
            $this->assertLessThan(Contrast::TEXT, Contrast::ratio(Accent::hex($word), Accent::hex('tint')),
                "$word now clears tint — the table says it cannot");
        }
    }

    public function test_mute_is_never_a_word(): void
    {
        $this->assertArrayNotHasKey('mute', Accent::words());
        $this->assertLessThan(Contrast::TEXT, Contrast::ratio(Accent::hex('mute'), Accent::hex('ground')),
            'mute now clears the text floor — if that is deliberate it is no longer mute');
    }

    public function test_what_was_accepted_below_a_floor_is_exactly_what_was_decided(): void
    {
        // Each accepted value must still measure what the decision said it does — under
        // the 3:1 a boundary owes — or the list is describing a palette that no longer
        // exists. And none of them may be drawn as a word.
        foreach (Accent::accepted() as $token => $a) {
            $got = Contrast::ratio(Accent::hex($token), Accent::hex($a['on']));
            $this->assertLessThan(Contrast::UI, $got, sprintf(
                '%s now measures %.2f:1 on %s — it is no longer below the floor it was accepted under',
                $token, $got, $a['on']));
            $this->assertArrayNotHasKey($token, Accent::words(), "$token was accepted as a non-word");
            $this->assertNotSame('', trim($a['why']), $token);
        }

        // Named, because "the outlined chip border" is the one a reviewer will ask about.
        $this->assertEqualsWithDelta(1.48,
            Contrast::ratio(Accent::hex('line-2'), Accent::hex('surface')), 0.01);
    }

    public function test_every_family_labels_on_its_own_wash(): void
    {
        // The tile is a family's ink on its own wash. The previous palette's live ink
        // failed here (4.44) and a live tile borrowed house ink; the handoff's `live-ink`
        // is 5.78, so the exception is retired and every family must hold.
        foreach (Accent::families() as $name => $f) {
            $got = Contrast::ratio(Accent::hex($f['ink']), Accent::hex($f['wash']));
            $this->assertGreaterThanOrEqual(Contrast::TEXT, round($got, 2),
                sprintf('%s ink on its own wash is %.2f:1', $name, $got));
        }
    }

    public function test_a_wash_holds_house_ink_well_above_the_text_floor(): void
    {
        // 12:1, not 4.5. A wash is a field body copy sits on.
        foreach (Accent::families() as $name => $f) {
            $got = Contrast::ratio(Accent::hex('ink'), Accent::hex($f['wash']));
            $this->assertGreaterThanOrEqual(12.0, round($got, 2),
                sprintf('house ink on the %s wash is %.2f:1', $name, $got));
        }
    }

    public function test_the_sweep_fails_on_a_planted_failure(): void
    {
        // Proving the measurement can fail before trusting it to pass: `mute` as a word
        // on the ground, and `soft` on tint, are both real failures of this palette.
        $this->assertLessThan(Contrast::TEXT, round(Contrast::ratio(Accent::hex('mute'), Accent::hex('ground')), 2));
        $this->assertLessThan(Contrast::TEXT, round(Contrast::ratio(Accent::hex('soft'), Accent::hex('tint')), 2));
    }
}
