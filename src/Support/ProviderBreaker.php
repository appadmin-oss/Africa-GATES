<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Stop paying a full timeout for a provider that cannot be reached at all.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PRODUCTION FAULT THIS EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Groq is pinned first for every AI feature, and on this deployment the host cannot
 * reach api.groq.com at all — the admin health check reports `HTTP 0`, which is the
 * request never leaving the server (DNS, or the account's outbound firewall).
 *
 * `HTTP 0` was classified as TRANSIENT, so every single call did this:
 *
 *     Groq attempt 1 → 6s timeout
 *     backoff        → 0.3s
 *     Groq attempt 2 → 6s timeout
 *     ...only now does the chain try Gemini
 *
 * Twelve and a half seconds burned on a certainty, before the provider that might
 * actually answer is contacted. A Gee turn makes more than one model call, so the
 * request exceeded the front end's patience long before any answer existed. That is
 * why "Gee is never able to respond" was true even where a second key was configured:
 * the fallback chain was correct and simply never got the time to run.
 *
 * ── WHY A BREAKER AND NOT JUST A SHORTER TIMEOUT ─────────────────────────────
 *
 * A shorter timeout punishes every slow-but-working call to buy back time from a
 * broken one. The right distinction is not fast/slow, it is reachable/unreachable —
 * and unreachable is a fact that stays true for minutes, so it should be learned once
 * rather than rediscovered on every request.
 *
 * ── DELIBERATELY ONLY `HTTP 0` ───────────────────────────────────────────────
 *
 * Not 401, not 429, not 5xx. Those are the provider ANSWERING, which proves the
 * network path works, and each has its own correct handling. Tripping on them would
 * turn a momentary rate-limit into five minutes of a provider being skipped — taking
 * a feature down to save a few hundred milliseconds.
 *
 * ── AND IT CAN NEVER BE THE REASON AI IS DEAD ────────────────────────────────
 *
 * {@see AiService::resolveRoute()} drops open-circuit providers ONLY while at least
 * one hop survives. If every provider is open, the breaker is ignored and all of them
 * are tried. A cache row must never be the thing that makes the platform unable to
 * think — the worst this may ever do is reorder attempts.
 */
final class ProviderBreaker
{
    /** How long an unreachable provider stays skipped. */
    public const OPEN_SECONDS = 300;

    /**
     * Within-request memo, so one page load asking twice costs one query.
     *
     * Also the whole store when the cache table cannot be read: losing the breaker
     * between requests is a performance regression, and throwing would be an outage,
     * so the degraded mode is the quiet one.
     *
     * @var array<string,int> provider => unix time the circuit reopens
     */
    private static array $memo = [];

    private static function key(string $provider): string
    {
        return 'ai_breaker:' . strtolower(trim($provider));
    }

    /** Record that a provider could not be reached at the network level. */
    public static function open(string $provider, int $seconds = self::OPEN_SECONDS): void
    {
        $until = time() + max(1, $seconds);
        self::$memo[strtolower(trim($provider))] = $until;

        try {
            $key = self::key($provider);
            $row = ['cache_key' => $key, 'payload' => (string) $until,
                    'expires_at' => Carbon::createFromTimestamp($until)->toDateTimeString()];
            // Update-then-insert rather than updateOrInsert: two concurrent requests
            // both discovering the same dead provider must not race into a duplicate
            // key error on a table with a unique cache_key.
            if (DB::table('gates_cache')->where('cache_key', $key)->update($row) === 0) {
                DB::table('gates_cache')->insertOrIgnore($row);
            }
        } catch (\Throwable) {
            // In-process only. Still worth having: a single request making several
            // model calls stops paying the timeout on every one of them.
        }
    }

    /** Is this provider currently being skipped? */
    public static function isOpen(string $provider): bool
    {
        $p = strtolower(trim($provider));

        if (isset(self::$memo[$p])) {
            if (self::$memo[$p] > time()) return true;
            unset(self::$memo[$p]);
            return false;
        }

        try {
            $row = DB::table('gates_cache')->where('cache_key', self::key($provider))->first();
            if (!$row) return false;

            $until = (int) ($row->payload ?? 0);
            if ($until <= time()) return false;

            self::$memo[$p] = $until;
            return true;
        } catch (\Throwable) {
            // Unreadable cache means no knowledge, and no knowledge means TRY. Failing
            // closed here would skip a healthy provider over a database hiccup.
            return false;
        }
    }

    /**
     * Forget a provider's breaker — used by the admin health check.
     *
     * Somebody pressing "Test AI now" is asking what happens if we try RIGHT NOW.
     * Answering from a five-minute-old breaker would report a provider as failing
     * seconds after the host fixed the firewall, and send them chasing a fault that
     * no longer exists.
     */
    public static function clear(string $provider): void
    {
        unset(self::$memo[strtolower(trim($provider))]);
        try { DB::table('gates_cache')->where('cache_key', self::key($provider))->delete(); }
        catch (\Throwable) {}
    }

    /** Drop every breaker. */
    public static function clearAll(): void
    {
        self::$memo = [];
        try { DB::table('gates_cache')->where('cache_key', 'LIKE', 'ai_breaker:%')->delete(); }
        catch (\Throwable) {}
    }

    /** Does this failure text mean the request never reached the provider? */
    public static function isUnreachable(string $error): bool
    {
        // The exact string httpPost() writes on a connection-level failure. Matched
        // narrowly on purpose: "HTTP 0" is cURL reporting no response at all, and it
        // must not be confused with a 0 appearing inside a provider's own message.
        return (bool) preg_match('~\bHTTP 0\b~', $error);
    }
}
