<?php
declare(strict_types=1);
namespace AfricaGates\Support;

/**
 * The one way to read configuration, because `$_ENV` alone does not see it.
 *
 * PHP's default `variables_order` is `GPCS` — no `E` — so `$_ENV` is NOT
 * populated from the process environment. It is populated by phpdotenv, which
 * writes the parsed `.env` file into it. The consequence, verified on this
 * runtime:
 *
 *     DB_PASS=secret php -r '... $_ENV["DB_PASS"] ...'
 *       $_ENV[DB_PASS]    → NULL
 *       $_SERVER[DB_PASS] → 'secret'
 *       getenv(DB_PASS)   → 'secret'
 *
 * So a deployment that supplies configuration as real environment variables —
 * which is how Docker, Kubernetes, systemd `Environment=`, PHP-FPM `env[]`,
 * Apache `SetEnv`, and every managed PaaS inject secrets — was invisible to this
 * application. Ninety-seven reads across thirty-nine keys silently fell back to
 * their hardcoded defaults.
 *
 * It is worse than a plain miss, because phpdotenv's IMMUTABLE writer refuses to
 * overwrite a name that is already defined anywhere it can see, and it CAN see
 * `$_SERVER`. So a real environment variable also SUPPRESSES the `.env` value it
 * was meant to override, leaving `$_ENV` with neither:
 *
 *     .env: SESSION_SECURE=0    real env: SESSION_SECURE=1
 *       $_ENV[SESSION_SECURE]    → NULL      ← both lost, default applies
 *       $_SERVER[SESSION_SECURE] → '1'
 *
 * Reading all three sources in this order restores the intended precedence: an
 * explicit environment variable wins over the file, and the file wins over the
 * hardcoded default.
 *
 * Nothing is cached. Values are read live on every call so that tests (and the
 * admin Settings fallbacks that write `$_ENV` at runtime) stay authoritative,
 * and because thirty-nine array lookups per request costs nothing worth saving.
 */
final class Env
{
    /**
     * Truthy spellings accepted for a boolean flag.
     *
     * Unified deliberately. Three call sites parsed flags by hand and they did
     * not agree: SmsService accepted `on`, while TRUST_PROXY and SESSION_SECURE
     * did not — so `TRUST_PROXY=on` read as false, which is the difference
     * between rate-limiting per visitor and rate-limiting the whole continent
     * as one bucket behind a CDN.
     */
    private const TRUE = ['1', 'true', 'yes', 'on'];
    private const FALSE = ['0', 'false', 'no', 'off'];

    /**
     * Raw string value, or $default when unset or blank-after-trim.
     *
     * Blank counts as unset on purpose: `DB_PASS=` in a `.env` is how operators
     * comment a value out, and a commented-out override must not beat the
     * default it was commenting out.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $raw = self::raw($key);
        return $raw === null ? $default : $raw;
    }

    /** Is the key set to a non-blank value in any source? */
    public static function has(string $key): bool
    {
        return self::raw($key) !== null;
    }

    /**
     * Boolean flag. Unset, blank, or unrecognised → $default.
     *
     * Unrecognised falls back rather than guessing: `TRUST_PROXY=maybe` should
     * behave like "not configured", not like true-because-non-empty.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        return self::truthy(self::raw($key), $default);
    }

    /**
     * The same flag spellings, for a value that did not come from the environment.
     *
     * Admin Settings rows shadow several env flags, so the same string has to be
     * interpreted the same way whether an operator typed it into a `.env` or into
     * the settings form. Exposing one parser is what stops the two from drifting
     * apart again.
     */
    public static function truthy(?string $raw, bool $default = false): bool
    {
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $v = strtolower(trim($raw));
        if (in_array($v, self::TRUE, true)) {
            return true;
        }
        if (in_array($v, self::FALSE, true)) {
            return false;
        }
        return $default;
    }

    /** Integer value. Unset or non-numeric → $default. */
    public static function int(string $key, int $default = 0): int
    {
        $raw = self::raw($key);
        return ($raw === null || !is_numeric($raw)) ? $default : (int) $raw;
    }

    /**
     * First non-blank value across $_ENV, $_SERVER, getenv() — or null.
     *
     * `HTTP_`-prefixed names are never read from `$_SERVER`. Request headers land
     * there as `HTTP_<NAME>`, so without that guard a caller asking for a config
     * key named `HTTP_...` would be reading a value the client chose. No current
     * key is affected; the guard is here so adding one cannot introduce the hole.
     */
    private static function raw(string $key): ?string
    {
        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            $v = trim((string) $_ENV[$key]);
            if ($v !== '') {
                return $v;
            }
        }
        if (!str_starts_with($key, 'HTTP_') && isset($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
            $v = trim((string) $_SERVER[$key]);
            if ($v !== '') {
                return $v;
            }
        }
        $v = getenv($key);
        if (is_string($v)) {
            $v = trim($v);
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }
}
