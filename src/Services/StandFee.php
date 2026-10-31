<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The stand fee: what is owed, the link that reaches it, and the payment.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT ACCEPTANCE USED TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see StandApplication::accept()} flipped a column and returned "Accepted. You will be
 * invoiced for the stand fee."
 *
 * Nothing invoiced anybody. There was no amount on the row, nothing the vendor could see,
 * no way to pay, and no way for an organiser to tell a paid pitch from an unpaid one on the
 * morning of the market. The whole argument for a published price beside a published quota
 * is that the transaction is defensible — and "we will send you an invoice" is the point at
 * which a defensible allocation turns back into a WhatsApp message and a bank transfer
 * nobody can reconcile.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE DECISIONS WORTH STATING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · THE FEE IS COPIED ONTO THE ROW AT ACCEPTANCE, not read live from the stand type.
 *     A live reference would let an organiser raise a price after somebody accepted at the
 *     old one. Same reason {@see StandCall::open()} snapshots the criteria: a term that can
 *     move after you agreed to it is not a term.
 *
 * 2 · THE LINK IS A TOKEN, not a sign-in. A market trader who applied from a phone six
 *     weeks ago does not remember a password, and an offer with a two-day clock cannot
 *     afford a password-reset round trip. Same doctrine as the questionnaire link and the
 *     claim link — the token IS the credential, so it is long, random, unique, and it
 *     reaches ONE application.
 *
 * 3 · ACCEPTANCE IS NOT A GET. The emailed link opens a page that SHOWS the offer; accepting
 *     and paying are posts from that page. A one-click accept URL would be a state change
 *     from a GET, and a mail scanner prefetching it would accept a pitch on somebody's
 *     behalf — which is not hypothetical, it is what corporate mail filters do all day.
 */
final class StandFee
{
    /**
     * Stamp what is owed, and mint the link that reaches it.
     *
     * Called from {@see StandApplication::offer()} — at OFFER, not at acceptance, because
     * the offer email needs both the amount and the link in it. A vendor deciding whether
     * to accept within two days needs to know what accepting costs.
     *
     * @return array{fee:int, deposit:int, token:string}
     */
    public static function stamp(int $appId): array
    {
        $app = StandApplication::find($appId);
        if (!$app) return ['fee' => 0, 'deposit' => 0, 'token' => ''];

        $type    = StandType::find((int) $app->stand_type_id);
        $fee     = (int) ($type->price_naira ?? 0);
        $deposit = (int) ($type->deposit_naira ?? 0);

        // A deposit larger than the fee is a data error, not a demand. Clamped rather than
        // refused: the offer must still go out.
        if ($deposit > $fee) $deposit = $fee;

        $token = trim((string) ($app->access_token ?? ''));
        if ($token === '') $token = bin2hex(random_bytes(24));

        try {
            DB::table('gates_stand_applications')->where('id', $appId)->update([
                'fee_naira'     => $fee,
                'deposit_naira' => $deposit,
                'access_token'  => $token,
            ]);
        } catch (\Throwable $e) {
            error_log('[stand-fee] could not stamp ' . $appId . ': ' . $e->getMessage());
            return ['fee' => $fee, 'deposit' => $deposit, 'token' => ''];
        }

        return ['fee' => $fee, 'deposit' => $deposit, 'token' => $token];
    }

