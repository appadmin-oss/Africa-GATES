<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventTierTone;
use Tests\TestCase;

/**
 * How loudly the registration card is allowed to react to each ticket tier.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE PREVENTS, WHICH THE HANDOFF ASKED FOR BY NAME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The design handoff said: "Tiers are already sorted by price. Rank comes from
 * `loop.index0`, so there is no new column and no migration."
 *
 * They are not sorted by price. `EventTicketService::tiers()` orders by `sort_order` and
 * then `id` — a hand-set column an organiser drags rows around with. Putting the premium
 * tier at the TOP of the list, which is what most people do, would have made the cheapest
 * tier sweep white-hot, run two laps and shed seven sparks, while the ₦380,000 table got
 * the quiet green one.
 *
 * Nothing about that failure is visible from the template. `loop.last` is always something,
 * and it is always plausible. So rank is a price question answered here, where it can be
 * held to a number.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE SECOND OPEN DECISION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff flagged "sparks on the top tier only" as the most arguable thing in it, and
 * proposed the fix itself: drive it from price relative to the RANGE rather than position
 * in the list. At ₦5,000 / ₦5,500 there is no premium tier — there are two general tiers
 * and one is slightly dearer — and a white-hot double lap over ₦500 reads as the page
 * being broken rather than as an upgrade.
 */
final class EventTierToneTest extends TestCase
{
    /** @return array<string,mixed> */
    private function tier(int $id, int $price, string $state = 'open'): array
    {
        return ['id' => $id, 'price_naira' => $price, 'state' => $state];
    }

    // ══ rank is a price, not a position ══════════════════════════════════════

    public function test_the_dearest_tier_is_the_peak_wherever_it_sits_in_the_list(): void
    {
        // Patron FIRST, which is what an organiser who is proud of it does. Under the
        // handoff's `loop.last` rule this would have crowned General admission.
        $tones = EventTierTone::forTiers([
            $this->tier(1, 380000),
            $this->tier(2, 25000),
            $this->tier(3, 5000),
        ]);

        $this->assertSame('peak', $tones[1]);
        $this->assertSame('rise', $tones[2]);
        $this->assertSame('calm', $tones[3], 'the cheapest available tier is the quiet one');
    }

    public function test_the_same_ladder_reversed_gives_the_same_answer(): void
    {
        $a = EventTierTone::forTiers([$this->tier(1, 380000), $this->tier(2, 5000)]);
        $b = EventTierTone::forTiers([$this->tier(2, 5000), $this->tier(1, 380000)]);

        // ksort, because the return is a MAP keyed by tier id and assertSame compares key
        // order on arrays. The keys come out in list order, which is the input this test
        // is deliberately varying — comparing them unsorted would have this fail on the
        // one thing it is asserting does not matter.
        ksort($a); ksort($b);
        $this->assertSame($a, $b, 'the tone depends on the price, so list order cannot change it');
    }

    // ══ the peak needs a real premium under it ═══════════════════════════════

    public function test_two_tiers_a_few_hundred_naira_apart_have_no_peak(): void
    {
        $tones = EventTierTone::forTiers([$this->tier(1, 5000), $this->tier(2, 5500)]);

        $this->assertNotContains('peak', $tones,
            '₦500 of difference is not an upgrade, and celebrating it reads as a fault');
        $this->assertSame('calm', $tones[1]);
        $this->assertSame('rise', $tones[2], 'it is still the dearer of the two, quietly');
    }

    public function test_double_the_cheapest_price_earns_the_peak(): void
    {
        // The threshold itself, from both sides, so a change to PEAK_RATIO is a change
        // somebody has to make deliberately.
        $this->assertSame(2, EventTierTone::peakId(
            [$this->tier(1, 5000), $this->tier(2, 10000)]
        ), 'exactly 2x qualifies');

        $this->assertSame(0, EventTierTone::peakId(
            [$this->tier(1, 5000), $this->tier(2, 9999)]
        ), 'a naira under 2x does not');
    }

    public function test_a_free_bottom_tier_makes_any_paid_tier_the_step_up(): void
    {
        // Nothing to divide by, and ₦0 → ₦5,000 is the clearest ladder there is.
        $tones = EventTierTone::forTiers([$this->tier(1, 0), $this->tier(2, 5000)]);

        $this->assertSame('calm', $tones[1]);
        $this->assertSame('peak', $tones[2]);
    }

    public function test_a_single_tier_event_has_no_peak(): void
    {
        // Which is most events. One calm sweep is the right amount of ceremony for the
        // only thing on offer.
        $tones = EventTierTone::forTiers([$this->tier(1, 250000)]);

        $this->assertSame('calm', $tones[1]);
        $this->assertSame(0, EventTierTone::peakId([$this->tier(1, 250000)]));
    }

