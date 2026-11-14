<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Somebody has charged back a payment, and the clock is already running.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY SILENCE IS THE EXPENSIVE OPTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Paystack gives a merchant **16 hours** to respond to a dispute. If the window
 * closes with no response, Paystack accepts the dispute on the merchant's behalf
 * and refunds the customer out of the merchant's balance. It also re-sends
 * `charge.dispute.remind` every four hours while the dispute is unresolved.
 *
 * The webhook handler already did the careful half of this: on
 * `charge.dispute.create` it claws back the votes that payment bought, because the
 * bank has already pulled the funds whatever the eventual resolution. Then it
 * wrote a log line and stopped.
 *
 * A log line on a cPanel host with no shell is not a notification. So the platform
 * was configured, in effect, to concede every dispute and pay for it — the loss
 * being the amount of the transaction, at the moment the deadline passed, with
 * nobody having read anything. The reminder events fire every four hours and are
 * deliberately ignored as step events, so there was no second chance either.
 *
 * This does not resolve the dispute. Doing that properly means the evidence flow —
 * fetch a 30-minute signed upload URL, PUT a receipt, then resolve as accepted or
 * declined — and that is a real feature with a real UI, not something to bolt onto
 * a webhook. What it does is make sure a person knows, in time, with the reference
 * and the deadline in front of them.
 *
 * ── TWO CHANNELS, ON PURPOSE ─────────────────────────────────────────────────
 *
 * A ticket, because it is durable and visible in a console that somebody already
 * checks, and because it survives a mail failure. Marked urgent, which also means
 * {@see SupportAutoResolver} will not touch it — a machine must not answer a
 * chargeback. And an email, because a ticket nobody opens for a day is no use
 * against a 16-hour SLA.
 *
 * ── AND IT IS QUEUED, NOT SENT INLINE ────────────────────────────────────────
 *
 * The webhook it fires from has roughly 30 seconds for the entire delivery, and
 * SMTP alone can take 12. The queue drains on the maintenance tick, which costs
 * about fifteen minutes of a sixteen-hour window — under two per cent of it — in
 * exchange for a webhook that answers immediately and never enters Paystack's
 * 72-hour retry schedule.
 */
final class DisputeAlert
{
    public const JOB = 'payment.dispute';

    /** Paystack accepts the dispute for you after this long. */
    public const RESPOND_WITHIN_HOURS = 16;

    /**
     * Record the alert for delivery on the next maintenance tick.
     *
     * Deduped on the reference so the four-hourly reminders — if they are ever
     * routed here too — cannot bury the desk in copies of one dispute.
     */
    public static function queue(string $reference, string $event, string $provider, ?int $amountNaira): void
    {
        $payload = ['reference' => $reference, 'event' => $event,
                    'provider' => $provider, 'amount' => $amountNaira,
                    'seen_at' => Carbon::now()->toDateTimeString()];
        try {
            (new QueueService())->push(self::JOB, $payload, 0, 'dispute:' . $reference);
        } catch (\Throwable $e) {
            error_log('[dispute] could not queue the alert for ' . $reference . ': ' . $e->getMessage());
        }
    }

    /**
     * Tell a person. Never throws: this runs in the job worker, and a mail failure
     * must not stop the rest of the queue draining.
     *
     * @param array<string,mixed> $p the queued payload
     */
    public static function send(array $p, ?OtpService $mailer = null): void
    {
        $ref      = trim((string) ($p['reference'] ?? ''));
        $event    = trim((string) ($p['event'] ?? 'dispute'));
        $provider = trim((string) ($p['provider'] ?? ''));
        $seenAt   = trim((string) ($p['seen_at'] ?? '')) ?: Carbon::now()->toDateTimeString();

        // The deadline as a time, not a duration. "16 hours" needs arithmetic done by
        // somebody who has just been handed bad news; a timestamp does not.
        $deadline = Carbon::now()->toDateTimeString();
        try {
            $deadline = Carbon::parse($seenAt)->addHours(self::RESPOND_WITHIN_HOURS)->toDateTimeString();
        } catch (\Throwable) {}

        $amount = $p['amount'] ?? null;
        $who    = '';
        $when   = '';
        try {
            $d = DB::table('gates_donations')->where('payment_ref', $ref)
                ->first(['donor_email', 'amount_naira', 'created_at', 'tier']);
            if ($d) {
                $amount ??= (int) ($d->amount_naira ?? 0);
                $who  = (string) ($d->donor_email ?? '');
                $when = (string) ($d->created_at ?? '');
            }
        } catch (\Throwable) {}

        $body = "A DISPUTE (chargeback) has been raised against a payment, and the votes it "
              . "bought have already been taken back.\n\n"
              . 'Reference:   ' . ($ref !== '' ? $ref : 'unknown') . "\n"
              . 'Gateway:     ' . ($provider !== '' ? $provider : 'unknown') . ' (' . $event . ")\n"
              . 'Amount:      ' . ($amount !== null ? ('NGN ' . number_format((float) $amount)) : 'unknown') . "\n"
              . 'Paid by:     ' . ($who !== '' ? $who : 'not on record') . "\n"
              . 'Paid at:     ' . ($when !== '' ? $when : 'not on record') . "\n"
              . 'Raised at:   ' . $seenAt . "\n\n"
              . "RESPOND BEFORE " . $deadline . " (" . self::RESPOND_WITHIN_HOURS . " hours).\n\n"
              . "If that deadline passes with no response, Paystack accepts the dispute for you "
              . "and refunds the customer from your balance. Doing nothing is not neutral — it is "
              . "how the money is lost.\n\n"
              . "WHERE TO DO IT: " . rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/')
              . "/admin/payments/disputes\n\n"
              . "That screen shows the hours remaining, what our own records say this payment "
              . "delivered, and the exact receipt it would send as evidence — and it does the whole "
              . "Paystack evidence flow in one press. The Paystack dashboard still works if you "
              . "prefer it, but you would have to find and attach the receipt yourself.\n\n"
              . "The votes have already been removed, so the tally is correct either way. "
              . "Nothing here needs undoing if you win.\n";

        // The ticket first: it is the durable half, and it must exist even if no mail
        // transport is configured.
        try {
            (new SupportTicketService())->open(
                $body,
                [],
                new SupportContext(null, null),
                [],
                ['subject_override' => 'Chargeback — respond before ' . $deadline
                                     . ' (' . ($ref !== '' ? $ref : 'unknown reference') . ')',
                 'severity' => 'urgent']
            );
        } catch (\Throwable $e) {
            error_log('[dispute] could not open a ticket for ' . $ref . ': ' . $e->getMessage());
        }

        Notifier::adminAlert($mailer, 'Chargeback on ' . ($ref !== '' ? $ref : 'an unknown reference')
                                    . ' — respond before ' . $deadline, $body);
    }
}
