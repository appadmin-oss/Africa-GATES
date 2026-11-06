<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "This was not me." One action, from the message we sent, with no account.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE GAP THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The claim notification told every contact on the nomination:
 *
 *     "If this was not you — reply to this email, or write to support quoting REF.
 *      We will stop it while a person looks."
 *
 * The claim is already ACTIVE when that arrives. So the real nominee's only lever was
 * to compose an email and wait for a human to read it, while somebody else held their
 * page. For a platform whose nominees include children, the length of that window is
 * the whole vulnerability — and the people least likely to draft a support email are
 * exactly the population §1 of the doctrine is written about.
 *
 * A tokenised link in the same message costs one tap and freezes the claim immediately.
 * A person still decides the outcome; what changes is that nothing is at stake while
 * they get to it.
 *
 * ── WHY THE LINK ITSELF CANNOT BE THE ACTION ─────────────────────────────────
 *
 * This must NOT freeze on GET, however convenient a one-click URL sounds. Corporate
 * mail systems, Gmail, Outlook and every link-safety scanner FETCH the links in a
 * message before a human sees it. A freeze on GET would be tripped automatically on
 * a large fraction of legitimate claims — the platform would appear to break claiming
 * at random, and the cause would be invisible in the logs because the request looks
 * like an ordinary visitor.
 *
 * So GET renders a page with one button and POST performs the freeze. The token is in
 * the URL either way; it is possession of the token that authorises, and the button
 * that establishes a human meant it.
 *
 * ── WHAT A FREEZE DOES, AND DOES NOT DO ──────────────────────────────────────
 *
 * DOES: sets the claim to `held`, clears `active_nominee_id` so page control reverts
 * immediately, records who objected and when, and opens a ticket with the reference so
 * a person picks it up in the ordinary queue.
 *
 * DOES NOT: decide anything. It is not a rejection and it is not an accusation — the
 * commonest disputer is a parent who did not realise their child had claimed, and the
 * second commonest is a nominee whose nominator claimed on their behalf meaning well.
 * The wording everywhere reflects that. And it never reveals who claimed: telling an
 * anonymous token-holder the claimant's identity would make the dispute link an
 * information-disclosure endpoint.
 */
final class ClaimDispute
{
    /** A fresh token. 16 bytes hex — the column is CHAR(32). */
    public static function mintToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The token for a claim, minting one if it has none.
     *
     * Called by the notifier while composing the message, so a claim that predates the
     * column still gets a working link rather than a dead one.
     */
    public static function tokenFor(int $claimId): ?string
    {
        if ($claimId < 1) return null;
        try {
            $existing = trim((string) DB::table('gates_nominee_claims')
                ->where('id', $claimId)->value('dispute_token'));
            if ($existing !== '') return $existing;

            $token = self::mintToken();
            DB::table('gates_nominee_claims')->where('id', $claimId)->update(['dispute_token' => $token]);
            return $token;
        } catch (\Throwable $e) {
            // A missing column on an unmigrated database must not stop the notification
            // going out: being TOLD about a claim matters more than being able to stop it
            // in one tap, and the email still names the support address.
            error_log('[claim-dispute] could not mint a token for claim ' . $claimId . ': ' . $e->getMessage());
            return null;
        }
    }

    /** The absolute URL that goes in the message. */
    public static function url(int $claimId, string $base): ?string
    {
        $token = self::tokenFor($claimId);
        return $token === null ? null : rtrim($base, '/') . '/claim/dispute/' . $token;
    }

    /**
     * Resolve a token to what the page may safely show.
     *
     * Deliberately thin. The nominee's NAME is here because the reader has to know which
     * page this is about — they may be the contact on several nominations. The claimant's
     * identity is not, and neither is any contact detail: the only credential is a token
     * that arrived in a message, and a token-holder is not necessarily the nominee.
     *
     * @return array{claim_id:int, nominee:string, reference:string, status:string,
     *               already:bool, activated_at:string}|null
     */
    public static function preview(string $token): ?array
    {
        $c = self::byToken($token);
        if ($c === null) return null;

        $name = '';
        try {
            $name = (string) (DB::table('gates_nominees')->where('id', $c->nominee_id)->value('name') ?? '');
        } catch (\Throwable) { /* the page can say "this page" */ }

        return [
            'claim_id'     => (int) $c->id,
            'nominee'      => $name,
            'reference'    => trim((string) ($c->reference ?? '')),
            'status'       => (string) ($c->status ?? ''),
            'already'      => !empty($c->disputed_at),
            'activated_at' => (string) ($c->activated_at ?? ''),
        ];
    }

