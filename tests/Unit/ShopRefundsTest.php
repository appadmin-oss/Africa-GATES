<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ShopController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Controllers\PaymentController;
use AfricaGates\Services\{PaymentService, ShopOrderLog, ShopOrderService, ShopRefunds};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Console refunds and cancelling (shop upgrade phase 1).
 *
 * The promises, each a way money goes wrong: a refund is settled only by `refund.processed`;
 * the refunds on an order never add up to more than its charge; a refusal releases the amount
 * and an unknown outcome does not; a repeated webhook does the work once; stock comes back when
 * the money does, never more than was taken; cancelling a paid order refunds it, and cancelling
 * a pending one hands its discount code's use back.
 */
final class ShopRefundsTest extends TestCase
{
    /** @var list<array{0:string,1:string,2:?int}> every refund the fake gateway was asked for */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
        DB::table('gates_shop_refunds')->delete();
        DB::table('gates_shop_order_events')->delete();
        $this->asked = [];
        $_SESSION['admin_id'] = 0;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * A gateway that answers refunds and verifications from a script.
     *
     * @param array<string,mixed> $refundAnswer
     * @param array<string,mixed> $verifyAnswer
     */
    private function gateway(array $refundAnswer = [], array $verifyAnswer = []): PaymentService
    {
        $test = $this;
        return new class ($refundAnswer, $verifyAnswer, $test) extends PaymentService {
            public function __construct(private array $r, private array $v, private ShopRefundsTest $t) { parent::__construct(); }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            {
                $this->t->sawRefund($provider, $reference, $amountNaira);
                return $this->r + ['ok' => true, 'status' => 'pending', 'message' => 'Refund queued',
                                   'provider_ref' => 'rf_' . count($this->t->asked()), 'retryable' => null, 'amount_naira' => null];
            }
            public function verify(string $provider, string $reference): array
            {
                return $this->v + ['ok' => true, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN', 'meta' => []];
            }
        };
    }

    public function sawRefund(string $p, string $ref, ?int $amount): void { $this->asked[] = [$p, $ref, $amount]; }
    public function asked(): array { return $this->asked; }

    /** A tee with stock 10, and a paid order for $qty of them that took them from stock. */
    private function paidOrder(int $qty = 2, int $price = 9000, string $ful = 'unfulfilled', string $code = ''): array
    {
        DB::table('gates_products')->where('slug', 'rf-tee')->delete();
        $pid = (int) DB::table('gates_products')->insertGetId([
            'slug' => 'rf-tee', 'name' => 'Heritage Tee', 'price_naira' => $price, 'stock' => 10 - $qty, 'is_active' => 1,
        ]);
        $ref = 'AFG-SHP-' . bin2hex(random_bytes(4));
        $oid = (int) DB::table('gates_orders')->insertGetId([
            'reference' => $ref, 'email' => 'buyer@example.test', 'name' => 'Ada Obi', 'address' => 'Lagos',
            'subtotal_naira' => $qty * $price, 'goods_naira' => $qty * $price, 'shipping_naira' => 0, 'discount_naira' => 0,
            'discount_code' => $code !== '' ? $code : null,
            'status' => 'paid', 'fulfilment' => $ful, 'provider' => 'paystack',
            'items_json' => json_encode([['slug' => 'rf-tee', 'name' => 'Heritage Tee', 'qty' => $qty, 'drawn' => $qty,
                                          'variant_id' => 0, 'line_total' => $qty * $price]]),
            'created_at' => Carbon::now()->toDateTimeString(), 'paid_at' => Carbon::now()->toDateTimeString(),
        ]);
        return [$oid, $ref, $pid];
    }

    private function order(int $id): object { return DB::table('gates_orders')->where('id', $id)->first(); }
    private function stock(int $pid): int { return (int) DB::table('gates_products')->where('id', $pid)->value('stock'); }

    private function processed(string $ref, string $gid, ?int $naira = null): string
    {
        $data = ['id' => $gid, 'transaction_reference' => $ref, 'status' => 'processed'];
        if ($naira !== null) $data['amount'] = (string) ($naira * 100);
        return ShopRefunds::applyWebhook('paystack', 'refund.processed', $ref, $data);
    }

    // ══ 1 · asking is not settling ═══════════════════════════════════════════

    public function test_a_gateway_refund_waits_for_refund_processed(): void
    {
        [$oid, $ref, $pid] = $this->paidOrder();
        $r = ShopRefunds::request($oid, null, [0 => 2], 'Wrong size', 7, $this->gateway());

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame([['paystack', $ref, null]], $this->asked, 'a whole refund must send no amount — the gateway\'s own record');
        $this->assertSame('paid', (string) $this->order($oid)->status, 'marked refunded when we ASKED');
        $this->assertSame(8, $this->stock($pid), 'stock came back before the money did');
        $this->assertSame(0, ShopRefunds::refundable($this->order($oid)), 'a pending refund must count against the ceiling');

        $this->assertSame('refund-processing', ShopRefunds::applyWebhook('paystack', 'refund.processing', $ref, ['id' => 'rf_1']));
        $this->assertSame('paid', (string) $this->order($oid)->status);

        $this->assertSame('refunded', $this->processed($ref, 'rf_1', 18000));
        $o = $this->order($oid);
        $this->assertSame('refunded', (string) $o->status);
        $this->assertSame(18000, (int) $o->refunded_naira);
        $this->assertSame(10, $this->stock($pid), 'the two units did not go back when the refund settled');
    }

    public function test_a_repeated_processed_delivery_does_the_work_once(): void
    {
        [$oid, $ref, $pid] = $this->paidOrder(3, 5000);
        ShopRefunds::request($oid, 5000, [0 => 1], 'One returned', 7, $this->gateway());

        $this->assertSame('refunded', $this->processed($ref, 'rf_1', 5000));
        $this->assertSame('duplicate', $this->processed($ref, 'rf_1', 5000));
        $this->assertSame('duplicate', $this->processed($ref, 'rf_1', 5000));

        $this->assertSame(5000, (int) $this->order($oid)->refunded_naira, 'a re-delivery added to the refunded total');
        $this->assertSame(8, $this->stock($pid), 'a re-delivery returned the unit again');
        $this->assertSame('paid', (string) $this->order($oid)->status, 'a partial refund is not a refunded order');
    }

    // ══ 2 · never more than the charge ═══════════════════════════════════════

    public function test_partial_refunds_never_add_up_to_more_than_the_charge(): void
    {
        [$oid, $ref] = $this->paidOrder(2, 9000);              // ₦18,000
        $gw = $this->gateway();

        $this->assertTrue(ShopRefunds::request($oid, 10000, [], 'Goodwill', 7, $gw)['ok']);
        $this->assertSame([['paystack', $ref, 10000]], $this->asked, 'a partial refund must name its amount');

        $over = ShopRefunds::request($oid, 9000, [], 'Again', 7, $gw);
        $this->assertFalse($over['ok']);
        $this->assertStringContainsString('8,000', $over['message']);
        $this->assertCount(1, $this->asked, 'the gateway was asked for a refund past the ceiling');

        $this->assertTrue(ShopRefunds::request($oid, 8000, [], 'The rest', 7, $gw)['ok']);
        $this->assertSame(0, ShopRefunds::refundable($this->order($oid)));

        $this->processed($ref, 'rf_1', 10000);
        $this->assertSame('paid', (string) $this->order($oid)->status);
        $this->processed($ref, 'rf_2', 8000);
        $this->assertSame('refunded', (string) $this->order($oid)->status, 'two partials covering the charge did not settle the order');
        $this->assertSame(18000, (int) $this->order($oid)->refunded_naira);
    }

    // ══ 3 · refused, failed, and unknown ═════════════════════════════════════

    public function test_a_definite_refusal_releases_the_amount_and_says_why(): void
    {
        [$oid] = $this->paidOrder();
        $r = ShopRefunds::request($oid, null, [], 'Wrong size', 7,
            $this->gateway(['ok' => false, 'status' => 'failed', 'message' => 'Transaction cannot be refunded', 'provider_ref' => null]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('cannot be refunded', $r['message']);
        $this->assertSame(18000, ShopRefunds::refundable($this->order($oid)), 'a refused refund still counted against the ceiling');
        $this->assertSame('failed', (string) DB::table('gates_shop_refunds')->where('order_id', $oid)->value('status'));
    }

    public function test_an_unknown_outcome_is_held_not_released(): void
    {
        [$oid] = $this->paidOrder();
        $r = ShopRefunds::request($oid, null, [], 'Wrong size', 7,
            $this->gateway(['ok' => false, 'status' => 'pending', 'message' => 'Could not reach the gateway.', 'provider_ref' => null]));

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('not known', $r['message']);
        $this->assertSame(0, ShopRefunds::refundable($this->order($oid)), 'an unknown outcome was released — a retry could pay twice');
    }

    public function test_refund_failed_from_the_gateway_leaves_the_order_paid_and_releases_the_amount(): void
    {
        [$oid, $ref, $pid] = $this->paidOrder();
        ShopRefunds::request($oid, null, [0 => 2], 'Wrong size', 7, $this->gateway());

        $this->assertSame('refund-failed', ShopRefunds::applyWebhook('paystack', 'refund.failed', $ref, ['id' => 'rf_1']));
        $this->assertSame('paid', (string) $this->order($oid)->status);
        $this->assertSame(18000, ShopRefunds::refundable($this->order($oid)));
        $this->assertSame(8, $this->stock($pid), 'a FAILED refund put stock back');
        $this->assertSame('duplicate', $this->processed($ref, 'rf_1'), 'a failed refund was later settled');
    }

    // ══ 4 · outside the gateway, and the gateway's own dashboard ═════════════

    public function test_a_refund_recorded_outside_the_gateway_settles_at_once(): void
    {
        [$oid, , $pid] = $this->paidOrder();
        $r = ShopRefunds::recordManual($oid, null, [0 => 1], 'Paid back in cash at the stand', 7);

        $this->assertTrue($r['ok']);
        $this->assertSame([], $this->asked);
        $this->assertSame('refunded', (string) $this->order($oid)->status);
        $this->assertSame(9, $this->stock($pid), 'only the one unit ticked should come back');
    }

    public function test_a_refund_made_on_the_gateway_dashboard_is_recorded_and_a_partial_one_stays_partial(): void
    {
        [$oid, $ref, $pid] = $this->paidOrder();
        $this->assertSame('refunded', $this->processed($ref, 'dash_1', 3000));
        $this->assertSame('paid', (string) $this->order($oid)->status, 'a ₦3,000 dashboard refund reversed an ₦18,000 order');
        $this->assertSame(3000, (int) $this->order($oid)->refunded_naira);
        $this->assertSame(8, $this->stock($pid));
        $this->assertSame('duplicate', $this->processed($ref, 'dash_1', 3000));

        // The rest, with no amount named: the whole of what is left, stock returned.
        $this->assertSame('refunded', $this->processed($ref, 'dash_2'));
        $this->assertSame('refunded', (string) $this->order($oid)->status);
        $this->assertSame(10, $this->stock($pid));
    }

    // ══ 5 · cancelling ═══════════════════════════════════════════════════════

    public function test_cancelling_a_pending_order_hands_its_discount_use_back(): void
    {
        DB::table('gates_shop_codes')->insert(['code' => 'GALA10', 'kind' => 'percent', 'amount' => 10,
            'used_count' => 3, 'max_per_email' => 1, 'is_active' => 1]);
        $oid = (int) DB::table('gates_orders')->insertGetId([
            'reference' => 'AFG-SHP-pend01', 'email' => 'b@example.test', 'name' => 'Ada', 'items_json' => '[]',
            'subtotal_naira' => 5000, 'status' => 'pending', 'provider' => 'paystack', 'discount_code' => 'GALA10',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $r = ShopRefunds::cancel($oid, 'Buyer changed their mind', 7, $this->gateway());
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('cancelled', (string) $this->order($oid)->status);
        $this->assertSame(2, (int) DB::table('gates_shop_codes')->where('code', 'GALA10')->value('used_count'));

        // Twice is once: the second cancel is refused, and the code does not gain another use.
        $this->assertFalse(ShopRefunds::cancel($oid, 'again', 7, $this->gateway())['ok']);
        $this->assertSame(2, (int) DB::table('gates_shop_codes')->where('code', 'GALA10')->value('used_count'));
        $this->assertSame([], $this->asked, 'a pending order has nothing to refund');
    }

    public function test_a_pending_order_the_buyer_did_pay_is_confirmed_not_cancelled(): void
    {
        $oid = (int) DB::table('gates_orders')->insertGetId([
            'reference' => 'AFG-SHP-pend02', 'email' => 'b@example.test', 'name' => 'Ada', 'items_json' => '[]',
            'subtotal_naira' => 5000, 'status' => 'pending', 'provider' => 'paystack',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $r = ShopRefunds::cancel($oid, 'Looks abandoned', 7,
            $this->gateway([], ['ok' => true, 'status' => 'success', 'amount' => 5000, 'currency' => 'NGN']));

        $this->assertFalse($r['ok']);
        $this->assertSame('paid', (string) $this->order($oid)->status);
    }

    public function test_cancelling_a_paid_order_refunds_it_and_restocks_on_settlement(): void
    {
        [$oid, $ref, $pid] = $this->paidOrder();
        $r = ShopRefunds::cancel($oid, 'Out of print', 7, $this->gateway(), false, true);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('cancelled', (string) $this->order($oid)->fulfilment);
        $this->assertSame([['paystack', $ref, null]], $this->asked);
        $this->assertSame(8, $this->stock($pid));

        $this->processed($ref, 'rf_1', 18000);
        $this->assertSame('refunded', (string) $this->order($oid)->status);
        $this->assertSame(10, $this->stock($pid));
        $kinds = array_column(ShopOrderLog::for($oid), 'kind');
        $this->assertContains('cancel', $kinds);
        $this->assertContains('refund', $kinds);
    }

    public function test_a_payment_that_lands_on_a_cancelled_order_is_never_fulfilled(): void
    {
        $oid = (int) DB::table('gates_orders')->insertGetId([
            'reference' => 'AFG-SHP-late01', 'email' => 'b@example.test', 'name' => 'Ada', 'items_json' => '[]',
            'subtotal_naira' => 5000, 'status' => 'cancelled', 'provider' => 'paystack',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $r = ShopOrderService::confirm('AFG-SHP-late01', 'paystack',
            $this->gateway([], ['ok' => true, 'status' => 'success', 'amount' => 5000, 'currency' => 'NGN']));

        $this->assertSame('mismatch', $r['state']);
        $this->assertSame('cancelled', (string) $this->order($oid)->status);
        $this->assertContains('payment', array_column(ShopOrderLog::for($oid), 'kind'));
    }

    // ══ 6 · a chargeback on goods that have left ═════════════════════════════

    public function test_a_reversal_on_a_delivered_order_returns_no_stock(): void
    {
        [$oid, $ref, $pid] = $this->paidOrder(2, 9000, 'delivered');
        $this->assertTrue(ShopOrderService::reverse($ref, 'charge.dispute.create'));
        $this->assertSame(8, $this->stock($pid), 'units came back for a parcel that was delivered');
    }

    // ══ 7 · the doors: the webhook, and the console ══════════════════════════

    public function test_the_webhook_routes_a_shop_refund_step_to_its_refund_row(): void
    {
        [$oid, $ref, $pid] = $this->paidOrder();
        ShopRefunds::request($oid, null, [0 => 2], 'Wrong size', 7, $this->gateway());

        $twig = $this->createMock(\Slim\Views\Twig::class);
        $ctrl = new PaymentController($this->gateway(), $twig);
        $m = new \ReflectionMethod($ctrl, 'handleWebhook');

        $payload = ['event' => 'refund.pending', 'data' => ['id' => 'rf_1', 'transaction_reference' => $ref, 'amount' => '1800000']];
        $m->invoke($ctrl, new \Slim\Psr7\Response(), 'paystack', 'refund.pending', 'test', $payload);
        $this->assertSame('paid', (string) $this->order($oid)->status, 'refund.pending settled a refund');

        $payload['event'] = 'refund.processed';
        $m->invoke($ctrl, new \Slim\Psr7\Response(), 'paystack', 'refund.processed', 'test', $payload);
        $m->invoke($ctrl, new \Slim\Psr7\Response(), 'paystack', 'refund.processed', 'test', $payload);
        $this->assertSame('refunded', (string) $this->order($oid)->status);
        $this->assertSame(10, $this->stock($pid));
        $this->assertSame(18000, (int) $this->order($oid)->refunded_naira);
    }

    public function test_cancelled_is_no_longer_a_label_the_list_can_set(): void
    {
        [$oid] = $this->paidOrder();
        $twig = $this->createMock(\Slim\Views\Twig::class);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/shop/orders/fulfil')
            ->withParsedBody(['id' => $oid, 'fulfilment' => 'cancelled']);
        $res = (new ShopController($twig, new AuditService()))->fulfil($req, new \Slim\Psr7\Response());

        $this->assertSame('/admin/shop/orders/' . $oid, $res->getHeaderLine('Location'));
        $this->assertSame('unfulfilled', (string) $this->order($oid)->fulfilment);
        $this->assertSame('paid', (string) $this->order($oid)->status);
    }

    public function test_the_order_page_renders_its_refunds_and_history(): void
    {
        [$oid, $ref] = $this->paidOrder();
        ShopRefunds::request($oid, 4000, [], 'Goodwill gesture', 0, $this->gateway());
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'test-token';

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get(ShopController::class)->order(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/orders/' . $oid),
            (new ResponseFactory())->createResponse(), ['id' => (string) $oid]);
        unset($_SESSION['admin_role'], $_SESSION['csrf_token']);

        $this->assertSame(200, $res->getStatusCode());
        $html = html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString($ref, $html);
        $this->assertStringContainsString('Goodwill gesture', $html);
        $this->assertStringContainsString('Waiting for the gateway', $html);
        $this->assertStringContainsString('/admin/shop/orders/' . $oid . '/refund', $html);
        $this->assertStringContainsString('/admin/shop/orders/' . $oid . '/cancel', $html);
    }
}
