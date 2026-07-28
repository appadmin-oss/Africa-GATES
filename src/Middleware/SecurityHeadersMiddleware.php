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
        return $handler->handle($req)
            ->withHeader('X-Frame-Options','SAMEORIGIN')
            ->withHeader('X-Content-Type-Options','nosniff')
            ->withHeader('X-XSS-Protection','0') // deprecated; CSP supersedes it
            ->withHeader('Referrer-Policy','strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy','geolocation=(), microphone=(), camera=(), interest-cohort=()')
            ->withHeader('Content-Security-Policy', \AfricaGates\Support\Csp::policy())
            ->withHeader('Strict-Transport-Security','max-age=63072000; includeSubDomains');
    }
}
