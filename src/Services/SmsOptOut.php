<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Phone;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Numbers that have asked not to be texted.
 *
 * ── WHY THIS ARRIVED LATE ────────────────────────────────────────────────────
 *
 * Every text this platform sent until now was a REPLY: a claim code somebody asked for, a
 * verification they triggered, an interview slot they agreed to. Somebody who does not
 * want those does not ask for them, so {@see SmsService} shipped with no consent check and
 * nothing looked wrong.
 *
 * The check-in text is the first message a person gets without having asked for that
 * particular message — they bought a ticket and walked through a door. "No way to stop
 * it" stops being a courtesy question there and becomes a legal one in most of the places
 * these events happen.
 *
 * ── ENFORCED IN THE SERVICE, NOT AT THE CALL SITES ───────────────────────────
 *
 * {@see SmsService::sendSms()} checks this itself. Putting the check in each caller means
 * ten places that must all remember, and the eleventh — written next year, by somebody who
 * has not read this — is the one that texts a person who asked twice to be left alone.
 *
 * A transactional reply somebody just asked for can still be sent by passing
 * `$respectOptOut = false`: refusing to send a login code to a number that once opted out
 * of event texts locks a person out of their own account, which is not what they asked
 * for. That flag is the whole judgement, so it is a parameter with a name rather than a
 * comment.
 */
final class SmsOptOut
{
    /**
     * UNKEYED, and that is the deliberate choice — see below.
     *
     * ── WHY NOT HMAC WITH THE APP KEY ────────────────────────────────────────
     *
     * The obvious improvement is to key this: a phone number is about ten digits of
     * entropy inside a known country code, so a plain digest of every Nigerian mobile is
     * minutes of work on a laptop, and a keyed one is useless to somebody who steals the
     * table without the key.
     *
     * It was written that way first, and it was wrong. This hash is the LOOKUP, and a
     * lookup has to keep matching forever. Key it, and the day APP_KEY is first set — or
     * rotated, which is a thing an operator is supposed to be able to do — every stored
     * row stops matching its own number, and every person who asked not to be texted
     * starts being texted again. Silently. Nobody finds out until the complaints.
     *
     * {@see EmailOptOut::hash()} makes the same call for the same reason and keys only
     * its unsubscribe TOKEN, which is a credential and must be unforgeable. This table has
     * no credential in it, so there is nothing here for a key to protect that is worth a
     * suppression list emptying itself.
     *
     * The exposure this accepts: somebody who has already exfiltrated the table can test
     * candidate numbers against it offline. They learn who asked not to be texted, which
     * is a real but small harm next to the alternative.
     */
    public static function hash(string $e164): string
    {
        return hash('sha256', self::normalise($e164));
    }

    /** E.164, or '' when it is not a usable number at all. */
    public static function normalise(string $raw): string
    {
        return (string) (Phone::normalize($raw) ?? '');
    }

    /** Has this number asked to be left alone? */
    public static function suppressed(string $raw): bool
    {
        $e164 = self::normalise($raw);
        if ($e164 === '') return false;

        try {
            return DB::table('gates_sms_optout')
                ->where('phone_hash', self::hash($e164))
                ->exists();
        } catch (\Throwable) {
            // A deployment that has not run the migration has no opt-outs, not an error.
            // Failing OPEN is the right way round here and only here: the alternative is
            // that one missing table silently stops every text on the platform, including
            // the login codes people are waiting on.
            return false;
        }
    }

    /**
     * Record an opt-out. Idempotent — a second STOP is not an error, and a person who
     * sends one must still be left alone rather than shown a failure.
     */
    public static function record(string $raw, string $source = 'stop-reply'): bool
    {
        $e164 = self::normalise($raw);
        if ($e164 === '') return false;

        try {
            DB::table('gates_sms_optout')->updateOrInsert(
                ['phone_hash' => self::hash($e164)],
                [
                    // Last four digits only. A support desk answering "am I still getting
                    // these" cannot do anything with sixty-four hex characters, and the
                    // alternative is keeping the number.
                    'phone_masked' => Phone::mask($e164),
                    'source'       => mb_substr($source, 0, 60),
                    'created_at'   => Carbon::now()->toDateTimeString(),
                ]
            );

            return true;
        } catch (\Throwable $e) {
            // ── NEVER QUIETLY ─────────────────────────────────────────────
            //
            // This caught and returned false for months while phone_masked was too
            // narrow for a Nigerian number, so every STOP from the platform's home
            // market was refused by the database, swallowed here, and answered with a
            // 204. Nothing anywhere recorded that somebody had asked to be left alone
            // and had not been.
            //
            // A suppression that failed is the one thing in this class worth shouting
            // about: the caller cannot retry it, the person cannot tell, and the next
            // campaign will text them again.
            error_log('[sms-optout] COULD NOT RECORD an opt-out — the person asked to be '
                    . 'left alone and was not: ' . $e->getMessage());
            return false;
        }
    }

    /** Undo one, for a support desk acting on a request made some other way. */
    public static function remove(string $raw): bool
    {
        $e164 = self::normalise($raw);
        if ($e164 === '') return false;

        try {
            return DB::table('gates_sms_optout')->where('phone_hash', self::hash($e164))->delete() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
