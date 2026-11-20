<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ShopController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Controllers\ShopCheckoutController;
use AfricaGates\Services\{OtpService, PaymentService, ShopOrderLog, ShopOrderService, ShopPickup};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Collection points (shop upgrade phase 4). The promise: collecting carries no delivery charge;
 * a paid collection order has a six-digit code that survives a leading zero; "Collected" needs
 * that code, or a reason recorded with the staff member; a point switched off is refused at
 * checkout rather than turned into a delivery.
 */
final class ShopPickupTest extends TestCase
{
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
        DB::table('gates_shop_pickup_points')->delete();
        $this->sent = [];
        $_SESSION['admin_id'] = 0;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function point(bool $active = true): int
    {
        return (int) DB::table('gates_shop_pickup_points')->insertGetId([
            'name' => 'Ikeja office', 'address' => '12 Allen Avenue', 'hours' => 'Weekdays 10–4',
            'is_active' => $active ? 1 : 0, 'sort_order' => 0,
        ]);
    }

    private function collectionOrder(int $point, string $status = 'paid', ?string $code = '012345'): int
    {
        return (int) DB::table('gates_orders')->insertGetId([
            'reference' => 'AFG-SHP-pk' . bin2hex(random_bytes(3)), 'email' => 'buyer@shop.example.org', 'name' => 'Ada',
            'address' => 'Collect from: Ikeja office', 'items_json' => '[]', 'subtotal_naira' => 5000,
            'status' => $status, 'fulfilment' => 'unfulfilled', 'pickup_point_id' => $point, 'pickup_code' => $code,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function mailer(): OtpService
    {
        $m = $this->createMock(OtpService::class);
        $m->method('sendBranded')->willReturnCallback(function ($to, $subject, $html) {
            $this->sent[] = [$subject, $html];
            return ['ok' => true];
        });
        return $m;
    }

    private function o(int $id): object { return DB::table('gates_orders')->where('id', $id)->first(); }

    // ══ 1 · price ════════════════════════════════════════════════════════════

    public function test_collecting_carries_no_delivery_charge(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => \AfricaGates\Services\ShopShipping::RATES_KEY],
            ['value' => json_encode(['South West' => 3500])]);
        $priced = ['subtotal' => 9000, 'lines' => [['slug' => 't', 'qty' => 1, 'line_total' => 9000]]];

        $delivered = ShopCheckoutController::totals($priced, 'South West');
        $collected = ShopCheckoutController::totals($priced, 'South West', '', '', ShopPickup::point($this->point()));

        $this->assertSame(3500, $delivered['shipping'], 'fixture: delivery should cost something here');
        $this->assertSame(0, $collected['shipping']);
        $this->assertSame(9000, $collected['charged']);
        $this->assertStringContainsString('Ikeja office', $collected['shipping_why']);
    }

    public function test_a_switched_off_point_is_not_usable_at_checkout(): void
    {
        $this->assertNull(ShopPickup::usable($this->point(false)));
        $this->assertNotNull(ShopPickup::usable($this->point(true)));
        $this->assertNull(ShopPickup::usable(999999));
    }

    // ══ 2 · the code ═════════════════════════════════════════════════════════

    public function test_a_paid_collection_order_gets_a_code_the_receipt_quotes(): void
    {
        $pid = $this->point();
        $id = $this->collectionOrder($pid, 'pending', null);
        $ref = (string) $this->o($id)->reference;
        $gw = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            { return ['ok' => true, 'status' => 'success', 'amount' => 5000, 'currency' => 'NGN', 'meta' => []]; }
        };
        $this->assertSame('confirmed', ShopOrderService::confirm($ref, 'paystack', $gw)['state']);

        $code = (string) $this->o($id)->pickup_code;
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame($code, ShopPickup::issueCode($id), 'a second issue changed the code');

        ShopOrderService::receipt($id, $this->mailer());
        $this->assertStringContainsString($code, $this->sent[0][1], 'the receipt does not quote the collection code');
    }

    public function test_a_delivery_order_never_gets_a_code(): void
    {
        $id = (int) DB::table('gates_orders')->insertGetId(['reference' => 'AFG-SHP-dlv', 'email' => 'b@shop.example.org',
            'name' => 'A', 'items_json' => '[]', 'status' => 'paid', 'created_at' => Carbon::now()->toDateTimeString()]);
        $this->assertNull(ShopPickup::issueCode($id));
    }

