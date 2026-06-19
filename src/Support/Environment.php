<?php
declare(strict_types=1);
namespace AfricaGates\Support;

/**
 * Production-safety decisions about the runtime environment.
 *
 * The single most dangerous misconfiguration is shipping a `.env` that still
 * says APP_ENV=development to a public host — that would leak PHP stack traces
 * and exception messages to every visitor. We therefore gate verbose error
 * output on THREE independent conditions, so no single stale value can open it:
 * debug must be on, the env must not be production/demo, AND the request must
 * be served from a local host. On any real domain, details stay hidden.
 */
final class Environment
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /** A request with no Host (CLI, health probe) or a local/dev hostname. */
    public static function isLocalHost(?string $host): bool
    {
        if ($host === null || $host === '') {
            return true; // CLI or host-less request — never a public visitor
        }
        $h = strtolower($host);
        // Normalise away an optional :port, being careful with IPv6 forms.
        if ($h[0] === '[') {                      // bracketed IPv6: [::1] or [::1]:8000
            $end = strpos($h, ']');
            if ($end !== false) {
                $h = substr($h, 1, $end - 1);
            }
        } elseif (substr_count($h, ':') === 1) {  // host:port (IPv4 / hostname)
            $h = substr($h, 0, (int) strpos($h, ':'));
        }
        // A bare IPv6 (multiple colons, no brackets) is left untouched.
        if (in_array($h, self::LOCAL_HOSTS, true)) {
            return true;
        }
        return str_ends_with($h, '.local')
            || str_ends_with($h, '.test')
            || str_ends_with($h, '.localhost');
    }

    /**
     * May the app show full error details (stack traces / exception messages)?
     * Only in a debug-enabled, non-production/demo env, served from a local host.
     */
    public static function showErrorDetails(?string $appEnv, bool $appDebug, ?string $host): bool
    {
        if (!$appDebug) {
            return false;
        }
        if ($appEnv === 'production' || $appEnv === 'demo') {
            return false;
        }
        return self::isLocalHost($host);
    }
}
