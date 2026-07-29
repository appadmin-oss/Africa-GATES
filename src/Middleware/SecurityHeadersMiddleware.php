<?php
declare(strict_types=1);
namespace AfricaGates\Middleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class SecurityHeadersMiddleware {
    /**
     * Defense-in-depth headers. The CSP itself lives in {@see \AfricaGates\Support\Csp},
     * which also serves the per-request nonce to Twig — one holder, because a header
     * and a template presenting different nonces would kill every script on the page.
     *
     * What changed and why is documented there. The short version: `script-src` used
     * to be `'unsafe-inline' 'unsafe-eval' https:`, which permits any injected script
     * and any script host on the internet, so it protected nothing. It is now nonce
     * based with an explicit host allowlist.
     *
     * form-action MUST include the payment gateways: POST /donate (and the shop
     * checkout) redirect to the gateway's HOSTED checkout URL, and browsers enforce
     * form-action against the redirect target of a form submission — so 'self' alone
     * silently blocks every donation/checkout that hands off to Paystack or
     * Flutterwave. The same hosts are allowed in frame-src for the gateways' inline
     * (iframe) modal mode.
     */
    public function __invoke(Request $req, Handler $handler): Response {
        $res = $handler->handle($req);

        // A CSP nonce is a per-request secret and is worth nothing if it is reused.
        // Nothing in this application caches rendered HTML — checked: Twig's `cache`
        // option compiles templates rather than storing output, and CacheService holds
        // data arrays, not markup. But no HTML response set Cache-Control at all,
        // which leaves a SHARED cache (a CDN, a reverse proxy, a corporate appliance)
        // free to store a page by heuristic and hand it to someone else. Whoever can
        // read that cached page then knows a nonce the page keeps advertising, and can
        // inject a script it will accept.
        //
        // MEASURED SCOPE, so this is not oversold: PHP's own session.cache_limiter is
        // `nocache` by default, so any page that has called session_start() ALREADY
        // sends `no-store, no-cache, must-revalidate` — verified over HTTP. Most pages
        // here do. This therefore closes the remaining case: an HTML response rendered
        // without a session, which would otherwise carry a nonce and no cache policy
        // at all.
        //
        // `private` is the proportionate choice: shared caches must not store it, while
        // the browser may still cache its own copy — harmless, because a single
        // response's header and body always carry the SAME nonce, so a
        // browser-cached page stays internally consistent.
        //
        // Applied only to HTML, and only when nothing has set Cache-Control already:
        // the JSON and setup routes deliberately send `no-store` and MediaController
        // sends its own policy, so overriding them would be a regression dressed as a
        // fix. An empty Content-Type counts as HTML because Slim has not always set it
        // by the time this middleware runs.
        $type = strtolower($res->getHeaderLine('Content-Type'));
        if (($type === '' || str_contains($type, 'text/html')) && !$res->hasHeader('Cache-Control')) {
            $res = $res->withHeader('Cache-Control', 'private');
        }

        return $res
            ->withHeader('X-Frame-Options','SAMEORIGIN')
            ->withHeader('X-Content-Type-Options','nosniff')
            ->withHeader('X-XSS-Protection','0') // deprecated; CSP supersedes it
            ->withHeader('Referrer-Policy','strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy','geolocation=(), microphone=(), camera=(), interest-cohort=()')
            ->withHeader('Content-Security-Policy', \AfricaGates\Support\Csp::policy())
            ->withHeader('Strict-Transport-Security','max-age=63072000; includeSubDomains');
    }
}
