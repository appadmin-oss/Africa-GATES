<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\PromoCode;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Discount codes, and every limit that stops one becoming a story.
 *
 * ── WHY THIS IS NOT THE TIER'S ACCESS CODE ───────────────────────────────────
 *
 * A tier already has an `access_code` that HIDES it. That is a different thing from a code
 * that reduces a price, and conflating them would mean every discount needed its own hidden
 * tier — so an organiser offering 20% to alumni would maintain a parallel set of tiers that
 * drift out of step with the real ones on price, capacity and sale window.
 *
 * ── EVERY LIMIT HERE EXISTS BECAUSE ITS ABSENCE IS A KNOWN FAILURE ───────────
 *
 *   • `max_uses` — a code shared in a WhatsApp group is a code used four hundred times.
 *   • `max_per_email` — without it, one person books ten discounted tables.
 *   • `tier_ids` — a discount meant for students that also applied to the ₦380,000 table is
 *     an expensive kind of generous, and it is the mistake an organiser makes at speed.
 *   • `starts_at` / `ends_at` — an early-bird code that still works in December.
 *
 * ── AND THE PRICE IS NEVER TAKEN TO ZERO BY ACCIDENT ─────────────────────────
 *
 * A fixed discount larger than the ticket clamps to the ticket price rather than going
 * negative, and a percentage is applied to the line total rather than to each seat, so
 * rounding cannot make ten seats cost less than ten times one seat. Both are arithmetic
 * nobody notices until it is wrong in public.
 *
 * The code is checked at RESERVE time and the amount it took off is written on the row, not
 * recomputed later: a code can be edited or deleted after somebody has bought against it, and
 * a receipt that silently restated history would make the money stop adding up.
 */
final class EventDiscount
{
    /**
     * Look a code up for one event, one tier and one buyer, and price it.
     *
     * Everything is checked in one place because a code that passes four of five checks is
     * still a code that must not apply, and spreading them across the caller is how the fifth
     * gets forgotten.
     *
     * @return array{ok:bool, message:string, id?:int, code?:string, off?:int, total?:int, label?:string}
     */
    public static function apply(string $raw, int $eventId, int $tierId, int $lineTotal,
                                 string $email, int $quantity = 1): array
    {
        $code = PromoCode::normalise($raw);
        if ($code === '') return ['ok' => false, 'message' => ''];

        $row = self::find($code, $eventId);
        if (!$row) {
            return ['ok' => false, 'message' => 'That code is not recognised for this event.'];
        }

        // Switched on, inside its window, not exhausted. {@see PromoCode} owns these because
        // the shop asks exactly the same questions and two copies would not stay identical.
        $no = PromoCode::refusal($row);
        if ($no !== '') return ['ok' => false, 'message' => $no];

        // Which tiers. NULL means all; a list means exactly those.
        if (!PromoCode::targets($row->tier_ids ?? null, $tierId)) {
            return ['ok' => false, 'message' => 'That code does not apply to this ticket type.'];
        }

        // Per person, counted from the registrations themselves rather than from a counter, so
        // it cannot drift: the rows ARE the record of who used it.
        $perEmail = max(1, (int) ($row->max_per_email ?? 1));
        if (self::timesUsedBy($code, $eventId, $email) >= $perEmail) {
            return ['ok' => false, 'message' => PromoCode::perPersonRefusal($perEmail)];
        }

        $off = PromoCode::amountOff($row, $lineTotal);
        if ($off <= 0) {
            return ['ok' => false, 'message' => 'That code takes nothing off this ticket.'];
        }

        return ['ok' => true, 'id' => (int) $row->id, 'code' => $code,
                'off' => $off, 'total' => max(0, $lineTotal - $off),
                'label' => (string) ($row->label ?? ''),
                'message' => PromoCode::says($row, $off)];
    }

    /**
     * The code row for this event, preferring an event-specific one over a global.
     *
     * A platform-wide staff code should not have to be recreated for every event of the year —
     * and when an event defines its own code with the same letters, the event's wins, because
     * the more specific configuration is the one somebody set up deliberately.
     */
    public static function find(string $code, int $eventId): ?object
    {
        $code = strtoupper(trim($code));
        if ($code === '') return null;
        try {
            $own = DB::table('gates_event_codes')
                ->whereRaw('UPPER(code) = ?', [$code])->where('event_id', $eventId)->first();
            if ($own) return $own;
            return DB::table('gates_event_codes')
                ->whereRaw('UPPER(code) = ?', [$code])->whereNull('event_id')->first() ?: null;
        } catch (\Throwable) { return null; }
    }

    /**
     * How many times this email has already used the code on this event.
     *
     * Counted from the registrations, not from a per-person counter, because the registrations
     * are the record — and a cancelled one does not count, or somebody whose card was declined
     * would be locked out of their own discount.
     */
    public static function timesUsedBy(string $code, int $eventId, string $email): int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return 0;
        try {
            return (int) DB::table('gates_event_registrations')
                ->where('event_id', $eventId)
                ->whereRaw('UPPER(discount_code) = ?', [strtoupper(trim($code))])
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereIn('status', ['confirmed', 'pending'])
                ->count();
        } catch (\Throwable) { return 0; }
    }

    /**
     * Count a use, once the seat is actually taken.
     *
     * Called from {@see EventTicketService::reserve()} rather than from a checkout page,
     * because `used_count` is what `max_uses` is checked against and a code counted when
     * somebody merely LOOKED at it would exhaust itself on window shoppers.
     */
    public static function countUse(int $codeId): void
    {
        try {
            DB::table('gates_event_codes')->where('id', $codeId)
                ->update(['used_count' => DB::raw('COALESCE(used_count, 0) + 1')]);
        } catch (\Throwable) {}
    }

    /** Release a use when a hold is withdrawn, so an abandoned checkout does not consume one. */
    public static function releaseUse(?string $code, int $eventId): void
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') return;
        try {
            $row = self::find($code, $eventId);
            if (!$row) return;
            DB::table('gates_event_codes')->where('id', (int) $row->id)
                ->where('used_count', '>', 0)
                ->update(['used_count' => DB::raw('used_count - 1')]);
        } catch (\Throwable) {}
    }

    /** @return list<array<string,mixed>> every code for an event, plus the global ones. */
    public static function forEvent(int $eventId): array
    {
        try {
            return DB::table('gates_event_codes')
                ->where(static function ($q) use ($eventId): void {
                    $q->where('event_id', $eventId)->orWhereNull('event_id');
                })
                ->orderByDesc('id')->get()
                ->map(static function ($r) use ($eventId): array {
                    $a = (array) $r;
                    $a['global'] = $r->event_id === null;
                    $a['left']   = $r->max_uses !== null
                        ? max(0, (int) $r->max_uses - (int) $r->used_count) : null;
                    // Decoded HERE rather than in the template. Twig has no json_decode, and
                    // the editor needs the list twice — to tick the boxes and to name the
                    // tiers in the table — so decoding it once is also the only way the two
                    // cannot disagree.
                    $ids = json_decode((string) ($r->tier_ids ?? ''), true);
                    $a['tier_list'] = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
                    return $a;
                })->all();
        } catch (\Throwable) { return []; }
    }
}
