<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The four things an attendee should never have to email support about.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS NEEDS A SECOND FACTOR WHEN THE TICKET PAGE DOES NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `/events/ticket/{ref}` is reachable with the reference alone, deliberately: an attendee has
 * no account, and putting a login between somebody and the door they are standing at is worse
 * than the risk. Reading a ticket you are holding is what a ticket is for.
 *
 * CHANGING one is a different act. The reference travels inside a QR code that gets
 * photographed, shown across a table, and posted to group chats — so a bearer token that can
 * also hand the ticket to somebody else is a ticket that anyone who glanced at a screen can
 * steal, and the theft is invisible until the real holder is refused at the door.
 *
 * So the split is by CONSEQUENCE, not by convenience:
 *
 *   RESEND      · reference alone. It can only ever send to the address already on the
 *                 booking, so a stranger holding the reference achieves nothing except mailing
 *                 the rightful owner. Rate-limited, because that is still a way to bother them.
 *   RENAME      · a code, emailed to the booking address.
 *   TRANSFER    · a code, emailed to the booking address.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY A TRANSFER RE-ISSUES THE CODE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The same doctrine as {@see EventTicketService::release()} clearing `ticket_code`: after a
 * transfer, the previous holder's screenshot must stop working. If it did not, "transfer"
 * would mean "two people can now get in on one ticket", and the second one through the door
 * would be turned away in front of a queue with a ticket they were legitimately given.
 *
 * Refunds and cancellations are NOT here, on purpose. Giving a seat up is a money decision —
 * the organiser may hold a deposit, the tier may be non-refundable — and this codebase already
 * says so where an organiser withdraws a seat: "a withdrawal and a refund are different
 * decisions". A self-service cancel that silently did neither would be the worst of both.
 */
final class TicketSelfService
{
    /** How long an emailed confirmation code lives. */
    private const CODE_TTL_MINUTES = 20;

    /** Wrong guesses before a code is burned. */
    private const MAX_ATTEMPTS = 5;

    private const PURPOSE = 'ticket-self';

    /**
     * Email the ticket again, to the address already on it.
     *
     * Returns the same answer whether or not the reference exists — this endpoint is reachable
     * by anybody, and a different answer for a real reference would make it a way to test
     * them. Rate-limited per reference for the same reason.
     *
     * @return array{ok:bool, message:string}
     */
    public static function resend(string $reference, ?OtpService $mailer,
                                  ?RateLimitService $limiter = null): array
    {
        $said = ['ok' => true, 'message' => 'If that ticket exists, we have emailed it to the '
                                          . 'address it was booked with.'];

        $reg = EventTicketService::byReference(trim($reference));
        if (!$reg) return $said;

        if ($limiter !== null && !$limiter->check(
                'ticket-resend:' . hash('sha256', (string) $reg->reference), 'ticket_resend', 4, 3600)) {
            return ['ok' => false, 'message' => 'That has been sent a few times already. '
                                              . 'Please check your spam folder, or try again later.'];
        }

        // Clear the claim so the mailer will actually send again — it is claimed once per
        // registration precisely so three racing confirmations send one email, and a deliberate
        // resend is the one case that must defeat that.
        try {
            if (OptionalColumn::on('gates_event_registrations', 'notified_at')) {
                DB::table('gates_event_registrations')->where('id', (int) $reg->id)
                    ->update(['notified_at' => null]);
            }
        } catch (\Throwable) {}

        EventTicketMailer::send((int) $reg->id, $mailer);
        return $said;
    }