    // ══ 3 · ready, and collected ═════════════════════════════════════════════

    public function test_ready_emails_the_buyer_their_code_and_where(): void
    {
        $id = $this->collectionOrder($this->point());
        $r = ShopPickup::markReady($id, 5, $this->mailer());
        $this->assertTrue($r['ok']);
        $this->assertSame('ready', (string) $this->o($id)->fulfilment);
        $this->assertStringContainsString('012345', $this->sent[0][1], 'a leading zero was lost from the code');
        $this->assertStringContainsString('12 Allen Avenue', $this->sent[0][1]);
    }

    public function test_collected_needs_the_code_or_a_reason(): void
    {
        $id = $this->collectionOrder($this->point());

        $this->assertFalse(ShopPickup::collect($id, '', '', 5)['ok'], 'handed over with neither code nor reason');
        $this->assertFalse(ShopPickup::collect($id, '999999', '', 5)['ok'], 'a wrong code was accepted');
        $this->assertFalse(ShopPickup::collect($id, '', 'ok', 5)['ok'], 'a three-letter reason was accepted');
        $this->assertSame('unfulfilled', (string) $this->o($id)->fulfilment);

        $this->assertTrue(ShopPickup::collect($id, '012 345', '', 5)['ok'], 'spaces typed off a phone are not a wrong code');
        $o = $this->o($id);
        $this->assertSame(['collected', 5], [(string) $o->fulfilment, (int) $o->collected_by]);
        $this->assertNull($o->collect_override);
        $this->assertFalse(ShopPickup::collect($id, '012345', '', 5)['ok'], 'collected twice');
    }

    public function test_handing_over_without_the_code_records_the_reason_and_the_name(): void
    {
        $aid = (int) DB::table('gates_admins')->insertGetId(['email' => 'stand@shop.example.org', 'name' => 'Tunde Stand', 'role' => 'editor', 'is_active' => 1]);
        $id = $this->collectionOrder($this->point());
        $this->assertTrue(ShopPickup::collect($id, '', 'Phone dead; saw ID, Ada Obi', $aid)['ok']);
        $this->assertSame('Phone dead; saw ID, Ada Obi', (string) $this->o($id)->collect_override);
        $last = array_slice(ShopOrderLog::for($id), -1)[0] ?? [];
        $this->assertSame('Tunde Stand', $last['by'] ?? '');
        $this->assertStringContainsString('WITHOUT the code', $last['body'] ?? '');
    }

    public function test_an_unpaid_or_cancelled_order_is_never_handed_over(): void
    {
        $p = $this->point();
        $this->assertFalse(ShopPickup::collect($this->collectionOrder($p, 'pending'), '012345', '', 5)['ok']);
        $c = $this->collectionOrder($p);
        DB::table('gates_orders')->where('id', $c)->update(['fulfilment' => 'cancelled']);
        $this->assertFalse(ShopPickup::collect($c, '012345', '', 5)['ok']);
    }

    public function test_collected_cannot_be_set_from_the_list(): void
    {
        $id = $this->collectionOrder($this->point());
        (new ShopController($this->createMock(\Slim\Views\Twig::class), new AuditService()))->fulfil(
            (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody(['id' => $id, 'fulfilment' => 'collected']),
            new \Slim\Psr7\Response());
        $this->assertSame('unfulfilled', (string) $this->o($id)->fulfilment);
    }

    public function test_the_points_screen_and_a_collection_order_render(): void
    {
        $id = $this->collectionOrder($this->point());
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 't';
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(ShopController::class);

        $html = (string) $ctrl->pickupPoints((new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/pickup'), (new ResponseFactory())->createResponse())->getBody();
        $this->assertStringContainsString('Ikeja office', $html);

        $html = (string) $ctrl->order((new ServerRequestFactory())->createServerRequest('GET', '/x'), (new ResponseFactory())->createResponse(), ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('/admin/shop/orders/' . $id . '/collect', $html);
        $this->assertStringNotContainsString('012345', $html, 'the order page shows staff the code it is meant to check');
        unset($_SESSION['admin_role'], $_SESSION['csrf_token']);
    }
}
