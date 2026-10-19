<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "You are registered" / "here is your ticket" — the one email an attendee actually needs.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT LEFT THE CONTROLLER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This was `EventsController::announce()`: private, and needing a `Request` to build its own
 * links. That made it reachable from exactly one place — the browser callback — which was fine
 * while the browser callback was the only thing that could confirm a ticket, and became the
 * problem the moment it stopped being.
 *
 * A buyer paying inside a bank or wallet app frequently never returns. Their ticket is now
 * confirmed by the gateway webhook or by the reconciliation sweep, and both of those need to
 * send this email — a confirmed ticket whose owner was never told is only marginally better
 * than one that was never issued.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT IS CLAIMED, AND IT IS QUEUED FROM THE WEBHOOK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Claimed on `notified_at` with a conditional UPDATE, so of {callback, webhook, sweep} —
 * which routinely race, and are supposed to — exactly one sends. A queued job that runs twice
 * therefore sends one email.
 *
 * And queued rather than sent when the caller is a webhook: Paystack allows roughly thirty
 * seconds for a whole delivery, SMTP here is allowed twelve, and the verify that preceded it
 * may already have spent fifteen. The browser paths call {@see send()} directly — nobody is
 * on a clock there, and an attendee refreshing their inbox is better served now than in
 * fifteen minutes.
 */
final class EventTicketMailer
{
    public const JOB = 'event.ticket';

    /** Send later — for callers inside a gateway webhook's budget. */
    public static function queue(int $registrationId): void
    {
        if ($registrationId < 1) return;
        try {
            (new QueueService())->push(self::JOB, ['registration_id' => $registrationId],
                                       0, 'event-ticket-' . $registrationId);
        } catch (\Throwable $e) {
            // An attendee told late beats one never told, and this must never break the
            // confirmation that triggered it.
            error_log('[event] could not queue the ticket email: ' . $e->getMessage());
            self::send($registrationId, null);
        }
    }

    /**
     * Tell the attendee, tell the team, tell the integrations.
     *
     * Best-effort throughout: a mail failure must never undo a confirmed ticket. The ticket
     * exists in the database and on its own page either way, which is the half that matters —
     * an email is a convenience, and treating it as the delivery mechanism is how somebody
     * ends up at a door with nothing to show.
     */
    public static function send(int $registrationId, ?OtpService $mailer): void
    {
        $reg = DB::table('gates_event_registrations')->where('id', $registrationId)->first();
        if (!$reg) return;

        // Only for a live registration. A cancelled or reversed one must not have a ticket
        // emailed to it — that is how a refunded attendee turns up at a door holding proof.
        if (!in_array((string) ($reg->status ?? ''), ['confirmed', 'waitlisted'], true)) return;

        // The claim. See the class note — this is what makes three racing callers send once.
        if (OptionalColumn::on('gates_event_registrations', 'notified_at')) {
            $claimed = DB::table('gates_event_registrations')->where('id', $registrationId)
                ->whereNull('notified_at')
                ->update(['notified_at' => Carbon::now()->toDateTimeString()]);
            if ($claimed === 0) return;
        }

        $event = DB::table('gates_site_events')->where('id', (int) $reg->event_id)->first();
        if (!$event) return;

        $amount     = (int) ($reg->amount_naira ?? 0);
        $qty        = (int) ($reg->quantity ?? 1);
        $ticketCode = (string) ($reg->ticket_code ?? '');
        $reference  = (string) ($reg->reference ?? '');

        WebhookService::dispatchLater('event.registration', [
            'event'    => ['slug' => (string) ($event->slug ?? ''), 'title' => (string) ($event->title ?? '')],
            'attendee' => ['name' => (string) $reg->name, 'email' => (string) $reg->email,
                           'phone' => (string) ($reg->phone ?? '')],
            'ticket'   => ['code' => $ticketCode, 'quantity' => $qty, 'amount_naira' => $amount],
        ]);

        $base = rtrim(\AfricaGates\Support\SiteUrl::base(), '/');
        // The ticket page, not the event page. This is the link somebody opens at the door,
        // and it is reachable with the reference alone precisely so that they do not need an
        // account they never made in a queue with no signal.
        $ticketUrl = $reference !== ''
            ? $base . '/events/ticket/' . rawurlencode($reference)
            : $base . '/events/' . rawurlencode((string) ($event->slug ?? ''));

        if ($mailer) {
            $tier = '';
            if (!empty($reg->tier_id)) {
                try {
                    $tier = (string) (DB::table('gates_event_tiers')->where('id', (int) $reg->tier_id)->value('name') ?? '');
                } catch (\Throwable) { /* a ticket without its tier's name is still a ticket */ }
            }
            $where = implode(', ', array_values(array_unique(array_filter([
                trim((string) ($event->venue ?? '')), trim((string) ($event->location ?? '')),
            ]))));
            try {
                \AfricaGates\Services\Mail\Emails::send($mailer, (string) $reg->email, 'ticket', [
                    'first_name' => DoorWelcome::firstName((string) $reg->name) ?: 'there',
                    'event'      => (string) ($event->title ?? 'the event'),
                    'when'       => \AfricaGates\Support\DisplayTime::showZoned((string) ($event->event_date ?? '')),
                    'where'      => $where,
                    'code'       => $ticketCode,
                    'tier'       => $tier,
                    // A hosted PNG: most inboxes refuse a data: image, and the code is printed
                    // beside it and as its alt, so a blocked image still gets somebody in.
                    'qr_url'     => $reference !== '' && $ticketCode !== '' ? $ticketUrl . '/qr.png' : '',
                    'ticket_url' => $ticketUrl,
                    // And the date, as a file their calendar understands. A seat that goes
                    // unused is usually not a change of mind — it is somebody who read the date
                    // here, meant to write it down, and did not. A link, not an attachment.
                    'ics_url'    => $reference !== '' ? $ticketUrl . '/calendar.ics' : '',
                    'seats'      => $qty,
                    'paid'       => $amount > 0 ? '₦' . number_format($amount) : '',
                    'note'       => trim((string) ($event->attendee_note ?? '')),
                    'waitlisted' => (string) $reg->status === 'waitlisted',
                ]);
            } catch (\Throwable) { /* a mail failure must never undo a confirmed ticket */ }
        }

        Notifier::adminAlert($mailer,
            ($amount > 0 ? 'Event ticket sold' : 'New event RSVP') . ' — ' . (string) ($event->title ?? ''),
            'Event: ' . (string) ($event->title ?? '') . "\nName: {$reg->name}\nEmail: {$reg->email}"
            . "\nPhone: " . (string) ($reg->phone ?? '') . "\nSeats: {$qty}"
            . ($amount > 0 ? "\nPaid: NGN " . number_format($amount) : '')
            . ($ticketCode !== '' ? "\nTicket: {$ticketCode}" : ''));
    }
}