    /**
     * Send a confirmation code to the address on the booking.
     *
     * The address is MASKED in the reply. Somebody holding a photographed QR should be able to
     * see that a code went to a•••@gmail.com — enough for the rightful owner to recognise
     * their own address — without the page handing a stranger the attendee's email.
     *
     * @return array{ok:bool, message:string, sent_to:string}
     */
    public static function sendCode(string $reference, ?OtpService $mailer,
                                    ?RateLimitService $limiter = null): array
    {
        $reg = EventTicketService::byReference(trim($reference));
        if (!$reg) {
            return ['ok' => false, 'message' => 'That ticket could not be found.', 'sent_to' => ''];
        }
        if ((string) $reg->status !== 'confirmed') {
            return ['ok' => false, 'sent_to' => '',
                    'message' => 'This ticket is not confirmed yet, so it cannot be changed.'];
        }

        if ($limiter !== null && !$limiter->check(
                'ticket-code:' . hash('sha256', (string) $reg->reference), 'ticket_code', 5, 3600)) {
            return ['ok' => false, 'sent_to' => '',
                    'message' => 'Too many codes have been requested for this ticket. Try again later.'];
        }

        $email = strtolower(trim((string) $reg->email));
        $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            DB::table('gates_otp_tokens')
                ->where('purpose', self::PURPOSE)->where('email_hash', self::key($reg))
                ->where('is_used', 0)->update(['is_used' => 1]);

            DB::table('gates_otp_tokens')->insert([
                // Keyed on the REFERENCE, not the address: one attendee may hold tickets to
                // two events, and a code for one must not unlock the other.
                'email_hash' => self::key($reg),
                'token_hash' => hash('sha256', $code),
                'purpose'    => self::PURPOSE,
                'nominee_id' => null,
                'award_id'   => null,
                'attempts'   => 0,
                'is_used'    => 0,
                'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES)->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[ticket] could not store a self-service code: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That could not be sent just now.', 'sent_to' => ''];
        }

        if ($mailer !== null) {
            try {
                $mailer->sendBranded($email, 'Your code for changing a ticket',
                    '<p>Your confirmation code is:</p>'
                    . '<p style="font-family:monospace;font-size:30px;font-weight:700;'
                    . 'letter-spacing:.14em;margin:14px 0">' . $code . '</p>'
                    . '<p>It lasts ' . self::CODE_TTL_MINUTES . ' minutes and can be used once.</p>'
                    // The sentence that turns a leaked reference into a caught one.
                    . '<p><strong>If you did not ask to change your ticket, ignore this email — '
                    . 'nothing has changed.</strong> Somebody may have your ticket link, so tell '
                    . 'us and we will re-issue it.</p>',
                    "Your confirmation code is {$code}. It lasts " . self::CODE_TTL_MINUTES
                    . " minutes.\n\nIf you did not ask to change your ticket, ignore this — "
                    . "nothing has changed.",
                    'Events');
            } catch (\Throwable) { /* the code is stored; a mail failure is not a state change */ }
        }

        return ['ok' => true, 'sent_to' => self::mask($email),
                'message' => 'We have sent a 6-digit code to ' . self::mask($email) . '.'];
    }

    /**
     * Check a code and burn it. Single-use, attempt-capped, expiring.
     *
     * @return array{ok:bool, message:string}
     */
    private static function consume(object $reg, string $code): array
    {
        $code = trim($code);
        if ($code === '') return ['ok' => false, 'message' => 'Enter the 6-digit code we emailed you.'];

        try {
            $tok = DB::table('gates_otp_tokens')
                ->where('purpose', self::PURPOSE)->where('email_hash', self::key($reg))
                ->where('is_used', 0)
                ->where('expires_at', '>', Carbon::now()->toDateTimeString())
                ->orderByDesc('id')->first();
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be checked just now.'];
        }

        if (!$tok) {
            return ['ok' => false, 'message' => 'That code has expired or was already used. '
                                              . 'Ask for a new one.'];
        }

        DB::table('gates_otp_tokens')->where('id', $tok->id)->increment('attempts');
        if (((int) $tok->attempts + 1) > self::MAX_ATTEMPTS) {
            DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
            return ['ok' => false, 'message' => 'Too many tries on that code. Ask for a new one.'];
        }

        if (!hash_equals((string) $tok->token_hash, hash('sha256', $code))) {
            return ['ok' => false, 'message' => 'That code is not right.'];
        }

        DB::table('gates_otp_tokens')->where('id', $tok->id)->update(['is_used' => 1]);
        return ['ok' => true, 'message' => ''];
    }

