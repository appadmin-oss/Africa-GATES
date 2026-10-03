<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\EmailOptOut;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE SEND RULES — one decision, made at the transport, for every message.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AT THE TRANSPORT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Seventy call sites send mail. Some announcement senders filtered the opt-out list
 * before sending and some did not — the voting reminder went to people who had pressed
 * Unsubscribe for its whole life — and none of them knew which addresses had bounced,
 * because nothing recorded a bounce. A rule that each sender must remember is a rule the
 * seventy-first forgets. So `OtpService` asks this class before it opens a connection,
 * and a sender's own pre-filter becomes a courtesy (it can count who it skipped) rather
 * than the guarantee.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS AN ANNOUNCEMENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A message that carries an unsubscribe link. That is already the convention every bulk
 * sender here follows — it is what puts the RFC 8058 headers on — and it is the honest
 * test: a message somebody can be offered a way out of is one they did not ask for. A
 * sign-in code, a receipt, a ticket or a reply carries none, and is never held by the
 * rules about announcements.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULES, IN ORDER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * For every message:
 *   1. an address that is not an address is refused;
 *   2. an address at a domain reserved so that no mailbox can exist there (RFC 2606 and
 *      RFC 6761 — `.invalid`, `.test`, `.example`, `.localhost`, `example.com`) is
 *      refused. The sandbox mints `@demo.invalid` nominees precisely so they cannot be
 *      mailed, and every attempt was a hard bounce against the domain.
 * For an announcement, additionally:
 *   3. an address that said stop ({@see EmailOptOut}) is refused;
 *   4. an address the mail system has evidence against ({@see Suppression}) is refused;
 *   5. an address that has already been SENT the daily cap of announcements in the last
 *      twenty-four hours is deferred. A finalist in the last week of an award could
 *      otherwise receive a campaign, the voting reminder and the newsletter on one
 *      morning — three asks in an inbox is how the third becomes "report spam".
 *
 * A refusal or a deferral opens no connection, is written to the mail log as what it is,
 * and is never counted as mail failing.
 */
final class SendPolicy
{
    public const REFUSED  = MailLog::REFUSED;
    public const DEFERRED = MailLog::DEFERRED;

    public const CAP_KEY     = 'mail_bulk_daily_cap';
    public const CAP_DEFAULT = 2;
    public const CAP_MIN     = 1;
    public const CAP_MAX     = 6;
    public const CAP_HOURS   = 24;

    /** The share of the mail account's daily allowance announcements may use. */
    public const BULK_SHARE  = 0.6;

    /** Top-level names reserved so that nothing can ever be delivered there. */
    public const RESERVED_TLDS = ['invalid', 'test', 'example', 'localhost', 'local'];
    /** Second-level names reserved for documentation (RFC 2606 §3). */
    public const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /**
     * Decide one message.
     *
     * @return array{status:string, reason:string}|null null when it may be sent
     */
    public static function decide(string $to, bool $bulk, ?int $cap = null): ?array
    {
        $to = EmailOptOut::normalise($to);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['status' => self::REFUSED, 'reason' => 'Not a valid email address.'];
        }
        if (self::reserved($to)) {
            return ['status' => self::REFUSED,
                    'reason' => 'A reserved domain — no mailbox can exist there.'];
        }
        if (!$bulk) return null;

        if (EmailOptOut::suppressed($to)) {
            return ['status' => self::REFUSED, 'reason' => 'Unsubscribed from announcements.'];
        }
        if (($why = Suppression::reasonFor($to)) !== null) {
            return ['status' => self::REFUSED,
                    'reason' => (Suppression::REASONS[$why] ?? 'Suppressed') . ' — announcements are held.'];
        }

        // ── THE ACCOUNT'S OWN DAILY QUOTA ────────────────────────────────────────
        // Google refuses every message once the account's day is spent, sign-in codes
        // included. Announcements stop at BULK_SHARE of it, so the rest of the day is
        // left for the mail somebody is waiting for.
        $limit = self::dailyLimit();
        if ($limit > 0 && MailLog::sentSince(Carbon::now()->subHours(24)->toDateTimeString()) >= (int) floor($limit * self::BULK_SHARE)) {
            return ['status' => self::DEFERRED,
                    'reason' => sprintf('The mail account’s daily allowance is %d and announcements stop at %d%% of it, so sign-in codes keep working.',
                                        $limit, (int) round(self::BULK_SHARE * 100))];
        }

        $cap ??= self::cap();
        $since = Carbon::now()->subHours(self::CAP_HOURS)->toDateTimeString();
        if (MailLog::bulkSentSince($to, $since) >= $cap) {
            return ['status' => self::DEFERRED,
                    'reason' => sprintf('Already sent %d announcement%s in the last %d hours.',
                                        $cap, $cap === 1 ? '' : 's', self::CAP_HOURS)];
        }
        return null;
    }

    /** @var array{0:int,1:int}|null [read at, limit] */
    private static ?array $limitMemo = null;

    /**
     * The account's daily allowance, read at most once a minute: a newsletter asks this
     * for every address, and each ask would otherwise load the whole settings table.
     */
    private static function dailyLimit(): int
    {
        if (self::$limitMemo === null || time() - self::$limitMemo[0] >= 60) {
            self::$limitMemo = [time(), MailConfig::load()->dailyLimit()];
        }
        return self::$limitMemo[1];
    }

    /** For the suite, and for a settings save that must be in force at once. */
    public static function forget(): void
    {
        self::$limitMemo = null;
    }

    public static function reserved(string $email): bool
    {
        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));
        if ($domain === '') return true;
        if (in_array($domain, self::RESERVED_DOMAINS, true)) return true;
        foreach (self::RESERVED_DOMAINS as $d) {
            if (str_ends_with($domain, '.' . $d)) return true;
        }
        $tld = (string) substr((string) strrchr('.' . $domain, '.'), 1);
        return in_array($tld, self::RESERVED_TLDS, true);
    }

    /**
     * The daily cap on announcements per address — the one reader of the setting.
     *
     * Floored at one, because nought would read as "no limit" and mean "no announcements
     * ever", and capped at six, past which it is not a limit worth calling one.
     *
     * @param array<string,mixed>|null $settings an already-loaded settings map
     */
    public static function cap(?array $settings = null): int
    {
        $v = null;
        if ($settings !== null) {
            $v = $settings[self::CAP_KEY] ?? null;
        } else {
            try {
                $v = DB::table('gates_settings')->where('key_name', self::CAP_KEY)->value('value');
            } catch (\Throwable) {
            }
        }
        $n = is_numeric($v) ? (int) $v : self::CAP_DEFAULT;
        return max(self::CAP_MIN, min(self::CAP_MAX, $n));
    }

    public static function saveCap(int $n): int
    {
        $n = max(self::CAP_MIN, min(self::CAP_MAX, $n));
        DB::table('gates_settings')->updateOrInsert(['key_name' => self::CAP_KEY], ['value' => (string) $n]);
        return $n;
    }
}
