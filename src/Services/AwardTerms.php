<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * AN AWARD'S TERMS: WHICH VERSION IS IN FORCE, WHAT CHANGED, AND WHO ACCEPTED IT.
 *
 * The one reader and the one writer of `gates_award_terms` (migrations/2027_03_05_award_terms.php).
 *
 * ── WHY VERSIONS ─────────────────────────────────────────────────────────────
 *
 * §8.3: "Terms is versioned, shows the effective date and a changelog, and requires
 * acceptance before nominating or voting." The old column was overwritten in place, so the
 * question that arrives with every dispute — *which words did I agree to?* — had no answer.
 * A version is never edited: `publish()` adds a row when the text CHANGES and does nothing
 * when it does not, so saving a programme form twice is not two versions.
 *
 * ── ACCEPTANCE IS RECORDED WHERE THE ACT HAPPENS ─────────────────────────────
 *
 * `accept()` is called by the vote path once a vote has been recorded (free and paid), and
 * is available to the nomination path. It stores an email HASH, never the address, so the
 * record answers "did this person accept version 3" without becoming a second voter list.
 * It never throws: an acceptance that could not be written must not cost somebody their
 * vote, which is already the rule for the message that rides beside a vote.
 *
 * ── REQUIRED ONLY WHERE THERE ARE TERMS ──────────────────────────────────────
 *
 * A programme that has published none has nothing to accept, and refusing its votes for want
 * of a tick against an empty page would be the platform inventing a requirement. So
 * `required()` is false there, and the ballot shows the platform terms link instead.
 */
final class AwardTerms
{
    public const KIND_VOTE       = 'vote';
    public const KIND_PAID_VOTE  = 'paid_vote';
    public const KIND_NOMINATION = 'nomination';

    /** @var array<int, ?array<string,mixed>> */
    private static array $current = [];

    /**
     * The version in force now: the newest whose `effective_at` has passed.
     *
     * @return array{id:int, version:int, body:string, changelog:string, effective_at:string}|null
     */
    public static function current(int $programmeId): ?array
    {
        if ($programmeId < 1) return null;
        if (array_key_exists($programmeId, self::$current)) return self::$current[$programmeId];
        try {
            $row = DB::table('gates_award_terms')->where('programme_id', $programmeId)
                ->where('effective_at', '<=', Carbon::now()->toDateTimeString())
                ->orderByDesc('version')->first();
        } catch (\Throwable) {
            $row = null; // an unmigrated database: no versions, so nothing to accept
        }
        return self::$current[$programmeId] = $row ? self::shape($row) : null;
    }

    /**
     * Every version, newest first — the changelog the Terms tab prints.
     *
     * @return list<array{id:int, version:int, body:string, changelog:string, effective_at:string}>
     */
    public static function history(int $programmeId): array
    {
        try {
            return DB::table('gates_award_terms')->where('programme_id', $programmeId)
                ->where('effective_at', '<=', Carbon::now()->toDateTimeString())
                ->orderByDesc('version')->get()->map(fn ($r) => self::shape($r))->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Whether voting or nominating in this programme needs the terms ticked. */
    public static function required(int $programmeId): bool
    {
        return self::current($programmeId) !== null;
    }

    /**
     * Publish a new version when — and only when — the text differs from the current one.
     * Called by the admin programme form; effective immediately.
     *
     * @return int the new version number, or 0 when nothing changed
     */
    public static function publish(int $programmeId, string $body, string $changelog = '', ?int $adminId = null): int
    {
        $body = trim($body);
        if ($programmeId < 1 || $body === '') return 0;
        $latest = DB::table('gates_award_terms')->where('programme_id', $programmeId)->orderByDesc('version')->first();
        if ($latest && trim((string) $latest->body) === $body) return 0;

        $version = $latest ? (int) $latest->version + 1 : 1;
        $now = Carbon::now()->toDateTimeString();
        DB::table('gates_award_terms')->insert([
            'programme_id' => $programmeId, 'version' => $version, 'body' => $body,
            'changelog'    => mb_substr(trim($changelog) !== '' ? trim($changelog)
                                : ($latest ? 'Wording updated.' : 'First published version.'), 0, 500),
            'effective_at' => $now, 'created_at' => $now,
            'created_by'   => ($adminId !== null && $adminId > 0) ? $adminId : null,
        ]);
        unset(self::$current[$programmeId]);
        return $version;
    }

    /**
     * Record that somebody accepted the version in force. Never throws.
     *
     * @return bool true when a row now exists for it (written now or earlier)
     */
    public static function accept(int $programmeId, string $kind, string $email, ?int $subjectId = null): bool
    {
        $t = self::current($programmeId);
        $email = strtolower(trim($email));
        if ($t === null || $email === '') return false;
        $row = [
            'terms_id' => $t['id'], 'programme_id' => $programmeId, 'kind' => mb_substr($kind, 0, 20),
            'email_hash' => hash('sha256', $email), 'subject_id' => $subjectId,
        ];
        try {
            $q = DB::table('gates_award_terms_acceptance')->where('terms_id', $t['id'])
                ->where('email_hash', $row['email_hash'])->where('kind', $row['kind']);
            $subjectId === null ? $q->whereNull('subject_id') : $q->where('subject_id', $subjectId);
            if ($q->exists()) return true;
            DB::table('gates_award_terms_acceptance')->insert($row + ['accepted_at' => Carbon::now()->toDateTimeString()]);
            return true;
        } catch (\Throwable $e) {
            error_log('[award-terms] acceptance not recorded: ' . $e->getMessage());
            return false;
        }
    }

    /** For tests: the per-request memo. */
    public static function forget(): void
    {
        self::$current = [];
    }

    /** @return array{id:int, version:int, body:string, changelog:string, effective_at:string} */
    private static function shape(object $r): array
    {
        return [
            'id' => (int) $r->id, 'version' => (int) $r->version, 'body' => (string) $r->body,
            'changelog' => (string) ($r->changelog ?? ''), 'effective_at' => (string) $r->effective_at,
        ];
    }
}
