<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A queue for the seats that come back.
 *
 * ── WHY THIS BECAME NECESSARY THE MOMENT A TIER COULD SELL OUT ───────────────
 *
 * Until tiers had limits, nothing was ever full. Now something is, and the only thing the page
 * could say to the person who arrived at that moment was "fully booked" — which throws away
 * the most motivated person in the room. They wanted to come enough to arrive on a sold-out
 * page.
 *
 * It is also the only honest answer to the thing that always happens next: SEATS COME BACK. A
 * card is declined, a hold expires, three people cancel the week before. Without a list those
 * seats are re-sold to whoever happens to be looking, which is a lottery wearing the clothes of
 * a queue.
 *
 * ── AN OFFER IS NOT A TICKET, AND IT EXPIRES ─────────────────────────────────
 *
 * Promotion does not hand somebody a seat; it gives them a window in which they may take one.
 * That distinction is the whole design:
 *
 *   • A free tier could be confirmed outright — but silently registering somebody days after
 *     they asked, without telling them, produces an attendee who does not know they are coming
 *     and a seat nobody uses. So they are OFFERED it too.
 *   • An offer that never expired would let one person who has stopped reading their email hold
 *     a seat that four other people on the list would take today.
 *
 * ── AND IT IS OFF BY DEFAULT ─────────────────────────────────────────────────
 *
 * `waitlist_open` starts unset. A waitlist an organiser has not thought about is a promise they
 * did not make, and the worst version of this feature is a queue nobody ever works — which is
 * strictly worse than "fully booked", because it costs somebody hope as well as a seat.
 */
final class EventWaitlist
{
    /** How long somebody has to take a seat they have been offered. */
    public const OFFER_HOURS = 48;

    /** Is this event taking names? */
    public static function open(?object $event): bool
    {
        return $event !== null && (int) ($event->waitlist_open ?? 0) === 1;
    }

