<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Selling a seat at an event: tiers with their own limits, and money that arrives.
 *
 * ── WHAT THIS REPLACES ───────────────────────────────────────────────────────
 *
 * Registration was a free RSVP. It took a name, an email and a phone number, checked
 * one capacity number for the whole event, and inserted a row. `gates_event_registrations`
 * has carried `amount_naira`, `reference` and `tier` since the day it was created and
 * nothing had ever written any of them — three columns describing a feature that did
 * not exist. Ticket tiers were a JSON blob the detail page printed as prose.
 *
 * So there was nowhere for a per-tier limit to live and nothing to count against it,
 * which is why "we cannot set an attendee limit per pricing tier" was a fact about the
 * schema rather than a missing form field. {@see \AfricaGates\Services\EventTicketService}
 * counts seats against tiers, and the tiers are rows.
 *
 * ── A SEAT IS TAKEN BY A HOLD, NOT BY A PAYMENT ──────────────────────────────
 *
 * The moment somebody starts a paid checkout their seat must stop being available, or a
 * sold-out tier oversells itself to everybody who happened to be on the page. But a
 * seat held by an abandoned checkout must come BACK, or one person closing a tab
 * permanently shrinks the room.
 *
 * So a pending registration holds its seats for {@see HOLD_MINUTES} and then stops
 * counting. Nothing needs to run for that to happen — {@see sold()} simply does not
 * count a hold that has expired, which means the seat is available again even on a
 * deployment whose cron has never fired. {@see releaseExpired()} exists to tidy the
 * rows for the attendee list, not to make the arithmetic true.
 *
 * ── BOTH LIMITS ARE REAL ─────────────────────────────────────────────────────
 *
 * A tier's capacity and the event's own are checked together, and an organiser who
 * sells 40 early-bird and 40 standard into a hall of 60 needs them both: the tiers say
 * how the room may be divided, the event says how big the room is. Neither is derived
 * from the other, because a real event's tiers routinely overlap on purpose.
 *
 * ── AND THE MONEY GOES THROUGH THE SAME DOOR AS EVERYTHING ELSE ──────────────
 *
 * `PaymentService::initialize()` and `verify()`, a reference this platform generates,
 * amount parity checked at confirm time, and the row left `pending` until the gateway
 * says otherwise. Identical to paid votes, the shop and donations — which also means
 * the reconciler, the triage screen and the gateway ledger can all see event money for
 * the first time, because they key off the same reference column that was always there
 * and never filled in.
 */
final class EventTicketService
{
    /** How long a started checkout keeps its seats. */
    public const HOLD_MINUTES = 30;

    /** Nobody buys more than this in one go without talking to somebody. */
    public const HARD_MAX_QTY = 50;

    /** The prefix every event reference carries, so a support desk can tell them apart. */
    public const REF_PREFIX = 'AFG-EVT-';

    // ══ 1. reading tiers ═════════════════════════════════════════════════════

    /**
     * The tiers a visitor may see, each with its availability worked out.
     *
     * A tier with an `access_code` is hidden until the visitor supplies it — that is how
     * a sponsor or speaker allocation stays out of the public list without needing a
     * second, secret event. Comparison is case-insensitive and trimmed, because the code
     * arrives from an email somebody has copied by hand.
     *
     * @return list<array<string,mixed>>
     */
    public static function tiers(int $eventId, ?string $code = null): array
    {
        $code = strtolower(trim((string) $code));

        self::ensureDefaultTier($eventId);

        try {
            $rows = DB::table('gates_event_tiers')
                ->where('event_id', $eventId)->where('is_active', 1)
                ->orderBy('sort_order')->orderBy('id')->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $t) {
            $gate = strtolower(trim((string) ($t->access_code ?? '')));
            if ($gate !== '' && $gate !== $code) continue;

            $a = self::availability($t);
            $out[] = [
                'id'          => (int) $t->id,
                'slug'        => (string) $t->slug,
                'name'        => (string) $t->name,
                'description' => (string) ($t->description ?? ''),
                'price_naira' => (int) $t->price_naira,
                'free'        => (int) $t->price_naira === 0,
                'capacity'    => $t->capacity !== null ? (int) $t->capacity : null,
                'perks'       => self::perks($t),
                'min'         => max(1, (int) ($t->min_per_order ?? 1)),
                'max'         => self::maxPerOrder($t),
                'unlocked'    => $gate !== '',
            ] + $a;
        }
        return $out;
    }

