<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Who arrived, where from, and whether it led to anything.
 *
 * ── ONE ROW PER ARRIVAL ──────────────────────────────────────────────────────
 *
 * Written once, on the first request of a browsing session, and updated once if that
 * session goes on to do something. Everything else about what happened here is already
 * counted off the domain tables by {@see \AfricaGates\Admin\Services\AnalyticsService},
 * and counting it twice is how two reports come to disagree.
 *
 * ── WHAT COUNTS AS A SOURCE ──────────────────────────────────────────────────
 *
 * `utm_source` when the link carried one, because an organiser who tagged their flier
 * has already told us the answer. Otherwise the referrer's HOST, folded to a name a
 * person recognises — a WhatsApp forward arrives from `l.facebook.com` or a bare
 * `whatsapp` referrer depending on the client, and a report listing both separately is a
 * report nobody reads. Otherwise 'direct', which honestly includes every app that strips
 * referrers on the way out.
 *
 * ── AND WHAT IS NOT RECORDED AT ALL ──────────────────────────────────────────
 *
 * Bots, admin pages, the cron endpoint, assets, and anybody who has asked not to be
 * tracked. The first four are noise that would drown the signal; the last is the point.
 * `Do Not Track` and `Sec-GPC` are honoured — not because they are enforceable, but
 * because this table exists to tell an organiser which flier worked, and no answer to
 * that question is worth ignoring somebody who said no.
 */
final class VisitTracker
{
    /** The session key holding this visit's row key. Not the session id — see the migration. */
    public const SESSION_KEY = '_ag_visit';

    /** Retention. Long enough to compare a campaign against the one before it. */
    public const KEEP_DAYS = 180;

    /**
     * Paths that are never an arrival.
     *
     * Prefix-matched. The admin console and the judge portal are people already here; the
     * cron endpoint and the health probes are not people at all; and an asset is a
     * consequence of a page view rather than one.
     */
    private const IGNORE = [
        '/admin', '/judge', '/__cron', '/__setup', '/api/', '/assets/', '/uploads/',
        '/favicon', '/robots.txt', '/sitemap', '/health', '/email/unsubscribe',
    ];

    /** Is the tracker switched on at all? */
    public static function enabled(): bool
    {
        try {
            $v = trim((string) (DB::table('gates_settings')
                ->where('key_name', 'visits_enabled')->value('value') ?? ''));
        } catch (\Throwable) {
            return false;
        }

        // Default ON. It records no personal data, it is the only answer to "did the
        // flier work", and a feature that ships off is one nobody discovers.
        return $v === '' || $v === '1' || $v === 'on' || $v === 'yes';
    }

    /**
     * Record this arrival, if it is one.
     *
     * Returns the visit key, or '' when nothing was recorded. Never throws: a tracker
     * that can 500 a page is worse than no tracker, and this runs in front of every
     * public request on the site.
     */
    public static function record(Request $request): string
    {
        try {
            if (!self::enabled()) return '';
            if (strtoupper($request->getMethod()) !== 'GET') return '';

            // Already counted. One row per session — a reader who opens nine pages is one
            // arrival, and counting nine would make every source look nine times better
            // than the one that sent a single visitor who bought a ticket.
            $existing = (string) ($_SESSION[self::SESSION_KEY] ?? '');
            if ($existing !== '') return $existing;

            $path = '/' . ltrim($request->getUri()->getPath(), '/');
            foreach (self::IGNORE as $prefix) {
                if (str_starts_with($path, $prefix)) return '';
            }

            if (self::optedOut($request) || self::looksLikeBot($request)) return '';

            $q      = $request->getQueryParams();
            $ref    = self::header($request, 'Referer');
            $host   = self::sameHost($request, $ref) ? '' : self::hostOf($ref);
            $key    = bin2hex(random_bytes(16));

            DB::table('gates_visits')->insert([
                'visit_key'     => $key,
                'source'        => self::source($q, $host),
                'medium'        => self::trim((string) ($q['utm_medium'] ?? ''), 60) ?: null,
                'campaign'      => self::trim((string) ($q['utm_campaign'] ?? ''), 80) ?: null,
                'referrer_host' => $host !== '' ? self::trim($host, 120) : null,
                // The path, redacted. Dropping the query is only half of it — see safePath().
                'landing_path'  => self::trim(self::safePath($path), 190),
                'device'        => self::device($request),
                'country'       => self::country($request),
                'ip_hash'       => self::ipHash($request),
                'created_at'    => Carbon::now()->toDateTimeString(),
            ]);

            $_SESSION[self::SESSION_KEY] = $key;

            return $key;
        } catch (\Throwable) {
            // Silent, and deliberately. The only thing worse than not knowing where
            // visitors came from is a white page where the home page used to be.
            return '';
        }
    }

