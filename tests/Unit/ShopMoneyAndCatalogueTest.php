<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\{ProductsController, ShopController, ShopTillController};
use AfricaGates\Admin\Services\{AuditService, UploadService};
use AfricaGates\Admin\Support\{Permissions, ShopMoney};
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The shop-money permission, and the catalogue's search, archive and duplicate (phase 7).
 *
 * The permission's promise is two-sided: a content editor WITHOUT it can no longer refund, and
 * nobody who could refund before the change lost that the day it shipped.
 */
final class ShopMoneyAndCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_orders')->delete();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function admin(string $role, ?int $money): int
    {
        return (int) DB::table('gates_admins')->insertGetId(['email' => $role . bin2hex(random_bytes(3)) . '@shop.example.org',
            'name' => ucfirst($role), 'role' => $role, 'is_active' => 1, 'shop_money' => $money]);
    }

    // ══ 1 · the permission ═══════════════════════════════════════════════════

    public function test_who_holds_shop_money(): void
    {
        $this->assertTrue(ShopMoney::can($this->admin('superadmin', 0), 'superadmin'), 'a superadmin can never be locked out');
        $this->assertTrue(ShopMoney::can($this->admin('editor', 1), 'editor'));
        $this->assertFalse(ShopMoney::can($this->admin('editor', 0), 'editor'));
        $this->assertFalse(ShopMoney::can($this->admin('editor', null), 'editor'), 'a new account starts without it');
        $this->assertFalse(ShopMoney::can($this->admin('viewer', 1), 'viewer'), 'a viewer writes nothing, flag or not');
        $this->assertFalse(ShopMoney::can($this->admin('moderator', 1), 'moderator'), 'a moderator cannot open the shop at all');
    }

    /**
     * Nobody lost access: the migration grants the flag to exactly the roles that could refund
     * before it — every role that may write AND open the shop. Read from the migration itself,
     * so changing either side without the other fails here.
     */
    public function test_the_migration_grants_it_to_everybody_who_could_refund_before(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2027_06_08_shop_money_archive.php');
        $this->assertSame(1, preg_match("/whereIn\\('role', \\[([^\\]]+)\\]\\)->update\\(\\['shop_money' => 1\\]\\)/", $src, $m));
        $granted = array_map(static fn ($s) => trim($s, " '"), explode(',', $m[1]));
        $before = array_values(array_filter(array_keys(Permissions::ROLES),
            static fn ($r) => Permissions::canWrite($r) && Permissions::canOpen($r, '/admin/shop/orders')));
        sort($granted); sort($before);
        $this->assertSame($before, $granted);
    }

    public function test_refunds_and_the_till_refuse_without_it_and_allow_with_it(): void
    {
        $oid = (int) DB::table('gates_orders')->insertGetId(['reference' => 'AFG-SHP-m1', 'email' => 'b@shop.example.org',
            'name' => 'A', 'items_json' => '[]', 'subtotal_naira' => 5000, 'status' => 'paid',
            'created_at' => Carbon::now()->toDateTimeString()]);
        $ctrl = new ShopController($this->createMock(\Slim\Views\Twig::class), new AuditService(), null, new PaymentService());
        $post = fn () => (new ServerRequestFactory())->createServerRequest('POST', '/x')
            ->withParsedBody(['method' => 'manual', 'reason' => 'Returned']);

        $_SESSION['admin_id'] = $this->admin('editor', 0);
        $_SESSION['admin_role'] = 'editor';
        $ctrl->refund($post(), new \Slim\Psr7\Response(), ['id' => (string) $oid]);
        $this->assertSame('paid', (string) DB::table('gates_orders')->where('id', $oid)->value('status'));
        $this->assertSame(ShopMoney::REFUSAL, $_SESSION['flash_error']);
        (new ShopTillController($this->createMock(\Slim\Views\Twig::class), new AuditService()))->open(
            (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody(['label' => 'Stand', 'float' => '0']),
            new \Slim\Psr7\Response());
        $this->assertSame(0, (int) DB::table('gates_shop_cash_sessions')->where('label', 'Stand')->count());

        $_SESSION['admin_id'] = $this->admin('editor', 1);
        $ctrl->refund($post(), new \Slim\Psr7\Response(), ['id' => (string) $oid]);
        $this->assertSame('refunded', (string) DB::table('gates_orders')->where('id', $oid)->value('status'));
    }

    // ══ 2 · the catalogue ════════════════════════════════════════════════════

    private function products(): ProductsController
    {
        return new ProductsController($this->createMock(\Slim\Views\Twig::class), new AuditService(),
                                      $this->createMock(UploadService::class));
    }

    /** @return list<string> */
    private function listed(array $q): array
    {
        $seen = [];
        $twig = $this->createMock(\Slim\Views\Twig::class);
        $twig->method('render')->willReturnCallback(function ($res, $tpl, $c) use (&$seen) { $seen = array_column($c['rows'], 'name'); return $res; });
        (new ProductsController($twig, new AuditService(), $this->createMock(UploadService::class)))
            ->index((new ServerRequestFactory())->createServerRequest('GET', '/x')->withQueryParams($q), new \Slim\Psr7\Response());
        sort($seen);
        return $seen;
    }

    public function test_search_and_filters(): void
    {
        DB::table('gates_products')->delete();
        DB::table('gates_products')->insert([
            ['slug' => 'heritage_tee', 'name' => 'Heritage Tee', 'category' => 'Apparel', 'price_naira' => 1, 'stock' => 1, 'is_active' => 1],
            ['slug' => 'mug', 'name' => 'Mug', 'category' => 'Home', 'price_naira' => 1, 'stock' => 50, 'is_active' => 0],
            ['slug' => 'old-cap', 'name' => 'Old Cap', 'category' => 'Apparel', 'price_naira' => 1, 'stock' => 9, 'is_active' => 0],
        ]);
        DB::table('gates_products')->where('slug', 'old-cap')->update(['archived_at' => '2026-11-01 00:00:00']);

        $this->assertSame(['Heritage Tee'], $this->listed(['q' => 'tage_t']), 'an underscore must be a letter, not a wildcard');
        $this->assertSame(['Heritage Tee', 'Mug'], $this->listed([]), 'archived products must not be in the everyday list');
        $this->assertSame(['Old Cap'], $this->listed(['show' => 'archived']));
        $this->assertSame(['Mug'], $this->listed(['show' => 'hidden']));
        $this->assertSame(['Heritage Tee'], $this->listed(['show' => 'low']));
        $this->assertSame(['Heritage Tee'], $this->listed(['category' => 'Apparel']));
        $this->assertSame(['Heritage Tee', 'Mug'], $this->listed(['show' => 'nonsense', 'category' => 'Nope']), 'an unknown filter falls back');
    }

    public function test_an_ordered_product_is_archived_not_deleted(): void
    {
        $pid = (int) DB::table('gates_products')->insertGetId(['slug' => 'sold-tee', 'name' => 'Sold Tee', 'price_naira' => 1, 'is_active' => 1]);
        $free = (int) DB::table('gates_products')->insertGetId(['slug' => 'typo-tee', 'name' => 'Typo', 'price_naira' => 1, 'is_active' => 1]);
        DB::table('gates_orders')->insert(['reference' => 'AFG-SHP-o1', 'email' => 'b@shop.example.org', 'name' => 'A',
            'items_json' => json_encode([['slug' => 'sold-tee', 'qty' => 1]]), 'status' => 'paid', 'created_at' => '2026-11-01 00:00:00']);
        $r = (new ServerRequestFactory())->createServerRequest('POST', '/x');

        $this->products()->delete($r, new \Slim\Psr7\Response(), ['id' => (string) $pid]);
        $this->assertTrue(DB::table('gates_products')->where('id', $pid)->exists(), 'an ordered product was deleted');
        $this->products()->delete($r, new \Slim\Psr7\Response(), ['id' => (string) $free]);
        $this->assertFalse(DB::table('gates_products')->where('id', $free)->exists(), 'an unordered typo could not be deleted');

        $this->products()->archive($r, new \Slim\Psr7\Response(), ['id' => (string) $pid]);
        $p = DB::table('gates_products')->where('id', $pid)->first();
        $this->assertNotNull($p->archived_at);
        $this->assertSame(0, (int) $p->is_active, 'an archived product is still on sale');
        $this->products()->archive($r->withParsedBody(['restore' => '1']), new \Slim\Psr7\Response(), ['id' => (string) $pid]);
        $this->assertNull(DB::table('gates_products')->where('id', $pid)->value('archived_at'));
    }

    public function test_a_duplicate_is_hidden_uncounted_and_brings_its_options(): void
    {
        $pid = (int) DB::table('gates_products')->insertGetId(['slug' => 'dup-tee', 'name' => 'Dup Tee', 'price_naira' => 8000,
            'stock' => 7, 'is_active' => 1, 'sold_count' => 40]);
        foreach (['S', 'M'] as $i => $l) {
            DB::table('gates_product_variants')->insert(['product_id' => $pid, 'label' => $l, 'stock' => 5, 'is_active' => 1,
                'sort_order' => $i, 'price_delta_naira' => $i * 500]);
        }
        $res = $this->products()->duplicate((new ServerRequestFactory())->createServerRequest('POST', '/x'),
                                            new \Slim\Psr7\Response(), ['id' => (string) $pid]);
        $new = (int) basename($res->getHeaderLine('Location'));
        $c = DB::table('gates_products')->where('id', $new)->first();
        $this->assertSame(['Copy of Dup Tee', 0, null, 8000], [(string) $c->name, (int) $c->is_active, $c->stock, (int) $c->price_naira]);
        $this->assertNotSame('dup-tee', (string) $c->slug);
        $this->assertEmpty($c->sold_count, 'the copy inherited the original\'s sales');
        $vs = DB::table('gates_product_variants')->where('product_id', $new)->orderBy('sort_order')->get();
        $this->assertSame(['S', 'M'], $vs->pluck('label')->all());
        $this->assertSame([null, null], $vs->pluck('stock')->all(), 'a copy inherited a count of goods it does not have');
        $this->assertSame(500, (int) $vs[1]->price_delta_naira);
    }
}
