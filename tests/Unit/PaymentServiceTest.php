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
}