    /**
     * Freeze the claim.
     *
     * Idempotent: a second POST — a double tap, a retried form, a reload — returns the
     * same outcome rather than opening a second ticket. Somebody who taps twice because
     * they are frightened should not be punished with two support threads.
     *
     * @param string $who  the masked channel that objected, if the caller can tell
     * @return array{ok:bool, code:string, message:string, reference?:string}
     */
    public static function freeze(string $token, string $note = '', string $who = ''): array
    {
        $c = self::byToken($token);
        if ($c === null) {
            return ['ok' => false, 'code' => 'UNKNOWN',
                    'message' => 'That link is not valid. It may have already been used, or the claim '
                               . 'may have been settled. Write to support and a person will help.'];
        }

        $reference = trim((string) ($c->reference ?? ''));

        if (!empty($c->disputed_at)) {
            return ['ok' => true, 'code' => 'ALREADY',
                    'message' => 'This claim is already frozen and with a person. You do not need to do '
                               . 'anything else.', 'reference' => $reference];
        }

        try {
            DB::table('gates_nominee_claims')->where('id', $c->id)->update([
                'status'       => 'held',
                // CLEARED, which is what actually takes the page back. The column is the
                // unique key that grants control; leaving it set would freeze the claim on
                // paper while the claimant kept the page.
                'active_nominee_id' => null,
                'disputed_at'  => Carbon::now()->toDateTimeString(),
                'disputed_by'  => mb_substr(trim($who), 0, 120) ?: null,
                'dispute_note' => mb_substr(trim($note), 0, 400) ?: null,
                'hold_reason'  => 'Disputed by a contact on the nomination',
            ]);
        } catch (\Throwable $e) {
            error_log('[claim-dispute] could not freeze claim ' . $c->id . ': ' . $e->getMessage());
            return ['ok' => false, 'code' => 'FAILED',
                    'message' => 'We could not record that just now. Please write to support quoting '
                               . ($reference ?: 'this claim') . ' and a person will act on it.'];
        }

        self::openTicket($c, $reference, $note, $who);

        return ['ok' => true, 'code' => 'FROZEN',
                'message' => 'Done. The claim is frozen, the page is no longer under anyone\'s control, '
                           . 'and a person will look at it. Nothing can be paid out while it is frozen.',
                'reference' => $reference];
    }

    /**
     * Put the dispute in the ordinary support queue.
     *
     * Never throws and never blocks the freeze: the freeze is the protection, and a
     * ticket that failed to open is a delay rather than an exposure. Logged loudly
     * because a frozen claim nobody is told about is a page stuck in limbo.
     */
    private static function openTicket(object $claim, string $reference, string $note, string $who): void
    {
        try {
            $name = (string) (DB::table('gates_nominees')->where('id', $claim->nominee_id)->value('name') ?? '');
            $body = "A contact on the nomination has DISPUTED an active claim, using the link in the "
                  . "notification we sent them.\n\n"
                  . 'Page:              ' . ($name ?: ('nominee #' . $claim->nominee_id)) . "\n"
                  . 'Claim reference:   ' . ($reference ?: ('#' . $claim->id)) . "\n"
                  . 'Objection came from: ' . ($who !== '' ? $who : 'a channel we could not identify') . "\n"
                  . 'Claim activated at: ' . ((string) ($claim->activated_at ?? 'unknown')) . "\n"
                  . 'Code originally went to: ' . ((string) ($claim->channel_hint ?? 'unknown')) . "\n\n"
                  . ($note !== '' ? "What they said:\n" . $note . "\n\n" : "They left no note.\n\n")
                  . "STATE NOW: the claim is held, the page is under nobody's control, and "
                  . "ClaimGuard refuses any payout while the dispute stands.\n\n"
                  . "This is not an accusation. The commonest disputer is a parent who did not know "
                  . "their child had claimed, and the second commonest is a nominee whose nominator "
                  . "claimed on their behalf meaning well. Settle it, do not judge it.\n";

            // The real API is an instance method, and it takes a SupportContext. No email
            // is attached: the token-holder is not necessarily the nominee, and inventing
            // a requester address would put the thread in front of the wrong person.
            (new SupportTicketService())->open(
                $body,
                [],
                new SupportContext(null, null),
                [],
                ['subject_override' => 'Disputed claim — ' . ($reference ?: ('claim #' . $claim->id))]
            );
        } catch (\Throwable $e) {
            error_log('[claim-dispute] frozen claim ' . $claim->id . ' but could NOT open a ticket: '
                    . $e->getMessage() . ' — somebody must pick this up by hand.');
        }
    }

    /** @return object|null the claim row, by dispute token */
    private static function byToken(string $token): ?object
    {
        $token = trim($token);
        // 32 hex characters exactly. Checked before the query so a short or malformed
        // token never becomes a database round trip on a public endpoint.
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        try {
            return DB::table('gates_nominee_claims')->where('dispute_token', $token)->first();
        } catch (\Throwable) { return null; }
    }
}