    /** The application a token addresses, or null. */
    public static function byToken(string $token): ?object
    {
        $t = trim($token);
        if (!preg_match('~^[a-f0-9]{48}$~', $t)) return null;

        try {
            return DB::table('gates_stand_applications')->where('access_token', $t)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The vendor's own link to this offer. Empty when there is no token to link to. */
    public static function url(string $siteUrl, ?object $app): string
    {
        $t = trim((string) ($app->access_token ?? ''));
        return $t === '' ? '' : rtrim($siteUrl, '/') . '/stand/' . $t;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE TRADING TERMS
    // ═══════════════════════════════════════════════════════════════════════

    /** The slug of the document a vendor agrees to on acceptance. */
    public const TERMS_SLUG = 'vendor-terms';

    /**
     * A version stamp for the terms as they stand right now.
     *
     * ── WHY THE VERSION AND NOT JUST THE TIMESTAMP ──────────────────────────
     *
     * "They accepted the terms" is worth nothing if nobody can say WHICH terms. The
     * document is admin-editable — that is the point of gates_legal_docs — so a clause
     * added next month would otherwise appear to have been agreed to by everybody who ever
     * accepted a pitch. An organiser enforcing a rule the trader never saw is the same
     * failure as a rejection with no reason.
     *
     * The stamp is the document's own updated date, which is what changes when somebody
     * edits it. Not a hash of the body: a body hash changes on a typo fix and would turn
     * every past agreement into a mismatch nobody can interpret.
     */
    public static function termsVersion(): string
    {
        try {
            $doc = \AfricaGates\Services\LegalService::get(self::TERMS_SLUG);
        } catch (\Throwable) {
            $doc = null;
        }
        if (!is_array($doc)) return '';

        $at = trim((string) ($doc['updated_at'] ?? $doc['created_at'] ?? ''));
        return $at !== '' ? substr($at, 0, 10) : 'unversioned';
    }

    /** Record that this vendor agreed, and to what. */
    public static function agreeToTerms(int $appId): bool
    {
        try {
            DB::table('gates_stand_applications')->where('id', $appId)->update([
                'terms_agreed_at' => date('Y-m-d H:i:s'),
                'terms_version'   => self::termsVersion(),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[stand-fee] could not record terms agreement: ' . $e->getMessage());
            return false;
        }
    }

    public static function hasAgreed(?object $app): bool
    {
        return trim((string) ($app->terms_agreed_at ?? '')) !== '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WHAT IS OWED
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * What this vendor owes right now, and what that means.
     *
     * @return array{fee:int, deposit:int, paid:int, due:int, settled:bool, stage:string,
     *                label:string}
     */
    public static function owing(?object $app): array
    {
        $fee     = (int) ($app->fee_naira ?? 0);
        $deposit = (int) ($app->deposit_naira ?? 0);
        $paid    = (int) ($app->paid_naira ?? 0);

        if ($fee <= 0) {
            // A free pitch is a real thing — a community market, a sponsored row — and it
            // must not render as an unpaid ₦0 invoice with a Pay button on it.
            return ['fee' => 0, 'deposit' => 0, 'paid' => $paid, 'due' => 0,
                    'settled' => true, 'stage' => 'free',
                    'label' => 'There is no fee for this stand.'];
        }

        if ($paid >= $fee) {
            return ['fee' => $fee, 'deposit' => $deposit, 'paid' => $paid, 'due' => 0,
                    'settled' => true, 'stage' => 'paid',
                    'label' => 'Paid in full — ₦' . number_format($paid) . '.'];
        }

        // A deposit that has been met leaves the balance owing, and says so with both
        // numbers: "you paid X of Y" is the sentence somebody needs before a market day.
        if ($deposit > 0 && $paid >= $deposit) {
            return ['fee' => $fee, 'deposit' => $deposit, 'paid' => $paid, 'due' => $fee - $paid,
                    'settled' => false, 'stage' => 'balance',
                    'label' => 'Deposit paid. ₦' . number_format($fee - $paid)
                             . ' of ₦' . number_format($fee) . ' still to pay.'];
        }

        $dueNow = $deposit > 0 ? $deposit - $paid : $fee - $paid;

        return ['fee' => $fee, 'deposit' => $deposit, 'paid' => $paid, 'due' => $dueNow,
                'settled' => false, 'stage' => $deposit > 0 ? 'deposit' : 'full',
                'label' => $deposit > 0
                    ? '₦' . number_format($dueNow) . ' due now as a deposit, out of ₦'
                      . number_format($fee) . ' in total.'
                    : '₦' . number_format($dueNow) . ' to pay.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PAYING
    // ═══════════════════════════════════════════════════════════════════════

    /** The reference we mint and hand to the gateway. */
    public static function reference(int $appId): string
    {
        return 'AFG-STAND-' . $appId . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Record which reference this application is paying under.
     *
     * Written BEFORE the hand-off, so a callback that arrives for a reference we have never
     * heard of is a callback we can refuse rather than guess at.
     */
    public static function beginPayment(int $appId, string $reference, string $provider): bool
    {
        try {
            DB::table('gates_stand_applications')->where('id', $appId)->update([
                'payment_ref'  => $reference,
                'fee_provider' => $provider,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[stand-fee] could not begin payment: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Credit a payment the GATEWAY has confirmed.
     *
     * ── WHY THE AMOUNT COMES FROM THE VERIFICATION AND NOT FROM THE PAGE ────
     *
     * The caller must pass what the gateway said was paid, in naira. Nothing here trusts a
     * form field, a query string or a callback body: a browser returning from a checkout is
     * a party to the transaction, not a witness to it. The same rule the donation and
     * ticket paths follow, for the same reason — the alternative is a stand marked paid by
     * anybody who can edit a URL.
     *
     * Idempotent on the reference: a callback and a webhook and a reconciliation sweep may
     * all report the same payment, and crediting it three times would mark a deposit as a
     * fee paid three times over.
     *
     * @return array{ok:bool, message:string, credited:int}
     */
    public static function confirm(string $reference, int $paidNaira, string $provider = ''): array
    {
        $ref = trim($reference);
        if ($ref === '' || $paidNaira < 1) {
            return ['ok' => false, 'credited' => 0, 'message' => 'Nothing to credit.'];
        }

        try {
            $app = DB::table('gates_stand_applications')->where('payment_ref', $ref)->first();
        } catch (\Throwable) {
            return ['ok' => false, 'credited' => 0, 'message' => 'That could not be checked.'];
        }

        if (!$app) {
            return ['ok' => false, 'credited' => 0,
                    'message' => 'That payment reference does not belong to a stand.'];
        }

        // Already credited at this amount or better — the second and third reports of the
        // same payment. Success, because the caller asked for a state that already holds.
        if ((int) $app->paid_naira >= $paidNaira) {
            return ['ok' => true, 'credited' => 0, 'message' => 'Already recorded.'];
        }

        try {
            DB::table('gates_stand_applications')->where('id', $app->id)->update([
                'paid_naira'   => $paidNaira,
                'paid_at'      => date('Y-m-d H:i:s'),
                'fee_provider' => $provider !== '' ? $provider : ($app->fee_provider ?? null),
            ]);
        } catch (\Throwable $e) {
            error_log('[stand-fee] could not credit ' . $ref . ': ' . $e->getMessage());
            return ['ok' => false, 'credited' => 0, 'message' => 'That could not be recorded.'];
        }

        return ['ok' => true, 'credited' => $paidNaira, 'message' => 'Payment recorded.'];
    }

    /**
     * Every accepted stand at an event, with what each one owes.
     *
     * For the organiser's own screen: "who has paid" is the question on the morning of the
     * market, and before this it had no answer anywhere in the product.
     *
     * @return array{rows:list<array<string,mixed>>, expected:int, collected:int, owed:int}
     */
    public static function ledger(int $eventId): array
    {
        try {
            $rows = DB::table('gates_stand_applications as a')
                ->leftJoin('gates_partner_orgs as o', 'o.id', '=', 'a.org_id')
                ->leftJoin('gates_stand_types as t', 't.id', '=', 'a.stand_type_id')
                ->where('a.event_id', $eventId)
                ->whereIn('a.decision', [StandApplication::DECISION_ACCEPTED,
                                         StandApplication::DECISION_OFFERED])
                ->select('a.*', 'o.name as org_name', 't.name as type_name')
                ->orderBy('o.name')
                ->get()->all();
        } catch (\Throwable) {
            return ['rows' => [], 'expected' => 0, 'collected' => 0, 'owed' => 0];
        }

        $out = [];
        $expected = $collected = 0;

        foreach ($rows as $r) {
            $owing = self::owing($r);

            // Only ACCEPTED pitches are money anybody is owed. An open offer is a place
            // being held, and counting it as expected income would let a page report a
            // figure that evaporates when the clock runs out.
            if ((string) $r->decision === StandApplication::DECISION_ACCEPTED) {
                $expected  += $owing['fee'];
                $collected += $owing['paid'];
            }

            $out[] = [
                'app'       => $r,
                'org_name'  => (string) ($r->org_name ?? 'Unknown'),
                'type_name' => (string) ($r->type_name ?? ''),
                'owing'     => $owing,
                'accepted'  => (string) $r->decision === StandApplication::DECISION_ACCEPTED,
            ];
        }

        return ['rows' => $out, 'expected' => $expected, 'collected' => $collected,
                'owed' => max(0, $expected - $collected)];
    }
}
