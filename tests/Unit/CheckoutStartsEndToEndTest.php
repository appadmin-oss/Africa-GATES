<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Can a buyer actually START a checkout?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS TEST EXISTS, AND WHY THE UNIT TESTS DID NOT CATCH WHAT IT CATCHES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every other test in this area calls a service directly with a hand-built object. That
 * verifies the arithmetic and the state machine, and it verifies NOTHING about whether the
 * route, the container wiring, the middleware stack and the controller constructor still
 * agree with one another — which is the whole of what a buyer's browser touches before any of
 * that arithmetic runs.
 *
 * The failure mode that gap allows is total and silent: a 500 on `POST /shop/checkout` with
 * every unit test green, because no test had ever asked the APP to handle a request. A
 * checkout that cannot start is worse than one that computes the wrong total, and it was the
 * one thing nothing asserted.
 *
 * So this drives the real Slim app, through the real container, with the real middleware
 * stack, in the same order as `public/index.php`. It asserts only one thing per endpoint —
 * that the response is not a 5xx — because the point is coverage of the WIRING, not of the
 * behaviour that other files already cover thoroughly.
 */
final class CheckoutStartsEndToEndTest extends TestCase
{
    /** The app, assembled exactly as public/index.php assembles it. */
    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->add(new \AfricaGates\Middleware\CsrfMiddleware());
        $app->addBodyParsingMiddleware();
        // Errors NOT displayed and NOT logged — a 500 must arrive here as a 500 so the
        // assertion can see it, rather than as an uncaught exception that aborts the run.
        $app->addErrorMiddleware(false, false, false);
        return $app;
    }

    private function post(string $path, array $body, bool $json = false): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        if ($json) {
            $req = $req->withHeader('Content-Type', 'application/json');
            $req->getBody()->write((string) json_encode($body));
            $req->getBody()->rewind();
        } else {
            $req = $req->withParsedBody($body);
        }
        return $this->app()->handle($req);
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
        );
    }

    /**
     * A 5xx is the only outcome this file rules out.
     *
     * A redirect back to the shop with `?checkout=unavailable` is a PASS: no gateway is
     * configured in a test environment, so refusing to start is correct behaviour. What must
     * never happen is the request dying before it gets to make that decision.
     */
    private function assertNotServerError(\Psr\Http\Message\ResponseInterface $res, string $what): void
    {
        $this->assertLessThan(500, $res->getStatusCode(),
            $what . ' returned HTTP ' . $res->getStatusCode()
            . ' — the endpoint is fataling before it can even refuse');
    }

    // ── the shop ─────────────────────────────────────────────────────────────

    public function test_the_shop_page_renders(): void
    {
        $this->assertNotServerError($this->get('/shop'), 'GET /shop');
    }

    public function test_posting_a_shop_checkout_does_not_fatal(): void
    {
        DB::table('gates_products')->insertOrIgnore([
            'id' => 8801, 'slug' => 'e2e-tee', 'name' => 'E2E Tee', 'price_naira' => 9000,
            'stock' => 10, 'is_active' => 1,
        ]);

        $res = $this->post('/shop/checkout', [
            'provider' => 'paystack', 'email' => 'buyer@example.test', 'name' => 'Ada Obi',
            'phone' => '08030000000', 'address' => '1 Test Road', 'region' => 'Lagos',
            'cart' => json_encode(['e2e-tee' => ['qty' => 1]]),
        ]);

        $this->assertNotServerError($res, 'POST /shop/checkout');
    }

    /** The quote endpoint the basket calls on every change. */
    public function test_the_shop_quote_endpoint_does_not_fatal(): void
    {
        $res = $this->post('/shop/quote', [
            'region' => 'Lagos', 'cart' => json_encode(['e2e-tee' => ['qty' => 1]]),
        ]);
        $this->assertNotServerError($res, 'POST /shop/quote');
    }

    public function test_the_shop_callback_does_not_fatal(): void
    {
        DB::table('gates_orders')->insertOrIgnore([
            'reference' => 'AFG-SHP-e2e001', 'email' => 'b@example.test', 'name' => 'Ada',
            'address' => 'Lagos', 'items_json' => '[]', 'subtotal_naira' => 9000,
            'status' => 'pending', 'provider' => 'paystack',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->assertNotServerError(
            $this->get('/shop/callback?provider=paystack&ref=AFG-SHP-e2e001'),
            'GET /shop/callback');
    }

    // ── events ───────────────────────────────────────────────────────────────

    private function event(): string
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'E2E Gala', 'slug' => 'e2e-gala', 'status' => 'published',
            'event_date' => Carbon::now()->addMonth()->toDateTimeString(),
        ]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $id, 'slug' => 'regular', 'name' => 'Regular', 'price_naira' => 5000,
            'capacity' => 50, 'min_per_order' => 1, 'max_per_order' => 10,
            'is_active' => 1, 'sort_order' => 0,
        ]);
        return 'e2e-gala';
    }

    public function test_the_event_page_renders(): void
    {
        $slug = $this->event();
        $this->assertNotServerError($this->get('/events/' . $slug), 'GET /events/{slug}');
    }

    public function test_registering_for_a_paid_event_does_not_fatal(): void
    {
        $slug = $this->event();

        $res = $this->post('/events/' . $slug . '/register', [
            'name' => 'Kwame Mensah', 'email' => 'k@example.test', 'phone' => '08030000000',
            'quantity' => 1,
        ], true);

        $this->assertNotServerError($res, 'POST /events/{slug}/register');
    }

    /**
     * The FREE path, which is the one that sends an email inline — and therefore the one that
     * touches the extracted mailer on a request a human is waiting for.
     */
    public function test_registering_for_a_free_event_does_not_fatal(): void
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'E2E Free', 'slug' => 'e2e-free', 'status' => 'published',
            'event_date' => Carbon::now()->addMonth()->toDateTimeString(),
        ]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $id, 'slug' => 'free', 'name' => 'Free', 'price_naira' => 0,
            'capacity' => 50, 'min_per_order' => 1, 'max_per_order' => 10,
            'is_active' => 1, 'sort_order' => 0,
        ]);

        $res = $this->post('/events/e2e-free/register', [
            'name' => 'Ada Obi', 'email' => 'free@example.test', 'phone' => '08030000000',
            'quantity' => 1,
        ], true);

        $this->assertNotServerError($res, 'POST /events/{slug}/register (free tier)');
    }

    public function test_the_event_callback_does_not_fatal(): void
    {
        $this->assertNotServerError(
            $this->get('/events/callback?provider=paystack&ref=AFG-EVT-NOPE'),
            'GET /events/callback');
    }

    // ── the webhook, which is not a browser but is the same wiring ───────────

    /**
     * An unsigned POST must be REFUSED, not crash. 404/401 are both correct answers; a 500
     * would mean the routing rewrite itself is broken, and a broken webhook is invisible from
     * both ends at once.
     */
    public function test_the_webhook_refuses_an_unsigned_post_without_fataling(): void
    {
        $res = $this->post('/pay/webhook', ['event' => 'charge.success'], true);
        $this->assertNotServerError($res, 'POST /pay/webhook');
        $this->assertContains($res->getStatusCode(), [400, 401, 404],
            'an unsigned webhook was not refused');
    }
}
