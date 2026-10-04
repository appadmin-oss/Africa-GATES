<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What kinds of money this platform may take, as CACENTRE finance defines them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS FETCHED AND NOT A `const`
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There used to be three streams in a class constant here: event tickets, shop orders, votes.
 * They were right until the organisation started taking membership fees, fines, dues and
 * training fees, each of which settles somewhere different and is accounted for differently.
 * None of them could be routed, because the list that decides what can be routed was in a file
 * that only a deployment can change.
 *
 * The list lives in finance now, because finance is who decides what a kind of money IS. A
 * stream added there this morning is routable here this afternoon with no deployment, and
 * there is exactly one list rather than one per site drifting from the others. The penalty for
 * drift is not cosmetic: a code this platform invents is refused at settlement, days after the
 * money was taken, when nobody remembers which checkout wrote it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A CHECKOUT MUST NEVER DEPEND ON FINANCE BEING UP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This sits upstream of every payment, so it is written to degrade rather than fail. Three
 * answers, in order:
 *
 *   1 · the live catalogue, cached for the TTL finance itself nominates;
 *   2 · the LAST GOOD catalogue, however old, when finance cannot be reached — a stale list
 *       routes money the way it was routed yesterday, which is almost always right and is
 *       never worse than refusing to sell;
 *   3 · the three built-in streams, for a deployment with no CFIS credentials at all.
 *
 * Nothing here ever throws. {@see PaymentDestination::streams()} is called while rendering a
 * checkout, and an exception there is a buyer who cannot pay.
 */
final class CfisCatalogue
{
    /**
     * The streams this platform has always had.
     *
     * Not a default so much as a floor: these three predate CFIS, money is already attributed
     * to them, and a deployment that never configures finance must keep working exactly as it
     * did. Finance's own list contains them too — this is what answers before it is reachable.
     */
    public const BUILT_IN = [
        'events' => 'Event tickets',
        'shop'   => 'Shop orders',
        'votes'  => 'Votes and donations',
    ];

    private const CACHE_KEY = 'cfis_stream_catalogue';
    private const LAST_GOOD_KEY = 'cfis_stream_catalogue_last_good';
    private const DEFAULT_TTL = 900;
    private const LAST_GOOD_TTL = 10 * 365 * 86400;

    /** Within one request the answer cannot change, and a checkout asks more than once. */
    private static ?array $memo = null;

