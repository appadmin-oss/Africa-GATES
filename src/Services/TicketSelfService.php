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
