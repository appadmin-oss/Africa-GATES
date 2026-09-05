<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\RecurringGiving as RG;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * MONTHLY GIVING, AND THE MONTH THAT USED NOT TO EXIST.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FIRST INSTALMENT IS EASY AND IS NOT THE FEATURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Month one is an ordinary checkout: our reference, our donation row, the confirmation path
 * that already exists. Everything after it is not. Paystack bills on its own clock and each
 * charge arrives as a webhook carrying a reference PAYSTACK generated — which matches no row
 * here, which the existing handler correctly logs as `unmatched` and acknowledges with a
 * 200.
 *
 * So a recurring gift built without this works perfectly for one month and then goes dark:
 * the money keeps arriving in the bank, the donor keeps being charged, the platform's total
 * stops moving, and nobody finds out until somebody reconciles a statement.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE PART THAT IS EASY TO GET WRONG IN THE OTHER DIRECTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Paystack retries a delivery every three minutes and then hourly for 72 hours. A handler
 * that minted a donation row per delivery would turn one ₦5,000 gift into a day of them —
 * on the one table this platform is trusted to be exact about.
 */
final class RecurringGivingTest extends TestCase
{
    private const PLAN  = 'PLN_month5k';
    private const EMAIL = 'amara@example.test';

    private function arrangement(int $amount = 5000, string $ref = 'AFG-DON-1'): int
    {
        $id = RG::start(self::EMAIL, 'Amara Okonkwo', $amount, self::PLAN, $ref);
        $this->assertGreaterThan(0, $id, 'the intention was not recorded before checkout');
        return $id;
    }

    // ══ the arrangement ══════════════════════════════════════════════════════