    /**
     * Change the name printed on the ticket.
     *
     * The commonest real need, and the one most often handled by an attendee simply turning up
     * under somebody else's name — which makes the door list useless and any per-person
     * catering or security check impossible.
     *
     * @return array{ok:bool, message:string}
     */
    public static function rename(string $reference, string $code, string $name): array
    {
        $reg = EventTicketService::byReference(trim($reference));
        if (!$reg) return ['ok' => false, 'message' => 'That ticket could not be found.'];
        if ((string) $reg->status !== 'confirmed') {
            return ['ok' => false, 'message' => 'This ticket is not confirmed yet.'];
        }
        if (($reg->checked_in_at ?? null) !== null) {
            return ['ok' => false, 'message' => 'This ticket has already been used at the door, '
                                              . 'so its name cannot be changed.'];
        }

        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            return ['ok' => false, 'message' => 'Enter the name that should be on the ticket.'];
        }

        $gate = self::consume($reg, $code);
        if (!$gate['ok']) return $gate;

        try {
            DB::table('gates_event_registrations')->where('id', (int) $reg->id)
                ->update(['name' => $name]);
        } catch (\Throwable $e) {
            error_log('[ticket] rename failed for ' . $reference . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => 'The ticket is now in the name ' . $name . '.'];
    }