    /**
     * Join the queue.
     *
     * Stored in the same table as a real registration, with `status = 'waitlisted'`, so the
     * attendee list, the CSV, the ticket machinery and the check-in screen all already
     * understand it. A separate table would have meant a second implementation of everything
     * and two places for a person to exist.
     *
     * @param array{name:string,email:string,phone:string} $who
     * @return array{ok:bool, message:string, id?:int, place?:int}
     */
    public static function join(int $eventId, int $tierId, array $who): array
    {
        $event = DB::table('gates_site_events')->where('id', $eventId)
            ->where('status', 'published')->first();
        if (!$event) return ['ok' => false, 'message' => 'That event is not open.'];
        if (!self::open($event)) {
            return ['ok' => false, 'message' => 'There is no waiting list for this event.'];
        }

        $name  = trim($who['name'] ?? '');
        $email = trim($who['email'] ?? '');
        $phone = trim($who['phone'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Please enter your name and a valid email address.'];
        }
        if (strlen((string) preg_replace('/\D+/', '', $phone)) < 7) {
            return ['ok' => false, 'message' => 'Please enter a valid phone number — it is how an '
                                              . 'organiser reaches you if a seat comes free.'];
        }

        $tier = EventTicketService::tier($tierId);
        if (!$tier || (int) $tier->event_id !== $eventId) {
            return ['ok' => false, 'message' => 'That ticket type is not available.'];
        }

        // Already on it, or already coming. Both are answered honestly rather than by adding a
        // second row: a queue with the same person in it twice is a queue that is wrong about
        // its own length, which is the only number it exists to report.
        $existing = DB::table('gates_event_registrations')
            ->where('event_id', $eventId)->where('tier_id', $tierId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->whereIn('status', ['waitlisted', 'pending', 'confirmed'])
            ->first();
        if ($existing) {
            return (string) $existing->status === 'waitlisted'
                ? ['ok' => true, 'id' => (int) $existing->id,
                   'place' => self::placeOf((int) $existing->id),
                   'message' => 'You are already on the list for this ticket.']
                : ['ok' => false, 'message' => 'You already have a booking for this ticket type.'];
        }

        $now = Carbon::now()->toDateTimeString();
        try {
            $id = (int) DB::table('gates_event_registrations')->insertGetId(
                OptionalColumn::filter('gates_event_registrations', [
                    'event_id' => $eventId, 'tier_id' => $tierId,
                    'tier'     => mb_substr((string) $tier->name, 0, 80),
                    'name'     => mb_substr($name, 0, 160),
                    'email'    => mb_substr($email, 0, 190),
                    'phone'    => mb_substr($phone, 0, 40),
                    'quantity' => 1,
                    // Zero, not the tier price. Nothing is owed for standing in a queue, and a
                    // waitlist row carrying an amount would appear in the revenue figure as
                    // money the organiser has not been paid.
                    'amount_naira' => 0,
                    'status'   => 'waitlisted',
                    'waitlist_at' => $now,
                    'created_at'  => $now,
                ], ['tier_id', 'quantity', 'status', 'waitlist_at'])
            );
        } catch (\Throwable $e) {
            error_log('[waitlist] could not add to event ' . $eventId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'We could not add you to the list just now.'];
        }

        return ['ok' => true, 'id' => $id, 'place' => self::placeOf($id),
                'message' => 'You are on the list. If a seat comes free you will be emailed, and '
                           . 'you will have ' . self::OFFER_HOURS . ' hours to take it.'];
    }

    /** How many people are ahead of this entry, plus itself. */
    public static function placeOf(int $id): int
    {
        try {
            $row = DB::table('gates_event_registrations')->where('id', $id)->first();
            if (!$row) return 0;
            return 1 + (int) DB::table('gates_event_registrations')
                ->where('event_id', (int) $row->event_id)
                ->where('tier_id', (int) $row->tier_id)
                ->where('status', 'waitlisted')
                ->where('id', '<', $id)
                ->count();
        } catch (\Throwable) { return 0; }
    }

    /** How many are waiting on one tier. */
    public static function length(int $tierId): int
    {
        try {
            return (int) DB::table('gates_event_registrations')
                ->where('tier_id', $tierId)->where('status', 'waitlisted')->count();
        } catch (\Throwable) { return 0; }
    }

    /**
     * Offer the free seats to the people who have been waiting longest.
     *
     * ── WHY IT COUNTS BEFORE EVERY OFFER, NOT ONCE ───────────────────────────
     *
     * An outstanding offer HOLDS a seat — that is what makes it an offer rather than a
     * lottery ticket — so the seats available shrink as this loop runs. Counting once at the
     * top and then offering that many would promise the same seat to several people, which is
     * the exact failure a waitlist exists to prevent.
     *
     * {@see EventTicketService::sold()} already counts a live offer, because an offered row is
     * `pending` with an expiry.
     *
     * @return array{offered:int, notified:list<string>, message:string}
     */
    public static function promote(int $tierId, int $limit = 20, ?OtpService $mailer = null): array
    {
        $tier = EventTicketService::tier($tierId);
        if (!$tier) return ['offered' => 0, 'notified' => [], 'message' => 'No such ticket type.'];

        $event = DB::table('gates_site_events')->where('id', (int) $tier->event_id)->first();
        if (!$event) return ['offered' => 0, 'notified' => [], 'message' => 'No such event.'];

        $offered = 0;
        $notified = [];

        for ($i = 0; $i < max(1, $limit); $i++) {
            // Recounted every time round. See the docblock.
            $avail = EventTicketService::availability($tier);
            if ($avail['state'] !== 'open') break;
            if ($avail['left'] !== null && $avail['left'] < 1) break;

            $eventCap = $event->capacity !== null ? (int) $event->capacity : null;
            if ($eventCap !== null && EventTicketService::soldForEvent((int) $tier->event_id) >= $eventCap) break;

            $next = DB::table('gates_event_registrations')
                ->where('tier_id', $tierId)->where('status', 'waitlisted')
                ->orderBy('waitlist_at')->orderBy('id')->first();
            if (!$next) break;

            $now = Carbon::now();
            $price = (int) $tier->price_naira;
            $ref   = EventTicketService::REF_PREFIX . strtoupper(bin2hex(random_bytes(5)));

            $changed = DB::table('gates_event_registrations')->where('id', (int) $next->id)
                ->where('status', 'waitlisted')
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    // `pending`, never `confirmed` — even for a free tier. Silently registering
                    // somebody days after they asked, without telling them, produces an
                    // attendee who does not know they are coming and a seat nobody uses.
                    'status'       => 'pending',
                    'amount_naira' => $price,
                    'reference'    => $ref,
                    'offered_at'   => $now->toDateTimeString(),
                    'offer_expires_at' => $now->copy()->addHours(self::OFFER_HOURS)->toDateTimeString(),
                    // The hold and the offer expire together, so the seat returns to the queue
                    // by the same arithmetic that releases an abandoned checkout.
                    'hold_expires_at'  => $now->copy()->addHours(self::OFFER_HOURS)->toDateTimeString(),
                ], ['status', 'reference', 'offered_at', 'offer_expires_at', 'hold_expires_at']));

            if ($changed === 0) continue;   // somebody else promoted them first

            $offered++;
            $notified[] = (string) $next->email;
            self::tell($event, $tier, (object) array_merge((array) $next, ['reference' => $ref]), $price, $mailer);
        }

