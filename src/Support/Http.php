<?php
declare(strict_types=1);
namespace AfricaGates\Support;

/**
 * Response-header plumbing that has to happen before PHP decides for itself.
 */
final class Http
{
    /**
     * Stop `session_start()` from emitting its own caching headers.
     *
     * PHP's `session.cache_limiter` defaults to `nocache`, which sends three headers
     * the moment a session starts:
     *
     *     Expires: Thu, 19 Nov 1981 08:52:00 GMT
     *     Pragma: no-cache
     *     Cache-Control: no-store, no-cache, must-revalidate
     *
     * They go out through `header()`, so a PSR-7 response object cannot see them and
     * middleware cannot reason about them. {@see \AfricaGates\Middleware\SecurityHeadersMiddleware}
     * set `Cache-Control` believing none was present, Slim's emitter replaced PHP's
     * value with the weaker one, and `Expires` and `Pragma` were left behind saying
     * the opposite. The result on the wire was `Pragma: no-cache` next to
     * `Cache-Control: private` — a response asserting both that it must never be
     * stored and that a browser may store it.
     *
     * Emptying the limiter is the only way to make the middleware authoritative.
     * Nothing is lost: the middleware sends a stricter, coherent policy, and `Pragma`
     * and `Expires` are HTTP/1.0 constructs that any cache written this century
     * ignores in the presence of `Cache-Control`.
     *
     * MUST be called before `session_start()`. After it, the headers are already sent.
     */
    public static function disableSessionCacheLimiter(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            session_cache_limiter('');
        }
    }
}
