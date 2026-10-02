<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\EmailOptOut;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * `gates_mail_log`: one row per message the platform decided about, and what became of it.
 *
 * ── FIVE OUTCOMES, AND ONLY TWO OF THEM ARE ATTEMPTS ─────────────────────────
 *
 *   sent        the provider accepted it
 *   failed      the provider, or the connection to it, refused it
 *   logged_dev  no transport configured outside production — written to a file
 *   refused     the send rules stopped it before any connection: see SendPolicy
 *   deferred    held by the daily cap on announcements; a later run may send it
 *
 * Every reader that turns this table into a RATE must count only {@see ATTEMPTS}. A
 * refusal is the rules working, not mail failing — counted as a failure it would open a
 * mail incident every time somebody's dead address was correctly not written to, and the
 * health page built to say "email is broken" would say it about a working platform.
 *
 * The address is never stored: `to_masked` is for an operator to recognise a row and
 * `to_hash` (the opt-out list's hash) is for the cap to count by.
 */
final class MailLog
{
    public const SENT     = 'sent';
    public const FAILED   = 'failed';
    public const DEV      = 'logged_dev';
    public const REFUSED  = 'refused';
    public const DEFERRED = 'deferred';

    /** What reached a transport. The denominator of every failure rate. */
    public const ATTEMPTS = [self::SENT, self::FAILED];

    /** What the send rules held back — never a failure. */
    public const HELD = [self::REFUSED, self::DEFERRED];

    public static function mask(string $to): string
    {
        [$local, $domain] = array_pad(explode('@', $to, 2), 2, '');
        return mb_substr(mb_substr($local, 0, 2) . '***@' . $domain, 0, 120);
    }

    /**
     * Mask every address inside free text — a server's refusal quotes the recipient
     * ("550 5.1.1 <ada@…>: user unknown"), so an unmasked error column puts back the
     * address `to_masked` exists to keep out.
     */
    public static function redact(?string $text): ?string
    {
        if ($text === null) return null;
        return (string) preg_replace_callback('~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i',
            static fn (array $m): string => self::mask($m[0]), $text);
    }

    /** Fault-tolerant: auditing can never break sending. */
    public static function write(string $to, string $subject, string $category, string $status,
                                 ?string $error = null, bool $bulk = false): void
    {
        try {
            DB::table('gates_mail_log')->insert([
                'to_masked'  => self::mask($to),
                'to_hash'    => EmailOptOut::hash($to),
                'bulk'       => $bulk ? 1 : 0,
                'subject'    => mb_substr($subject, 0, 200),
                'category'   => $category !== '' ? mb_substr($category, 0, 40) : null,
                'status'     => $status,
                'error'      => $error !== null ? mb_substr((string) self::redact($error), 0, 300) : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            // Before the send-rules migration the two new columns do not exist; the row
            // is still worth having without them. A held outcome is not written there at
            // all: the old column cannot hold its word, and recording it as a failure is
            // the miscount this class exists to prevent.
            if (in_array($status, self::HELD, true)) return;
            try {
                DB::table('gates_mail_log')->insert([
                    'to_masked' => self::mask($to), 'subject' => mb_substr($subject, 0, 200),
                    'category'  => $category !== '' ? mb_substr($category, 0, 40) : null,
                    'status'    => $status,
                    'error'     => $error !== null ? mb_substr((string) self::redact($error), 0, 300) : null,
                    'created_at'=> Carbon::now()->toDateTimeString(),
                ]);
            } catch (\Throwable) {
            }
        }
    }

    /** Announcements this address has been SENT since a moment — what the daily cap counts. */
    public static function bulkSentSince(string $to, string $since): int
    {
        try {
            return (int) DB::table('gates_mail_log')
                ->where('to_hash', EmailOptOut::hash($to))
                ->where('created_at', '>=', $since)
                ->where('bulk', 1)->where('status', self::SENT)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
