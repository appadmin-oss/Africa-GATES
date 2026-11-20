<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ShopController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\{OtpService, ShopOrderLog};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The orders desk (shop upgrade phase 3): paging with a true total and a date range, bulk moves
 * that report per row and audit once, the pick list and slips, staff notes, and the buyer told
 * when an order is delivered.
 */
final class ShopOrdersDeskTest extends TestCase
{
    /** @var list<array{0:string,1:string}> [to, subject] of every email sent */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
        DB::table('gates_shop_order_events')->delete();
        $this->sent = [];
        $_SESSION['admin_id'] = 0;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function order(string $ref, string $status = 'paid', string $created = '2026-11-10 10:00:00', array $lines = [], string $ful = 'unfulfilled'): int
    {
        return (int) DB::table('gates_orders')->insertGetId([
            'reference' => $ref, 'email' => strtolower($ref) . '@example.org', 'name' => 'Buyer ' . $ref, 'address' => 'Lagos',
            'items_json' => json_encode($lines), 'subtotal_naira' => 5000, 'status' => $status, 'fulfilment' => $ful,
            'created_at' => $created,
        ]);
    }

    private function ctrl(?\Slim\Views\Twig $twig = null): ShopController
    {
        $mailer = $this->createMock(OtpService::class);
        $mailer->method('sendBranded')->willReturnCallback(function ($to, $subject) {
            $this->sent[] = [$to, $subject];
            return ['ok' => true];
        });
        return new ShopController($twig ?? $this->createMock(\Slim\Views\Twig::class), new AuditService(), $mailer);
    }

