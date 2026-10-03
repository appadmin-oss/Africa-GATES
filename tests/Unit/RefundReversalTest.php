<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventTicketService, PaymentService, ReferralPayout, ReferralService, ShopOrderService};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * What money going BACK has to undo, beyond the row whose status it changes.
 *
 * Each case here is a side effect of a sale that used to survive the sale's reversal:
 *
 *   · a referral credit — refunds and chargebacks never touched `gates_referral_credits`,
 *     so a refunded ticket stayed one of the member's ten paid referrals and its
 *     commission stayed withdrawable;
 *   · the shop's self-referral — the shop stamped whatever `?ref=` the session held and
 *     creditSale() paid it, so a member's own link earned them commission on their own
 *     order;
 *   · restocked units — an oversold line was clamped to zero on the way out and the FULL
 *     quantity added back on a refund, minting stock nobody had.
 *
 * Plus the retired /pay/init, which took money for bonus votes nothing could redeem.
 */
final class RefundReversalTest extends TestCase
{
    private int $referrer = 0;
    private string $code = '';
    private string $ownerEmail = '';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_referral_credits')->delete();
        DB::table('gates_referral_codes')->delete();
        DB::table('gates_orders')->delete();
        DB::table('gates_products')->delete();
        DB::table('gates_event_registrations')->delete();