    public function test_an_all_free_event_has_no_peak(): void
    {
        $tones = EventTierTone::forTiers([$this->tier(1, 0), $this->tier(2, 0)]);

        $this->assertSame(['calm', 'calm'], array_values($tones));
    }

    public function test_two_tiers_at_the_same_top_price_crown_neither(): void
    {
        // Two different things at one price. Picking one of them to crown is arbitrary, and
        // the arbitrary choice would be stable enough to look deliberate.
        $tones = EventTierTone::forTiers([
            $this->tier(1, 5000), $this->tier(2, 250000), $this->tier(3, 250000),
        ]);

        $this->assertNotContains('peak', $tones);
    }

    // ══ availability beats rank ══════════════════════════════════════════════

    public function test_a_sold_out_tier_is_never_celebrated(): void
    {
        // The most expensive row is exactly where a triumphant white flash lands worst: the
        // press that reaches it is somebody joining a waiting list.
        $tones = EventTierTone::forTiers([
            $this->tier(1, 5000),
            $this->tier(2, 380000, 'sold_out'),
        ]);

        $this->assertSame('hold', $tones[2]);
        // Zero heat is the quietest the ladder goes, and it is what makes the wash on a
        // sold-out row land at the floor weight and no more. It was "never flashes white"
        // when the press was a firework; it is "never lands heavier than it has to" now,
        // and it is the same number saying the same thing.
        $this->assertSame(0.0, EventTierTone::HEAT['hold']);
        $this->assertSame(min(EventTierTone::HEAT), EventTierTone::HEAT['hold'],
            'a waiting list must never be acknowledged more loudly than a seat on sale');
    }

    public function test_hold_is_the_slowest_tone(): void
    {
        // Slow reads as sober. The escalation runs the other way — see the next test.
        $this->assertSame(max(EventTierTone::MS), EventTierTone::MS['hold']);
    }

    public function test_a_sold_out_bottom_tier_does_not_anchor_the_ladder(): void
    {
        // ₦5,000 has gone. Against what is actually on sale — ₦25,000 — the ₦380,000 table
        // is a 15x step and still the peak. Anchoring on a price nobody can pay would make
        // the ladder read off a row that is not for sale.
        $tones = EventTierTone::forTiers([
            $this->tier(1, 5000, 'sold_out'),
            $this->tier(2, 25000),
            $this->tier(3, 380000),
        ]);

        $this->assertSame('hold', $tones[1]);
        $this->assertSame('calm', $tones[2], 'the cheapest tier actually on sale');
        $this->assertSame('peak', $tones[3]);
    }

    public function test_a_closed_or_early_tier_is_also_hold(): void
    {
        foreach (['early', 'closed', 'sold_out'] as $state) {
            $tones = EventTierTone::forTiers([
                $this->tier(1, 5000), $this->tier(2, 380000, $state),
            ]);
            $this->assertSame('hold', $tones[2], $state . ' should be held, not celebrated');
        }
    }

    // ══ higher = faster and heavier, in that order ═══════════════════════════

    public function test_the_escalation_runs_the_right_way(): void
    {
        // A slow response on the premium tier reads as sluggish, not luxurious. This is the
        // one relationship the whole effect rests on, so it is asserted rather than
        // commented.
        //
        // It is asserted on the RAW numbers, and the template renders MS at .4×. That is
        // safe because the rescale is monotonic — but only because it is monotonic, which
        // is why the scale factor is a single constant beside the property it sets rather
        // than a per-tone table that could reorder these behind this test's back.
        $this->assertGreaterThan(EventTierTone::MS['rise'], EventTierTone::MS['calm']);
        $this->assertGreaterThan(EventTierTone::MS['peak'], EventTierTone::MS['rise']);

        $this->assertGreaterThan(EventTierTone::HEAT['calm'], EventTierTone::HEAT['rise']);
        $this->assertGreaterThan(EventTierTone::HEAT['rise'], EventTierTone::HEAT['peak']);
    }

    /**
     * The two tables that are gone, and why this is not a gap.
     *
     * `LAPS` and `SPARKS` counted laps of a light running the registration card's rim and
     * particles shed off its corner — the effect that fired on every tier press. It was
     * removed: it animated the whole card for an action that happened on one 48px row,
     * for up to 1.4s, on a press people repeat three times while comparing tiers.
     *
     * A press is a state layer and a ripple on the pressed row now, and neither can say a
     * lap count. The one firework left runs on a completed registration and is uniform on
     * purpose. So the two tables had nobody to speak to, and a constant with no reader is
     * this codebase's most expensive bug — see CODEBASE-INDEX §17.
     */
    public function test_the_ladder_is_two_tables_and_they_are_the_two_a_press_can_express(): void
    {
        $this->assertFalse(defined(EventTierTone::class . '::LAPS'),
            'LAPS is back with nothing to read it — a press cannot express a lap count');
        $this->assertFalse(defined(EventTierTone::class . '::SPARKS'),
            'SPARKS is back with nothing to read it');

        // A tone missing from one of the surviving tables renders as a row that responds
        // for 0ms at 0 opacity, which looks exactly like the effect not being wired up.
        foreach (EventTierTone::TONES as $tone) {
            foreach (['MS', 'HEAT'] as $table) {
                $this->assertArrayHasKey($tone, constant(EventTierTone::class . '::' . $table),
                    $tone . ' is missing from ' . $table);
            }
        }
    }

