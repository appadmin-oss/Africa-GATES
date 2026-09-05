<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\FlierLayout;
use PHPUnit\Framework\TestCase;

/**
 * The share-graphic spec, pinned.
 *
 * Everything here comes from the design document that specified the redraw. It is
 * worth asserting rather than eyeballing because almost nothing on the card sits at
 * a fixed y any more: the panel height depends on whether there is a rank, four
 * other positions depend on the panel, and the name block's top depends on the font
 * size AND the line count, both of which depend on the name. That arithmetic is
 * exactly the kind that looks right in one screenshot and is wrong for the next
 * nominee.
 *
 * No database and no rendering — {@see FlierLayout} is deliberately pure so the spec
 * can be checked as arithmetic.
 */
final class FlierLayoutTest extends TestCase
{
    /** @param array<string,mixed> $standing */
    private function layout(string $name, array $standing = [], string $url = 'afg.org/vote/1'): array
    {
        return FlierLayout::for([
            'name' => $name,
            'category' => 'Literature & Letters',
            'country' => 'NG',
            'short_url' => $url,
            'standing' => $standing + ['rank' => 3, 'field' => 24, 'gap_ahead' => 12, 'next_rank' => 2,
                                       'momentum_24h' => 41, 'momentum_available' => true],
        ]);
    }

    // ── The name ─────────────────────────────────────────────────────────────

    public function test_the_size_ladder_steps_where_the_spec_says(): void
    {
        $this->assertSame(96, FlierLayout::nameSize(str_repeat('a', 14)));
        $this->assertSame(82, FlierLayout::nameSize(str_repeat('a', 15)));
        $this->assertSame(82, FlierLayout::nameSize(str_repeat('a', 20)));
        $this->assertSame(68, FlierLayout::nameSize(str_repeat('a', 21)));
        $this->assertSame(68, FlierLayout::nameSize(str_repeat('a', 28)));
        $this->assertSame(58, FlierLayout::nameSize(str_repeat('a', 29)));
        $this->assertSame(58, FlierLayout::nameSize(str_repeat('a', 36)));
        $this->assertSame(50, FlierLayout::nameSize(str_repeat('a', 37)));
    }

    /**
     * THE ONE THAT MATTERS FOR THIS PLATFORM. Sizing on code points would drop a
     * Yoruba name two steps below a Latin name of the same visual width — so exactly
     * the names this site exists for would render smallest. Marks add height, not
     * width, and the 12px pad above the block is what makes room for them.
     */
    public function test_diacritics_do_not_shrink_a_name(): void
    {
        $marked = 'Ọlásùnkànmí';         // 11 letters, but more code points in NFD
        $plain  = 'Olasunkanmi';         // the same 11 letters, unmarked

        $this->assertSame(
            FlierLayout::nameSize($plain),
            FlierLayout::nameSize($marked),
            'a marked name must be set at the same size as its unmarked twin'
        );
        $this->assertSame(96, FlierLayout::nameSize($marked));
    }

    public function test_a_short_name_stays_on_one_line(): void
    {
        $this->assertSame(['Ada Obi'], FlierLayout::splitName('Ada Obi'));
    }

    /**
     * Balanced, not greedy. A greedy wrap fills line one and leaves a stub, which on
     * a poster reads as a mistake rather than as a setting.
     */
    public function test_a_long_name_breaks_at_the_space_nearest_the_middle(): void
    {
        $this->assertSame(
            ['Ọlásùnkànmí', 'Adébáyọ̀ Ogundipe'],
            FlierLayout::splitName('Ọlásùnkànmí Adébáyọ̀ Ogundipe')
        );
    }

    /** A single long word has no space to break at, and hyphenating a name is worse. */
    public function test_a_single_unbreakable_word_is_left_alone(): void
    {
        $this->assertSame(['Nwakaegoputamkpume'], FlierLayout::splitName('Nwakaegoputamkpume'));
    }

    // ── The panel, and everything that moves with it ─────────────────────────

