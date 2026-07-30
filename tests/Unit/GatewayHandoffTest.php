<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GatewayHandoff;
use AfricaGates\Support\Csp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Handing a buyer to Paystack without the browser calling it a form submission.
 *
 * ── THE FAILURE ─────────────────────────────────────────────────────────────
 *
 *     Sending form data to 'https://afg.afrovanguard.org.ng/vote/paid/start' violates
 *     the following Content Security Policy directive: "form-action 'self'".
 *     The request has been blocked.
 *
 * The POST never leaves the browser: no pending order, no gateway call, nothing in any
 * server log. The reported symptom is "it does not redirect to Paystack" and the server
 * has no evidence the buyer was ever there. The quoted URL is SAME-ORIGIN, which is why
 * it reads like a browser bug — Chrome applies `form-action` to the whole redirect chain
 * of a submission, and `POST /vote/paid/start` answered `302 https://checkout.paystack.com/…`.
 *
 * `Csp::PAY_HOSTS` fixes it only on a deployment serving the current policy, and that
 * has repeatedly not been the case here. Which is the real problem this file guards
 * against: **a security header decided whether revenue worked**, with no server-side
 * trace when it said no.
 */
class GatewayHandoffTest extends TestCase
{
    private const PAYSTACK = 'https://checkout.paystack.com/abc123';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    private function request(string $ref): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://afg.test/vote/paid/redirect?ref=' . urlencode($ref))
            ->withQueryParams(['ref' => $ref]);
    }

    // ── The property that makes payments CSP-independent ────────────────────

    /**
     * The URL a form POST redirects to must be SAME-ORIGIN — that is the whole fix.
     * `'self'` is in every policy this site has ever served, including the stale one
     * still live in production, so the submission is never blocked again.
     */
    public function test_the_form_submission_only_ever_redirects_to_our_own_origin(): void
    {
        $to = GatewayHandoff::remember('AFG-PVOTE-x', self::PAYSTACK, 'https://afg.test/vote/paid/redirect', 'paystack');

        $host = parse_url($to, PHP_URL_HOST);
        $this->assertSame('afg.test', $host,
            'a form POST must never 302 to a gateway host — form-action governs that redirect');
        $this->assertStringNotContainsString('paystack.com', $to,
            'and the gateway URL must not travel in the query string either');
    }

    /**
     * The gateway URL is kept server-side, never in the query string.
     *
     * `?to=https://checkout.paystack.com/…` would be an open redirect the moment anything
     * about the validation is wrong, and it would be written into every proxy access log
     * beside a URL that authorises a live payment session.
     */
    public function test_the_checkout_url_never_appears_in_a_url(): void
    {
        $to = GatewayHandoff::remember('AFG-PVOTE-x', self::PAYSTACK, 'https://afg.test/vote/paid/redirect', 'paystack');

        $this->assertStringNotContainsString(self::PAYSTACK, $to);
        $this->assertStringNotContainsString('http', (string) parse_url($to, PHP_URL_QUERY));
    }

    /** The handoff page must not rely on JavaScript — three independent continuations. */
    public function test_the_page_redirects_without_javascript(): void
    {
        $res = GatewayHandoff::page(new Response(), self::PAYSTACK, 'Paystack', 'AFG-PVOTE-x');
        $html = (string) $res->getBody();

        // 1. An HTTP header, acted on before the HTML is parsed. Governed by no CSP directive.
        $this->assertStringContainsString('0;url=' . self::PAYSTACK, $res->getHeaderLine('Refresh'));
        // 2. meta refresh, for clients that ignore the header.
        $this->assertMatchesRegularExpression('~<meta http-equiv="refresh"[^>]*' . preg_quote(self::PAYSTACK, '~') . '~', $html);
        // 3. A real link — the no-JS path, and the escape hatch when an extension eats the hop.
        $this->assertMatchesRegularExpression('~<a href="' . preg_quote(self::PAYSTACK, '~') . '"~', $html);

        // And explicitly NOT a script: it would need a nonce, and a nonce is precisely
        // what the stale policies get wrong.
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_the_page_is_never_cached_or_indexed(): void
    {
        $res = GatewayHandoff::page(new Response(), self::PAYSTACK, 'Paystack', 'AFG-PVOTE-x');

        // It is single-use and it names a live payment session.
        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('noindex', (string) $res->getBody());
    }

    // ── Single use, and matched to its reference ─────────────────────────────

    public function test_a_handoff_is_single_use(): void
    {
        GatewayHandoff::remember('AFG-PVOTE-x', self::PAYSTACK, '/vote/paid/redirect', 'paystack');

        $this->assertSame(self::PAYSTACK, GatewayHandoff::take('AFG-PVOTE-x'));
        // A back-button return must not re-send a buyer to a checkout session for an order
        // they may already have completed.
        $this->assertNull(GatewayHandoff::take('AFG-PVOTE-x'), 'the second read must fail');
    }

    public function test_the_reference_must_match(): void
    {
        GatewayHandoff::remember('AFG-PVOTE-mine', self::PAYSTACK, '/vote/paid/redirect', 'paystack');

        $this->assertNull(GatewayHandoff::take('AFG-PVOTE-someone-elses'));
        $this->assertNull(GatewayHandoff::take(''), 'an empty reference must never match');
    }

    public function test_an_expired_handoff_is_refused(): void
    {
        GatewayHandoff::remember('AFG-PVOTE-x', self::PAYSTACK, '/vote/paid/redirect', 'paystack');
        // It exists only to survive one redirect; a stale one would send a buyer to a
        // checkout page for an order they abandoned an hour ago.
        $_SESSION['gateway_handoff']['at'] = time() - 4000;

        $this->assertNull(GatewayHandoff::take('AFG-PVOTE-x'));
    }

    // ── Only ever a real gateway ─────────────────────────────────────────────

    public function test_only_https_urls_on_a_known_gateway_host_are_accepted(): void
    {
        foreach ([
            'https://checkout.paystack.com/abc'          => true,
            'https://paystack.com/pay/x'                 => true,
            'https://checkout.flutterwave.com/v3/hosted' => true,
            'https://flutterwave.com/pay/x'              => true,
            // Refused: wrong scheme, wrong host, and the lookalikes.
            'http://checkout.paystack.com/abc'           => false,
            'https://checkout.paystack.com.evil.test/x'  => false,
            'https://evil.test/checkout.paystack.com'    => false,
            'https://paystack.com.attacker.test/x'       => false,
            'javascript:alert(1)'                        => false,
            '//checkout.paystack.com/abc'                => false,
            ''                                           => false,
        ] as $url => $expected) {
            $this->assertSame($expected, GatewayHandoff::isGatewayUrl($url), $url);
        }
    }

    public function test_a_non_gateway_url_in_the_session_is_refused_on_the_way_out(): void
    {
        // Not defence against an attacker — this application wrote it one request ago. It
        // is defence against sending a buyer somewhere unexpected if any upstream code is
        // ever wrong about what a gateway returned.
        $_SESSION['gateway_handoff'] = ['ref' => 'r', 'url' => 'https://evil.test/x', 'provider' => 'paystack', 'at' => time()];

        $this->assertNull(GatewayHandoff::take('r'));
    }

    /** The gateway list is the CSP's, so the two cannot drift apart. */
    public function test_the_allowed_hosts_come_from_the_csp(): void
    {
        $this->assertStringContainsString('paystack', Csp::PAY_HOSTS);
        $this->assertStringContainsString('flutterwave', Csp::PAY_HOSTS);
        // Adding a provider to PAY_HOSTS must be all it takes.
        foreach (['https://checkout.paystack.com/x', 'https://checkout.flutterwave.com/x'] as $u) {
            $this->assertTrue(GatewayHandoff::isGatewayUrl($u));
        }
    }

    // ── The label the buyer is shown ─────────────────────────────────────────

    public function test_the_provider_label_is_never_taken_from_the_request(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://afg.test/vote/paid/redirect')
            ->withQueryParams(['ref' => 'r', 'provider' => '<script>alert(1)</script>']);

        GatewayHandoff::remember('r', self::PAYSTACK, '/vote/paid/redirect', 'paystack');
        GatewayHandoff::take('r');

        // The page tells a buyer whose payment form they are about to see, on a page that
        // also names a live payment reference — so it must not be attacker-settable text.
        $this->assertSame('Paystack', GatewayHandoff::providerLabel());
        $this->assertSame('r', GatewayHandoff::reference($req->withQueryParams(['ref' => 'r'])));
    }

    public function test_the_page_escapes_everything_it_prints(): void
    {
        $res = GatewayHandoff::page(
            new Response(),
            'https://checkout.paystack.com/a"onload="alert(1)',
            'Pay"><script>x</script>',
            'REF"><b>'
        );
        $html = (string) $res->getBody();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onload="alert', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    // ── Wiring ───────────────────────────────────────────────────────────────

    /**
     * No checkout controller may 302 straight to a gateway again. That is the regression
     * that turns payments back off whenever the CSP is not the current one.
     */
    public function test_no_controller_redirects_a_form_post_straight_to_a_gateway(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') ?: [] as $file) {
            $src = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', (string) file_get_contents($file));
            if (preg_match("~redirect\(\s*\\\$res\s*,\s*\\\$init\['checkout_url'\]~", $src) === 1) {
                $offenders[] = basename($file);
            }
        }
        sort($offenders);
        $this->assertSame([], $offenders,
            "these hand a form submission straight to a gateway host, which `form-action` "
            . "blocks in the browser before any PHP runs:\n  " . implode("\n  ", $offenders));
    }

    public function test_every_checkout_path_has_a_same_origin_handoff_route(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        foreach ([
            '/vote/paid/redirect' => 'PaidVoteController',
            '/donate/redirect'    => 'DonationController',
            '/shop/redirect'      => 'ShopCheckoutController',
            '/pay/redirect'       => 'PaymentController',
        ] as $path => $controller) {
            $this->assertMatchesRegularExpression(
                '~' . preg_quote($path, '~') . "'?,?\s*.*" . $controller . ".*':handoff'~",
                $routes,
                $path . ' must be routed to ' . $controller . '::handoff'
            );
        }
    }
}
