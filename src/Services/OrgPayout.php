<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * An organisation asking for its money.
 *
 * ── WHERE THE MONEY ACTUALLY IS, WHICH DECIDES WHAT THIS DOES ────────────────
 *
 * Partner donations split at the gateway, so an organisation's share never passes through
 * an Africa GATES account. Where it sits afterwards depends on the subaccount's own
 * settlement schedule, and that is the difference between the two modes below:
 *
 *   • SETTLEMENT MODE (default). The subaccount settles on Paystack's own schedule straight
 *     to the organisation's bank. There is nothing for this platform to send, because the
 *     money was never ours to send. A withdrawal request here is a REQUEST — recorded,
 *     visible to both sides, and actioned by a human against the gateway. That is honest
 *     about what is happening, which a button that pretended to move money would not be.
 *
 *   • TRANSFER MODE. The platform holds a balance and pays out of it with the Transfers
 *     API. This is the only mode where Africa GATES is a custodian of somebody else's
 *     charitable money, with the AML and insolvency questions that follow, so it is OFF
 *     unless `PARTNER_PAYOUT_MODE=transfer` is set deliberately. It is built and tested
 *     because a platform that grows into a ledger should not also be writing its payout
 *     code for the first time on the day it needs it.
 *
 * Both modes share one row and one state machine, so a payout's history reads the same
 * either way and switching modes does not orphan anything.
 *
 * ── THE REFERENCE IS THE ONLY THING BETWEEN A RETRY AND PAYING TWICE ─────────
 *
 * Paystack documents no client-supplied idempotency key, so ours is the mechanism. It is
 * generated and COMMITTED before the gateway is called, and a retry reuses it verbatim.
 * Transfer references are stricter than transaction references — lowercase alphanumerics,
 * hyphen and underscore only — so they are minted to that shape here rather than being
 * corrected later, when they would already be written on a row.
 */
final class OrgPayout
{
    /** Nothing has been sent yet. */
    public const ST_QUEUED = 'queued';

    /**
     * Paystack's transfer states, verbatim. Copying their vocabulary rather than inventing
     * ours means a support conversation can quote a status and have it mean the same thing
     * on both sides of the call.
     */
    public const ST_PENDING   = 'pending';    // in flight, not conclusive
    public const ST_RECEIVED  = 'received';   // awaiting merchant approval, not conclusive
    public const ST_OTP       = 'otp';        // needs an OTP, not conclusive
    public const ST_SUCCESS   = 'success';    // conclusive — though not instant credit
    public const ST_FAILED    = 'failed';     // conclusive
    public const ST_REVERSED  = 'reversed';   // conclusive — money came back
    public const ST_ABANDONED = 'abandoned';  // conclusive — OTP never given
    public const ST_BLOCKED   = 'blocked';    // conclusive — approval endpoint too slow
    public const ST_REJECTED  = 'rejected';   // conclusive — approval endpoint declined

    /** A payout in one of these will not change again by itself. */
    public const TERMINAL = [
        self::ST_SUCCESS, self::ST_FAILED, self::ST_REVERSED,
        self::ST_ABANDONED, self::ST_BLOCKED, self::ST_REJECTED,
    ];

    /**
     * States that still hold money against an organisation's balance.
     *
     * `success` counts: the money is gone. The conclusive failures do NOT, because the
     * amount came back and must become available again — a payout that failed and still
     * held the balance down would strand a charity's money with no way to ask for it.
     */
    public const HOLDS_FUNDS = [
        self::ST_QUEUED, self::ST_PENDING, self::ST_RECEIVED, self::ST_OTP, self::ST_SUCCESS,
    ];

    /** Smallest payout worth the gateway fee and the operational attention. */
    public const MIN_NAIRA = 1000;

    public static function mode(): string
    {
        return strtolower(trim((string) Env::get('PARTNER_PAYOUT_MODE', 'settlement'))) === 'transfer'
            ? 'transfer'
            : 'settlement';
    }

    /**
     * What an organisation may still ask for.
     *
     * Net confirmed donations, less everything already requested that has not conclusively
     * failed. Computed rather than stored: a balance column is a second source of truth for
     * a number that must never disagree with the rows underneath it.
     */
    public static function available(int $orgId): int
    {
        $net = PartnerOrg::totals($orgId)['net'];

        try {
            $held = (int) DB::table('gates_org_payouts')
                ->where('org_id', $orgId)
                ->whereIn('status', self::HOLDS_FUNDS)
                ->sum('amount_naira');
        } catch (\Throwable) {
            // If we cannot read what is already out, the safe answer is "nothing more".
            return 0;
        }
        return max(0, $net - $held);
    }

