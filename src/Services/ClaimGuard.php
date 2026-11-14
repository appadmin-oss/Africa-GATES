<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The one authority on what a claim permits — and the code behind a promise the
 * platform was already making in writing.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS CLASS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every contact on a nomination receives this sentence when the page is claimed:
 *
 *     "No money moves on a claim less than 7 days old, and any payment can only ever
 *      go to a bank account in the nominee's own name."
 *
 * The seven days lived in one constant, interpolated into that email, and referenced
 * by nothing else. {@see CommunityReturnService::release()} — the money-out path — had
 * never read claim state at all. So the platform was making a safety promise to
 * precisely the people it was asking to trust claiming, and no code kept it.
 *
 * That is worse than a missing feature. A hijacked claim was worth money the instant it
 * activated, and the notification told the real nominee to reply to an email and wait.
 *
 * ── THE RULE, IN ONE SENTENCE ────────────────────────────────────────────────
 *
 * Money leaves only against a claim that is ACTIVE, PAST its cooling-off window, and
 * NOT under dispute.
 *
 * Each clause fails closed and for its own reason:
 *
 *   no claim        nobody has proved they are this nominee, so there is nobody to pay
 *   held / pending  a person is still deciding, and that decision is the point
 *   disputed        somebody on the nomination has said "this is not me"
 *   cooling off     the window the nominee was promised has not elapsed
 *
 * ── WHY IT REFUSES RATHER THAN WARNS ─────────────────────────────────────────
 *
 * An advisory check on a money path is a check that gets skipped under pressure, on the
 * day somebody is shouting about a delayed payout. There is an override, it is explicit,
 * and it demands a written reason that lands in the ledger next to the amount — because
 * the legitimate exceptions are real (a nominee who claimed by video call, a page an
 * administrator claimed on a nominee's behalf) and pretending otherwise just means the
 * guard gets commented out.
 */
final class ClaimGuard
{
    /**
     * How long after activation before money may move. Quoted to the nominee.
     *
     * The number lives HERE, and {@see ClaimNotifier} reads it, so the sentence in the
     * email and the behaviour of the platform cannot drift apart — which is exactly what
     * had happened.
     */
    public const COOLING_OFF_DAYS = 7;

    /**
     * May money move to this nominee, and if not, why not?
     *
     * @return array{payable:bool, code:string, reason:string, claim_id:?int,
     *               reference:?string, cooling_off_until:?string, disputed:bool}
     */
    public static function payoutState(int $nomineeId): array
    {
        $no = static fn(string $code, string $reason, array $extra = []): array => array_merge([
            'payable' => false, 'code' => $code, 'reason' => $reason, 'claim_id' => null,
            'reference' => null, 'cooling_off_until' => null, 'disputed' => false,
        ], $extra);

        if ($nomineeId < 1) return $no('NO_NOMINEE', 'No nominee.');

        try {
            // The whole claim history for this page, newest first — not just the active
            // row. A DISPUTED claim is no longer active (the dispute clears
            // active_nominee_id so page control reverts), and a payout must still be
            // blocked by it rather than falling through to "no claim, nothing to check".
            $claims = DB::table('gates_nominee_claims')
                ->where('nominee_id', $nomineeId)
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable $e) {
            // FAIL CLOSED. An unreadable claims table is not permission to pay.
            error_log('[claim-guard] could not read claims for nominee ' . $nomineeId . ': ' . $e->getMessage());
            return $no('UNREADABLE', 'Claim state could not be read, so no payment can be authorised.');
        }

        if ($claims->isEmpty()) {
            return $no('UNCLAIMED',
                'Nobody has claimed this page, so there is no verified person to pay.');
        }

        // An unresolved dispute outranks everything, whichever claim carries it.
        foreach ($claims as $c) {
            if (!empty($c->disputed_at) && (string) ($c->status ?? '') !== 'rejected') {
                return $no('DISPUTED',
                    'Someone on the nomination has disputed this claim. A person has to settle it '
                    . 'before any money moves.', [
                        'claim_id' => (int) $c->id,
                        'reference' => trim((string) ($c->reference ?? '')) ?: null,
                        'disputed' => true,
                    ]);
                }
        }

        $active = null;
        foreach ($claims as $c) {
            if ((string) ($c->status ?? '') === 'active') { $active = $c; break; }
        }

        if ($active === null) {
            $latest = $claims->first();
            $status = (string) ($latest->status ?? 'pending');
            return $no('NOT_ACTIVE', match ($status) {
                'held'    => 'This claim is with a person for review, and that review is the point — '
                           . 'no money moves until it finishes.',
                'revoked' => 'This claim was revoked, so there is no verified person to pay.',
                'rejected'=> 'This claim was refused, so there is no verified person to pay.',
                default   => 'This claim has not completed, so there is no verified person to pay.',
            }, ['claim_id' => (int) $latest->id,
                'reference' => trim((string) ($latest->reference ?? '')) ?: null]);
        }

        $until = self::coolingOffUntil($active);
        if ($until !== null && Carbon::now()->lt($until)) {
            return $no('COOLING_OFF',
                'This claim is inside the ' . self::COOLING_OFF_DAYS . '-day window the nominee was '
                . 'promised. Money may move from ' . $until->toDayDateTimeString() . '.', [
                    'claim_id' => (int) $active->id,
                    'reference' => trim((string) ($active->reference ?? '')) ?: null,
                    'cooling_off_until' => $until->toDateTimeString(),
                ]);
        }

        return [
            'payable' => true, 'code' => 'PAYABLE',
            'reason' => 'Claim is active, past its cooling-off window and undisputed.',
            'claim_id' => (int) $active->id,
            'reference' => trim((string) ($active->reference ?? '')) ?: null,
            'cooling_off_until' => $until?->toDateTimeString(),
            'disputed' => false,
        ];
    }

    /** Convenience for the callers that only need the yes/no. */
    public static function payable(int $nomineeId): bool
    {
        return self::payoutState($nomineeId)['payable'];
    }

    /**
     * When this claim's window closes.
     *
     * Prefers the STORED date. The window length is a policy that will change, and a
     * claim must be governed by the policy in force when it was made — deriving it from
     * today's constant would silently move a date a nominee has already been given in
     * writing. The fallback exists only for rows that predate the column.
     */
    public static function coolingOffUntil(object $claim): ?Carbon
    {
        $stored = trim((string) ($claim->cooling_off_until ?? ''));
        if ($stored !== '') {
            try { return Carbon::parse($stored); } catch (\Throwable) { /* fall through */ }
        }
        $from = trim((string) ($claim->activated_at ?? ''));
        if ($from === '') return null;
        try { return Carbon::parse($from)->addDays(self::COOLING_OFF_DAYS); }
        catch (\Throwable) { return null; }
    }

    /** The window a claim activating NOW should carry, as a string for the column. */
    public static function windowFromNow(): string
    {
        return Carbon::now()->addDays(self::COOLING_OFF_DAYS)->toDateTimeString();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Page control
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Is this page under someone's control right now?
     *
     * Separate from payoutState() on purpose: editing a page and being paid for it are
     * different permissions with different bars. A claim inside its cooling-off window
     * may absolutely manage the page — that is the whole point of claiming — while money
     * waits. Conflating them would either block a nominee from their own page for a week
     * or pay out on day one.
     */
    public static function controlledBy(int $nomineeId): ?array
    {
        if ($nomineeId < 1) return null;
        try {
            $c = DB::table('gates_nominee_claims')
                ->where('nominee_id', $nomineeId)->where('status', 'active')
                ->whereNull('disputed_at')
                ->orderByDesc('id')->first();
        } catch (\Throwable) { return null; }
        if ($c === null) return null;

        return [
            'claim_id'  => (int) $c->id,
            'user_id'   => (int) ($c->user_id ?? 0) ?: null,
            'reference' => trim((string) ($c->reference ?? '')) ?: null,
            'since'     => (string) ($c->activated_at ?? ''),
            // So a page can tell its owner, honestly, that the window is still open —
            // and that anybody on the nomination can still stop it.
            'cooling_off_until' => self::coolingOffUntil($c)?->toDateTimeString(),
        ];
    }
}
