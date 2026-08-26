<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * How loudly the registration card reacts when somebody picks a ticket tier.
 *
 * ═════════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SERVICE AND NOT `loop.index0` IN THE TEMPLATE
 * ═════════════════════════════════════════════════════════════════════════════════
 *
 * The handoff for this effect said "tiers are already sorted by price, so rank comes from
 * `loop.index0`". They are not. {@see EventTicketService::tiers()} orders by `sort_order`
 * and then `id` — a hand-set column an organiser drags rows around with. An organiser who
 * puts Patron at the top of the list, which is the obvious thing to do, would have made the
 * CHEAPEST tier sweep white-hot and shed sparks. Nothing about that failure is visible from
 * the template: `loop.last` is always something, and it is always plausible.
 *
 * So rank is computed from the price, here, where it can be tested.
 *
 * ═════════════════════════════════════════════════════════════════════════════════
 * WHY INTENSITY ESCALATES AND HUE DOES NOT
 * ═════════════════════════════════════════════════════════════════════════════════
 *
 * The design escalated green → gold → white-hot with the tier's rank. Gold on this platform
 * already means "early bird", and it is what the sold-progress bar is painted with. A third
 * meaning would have made a colour that currently says "there is a discount on" also say
 * "this is the expensive one", on the same card, eleven pixels apart.
 *
 * The escalation survives intact without touching hue, because hue was never carrying it:
 * the peak tone is FASTER, brighter, goes round twice and sheds sparks. That reads as
 * "more" whatever colour it is. So the hue comes from the event's own accent — through
 * {@see EventTierPalette}, the same ladder that colours the dot on the printed ticket — and
 * the light that runs the card is the colour of the ticket the buyer is about to receive.
 * A tier the organiser has given a slot sweeps in its own colour; one they have not sweeps
 * in the platform's green.
 *
 * ═════════════════════════════════════════════════════════════════════════════════
 * AND WHY THE PEAK IS A PRICE RATIO, NOT A POSITION
 * ═════════════════════════════════════════════════════════════════════════════════
 *
 * "The most expensive tier gets the full treatment" is wrong at ₦5,000 / ₦5,500. There is
 * no Patron tier there; there are two general tiers and one of them is slightly dearer, and
 * a white-hot double lap with seven sparks over ₦500 reads as the page being broken.
 *
 * The peak needs a real premium underneath it, so it requires the top price to be at least
 * {@see PEAK_RATIO}× the cheapest paid tier — or the cheapest tier to be free, where any
 * paid tier IS the step up. Two tiers minimum, because a ladder of one has no top.
 */
final class EventTierTone
{
    /**
     * Tones, quietest first. Named for what they DO, not for a colour, because the colour
     * comes from the event — see the class note.
     *
     * `hold` is not on the ladder: it is what a sold-out tier with a waiting list gets, and
     * it is deliberately the slowest and dimmest of the four. Joining a queue is not a
     * thing to celebrate, and a page that celebrates it is a page that has misread the
     * moment somebody is in.
     */
    public const TONES = ['calm', 'rise', 'peak', 'hold'];

    /** The top tier must cost at least this many times the cheapest paid one to be `peak`. */
    public const PEAK_RATIO = 2.0;

    /** Arc duration in ms. Higher tier = FASTER: a slow sweep reads as sluggish, not lavish. */
    public const MS = ['calm' => 950, 'rise' => 820, 'peak' => 700, 'hold' => 1050];

    /** Laps the light runs. Two on `peak` and nowhere else. */
    public const LAPS = ['calm' => 1, 'rise' => 1, 'peak' => 2, 'hold' => 1];

    /** Particles shed off the top-right corner. */
    public const SPARKS = ['calm' => 0, 'rise' => 0, 'peak' => 7, 'hold' => 0];

    /**
     * How near white the leading spark burns, 0–1.
     *
     * This is the whole escalation, together with MS and LAPS. `hold` is 0 on purpose:
     * a sold-out tier never flashes white.
     */
    public const HEAT = ['calm' => 0.34, 'rise' => 0.62, 'peak' => 1.0, 'hold' => 0.0];

    /**
     * The tone for every tier in a list, keyed by tier id.
     *
     * Takes the whole list because a tone is a statement about a tier's place among the
     * others — there is no such thing as one tier's rank on its own, which is the same
     * reason {@see EventTierPalette::forTier()} needs the event.
     *
     * @param list<array<string,mixed>> $tiers as returned by EventTicketService::tiers()
     * @return array<int,string> tier id → tone
     */
    public static function forTiers(array $tiers): array
    {
        $out = [];
        $peakId = self::peakId($tiers);

        foreach ($tiers as $t) {
            $id    = (int) ($t['id'] ?? 0);
            $state = (string) ($t['state'] ?? 'open');
            $price = (int) ($t['price_naira'] ?? 0);

            if ($id === 0) continue;

            // Availability beats rank. A sold-out Patron tier is `hold`, not `peak` — the
            // most expensive row on the card is exactly the one where a triumphant flash
            // over a waiting list would land worst.
            if ($state !== 'open') { $out[$id] = 'hold'; continue; }

            if ($id === $peakId)    { $out[$id] = 'peak'; continue; }

            // `rise` is any paid tier that is not the cheapest. A free tier is always
            // `calm`: there is no premium to signal.
            $out[$id] = ($price > 0 && $price > self::floorPrice($tiers)) ? 'rise' : 'calm';
        }

        return $out;
    }