    /**
     * Hand the ticket to somebody else.
     *
     * ── WHAT CHANGES, AND WHAT DELIBERATELY DOES NOT ─────────────────────────
     *
     * The name, the email and the ticket CODE change. The reference does not: it is the key
     * every other table joins on — the payment route, the gateway ledger, the reconciler — and
     * rewriting it to hand a ticket over would orphan the money from the seat.
     *
     * A fresh code is the security half. Without it the previous holder's screenshot still
     * scans, "transfer" quietly means "two people can enter on one ticket", and the second one
     * through the door is refused in front of a queue holding a ticket they were legitimately
     * given.
     *
     * Both parties are told. The recipient needs the new link; the sender needs to know it
     * left — and if they did not do it, that email is how they find out.
     *
     * @return array{ok:bool, message:string}
     */
    public static function transfer(string $reference, string $code, string $toName,
                                    string $toEmail, ?OtpService $mailer): array
    {
        $reg = EventTicketService::byReference(trim($reference));
        if (!$reg) return ['ok' => false, 'message' => 'That ticket could not be found.'];
        if ((string) $reg->status !== 'confirmed') {
            return ['ok' => false, 'message' => 'This ticket is not confirmed yet.'];
        }
        if (($reg->checked_in_at ?? null) !== null) {
            return ['ok' => false, 'message' => 'This ticket has already been used at the door, '
                                              . 'so it cannot be transferred.'];
        }

        $toName  = trim($toName);
        $toEmail = strtolower(trim($toEmail));
        if (mb_strlen($toName) < 2)                          return ['ok' => false, 'message' => 'Enter their name.'];
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL))    return ['ok' => false, 'message' => 'Enter a valid email address for them.'];
        if ($toEmail === strtolower(trim((string) $reg->email))) {
            return ['ok' => false, 'message' => 'That is already the address on this ticket. '
                                              . 'To change only the name, use “Change the name”.'];
        }

        $gate = self::consume($reg, $code);
        if (!$gate['ok']) return $gate;

        $from     = (string) $reg->email;
        $fromName = (string) $reg->name;
        $fresh    = EventTicketService::freshCode();

        try {
            DB::table('gates_event_registrations')->where('id', (int) $reg->id)
                ->where('status', 'confirmed')->whereNull('checked_in_at')
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'name'        => $toName,
                    'email'       => mb_substr($toEmail, 0, 190),
                    // The old screenshot stops working here.
                    'ticket_code' => $fresh,
                    // So the new holder actually gets their copy: the mailer is claimed once
                    // per registration, and this is a new person who has never been sent it.
                    'notified_at' => null,
                ], ['ticket_code', 'notified_at']));
        } catch (\Throwable $e) {
            error_log('[ticket] transfer failed for ' . $reference . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        // The new holder gets the ticket itself, with the new code on it.
        EventTicketMailer::send((int) $reg->id, $mailer);

        // And the previous holder is told, by name, that it has gone. If they did not do this,
        // this email is the only way they find out — so it names who has it and what to do.
        if ($mailer !== null && $from !== '') {
            try {
                $mailer->sendBranded($from, 'Your ticket has been transferred',
                    '<p>Hi ' . htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>The ticket booked under reference <strong>'
                    . htmlspecialchars((string) $reg->reference, ENT_QUOTES, 'UTF-8')
                    . '</strong> has been transferred to <strong>'
                    . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                    . '<p>Your old ticket code no longer works at the door.</p>'
                    . '<p><strong>If you did not do this</strong>, reply to this email straight '
                    . 'away and we will put it back.</p>',
                    "Hi {$fromName},\n\nThe ticket booked under {$reg->reference} has been "
                    . "transferred to {$toName}. Your old code no longer works at the door.\n\n"
                    . "If you did not do this, reply to this email straight away and we will "
                    . "put it back.\n",
                    'Events');
            } catch (\Throwable) { /* the transfer happened; the notice is best-effort */ }
        }

        return ['ok' => true, 'message' => 'Done — the ticket is now ' . $toName . '\'s, and we '
                                         . 'have emailed it to them. Your old code no longer works.'];
    }

    /**
     * Give the seat up, and pay back whatever the organiser's policy says.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE ORDER OF OPERATIONS IS THE DESIGN
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Cancel first, refund second. It looks like the riskier order and it is the safe one:
     *
     *   REFUND FIRST, THEN CANCEL   a failure between them means money has left the account
     *                               and the seat is still held. Nothing on the platform knows,
     *                               because the row still reads `confirmed`.
     *   CANCEL FIRST, THEN REFUND   a failure between them means the seat is released — which
     *                               is what the attendee asked for — and the row records
     *                               `refund_status = pending` with nobody paid. Visible,
     *                               alerted, and fixable by hand.
     *
     * The second failure is recoverable and the first is silent. So the seat is released by a
     * conditional UPDATE (which is also what makes a double-click safe: exactly one caller
     * wins and only the winner asks the gateway for money), the intent to refund is written
     * BEFORE the network call so a crash mid-call leaves evidence, and the gateway's answer
     * updates it afterwards.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND `pending` IS A REAL ANSWER, NOT A FAILURE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Paystack queues a refund and settles it hours later. Treating anything but `refunded` as
     * an error would make the caller retry a refund that is already on its way — which is how
     * somebody gets paid back twice. See {@see PaymentService::refund()}.
     *
     * @return array{ok:bool, message:string, refunded:int, status:string}
     */
    public static function cancel(string $reference, string $code, ?OtpService $mailer,
                                  ?PaymentService $payments = null): array
    {
        $no = static fn (string $m): array =>
            ['ok' => false, 'message' => $m, 'refunded' => 0, 'status' => ''];

        $reg = EventTicketService::byReference(trim($reference));
        if (!$reg) return $no('That booking could not be found.');

        // The quote is taken BEFORE anything changes, and it is the same function the page
        // showed the attendee — so the figure they agreed to is the figure they get.
        $q = EventRefundPolicy::quote($reg);
        if (!$q['can_cancel']) return $no($q['why']);

        $gate = self::consume($reg, $code);
        if (!$gate['ok']) return $gate + ['refunded' => 0, 'status' => ''];

        $owed = (int) $q['naira'];

        // ── 1 · the seat, by conditional UPDATE ──────────────────────────────
        try {
            $won = DB::table('gates_event_registrations')->where('id', (int) $reg->id)
                ->where('status', 'confirmed')->whereNull('checked_in_at')
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'status'        => 'cancelled',
                    'cancelled_at'  => Carbon::now()->toDateTimeString(),
                    'cancelled_by'  => 'attendee',
                    'notes'         => 'cancelled by the attendee',
                    // The old screenshot stops working at the door. Same reason as a transfer.
                    'ticket_code'   => null,
                    // Written before the gateway is called, so a crash mid-call is evidence
                    // rather than silence.
                    'refund_status' => $owed > 0 ? 'pending' : 'none',
                    'refund_naira'  => $owed > 0 ? $owed : null,
                ], ['status', 'cancelled_at', 'cancelled_by', 'notes', 'ticket_code',
                    'refund_status', 'refund_naira'])) > 0;
        } catch (\Throwable $e) {
            error_log('[ticket] self-cancel failed for ' . $reference . ': ' . $e->getMessage());
            return $no('That could not be saved just now.');
        }

        if (!$won) {
            // Somebody else got there first — a double-click, or an organiser withdrawing the
            // seat in the same second. Not an error to the person in front of us.
            return ['ok' => true, 'refunded' => 0, 'status' => 'already',
                    'message' => 'This booking was already cancelled.'];
        }

        // The seat is genuinely free now, so the queue can have it.
        try { EventTicketService::releaseDiscountFor($reg); } catch (\Throwable) {}
        try { EventWaitlist::promote((int) ($reg->tier_id ?? 0), 1, $mailer); } catch (\Throwable) {}

        // ── 2 · the money ────────────────────────────────────────────────────
        if ($owed < 1) {
            self::tellCancelled($reg, 0, 'none', $mailer);
            return ['ok' => true, 'refunded' => 0, 'status' => 'none',
                    'message' => 'Your place has been given up. ' . $q['why']];
        }

        $payments ??= new PaymentService();
        $provider = strtolower(trim((string) ($reg->provider ?? ''))) ?: 'paystack';

        // A PARTIAL amount is passed only when it is genuinely partial. Passing our own figure
        // for a full refund asks the gateway to trust our arithmetic over its own record of
        // what it collected — and it fails asymmetrically: too low succeeds quietly and leaves
        // the buyer short with every column reading `refunded`. See PaymentService::refund().
        $ask = ($q['mode'] === 'full' && $owed === (int) ($reg->amount_naira ?? 0)) ? null : $owed;

        $r = $payments->refund($provider, (string) $reg->reference, $ask);

        $status = match ((string) ($r['status'] ?? 'pending')) {
            'refunded' => 'refunded',
            'failed'   => 'failed',
            default    => 'pending',
        };

        try {
            DB::table('gates_event_registrations')->where('id', (int) $reg->id)
                ->update(OptionalColumn::filter('gates_event_registrations', [
                    'refund_status' => $status,
                    'refund_ref'    => $r['provider_ref'] ?? null,
                    'refunded_at'   => $status === 'refunded' ? Carbon::now()->toDateTimeString() : null,
                ], ['refund_status', 'refund_ref', 'refunded_at']));
        } catch (\Throwable) {}

        if ($status === 'failed') {
            // The seat is gone and the money is not. Loud, because only a person can fix it and
            // nothing else on the platform is watching this row.
            Notifier::adminAlert($mailer, 'Event refund FAILED — a seat was released and nobody was paid',
                'Reference: ' . (string) $reg->reference . "\n"
                . 'Attendee:  ' . (string) $reg->name . ' <' . (string) $reg->email . '>' . "\n"
                . 'Owed:      ₦' . number_format($owed) . "\n"
                . 'Gateway:   ' . (string) ($r['message'] ?? 'no message') . "\n\n"
                . "Their place has been given up and the ticket code cleared, so this is money "
                . "owed with nothing holding it. Refund it by hand from the payments screen.");

            self::tellCancelled($reg, $owed, 'failed', $mailer);
            return ['ok' => true, 'refunded' => $owed, 'status' => 'failed',
                    'message' => 'Your place has been given up. The refund of ₦'
                               . number_format($owed) . ' could not be sent automatically — '
                               . 'the team has been told and will sort it out.'];
        }

        self::tellCancelled($reg, $owed, $status, $mailer);

        return ['ok' => true, 'refunded' => $owed, 'status' => $status,
                'message' => $status === 'refunded'
                    ? 'Your place has been given up and ₦' . number_format($owed)
                      . ' has been refunded.'
                    // Named as a real state rather than dressed up as done — "up to 10
                    // business days" is the honest answer and the one that prevents a
                    // support email on day two.
                    : 'Your place has been given up and a refund of ₦' . number_format($owed)
                      . ' is on its way. It can take a few days to reach your bank.'];
    }

    /** Tell the attendee, and the team, what just happened to the booking. */
    private static function tellCancelled(object $reg, int $naira, string $status,
                                          ?OtpService $mailer): void
    {
        $money = match ($status) {
            'refunded' => '<p>We have refunded <strong>₦' . number_format($naira) . '</strong>.</p>',
            'pending'  => '<p>A refund of <strong>₦' . number_format($naira) . '</strong> is on its '
                        . 'way. Refunds usually take a few days to reach a bank.</p>',
            'failed'   => '<p>The refund of <strong>₦' . number_format($naira) . '</strong> did not '
                        . 'go through automatically. We know, and we are sorting it out.</p>',
            default    => '<p>There was nothing to refund on this booking.</p>',
        };

        if ($mailer !== null) {
            try {
                $mailer->sendBranded((string) $reg->email, 'Your booking has been cancelled',
                    '<p>Hi ' . htmlspecialchars((string) $reg->name, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>Your place has been given up, and your ticket code no longer works at '
                    . 'the door.</p>' . $money
                    . '<p style="font-family:monospace">Reference '
                    . htmlspecialchars((string) $reg->reference, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p><strong>If you did not do this</strong>, reply to this email straight '
                    . 'away.</p>',
                    'Hi ' . (string) $reg->name . ",\n\nYour place has been given up and your "
                    . "ticket code no longer works at the door.\n"
                    . ($naira > 0 ? '₦' . number_format($naira) . " refund: {$status}.\n" : '')
                    . 'Reference ' . (string) $reg->reference . "\n\n"
                    . "If you did not do this, reply to this email straight away.\n",
                    'Events');
            } catch (\Throwable) { /* the cancellation happened; the notice is best-effort */ }
        }

        Notifier::adminAlert($mailer, 'Event booking cancelled by the attendee',
            'Reference: ' . (string) $reg->reference . "\n"
            . 'Attendee:  ' . (string) $reg->name . ' <' . (string) $reg->email . '>' . "\n"
            . 'Seats:     ' . (int) ($reg->quantity ?? 1) . "\n"
            . 'Paid:      ₦' . number_format((int) ($reg->amount_naira ?? 0)) . "\n"
            . 'Refund:    ' . ($naira > 0 ? '₦' . number_format($naira) . ' (' . $status . ')' : 'none'));
    }

    /** The OTP key for one registration. Scoped to the reference, not the address. */
    private static function key(object $reg): string
    {
        return hash('sha256', 'ticket:' . (string) $reg->reference);
    }

    /** a•••@gmail.com — enough to recognise your own address, not enough to learn somebody's. */
    private static function mask(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) return '•••';
        return mb_substr($email, 0, 1) . '•••' . mb_substr($email, $at);
    }
}