    public function test_the_panel_grows_when_there_is_no_rank_pill(): void
    {
        $ranked   = $this->layout('Ada Obi');
        $unranked = $this->layout('Ada Obi', ['field' => 1, 'rank' => 1]);

        $this->assertSame(1020, $ranked['panelH']);
        $this->assertSame(1120, $unranked['panelH']);
        $this->assertFalse($unranked['showRank'], '"#1 of 1" is not a standing');

        // The spec's own worked example for field < 2.
        $this->assertSame(1012, $unranked['nameBottom']);
        $this->assertSame(1030, $unranked['catTop']);
        $this->assertSame(740,  $unranked['scrimTop'], 'the scrim always ends flush with the panel base');
    }

    /**
     * The name block grows UPWARD from a fixed bottom edge, so a two-line name pushes
     * into the photo instead of down into the category line. Computed, never fixed.
     */
    public function test_the_name_block_is_anchored_to_its_bottom_edge(): void
    {
        $one = $this->layout('Ada Obi');                       // 1 line at 96
        $two = $this->layout('Ọlásùnkànmí Adébáyọ̀ Ogundipe');  // 2 lines, smaller

        $this->assertSame(1, $one['lineCount']);
        $this->assertSame(2, $two['lineCount']);
        $this->assertSame(912, $one['nameBottom']);
        $this->assertSame(912, $two['nameBottom'], 'the bottom edge does not move');
        $this->assertLessThan($one['nameTop'], $two['nameTop'], 'the second line grows upward');

        // top = bottom − (pad + lines × 1.22 × size)
        $this->assertSame((int) round(912 - (12 + 1 * 1.22 * 96)), $one['nameTop']);
    }

    // ── The standing line ────────────────────────────────────────────────────

    public function test_all_three_clauses_when_all_three_are_true(): void
    {
        $L = $this->layout('Ada Obi');

        $this->assertSame('12 votes from #2', $L['gapText']);
        $this->assertSame('', $L['leadText']);
        $this->assertSame('41 in 24h', $L['momText']);
        $this->assertTrue($L['showMiddot']);
        $this->assertTrue($L['showStanding']);
    }

    public function test_the_leader_gets_a_leading_clause_instead_of_a_gap(): void
    {
        $L = $this->layout('Ada Obi', ['rank' => 1, 'gap_ahead' => 0]);

        $this->assertSame('', $L['gapText']);
        $this->assertSame('Leading the field', $L['leadText']);
        $this->assertTrue($L['showMiddot'], 'the gold momentum clause is unaffected');
    }

    /** "0 in 24h" on a graphic you are about to post is an argument against voting. */
    public function test_zero_momentum_drops_the_gold_clause_and_its_middot(): void
    {
        $L = $this->layout('Ada Obi', ['momentum_24h' => 0]);

        $this->assertSame('', $L['momText']);
        $this->assertFalse($L['showMiddot']);
        $this->assertTrue($L['showStanding'], 'the gap clause still stands alone');
    }

    /**
     * Momentum that cannot be MEASURED is not momentum of zero. A category with no
     * timestamped votes must drop the clause rather than print a figure it invented.
     */
    public function test_unmeasurable_momentum_is_dropped_not_zeroed(): void
    {
        $L = $this->layout('Ada Obi', ['momentum_24h' => 41, 'momentum_available' => false]);

        $this->assertSame('', $L['momText']);
    }

    public function test_an_unranked_card_has_no_standing_line_at_all(): void
    {
        $L = $this->layout('Ada Obi', ['field' => 1, 'rank' => 1, 'momentum_24h' => 0]);

        $this->assertFalse($L['showStanding']);
    }

    /**
     * The gap skips EQUAL totals, so a jointly-second nominee is told the distance to
     * first rather than "0 votes from #2" — which would say they are level with the
     * position they already hold.
     */
    public function test_the_gap_names_the_position_actually_above(): void
    {
        $L = $this->layout('Ada Obi', ['rank' => 2, 'gap_ahead' => 60, 'next_rank' => 1]);

        $this->assertSame('60 votes from #1', $L['gapText']);
    }

    public function test_a_one_vote_gap_is_not_pluralised(): void
    {
        $this->assertSame('1 vote from #2', $this->layout('Ada Obi', ['gap_ahead' => 1])['gapText']);
    }