        return ['offered' => $offered, 'notified' => $notified,
                'message' => $offered === 0
                    ? 'No seats are free on that ticket type, or nobody is waiting.'
                    : $offered . ' offer(s) sent, each valid for ' . self::OFFER_HOURS . ' hours.'];
    }

    /**
     * Offers nobody took, put back.
     *
     * The seats are already free — {@see EventTicketService::sold()} stops counting an expired
     * hold — so this is about the QUEUE, not the arithmetic: a row stuck at "offered" is a
     * person the organiser thinks they are waiting on, and the next person never gets a turn.
     *
     * Returned to `waitlisted` rather than cancelled, and to the FRONT of the queue they were
     * already at, because somebody who missed an email in a bad week has not forfeited their
     * place — they have missed one offer.
     */
    public static function expireOffers(int $limit = 200): int
    {
        $now = Carbon::now()->toDateTimeString();
        try {
            $rows = DB::table('gates_event_registrations')
                ->where('status', 'pending')->whereNotNull('offered_at')
                ->whereNotNull('offer_expires_at')->where('offer_expires_at', '<', $now)
                ->limit($limit)->get();
        } catch (\Throwable) { return 0; }

        $n = 0;
        foreach ($rows as $r) {
            try {
                $done = DB::table('gates_event_registrations')->where('id', (int) $r->id)
                    ->where('status', 'pending')
                    ->update(OptionalColumn::filter('gates_event_registrations', [
                        'status' => 'waitlisted',
                        'offered_at' => null, 'offer_expires_at' => null,
                        'hold_expires_at' => null, 'reference' => null,
                        'amount_naira' => 0,
                        'notes' => 'an earlier offer expired unused',
                    ], ['status', 'offered_at', 'offer_expires_at', 'hold_expires_at', 'notes']));
                if ($done > 0) $n++;
            } catch (\Throwable) {}
        }
        return $n;
    }

    /** @return list<array<string,mixed>> the queue for an event, in order. */
    public static function forEvent(int $eventId): array
    {
        try {
            return DB::table('gates_event_registrations')
                ->where('event_id', $eventId)
                ->whereIn('status', ['waitlisted'])
                ->orderBy('waitlist_at')->orderBy('id')
                ->get()->map(static fn ($r): array => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    /**
     * Tell them a seat is free. Best-effort: the offer exists in the database either way.
     *
     * A waitlist offer that depended on an email arriving would silently expire for anybody
     * whose mail bounced — so the row is the record, and the organiser's screen shows an
     * outstanding offer whether or not the message got through.
     */
    private static function tell(object $event, object $tier, object $reg, int $price, ?OtpService $mailer): void
    {
        if ($mailer === null) return;

        $base = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/');
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $link = $base . '/events/' . rawurlencode((string) $event->slug);

        $what = $price > 0
            ? 'a seat at ₦' . number_format($price) . ' has come free'
            : 'a place has come free';

        $html = '<p>Hello <strong>' . $e((string) $reg->name) . '</strong>,</p>'
              . '<p>You asked to be told if ' . $what . ' for <strong>'
              . $e((string) $event->title) . '</strong> — one has, on the <strong>'
              . $e((string) $tier->name) . '</strong> ticket.</p>'
              . '<p><strong>This is held for you for ' . self::OFFER_HOURS . ' hours.</strong> '
              . 'After that it goes to the next person on the list.</p>'
              . '<p style="text-align:center;margin:22px 0"><a href="' . $link . '"'
              . ' style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;'
              . 'border-radius:999px;font-weight:600;text-decoration:none">Take the seat →</a></p>';

        $plain = 'Hello ' . (string) $reg->name . ",\n\n"
               . ucfirst($what) . ' for ' . (string) $event->title . ' on the '
               . (string) $tier->name . " ticket.\n\n"
               . 'It is held for you for ' . self::OFFER_HOURS . " hours.\n\n" . $link
               . "\n\n— Africa GATES";

        try {
            $mailer->sendBranded((string) $reg->email,
                'A seat has come free — ' . (string) $event->title,
                $html, $plain, 'Events');
        } catch (\Throwable) {}
    }
}
