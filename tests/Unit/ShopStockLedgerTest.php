<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ShopStockController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\{PaymentService, ShopOrderService, ShopRefunds, ShopStock};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The stock ledger, levels, the stock-take and the daily digest (shop upgrade phase 2).
 *
 * The promise: every path that changes a count writes one ledger row with a reason, so the
 * history adds up to the shelf; a blank counted box is "not counted", never zero; untracked
 * stays untracked; a level is the option's own or the shop-wide default; and the digest goes
 * once a day, only when something is low.
 */
final class ShopStockLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_shop_stock_moves')->delete();
        DB::table('gates_orders')->delete();
        DB::table('gates_settings')->whereIn('key_name', [ShopStock::LOW_KEY, ShopStock::DIGEST_KEY])->delete();
        $_SESSION['admin_id'] = 0;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A shirt in M (stock $m) and L (stock $l); returns [productId, mId, lId]. */
    private function shirt(?int $m = 5, ?int $l = 2): array
    {
        $pid = (int) DB::table('gates_products')->insertGetId(['slug' => 'ledger-tee-' . bin2hex(random_bytes(3)),
            'name' => 'Ledger Tee', 'price_naira' => 8000, 'stock' => null, 'is_active' => 1]);
        $mid = (int) DB::table('gates_product_variants')->insertGetId(['product_id' => $pid, 'label' => 'M', 'stock' => $m, 'is_active' => 1, 'sort_order' => 0]);
        $lid = (int) DB::table('gates_product_variants')->insertGetId(['product_id' => $pid, 'label' => 'L', 'stock' => $l, 'is_active' => 1, 'sort_order' => 1]);
        return [$pid, $mid, $lid];
    }

    private function vstock(int $vid): ?int
    {
        $v = DB::table('gates_product_variants')->where('id', $vid)->value('stock');
        return $v === null ? null : (int) $v;
    }

    /** @return array<string,int> reason => summed delta for a product */
    private function ledger(int $pid): array
    {
        $out = [];
        foreach (DB::table('gates_shop_stock_moves')->where('product_id', $pid)->get() as $m) {
            $out[(string) $m->reason] = ($out[(string) $m->reason] ?? 0) + (int) $m->delta;
        }
        return $out;
    }

    private function gateway(): PaymentService
    {
        return new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            { return ['ok' => true, 'status' => 'success', 'amount' => 16000, 'currency' => 'NGN', 'meta' => []]; }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            { return ['ok' => true, 'status' => 'pending', 'message' => 'ok', 'provider_ref' => 'rf_led', 'amount_naira' => null]; }
        };
    }

    // ══ 1 · every path writes a row ══════════════════════════════════════════

    public function test_a_sale_and_its_refund_are_both_in_the_ledger_and_add_up(): void
    {
        [$pid, $mid] = $this->shirt(5, 2);
        $slug = (string) DB::table('gates_products')->where('id', $pid)->value('slug');
        $oid = (int) DB::table('gates_orders')->insertGetId([
            'reference' => 'AFG-SHP-led001', 'email' => 'b@example.test', 'name' => 'Ada', 'address' => 'Lagos',
            'subtotal_naira' => 16000, 'status' => 'pending', 'provider' => 'paystack',
            'items_json' => json_encode([['slug' => $slug, 'name' => 'Ledger Tee', 'variant' => 'M', 'variant_id' => $mid, 'qty' => 2, 'line_total' => 16000]]),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->assertSame('confirmed', ShopOrderService::confirm('AFG-SHP-led001', 'paystack', $this->gateway())['state']);
        $this->assertSame(3, $this->vstock($mid));
        $sale = DB::table('gates_shop_stock_moves')->where('reason', 'sale')->first();
        $this->assertNotNull($sale, 'a sale left no ledger row');
        $this->assertSame([-2, 3, $oid], [(int) $sale->delta, (int) $sale->after_qty, (int) $sale->order_id]);

        ShopRefunds::recordManual($oid, null, [0 => 2], 'Returned', 4);
        $this->assertSame(5, $this->vstock($mid));
        $this->assertSame(['sale' => -2, 'refund' => 2], $this->ledger($pid));
    }

    public function test_an_adjustment_is_reason_coded_and_signed(): void
    {
        [$pid, $mid] = $this->shirt(5, 2);
        $slug = (string) DB::table('gates_products')->where('id', $pid)->value('slug');
        $this->assertTrue(ShopStock::move($slug, $mid, 10, 'received', 'From the printer', null, 3));
        $this->assertTrue(ShopStock::move($slug, $mid, -1, 'damaged', 'Ink stain', null, 3));
        $this->assertTrue(ShopStock::move($slug, $mid, -2, 'giveaway', 'Judges', null, 3));
        $this->assertSame(12, $this->vstock($mid));
        $this->assertSame(['received' => 10, 'damaged' => -1, 'giveaway' => -2], $this->ledger($pid));

        // Never below zero unless the till asks for it, and what was taken is what is recorded.
        $this->assertTrue(ShopStock::move($slug, $mid, -50, 'damaged', 'Flood', null, 3));
        $this->assertSame(0, $this->vstock($mid));
        $this->assertSame(-12, (int) DB::table('gates_shop_stock_moves')->orderByDesc('id')->value('delta'));
        $this->assertTrue(ShopStock::move($slug, $mid, -1, 'pos', 'Queue', null, 3, true));
        $this->assertSame(-1, $this->vstock($mid), 'the till could not sell below zero');
    }

    public function test_untracked_stays_untracked(): void
    {
        [$pid, , $lid] = $this->shirt(5, null);
        $slug = (string) DB::table('gates_products')->where('id', $pid)->value('slug');
        $this->assertFalse(ShopStock::move($slug, $lid, 3, 'received'));
        $this->assertNull($this->vstock($lid), 'receiving into an uncounted option turned it into a number');
        $this->assertSame([], $this->ledger($pid));

        // Switching counting ON records the whole starting count, so the ledger adds up.
        $this->assertSame(7, ShopStock::set($pid, $lid, 7, 'recount', 'First count', 3));
        $this->assertSame(7, $this->vstock($lid));
        $this->assertSame(['recount' => 7], $this->ledger($pid));
    }

    // ══ 2 · levels, the low list ═════════════════════════════════════════════

    public function test_the_low_list_uses_the_options_own_level_or_the_default(): void
    {
        [$pid, $mid, $lid] = $this->shirt(5, 2);
        $this->assertSame(ShopStock::LOW_DEFAULT, ShopStock::lowLevel());
        $names = static fn (): array => array_map(static fn ($r) => $r['option'] . ':' . $r['state'],
            array_filter(ShopStock::low(), static fn ($r) => $r['product_id'] === $pid));

        $this->assertSame(['L:low'], array_values($names()), 'L (2) is under the default of 3; M (5) is not');
        ShopStock::setOwnLow($pid, $mid, 6);
        $this->assertEqualsCanonicalizing(['L:low', 'M:low'], array_values($names()));
        ShopStock::setLowLevel(1);
        $this->assertSame(['M:low'], array_values($names()), 'the default did not apply to the option with no level of its own');
        DB::table('gates_product_variants')->where('id', $lid)->update(['stock' => 0]);
        $this->assertSame('L:out', array_values($names())[0], 'out of stock should sort first');
    }

    // ══ 3 · the stock-take screen ════════════════════════════════════════════

    public function test_a_stock_take_writes_differences_and_leaves_blanks_alone(): void
    {
        [$pid, $mid, $lid] = $this->shirt(5, 2);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/shop/stock/count')->withParsedBody([
            'counted' => [$pid . ':' . $mid => '4', $pid . ':' . $lid => ''],
            'low' => [$pid . ':' . $mid => '2', $pid . ':' . $lid => ''], 'low_was' => [$pid . ':' . $mid => '', $pid . ':' . $lid => ''],
            'note' => 'Monday count', 'default_low' => '3',
        ]);
        (new ShopStockController($this->createMock(\Slim\Views\Twig::class), new AuditService()))->count($req, new \Slim\Psr7\Response());

        $this->assertSame(4, $this->vstock($mid));
        $this->assertSame(2, $this->vstock($lid), 'a blank counted box zeroed an option');
        $this->assertSame(['recount' => -1], $this->ledger($pid));
        $this->assertSame(2, (int) DB::table('gates_product_variants')->where('id', $mid)->value('low_stock'));
        $this->assertNull(DB::table('gates_product_variants')->where('id', $lid)->value('low_stock'));
    }

    public function test_a_stock_take_with_a_bad_number_saves_nothing(): void
    {
        [$pid, $mid, $lid] = $this->shirt(5, 2);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/shop/stock/count')->withParsedBody([
            'counted' => [$pid . ':' . $mid => '4', $pid . ':' . $lid => '-3'],
        ]);
        (new ShopStockController($this->createMock(\Slim\Views\Twig::class), new AuditService()))->count($req, new \Slim\Psr7\Response());
        $this->assertSame(5, $this->vstock($mid), 'half a payload was written before the bad row was found');
        $this->assertStringContainsString('Nothing was saved', (string) ($_SESSION['flash_error'] ?? ''));
    }

    public function test_the_stock_screens_render(): void
    {
        [$pid, $mid] = $this->shirt(5, 1);
        $slug = (string) DB::table('gates_products')->where('id', $pid)->value('slug');
        ShopStock::move($slug, $mid, 2, 'received', 'Printer run 2', null, 0);
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 't';
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(ShopStockController::class);

        $res = $ctrl->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/stock'), (new ResponseFactory())->createResponse());
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Running low', $html);
        $this->assertStringContainsString('name="counted[' . $pid . ':' . $mid . ']"', $html);

        $res = $ctrl->history((new ServerRequestFactory())->createServerRequest('GET', '/x'), (new ResponseFactory())->createResponse(), ['id' => (string) $pid]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Printer run 2', (string) $res->getBody());
        unset($_SESSION['admin_role'], $_SESSION['csrf_token']);
    }

    // ══ 4 · the product form goes through the ledger ═════════════════════════

    public function test_typing_over_a_count_in_the_product_form_is_a_correction_with_a_name(): void
    {
        [$pid, $mid] = $this->shirt(5, 2);
        $_SESSION['admin_id'] = 9;
        ShopStock::set($pid, $mid, 8, 'correction', 'Product form', 9);
        $row = DB::table('gates_shop_stock_moves')->where('product_id', $pid)->first();
        $this->assertSame(['correction', 3, 9], [(string) $row->reason, (int) $row->delta, (int) $row->admin_id]);

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/ProductsController.php');
        $this->assertDoesNotMatchRegularExpression("/'stock'\\s*=>\\s*\\\$(stockRaw|rawStock)/", $src,
            'the product form writes a count straight into the column again, past the ledger');
    }

    // ══ 5 · the daily digest ═════════════════════════════════════════════════

    public function test_the_digest_goes_once_a_day_and_only_when_something_is_low(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'admin_alert_email'], ['value' => 'ops@shop.example.org']);
        $sent = [];
        $mailer = $this->createMock(\AfricaGates\Services\OtpService::class);
        $mailer->method('sendBranded')->willReturnCallback(function ($to, $subject) use (&$sent) { $sent[] = $subject; return ['ok' => true]; });

        Carbon::setTestNow(Carbon::parse('2026-11-18 06:30:00'));
        $this->shirt(5, 1);
        $this->assertSame(0, ShopStock::digest($mailer), 'sent before the morning hour');

        Carbon::setTestNow(Carbon::parse('2026-11-18 08:00:00'));
        $this->assertSame(1, ShopStock::digest($mailer));
        $this->assertSame(0, ShopStock::digest($mailer), 'a second tick the same day sent another');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('1 low', $sent[0]);

        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00'));
        DB::table('gates_product_variants')->update(['stock' => 50]);
        $this->assertSame(0, ShopStock::digest($mailer), 'an all-fine digest was sent');
        $this->assertCount(1, $sent);
    }
}