    /**
     * Written BEFORE the donor leaves for the gateway.
     *
     * Somebody who pays inside a wallet app and never comes back must not leave a
     * subscription that exists at Paystack and nowhere here — that is a charge every month
     * against a record this platform cannot see, explain, or stop.
     */
    public function test_the_intention_is_recorded_before_the_gateway_is_called(): void
    {
        $id  = $this->arrangement();
        $row = DB::table('gates_donation_subscriptions')->find($id);

        $this->assertSame(RG::ST_PENDING, (string) $row->status,
            'a subscription was called active before the gateway said it existed');
        $this->assertSame(self::PLAN, (string) $row->plan_code);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row->manage_token,
            'no stop link was minted, so the receipt has nothing to offer');
    }

    /** The gateway confirming it is what makes it active — and hands over the stop keys. */
    public function test_the_gateway_confirming_makes_it_active(): void
    {
        $id = $this->arrangement();

        $this->assertTrue(RG::activate(self::EMAIL, 5000, 'SUB_a', 'tok_a', 'CUS_1',
                                       '2026-10-04 09:00:00', 'AFG-DON-1'));

        $row = DB::table('gates_donation_subscriptions')->find($id);
        $this->assertSame(RG::ST_ACTIVE, (string) $row->status);
        // Both, and only both: Paystack requires the pair together to stop a subscription,
        // so a row holding one of them is a donor who cannot cancel.
        $this->assertSame('SUB_a', (string) $row->subscription_code);
        $this->assertSame('tok_a', (string) $row->email_token);
    }

    // ══ the second month ═════════════════════════════════════════════════════

    /**
     * THE WHOLE POINT. A recurring charge is matched by PLAN and PAYER, not by our
     * reference, and it MINTS the donation row rather than looking one up.
     *
     * Keyed that way because that is what the payload carries: Paystack's `charge.success`
     * for an instalment has the plan object and the customer on it and leaves the
     * subscription code to the invoice events. Resolving on a subscription code alone finds
     * nothing on every real recurring charge — which is the original bug wearing a fix.
     */
    public function test_a_recurring_charge_becomes_a_donation_row(): void
    {
        $this->arrangement();
        RG::activate(self::EMAIL, 5000, 'SUB_a', 'tok_a', 'CUS_1', '', 'AFG-DON-1');

        $before = DB::table('gates_donations')->count();
        $id = RG::chargeArrived(self::PLAN, self::EMAIL, 'psk_generated_ref', 5000, '2026-10-04 09:00:05');

        $this->assertGreaterThan(0, $id, 'the second month never reached the database');
        $this->assertSame($before + 1, DB::table('gates_donations')->count());

        $d = DB::table('gates_donations')->find($id);
        $this->assertSame('confirmed', (string) $d->status);
        $this->assertSame(5000, (int) $d->amount_naira);
        $this->assertSame(self::EMAIL, (string) $d->donor_email);
        // Tied to the arrangement, or "how much has this supporter given" cannot be answered.
        $this->assertGreaterThan(0, (int) $d->subscription_id);
        // No bonus votes: vote packs are a separate product, and a standing order must not
        // quietly accumulate influence every month.
        $this->assertSame(0, (int) $d->bonus_votes);
    }

    /**
     * IDEMPOTENT on the gateway's reference.
     *
     * Paystack retries every three minutes, then hourly for 72 hours. A row per delivery
     * turns one gift into a day of them, on the table the platform's published total is
     * summed from.
     */
    public function test_a_retried_delivery_does_not_mint_a_second_gift(): void
    {
        $this->arrangement();
        RG::activate(self::EMAIL, 5000, 'SUB_a', 'tok_a', 'CUS_1', '', 'AFG-DON-1');

        $first = RG::chargeArrived(self::PLAN, self::EMAIL, 'psk_ref', 5000);
        $again = RG::chargeArrived(self::PLAN, self::EMAIL, 'psk_ref', 5000);
        $third = RG::chargeArrived(self::PLAN, self::EMAIL, 'psk_ref', 5000);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $again, 'a retry minted a second donation');
        $this->assertSame(0, $third);
        $this->assertSame(1, DB::table('gates_donations')->where('payment_ref', 'psk_ref')->count());

        // And the count on the arrangement moved once, not three times.
        $this->assertSame(1, (int) DB::table('gates_donation_subscriptions')
            ->where('subscription_code', 'SUB_a')->value('charges'));
    }

    /** A charge on a plan nobody here arranged is not ours to record. */
    public function test_a_charge_on_an_unknown_plan_is_ignored(): void
    {
        $before = DB::table('gates_donations')->count();

        $this->assertSame(0, RG::chargeArrived('PLN_someone_else', self::EMAIL, 'psk_x', 5000));
        $this->assertSame(0, RG::chargeArrived(self::PLAN, 'stranger@example.test', 'psk_y', 5000));
        $this->assertSame($before, DB::table('gates_donations')->count());
    }

    // ══ stopping ═════════════════════════════════════════════════════════════

    /**
     * We asked is not the gateway agreed.
     *
     * A subscription this platform believes is stopped while Paystack is still billing
     * reaches a person as "you charged me after I cancelled". `cancelling` records that we
     * asked; only the webhook makes it `cancelled`.
     */
    public function test_asking_to_stop_is_not_the_same_as_being_stopped(): void
    {
        $id = $this->arrangement();
        RG::activate(self::EMAIL, 5000, 'SUB_a', 'tok_a', 'CUS_1', '', 'AFG-DON-1');

        RG::markCancelling($id);
        $this->assertSame(RG::ST_CANCELLING,
            (string) DB::table('gates_donation_subscriptions')->find($id)->status);

        $this->assertTrue(RG::cancelled('SUB_a'));
        $row = DB::table('gates_donation_subscriptions')->find($id);
        $this->assertSame(RG::ST_CANCELLED, (string) $row->status);
        $this->assertNotNull($row->cancelled_at);
    }

    /**
     * A collection failure must never reopen a gift somebody has already stopped.
     *
     * Paystack can deliver an `invoice.payment_failed` for a cycle that was already in
     * flight when the donor cancelled. Moving that row back to `failed` would put a stopped
     * arrangement back on a screen as something to chase.
     */
    public function test_a_failure_after_a_cancellation_does_not_reopen_it(): void
    {
        $this->arrangement();
        RG::activate(self::EMAIL, 5000, 'SUB_a', 'tok_a', 'CUS_1', '', 'AFG-DON-1');

        $this->assertTrue(RG::collectionFailed('SUB_a'));
        $this->assertSame(RG::ST_FAILED, (string) DB::table('gates_donation_subscriptions')
            ->where('subscription_code', 'SUB_a')->value('status'));

        RG::cancelled('SUB_a');
        $this->assertFalse(RG::collectionFailed('SUB_a'),
            'a late failure reopened a cancelled subscription');
        $this->assertSame(RG::ST_CANCELLED, (string) DB::table('gates_donation_subscriptions')
            ->where('subscription_code', 'SUB_a')->value('status'));
    }

    // ══ the donor's link ═════════════════════════════════════════════════════

    /**
     * The stop link is per GIFT, and resolves only on a well-formed token.
     *
     * Per gift because a receipt is for one arrangement — a link that also listed somebody's
     * other gifts would turn a forwarded email into a disclosure.
     */
    public function test_the_stop_link_resolves_one_gift_and_refuses_anything_else(): void
    {
        $id  = $this->arrangement();
        $tok = (string) DB::table('gates_donation_subscriptions')->find($id)->manage_token;

        $this->assertSame($id, (int) RG::byToken($tok)['id']);
        $this->assertNull(RG::byToken(''));
        $this->assertNull(RG::byToken('not-a-token'));
        $this->assertNull(RG::byToken(str_repeat('a', 32)), 'an unknown token resolved to a gift');
    }

    /**
     * A cancelled gift still resolves.
     *
     * Somebody following an old link deserves "this is already stopped" rather than a 404,
     * which reads as a fault and sends a person who is trying to stop paying us to their
     * bank instead.
     */
    public function test_an_already_stopped_gift_still_answers(): void
    {
        $id  = $this->arrangement();
        RG::activate(self::EMAIL, 5000, 'SUB_a', 'tok_a', 'CUS_1', '', 'AFG-DON-1');
        $tok = (string) DB::table('gates_donation_subscriptions')->find($id)->manage_token;
        RG::cancelled('SUB_a');

        $sub = RG::byToken($tok);
        $this->assertNotNull($sub);
        $this->assertSame(RG::ST_CANCELLED, (string) $sub['status']);
    }
}
