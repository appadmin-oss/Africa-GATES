<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CheckoutMailer;
use AfricaGates\Services\GatewayHandoff;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\RefundService;
use PHPUnit\Framework\TestCase;

/**
 * The clocks a payment runs against, and which of them are allowed to disagree.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A paid vote is judged by at least five separate timers, written at different
 * times by people solving different problems, and every one of them can declare
 * the payment over:
 *
 *   the checkout cutoff      stop selling before the ballot closes
 *   the in-flight tolerance  how long a bank may still be moving the money
 *   the abandoned nudge      when to email "you left this behind"
 *   the reconciler tombstone when to give up on a pending row entirely
 *   the refund grace         how long after confirming a mint may still be late
 *
 * Nothing connected them. They were four different numbers in four files, and the
 * abandoned nudge had drifted to 45 minutes while everything else assumed two
 * hours — so the platform emailed people mid-payment to say they had not paid.
 *
 * These are not tests of behaviour. They are tests of the RELATIONSHIPS, so that
 * lowering one number in one file fails here instead of quietly telling a
 * supporter their money did not arrive.
 */
final class PaymentWindowsTest extends TestCase
{
    /**
     * NOTHING MAY CALL A PAYMENT DEAD WHILE IT IS STILL MOVING.
     *
     * Not every payment is a card. A bank transfer settles when the bank feels
     * like it, USSD is a person on a feature phone, a wallet app switch can
     * strand a buyer on a network that drops. Thirty to ninety minutes is
     * ordinary. Any timer that ends a checkout has to sit outside that.
     */
    public function test_nothing_declares_a_checkout_abandoned_while_it_may_still_be_settling(): void
    {
        $inFlight = PaymentService::IN_FLIGHT_MINUTES;

        $this->assertGreaterThanOrEqual($inFlight, CheckoutMailer::GRACE_MINUTES,
            'the "you left this behind" email must not reach somebody mid-payment');

        // The reconciler's tombstone is in days and must clear the same bar by a
        // wide margin — it ends the row, not just the conversation.
        $expireMinutes = $this->constant(\AfricaGates\Services\PaymentReconciler::class, 'EXPIRE_AFTER_DAYS') * 24 * 60;
        $this->assertGreaterThan($inFlight, $expireMinutes,
            'a pending row must never be tombstoned inside the settlement window');
    }

    /**
     * THE REFUND GRACE IS DOUBLE THE HOUR IT USED TO BE.
     *
     * The hour was chosen when the only late mint anybody imagined was a webhook
     * beating a browser callback — seconds. The real population is slower: a cycle
     * an admin is part-way through extending, a reconciliation sweep that has not
     * run because web-cron ticks on traffic and it is 04:00.
     *
     * The asymmetry is what decides it. Waiting an extra hour costs a buyer who is
     * owed money an hour — and they are told either way. Refunding too early costs
     * a buyer their money back AND their votes gone, on an order that was about to
     * be delivered. Those are not the same mistake, so the window goes where the
     * cheaper one is.
     */
    public function test_the_refund_grace_is_two_hours(): void
    {
        $this->assertSame(120, $this->constant(RefundService::class, 'GRACE_MINUTES'));
    }

    /**
     * AND THE ONE THAT MUST NOT BE DRAGGED ALONG WITH THEM.
     *
     * `GatewayHandoff` stores a live checkout URL in the session. That URL is a
     * BEARER CAPABILITY for a payment session — anyone holding it can open the
     * buyer's checkout — so its lifetime answers a security question, not a
     * patience one.
     *
     * The temptation, every single time the platform gets more tolerant of slow
     * banks, is to widen this too "for consistency". It is the opposite of
     * consistent: tolerance is about how long we WAIT for a gateway, and this is
     * about how long a credential may sit in a session. The handoff exists to
     * survive one redirect. It should still be measured in minutes when the rest
     * of the system is measured in hours, and this test is here to make widening
     * it a deliberate act rather than a tidy-up.
     */
    public function test_the_handoff_capability_stays_short_however_patient_the_rest_becomes(): void
    {
        $ttlMinutes = $this->constant(GatewayHandoff::class, 'TTL') / 60;

        $this->assertLessThanOrEqual(30, $ttlMinutes,
            'a stored checkout URL is a bearer capability, not a tolerance setting');
        $this->assertLessThan(PaymentService::IN_FLIGHT_MINUTES, $ttlMinutes,
            'it must NOT track the in-flight window — different question, different answer');
    }

    /**
     * A payment refused at the checkout cutoff must still be one the platform
     * could have honoured had it been taken — otherwise the cutoff is theatre.
     *
     * The cutoff stops sales shortly before the bell; the late-delivery grace
     * keeps minting for hours after it. So the grace has to be comfortably longer
     * than the tolerance, or an order accepted just inside the cutoff could
     * legitimately still be settling when the mint window shuts on it.
     */
    public function test_a_payment_taken_at_the_cutoff_can_still_be_delivered(): void
    {
        $graceMinutes = \AfricaGates\Services\PaidVoteService::lateMintGraceHours() * 60;

        $this->assertGreaterThan(PaymentService::IN_FLIGHT_MINUTES, $graceMinutes,
            'the late-delivery window must outlast the slowest honest payment, or the '
            . 'platform is taking money it has already decided it cannot honour');
    }

    /** Read a private constant without making it public just to be testable. */
    private function constant(string $class, string $name): int
    {
        return (int) (new \ReflectionClass($class))->getConstant($name);
    }
}