    // ══ the hue comes from the event, not from a new palette ═════════════════

    public function test_a_tier_with_no_colour_sweeps_in_the_platform_green(): void
    {
        $hue = EventTierTone::hue(['colour' => ''], ['ticket_accent' => '#2a6fdb']);

        // NULL is the default and most common state of the colour column, and it must not
        // produce `transparent` — an invisible sweep reads as the effect being broken.
        $this->assertSame(EventTierTone::DEFAULT_HUE, $hue);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $hue);
    }

    public function test_a_tier_with_a_slot_sweeps_in_the_events_own_accent(): void
    {
        // This is the fix for the handoff's first open decision. Gold on this platform
        // already means "early bird" and paints the sold-progress bar; a third meaning
        // eleven pixels away from the second is not a palette, it is a collision. The
        // sweep is the colour of the dot on the ticket the buyer is about to be sent.
        $blue = EventTierTone::hue(['colour' => 'deep'], ['ticket_accent' => '#2a6fdb']);
        $rust = EventTierTone::hue(['colour' => 'deep'], ['ticket_accent' => '#b4452f']);

        $this->assertNotSame($blue, $rust, 'the sweep must follow the event, or it is a fourth palette');
        $this->assertNotSame(EventTierTone::DEFAULT_HUE, $blue);
        foreach ([$blue, $rust] as $h) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $h);
        }
    }

    public function test_the_light_is_the_colour_the_organiser_actually_SET(): void
    {
        // This was `edge` at first, reasoning that a 1.5px line on a white card owes 3:1
        // (WCAG 1.4.11). True of the STATIC indicators, wrong for the light: `edge` is a
        // DARKENED derivative, so an organiser who chose "Warm" and watched a dark violet
        // run round the card had not been shown the colour they set.
        //
        // `fill` is the identity — the swatch in the admin picker, the dot on the printed
        // ticket, and the light. The light owes nothing: it is aria-hidden, decorative, and
        // says nothing the border and the radio do not also say.
        $palette = \AfricaGates\Services\EventTierPalette::fromAccent('#2a6fdb');

        $this->assertSame(
            $palette['soft']['fill'],
            EventTierTone::hue(['colour' => 'soft'], ['ticket_accent' => '#2a6fdb'])
        );
    }

    public function test_the_edge_is_kept_for_what_actually_owes_contrast(): void
    {
        // The selected row's border and the filled radio are non-text indicators of state
        // and owe 3:1 against white. A pale fill as a hairline is an absence, not a mark.
        $palette = \AfricaGates\Services\EventTierPalette::fromAccent('#2a6fdb');

        $this->assertSame(
            $palette['soft']['edge'],
            EventTierTone::edge(['colour' => 'soft'], ['ticket_accent' => '#2a6fdb'])
        );
    }

    public function test_the_pair_is_the_same_pair_the_printed_ticket_draws(): void
    {
        // `background: fill` inside a `1px solid edge` ring is exactly `.tk__dot` on the
        // ticket. One choice by the organiser, the same object in the admin picker, on the
        // registration row, and on the door.
        $both = EventTierTone::hues(['colour' => 'bold'], ['ticket_accent' => '#b4452f']);
        $sw   = \AfricaGates\Services\EventTierPalette::forTier(
            ['colour' => 'bold'], ['ticket_accent' => '#b4452f']
        );

        $this->assertSame(['hue' => $sw['fill'], 'edge' => $sw['edge']], $both);
    }

    public function test_a_tier_with_no_colour_gets_a_usable_pair_too(): void
    {
        // Still most tiers. Neither half may come back empty: an empty --tier-hue renders
        // as `transparent`, which is an invisible sweep and reads as the effect being
        // broken rather than as a tier without a colour.
        $both = EventTierTone::hues(['colour' => ''], null);

        $this->assertSame(EventTierTone::DEFAULT_HUE, $both['hue']);
        $this->assertSame(EventTierTone::DEFAULT_EDGE, $both['edge']);
        foreach ($both as $h) $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $h);
    }

    public function test_a_tier_with_no_id_is_skipped_rather_than_keyed_on_zero(): void
    {
        // Keying on 0 would give every unsaved row the same tone entry, and the last one
        // written would silently win.
        $tones = EventTierTone::forTiers([['price_naira' => 5000, 'state' => 'open']]);

        $this->assertSame([], $tones);
    }
}
