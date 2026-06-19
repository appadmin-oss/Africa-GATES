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
     */
    private const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; "
        . "style-src 'self' 'unsafe-inline' https:; "
        . "img-src 'self' data: blob: https:; "
        . "font-src 'self' data: https:; "
        . "connect-src 'self' https:; "
        . "media-src 'self' https:; "
        . "frame-src https://challenges.cloudflare.com https://www.youtube.com https://www.youtube-nocookie.com https://my.spline.design https://prod.spline.design; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

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