    /** @return array<string,mixed> the context the list renders with */
    private function listing(array $query): array
    {
        $ctx = [];
        $twig = $this->createMock(\Slim\Views\Twig::class);
        $twig->method('render')->willReturnCallback(function ($res, $tpl, $c) use (&$ctx) { $ctx = $c; return $res; });
        $this->ctrl($twig)->orders((new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/orders')->withQueryParams($query),
                                   new \Slim\Psr7\Response());
        return $ctx;
    }

    private function bulk(array $body): void
    {
        $this->ctrl()->bulk((new ServerRequestFactory())->createServerRequest('POST', '/admin/shop/orders/bulk')->withParsedBody($body),
                            new \Slim\Psr7\Response());
    }

    // ══ 1 · the list ═════════════════════════════════════════════════════════

    public function test_the_list_pages_with_a_true_total_and_no_ceiling(): void
    {
        for ($i = 1; $i <= ShopController::PAGE_SIZE + 7; $i++) $this->order(sprintf('AFG-SHP-p%04d', $i));
        $first = $this->listing([]);
        $this->assertSame(ShopController::PAGE_SIZE + 7, $first['total']);
        $this->assertCount(ShopController::PAGE_SIZE, $first['rows']);
        $this->assertSame(2, $first['pages']);

        $second = $this->listing(['page' => '2']);
        $this->assertCount(7, $second['rows']);
        $this->assertSame('AFG-SHP-p0001', end($second['rows'])['reference'], 'the oldest order fell off the list');

        // A stale page number is clamped to the last page, not an empty table.
        $this->assertCount(7, $this->listing(['page' => '99'])['rows']);
    }

    public function test_the_date_range_filters_above_the_page_and_a_bad_date_is_ignored(): void
    {
        $this->order('AFG-SHP-d1', 'paid', '2026-11-01 09:00:00');
        $this->order('AFG-SHP-d2', 'paid', '2026-11-05 23:59:00');
        $this->order('AFG-SHP-d3', 'paid', '2026-11-06 00:00:00');

        $refs = static fn (array $c): array => array_column($c['rows'], 'reference');
        $this->assertEqualsCanonicalizing(['AFG-SHP-d1', 'AFG-SHP-d2'], $refs($this->listing(['from' => '2026-11-01', 'to' => '2026-11-05'])));
        $this->assertSame(['AFG-SHP-d3'], $refs($this->listing(['from' => '2026-11-06'])));
        $this->assertCount(3, $this->listing(['from' => '2026-11-06T00:00', 'status' => 'nonsense'])['rows'],
            'an unknown value must fall back to "any", not filter everything out');
    }

    // ══ 2 · bulk moves ═══════════════════════════════════════════════════════

    public function test_a_bulk_move_reports_per_row_and_audits_once(): void
    {
        $a = $this->order('AFG-SHP-b1');
        $b = $this->order('AFG-SHP-b2', 'paid', '2026-11-10 10:00:00', [], 'shipped');
        $c = $this->order('AFG-SHP-b3', 'pending');
        $before = (int) DB::table('gates_audit_log')->count();

        $this->bulk(['action' => 'shipped', 'ids' => [$a, $b, $c]]);

        $this->assertSame('shipped', (string) DB::table('gates_orders')->where('id', $a)->value('fulfilment'));
        $this->assertSame('unfulfilled', (string) DB::table('gates_orders')->where('id', $c)->value('fulfilment'), 'a pending order was marked shipped');
        $flash = (string) ($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('1 marked shipped, 1 already were', $flash, 'partial must be reported as partial');
        $this->assertStringContainsString('AFG-SHP-b3', $flash);
        $this->assertSame($before + 1, (int) DB::table('gates_audit_log')->count(), 'one audit event per run, not one per row');
        $this->assertCount(1, $this->sent, 'only the order that moved is emailed');
    }

    public function test_a_bulk_run_over_the_cap_changes_nothing_and_names_the_cap(): void
    {
        $id = $this->order('AFG-SHP-cap');
        $this->bulk(['action' => 'packed', 'ids' => array_merge([$id], range(100000, 100000 + ShopController::BULK_MAX))]);
        $this->assertSame('unfulfilled', (string) DB::table('gates_orders')->where('id', $id)->value('fulfilment'));
        $this->assertStringContainsString((string) ShopController::BULK_MAX, (string) $_SESSION['flash_error']);
    }

    public function test_delivered_emails_the_buyer_once(): void
    {
        $id = $this->order('AFG-SHP-dl1');
        $this->bulk(['action' => 'delivered', 'ids' => [$id]]);
        $this->bulk(['action' => 'delivered', 'ids' => [$id]]);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('delivered', $this->sent[0][1]);
        $this->assertContains('status', array_column(ShopOrderLog::for($id), 'kind'));
    }

    // ══ 3 · printing and notes ═══════════════════════════════════════════════

    public function test_the_pick_list_totals_each_product_and_option(): void
    {
        $vid = (int) DB::table('gates_product_variants')->insertGetId(['product_id' => 1, 'label' => 'M', 'sku' => 'TEE-M', 'stock' => 9, 'is_active' => 1, 'sort_order' => 0]);
        $tee = ['slug' => 'tee', 'name' => 'Tee', 'variant' => 'M', 'variant_id' => $vid];
        $a = $this->order('AFG-SHP-k1', 'paid', '2026-11-10 10:00:00', [$tee + ['qty' => 2], ['slug' => 'mug', 'name' => 'Mug', 'variant_id' => 0, 'qty' => 1]]);
        $b = $this->order('AFG-SHP-k2', 'paid', '2026-11-10 10:00:00', [$tee + ['qty' => 3]]);
        $this->order('AFG-SHP-k3', 'paid', '2026-11-10 10:00:00', [$tee + ['qty' => 50]], 'shipped');

        $ctx = [];
        $twig = $this->createMock(\Slim\Views\Twig::class);
        $twig->method('render')->willReturnCallback(function ($res, $tpl, $c) use (&$ctx) { $ctx = $c; return $res; });
        $this->ctrl($twig)->printable((new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/orders/print')
            ->withQueryParams(['kind' => 'pick']), new \Slim\Psr7\Response());

        $byName = array_column($ctx['pick'], null, 'name');
        $this->assertSame(5, $byName['Tee']['qty'], 'the shipped order was picked again, or the two were not summed');
        $this->assertSame('TEE-M', $byName['Tee']['sku']);
        $this->assertSame(1, $byName['Mug']['qty']);

        $this->ctrl($twig)->printable((new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/orders/print')
            ->withQueryParams(['kind' => 'slips', 'ids' => $b . ',' . $a]), new \Slim\Psr7\Response());
        $this->assertSame([$a, $b], array_map('intval', array_column($ctx['orders'], 'id')));
    }

    public function test_a_staff_note_lands_on_the_history_with_its_author(): void
    {
        $aid = (int) DB::table('gates_admins')->insertGetId(['email' => 'desk@shop.example.org', 'name' => 'Kemi Desk', 'role' => 'editor', 'is_active' => 1]);
        $_SESSION['admin_id'] = $aid;
        $id = $this->order('AFG-SHP-n1');
        $this->ctrl()->note((new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody(['note' => 'Buyer will collect on Friday']),
                            new \Slim\Psr7\Response(), ['id' => (string) $id]);
        $h = ShopOrderLog::for($id);
        $this->assertSame(['note', 'Buyer will collect on Friday', 'Kemi Desk'], [$h[0]['kind'], $h[0]['body'], $h[0]['by']]);
    }

    public function test_the_list_and_the_printable_pages_render(): void
    {
        $this->order('AFG-SHP-r1', 'paid', '2026-11-10 10:00:00', [['slug' => 'tee', 'name' => 'Tee', 'qty' => 1, 'variant_id' => 0]]);
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 't';
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(ShopController::class);

        $html = (string) $ctrl->orders((new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/orders'), (new ResponseFactory())->createResponse())->getBody();
        $this->assertStringContainsString('id="soBulk"', $html);
        $this->assertStringContainsString('form="soBulk"', $html);
        foreach (['pick', 'slips'] as $kind) {
            $res = $ctrl->printable((new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/orders/print')->withQueryParams(['kind' => $kind]),
                                    (new ResponseFactory())->createResponse());
            $this->assertSame(200, $res->getStatusCode());
            $this->assertStringContainsString('AFG-SHP-r1', (string) $res->getBody());
        }
        unset($_SESSION['admin_role'], $_SESSION['csrf_token']);
    }
}
