<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\WebhookService;

/**
 * Dispatch logic for outbound webhooks: only ACTIVE endpoints SUBSCRIBED to the
 * event are delivered to, and every attempt is logged. Endpoints point at an
 * unreachable local port so deliveries fail fast (connection refused) — we assert
 * the routing + logging, not network success.
 */
class WebhookServiceTest extends TestCase
{
    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        DB::table('gates_webhooks')->insert([
            ['url' => 'http://127.0.0.1:1/a', 'secret' => 's1', 'events' => json_encode(['order.paid']), 'is_active' => 1, 'created_at' => $now],
            ['url' => 'http://127.0.0.1:1/b', 'secret' => 's2', 'events' => '*',                           'is_active' => 0, 'created_at' => $now], // inactive
            ['url' => 'http://127.0.0.1:1/c', 'secret' => 's3', 'events' => '*',                           'is_active' => 1, 'created_at' => $now], // all events
        ]);
    }

    public function test_dispatch_routes_to_active_subscribers_and_logs(): void
    {
        $this->seed();

        // order.paid → hook A (subscribed) + hook C (all). Hook B is inactive.
        WebhookService::dispatch('order.paid', ['ref' => 'AFG-1']);
        $this->assertSame(2, DB::table('gates_webhook_deliveries')->count());

        // event.registration → only hook C (all events); A isn't subscribed.
        WebhookService::dispatch('event.registration', ['email' => 'x@y.io']);
        $this->assertSame(3, DB::table('gates_webhook_deliveries')->count());

        // Every logged delivery records the event it carried.
        $this->assertSame(2, DB::table('gates_webhook_deliveries')->where('event', 'order.paid')->count());
        $this->assertSame(1, DB::table('gates_webhook_deliveries')->where('event', 'event.registration')->count());
    }

    public function test_dispatch_is_safe_when_no_webhooks(): void
    {
        WebhookService::dispatch('order.paid', ['ref' => 'AFG-2']); // no rows — must not throw
        $this->assertSame(0, DB::table('gates_webhook_deliveries')->count());
    }
}