    /**
     * The one tier that earns the full treatment, or 0 for none.
     *
     * @param list<array<string,mixed>> $tiers
     */
    public static function peakId(array $tiers): int
    {
        $open = array_values(array_filter(
            $tiers,
            static fn (array $t): bool => (string) ($t['state'] ?? 'open') === 'open'
        ));

        // A ladder of one has no top. Nothing is `peak` on a single-tier event, which is
        // most events — the effect there is one calm sweep, which is the right amount.
        if (count($open) < 2) return 0;

        $top = null;
        foreach ($open as $t) {
            if ($top === null || (int) $t['price_naira'] > (int) $top['price_naira']) $top = $t;
        }
        if ($top === null || (int) $top['price_naira'] <= 0) return 0;

        // Ties do not get a peak. Two tiers at ₦250,000 means the organiser is selling two
        // different things at one price, and picking one of them to crown is arbitrary.
        $atTop = 0;
        foreach ($open as $t) {
            if ((int) $t['price_naira'] === (int) $top['price_naira']) $atTop++;
        }
        if ($atTop > 1) return 0;

        $floor = self::floorPrice($tiers);

        // Free floor: any paid tier above it is a genuine step up, so the ratio does not
        // apply — there is nothing to divide by, and ₦0 → ₦5,000 is the clearest ladder
        // there is.
        if ($floor === 0) return (int) $top['id'];

        return ((int) $top['price_naira']) >= (int) round($floor * self::PEAK_RATIO)
            ? (int) $top['id']
            : 0;
    }

    /**
     * The cheapest AVAILABLE price, sold-out rows excluded.
     *
     * Excluded deliberately: a sold-out ₦5,000 tier would otherwise keep the ladder anchored
     * at a price nobody can pay, and make the ₦250,000 row look like a bigger step up than
     * it is against what is actually on sale.
     *
     * @param list<array<string,mixed>> $tiers
     */
    private static function floorPrice(array $tiers): int
    {
        $min = null;
        foreach ($tiers as $t) {
            if ((string) ($t['state'] ?? 'open') !== 'open') continue;
            $p = (int) ($t['price_naira'] ?? 0);
            if ($min === null || $p < $min) $min = $p;
        }
        return $min ?? 0;
    }

    /**
     * The two hexes one tier's row needs: the colour the organiser chose, and an edge dark
     * enough to be a visible line on white.
     *
     * ── WHY TWO AND NOT ONE ──────────────────────────────────────────────────
     *
     * This started as one value, the palette's `edge`, on the reasoning that a line of
     * light 1.5px wide on a white card needs to clear 3:1 (WCAG 1.4.11). That is true of
     * the STATIC indicators — the selected row's border and the filled radio — and it is
     * the wrong answer for the light itself, because `edge` is a darkened derivative. An
     * organiser who picks "Warm" and watches a dark violet run round the card has not been
     * shown the colour they set.
     *
     * So: `fill` is the identity — the swatch on the picker, the dot on the printed ticket,
     * and now the light — and `edge` is used only where a contrast obligation actually
     * applies. That is exactly the pair the ticket's own dot already draws
     * (`background: fill`, `1px solid edge`), which is what makes the row on the
     * registration card and the mark on the door literally the same object.
     *
     * The light itself carries no contrast obligation: it is `aria-hidden`, decorative, and
     * conveys nothing that the border, the background and the radio do not also say.
     *
     * @param array<string,mixed>|object|null $tier a tier row carrying `colour`
     * @param array<string,mixed>|object|null $event a `gates_site_events` row
     * @return array{hue:string, edge:string}
     */
    public static function hues(array|object|null $tier, array|object|null $event): array
    {
        $resolved = EventTierPalette::forTier($tier, $event);

        return $resolved !== null
            ? ['hue' => $resolved['fill'], 'edge' => $resolved['edge']]
            : ['hue' => self::DEFAULT_HUE, 'edge' => self::DEFAULT_EDGE];
    }

    /**
     * The colour the organiser set, for the light.
     *
     * Kept as its own call because the light is the thing most callers want, and a
     * `['hue']` at every use site reads worse than a name.
     */
    public static function hue(array|object|null $tier, array|object|null $event): string
    {
        return self::hues($tier, $event)['hue'];
    }

    /** The line-on-white variant, for the border and the radio. */
    public static function edge(array|object|null $tier, array|object|null $event): string
    {
        return self::hues($tier, $event)['edge'];
    }

    /**
     * The platform's own green, for a tier whose organiser set no colour — which is still
     * most tiers, and is a real answer rather than a missing one.
     */
    public const DEFAULT_HUE = '#237b22';

    /**
     * And its edge. Darker than DEFAULT_HUE by the same reasoning the palette applies to
     * every other slot: `#237b22` on white is 4.4:1, which clears 3:1 comfortably, so this
     * is the same value — stated separately so a future change to one is a deliberate
     * change to one.
     */
    public const DEFAULT_EDGE = '#1a6118';
}
