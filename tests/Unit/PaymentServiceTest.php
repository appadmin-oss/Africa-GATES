<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Services\PaymentService;

/**
 * Provider gating: a gateway is usable ONLY when its SECRET key is configured, so
 * the UI never offers a provider that can't transact and secret keys gate the
 * whole flow. These tests exercise the env-driven enable logic and the early-exit
 * guards in initialize()/verify() — no network is touched.
 */
class PaymentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean slate every test — these env keys drive enablement.
        foreach ([
            'PAYSTACK_SECRET_KEY','PAYSTACK_PUBLIC_KEY',
            'FLUTTERWAVE_SECRET_KEY','FLUTTERWAVE_PUBLIC_KEY',
        ] as $k) { unset($_ENV[$k]); }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function test_no_keys_means_no_enabled_providers(): void
    {
        $svc = new PaymentService();
        $this->assertSame([], $svc->enabledProviders());
        $this->assertSame([], $svc->enabledProviderIds());
        $this->assertFalse($svc->isEnabled('paystack'));
        $this->assertFalse($svc->isEnabled('flutterwave'));
    }

    public function test_public_key_alone_does_not_enable_provider(): void
    {
        // Only the SECRET key gates enablement — a publishable key is not enough.
        $_ENV['PAYSTACK_PUBLIC_KEY'] = 'pk_test_x';
        $svc = new PaymentService();
        $this->assertFalse($svc->isEnabled('paystack'));
        $this->assertSame([], $svc->enabledProviderIds());
    }

    public function test_secret_key_enables_only_that_provider(): void
    {
        $_ENV['PAYSTACK_SECRET_KEY'] = 'sk_test_x';
        $svc = new PaymentService();

        $this->assertTrue($svc->isEnabled('paystack'));
        $this->assertFalse($svc->isEnabled('flutterwave'));
        $this->assertSame(['paystack'], $svc->enabledProviderIds());

        $providers = $svc->enabledProviders();
        $this->assertCount(1, $providers);
        $this->assertSame('paystack', $providers[0]['id']);
        $this->assertSame('Paystack', $providers[0]['label']);
        $this->assertArrayHasKey('public_key', $providers[0]);
    }

    public function test_both_providers_enabled_when_both_secrets_present(): void
    {
        $_ENV['PAYSTACK_SECRET_KEY']    = 'sk_test_x';
        $_ENV['FLUTTERWAVE_SECRET_KEY'] = 'FLWSECK_TEST-x';
        $svc = new PaymentService();

        $this->assertEqualsCanonicalizing(['paystack','flutterwave'], $svc->enabledProviderIds());
    }

    public function test_unknown_provider_is_never_enabled(): void
    {
        $_ENV['PAYSTACK_SECRET_KEY'] = 'sk_test_x';
        $svc = new PaymentService();
        $this->assertFalse($svc->isKnownProvider('gtpay'));
        $this->assertFalse($svc->isEnabled('gtpay'));
    }

    public function test_initialize_short_circuits_when_provider_disabled(): void
    {
        // No keys set → initialize must fail fast WITHOUT any network call.
        $svc = new PaymentService();
        $r = $svc->initialize('paystack', 1000, 'a@b.io', 'AFG-1', 'https://x/cb');
        $this->assertFalse($r['ok']);
        $this->assertNull($r['checkout_url']);
    }

    public function test_verify_short_circuits_when_provider_disabled(): void
    {
        $svc = new PaymentService();
        $r = $svc->verify('flutterwave', 'AFG-1');
        $this->assertFalse($r['ok']);
        $this->assertSame('pending', $r['status']);
        $this->assertSame(0, $r['amount']);
    }

    public function test_blank_secret_is_treated_as_unset(): void
    {
        $_ENV['PAYSTACK_SECRET_KEY'] = '   '; // whitespace only
        $svc = new PaymentService();
        $this->assertFalse($svc->isEnabled('paystack'));
    }

    // ── "the gateway said no" is two different events ────────────────────────

    private function classify(int $http, string $message): ?bool
    {
        $m = new \ReflectionMethod(PaymentService::class, 'classifyRefusal');
        $m->setAccessible(true);
        return $m->invoke(null, $http, $message);
    }

    /**
     * The refusals that fix themselves, which is most of them here.
     *
     * Neither gateway returns a machine-readable code on the refund endpoint, so
     * this reads their prose — and the alternative, treating every refusal
     * identically, is what produced a day and a half of pointless retries on a
     * revoked API key while nobody was told.
     */
    public function test_a_refusal_the_clock_will_fix_is_marked_retryable(): void
    {
        foreach ([
            'Insufficient balance in your Paystack balance',
            'Your settlement balance is too low to process this refund',
            'Service temporarily unavailable, please try again',
            'Request is being processed, try again later',
        ] as $msg) {
            $this->assertTrue($this->classify(400, $msg), $msg);
        }

        // The gateway's own admission that this was capacity, not the request.
        $this->assertTrue($this->classify(503, 'Bad gateway'));
        $this->assertTrue($this->classify(429, 'Too many requests'));
    }

    /** And the ones no amount of waiting will change. */
    public function test_a_refusal_that_can_never_succeed_is_marked_permanent(): void
    {
        foreach ([
            'Transaction not found',
            'Invalid key',
            'You do not have permission to perform this action',
            'Transaction is too old to be refunded',
            'Refund amount exceeds the transaction amount',
        ] as $msg) {
            $this->assertFalse($this->classify(400, $msg), $msg);
        }
    }

    /**
     * THE PAIR THAT MUST NOT BE CONFUSED.
     *
     * "Insufficient permission" contains the word "insufficient". Matching the
     * transient list first would read a revoked credential as a low balance and
     * retry it for thirty-one hours — which is the exact failure this whole
     * classification exists to prevent. Permanent phrases are therefore tested
     * BEFORE transient ones, and this is the test that pins that ordering.
     */
    public function test_insufficient_permission_is_not_an_insufficient_balance(): void
    {
        $this->assertFalse($this->classify(403, 'Insufficient permission for this operation'));
        $this->assertTrue($this->classify(400, 'Insufficient funds in settlement account'));
    }

    /**
     * And wording we do not recognise says so, rather than guessing.
     *
     * A gateway renaming an error must never silently become an infinite retry
     * loop OR a silently abandoned refund. Null is routed to a short, bounded
     * schedule by RefundService — see the tests there.
     */
    public function test_an_unrecognised_refusal_is_not_guessed_at(): void
    {
        $this->assertNull($this->classify(400, 'Refund was refused.'));
        $this->assertNull($this->classify(400, 'ERR_9042'));
        $this->assertNull($this->classify(400, ''));
    }
}