    /**
     * Mark this session's arrival as having led to something.
     *
     * ── FIRST ONE WINS ───────────────────────────────────────────────────────
     *
     * A visitor who votes and then buys a ticket is not two conversions, and overwriting
     * would make the last thing anybody did look like the thing every campaign produced.
     * The first is also the more useful answer: it is what the link was FOR.
     */
    public static function convert(string $kind): bool
    {
        try {
            $key = (string) ($_SESSION[self::SESSION_KEY] ?? '');
            if ($key === '' || trim($kind) === '') return false;

            return DB::table('gates_visits')
                ->where('visit_key', $key)
                ->whereNull('converted_kind')
                ->update([
                    'converted_kind' => self::trim(trim($kind), 40),
                    'converted_at'   => Carbon::now()->toDateTimeString(),
                ]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * How long arrivals are kept, as the operator set it.
     *
     * Clamped on READ as well as on save. A row written by hand, or by a save from an
     * older screen, must not be able to make the pruner delete today's arrivals — and the
     * floor is what stops a 0 doing exactly that on the next maintenance tick.
     */
    public static function keepDays(): int
    {
        try {
            $v = (int) trim((string) (DB::table('gates_settings')
                ->where('key_name', 'visits_days')->value('value') ?? ''));
        } catch (\Throwable) {
            return self::KEEP_DAYS;
        }

        return $v > 0 ? max(7, min(730, $v)) : self::KEEP_DAYS;
    }

    /** Drop arrivals past the retention window. Returns how many. */
    public static function prune(?int $days = null): int
    {
        $days ??= self::keepDays();

        try {
            return (int) DB::table('gates_visits')
                ->where('created_at', '<', Carbon::now()->subDays(max(1, $days))->toDateTimeString())
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * A landing path with any credential in it reduced to a star.
     *
     * ── THE HALF THAT DROPPING THE QUERY STRING DOES NOT COVER ───────────────
     *
     * The query is dropped because this platform puts live credentials in links. It also
     * puts them in PATHS, and that was missed: `/honour/AGI-K7M2QX4T` is a guest of
     * honour's pass reference — the same string the support desk treats as the key to
     * their invitation — and `/claim/dispute/<32 hex>` is a live dispute token.
     *
     * Recorded verbatim, those sat in a table built to be read by operators and exported
     * to a spreadsheet. The redaction is done at WRITE time and not in the report,
     * because a value that never enters the column cannot leave it by some other door.
     *
     * ── A LIST AND A SHAPE, BOTH ─────────────────────────────────────────────
     *
     * The prefixes are exact and cover what exists today. The shape rule is what covers
     * the route somebody adds next — the failure mode here is FORGETTING, and a list on
     * its own is a rule to be remembered on the day a new token-bearing URL is written.
     *
     * Real slugs survive: `incredible-principal-awards-2026` is long, but it is hyphenated
     * lower-case words, and nothing below matches it.
     */
    public static function safePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') return '/';

        $parts = explode('/', ltrim($path, '/'));

        // Known credential-bearing routes, by prefix. Everything below the named depth is
        // a secret regardless of what it looks like.
        foreach ([['honour', 1], ['claim', 2], ['ticket', 1], ['pass', 1]] as [$head, $keep]) {
            if (($parts[0] ?? '') !== $head) continue;
            if (count($parts) <= $keep) continue;

            // /claim/dispute/<token> keeps 'dispute'; /claim/42 does not need redacting but
            // starring it costs nothing and is the safer default for a route that grows.
            return '/' . implode('/', array_slice($parts, 0, $keep)) . '/*';
        }

        $out = [];
        foreach ($parts as $seg) {
            $out[] = self::looksLikeSecret($seg) ? '*' : $seg;
        }

        return '/' . implode('/', $out);
    }

    /** A path segment that is a token rather than a name. */
    private static function looksLikeSecret(string $seg): bool
    {
        // Hex of token length: session ids, dispute tokens, anything from bin2hex().
        if (preg_match('/^[0-9a-f]{16,}$/i', $seg)) return true;

        // This platform's own reference shapes: AGI- for an invitation, AFG- for a payment.
        if (preg_match('/^(AGI|AFG)-[A-Z0-9-]{4,}$/i', $seg)) return true;

        // A long unhyphenated run mixing letters and digits — the shape of a random token
        // and NOT the shape of a slug, which is hyphenated words.
        return preg_match('/^(?=.*\d)(?=.*[A-Za-z])[A-Za-z0-9_]{20,}$/', $seg) === 1;
    }

    // ── how a source is decided ──────────────────────────────────────────────

    /**
     * Referrer hosts folded to the name a person would say.
     *
     * A WhatsApp forward arrives as `l.facebook.com`, `lm.facebook.com`, a bare
     * `whatsapp`, or nothing at all depending on the client and the platform it passed
     * through. Listing each separately produces a report where the one channel that
     * matters most here is split five ways and looks like five small ones.
     */
    private const FOLD = [
        'whatsapp'   => ['whatsapp', 'wa.me', 'web.whatsapp'],
        'facebook'   => ['facebook', 'fb.com', 'fb.me', 'l.facebook', 'lm.facebook', 'm.facebook'],
        'instagram'  => ['instagram', 'l.instagram'],
        'x'          => ['twitter.com', 't.co', 'x.com'],
        'linkedin'   => ['linkedin', 'lnkd.in'],
        'telegram'   => ['telegram', 't.me'],
        'tiktok'     => ['tiktok'],
        'youtube'    => ['youtube', 'youtu.be'],
        'google'     => ['google.', 'googleusercontent'],
        'bing'       => ['bing.com'],
        'email'      => ['mail.google', 'outlook.', 'mail.yahoo', 'webmail'],
    ];

    /** @param array<string,mixed> $query */
    public static function source(array $query, string $referrerHost): string
    {
        // The organiser's own tag wins. They tagged the flier; they have already told us.
        $utm = self::trim(strtolower(trim((string) ($query['utm_source'] ?? ''))), 60);
        if ($utm !== '') return $utm;

        $host = strtolower(trim($referrerHost));
        if ($host === '') return 'direct';

        foreach (self::FOLD as $name => $needles) {
            foreach ($needles as $n) {
                if (str_contains($host, $n)) return $name;
            }
        }

        // An unrecognised host, kept as itself minus a leading www. Better than 'other':
        // a partner site sending real traffic should be nameable in the report.
        return self::trim(preg_replace('/^www\./', '', $host) ?? $host, 60);
    }

    private static function hostOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            // Some clients send a bare token rather than a URL ("whatsapp", "android-app://…").
            $host = (string) preg_replace('~^[a-z]+://~i', '', $url);
            $host = (string) preg_replace('~[/?#].*$~', '', $host);
        }

        return strtolower(trim($host));
    }

    /** Our own pages are navigation, not arrivals. */
    private static function sameHost(Request $request, string $referrer): bool
    {
        $ref = self::hostOf($referrer);

        return $ref !== '' && $ref === strtolower($request->getUri()->getHost());
    }

    // ── the reader, and what is deliberately not learned about them ──────────

    private static function device(Request $request): string
    {
        $ua = strtolower(self::header($request, 'User-Agent'));
        if ($ua === '') return 'unknown';
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) return 'tablet';
        if (str_contains($ua, 'mobi') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * The country, only if a CDN in front of us has already worked it out.
     *
     * Never geolocated here. There is no database on this host to do it with, and a
     * third-party lookup would send every visitor's IP to somebody else in order to fill
     * in a column on a report.
     */
    private static function country(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Vercel-IP-Country', 'X-Country-Code'] as $h) {
            $v = strtoupper(trim(self::header($request, $h)));
            if (preg_match('/^[A-Z]{2}$/', $v)) return $v;
        }

        return null;
    }

    /**
     * A daily-salted hash, so the same reader is one arrival today and unlinkable across days.
     *
     * The column exists to spot a single machine refreshing a campaign link a thousand
     * times. A stable hash would make it a permanent pseudonymous identifier for every
     * visitor to the site, which is a different thing entirely and not one this table
     * needs to be.
     */
    private static function ipHash(Request $request): ?string
    {
        $ip = trim(self::header($request, 'CF-Connecting-IP'))
            ?: trim(explode(',', self::header($request, 'X-Forwarded-For'))[0])
            ?: (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        $ip = trim($ip);
        if ($ip === '') return null;

        return hash('sha256', $ip . '|' . Carbon::now()->toDateString());
    }

    /** Do Not Track, and Global Privacy Control. */
    private static function optedOut(Request $request): bool
    {
        return self::header($request, 'DNT') === '1' || self::header($request, 'Sec-GPC') === '1';
    }

    private static function looksLikeBot(Request $request): bool
    {
        $ua = strtolower(self::header($request, 'User-Agent'));
        if ($ua === '') return true;   // no agent at all is a script, not a reader

        foreach (['bot', 'crawler', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
                  'headlesschrome', 'phantomjs', 'preview', 'monitor', 'uptime',
                  'facebookexternalhit', 'whatsapp'] as $needle) {
            // NOTE: 'whatsapp' here is WhatsApp's LINK PREVIEW fetcher, which requests
            // every URL pasted into a chat. Counting those as arrivals would make any
            // link shared in a busy group look like a hundred visitors before one person
            // had opened it. A human arriving FROM WhatsApp is identified by the referrer,
            // not by the agent, so this does not cost the channel its credit.
            if (str_contains($ua, $needle)) return true;
        }

        return false;
    }

    private static function header(Request $request, string $name): string
    {
        $v = $request->getHeaderLine($name);

        return is_string($v) ? trim($v) : '';
    }

    private static function trim(string $v, int $len): string
    {
        return mb_substr(trim($v), 0, $len);
    }
}
