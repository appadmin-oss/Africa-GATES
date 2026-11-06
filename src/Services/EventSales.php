<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Is this event on sale — the ONE answer, for the events index, the detail page's ticket
 * card, its fixed bar and the sale-alert sweep (Phase 7, §8.10).
 *
 * ── WHY ONE RESOLVER ─────────────────────────────────────────────────────────
 *
 * The index chip, the card's state and the bottom bar all say the same thing in different
 * words, and the costliest shape in this codebase is two lists claiming one fact
 * (`JudgeSchedule`'s 'scheduled'). The old page computed "past", "closed" and "full" in
 * three places from three variables; the index computed nothing and showed every upcoming
 * event as bookable.
 *
 * ── COMING SOON IS DERIVED FROM THE TIERS, NOT STORED ON THE EVENT ───────────
 *
 * Every ticket tier already has `sale_starts_at`, set in the tier editor, and
 * `EventTicketService::reserve()` already refuses a tier whose sales have not started
 * (`availability()` → 'early'). So "announced, tickets not on sale yet" is a fact the
 * schema already holds: every PUBLIC tier is early. An event-level "on sale from" column
 * beside it would be a second rule claiming the same thing — and the first organiser to
 * change one and not the other would publish a date the checkout disagrees with. The date
 * tickets go on sale is the earliest public tier's `sale_starts_at` ({@see opensAt()}).
 *
 * States, in the order they are decided:
 *   ended     the event's date has passed
 *   closed    registration closed early (`sales_close_at`), or every public tier's sales ended
 *   soon      every public tier is early — announced, not on sale ({@see opensAt()})
 *   soldout   no tier can be bought (the room or every tier is full) and there is no list
 *   waitlist  the same, with the organiser's waiting list open
 *   open      something can be bought
 */
final class EventSales
{
    public const STATES = ['open', 'waitlist', 'soldout', 'closed', 'ended', 'soon'];

    /**
     * At or under this many places left, a page says how many: the event card's "Only N
     * places left" and the index card's "N left". One number, so the two cannot disagree
     * about whether an event is nearly full.
     */
    public const LOW_PLACES = 25;

    /**
     * @param array<string,mixed>       $event a `gates_site_events` row
     * @param list<array<string,mixed>> $tiers EventTicketService::tiers() for it
     * @param bool $roomFull the event's own capacity is taken (seats + live holds)
     */
    public static function state(array $event, array $tiers, bool $roomFull = false, ?string $now = null): string
    {
        $now = $now ?? Carbon::now()->toDateTimeString();

        if ((string) ($event['event_date'] ?? '') !== '' && (string) $event['event_date'] < $now) return 'ended';

        $closes = trim((string) ($event['sales_close_at'] ?? ''));
        if ($closes !== '' && $closes < $now) return 'closed';

        // Only the tiers anybody can see decide the event's state. A code-gated sponsor
        // allocation that is open does not make the event "on sale" to the public.
        $public = array_values(array_filter($tiers, static fn (array $t): bool => !($t['unlocked'] ?? false)));
        $states = array_map(static fn (array $t): string => (string) ($t['state'] ?? 'open'), $public);

        if ($states !== [] && !in_array('open', $states, true)) {
            if (!array_diff($states, ['early'])) return 'soon';
            if (!array_diff($states, ['closed'])) return 'closed';
        }

        $buyable = in_array('open', $states, true) || $states === [];
        if ($roomFull || !$buyable) {
            return ((int) ($event['waitlist_open'] ?? 0) === 1) ? 'waitlist' : 'soldout';
        }
        return 'open';
    }

    /**
     * When tickets go on sale: the earliest future `sale_starts_at` among the event's public,
     * active tiers. Null when nothing is waiting to open.
     */
    public static function opensAt(int $eventId, ?string $now = null): ?string
    {
        $now = $now ?? Carbon::now()->toDateTimeString();
        try {
            $v = DB::table('gates_event_tiers')
                ->where('event_id', $eventId)->where('is_active', 1)
                ->where(static fn ($q) => $q->whereNull('access_code')->orWhere('access_code', ''))
                ->where('sale_starts_at', '>', $now)
                ->min('sale_starts_at');
        } catch (\Throwable) {
            return null;
        }
        return $v !== null && (string) $v !== '' ? (string) $v : null;
    }

    /**
     * "From ₦X": the cheapest price somebody could pay — among tiers on sale, or, before
     * sales open, among the ones that will be. Null when there is nothing to quote.
     *
     * @param list<array<string,mixed>> $tiers
     */
    public static function lowest(array $tiers, string $state): ?int
    {
        $want = $state === 'soon' ? ['early', 'open'] : ['open'];
        $min = null;
        foreach ($tiers as $t) {
            if ($t['unlocked'] ?? false) continue;
            if (!in_array((string) ($t['state'] ?? 'open'), $want, true)) continue;
            $p = (int) ($t['price_naira'] ?? 0);
            if ($min === null || $p < $min) $min = $p;
        }
        return $min;
    }

    /** The price as a reader sees it: "Free", or "₦15,000". */
    public static function money(?int $naira): string
    {
        if ($naira === null) return '';
        return $naira > 0 ? '₦' . number_format($naira) : \AfricaGates\Support\Translator::t('Free');
    }
}
