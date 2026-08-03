<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A link that opens one support thread, for somebody with no account.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every ticket endpoint required a signed-in member. The reasoning was sound as far
 * as it went — "a ticket is a promise to reply, and a reply needs a verified
 * address" — but it locked out the two groups most likely to need support:
 *
 *   • GUEST PAYERS. Paid voting takes an email and a card and creates no account.
 *     The whole unminted-vote incident population was guests: given the repair
 *     tools, then no way to answer the reply they received.
 *   • HELD CLAIMANTS. The claim fairness rules require a human route that works
 *     WITHOUT an account, and the assisted path routes to a ticket — one the person
 *     it was opened for could not open.
 *
 * A support thread the requester cannot reply to is a monologue.
 *
 * ── THE PLEASANT SURPRISE ────────────────────────────────────────────────────
 *
 * {@see SupportTicketService::threadFor()} and `reply()` already accept
 * `userId = 0` and match on email alone. The service layer was built for this from
 * the start; only the HTTP layer demanded a member. So this class does one thing —
 * establish that the bearer of a link owns the address on a ticket — and then hands
 * off to the code that already exists. It grants no capability the member path does
 * not already have, and notably it cannot LIST anything.
 *
 * ── WHAT A LINK IS, AND IS NOT ───────────────────────────────────────────────
 *
 * It is permission for one person to see one conversation, for a while. It is not a
 * login, not a bearer key to a row id, and not transferable in any way we can
 * prevent — which is why the blast radius is deliberately one thread, why it
 * expires, and why changing a ticket's email kills every link already sent for it.
 *
 * Only the SHA-256 is stored. A dump of `gates_ticket_links` yields nothing usable,
 * for the same reason password hashes exist.
 */
final class TicketLinkService
{
    /**
     * How long a link stays usable.
     *
     * Thirty days is the life of a support conversation, not a session. Shorter and
     * the population this exists for — somebody on patchy mobile data who reads
     * their email on Saturday — arrives to a dead link and has to start again with
     * no account to fall back on, which is precisely the exclusion being fixed.
     * Every outbound reply mints a fresh one, so an active thread never expires
     * under anybody.
     */
    public const TTL_DAYS = 30;

    /** Bytes of entropy in a token. 32 → 64 hex characters, unguessable. */
    private const TOKEN_BYTES = 32;

    /** True once the table exists. Everything degrades to "no link" without it. */
    public static function ready(): bool
    {
        try { return DB::schema()->hasTable('gates_ticket_links'); }
        catch (\Throwable) { return false; }
    }

    /**
     * Mint a link for a ticket. Returns the RAW token — the only time it exists.
     *
     * A new token per outbound email rather than reusing one, because reuse would
     * require storing the raw value and the entire point is that we cannot. The cost
     * is a few rows; the benefit is that nothing recoverable is ever at rest.
     *
     * @return string|null null when the table is missing or the write failed. Callers
     *                     must treat that as "send the email without a link" and not
     *                     as a reason to fail the reply — a support answer that never
     *                     goes out is worse than one without a convenience link.
     */
    public static function issue(int $ticketId, string $email, ?int $days = null): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($ticketId < 1 || $email === '' || !self::ready()) return null;

