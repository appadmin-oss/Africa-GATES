<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaymentService;
use PHPUnit\Framework\TestCase;

/**
 * The provider probe asks about the key a payment would be made with.
 *
 * It read a `paystack_secret_key` setting nothing writes and, for Flutterwave, an env name
 * (`FLW_SECRET_KEY`) payments never read — so it could report "no key set" while payments
 * worked, or test a key no payment used.
 */
final class ProviderProbeKeyTest extends TestCase
{
    public function test_the_probe_and_payments_read_one_key(): void
    {
        $prev = $_ENV['FLUTTERWAVE_SECRET_KEY'] ?? null;
        $_ENV['FLUTTERWAVE_SECRET_KEY'] = 'FLWSECK_TEST-abc';
        try {
            $this->assertSame('FLWSECK_TEST-abc', PaymentService::secretKey('flutterwave'));
            $this->assertSame('', PaymentService::secretKey('nobody'));
        } finally {
            if ($prev === null) unset($_ENV['FLUTTERWAVE_SECRET_KEY']); else $_ENV['FLUTTERWAVE_SECRET_KEY'] = $prev;
        }
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ProviderProbe.php');
        $this->assertStringContainsString("PaymentService::secretKey('paystack')", $src);
        $this->assertStringContainsString("PaymentService::secretKey('flutterwave')", $src);
        $this->assertStringNotContainsString('paystack_secret_key', $src);
        $this->assertStringNotContainsString('FLW_SECRET_KEY', $src);
    }
}
