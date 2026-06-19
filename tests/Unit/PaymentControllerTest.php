<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Controllers\PaymentController;
use AfricaGates\Services\PaymentService;
use Slim\Views\Twig;
use Twig\Loader\ArrayLoader;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface as Req;

/**
 * Security contract for checkout. The amount comes ONLY from the server price
 * table; confirmation requires a server-side verify with a matching amount; and
 * confirmation is idempotent (one reference credits once). Webhooks must be
 * signature-verified. A scripted PaymentService stub stands in for the gateway so
 * no network is touched.
 */
class PaymentControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['APP_URL'] = 'https://afg.local';
        $_ENV['PAYSTACK_SECRET_KEY'] = 'sk_test_x';        // enables paystack
        $_ENV['FLUTTERWAVE_WEBHOOK_HASH'] = 'fw-shared-hash';
        unset($_ENV['FLUTTERWAVE_SECRET_KEY']);
    }

    /** A PaymentService whose network boundary is scripted. */
    private function stubPayments(array $opts = []): PaymentService
    {
        return new class($opts) extends PaymentService {
            public function __construct(private array $opts) { parent::__construct(null); }
            public function isEnabled(string $provider): bool {
                return in_array($provider, $this->opts['enabled'] ?? ['paystack','flutterwave'], true);
            }
            public function isKnownProvider(string $provider): bool {
                return in_array($provider, ['paystack','flutterwave'], true);
            }
            public function initialize(string $provider, int $amountNaira, string $email, string $reference, string $callbackUrl, array $meta = []): array {
                return $this->opts['init'] ?? ['ok' => true, 'checkout_url' => 'https://gw/checkout/' . $reference, 'message' => 'ok'];
            }
            public function verify(string $provider, string $reference): array {
                return $this->opts['verify'] ?? ['ok' => true, 'status' => 'success', 'amount' => 0, 'currency' => 'NGN', 'meta' => []];
            }
        };
    }

    private function controller(PaymentService $svc): PaymentController
    {
        $twig = new Twig(new ArrayLoader(['pages/pay-success.twig' => 'ok']));
        return new PaymentController($svc, $twig, null);
    }

    private function postInit(array $body): Req
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://afg.local/pay/init')
            ->withParsedBody($body);
    }

    // ───────────────────────────── /pay/init ────────────────────────────────

    public function test_init_uses_server_price_and_writes_pending_row(): void
    {
        $svc = $this->stubPayments();
        $res = $this->controller($svc)->init(
            $this->postInit(['provider' => 'paystack', 'purpose' => 'vote', 'tier' => 'champion', 'email' => 'a@b.io']),
            new Response()
        );

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('https://gw/checkout/AFG-', $res->getHeaderLine('Location'));

        $row = DB::table('gates_donations')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        // ₦5,000 / 35 votes comes from the SERVER table, not the request.
        $this->assertSame(5000, (int) $row->amount_naira);
        $this->assertSame(35, (int) $row->bonus_votes);
        $this->assertStringStartsWith('AFG-', (string) $row->payment_ref);
    }

    public function test_init_ignores_any_client_supplied_amount(): void
    {
        // A malicious client tries to set amount=1; the server must ignore it.
        $svc = $this->stubPayments();
        $this->controller($svc)->init(
            $this->postInit(['provider' => 'paystack', 'purpose' => 'vote', 'tier' => 'supporter', 'email' => 'a@b.io', 'amount' => '1', 'amount_naira' => '1', 'bonus_votes' => '99999']),
            new Response()
        );
        $row = DB::table('gates_donations')->first();
        $this->assertSame(1000, (int) $row->amount_naira); // table price, not 1
        $this->assertSame(5, (int) $row->bonus_votes);     // table votes, not 99999
    }

    public function test_init_rejects_unknown_tier(): void
    {
        $res = $this->controller($this->stubPayments())->init(
            $this->postInit(['provider' => 'paystack', 'purpose' => 'vote', 'tier' => 'nope', 'email' => 'a@b.io']),
            new Response()
        );
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('/partner#enquiry', $res->getHeaderLine('Location'));
        $this->assertSame(0, DB::table('gates_donations')->count());
    }

    public function test_init_rejects_disabled_provider(): void
    {
        // flutterwave has no secret in this test → disabled.
        $res = $this->controller($this->stubPayments(['enabled' => ['paystack']]))->init(
            $this->postInit(['provider' => 'flutterwave', 'purpose' => 'vote', 'tier' => 'champion', 'email' => 'a@b.io']),
            new Response()
        );
        $this->assertStringContainsString('/partner#enquiry', $res->getHeaderLine('Location'));
        $this->assertSame(0, DB::table('gates_donations')->count());
    }

    public function test_init_rejects_invalid_email(): void
    {
        $res = $this->controller($this->stubPayments())->init(
            $this->postInit(['provider' => 'paystack', 'purpose' => 'vote', 'tier' => 'champion', 'email' => 'not-an-email']),
            new Response()
        );
        $this->assertStringContainsString('/partner#enquiry', $res->getHeaderLine('Location'));
        $this->assertSame(0, DB::table('gates_donations')->count());
    }

    public function test_init_marks_pending_failed_when_gateway_init_fails(): void
    {
        $svc = $this->stubPayments(['init' => ['ok' => false, 'checkout_url' => null, 'message' => 'down']]);
        $this->controller($svc)->init(
            $this->postInit(['provider' => 'paystack', 'purpose' => 'vote', 'tier' => 'champion', 'email' => 'a@b.io']),
            new Response()
        );
        // Row was created then demoted to 'failed' so it can't later be confirmed.
        $row = DB::table('gates_donations')->first();
        $this->assertSame('failed', $row->status);
    }

    // ───────────────────────────── /pay/callback ────────────────────────────

    private function seedPending(string $ref = 'AFG-ref1', int $amount = 5000, int $votes = 35): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'D', 'donor_email' => 'a@b.io', 'amount_naira' => $amount,
            'tier' => 'vote:champion', 'bonus_votes' => $votes, 'votes_used' => 0,
            'payment_ref' => $ref, 'status' => 'pending',
        ]);
    }

    private function getCallback(string $ref, string $provider = 'paystack'): Req
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://afg.local/pay/callback')
            ->withQueryParams(['ref' => $ref, 'provider' => $provider]);
    }

    public function test_callback_confirms_on_success_and_matching_amount(): void
    {
        $this->seedPending('AFG-ok', 5000);
        $svc = $this->stubPayments(['verify' => ['ok' => true, 'status' => 'success', 'amount' => 5000, 'currency' => 'NGN', 'meta' => []]]);

        $res = $this->controller($svc)->callback($this->getCallback('AFG-ok'), new Response());

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('/pay/success', $res->getHeaderLine('Location'));
        $this->assertSame('confirmed', DB::table('gates_donations')->where('payment_ref', 'AFG-ok')->value('status'));
    }

    public function test_callback_refuses_confirm_on_amount_mismatch(): void
    {
        // Buyer paid less than the table price → MUST NOT confirm.
        $this->seedPending('AFG-mm', 5000);
        $svc = $this->stubPayments(['verify' => ['ok' => true, 'status' => 'success', 'amount' => 100, 'currency' => 'NGN', 'meta' => []]]);

        $res = $this->controller($svc)->callback($this->getCallback('AFG-mm'), new Response());

        $this->assertStringContainsString('/partner?pay=failed', $res->getHeaderLine('Location'));
        $this->assertSame('pending', DB::table('gates_donations')->where('payment_ref', 'AFG-mm')->value('status'));
    }

    public function test_callback_marks_failed_on_failed_verify(): void
    {
        $this->seedPending('AFG-f', 5000);
        $svc = $this->stubPayments(['verify' => ['ok' => true, 'status' => 'failed', 'amount' => 0, 'currency' => 'NGN', 'meta' => []]]);

        $this->controller($svc)->callback($this->getCallback('AFG-f'), new Response());

        $this->assertSame('failed', DB::table('gates_donations')->where('payment_ref', 'AFG-f')->value('status'));
    }

    public function test_callback_leaves_pending_when_gateway_still_pending(): void
    {
        $this->seedPending('AFG-p', 5000);
        $svc = $this->stubPayments(['verify' => ['ok' => true, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN', 'meta' => []]]);

        $this->controller($svc)->callback($this->getCallback('AFG-p'), new Response());

        // Not demoted — a later webhook can still confirm.
        $this->assertSame('pending', DB::table('gates_donations')->where('payment_ref', 'AFG-p')->value('status'));
    }

    public function test_confirmation_is_idempotent_no_double_credit(): void
    {
        $this->seedPending('AFG-idem', 5000);
        $svc = $this->stubPayments(['verify' => ['ok' => true, 'status' => 'success', 'amount' => 5000, 'currency' => 'NGN', 'meta' => []]]);
        $ctrl = $this->controller($svc);

        // Two callbacks + a webhook for the same reference.
        $ctrl->callback($this->getCallback('AFG-idem'), new Response());
        $ctrl->callback($this->getCallback('AFG-idem'), new Response());

        // Still exactly one confirmed row; status stays confirmed; nothing duplicated.
        $rows = DB::table('gates_donations')->where('payment_ref', 'AFG-idem')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('confirmed', $rows[0]->status);
        $this->assertSame(0, (int) $rows[0]->votes_used); // crediting bonus votes is a separate, later step
    }

    public function test_callback_unknown_reference_redirects_error(): void
    {
        $res = $this->controller($this->stubPayments())->callback($this->getCallback('AFG-missing'), new Response());
        $this->assertStringContainsString('/partner?pay=error', $res->getHeaderLine('Location'));
    }

    // ───────────────────────────── /pay/webhook ─────────────────────────────

    private function webhookReq(string $rawBody, array $headers): Req
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://afg.local/pay/webhook');
        $req = $req->withBody((new StreamFactory())->createStream($rawBody));
        foreach ($headers as $k => $v) { $req = $req->withHeader($k, $v); }
        return $req;
    }

    public function test_webhook_confirms_with_valid_paystack_signature(): void
    {
        $this->seedPending('AFG-wh', 5000);
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'AFG-wh', 'status' => 'success', 'amount' => 500000]]);
        $sig = hash_hmac('sha512', $raw, $_ENV['PAYSTACK_SECRET_KEY']);

        $svc = $this->stubPayments(['verify' => ['ok' => true, 'status' => 'success', 'amount' => 5000, 'currency' => 'NGN', 'meta' => []]]);
        $res = $this->controller($svc)->webhook($this->webhookReq($raw, ['x-paystack-signature' => $sig]), new Response());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('confirmed', DB::table('gates_donations')->where('payment_ref', 'AFG-wh')->value('status'));
    }

    public function test_webhook_rejects_bad_paystack_signature(): void
    {
        $this->seedPending('AFG-bad', 5000);
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'AFG-bad']]);

        $svc = $this->stubPayments(['verify' => ['ok' => true, 'status' => 'success', 'amount' => 5000, 'currency' => 'NGN', 'meta' => []]]);
        $res = $this->controller($svc)->webhook($this->webhookReq($raw, ['x-paystack-signature' => 'deadbeef']), new Response());

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('pending', DB::table('gates_donations')->where('payment_ref', 'AFG-bad')->value('status'));
    }

    public function test_webhook_confirms_with_valid_flutterwave_hash(): void
    {
        $_ENV['FLUTTERWAVE_SECRET_KEY'] = 'FLWSECK_TEST-x'; // enable FW for this test
        $this->seedPending('AFG-fw', 25000);
        $raw = json_encode(['event' => 'charge.completed', 'data' => ['tx_ref' => 'AFG-fw', 'status' => 'successful', 'amount' => 25000]]);

        $svc = $this->stubPayments(['enabled' => ['flutterwave'], 'verify' => ['ok' => true, 'status' => 'success', 'amount' => 25000, 'currency' => 'NGN', 'meta' => []]]);
        $res = $this->controller($svc)->webhook($this->webhookReq($raw, ['verif-hash' => 'fw-shared-hash']), new Response());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('confirmed', DB::table('gates_donations')->where('payment_ref', 'AFG-fw')->value('status'));
    }

    public function test_webhook_rejects_wrong_flutterwave_hash(): void
    {
        $_ENV['FLUTTERWAVE_SECRET_KEY'] = 'FLWSECK_TEST-x';
        $this->seedPending('AFG-fw2', 25000);
        $raw = json_encode(['event' => 'charge.completed', 'data' => ['tx_ref' => 'AFG-fw2']]);

        $svc = $this->stubPayments(['enabled' => ['flutterwave']]);
        $res = $this->controller($svc)->webhook($this->webhookReq($raw, ['verif-hash' => 'WRONG']), new Response());

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('pending', DB::table('gates_donations')->where('payment_ref', 'AFG-fw2')->value('status'));
    }

    public function test_webhook_unknown_provider_404(): void
    {
        $res = $this->controller($this->stubPayments())->webhook($this->webhookReq('{}', []), new Response());
        $this->assertSame(404, $res->getStatusCode());
    }
}
