<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ShopReportsController;
use AfricaGates\Services\{GatewayFee, PaymentService, ShopOrderService, ShopRefunds, ShopReports};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The shop's numbers (phase 6). The promises: net = sales − refunds settled − fees; a fee the
 * gateway reported is used as reported and anything else is an estimate counted apart; the
 * per-product table sums units and takings and nets returns; the range is clamped; the CSV
 * neutralises a formula a buyer typed into their name.
 */
final class ShopReportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
        DB::table('gates_shop_refunds')->delete();
        GatewayFee::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paid(string $ref, int $naira, string $paidAt, ?int $fee, array $lines = [], string $channel = '', string $name = 'Ada'): int
    {
        return (int) DB::table('gates_orders')->insertGetId([
            'reference' => $ref, 'email' => 'b@shop.example.org', 'name' => $name, 'items_json' => json_encode($lines),
            'subtotal_naira' => $naira, 'goods_naira' => $naira, 'status' => 'paid', 'provider' => $channel === 'pos' ? null : 'paystack',
            'fee_naira' => $fee, 'channel' => $channel !== '' ? $channel : null, 'pos_method' => $channel === 'pos' ? 'cash' : null,
            'created_at' => $paidAt, 'paid_at' => $paidAt,
        ]);
    }

    public function test_net_is_sales_less_refunds_less_fees_and_an_estimate_is_counted_apart(): void
    {
        $a = $this->paid('AFG-SHP-r1', 20000, '2026-11-10 10:00:00', 400);
        $this->paid('AFG-SHP-r2', 10000, '2026-11-11 10:00:00', null);           // fee not reported
        $this->paid('AFG-SHP-POS-1', 5000, '2026-11-11 12:00:00', null, [], 'pos');
        $this->paid('AFG-SHP-r3', 99999, '2026-10-01 10:00:00', 1000);            // outside the range
        ShopRefunds::recordManual($a, 3000, [], 'Goodwill', 0);
        DB::table('gates_shop_refunds')->update(['processed_at' => '2026-11-12 09:00:00']);

        $m = ShopReports::money('2026-11-10', '2026-11-12');
        $est = GatewayFee::fee(10000);
        $this->assertSame(3, $m['orders']);
        $this->assertSame(35000, $m['gross']);
        $this->assertSame(5000, $m['in_person']);
        $this->assertSame(3000, $m['refunds']);
        $this->assertSame(400 + $est, $m['fees'], 'a till sale was charged a gateway fee, or the reported fee was recomputed');
        $this->assertSame($est, $m['fees_estimated']);
        $this->assertSame(35000 - 3000 - 400 - $est, $m['net']);
    }

    public function test_the_fee_the_gateway_reports_is_stored_at_confirmation(): void
    {
        DB::table('gates_orders')->insert(['reference' => 'AFG-SHP-fee1', 'email' => 'b@shop.example.org', 'name' => 'A',
            'items_json' => '[]', 'subtotal_naira' => 10000, 'status' => 'pending', 'provider' => 'paystack',
            'created_at' => Carbon::now()->toDateTimeString()]);
        $gw = new class extends PaymentService {
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            { return ['ok' => true, 'status' => 'success', 'amount' => 10000, 'currency' => 'NGN', 'meta' => [], 'fees' => 250]; }
        };
        ShopOrderService::confirm('AFG-SHP-fee1', 'paystack', $gw);
        $this->assertSame(250, (int) DB::table('gates_orders')->where('reference', 'AFG-SHP-fee1')->value('fee_naira'));
    }

    public function test_products_are_summed_and_ranked_by_takings_with_returns_netted(): void
    {
        $tee = ['slug' => 'tee', 'name' => 'Tee', 'variant' => 'M', 'variant_id' => 4];
        $this->paid('AFG-SHP-p1', 16000, '2026-11-10 10:00:00', 0, [$tee + ['qty' => 2, 'line_total' => 16000, 'restocked' => 1]]);
        $this->paid('AFG-SHP-p2', 11000, '2026-11-10 11:00:00', 0, [$tee + ['qty' => 1, 'line_total' => 8000],
            ['slug' => 'mug', 'name' => 'Mug', 'variant_id' => 0, 'qty' => 1, 'line_total' => 3000]]);

        $rows = ShopReports::products('2026-11-01', '2026-11-30');
        $this->assertSame(['Tee', 'Mug'], array_column($rows, 'product'), 'best sellers first');
        $this->assertSame([3, 1, 24000, 2], [$rows[0]['units'], $rows[0]['returned'], $rows[0]['takings'], $rows[0]['orders']]);
    }

    public function test_the_range_is_clamped_and_a_bad_date_falls_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
        $this->assertSame(['2026-10-20', '2026-11-18'], ShopReports::range('nonsense', ''));
        [$f, $t] = ShopReports::range('2020-01-01', '2026-11-18');
        $this->assertSame(ShopReports::MAX_DAYS - 1, (int) Carbon::parse($f)->diffInDays(Carbon::parse($t)));
        $this->assertSame(['2026-11-01', '2026-11-05'], ShopReports::range('2026-11-05', '2026-11-01'));
    }

    public function test_today_counts_what_needs_doing(): void
    {
        $now = Carbon::now()->toDateTimeString();
        $this->paid('AFG-SHP-t1', 5000, $now, 100);
        $b = $this->paid('AFG-SHP-t2', 5000, $now, 100);
        DB::table('gates_orders')->where('id', $b)->update(['fulfilment' => 'shipped']);
        $t = ShopReports::today();
        $this->assertSame(1, $t['to_prepare']);
        $this->assertSame(1, $t['not_handed']);
        $this->assertSame(10000, $t['today']['gross']);
        $this->assertSame(9800, $t['today']['net']);
    }

    public function test_the_csv_is_complete_and_formula_safe_and_the_screens_render(): void
    {
        $this->paid('AFG-SHP-c1', 5000, '2026-11-10 10:00:00', 100, [], '', '=HYPERLINK("http://x")');
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 't';
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(ShopReportsController::class);
        $rf = new ResponseFactory();

        $csv = (string) $ctrl->csv((new ServerRequestFactory())->createServerRequest('GET', '/x')
            ->withQueryParams(['kind' => 'orders', 'from' => '2026-11-01', 'to' => '2026-11-30']), $rf->createResponse())->getBody();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('AFG-SHP-c1', $lines[1]);
        $this->assertStringNotContainsString(',=HYPERLINK', $lines[1], 'a formula reached the spreadsheet as a formula');
        $this->assertStringContainsString(',4900,', $lines[1], 'net column');

        foreach (['dashboard' => [], 'reports' => ['from' => '2026-11-01', 'to' => '2026-11-30']] as $m => $qp) {
            $res = $ctrl->{$m}((new ServerRequestFactory())->createServerRequest('GET', '/x')->withQueryParams($qp), $rf->createResponse());
            $this->assertSame(200, $res->getStatusCode(), $m);
        }
        unset($_SESSION['admin_role'], $_SESSION['csrf_token']);
    }
}