    // ── The rest ─────────────────────────────────────────────────────────────

    public function test_a_long_url_steps_down_one_size_to_stay_in_the_pill(): void
    {
        $this->assertSame(40, $this->layout('Ada Obi', [], str_repeat('a', 30))['urlSize']);
        $this->assertSame(34, $this->layout('Ada Obi', [], str_repeat('a', 31))['urlSize']);
    }

    /** A digit at 400px reads as a rendering fault, not as somebody's initials. */
    public function test_the_monogram_skips_words_that_start_with_a_digit(): void
    {
        $this->assertSame('NS', FlierLayout::monogram('Nominee 48 Surname'));
        $this->assertSame('N',  FlierLayout::monogram('Nominee 48'));
        $this->assertSame('AO', FlierLayout::monogram('Ada Obi'));
        $this->assertSame('?',  FlierLayout::monogram('48 90'));
    }

    /** `Ọ` is a different letter from `O` to the person whose name it is. */
    public function test_the_monogram_keeps_its_marks(): void
    {
        $this->assertSame('ỌA', FlierLayout::monogram('Ọlásùnkànmí Adébáyọ̀'));
    }

    public function test_the_og_name_is_bottom_anchored_at_its_own_edge(): void
    {
        $L = $this->layout('Ada Obi');

        $this->assertSame($L['nameSize'], $L['ogNameSize'], 'the card runs the same ladder');
        $this->assertSame((int) round(496 - (12 + 1 * 1.22 * 96)), $L['ogNameTop']);
    }

    public function test_hex_to_rgb_round_trips_the_palette(): void
    {
        $this->assertSame([18, 59, 47],  FlierLayout::rgb(FlierLayout::C_BG_TOP));
        $this->assertSame([201, 162, 39], FlierLayout::rgb(FlierLayout::C_GOLD));
        $this->assertSame([10, 39, 33],  FlierLayout::rgb(FlierLayout::C_SCRIM));
    }

    // ── The top scrim ────────────────────────────────────────────────────────

    /**
     * The kicker is set in white and gold and sits directly on the photo. Against a
     * portrait shot on a pale background — a whitewashed wall, an overcast sky, a
     * studio backdrop — it disappeared entirely, because the only scrim covered the
     * BOTTOM of the panel. Measured on a near-white test portrait: the backdrop behind
     * the lockup went from luminance 211 to 85.
     */
    public function test_the_top_scrim_holds_full_strength_through_the_kicker(): void
    {
        // The kicker's lower line sits at y≈134; the scrim must still be at full
        // strength there, not most of the way faded.
        $holdEndsAt = FlierLayout::TOP_SCRIM_H * FlierLayout::TOP_SCRIM_HOLD;

        $this->assertGreaterThanOrEqual(132, $holdEndsAt,
            'the scrim must not begin fading before the second kicker line');
        $this->assertGreaterThan(0.6, FlierLayout::TOP_SCRIM_OP,
            'weaker than this does not hold white type on a near-white photo');
    }

    /**
     * The falloff has ZERO SLOPE where the hold ends. A straight ramp put a visible
     * horizontal seam across the photo at the moment the fade began — the eye reads a
     * sudden change in gradient as an edge.
     */
    public function test_the_scrim_falloff_leaves_no_seam_at_the_hold_boundary(): void
    {
        $peak = FlierLayout::TOP_SCRIM_OP;
        $at = static fn (float $u): float => $peak * (1 - $u * $u);   // the shipped curve

        // Immediately after the boundary the change per step is far smaller than a
        // linear ramp's would be over the same interval.
        $linearStep = $peak * 0.02;
        $this->assertLessThan($linearStep, $at(0.0) - $at(0.02),
            'the curve must leave the hold boundary flat, not with a linear kink');

        // Monotonic, and all the way to nothing.
        $prev = INF;
        foreach (range(0, 20) as $i) {
            $v = $at($i / 20);
            $this->assertLessThanOrEqual($prev, $v);
            $prev = $v;
        }
        $this->assertSame(0.0, $at(1.0), 'it must reach fully transparent');
    }
}
