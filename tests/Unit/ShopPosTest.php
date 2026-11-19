<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ShopTillController;
use AfricaGates\Services\{ShopPos, ShopRefunds};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The till and the cash drawer (shop upgrade phase 5). The promises: an in-person sale draws
 * from the same stock as the website, through the ledger; it never stalls on a count (it may go
 * below zero, and says so); cash needs an open drawer; the drawer expects float + cash − cash
 * handed back, and a drawer that does not balance cannot close without a note.
 */
final class ShopPosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
        DB::table('gates_shop_cash_sessions')->delete();
        DB::table('gates_shop_stock_moves')->delete();
    }

    /** @return array{0:int,1:int,2:int} product, M variant, a variant-less mug */
    private function stock(): array
    {
        $p = (int) DB::table('gates_products')->insertGetId(['slug' => 'pos-tee-' . bin2hex(random_bytes(2)), 'name' => 'Tee', 'price_naira' => 8000, 'is_active' => 1]);
        $m = (int) DB::table('gates_product_variants')->insertGetId(['product_id' => $p, 'label' => 'XL', 'price_delta_naira' => 1500, 'stock' => 1, 'is_active' => 1, 'sort_order' => 0]);
        $mug = (int) DB::table('gates_products')->insertGetId(['slug' => 'pos-mug-' . bin2hex(random_bytes(2)), 'name' => 'Mug', 'price_naira' => 3000, 'stock' => 10, 'is_active' => 1]);
        return [$p, $m, $mug];
    }

    public function test_a_cash_sale_draws_stock_through_the_ledger_and_may_go_below_zero(): void
    {
        [$p, $xl, $mug] = $this->stock();
        $sid = (int) ShopPos::open('Gala stand', 5000, 3)['id'];
        $r = ShopPos::sell([['product_id' => $p, 'variant_id' => $xl, 'qty' => 2], ['product_id' => $mug, 'variant_id' => 0, 'qty' => 1]],
                           'cash', $sid, '', '', '', 3);

        $this->assertTrue($r['ok'], $r['message']);
        $o = DB::table('gates_orders')->where('id', $r['order_id'])->first();
        $this->assertSame([22000, 'paid', 'pos', 'cash', 'collected'],
            [(int) $o->subtotal_naira, (string) $o->status, (string) $o->channel, (string) $o->pos_method, (string) $o->fulfilment],
            'the XL is base 8,000 + 1,500, twice, plus a 3,000 mug');
        $this->assertSame(-1, (int) DB::table('gates_product_variants')->where('id', $xl)->value('stock'), 'the till refused, or floored, a sale the count could not cover');
        $this->assertNotEmpty($r['below_zero']);
        $this->assertSame(9, (int) DB::table('gates_products')->where('id', $mug)->value('stock'));
        $this->assertSame(2, (int) DB::table('gates_shop_stock_moves')->where('order_id', $r['order_id'])->where('reason', 'pos')->count());
    }

    public function test_cash_needs_an_open_drawer_and_a_bad_basket_writes_nothing(): void
    {
        [$p, $xl] = $this->stock();
        $this->assertFalse(ShopPos::sell([['product_id' => $p, 'variant_id' => $xl, 'qty' => 1]], 'cash', null, '', '', '', 3)['ok']);
        // A variant product sold without its option is refused before anything is written.
        $this->assertFalse(ShopPos::sell([['product_id' => $p, 'variant_id' => 0, 'qty' => 1]], 'transfer', null, '', '', '', 3)['ok']);
        $this->assertSame(0, (int) DB::table('gates_orders')->count());
        $this->assertSame(1, (int) DB::table('gates_product_variants')->where('id', $xl)->value('stock'));
        $this->assertTrue(ShopPos::sell([['product_id' => $p, 'variant_id' => $xl, 'qty' => 1]], 'transfer', null, '', '', 'Ref 7781', 3)['ok'],
            'a transfer needs no drawer');
    }

    public function test_the_drawer_expects_float_plus_cash_less_cash_handed_back(): void
    {
        [$p, $xl, $mug] = $this->stock();
        $sid = (int) ShopPos::open('Office, Monday', 2000, 3)['id'];
        $a = ShopPos::sell([['product_id' => $mug, 'variant_id' => 0, 'qty' => 2]], 'cash', $sid, '', '', '', 3);      // 6,000 cash
        ShopPos::sell([['product_id' => $mug, 'variant_id' => 0, 'qty' => 1]], 'terminal', $sid, '', '', '', 3);           // 3,000 card
        ShopRefunds::recordManual((int) $a['order_id'], 3000, [0 => 1], 'One mug returned at the table', 3);

        $s = ShopPos::summary($sid);
        $this->assertSame(['cash' => 6000, 'transfer' => 0, 'terminal' => 3000], $s['by_method']);
        $this->assertSame(2000 + 6000 - 3000, $s['expected']);

        $short = ShopPos::close($sid, 4500, '', 3);
        $this->assertFalse($short['ok'], 'a drawer ₦500 short closed without a word');
        $this->assertTrue(ShopPos::close($sid, 4500, 'Gave change wrongly once', 3)['ok']);
        $row = DB::table('gates_shop_cash_sessions')->where('id', $sid)->first();
        $this->assertSame([5000, 4500, -500], [(int) $row->expected_naira, (int) $row->counted_naira, (int) $row->difference_naira]);
        $this->assertFalse(ShopPos::sell([['product_id' => $mug, 'variant_id' => 0, 'qty' => 1]], 'cash', $sid, '', '', '', 3)['ok'],
            'cash went into a closed drawer');
    }

    public function test_a_till_sale_refunds_outside_the_gateway_only(): void
    {
        [, , $mug] = $this->stock();
        $r = ShopPos::sell([['product_id' => $mug, 'variant_id' => 0, 'qty' => 1]], 'transfer', null, '', '', '', 3);
        $gw = new class extends \AfricaGates\Services\PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
        };
        $this->assertFalse(ShopRefunds::request((int) $r['order_id'], null, [], 'Wrong mug', 3, $gw)['ok'],
            'a till sale was sent to a gateway it never went through');
    }

    public function test_the_till_renders_with_a_session(): void
    {
        $this->stock();
        $sid = (int) ShopPos::open('Gala stand', 1000, 0)['id'];
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 't';
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get(ShopTillController::class)->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/till')->withQueryParams(['session' => (string) $sid]),
            (new ResponseFactory())->createResponse());
        unset($_SESSION['admin_role'], $_SESSION['csrf_token']);
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Expected in the drawer', $html);
        $this->assertStringContainsString('XL', $html);
    }
}
