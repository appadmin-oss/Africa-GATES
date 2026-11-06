<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Middleware\SecurityHeadersMiddleware;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * An error response is still a page, and it must still carry the policy.
 *
 * WHAT WAS MEASURED. A request for a URL that does not exist, served by the app
 * exactly as public/index.php assembles it:
 *
 *     $ curl -sD - http://127.0.0.1/definitely-not-a-real-page-xyz
 *     HTTP/1.1 404 Not Found
 *     Content-type: text/html; charset=UTF-8
 *     Set-Cookie: PHPSESSID=…
 *     X-Powered-By: PHP/8.4.19
 *
 * That is the whole header list. No Content-Security-Policy, and none of the six
 * headers in {@see SecurityHeadersMiddleware::SHARED}. The body was the full site
 * layout: 11 inline <script> blocks, each carrying `nonce="qKclccxeR0BLRIw3RADUWA=="`
 * — a nonce with no policy on the other side to honour it.
 *
 * WHY IT HAPPENS. Slim runs middleware LIFO: the last one added is the outermost.
 * public/index.php adds SecurityHeadersMiddleware, and then adds the error
 * middleware AFTER it. So the error middleware wraps the security headers rather
 * than the other way round, and an exception — or a 404, which Slim raises as
 * HttpNotFoundException — is caught OUTSIDE the layer that adds the headers. The
 * error response is built and returned without ever passing back through it.
 *
 * WHY .htaccess DOES NOT COVER IT. The six shared headers are duplicated in
 * public/.htaccess with `Header always set`, and `always` does apply to error
 * responses — so on Apache those six survive. The CSP is deliberately NOT in
 * .htaccess, because it carries a per-request nonce that a static file cannot
 * contain (the reasoning is written out at length in public/.htaccess). The
 * consequence, which that reasoning did not follow through to: the error path is
 * the one case with no CSP from either source. Every 404, every 500 and every
 * rejected-CSRF page on production is served with no Content-Security-Policy.
 *
 * WHY IT MATTERS RATHER THAN BEING TIDY. An error page is the classic reflection
 * surface — it exists to say something about the request that failed — and it is
 * the one page an attacker can always reach without authenticating. It is also the
 * page most likely to be reached while something else is already going wrong.
 */
class CspErrorResponseTest extends TestCase
{
    /**
     * The middleware stack from public/index.php, in the same order, reduced to the
     * two layers whose ORDER is the subject here. Routing is added so that an
     * unmatched path raises HttpNotFoundException the way it does in production.
     */
    private function app(bool $securityOutsideErrors): \Slim\App
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();

        if ($securityOutsideErrors) {
            // Fixed order: errors are caught INSIDE, so the response still passes
            // back out through the security headers.
            $app->addErrorMiddleware(false, false, false);
            $app->add(new SecurityHeadersMiddleware());
        } else {
            // The order public/index.php used: security headers added first, error
            // middleware added after ⇒ error middleware is outermost.
            $app->add(new SecurityHeadersMiddleware());
            $app->addErrorMiddleware(false, false, false);
        }

        $app->get('/ok', fn($rq, $rs) => $rs);
        return $app;
    }

    private function headersFor(\Slim\App $app, string $path): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path);
        return $app->handle($req)->getHeaders();
    }

    /** A page that routes normally has always been fine — this is the control. */
    public function test_a_successful_response_carries_the_policy(): void
    {
        $h = $this->headersFor($this->app(false), '/ok');
        $this->assertArrayHasKey('Content-Security-Policy', $h);
    }

    /**
     * The regression itself. With the error middleware outermost, a 404 is returned
     * with no policy — this asserts the FIXED order, so it fails on the old wiring.
     */
    public function test_a_404_carries_the_policy(): void
    {
        $h = $this->headersFor($this->app(true), '/no-such-page');
        $this->assertArrayHasKey(
            'Content-Security-Policy',
            $h,
            'A 404 was served with no CSP. Slim runs middleware LIFO, so an error '
            . 'middleware added AFTER SecurityHeadersMiddleware wraps it and the '
            . 'error response never passes back through it.'
        );
    }

    /** The six shared headers have the same fate on the error path, for the same reason. */
    public function test_a_404_carries_the_shared_security_headers(): void
    {
        $h = $this->headersFor($this->app(true), '/no-such-page');
        foreach (array_keys(SecurityHeadersMiddleware::SHARED) as $name) {
            $this->assertArrayHasKey($name, $h, "A 404 was served without $name.");
        }
    }

    /**
     * Proof that the ordering is the cause and not an incidental detail: the same
     * request through the old order has no policy. If a later refactor makes the
     * bypass impossible by construction, this test is the one to delete.
     */
    public function test_the_old_order_is_what_dropped_the_policy(): void
    {
        $h = $this->headersFor($this->app(false), '/no-such-page');
        $this->assertArrayNotHasKey(
            'Content-Security-Policy',
            $h,
            'The old ordering no longer reproduces the bypass — if the framework or '
            . 'wiring changed so this can no longer happen, delete this test.'
        );
    }
}