    /**
     * An event with no ticket types gets one, silently.
     *
     * ── WHY A READ IS ALLOWED TO WRITE HERE ──────────────────────────────────
     *
     * Registration goes through a tier now, so an event with none is an event nobody can
     * register for — and that is never what anybody meant. It is simply what you get when
     * somebody creates an event, fills in the date and the venue, and does not think about
     * ticketing because the event is free and always was.
     *
     * Before this, the same event took RSVPs happily. Requiring an organiser to go back and
     * add a "General admission" row before their page worked again would be this feature
     * breaking the ordinary case in order to serve the complicated one.
     *
     * So the default is created on first read: free, unlimited, one row, idempotent. It
     * takes its price from the event's own legacy `price_naira` when that is set, because an
     * event that has always advertised a price should not become free by upgrade.
     *
     * Deliberately NOT done in a migration alone. The migration covers every event that
     * exists today; this covers every event created tomorrow by somebody who never opens the
     * tier editor.
     */
    private static function ensureDefaultTier(int $eventId): void
    {
        try {
            if (DB::table('gates_event_tiers')->where('event_id', $eventId)->exists()) return;

            $event = DB::table('gates_site_events')->where('id', $eventId)->first();
            if (!$event) return;

            $price = (int) ($event->price_naira ?? 0);
            $now   = Carbon::now()->toDateTimeString();

            DB::table('gates_event_tiers')->insert([
                'event_id'    => $eventId,
                'slug'        => $price > 0 ? 'standard' : 'general',
                'name'        => $price > 0 ? 'Standard' : 'General admission',
                'price_naira' => $price,
                // Unlimited: the event's own `capacity` is still checked on top, so the room
                // is protected without inventing a per-tier limit nobody asked for.
                'capacity'    => null,
                'min_per_order' => 1,
                'max_per_order' => 10,
                'is_active'   => 1,
                'sort_order'  => 0,
                'created_at'  => $now, 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // A deployment that has uploaded this code and not yet run /__setup/migrate has
            // no tier table. Registration falls back to the legacy JSON blob on the page and
            // this stays quiet rather than turning a missing migration into a 500.
            error_log('[event] could not create a default tier for ' . $eventId . ': ' . $e->getMessage());
        }
    }

    /** @return list<string> */
    private static function perks(object $t): array
    {
        $raw = json_decode((string) ($t->perks ?? '[]'), true);
        if (!is_array($raw)) return [];
        return array_values(array_filter(array_map(
            static fn ($p): string => trim((string) $p),
            $raw
        ), static fn (string $p): bool => $p !== ''));
    }

    private static function maxPerOrder(object $t): int
    {
        $max = (int) ($t->max_per_order ?? 10);
        return max(1, min(self::HARD_MAX_QTY, $max ?: 10));
    }

    public static function tier(int $tierId): ?object
    {
        try { return DB::table('gates_event_tiers')->where('id', $tierId)->first() ?: null; }
        catch (\Throwable) { return null; }
    }

    /**
     * How many seats of this tier are gone, and whether it can still be bought.
     *
     * `state` is the single word a template branches on, and each one is a different
     * sentence to the visitor rather than a shade of "no": `open`, `sold_out`, `early`
     * (sales have not started), `closed` (they have ended).
     *
     * @return array{sold:int, left:?int, state:string, why:string}
     */
    public static function availability(object $tier, ?string $now = null): array
    {
        $now  = $now ?? Carbon::now()->toDateTimeString();
        $sold = self::sold((int) $tier->id);
        $cap  = $tier->capacity !== null ? (int) $tier->capacity : null;
        $left = $cap !== null ? max(0, $cap - $sold) : null;

        $starts = trim((string) ($tier->sale_starts_at ?? ''));
        $ends   = trim((string) ($tier->sale_ends_at ?? ''));

        if ($starts !== '' && $starts > $now) {
            return ['sold' => $sold, 'left' => $left, 'state' => 'early',
                    'why' => 'Sales for this open on ' . Carbon::parse($starts)->format('j F, H:i') . '.'];
        }
        if ($ends !== '' && $ends < $now) {
            return ['sold' => $sold, 'left' => $left, 'state' => 'closed',
                    'why' => 'Sales for this closed on ' . Carbon::parse($ends)->format('j F') . '.'];
        }
        if ($left !== null && $left <= 0) {
            return ['sold' => $sold, 'left' => 0, 'state' => 'sold_out',
                    'why' => 'Every seat at this price has gone.'];
        }
        return ['sold' => $sold, 'left' => $left, 'state' => 'open', 'why' => ''];
    }

    /**
     * Seats gone from one tier: confirmed, plus holds that have not expired.
     *
     * The expiry is applied HERE, in the arithmetic, rather than by a sweeper that flips
     * statuses. That is deliberate: on a deployment whose cron has never run — and this
     * platform ships a webcron precisely because that is common — a seat would otherwise
     * stay held by an abandoned checkout forever, and the tier would sell out to nobody.
     */
    public static function sold(int $tierId): int
    {
        $now = Carbon::now()->toDateTimeString();
        try {
            return (int) DB::table('gates_event_registrations')
                ->where('tier_id', $tierId)
                ->where(static function ($q) use ($now): void {
                    $q->where('status', 'confirmed')
                      ->orWhere(static function ($h) use ($now): void {
                          $h->where('status', 'pending')
                            ->where(static function ($e) use ($now): void {
                                // A hold with no expiry is a row from before this feature
                                // existed; treat it as live rather than free, because
                                // handing out a seat somebody may already hold is the
                                // worse of the two mistakes.
                                $e->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>=', $now);
                            });
                      });
                })
                ->sum(DB::raw('COALESCE(quantity, 1)'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Seats gone from the whole event, across every tier. */
    public static function soldForEvent(int $eventId): int
    {
        $now = Carbon::now()->toDateTimeString();
        try {
            return (int) DB::table('gates_event_registrations')
                ->where('event_id', $eventId)
                ->where(static function ($q) use ($now): void {
                    $q->where('status', 'confirmed')
                      ->orWhere(static function ($h) use ($now): void {
                          $h->where('status', 'pending')
                            ->where(static function ($e) use ($now): void {
                                $e->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>=', $now);
                            });
                      });
                })
                ->sum(DB::raw('COALESCE(quantity, 1)'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Seats belonging to people who have actually PAID (or been given a free place).
     *
     * ── WHY THIS IS NOT soldForEvent() ───────────────────────────────────────
     *
     * {@see soldForEvent()} answers "how many seats are unavailable", and a live hold makes a
     * seat unavailable whether or not the money has arrived. That is the right answer for
     * capacity and the WRONG one for a sentence shown to the public, because a hold is
     * somebody who is mid-checkout and may never come back.
     *
     * Two different questions that were being answered by one number — and, before this,
     * by a number that was neither: a raw `count()` of every row on the event, which counted
     * cancelled registrations, waitlist entries and abandoned checkouts as attendees, and
     * counted a table of ten as one.
     */
    public static function attendingForEvent(int $eventId): int
    {
        try {
            return (int) DB::table('gates_event_registrations')
                ->where('event_id', $eventId)
                ->where('status', 'confirmed')
                ->sum(DB::raw('COALESCE(quantity, 1)'));
        } catch (\Throwable) {
            return 0;
        }
    }

    // ══ 2. taking a seat ═════════════════════════════════════════════════════

    /**
     * Hold seats and, when they cost money, produce a reference to pay against.
     *
     * ── HOW OVERSELLING IS PREVENTED WITHOUT ROW LOCKS ───────────────────────
     *
     * Two people press the last seat at the same moment. Counting first and inserting
     * second lets both through, and this codebase does not use `SELECT … FOR UPDATE`
     * anywhere — MySQL and SQLite behave differently enough under it that a shared
     * helper would be a lie on one of them.
     *
     * So: insert the hold FIRST, then count. If the count now exceeds the cap, the row
     * that pushed it over cancels ITSELF and reports the tier as full. Both writers do
     * the same thing, and whichever one's count comes back over the line loses — which
     * is a race that resolves rather than a race that oversells. The loser has a
     * cancelled row and a clear message; nobody has a seat that does not exist.
     *
     * @param array{name:string,email:string,phone:string} $who
     * @param string|null $discount a discount code typed by the buyer, or null
     * @return array{ok:bool, message:string, id?:int, reference?:string, amount?:int,
     *                free?:bool, ticket_code?:string, state?:string}
     */
    public static function reserve(int $eventId, int $tierId, array $who, int $qty = 1,
                                   ?string $code = null, ?int $userId = null,
                                   ?string $discount = null): array
    {
        $event = DB::table('gates_site_events')->where('id', $eventId)
            ->where('status', 'published')->first();
        if (!$event) return ['ok' => false, 'message' => 'That event is not open for registration.'];

        if ((string) $event->event_date < Carbon::now()->toDateTimeString()) {
            return ['ok' => false, 'message' => 'Registration has closed for this event.'];
        }

        // A cutoff BEFORE the event date, which almost every organiser needs and none of them
        // could express: catering is ordered on the Tuesday for a Saturday, and a badge printed
        // on the Friday morning cannot include somebody who booked on the Friday night.
        $closes = trim((string) ($event->sales_close_at ?? ''));
        if ($closes !== '' && $closes < Carbon::now()->toDateTimeString()) {
            return ['ok' => false, 'state' => 'closed',
                    'message' => 'Registration for this event closed on '
                               . Carbon::parse($closes)->format('j F, H:i') . '.'];
        }

        $tier = self::tier($tierId);
        if (!$tier || (int) $tier->event_id !== $eventId || (int) ($tier->is_active ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'That ticket type is not available.'];
        }

        // A code-gated tier cannot be bought by guessing its id. The visible list hides
        // it; this refuses it, because the id is in the page source for anybody who has
        // the code and hiding alone is not a control.
        $gate = strtolower(trim((string) ($tier->access_code ?? '')));
        if ($gate !== '' && $gate !== strtolower(trim((string) $code))) {
            return ['ok' => false, 'message' => 'That ticket type needs an access code.'];
        }

        $name  = trim($who['name'] ?? '');
        $email = trim($who['email'] ?? '');
        $phone = trim($who['phone'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Please enter your name and a valid email address.'];
        }
        // Required, and it always has been: an organiser needs to reach attendees about a
        // venue change on the morning, and email alone does not reach anybody in a hurry.
        if (strlen((string) preg_replace('/\D+/', '', $phone)) < 7) {
            return ['ok' => false, 'message' => 'Please enter a valid phone number.'];
        }

        $min = max(1, (int) ($tier->min_per_order ?? 1));
        $max = self::maxPerOrder($tier);
        if ($qty < $min) return ['ok' => false, 'message' => 'This ticket is sold in ' . $min . 's or more.'];
        if ($qty > $max) return ['ok' => false, 'message' => 'You can book at most ' . $max . ' of these at once.'];

        $avail = self::availability($tier);
        if ($avail['state'] !== 'open') {
            return ['ok' => false, 'state' => $avail['state'], 'message' => $avail['why']];
        }
        if ($avail['left'] !== null && $qty > $avail['left']) {
            return ['ok' => false, 'state' => 'sold_out',
                    'message' => $avail['left'] === 1
                        ? 'Only one seat is left at this price.'
                        : 'Only ' . $avail['left'] . ' seats are left at this price.'];
        }

        $price = (int) $tier->price_naira;
        $gross = $price * $qty;
        $now   = Carbon::now();

        // ── THE DISCOUNT IS PRICED HERE, NOT ON THE PAGE ─────────────────────
        //
        // The browser is told what a code takes off so the buyer can see it before they
        // commit, but that preview is a courtesy. This is the only place the number that
        // reaches the gateway is decided, because a discount computed client-side is a
        // discount anybody can type into the request — and `confirm()` refuses a payment
        // smaller than the amount on the row, so a forged discount would not merely
        // undercharge, it would make the ticket unissuable and the money unmatched.
        //
        // A code that does not apply is NOT an error. Somebody typing a code that has
        // expired still wants the ticket at full price far more often than they want their
        // booking refused, so the reason is carried back alongside a successful hold.
        $off = 0; $usedCode = null; $usedCodeId = null; $codeNote = '';
        if (trim((string) $discount) !== '') {
            $d = EventDiscount::apply((string) $discount, $eventId, $tierId, $gross, $email, $qty);
            if ($d['ok']) {
                $off        = (int) $d['off'];
                $usedCode   = (string) $d['code'];
                $usedCodeId = (int) $d['id'];
                $codeNote   = (string) $d['message'];
            } else {
                $codeNote = (string) $d['message'];
            }
        }

        $amount = max(0, $gross - $off);
        // Free because it costs nothing NOW, whether the tier was free or a code took the
        // whole price off. Either way there is no payment to wait for, and leaving the row
        // pending would hold a seat behind money nobody owes.
        $free = $amount === 0;

        // ── A LIVE HOLD IS THE SAME ATTEMPT, NOT A SECOND ONE ────────────────
        //
        // Somebody who presses the button twice, or comes back to the tab they left open,
        // must not end up holding two lots of seats out of a limited tier — that is how a
        // tier of 40 sells out at 20 buyers. Handing back the reference they already have
        // is both the correct arithmetic and the better experience: they resume the
        // checkout they abandoned instead of starting a new one.
        //
        // Only for the SAME tier and the same quantity. A different tier or a different
        // number of seats is a genuinely different purchase.
        if (!$free) {
            $live = DB::table('gates_event_registrations')
                ->where('event_id', $eventId)->where('tier_id', $tierId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->where('status', 'pending')
                ->where(static function ($q) use ($now): void {
                    $q->whereNull('hold_expires_at')
                      ->orWhere('hold_expires_at', '>=', $now->toDateTimeString());
                })
                ->orderByDesc('id')->first();

            // The same quantity AND the same code. A buyer who went away, found a discount
            // code and came back is making a different purchase to the one they abandoned,
            // and handing them back the old reference would charge them the old price.
            $sameCode = $live !== null
                && strtoupper(trim((string) ($live->discount_code ?? '')))
                   === strtoupper((string) ($usedCode ?? ''));

            if ($live && (int) ($live->quantity ?? 1) === $qty && $sameCode) {
                return ['ok' => true, 'id' => (int) $live->id, 'reference' => (string) $live->reference,
                        'amount' => (int) ($live->amount_naira ?? $amount), 'free' => false,
                        'ticket_code' => null, 'resumed' => true,
                        'discount_note' => $codeNote,
                        'message' => 'Picking up the booking you already started.'];
            }
        }

        $ref = self::freshReference();

        try {
            $id = (int) DB::table('gates_event_registrations')->insertGetId(
                OptionalColumn::filter('gates_event_registrations', [
                    'event_id'     => $eventId,
                    'tier_id'      => $tierId,
                    // The tier NAME is copied as well as referenced. A tier can be renamed
                    // after somebody has bought against it, and an attendee list that
                    // silently restates history is worse than one that repeats itself.
                    'tier'         => mb_substr((string) $tier->name, 0, 80),
                    'name'         => mb_substr($name, 0, 160),
                    'email'        => mb_substr($email, 0, 190),
                    'phone'        => mb_substr($phone, 0, 40),
                    'quantity'     => $qty,
                    'amount_naira' => $amount,
                    // What was used and what it took off, written on the ROW. A code can be
                    // edited or deleted after somebody has bought against it, and a receipt
                    // that silently restated history would make the money stop adding up.
                    'discount_code'  => $usedCode,
                    'discount_naira' => $off > 0 ? $off : null,
                    'reference'    => $ref,
                    'user_id'      => $userId,
                    // A free seat is confirmed on the spot: there is nothing to wait for,
                    // and leaving it pending would hold a seat behind a payment nobody owes.
                    'status'       => $free ? 'confirmed' : 'pending',
                    'ticket_code'  => $free ? self::freshCode() : null,
                    'confirmed_at' => $free ? $now->toDateTimeString() : null,
                    'hold_expires_at' => $free ? null : $now->copy()->addMinutes(self::HOLD_MINUTES)->toDateTimeString(),
                    'created_at'   => $now->toDateTimeString(),
                ], ['tier_id', 'quantity', 'status', 'ticket_code', 'confirmed_at',
                    'hold_expires_at', 'user_id', 'discount_code', 'discount_naira'])
            );
        } catch (\Throwable $e) {
            // UNIQUE(event_id, email) on the original table. Somebody registering twice is
            // not an error worth an apology, but with paid tiers it is no longer safe to
            // answer "you are already on the list" — they may be trying to buy a second,
            // different ticket. Say what happened and let them use the desk.
            error_log('[event] could not reserve for event ' . $eventId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'You already have a registration for this event. '
                                              . 'If you need another ticket, please contact us.'];
        }

        // Counted here rather than after the capacity check below, so that it is symmetrical
        // with the release in rollBack(): a use that had not yet been counted when the
        // rollback released one would take a use off somebody ELSE's live booking.
        if ($usedCodeId !== null) EventDiscount::countUse($usedCodeId);

        // ── the count, AFTER the insert. See the docblock. ───────────────────
        $overTier  = $avail['left'] !== null && self::sold($tierId) > (int) $tier->capacity;
        $eventCap  = $event->capacity !== null ? (int) $event->capacity : null;
        $overEvent = $eventCap !== null && self::soldForEvent($eventId) > $eventCap;

        if ($overTier || $overEvent) {
            // rollBack(), not cancel(). A FREE tier is inserted as `confirmed` — there is
            // nothing to wait for — and cancel() deliberately refuses to touch a confirmed
            // row, because that is somebody's paid ticket. So a free registration that lost
            // the race stayed confirmed and the capacity of 1 admitted two people.
            self::rollBack($id, 'lost a race for the last seat');
            return ['ok' => false, 'state' => 'sold_out',
                    'message' => $overEvent && !$overTier
                        ? 'The event filled up while you were booking. Nothing has been charged.'
                        : 'Those seats went while you were booking. Nothing has been charged.'];
        }

        return [
            'ok' => true, 'id' => $id, 'reference' => $ref, 'amount' => $amount, 'free' => $free,
            'gross' => $gross, 'discount' => $off, 'discount_code' => $usedCode,
            'discount_note' => $codeNote,
            'ticket_code' => $free ? (string) DB::table('gates_event_registrations')
                ->where('id', $id)->value('ticket_code') : null,
            'message' => $free
                ? ($off > 0 && $price > 0
                    ? 'Your code covered the whole ticket — you are registered.'
                    : 'You are registered.')
                : 'Seats held for ' . self::HOLD_MINUTES . ' minutes while you pay.',
        ];
    }

    // ══ 3. money ═════════════════════════════════════════════════════════════

    /**
     * The gateway said it was paid — check that ourselves, then issue the ticket.
     *
     * Amount parity is refused for the reason every other confirm path on this platform
     * refuses it: a gateway reporting success for less than the order does not mean this
     * order was paid, and a ticket is a thing somebody gets into a room with.
     *
     * Idempotent. The conditional update means a webhook and a browser callback arriving
     * together produce one confirmation and one ticket code, and the second caller is
     * told the truth rather than an error.
     *
     * @return array{ok:bool, message:string, ticket_code?:string, already?:bool, id?:int}
     */
    public static function confirm(string $reference, ?PaymentService $payments = null): array
    {
        $reference = trim($reference);
        $reg = self::byReference($reference);
        if (!$reg) return ['ok' => false, 'message' => 'That registration could not be found.'];

        if ((string) $reg->status === 'confirmed') {
            return ['ok' => true, 'already' => true, 'id' => (int) $reg->id,
                    'ticket_code' => (string) ($reg->ticket_code ?? ''),
                    'message' => 'This registration is already confirmed.'];
        }
        if ((string) $reg->status === 'cancelled') {
            return ['ok' => false, 'message' => 'That registration was cancelled.'];
        }

        $payments = $payments ?? new PaymentService();
        $stored   = strtolower(trim((string) ($reg->provider ?? '')));
        $order    = $stored !== '' ? array_merge([$stored], array_diff($payments->enabledProviderIds(), [$stored]))
                                   : $payments->enabledProviderIds();

        foreach ($order as $provider) {
            $v = $payments->verify($provider, $reference);
            if (!($v['ok'] ?? false) || (string) ($v['status'] ?? '') !== 'success') continue;

            $paid = (int) ($v['amount'] ?? 0);
            $owed = (int) ($reg->amount_naira ?? 0);
            if ($paid < $owed) {
                return ['ok' => false,
                        'message' => 'The gateway shows ₦' . number_format($paid) . ' against a ticket of ₦'
                                   . number_format($owed) . ', so nothing has been issued. Please contact us.'];
            }
            if (strtoupper((string) ($v['currency'] ?? 'NGN')) !== 'NGN') {
                return ['ok' => false, 'message' => 'That payment was not in naira, so it could not be '
                                                  . 'matched to this ticket. Please contact us.'];
            }

            $code = self::freshCode();
            $changed = DB::table('gates_event_registrations')
                ->where('id', (int) $reg->id)->where('status', 'pending')
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'status'       => 'confirmed',
                    'provider'     => $provider,
                    'provider_ref' => $reference,
                    'ticket_code'  => $code,
                    'confirmed_at' => Carbon::now()->toDateTimeString(),
                    // The hold has done its job. Leaving it set would make a confirmed
                    // seat look like one that expires.
                    'hold_expires_at' => null,
                ], ['status', 'provider', 'provider_ref', 'ticket_code', 'confirmed_at', 'hold_expires_at']));

            if ($changed === 0) {
                $fresh = self::byReference($reference);
                return ['ok' => true, 'already' => true, 'id' => (int) $reg->id,
                        'ticket_code' => (string) ($fresh->ticket_code ?? ''),
                        'message' => 'This registration was confirmed a moment ago.'];
            }

            return ['ok' => true, 'id' => (int) $reg->id, 'ticket_code' => $code,
                    'message' => 'Payment received — your ticket is confirmed.'];
        }

        return ['ok' => false, 'message' => 'The gateway does not show that payment as successful yet. '
                                          . 'If your bank has debited you it can take a few minutes.'];
    }

    /**
     * Give up a seat.
     *
     * `cancelled` rather than deleted, because a row is the only evidence that somebody
     * tried — and on a paid tier it is the only place a reference lives, which the
     * reconciler and the gateway ledger both need if money turns out to have moved after
     * all.
     */
    public static function cancel(int $id, string $why = ''): bool
    {
        try {
            $row = DB::table('gates_event_registrations')->where('id', $id)->first();

            $done = DB::table('gates_event_registrations')->where('id', $id)
                ->whereIn('status', ['pending', 'waitlisted'])
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'status'       => 'cancelled',
                    'cancelled_at' => Carbon::now()->toDateTimeString(),
                    'notes'        => $why !== '' ? mb_substr($why, 0, 500) : null,
                    'hold_expires_at' => null,
                ], ['status', 'cancelled_at', 'notes', 'hold_expires_at'])) > 0;

            // The use goes back with the seat. Otherwise a code limited to fifty is exhausted
            // by fifty abandoned checkouts, and the organiser's promotion quietly ends before
            // a single person has been to their event.
            if ($done && $row !== null) {
                self::releaseDiscount($row);
            }
            return $done;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Give a discount use back, once the row that consumed it is no longer holding a seat. */
    private static function releaseDiscount(object $row): void
    {
        $code = trim((string) ($row->discount_code ?? ''));
        if ($code === '') return;
        EventDiscount::releaseUse($code, (int) $row->event_id);
    }

    /**
     * An organiser withdraws somebody's seat, on purpose.
     *
     * ── WHY cancel() COULD NOT DO THIS ───────────────────────────────────────
     *
     * {@see cancel()} deliberately refuses a `confirmed` row, because everything that calls
     * it is a MACHINE — an expired hold, a gateway that would not start, a lost race — and a
     * machine must never withdraw somebody's paid ticket on its own.
     *
     * But a person has to be able to. Somebody emails to say they cannot come, a duplicate
     * booking needs merging, a table of ten becomes a table of eight. Without this the seat
     * stays gone: the waiting list has nothing to promote into, the room reads as full, and
     * the organiser's answer to "can you take my name off" is "no".
     *
     * The ticket code is cleared, so a screenshot of a withdrawn ticket does not open a door.
     * The row stays as `cancelled` with a reason, because on a paid tier it is the only place
     * the reference lives and the reconciler needs it if a refund has to be traced. Refunding
     * the money is a separate act on the refunds screen — deliberately not automatic, since
     * a withdrawal and a refund are different decisions and one organiser in three will want
     * to keep a deposit.
     */
    public static function release(int $id, string $why = '', ?int $byAdminId = null): array
    {
        $row = DB::table('gates_event_registrations')->where('id', $id)->first();
        if (!$row) return ['ok' => false, 'message' => 'That registration could not be found.'];

        if ((string) $row->status === 'cancelled') {
            return ['ok' => false, 'message' => 'That registration was already cancelled.'];
        }

        $paid = (int) ($row->amount_naira ?? 0);
        $note = trim($why) !== '' ? trim($why) : 'withdrawn by an organiser';

        try {
            $done = DB::table('gates_event_registrations')->where('id', $id)
                ->whereIn('status', ['confirmed', 'pending', 'waitlisted'])
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'status'       => 'cancelled',
                    'cancelled_at' => Carbon::now()->toDateTimeString(),
                    'notes'        => mb_substr($note, 0, 500),
                    // Cleared so a screenshot of the old ticket does not pass at the door.
                    'ticket_code'  => null,
                    'hold_expires_at'  => null,
                    'offered_at'       => null,
                    'offer_expires_at' => null,
                ], ['status', 'cancelled_at', 'notes', 'ticket_code', 'hold_expires_at',
                    'offered_at', 'offer_expires_at']));
        } catch (\Throwable $e) {
            error_log('[event] could not release registration ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That seat could not be released just now.'];
        }

        if ($done === 0) return ['ok' => false, 'message' => 'That registration could not be released.'];

        self::releaseDiscount($row);

        return ['ok' => true, 'seats' => (int) ($row->quantity ?? 1),
                'tier_id' => (int) ($row->tier_id ?? 0),
                'refund_due' => $paid > 0 && (string) $row->status === 'confirmed',
                'amount' => $paid,
                'message' => (string) $row->name . '’s seat'
                    . ((int) ($row->quantity ?? 1) > 1 ? 's have' : ' has') . ' been released.'
                    . ($paid > 0 && (string) $row->status === 'confirmed'
                        ? ' ₦' . number_format($paid) . ' was paid — a refund is a separate '
                        . 'decision and has NOT been issued.'
                        : '')];
    }

    /**
     * The money went back: a refund settled, or a bank pulled it in a chargeback.
     *
     * ── THE HALF THAT MATTERS IS THE CODE, NOT THE STATUS ────────────────────
     *
     * Reversal webhooks only ever reached `gates_donations`, so a charged-back ticket stayed
     * `confirmed` — and a confirmed ticket renders a scannable QR on a page reachable with the
     * reference alone. The bank had taken the money back and the ticket still opened a door.
     * Clearing `ticket_code` is therefore the point of this method; the status change is the
     * bookkeeping around it.
     *
     * The seat goes back to the tier, and the waiting list can have it. That is the right
     * default: somebody who has been refunded is not coming, and holding an empty seat out of
     * squeamishness costs the organiser a paying attendee.
     *
     * Idempotent by status — only a `confirmed` row can be reversed — so a duplicate
     * `refund.processed` delivery does the work once.
     *
     * @return bool whether this call was the one that reversed it
     */
    public static function reverse(string $reference, string $why): bool
    {
        $row = self::byReference(trim($reference));
        if (!$row || (string) $row->status !== 'confirmed') return false;

        try {
            $done = DB::table('gates_event_registrations')->where('id', (int) $row->id)
                ->where('status', 'confirmed')
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'status'       => 'cancelled',
                    'cancelled_at' => Carbon::now()->toDateTimeString(),
                    'notes'        => mb_substr('payment reversed — ' . $why, 0, 500),
                    // The whole reason this method exists.
                    'ticket_code'  => null,
                ], ['status', 'cancelled_at', 'notes', 'ticket_code'])) > 0;
        } catch (\Throwable $e) {
            error_log('[event] could not reverse registration ' . (int) $row->id . ': ' . $e->getMessage());
            return false;
        }

        if (!$done) return false;

        self::releaseDiscount($row);

        Notifier::adminAlert(null, 'Event ticket reversed — ' . $why,
            'Reference: ' . (string) $row->reference . "\n"
            . 'Attendee:  ' . (string) $row->name . ' <' . (string) $row->email . '>' . "\n"
            . 'Seats:     ' . (int) ($row->quantity ?? 1) . "\n"
            . 'Amount:    ₦' . number_format((int) ($row->amount_naira ?? 0)) . "\n"
            . 'Reason:    ' . $why . "\n\n"
            . "The ticket code has been cleared, so the old ticket will no longer pass at the "
            . "door, and the seat has gone back to the tier for the waiting list.");

        return true;
    }

    /**
     * Undo a row this same call created moments ago.
     *
     * Separate from {@see cancel()} and narrower in what it trusts: it matches on `id` alone
     * and will therefore cancel a `confirmed` row, which cancel() refuses to do because a
     * confirmed row is generally somebody's paid ticket.
     *
     * That refusal is right everywhere except here. A free tier is inserted as confirmed —
     * there is no payment to wait for — so the one place that must be able to withdraw a
     * confirmed row is the over-capacity check a few lines after the insert that created it.
     * Private, and reachable only from {@see reserve()}, so the exception cannot spread.
     */
    private static function rollBack(int $id, string $why): void
    {
        try {
            $row = DB::table('gates_event_registrations')->where('id', $id)->first();
            if ($row !== null) self::releaseDiscount($row);

            DB::table('gates_event_registrations')->where('id', $id)
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'status'       => 'cancelled',
                    'cancelled_at' => Carbon::now()->toDateTimeString(),
                    'notes'        => mb_substr($why, 0, 500),
                    'ticket_code'  => null,
                    'confirmed_at' => null,
                    'hold_expires_at' => null,
                ], ['status', 'cancelled_at', 'notes', 'ticket_code', 'confirmed_at', 'hold_expires_at']));
        } catch (\Throwable $e) {
            error_log('[event] could not roll back registration ' . $id . ': ' . $e->getMessage());
        }
    }

    /**
     * Tidy up holds nobody came back for.
     *
     * Housekeeping only — {@see sold()} already ignores an expired hold, so the seats are
     * available whether or not this ever runs. What it fixes is the ATTENDEE LIST, where a
     * fortnight of abandoned checkouts sitting at "pending" makes an organiser think half
     * their room has not paid.
     *
     * ── IT WILL NOT TOUCH A HOLD THAT MONEY MIGHT BE ATTACHED TO ─────────────
     *
     * This method had no caller for its whole life, and wiring it up without the guard below
     * would have been worse than leaving it dead. {@see confirm()} only promotes a `pending`
     * row; `cancel()` only demotes one. So cancelling an expired hold on a PAID tier — a
     * thirty-minute window, against bank transfers that routinely settle later — would take
     * the row out of reach of every confirmation path on the platform, permanently, for a
     * payment that had already happened.
     *
     * Priced rows therefore belong to {@see \AfricaGates\Services\PaymentReconciler}, which
     * asks the gateway BEFORE writing anything off. This sweep is for the free ones: an RSVP
     * that was never completed owes nobody anything, so its hold can lapse on the clock alone.
     */
    public static function releaseExpired(int $limit = 500): int
    {
        $now = Carbon::now()->toDateTimeString();
        try {
            $ids = DB::table('gates_event_registrations')
                ->where('status', 'pending')->whereNotNull('hold_expires_at')
                ->where('hold_expires_at', '<', $now)
                // Free tiers only. See the note above — this is the whole safety of it.
                ->where(static function ($q): void {
                    $q->whereNull('amount_naira')->orWhere('amount_naira', '<=', 0);
                })
                ->limit($limit)->pluck('id')->all();
        } catch (\Throwable) {
            return 0;
        }

        $n = 0;
        foreach ($ids as $id) {
            if (self::cancel((int) $id, 'the checkout was never completed')) $n++;
        }
        return $n;
    }

    // ══ 4. the ticket itself ═════════════════════════════════════════════════

    /**
     * A booking reference — and, because the ticket page needs no login, a bearer token.
     *
     * ── WHY IT GOT WIDER ─────────────────────────────────────────────────────
     *
     * It was five bytes. Forty bits is ample against somebody guessing ONE reference and
     * hopeless against somebody enumerating: `/events/ticket/{ref}` and its calendar file are
     * unauthenticated by design — an attendee has no account and putting a login between a
     * person and the door they are standing at is worse — and neither route is rate-limited.
     * The only thing standing between a scraper and the attendee list is the width of this
     * string, so it is eight bytes now.
     *
     * Existing references keep working: every lookup is an exact match on the stored value,
     * and nothing anywhere parses the length.
     */
    public static function freshReference(): string
    {
        return self::REF_PREFIX . strtoupper(bin2hex(random_bytes(8)));
    }

    /**
     * A code somebody reads out over a bad phone line.
     *
     * No `0`/`O`, no `1`/`I`, no `5`/`S`: the alphabet is the part of a ticket code that
     * decides whether a door queue moves. Grouped in fours for the same reason.
     */
    public static function freshCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRTUVWXY2346789';
        for ($try = 0; $try < 12; $try++) {
            $raw = '';
            for ($i = 0; $i < 8; $i++) $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            try {
                $taken = DB::table('gates_event_registrations')->where('ticket_code', $code)->exists();
            } catch (\Throwable) {
                $taken = false;
            }
            if (!$taken) return $code;
        }
        // 29^8 is large enough that twelve collisions means something else is wrong; a
        // longer code is a better answer than an exception on somebody's checkout.
        return strtoupper(bin2hex(random_bytes(6)));
    }

    public static function byReference(string $reference): ?object
    {
        $reference = trim($reference);
        if ($reference === '') return null;
        try {
            return DB::table('gates_event_registrations')->where('reference', $reference)->first() ?: null;
        } catch (\Throwable) { return null; }
    }

    public static function byTicketCode(string $code): ?object
    {
        $code = strtoupper(trim($code));
        if ($code === '') return null;
        try {
            return DB::table('gates_event_registrations')->where('ticket_code', $code)->first() ?: null;
        } catch (\Throwable) { return null; }
    }

    // ══ 5. for the organiser ═════════════════════════════════════════════════

    /**
     * What is sold, tier by tier, plus the room's own total.
     *
     * @return array{tiers:list<array<string,mixed>>, sold:int, capacity:?int, left:?int,
     *               revenue:int, pending:int}
     */
    public static function summary(int $eventId): array
    {
        $event = DB::table('gates_site_events')->where('id', $eventId)->first();
        $cap   = $event && $event->capacity !== null ? (int) $event->capacity : null;
        $sold  = self::soldForEvent($eventId);

        $tiers = [];
        try {
            foreach (DB::table('gates_event_tiers')->where('event_id', $eventId)
                        ->orderBy('sort_order')->orderBy('id')->get() as $t) {
                $a = self::availability($t);
                $tiers[] = [
                    'id' => (int) $t->id, 'name' => (string) $t->name,
                    'price_naira' => (int) $t->price_naira,
                    'capacity' => $t->capacity !== null ? (int) $t->capacity : null,
                    'active' => (int) ($t->is_active ?? 0) === 1,
                ] + $a;
            }
        } catch (\Throwable) {}

        $revenue = 0; $pending = 0;
        try {
            $revenue = (int) DB::table('gates_event_registrations')
                ->where('event_id', $eventId)->where('status', 'confirmed')
                ->sum(DB::raw('COALESCE(amount_naira, 0)'));
            $pending = (int) DB::table('gates_event_registrations')
                ->where('event_id', $eventId)->where('status', 'pending')->count();
        } catch (\Throwable) {}

        return ['tiers' => $tiers, 'sold' => $sold, 'capacity' => $cap,
                'left' => $cap !== null ? max(0, $cap - $sold) : null,
                'revenue' => $revenue, 'pending' => $pending];
    }

    /** @return list<array<string,mixed>> */
    public static function attendees(int $eventId, string $status = '', int $limit = 500): array
    {
        try {
            $q = DB::table('gates_event_registrations')->where('event_id', $eventId);
            if ($status !== '') $q->where('status', $status);
            return $q->orderByDesc('id')->limit($limit)->get()
                ->map(static fn ($r): array => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }
}
