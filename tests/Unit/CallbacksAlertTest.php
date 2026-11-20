<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Services\GatewayEventLog;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "Payment callbacks have gone quiet" — owner, 13 Nov 2026: "the site sees abandonment as
 * payment not working". It fired on checkouts PENDING an hour with no gateway event since,
 * and said that many supporters had paid; on a quiet evening those are people who closed the
 * tab. It now fires on the evidence that callbacks stopped: payments CONFIRMING (by the
 * return page or the sweep) that the gateway never called back about.
 */
final class CallbacksAlertTest extends TestCase
{
    private function donation(int $i, string $status, ?string $confirmedAgo = null, string $createdAgo = '-3 hours'): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'S' . $i, 'donor_email' => 's' . $i . '@example.test', 'amount_naira' => 2000,
            'status' => $status, 'payment_ref' => 'AFG-CB-' . $i,
            'confirmed_at' => $confirmedAgo !== null ? date('Y-m-d H:i:s', strtotime($confirmedAgo)) : null,
            'created_at' => date('Y-m-d H:i:s', strtotime($createdAgo)),
        ]);
    }

    private function callbacks(): ?array
    {
        ConsoleAlerts::reset();
        foreach (ConsoleAlerts::open() as $a) if ($a['key'] === 'callbacks') return $a;
        return null;
    }

    public function test_people_who_left_the_checkout_raise_nothing(): void
    {
        DB::table('gates_donations')->truncate();
        for ($i = 0; $i < 25; $i++) $this->donation($i, 'pending');
        $this->assertNull($this->callbacks(), 'pending checkouts on a quiet night are drop-off, not missing money');
    }

    public function test_payments_confirming_without_a_callback_raise_it(): void
    {
        DB::table('gates_donations')->truncate();
        GatewayEventLog::record('paystack', 'charge.success', 'AFG-OLD', 'webhook', 'ok');
        DB::table('gates_gateway_events')->update(['created_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))]);
        $this->donation(1, 'confirmed', '-2 hours');
        $this->donation(2, 'confirmed', '-90 minutes');
        $a = $this->callbacks();
        $this->assertNotNull($a, 'two payments settled with no word from the gateway');

        // The gateway calls back again: resolved.
        GatewayEventLog::record('paystack', 'charge.success', 'AFG-CB-2', 'webhook', 'ok');
        $this->assertNull($this->callbacks());
    }
}