    /** @return array<int,object> */
    public static function history(int $orgId, int $limit = 50): array
    {
        try {
            return DB::table('gates_org_payouts')
                ->where('org_id', $orgId)
                ->orderByDesc('id')->limit(max(1, min(200, $limit)))
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A transfer-safe reference. Lowercase alphanumerics, hyphen and underscore only.
     *
     * Paystack has announced it will begin ENFORCING `reference` as required on Initiate
     * Transfer, so supplying our own is both the safe choice today and the one that does not
     * break later.
     */
    public static function mintReference(int $orgId): string
    {
        return 'agpo-' . $orgId . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Record the request, then — in transfer mode — try to send it.
     *
     * The row is committed BEFORE the gateway is called. That ordering is the whole
     * idempotency story: if the response is lost, the reference already exists, the sweep
     * can ask what happened to it, and no retry can invent a second one.
     *
     * @return array{ok:bool,message:string,reference:string,status:string}
     */
    public static function request(
        PaymentService $payments,
        int            $orgId,
        int            $amountNaira,
        int            $byOrgUserId
    ): array {
        $fail = ['ok' => false, 'reference' => '', 'status' => ''];

        $org = PartnerOrg::find($orgId);
        if (!$org) return $fail + ['message' => 'That organisation does not exist.'];

        // A suspended partner cannot pull money out any more than it can take money in.
        if ((string) $org->status !== PartnerOrg::STATUS_APPROVED) {
            return $fail + ['message' => 'This organisation is not currently approved, so payouts are on hold.'];
        }
        if ($amountNaira < self::MIN_NAIRA) {
            return $fail + ['message' => 'The smallest payout is ₦' . number_format(self::MIN_NAIRA) . '.'];
        }

        $available = self::available($orgId);
        if ($amountNaira > $available) {
            return $fail + ['message' => 'That is more than is available. You can request up to ₦'
                                       . number_format($available) . '.'];
        }

        $reference = self::mintReference($orgId);

        try {
            DB::table('gates_org_payouts')->insert([
                'org_id'       => $orgId,
                'reference'    => $reference,
                'amount_naira' => $amountNaira,
                'status'       => self::ST_QUEUED,
                'requested_by' => $byOrgUserId > 0 ? $byOrgUserId : null,
                'requested_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return $fail + ['message' => 'Could not record that request. Nothing was sent.'];
        }

        // Settlement mode stops here, and says so plainly rather than implying money moved.
        if (self::mode() !== 'transfer') {
            return [
                'ok' => true, 'reference' => $reference, 'status' => self::ST_QUEUED,
                'message' => 'Payout requested. Your donations settle to your own account through '
                           . 'Paystack — this request tells us to release the next settlement, and '
                           . 'you will see it here when it moves.',
            ];
        }

        return self::send($payments, $reference);
    }

    /**
     * Hand a queued payout to the gateway. Safe to call again with the same reference.
     *
     * @return array{ok:bool,message:string,reference:string,status:string}
     */
    public static function send(PaymentService $payments, string $reference): array
    {
        $row = DB::table('gates_org_payouts')->where('reference', $reference)->first();
        if (!$row) return ['ok' => false, 'reference' => $reference, 'status' => '', 'message' => 'Unknown payout.'];

        // Never re-send something already in flight or finished. This is the second half of
        // the idempotency story — the first is that the reference never changes.
        if ((string) $row->status !== self::ST_QUEUED) {
            return ['ok' => true, 'reference' => $reference, 'status' => (string) $row->status,
                    'message' => 'That payout has already been sent.'];
        }

        $org = PartnerOrg::find((int) $row->org_id);
        if (!$org) return ['ok' => false, 'reference' => $reference, 'status' => '', 'message' => 'Unknown organisation.'];

        // ── THE RECIPIENT WAS CREATED AT ONBOARDING ─────────────────────────
        //
        // Building it here would need the bank account number, which this platform
        // deliberately does not store — so it is created in the one request that legitimately
        // has the number, and only its code is kept. A payout needs nothing else.
        //
        // Missing means onboarding could not reach Paystack at the time. That is recoverable
        // from the admin screen and is NOT something to paper over by inventing a second
        // recipient here: two live handles to one bank account with no way to tell which a
        // transfer used is how a reconciliation becomes unanswerable.
        $recipient = trim((string) ($row->recipient_code ?? '')) ?: trim((string) ($org->payout_recipient_code ?? ''));
        if ($recipient === '') {
            $why = 'This organisation has no payout recipient on file, so a transfer cannot be '
                 . 'built. Re-attach its settlement account from the admin screen.';
            self::markAttempt($reference, $why);
            return ['ok' => false, 'reference' => $reference, 'status' => self::ST_QUEUED, 'message' => $why];
        }
        if (trim((string) ($row->recipient_code ?? '')) === '') {
            // Stamped onto the payout row so the transfer's handle is recorded against the
            // payment, not merely against the organisation it happened to belong to today.
            DB::table('gates_org_payouts')->where('reference', $reference)->update(['recipient_code' => $recipient]);
        }

        $sent = $payments->initiateTransfer(
            (int) $row->amount_naira, $recipient, $reference,
            'Africa GATES payout to ' . $org->name
        );

        if (!$sent['ok']) {
            // ── AND THIS IS NOT "IT DID NOT HAPPEN" ──────────────────────────
            //
            // A refused transfer and a lost response look identical from here. The row stays
            // QUEUED with its reference intact so the sweep can ask Paystack what became of
            // it; what must never happen is a retry under a fresh reference, which is how a
            // charity gets paid twice.
            self::markAttempt($reference, $sent['message']);
            return ['ok' => false, 'reference' => $reference, 'status' => self::ST_QUEUED,
                    'message' => $sent['message']];
        }

        self::applyStatus($reference, $sent['status'] ?: self::ST_PENDING, $sent['message'], [
            'transfer_code'       => $sent['transfer_code'],
            'gateway_transfer_id' => $sent['transfer_id'],
        ]);

        return ['ok' => true, 'reference' => $reference, 'status' => $sent['status'],
                'message' => 'Payout sent to the bank. It is not confirmed until the gateway says so.'];
    }

    /**
     * Move a payout to a gateway-reported state.
     *
     * ── STATE-CHECKING, NOT SEQUENCE-ASSUMING ────────────────────────────────
     *
     * Nothing guarantees webhook ordering, and a delivery can arrive twice. So this never
     * treats "the event I am holding" as the latest word: a payout already in a TERMINAL
     * state is left alone, which makes a late `pending` arriving after a `success`
     * harmless rather than a payout that reopens itself.
     */
    public static function applyStatus(string $reference, string $status, string $message = '', array $extra = []): bool
    {
        $status = strtolower(trim($status));
        if ($status === '') return false;

        $row = DB::table('gates_org_payouts')->where('reference', $reference)->first();
        if (!$row) return false;

        if (in_array((string) $row->status, self::TERMINAL, true)) {
            return false;   // already settled; a later event cannot unsettle it
        }

        $update = [
            'status'          => $status,
            'gateway_message' => $message !== '' ? mb_substr($message, 0, 250) : ($row->gateway_message ?? null),
            'last_checked_at' => date('Y-m-d H:i:s'),
        ];
        foreach (['transfer_code', 'gateway_transfer_id'] as $k) {
            if (!empty($extra[$k])) $update[$k] = $extra[$k];
        }
        if ($status === self::ST_SUCCESS) {
            $update['settled_at'] = date('Y-m-d H:i:s');
        }

        DB::table('gates_org_payouts')->where('reference', $reference)->update($update);
        return true;
    }

    private static function markAttempt(string $reference, string $message): void
    {
        try {
            DB::table('gates_org_payouts')->where('reference', $reference)->update([
                'attempts'        => DB::raw('attempts + 1'),
                'gateway_message' => mb_substr($message, 0, 250),
                'last_checked_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Bookkeeping. Never worth failing a payout over.
        }
    }

    /**
     * The reconciliation backstop.
     *
     * Webhooks are the primary signal and they are not a guarantee: Paystack retries a failed
     * endpoint every three minutes and then hourly for up to 72 hours and THEN STOPS, and its
     * own status history records at least one incident of degraded webhook delivery. A system
     * whose only knowledge of where money went arrives by webhook is a system that is one bad
     * afternoon away from a charity's payout being lost in an unknown state.
     *
     * So this asks. Only about payouts that are not terminal, oldest checked first, and only
     * ones old enough that asking is not a race against the transfer being created — Verify
     * Transfer returns an error for a transfer that does not exist yet, which reads exactly
     * like a failure.
     *
     * @return array{checked:int,changed:int}
     */
    public static function sweep(PaymentService $payments, int $limit = 25): array
    {
        $checked = 0;
        $changed = 0;

        try {
            $rows = DB::table('gates_org_payouts')
                ->whereNotIn('status', self::TERMINAL)
                ->where('status', '!=', self::ST_QUEUED)
                ->where('requested_at', '<=', date('Y-m-d H:i:s', time() - 120))
                ->orderByRaw('last_checked_at IS NULL DESC')
                ->orderBy('last_checked_at')
                ->limit(max(1, min(100, $limit)))
                ->get();
        } catch (\Throwable) {
            return ['checked' => 0, 'changed' => 0];
        }

        foreach ($rows as $row) {
            $checked++;
            $v = $payments->verifyTransfer((string) $row->reference);

            if (!$v['ok']) {
                // Unreachable is not a verdict. Touch the clock so the queue rotates and this
                // row is not asked about again immediately, but change nothing else.
                try {
                    DB::table('gates_org_payouts')->where('id', $row->id)
                        ->update(['last_checked_at' => date('Y-m-d H:i:s')]);
                } catch (\Throwable) {
                }
                continue;
            }
            if (self::applyStatus((string) $row->reference, $v['status'], 'Resolved by reconciliation sweep.')) {
                $changed++;
            }
        }

        return ['checked' => $checked, 'changed' => $changed];
    }
}
