<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Phone;

/**
 * Send one real message, to a number an operator typed, and report exactly what happened.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS BESIDE A PAGE OF PROBES THAT DELIBERATELY DO NOT SEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see ProviderProbe} asks every vendor a read-only question — a balance, an account, a
 * token — because a diagnostic that fires on a page load must never spend money or ring a
 * real phone. That is the right default and it stays.
 *
 * But a passing probe is not a delivered message, and the gap between the two is where
 * this platform's SMS has actually failed:
 *
 *   THE SANDBOX.        Africa's Talking issues sandbox credentials first, and a sandbox
 *                       account has its own hostname. The balance probe answers happily;
 *                       the message goes to a simulator and no handset on earth rings.
 *   THE SENDER ID.      Termii answers `200 OK` with a `message` field explaining that the
 *                       sender ID is not approved. The key is valid. The account is fine.
 *                       Nothing arrives, forever.
 *   THE TRIAL ACCOUNT.  Twilio trial credentials authenticate perfectly and refuse every
 *                       recipient that has not been verified in their console.
 *
 * All three read as a configured, working gateway on every screen this platform has. The
 * only question that separates them is "did a phone in my hand buzz", and until now
 * nothing here could ask it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * SO THE RULES IT IS BOUND BY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   NEVER ON A PAGE LOAD.     An operator types a number and presses a button. There is no
 *                             route into this that a refresh, a prefetch or a probe run can
 *                             take.
 *   THE OPT-OUT LIST WINS.    Somebody who replied STOP has asked not to be texted, and
 *                             "it was only a test" is not an exception a person consented
 *                             to. Refused, and the refusal says why.
 *   CAPPED.                   {@see PER_HOUR} per admin. Every send costs money and this is
 *                             a button on a page somebody will press twice.
 *   ITS OWN TEMPLATE.         `admin_test` in `gates_messages`, so a test send is never
 *                             mistaken for a notification anybody was owed.
 */
final class MessageSendTest
{
    /**
     * Test sends allowed per admin per hour.
     *
     * Enough to change a setting and try again a few times, which is the actual workflow;
     * far too few to matter on a bill. Keyed on the ADMIN and not the destination, because
     * the thing worth bounding is the pressing, not the phone.
     */
    public const PER_HOUR = 6;

    /** The template name these are filed under, so they are findable and never look real. */
    public const TEMPLATE = 'admin_test';

    /**
     * What it says.
     *
     * A person receiving this is a colleague holding a phone, not a user — so it names the
     * platform (an unidentified SMS is alarming and, in several places, unlawful), says
     * plainly that nothing is wrong, and carries a reference that is also on the audit row
     * and on the screen. Under 160 GSM-7 characters with the reference filled in, so it
     * costs one segment rather than two.
     */
    public const BODY = 'Africa GATES: test message from the admin console. Nothing is wrong, '
                      . 'nothing needs doing. Ref %s';