        try {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            DB::table('gates_ticket_links')->insert([
                'ticket_id'  => $ticketId,
                'token_hash' => hash('sha256', $token),
                'email'      => $email,
                'expires_at' => Carbon::now()->addDays($days ?? self::TTL_DAYS)->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
            return $token;
        } catch (\Throwable $e) {
            error_log('[ticket-link] could not issue for ticket ' . $ticketId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Who does this token let in, if anyone?
     *
     * Every failure returns the SAME null. A token that is unknown, expired, revoked
     * or belongs to a ticket whose address has changed must be indistinguishable from
     * the outside — otherwise the endpoint becomes an oracle for which references
     * exist, and references appear in emails, receipts and screenshots.
     *
     * @return array{ticket_id:int, reference:string, email:string, expires_at:string}|null
     */
    public static function resolve(string $token): ?array
    {
        $token = trim($token);
        // Cheap shape check first, so a junk request never reaches the database.
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token) || !self::ready()) return null;

        try {
            $link = DB::table('gates_ticket_links')
                ->where('token_hash', hash('sha256', $token))
                ->first();
            if (!$link) return null;

            if (($link->revoked_at ?? null) !== null) return null;
            if (Carbon::parse((string) $link->expires_at)->lt(Carbon::now())) return null;

            $t = DB::table('gates_support_tickets')->where('id', (int) $link->ticket_id)
                ->first(['id', 'reference', 'email']);
            if (!$t) return null;

            // THE ADDRESS MUST STILL MATCH.
            //
            // A link is permission for one PERSON, not for a row id. If a ticket's
            // email is ever corrected or reassigned, every link already sent for it
            // dies here rather than continuing to admit whoever holds the old one.
            if (mb_strtolower(trim((string) $t->email)) !== mb_strtolower(trim((string) $link->email))) {
                return null;
            }

            return [
                'ticket_id'  => (int) $t->id,
                'reference'  => (string) $t->reference,
                'email'      => (string) $link->email,
                'expires_at' => (string) $link->expires_at,
            ];
        } catch (\Throwable $e) {
            error_log('[ticket-link] resolve failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Record that a link was used. Best-effort; never blocks the read.
     *
     * `uses` and `last_used_at` exist so an unusual pattern — one link opened from
     * many places, long after it was sent — is answerable after the fact. A link
     * that is forwarded is not by itself an incident, but it should not be invisible.
     */
    public static function touch(string $token): void
    {
        if (!self::ready()) return;
        try {
            DB::table('gates_ticket_links')->where('token_hash', hash('sha256', $token))->update([
                'last_used_at' => Carbon::now()->toDateTimeString(),
                'uses'         => DB::raw('uses + 1'),
            ]);
        } catch (\Throwable) {}
    }

    /** Kill every link for a ticket — for "that wasn't me" and for a reassigned address. */
    public static function revokeForTicket(int $ticketId): int
    {
        if ($ticketId < 1 || !self::ready()) return 0;
        try {
            return (int) DB::table('gates_ticket_links')
                ->where('ticket_id', $ticketId)->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) { return 0; }
    }

    /**
     * Drop links that expired a while ago.
     *
     * Kept a week past expiry rather than deleted on the day, so "my link stopped
     * working" can still be answered — the row proves one was issued and when it
     * lapsed. After that it is dead weight holding an email address.
     */
    public static function prune(int $graceDays = 7): int
    {
        if (!self::ready()) return 0;
        try {
            return (int) DB::table('gates_ticket_links')
                ->where('expires_at', '<', Carbon::now()->subDays(max(0, $graceDays))->toDateTimeString())
                ->delete();
        } catch (\Throwable) { return 0; }
    }

    /**
     * The absolute URL to put in an email.
     *
     * A path segment, not a query string: query strings are the part of a URL that
     * gets pasted into chat, quoted in "here's what I clicked" screenshots and
     * retained by more middleboxes. Neither placement is secret from the server's own
     * access log, which is why the real controls are the expiry, the single-thread
     * scope and the address binding rather than URL cosmetics.
     */
    public static function url(string $token, string $base = ''): string
    {
        $base = rtrim($base !== '' ? $base : (string) \AfricaGates\Support\SiteUrl::base(), '/');
        return $base . '/support/t/' . $token;
    }

    /**
     * Mint a link and return the URL, or '' when links are unavailable.
     *
     * The one call site an email template should need. Returning '' rather than
     * throwing is deliberate: an unmigrated database must degrade to an email with
     * no link, never to an unsent reply.
     */
    public static function urlFor(int $ticketId, string $email, string $base = ''): string
    {
        $token = self::issue($ticketId, $email);
        return $token === null ? '' : self::url($token, $base);
    }
}
