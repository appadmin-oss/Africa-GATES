<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A standing monthly gift — the arrangement, not the charge.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HARD PART IS THE SECOND MONTH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The first instalment is an ordinary checkout: our reference, our donation row, the
 * existing confirmation path. Everything after it is not. Paystack bills on its own clock
 * and each charge arrives as a webhook carrying a reference PAYSTACK generated — which
 * matches no row here, which the existing handler correctly logs as `unmatched` and
 * acknowledges.
 *
 * So a recurring donation built without this class works perfectly for one month and then
 * becomes invisible: the money keeps arriving in the bank, the donor keeps being charged,
 * the platform's own total stops moving, and nobody notices until somebody reconciles a
 * statement. That is the failure this file exists to prevent, and it is why
 * {@see chargeArrived()} MINTS a donation row rather than looking one up.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * STATUS MOVES ON WEBHOOKS, NEVER ON OUR OWN GUESS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A subscription this platform believes is live and the gateway has already stopped is the
 * shape of fault that reaches a person as "you charged me after I cancelled" — or worse,
 * as a receipt for a month nobody was charged for. So `pending` becomes `active` when the
 * gateway says a subscription exists, and `cancelled` when the gateway says it is disabled.
 * {@see markCancelling()} is the one exception, and it is deliberately a different word: it
 * records that WE asked, and waits for the gateway to confirm.
 */
final class RecurringGiving
{
    public const ST_PENDING   = 'pending';    // checkout started, gateway has not confirmed
    public const ST_ACTIVE    = 'active';     // the gateway says this is billing
    public const ST_CANCELLING = 'cancelling';// we asked to stop; the gateway has not said so yet
    public const ST_CANCELLED = 'cancelled';  // the gateway says it is stopped
    public const ST_FAILED    = 'failed';     // the gateway could not collect

    /** The intervals offered. Monthly only, because it is the only one anybody asked for. */
    public const INTERVAL = 'monthly';

    /** Is recurring giving available at all on this deployment? */
    public static function available(?PaymentService $payments = null): bool
    {
        // Paystack ONLY. Flutterwave has payment plans of a different shape and this has not
        // been built against them — and an option that silently falls back to a one-off gift
        // is worse than no option, because the donor believes they have set something up.
        return ($payments ?? new PaymentService())->isEnabled('paystack');
    }

