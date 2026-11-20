<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\EmailOptOut;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Addresses the mail system has EVIDENCE it must stop writing to.
 *
 * ── WHY THIS IS NOT THE OPT-OUT LIST ─────────────────────────────────────────
 *
 * `EmailOptOut` is what a person chose. This is what the mail system was told:
 *
 *   bounce     the receiving server said the mailbox does not exist — a permanent (5xx)
 *              refusal at the moment of sending, or a provider's hard-bounce report.
 *   complaint  the reader pressed "report spam". Gmail and Yahoo's bulk-sender rules
 *              require that this stops announcements, and a sender that keeps writing to
 *              complainers is what gets a whole domain's mail filtered — the sign-in codes
 *              with it.
 *
 * Every announcement re-sent to a dead address is a hard bounce charged against the
 * domain's reputation, and nothing here used to remember one: the same nominee address
 * that bounced in August bounced again in every broadcast after it.
 *
 * ── IT STOPS ANNOUNCEMENTS, NOT WHAT SOMEBODY ASKED FOR ──────────────────────
 *
 * A sign-in code requested from the form is fresh evidence that somebody is at that
 * address now; refusing it on the strength of a bounce from months ago would lock a person
 * out of their own account with nothing on any screen to say why. So {@see SendPolicy}
 * consults this list for bulk mail only.
 *
 * Lifted by newer evidence: an operator who has checked the address, or the person
 * confirming a newsletter subscription from inside that very inbox — proof it receives.
 */
final class Suppression
{
    public const BOUNCE    = 'bounce';
    public const COMPLAINT = 'complaint';

    public const REASONS = [
        self::BOUNCE    => 'The mailbox does not exist',
        self::COMPLAINT => 'Reported as spam',
    ];

    /** A complaint outranks a bounce: it is the reader speaking. */
    private const RANK = [self::BOUNCE => 1, self::COMPLAINT => 2];

    public static function record(string $email, string $reason, string $source, string $detail = ''): void
    {
        if (!isset(self::REASONS[$reason])) return;
        $email = EmailOptOut::normalise($email);
        if ($email === '') return;
        $hash = EmailOptOut::hash($email);
        $now  = Carbon::now()->toDateTimeString();

        try {
            $row = DB::table('gates_mail_suppression')->where('email_hash', $hash)->first();
            if ($row === null) {
                DB::table('gates_mail_suppression')->insert([
                    'email_hash' => $hash, 'email_masked' => MailLog::mask($email),
                    'reason' => $reason, 'source' => mb_substr($source, 0, 40),
                    'detail' => $detail !== '' ? mb_substr((string) MailLog::redact($detail), 0, 300) : null,
                    'events' => 1, 'first_at' => $now, 'last_at' => $now,
                ]);
                return;
            }
            $keep = (self::RANK[(string) $row->reason] ?? 0) >= self::RANK[$reason];
            DB::table('gates_mail_suppression')->where('id', $row->id)->update([
                'reason' => $keep ? $row->reason : $reason,
                'source' => $keep ? $row->source : mb_substr($source, 0, 40),
                'detail' => $keep ? $row->detail : ($detail !== '' ? mb_substr((string) MailLog::redact($detail), 0, 300) : null),
                'events' => (int) $row->events + 1,
                'last_at'=> $now,
            ]);
        } catch (\Throwable) {
            // Before the migration, or a concurrent insert of the same address won the
            // UNIQUE key — either way the address is, or will next time be, recorded.
        }
    }

    /** The reason this address is suppressed, or null. */
    public static function reasonFor(string $email): ?string
    {
        try {
            $r = DB::table('gates_mail_suppression')->where('email_hash', EmailOptOut::hash($email))->value('reason');
            return $r !== null ? (string) $r : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Every suppressed hash, for filtering a list in one query. @return array<string,string> hash => reason */
    public static function hashes(): array
    {
        $out = [];
        try {
            foreach (DB::table('gates_mail_suppression')->get(['email_hash', 'reason']) as $r) {
                $out[(string) $r->email_hash] = (string) $r->reason;
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    public static function lift(string $email): bool
    {
        try {
            return DB::table('gates_mail_suppression')->where('email_hash', EmailOptOut::hash($email))->delete() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function liftById(int $id): bool
    {
        try {
            return DB::table('gates_mail_suppression')->where('id', $id)->delete() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<object> */
    public static function recent(int $limit = 20): array
    {
        try {
            return DB::table('gates_mail_suppression')->orderByDesc('last_at')->limit($limit)->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,int> reason => count */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::REASONS), 0);
        try {
            foreach (DB::table('gates_mail_suppression')->selectRaw('reason, COUNT(*) n')->groupBy('reason')->get() as $r) {
                $out[(string) $r->reason] = (int) $r->n;
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    /**
     * Whether a refusal from the receiving server is PERMANENT and about the mailbox.
     *
     * A 4xx is a "try later" — greylisting, a full mailbox, a busy server — and suppressing
     * on one would quietly delete real subscribers during somebody else's outage. Only a
     * 5xx that {@see MailFailure} attributes to the recipient counts; a 5xx about our
     * login or our From address is the platform's fault, not the address's.
     */
    public static function isPermanentBounce(string $error): bool
    {
        if (MailFailure::classify($error) !== MailFailure::RECIPIENT) return false;
        return (bool) preg_match('~\b5\d\d\b|\b5\.\d{1,3}\.\d{1,3}\b~', $error);
    }
}
