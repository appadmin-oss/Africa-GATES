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

        $base  = rtrim(\AfricaGates\Support\SiteUrl::base(), '/');
        $e     = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $title = $e((string) ($event->title ?? 'the event'));
        $when  = $e((string) ($event->event_date ?? ''));
        $where = $e((string) ($event->location ?? ''));
        $nm    = $e((string) $reg->name);

        $ticketUrl = $reference !== ''
            ? $base . '/events/ticket/' . rawurlencode($reference)
            : $base . '/events/' . rawurlencode((string) ($event->slug ?? ''));

        $codeRow = $ticketCode !== ''
            ? '<br>Ticket code: <strong style="font-family:monospace;font-size:16px">' . $e($ticketCode) . '</strong>'
            : '';
        $seatRow = $qty > 1 ? '<br>Seats: <strong>' . $qty . '</strong>' : '';
        $paidRow = $amount > 0 ? '<br>Paid: <strong>₦' . number_format($amount) . '</strong>' : '';

        $html = "<p>Hi <strong>{$nm}</strong>,</p>"
              . '<p>' . ($amount > 0
                  ? 'Your payment has been received and your ticket is confirmed.'
                  : 'You are registered — we have saved your spot.') . '</p>'
              . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
              . 'style="margin:16px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px">'
              . "<tr><td style=\"font-size:14px;color:#166534;line-height:1.7\">Event: <strong>{$title}</strong><br>"
              . "When: <strong>{$when}</strong>"
              . ($where !== '' ? "<br>Where: <strong>{$where}</strong>" : '')
              . $seatRow . $paidRow . $codeRow . '</td></tr></table>'
              // The ticket page, not the event page. This is the link somebody opens at the
              // door, and it is reachable with the reference alone precisely so that they do
              // not need an account they never made in a queue with no signal.
              . '<p style="text-align:center;margin:22px 0 8px"><a href="' . $ticketUrl . '"'
              . ' style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;'
              . 'border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">Your ticket →</a></p>'
              // And the date, as a file their calendar understands. A seat that goes unused is
              // usually not a change of mind — it is somebody who read the date here, meant to
              // write it down, and did not.
              . ($reference !== ''
                  ? '<p style="text-align:center;margin:0 0 20px;font-size:13px">'
                    . '<a href="' . $ticketUrl . '/calendar.ics"'
                    . ' style="color:#237b22;font-weight:600;text-decoration:none">'
                    . 'Add it to your calendar</a></p>'
                  : '')
              . self::noteHtml($event);

        $note  = trim((string) ($event->attendee_note ?? ''));
        $plain = "Hi {$reg->name},\n\n"
               . ($amount > 0 ? "Your payment has been received and your ticket is confirmed.\n"
                              : "You are registered.\n")
               . 'Event: ' . (string) ($event->title ?? '') . "\n"
               . 'When: ' . (string) ($event->event_date ?? '') . "\n"
               . ($ticketCode !== '' ? "Ticket code: {$ticketCode}\n" : '')
               . "\nYour ticket: {$ticketUrl}\n"
               . ($reference !== '' ? "Add to your calendar: {$ticketUrl}/calendar.ics\n" : '')
               . ($note !== '' ? "\n" . $note . "\n" : '')
               . "\n— Africa GATES";

        if ($mailer) {
            try {
                $mailer->sendBranded(
                    (string) $reg->email,
                    ($amount > 0 ? 'Your ticket — ' : 'You are registered — ')
                        . (string) ($event->title ?? 'Africa GATES event'),
                    $html, $plain, 'Events',
                    $base . '/assets/img/illustrations/illo-trophy2.jpg'
                );
            } catch (\Throwable) { /* a mail failure must never undo a confirmed ticket */ }
        }

        Notifier::adminAlert($mailer,
            ($amount > 0 ? 'Event ticket sold' : 'New event RSVP') . ' — ' . (string) ($event->title ?? ''),
            'Event: ' . (string) ($event->title ?? '') . "\nName: {$reg->name}\nEmail: {$reg->email}"
            . "\nPhone: " . (string) ($reg->phone ?? '') . "\nSeats: {$qty}"
            . ($amount > 0 ? "\nPaid: NGN " . number_format($amount) : '')
            . ($ticketCode !== '' ? "\nTicket: {$ticketCode}" : ''));
    }

    /**
     * The organiser's note to attendees, as one escaped block.
     *
     * Escaped and THEN given paragraph breaks, in that order: an organiser typing an ampersand
     * or an angle bracket into a note about a venue must not be able to inject markup into an
     * email that goes out over this platform's name.
     */
    private static function noteHtml(?object $event): string
    {
        $note = trim((string) ($event->attendee_note ?? ''));
        if ($note === '') return '';
        $safe = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
        $safe = str_replace(["\r\n", "\r"], "\n", $safe);
        $safe = implode('</p><p style="margin:0 0 10px">',
                        array_filter(array_map('trim', explode("\n\n", $safe))));
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
             . ' style="margin:18px 0;background:#fffbeb;border-left:4px solid #f59e0b;'
             . 'border-radius:0 8px 8px 0;padding:14px 18px"><tr><td'
             . ' style="font-size:14px;color:#78350f;line-height:1.7">'
             . '<strong>Before you come</strong><p style="margin:8px 0 10px">'
             . nl2br($safe) . '</p></td></tr></table>';
    }
}