        $this->ownerEmail = 'referrer-' . bin2hex(random_bytes(3)) . '@example.test';
        $this->referrer   = (int) DB::table('gates_users')->insertGetId([
            'email' => $this->ownerEmail, 'name' => 'Referrer',
        ]);
        $this->code = 'AGRV' . strtoupper(bin2hex(random_bytes(2)));
        DB::table('gates_referral_codes')->insert([
            'user_id' => $this->referrer, 'code' => $this->code, 'created_at' => '2026-08-01 10:00:00',
        ]);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'referral_threshold'], ['value' => '1']);
    }

    private function credit(string $type, int $id): ?object
    {
        return DB::table('gates_referral_credits')
            ->where('source_type', $type)->where('source_id', $id)->first() ?: null;
    }

    private function paidOrder(array $lines, array $over = []): object
    {
        $ref = 'AFG-SHP-' . bin2hex(random_bytes(4));
        DB::table('gates_orders')->insert($over + [
            'reference' => $ref, 'email' => 'buyer@example.test', 'name' => 'Buyer',
            'items_json' => json_encode($lines), 'subtotal_naira' => 20000,
            'status' => 'paid', 'fulfilment' => 'unfulfilled', 'provider' => 'paystack',
            'referral_code' => $this->code, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        return DB::table('gates_orders')->where('reference', $ref)->first();
    }

    // ══ 1 · a referral is reversed with the sale ═════════════════════════════

    public function test_a_charged_back_ticket_reverses_its_referral(): void
    {
        $eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Gala', 'slug' => 'rv-gala-' . bin2hex(random_bytes(3)), 'status' => 'published',
            'event_date' => Carbon::parse('+30 days')->toDateTimeString(),
        ]);
        $ref = 'AFG-EVT-' . strtoupper(bin2hex(random_bytes(4)));
        $id  = (int) DB::table('gates_event_registrations')->insertGetId([
            'event_id' => $eventId, 'tier' => 'Regular', 'name' => 'Ada', 'email' => 'ada@example.test',
            'phone' => '08030000000', 'quantity' => 1, 'amount_naira' => 20000, 'reference' => $ref,
            'ticket_code' => 'CODE-1', 'status' => 'confirmed', 'referral_code' => $this->code,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        ReferralService::credit(DB::table('gates_event_registrations')->where('id', $id)->first());
        $this->assertTrue(ReferralPayout::available($this->referrer)['ok'], 'fixture');

        $this->assertTrue(EventTicketService::reverse($ref, 'charge.dispute'));

        $this->assertNotNull($this->credit('registration', $id)?->reversed_at);
        $this->assertFalse(ReferralPayout::available($this->referrer)['ok']);
        $this->assertSame(0, ReferralService::stats($this->referrer)['referrals']);
    }

    public function test_a_refunded_shop_order_reverses_its_referral(): void
    {
        $o = $this->paidOrder([]);
        $this->assertTrue(ReferralService::creditSale('shop_order', (int) $o->id, $this->code, 20000,
                                                      null, null, (string) $o->email));

        $this->assertTrue(ShopOrderService::reverse((string) $o->reference, 'refund.processed'));

        $this->assertNotNull($this->credit('shop_order', (int) $o->id)?->reversed_at);
        $this->assertFalse(ReferralPayout::available($this->referrer)['ok']);
    }

    // ══ 2 · the shop cannot pay a member for their own order ═════════════════

    public function test_creditSale_refuses_the_owners_own_purchase(): void
    {
        // By address — the shop has no account column — and by account.
        $this->assertFalse(ReferralService::creditSale('shop_order', 9001, $this->code, 20000,
                                                       null, null, strtoupper($this->ownerEmail)));
        $this->assertFalse(ReferralService::creditSale('shop_order', 9002, $this->code, 20000,
                                                       null, $this->referrer, 'someone@else.test'));
        $this->assertSame(0, DB::table('gates_referral_credits')->count());
    }

    /** Through the real caller, so the buyer's address is proven to reach the check. */
    public function test_a_shop_order_on_the_buyers_own_link_earns_nothing(): void
    {
        $o = $this->paidOrder([], ['status' => 'pending', 'email' => $this->ownerEmail]);

        $gw = new class extends PaymentService {
            public function __construct() { parent::__construct(); }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                return ['ok' => true, 'status' => 'success', 'amount' => 20000, 'currency' => 'NGN', 'meta' => []];
            }
        };

        $r = ShopOrderService::confirm((string) $o->reference, 'paystack', $gw);
        $this->assertSame('confirmed', $r['state'], $r['message']);
        $this->assertNull($this->credit('shop_order', (int) $o->id), 'paid commission on a self-referral');
    }

    // ══ 3 · a refund returns what was drawn, not what was ordered ════════════

    public function test_a_refunded_oversold_order_returns_only_what_was_drawn(): void
    {
        DB::table('gates_products')->insert([
            'slug' => 'tee', 'name' => 'The Tee', 'price_naira' => 4000, 'stock' => 3,
            'is_active' => 1, 'sort_order' => 0, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $o = $this->paidOrder([['slug' => 'tee', 'name' => 'The Tee', 'qty' => 5, 'variant_id' => 0]],
                              ['referral_code' => null]);

        ShopOrderService::fulfil((int) $o->id);
        $this->assertSame(0, (int) DB::table('gates_products')->where('slug', 'tee')->value('stock'));

        $this->assertTrue(ShopOrderService::reverse((string) $o->reference, 'refund.processed'));
        $this->assertSame(3, (int) DB::table('gates_products')->where('slug', 'tee')->value('stock'),
            'the refund put back two units that never existed');
    }

    public function test_an_order_with_no_drawn_record_still_restocks_its_quantity(): void
    {
        // Fulfilled before `drawn` was recorded: the old behaviour is the only answer there is.
        DB::table('gates_products')->insert([
            'slug' => 'mug', 'name' => 'Mug', 'price_naira' => 4000, 'stock' => 1,
            'is_active' => 1, 'sort_order' => 0, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $o = $this->paidOrder([['slug' => 'mug', 'name' => 'Mug', 'qty' => 2, 'variant_id' => 0]],
                              ['referral_code' => null]);

        ShopOrderService::reverse((string) $o->reference, 'refund.processed');
        $this->assertSame(3, (int) DB::table('gates_products')->where('slug', 'mug')->value('stock'));
    }

    // ══ 4 · /pay/init is retired ═════════════════════════════════════════════

    public function test_pay_init_is_gone_and_charges_nothing(): void
    {
        $root    = dirname(__DIR__, 2);
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $root . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require $root . '/src/routes.php')($app);

        $route = null;
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if ($r->getPattern() === '/pay/init' && in_array('POST', $r->getMethods(), true)) $route = $r;
        }
        $this->assertNotNull($route, 'keep the path answering, so a stale form is told it has gone');

        $before = DB::table('gates_donations')->count();
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://afg.local/pay/init')
            ->withParsedBody(['provider' => 'paystack', 'purpose' => 'vote', 'tier' => 'champion', 'email' => 'a@b.io']);
        $res = ($route->getCallable())($req, new Response(), []);

        $this->assertSame(410, $res->getStatusCode());
        $this->assertSame($before, DB::table('gates_donations')->count(), 'a pending vote-pack row was written');
    }
}