    /**
     * The gateway plan code for an amount, created once and remembered.
     *
     * Paystack will make a second plan for the same amount every time it is asked, and each
     * one bills independently — so without this mapping a month of checkouts leaves a spread
     * of near-identical plans and no way to answer "how many people give ₦5,000 a month".
     *
     * @return array{ok:bool, code:string, message:string}
     */
    public static function planFor(int $amountNaira, PaymentService $payments,
                                   string $interval = self::INTERVAL): array
    {
        $amountNaira = max(0, $amountNaira);
        if ($amountNaira < 1) return ['ok' => false, 'code' => '', 'message' => 'Invalid amount.'];

        try {
            $existing = DB::table('gates_donation_plans')
                ->where('provider', 'paystack')->where('amount_naira', $amountNaira)
                ->where('interval_name', $interval)->value('plan_code');
            if (is_string($existing) && trim($existing) !== '') {
                return ['ok' => true, 'code' => trim($existing), 'message' => ''];
            }
        } catch (\Throwable) {
            // No table on this deployment yet. Falling through creates the plan at the
            // gateway and fails to remember it, which bills the donor correctly and leaves a
            // duplicate for somebody to tidy — the right way round for money.
        }

        $r = $payments->createPlan($amountNaira, $interval);
        if (!$r['ok']) return $r;

        try {
            DB::table('gates_donation_plans')->insert([
                'provider' => 'paystack', 'amount_naira' => $amountNaira,
                'interval_name' => $interval, 'plan_code' => $r['code'],
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) { /* a race lost the unique index; the code above is still valid */ }

        return $r;
    }

    /**
     * Record the intention, before the donor leaves for the gateway.
     *
     * Written at checkout START rather than on the way back, for the same reason the
     * donation row is: a donor who pays inside a wallet app and never returns must not leave
     * a subscription that exists at Paystack and nowhere here.
     */
    public static function start(string $email, string $name, int $amountNaira,
                                 string $planCode, string $reference): int
    {
        try {
            return (int) DB::table('gates_donation_subscriptions')->insertGetId([
                'provider' => 'paystack', 'donor_email' => strtolower(trim($email)),
                'donor_name' => trim($name), 'amount_naira' => $amountNaira,
                'interval_name' => self::INTERVAL, 'plan_code' => $planCode,
                'status' => self::ST_PENDING, 'first_ref' => $reference,
                // The donor's own stop link, minted here so it can travel in the receipt.
                // Same shape as the shop's back-in-stock unsubscribe: a stored random token,
                // not a signature — stored means it can be withdrawn, and an HMAC over an
                // email would stay valid for as long as the app key does.
                'manage_token' => bin2hex(random_bytes(16)),
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The gateway says a subscription now exists — `subscription.create`.
     *
     * Matched on the FIRST REFERENCE where the payload carries it, and on the email and
     * amount otherwise. Paystack's `subscription.create` does not always carry our
     * reference, and matching on email alone would attach the confirmation to whichever
     * arrangement that person set up first.
     */
    public static function activate(string $email, int $amountNaira, string $subscriptionCode,
                                    string $emailToken, string $customerCode = '',
                                    string $nextChargeAt = '', string $firstRef = ''): bool
    {
        $q = DB::table('gates_donation_subscriptions')
            ->whereIn('status', [self::ST_PENDING, self::ST_ACTIVE]);

        if (trim($firstRef) !== '') {
            $q->where('first_ref', trim($firstRef));
        } else {
            $q->where('donor_email', strtolower(trim($email)))
              ->where('amount_naira', $amountNaira);
        }

        // The most recent matching intention. An email that set up two gifts a year apart
        // has two rows, and the one being confirmed now is the newer.
        $row = $q->orderByDesc('id')->first();
        if (!$row) return false;

        DB::table('gates_donation_subscriptions')->where('id', (int) $row->id)->update([
            'status'            => self::ST_ACTIVE,
            'subscription_code' => trim($subscriptionCode),
            'email_token'       => trim($emailToken),
            'customer_code'     => trim($customerCode),
            'next_charge_at'    => $nextChargeAt !== '' ? $nextChargeAt : null,
        ]);

        return true;
    }

    /**
     * A recurring charge landed — mint the donation row it belongs to.
     *
     * THE SECOND MONTH, which is the whole point of this class. The reference is Paystack's
     * own and matches nothing here; without this the money arrives in the bank and nowhere
     * else. Returns the donation id, or 0 when this was not ours or was already recorded.
     *
     * IDEMPOTENT on the gateway reference: Paystack retries a delivery every three minutes
     * and then hourly for 72 hours, and a handler that minted a row per delivery would turn
     * one ₦5,000 gift into a day of them.
     */
    public static function chargeArrived(string $planCode, string $email, string $reference,
                                         int $amountNaira, string $when = '',
                                         string $subscriptionCode = ''): int
    {
        $ref = trim($reference);
        if ($ref === '' || $amountNaira < 1) return 0;

        // ── MATCHED ON WHAT THE PAYLOAD ACTUALLY CARRIES ─────────────────────
        //
        // Paystack's `charge.success` for a subscription instalment carries the PLAN object
        // and the customer, and does NOT carry a subscription code — that one appears on the
        // invoice events. Resolving on a subscription code alone would find nothing on every
        // real recurring charge and fall through to "unmatched", which is precisely the
        // failure this method exists to prevent. So the plan and the payer are the key, and
        // the subscription code is used when an invoice event supplies it.
        $q = DB::table('gates_donation_subscriptions');
        if (trim($subscriptionCode) !== '') {
            $q->where('subscription_code', trim($subscriptionCode));
        } elseif (trim($planCode) !== '' && trim($email) !== '') {
            $q->where('plan_code', trim($planCode))
              ->where('donor_email', strtolower(trim($email)));
        } else {
            return 0;
        }

        // The most recent. Somebody who stopped a ₦5,000 monthly gift and started another
        // has two rows on the same plan, and the charge belongs to the newer.
        $sub = $q->orderByDesc('id')->first();
        if (!$sub) return 0;

        // Already recorded — a retry, or the same delivery twice.
        if (DB::table('gates_donations')->where('payment_ref', $ref)->exists()) return 0;

        $now = $when !== '' ? $when : Carbon::now()->toDateTimeString();

        $id = (int) DB::table('gates_donations')->insertGetId([
            'donor_name'   => (string) ($sub->donor_name ?? ''),
            'donor_email'  => (string) ($sub->donor_email ?? ''),
            'amount_naira' => $amountNaira,
            // CONFIRMED, and this is the one place on the platform where that is written
            // without a server-to-server verify first. The signature on the delivery is the
            // proof: this row is minted only from a webhook that has already passed HMAC
            // verification in PaymentController, and there is no browser callback for a
            // charge the donor was not present for.
            'status'       => 'confirmed',
            'provider'     => 'paystack',
            'payment_ref'  => $ref,
            'confirmed_at' => $now,
            'created_at'   => $now,
            'subscription_id' => (int) $sub->id,
            // No bonus votes on a recurring instalment. Vote packs are a separate product
            // with their own reference prefix; minting votes here would let a standing order
            // accumulate influence every month without anybody choosing it.
            'bonus_votes'  => 0,
        ]);

        DB::table('gates_donation_subscriptions')->where('id', (int) $sub->id)->update([
            'status'         => self::ST_ACTIVE,
            'charges'        => (int) ($sub->charges ?? 0) + 1,
            'last_charge_at' => $now,
        ]);

        return $id;
    }

    /** We asked the gateway to stop. Not `cancelled` — the gateway has not said so yet. */
    public static function markCancelling(int $id): void
    {
        try {
            DB::table('gates_donation_subscriptions')->where('id', $id)
                ->update(['status' => self::ST_CANCELLING]);
        } catch (\Throwable) {}
    }

    /** The gateway says it is stopped — `subscription.disable` / `subscription.not_renew`. */
    public static function cancelled(string $subscriptionCode): bool
    {
        $code = trim($subscriptionCode);
        if ($code === '') return false;

        try {
            return DB::table('gates_donation_subscriptions')
                ->where('subscription_code', $code)
                ->whereNotIn('status', [self::ST_CANCELLED])
                ->update(['status' => self::ST_CANCELLED,
                          'cancelled_at' => Carbon::now()->toDateTimeString()]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The gateway could not collect — `invoice.payment_failed`. */
    public static function collectionFailed(string $subscriptionCode): bool
    {
        $code = trim($subscriptionCode);
        if ($code === '') return false;

        try {
            // Only from ACTIVE. A failure arriving after a cancellation must not reopen a
            // subscription the donor has already stopped.
            return DB::table('gates_donation_subscriptions')
                ->where('subscription_code', $code)->where('status', self::ST_ACTIVE)
                ->update(['status' => self::ST_FAILED]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Everything still billing for one donor. For the admin view and for tests. */
    public static function activeFor(string $email): array
    {
        try {
            return DB::table('gates_donation_subscriptions')
                ->where('donor_email', strtolower(trim($email)))
                ->whereIn('status', [self::ST_ACTIVE, self::ST_PENDING, self::ST_CANCELLING])
                ->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * One standing gift, by the token in its receipt.
     *
     * The stop link is per GIFT rather than per donor, deliberately: a receipt is for one
     * arrangement, and a link that also exposes somebody's other gifts turns a forwarded
     * email into a disclosure. It returns a cancelled one too — somebody following an old
     * link deserves "this is already stopped" rather than a 404 that reads as a fault.
     */
    public static function byToken(string $token): ?array
    {
        $t = trim($token);
        if (!preg_match('/^[a-f0-9]{32}$/', $t)) return null;

        try {
            $row = DB::table('gates_donation_subscriptions')->where('manage_token', $t)->first();
        } catch (\Throwable) {
            return null;
        }

        return $row ? (array) $row : null;
    }

    /** The donor's own stop link for one gift, absolute, for a receipt. */
    public static function manageUrl(string $base, string $token): string
    {
        return rtrim($base, '/') . '/donate/giving/' . rawurlencode($token);
    }
}
