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
            // `$planCode` mirrors the parent's signature, which grew it when monthly giving
            // arrived. A stub that drops a parameter the parent has is a fatal
            // incompatibility, not a looser contract — and it is recorded here so the plan
            // reaches assertions rather than being silently dropped by the double.
            public string $sawPlan = '';
            public function initialize(string $provider, int $amountNaira, string $email, string $reference, string $callbackUrl, array $meta = [], string $planCode = ''): array {
                $this->sawPlan = $planCode;
                return $this->opts['init'] ?? ['ok' => true, 'checkout_url' => 'https://checkout.paystack.com/' . $reference, 'message' => 'ok'];
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

        // SAME-ORIGIN, not the gateway. A 302 from a form POST to a gateway host is
        // governed by `form-action`, and a policy without the gateways blocks the POST in
        // the browser before any PHP runs — which is what "it does not redirect to
        // Paystack" was. The buyer reaches the gateway on the next hop instead; see
        // GatewayHandoff and GatewayHandoffTest.
        $this->assertSame(302, $res->getStatusCode());
        $location = $res->getHeaderLine('Location');
        $this->assertStringContainsString('/pay/redirect?ref=AFG-', $location);
        $this->assertStringNotContainsString('paystack.com', $location,
            'the gateway URL must not travel in a redirect the browser attributes to a form');

        $row = DB::table('gates_donations')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        // ₦5,000 / 35 votes comes from the SERVER table, not the request.
        $this->assertSame(5000, (int) $row->amount_naira);
        $this->assertSame(35, (int) $row->bonus_votes);
        $this->assertStringStartsWith('AFG-', (string) $row->payment_ref);
    }

    /**
     * …and the next hop delivers the gateway. Without this the test above would pass on a
     * change that redirected same-origin and then went nowhere.
     */
    public function test_the_same_origin_hop_then_delivers_the_gateway(): void
    {
        $_SESSION = [];
        $ctl = $this->controller($this->stubPayments());
        $res = $ctl->init(
            $this->postInit(['provider' => 'paystack', 'purpose' => 'vote', 'tier' => 'champion', 'email' => 'a@b.io']),
            new Response()
        );
        $location = $res->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $hop = $ctl->handoff(
            (new ServerRequestFactory())->createServerRequest('GET', $location)->withQueryParams($q),
            new Response()
        );

        $this->assertSame(200, $hop->getStatusCode());
        $this->assertStringContainsString('checkout.paystack.com', $hop->getHeaderLine('Refresh'));
        // No JavaScript anywhere on the path between intent and money.
        $this->assertStringNotContainsString('<script', (string) $hop->getBody());
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

    // ── monthly giving: the deliveries that carry no reference of ours ──────

    private function signed(array $payload): \Psr\Http\Message\ServerRequestInterface
    {
        $raw = (string) json_encode($payload);

        return $this->webhookReq($raw, [
            'x-paystack-signature' => hash_hmac('sha512', $raw, $_ENV['PAYSTACK_SECRET_KEY']),
        ]);
    }

    /**
     * `subscription.create` carries a subscription code and an email token and NO reference.
     *
     * Run through the ordinary confirmation path it matches nothing, logs `unmatched`, and
     * the platform never learns the two values Paystack requires together to stop the
     * subscription — leaving a donor who wants out with nowhere to go but their bank.
     */
    public function test_webhook_records_a_new_subscription(): void
    {
        \AfricaGates\Services\RecurringGiving::start('amara@example.test', 'Amara Okonkwo',
                                                      5000, 'PLN_x', 'AFG-sub1');

        $res = $this->controller($this->stubPayments())->webhook($this->signed([
            'event' => 'subscription.create',
            'data'  => [
                'subscription_code' => 'SUB_live',
                'email_token'       => 'tok_live',
                'next_payment_date' => '2026-10-04T09:00:00.000Z',
                // KOBO, as Paystack quotes a plan everywhere.
                'plan'     => ['plan_code' => 'PLN_x', 'amount' => 500000],
                'customer' => ['email' => 'amara@example.test', 'customer_code' => 'CUS_1'],
                'metadata' => ['reference' => 'AFG-sub1'],
            ],
        ]), new Response());

        $this->assertSame(200, $res->getStatusCode());

        $row = DB::table('gates_donation_subscriptions')->where('first_ref', 'AFG-sub1')->first();
        $this->assertSame('active', (string) $row->status);
        $this->assertSame('SUB_live', (string) $row->subscription_code);
        $this->assertSame('tok_live', (string) $row->email_token,
            'without the email token the donor cannot be given a working stop button');
    }

    /**
     * THE SECOND MONTH. A recurring charge arrives with a reference PAYSTACK generated.
     *
     * This is exactly where it used to stop: no matching row, logged `unmatched`,
     * acknowledged with a 200. The money kept arriving in the bank and the platform's own
     * total stopped moving after month one.
     *
     * Note what identifies it: the PLAN and the payer. Paystack's `charge.success` for an
     * instalment carries the plan object and the customer, and leaves the subscription code
     * to the invoice events — so a handler keyed on the subscription code would miss every
     * real recurring charge while looking like it worked.
     */
    public function test_webhook_mints_a_donation_for_a_recurring_charge(): void
    {
        \AfricaGates\Services\RecurringGiving::start('amara@example.test', 'Amara Okonkwo',
                                                      5000, 'PLN_x', 'AFG-sub2');
        \AfricaGates\Services\RecurringGiving::activate('amara@example.test', 5000,
                                                         'SUB_live', 'tok_live', 'CUS_1', '', 'AFG-sub2');

        $charge = [
            'event' => 'charge.success',
            'data'  => [
                // Paystack's own, not ours. It matches nothing in gates_donations.
                'reference' => 'psk_0f3a91',
                'status'    => 'success',
                'amount'    => 500000,
                'paid_at'   => '2026-10-04 09:00:05',
                'plan'      => ['plan_code' => 'PLN_x', 'amount' => 500000],
                'customer'  => ['email' => 'amara@example.test'],
            ],
        ];

        $before = DB::table('gates_donations')->count();
        $res = $this->controller($this->stubPayments())->webhook($this->signed($charge), new Response());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($before + 1, DB::table('gates_donations')->count(),
            'the second month never reached the database');

        $d = DB::table('gates_donations')->where('payment_ref', 'psk_0f3a91')->first();
        $this->assertSame('confirmed', (string) $d->status);
        $this->assertSame(5000, (int) $d->amount_naira);

        // And the retry Paystack sends three minutes later mints nothing.
        $this->controller($this->stubPayments())->webhook($this->signed($charge), new Response());
        $this->assertSame($before + 1, DB::table('gates_donations')->count(),
            'a retried delivery minted a second gift');
    }

    /** The gateway saying it is stopped is what stops it here. */
    public function test_webhook_records_a_cancellation(): void
    {
        \AfricaGates\Services\RecurringGiving::start('amara@example.test', 'A', 5000, 'PLN_x', 'AFG-sub3');
        \AfricaGates\Services\RecurringGiving::activate('amara@example.test', 5000,
                                                         'SUB_live', 'tok_live', 'CUS_1', '', 'AFG-sub3');

        $this->controller($this->stubPayments())->webhook($this->signed([
            'event' => 'subscription.disable',
            'data'  => ['subscription_code' => 'SUB_live'],
        ]), new Response());

        $this->assertSame('cancelled', (string) DB::table('gates_donation_subscriptions')
            ->where('subscription_code', 'SUB_live')->value('status'));
    }

    /**
     * A charge on a plan this platform never arranged is still not ours.
     *
     * The Paystack account is shared by every product here and one webhook URL serves all of
     * them; a handler that minted a donation for any plan-bearing charge would invent
     * donations out of somebody else's subscription.
     */
    public function test_webhook_ignores_a_recurring_charge_on_an_unknown_plan(): void
    {
        $before = DB::table('gates_donations')->count();

        $res = $this->controller($this->stubPayments())->webhook($this->signed([
            'event' => 'charge.success',
            'data'  => [
                'reference' => 'psk_stranger', 'status' => 'success', 'amount' => 500000,
                'plan'      => ['plan_code' => 'PLN_not_ours'],
                'customer'  => ['email' => 'someone@example.test'],
            ],
        ]), new Response());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($before, DB::table('gates_donations')->count(),
            'a donation was invented from somebody else\'s subscription');
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