    /**
     * code => label, for every stream this platform may send.
     *
     * Always at least {@see BUILT_IN}. Ordered as finance returns it, with any built-in it did
     * not mention appended — a stream somebody has money against never disappears from the
     * admin screen because finance stopped listing it.
     */
    public static function streams(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $out = [];
        foreach (self::fetch() as $s) {
            $code = trim((string) ($s['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = trim((string) ($s['name'] ?? '')) ?: $code;
        }

        foreach (self::BUILT_IN as $code => $label) {
            $out[$code] ??= $label;
        }

        return self::$memo = $out;
    }

    /**
     * Everything finance said about one stream, or [] when it said nothing.
     *
     * The admin screen reads `expects_subaccount` from here so it can say WHICH streams finance
     * is waiting on a subaccount for, rather than making an operator guess which of fourteen
     * rows matter.
     */
    public static function describe(string $code): array
    {
        foreach (self::fetch() as $s) {
            if (($s['code'] ?? null) === $code) {
                return $s;
            }
        }

        return [];
    }

    /** Is this a code finance will accept? Asked where a human picks or types one. */
    public static function knows(string $code): bool
    {
        return isset(self::streams()[$code]);
    }

    /** Drop the cache, so an operator who has just added a stream in finance can see it now. */
    public static function refresh(): void
    {
        self::$memo = null;
        self::forget(self::CACHE_KEY);
    }

    /**
     * The raw catalogue rows, cached.
     *
     * @return list<array<string,mixed>>
     */
    private static function fetch(): array
    {
        $cached = self::cached(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        $live = self::ask();
        if ($live !== null) {
            self::store(self::CACHE_KEY, $live, self::ttlFrom($live));
            /* Kept effectively forever, deliberately. This is the answer when finance is
               down, and a short expiry on it would mean the longer the outage the less this
               platform knows — exactly backwards. The far date is so CacheService::prune(),
               which deletes anything already expired, leaves it alone. */
            self::store(self::LAST_GOOD_KEY, $live, self::LAST_GOOD_TTL);

            return $live['streams'];
        }

        $lastGood = self::cached(self::LAST_GOOD_KEY);

        return $lastGood ?? [];
    }

    /**
     * Ask finance. Null on any failure at all — unreachable, refused, nonsense.
     *
     * @return array{streams: list<array<string,mixed>>, cache_seconds: int}|null
     */
    private static function ask(): ?array
    {
        $base = self::setting('cfis_url', 'CFIS_URL');
        $source = self::setting('cfis_source', 'CFIS_SOURCE');
        $secret = self::setting('cfis_secret', 'CFIS_SECRET');

        if ($base === '' || $source === '' || strlen($secret) < 32) {
            return null;
        }

        try {
            $token = self::mint($secret, []);
            $ch = curl_init(rtrim($base, '/') . '/api/streams/' . rawurlencode($source));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                /* Short on purpose. This runs in the path of a checkout; a finance box that is
                   merely slow must cost a buyer a moment, not the sale. */
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-CFIS-Token: ' . $token,
                ],
                CURLOPT_POSTFIELDS => json_encode(['token' => $token]),
            ]);
            $body = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error !== '' || $status !== 200) {
                error_log('[cfis] catalogue unavailable (' . ($error ?: 'HTTP ' . $status) . ') — using the last good list');

                return null;
            }

            $json = json_decode($body, true);
            if (!is_array($json) || empty($json['ok']) || !is_array($json['streams'] ?? null)) {
                error_log('[cfis] catalogue came back in a shape this does not understand');

                return null;
            }

            return [
                'streams' => array_values(array_filter($json['streams'], 'is_array')),
                'cache_seconds' => (int) ($json['cache_seconds'] ?? self::DEFAULT_TTL),
            ];
        } catch (\Throwable $e) {
            error_log('[cfis] catalogue: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Settings row, else env, else ''. The one reader of each of these three keys.
     *
     * Settings FIRST because there is no shell on production: a credential that can only
     * be read from `.env` is one nobody can set, and the screen then explains itself
     * correctly while the integration is dead — which is exactly how the Google Calendar
     * integration spent months. Never throws; no settings table yet (installer, CLI
     * before boot) is "not configured", which the fallback below is the whole point of.
     */
    private static function setting(string $settingKey, string $envKey): string
    {
        $v = null;
        try {
            $v = DB::table('gates_settings')->where('key_name', $settingKey)->value('value');
        } catch (\Throwable) {
            // See above: not an error.
        }
        $v = is_string($v) ? trim($v) : '';

        return $v !== '' ? $v : trim((string) \AfricaGates\Support\Env::get($envKey, ''));
    }

    /** The same signed envelope the settlement intake takes, so no new credential is needed. */
    private static function mint(string $secret, array $claims): string
    {
        $now = time();
        $payload = json_encode($claims + [
            'nonce' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + 300,
        ], JSON_UNESCAPED_SLASHES);

        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $body = $b64($payload);

        return 'v1.' . $body . '.' . $b64(hash_hmac('sha256', $body, $secret, true));
    }

    private static function ttlFrom(array $live): int
    {
        return max(60, min(86400, (int) $live['cache_seconds']));
    }

    /** @return list<array<string,mixed>>|null */
    private static function cached(string $key): ?array
    {
        try {
            $row = DB::table('gates_cache')->where('cache_key', $key)->first();
            if (!$row) {
                return null;
            }
            if (strtotime((string) $row->expires_at) < time()) {
                return null;
            }
            $v = json_decode((string) $row->payload, true);

            return is_array($v['streams'] ?? null) ? $v['streams'] : null;
        } catch (\Throwable) {
            // No cache table yet. Asking finance every time is slow, never wrong.
            return null;
        }
    }

    private static function store(string $key, array $live, int $ttl): void
    {
        try {
            DB::table('gates_cache')->updateOrInsert(['cache_key' => $key], [
                'payload' => json_encode($live, JSON_UNESCAPED_SLASHES),
                'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
                'tags' => 'cfis',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[cfis] could not cache the catalogue: ' . $e->getMessage());
        }
    }

    private static function forget(string $key): void
    {
        try {
            DB::table('gates_cache')->where('cache_key', $key)->delete();
        } catch (\Throwable) {
        }
    }
}
