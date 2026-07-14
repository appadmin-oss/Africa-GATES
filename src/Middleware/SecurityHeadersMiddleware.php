<?php
declare(strict_types=1);
namespace AfricaGates\Middleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class SecurityHeadersMiddleware {
    /**
     * Defense-in-depth CSP. Inline scripts/handlers (Alpine x-data, inline
     * <script> blocks) and the Stripe-gradient eval require 'unsafe-inline'
     * /'unsafe-eval'; we still lock object-src, base-uri, frame-ancestors and
     * form-action, and restrict frames to known origins so foreign resource
     * injection is blocked.
     *
     * form-action MUST include the payment gateways: POST /donate (and the shop
     * checkout) redirect to the gateway's HOSTED checkout URL, and browsers
     * enforce form-action against the redirect target of a form submission — so
     * 'self' alone silently blocks every donation/checkout that hands off to
     * Paystack or Flutterwave. The same hosts are allowed in frame-src for the
     * gateways' inline (iframe) modal mode.
     */
    private const PAY_HOSTS = "https://*.paystack.com https://*.paystack.co https://*.flutterwave.com https://flutterwave.com";

    private const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; "
        . "style-src 'self' 'unsafe-inline' https:; "
        . "img-src 'self' data: blob: https:; "
        . "font-src 'self' data: https:; "
        . "connect-src 'self' https:; "
        . "media-src 'self' https:; "
        . "frame-src https://challenges.cloudflare.com https://www.youtube.com https://www.youtube-nocookie.com https://my.spline.design https://prod.spline.design https://googleads.g.doubleclick.net https://tpc.googlesyndication.com https://www.google.com " . self::PAY_HOSTS . "; "
        . "object-src 'none'; base-uri 'self'; form-action 'self' " . self::PAY_HOSTS . "; frame-ancestors 'self'";

    public function __invoke(Request $req, Handler $handler): Response {
        return $handler->handle($req)
            ->withHeader('X-Frame-Options','SAMEORIGIN')
            ->withHeader('X-Content-Type-Options','nosniff')
            ->withHeader('X-XSS-Protection','0') // deprecated; CSP supersedes it
            ->withHeader('Referrer-Policy','strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy','geolocation=(), microphone=(), camera=(), interest-cohort=()')
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('Strict-Transport-Security','max-age=63072000; includeSubDomains');
    }
}