    /**
     * Send it.
     *
     * Never throws. Every refusal and every failure comes back in the same shape, because
     * the screen has to tell them apart and an exception is the one form it cannot render.
     *
     * @param string $channel 'sms' or 'whatsapp'
     * @return array{ok:bool, sent:bool, channel:string, provider:?string, to:string,
     *               ref:?string, ms:int, detail:string, note:string}
     */
    public static function send(string $channel, string $rawNumber, int $adminId,
                                ?SmsService $sms = null, ?RateLimitService $limits = null): array
    {
        $channel = $channel === 'whatsapp' ? 'whatsapp' : 'sms';
        $sms   ??= SmsService::boot();

        $fail = static fn (string $detail, string $note = ''): array => [
            'ok' => false, 'sent' => false, 'channel' => $channel, 'provider' => null,
            'to' => '', 'ref' => null, 'ms' => 0, 'detail' => $detail, 'note' => $note,
        ];

        $provider = $channel === 'whatsapp' ? $sms->whatsappProvider() : $sms->smsProvider();
        if ($provider === null) {
            return $fail($channel === 'whatsapp'
                ? 'No WhatsApp transport is configured, so there is nothing to test yet.'
                : 'No SMS gateway is configured, so there is nothing to test yet.');
        }

        // Normalised before anything else is decided: every check below — the opt-out list,
        // the audit row, the gateway itself — is about a specific number, and a number
        // typed as 08012345678 is not the one they are about.
        $to = Phone::normalize($rawNumber);
        if ($to === null) {
            return $fail('That is not a number this can send to. Write it in full '
                       . 'international form, like +234 801 234 5678.');
        }

        // ── THE OPT-OUT LIST IS NOT WAIVED FOR A TEST ────────────────────────
        //
        // `deliver()` does not consult it — the queue's callers do, before they get here —
        // so this is the check, and it has to be here rather than assumed. A person who
        // replied STOP asked not to be texted by this platform; they did not agree to an
        // exception for the times we are curious whether our gateway works.
        if (SmsOptOut::suppressed($to)) {
            return $fail('That number has opted out of messages from this platform, so '
                       . 'nothing was sent. Try a number that has not.');
        }

        $limits ??= new RateLimitService();
        try {
            if (!$limits->check('admin:' . $adminId, 'msg_test', self::PER_HOUR, 3600)) {
                return $fail('That is ' . self::PER_HOUR . ' test messages this hour, which '
                           . 'is the limit. Every one of them costs money.');
            }
        } catch (\Throwable) {
            // A limiter that cannot read its own table must not be the reason an operator
            // cannot diagnose their gateway. The cap is a cost control, not a safety one.
        }

        $ref  = strtoupper(bin2hex(random_bytes(3)));
        $body = sprintf(self::BODY, $ref);
        $note = self::note($channel, $provider, $sms);

        $t0 = microtime(true);
        try {
            // `deliver()` and not `sendSms()`: the best-effort wrapper swallows the reason
            // and answers false, and the reason is the entire point of this screen.
            $sms->deliver($channel, $to, $body, self::TEMPLATE);
            $ok = true; $why = '';
        } catch (\Throwable $e) {
            $ok = false; $why = trim($e->getMessage());
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);

        return [
            'ok'       => $ok,
            'sent'     => true,
            'channel'  => $channel,
            'provider' => $provider,
            'to'       => Phone::mask($to),
            'ref'      => $ok ? $ref : null,
            'ms'       => $ms,
            'detail'   => $ok
                ? 'The gateway accepted it. Reference ' . $ref . ' — if no phone buzzes, the '
                  . 'message was accepted and then dropped downstream, which is a different '
                  . 'fault from this one.'
                // The provider's OWN words. A rewritten error is a second thing to be wrong
                // about, and "Invalid sender id" is an instruction where "delivery failed"
                // is a shrug.
                : ($why !== '' ? $why : 'The gateway refused it and gave no reason.'),
            'note'     => $note,
        ];
    }

    /**
     * The thing that is true about this gateway and is not visible in its answer.
     *
     * Every one of these is a case where the send SUCCEEDS and no phone rings — which is
     * the failure this whole screen exists to catch, and the only one a success message
     * would otherwise hide.
     */
    private static function note(string $channel, string $provider, SmsService $sms): string
    {
        if ($channel === 'sms' && $provider === 'africastalking' && $sms->atSandbox()) {
            return 'This account is the Africa\'s Talking SANDBOX, so the message goes to '
                 . 'their simulator and no handset will ring. Switch to live credentials '
                 . 'before an event.';
        }
        if ($channel === 'sms' && $provider === 'twilio') {
            return 'On a Twilio trial account only numbers verified in their console can '
                 . 'receive anything; everything else is refused with that reason.';
        }
        if ($channel === 'sms' && $provider === 'termii') {
            return 'Termii answers 200 even when it rejects a sender ID, so read the '
                 . 'reference above rather than the status.';
        }

        return '';
    }

    /**
     * How many of this hour's allowance an admin has left. For the screen.
     *
     * Read through the limiter rather than off the table: the column is `hit_count`, and a
     * reader here that guessed `hits` would get null, report a full allowance forever and
     * be indistinguishable from a correct one.
     */
    public static function remaining(int $adminId, ?RateLimitService $limits = null): int
    {
        try {
            $used = ($limits ?? new RateLimitService())->hits('admin:' . $adminId, 'msg_test', 3600);
        } catch (\Throwable) {
            return self::PER_HOUR;
        }

        return max(0, self::PER_HOUR - $used);
    }
}
