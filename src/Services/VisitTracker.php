<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Who arrived, where from, and whether it led to anything.
 *
 * ── ONE ROW PER VISIT ────────────────────────────────────────────────────────
 *
 * A visit is what GA4, Adobe and Plausible all call one: it starts on a page view and ends
 * after {@see IDLE_SECONDS} without another. Each later page view in it adds one to
 * `pages` ({@see touch()}), and the visit is stamped once if it leads to something.
 *
 * It used to be one row per PHP SESSION, and the session cookie lives a week
 * (Support\SessionStore). Somebody who came back every evening was one arrival, every
 * source after the first was invisible, and a vote on day six was credited to the link
 * that brought them on day one. Everything else about what happened here is counted off
 * the domain tables by {@see \AfricaGates\Admin\Services\AnalyticsService}.
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
 * Bots, admin pages, the cron endpoint, assets, anything that is not a browser asking for
 * a page (isPageNavigation()), a page fetched in advance (isSpeculative()), a request with
 * no Accept-Language (a script), staff signed in to the console, any answer that is not a
 * page (a 404 or a redirect; see discard()), and anybody who has asked not to be tracked. All but the last are noise that
 * would drown the signal; the last is the point.
 * `Do Not Track` and `Sec-GPC` are honoured — not because they are enforceable, but
 * because this table exists to tell an organiser which flier worked, and no answer to
 * that question is worth ignoring somebody who said no. They were also, for a long time,
 * the ONLY way to say no, which meant most visitors had none: see {@see CookiePrefs},
 * which owns that decision now and adds a choice a person can actually make.
 */
final class VisitTracker
{
    /** The session key holding this visit's row key. Not the session id — see the migration. */
    public const SESSION_KEY = '_ag_visit';

    /** The time this browser's visit was last active (unix seconds). */
    public const SESSION_AT = '_ag_visit_at';

    /** How many visits this browser has started, so a later one is known to be a return. */
    public const SESSION_N = '_ag_visit_n';

    /**
     * A visit ends after this long without a page view: the industry default (GA4, Adobe
     * and Plausible all use 30 minutes), so our figures compare with theirs.
     */
    public const IDLE_SECONDS = 1800;

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

            $path = '/' . ltrim($request->getUri()->getPath(), '/');
            foreach (self::IGNORE as $prefix) {
                if (str_starts_with($path, $prefix)) return '';
            }

            // One resolver for "may we count this person", and it is not this class:
            // the answer now includes a choice they can make on /cookies, not only a header
            // two of the three major browsers stopped sending. See CookiePrefs.
            if (!CookiePrefs::allows($request, CookiePrefs::ANALYTICS) || !self::isReader($request)) return '';

            // Our own staff reading the public site are not its audience. On a small site the
            // team checking a page after every change is a real share of the traffic, and it
            // lands on exactly the pages being worked on, which is the worst place to inflate.
            if (self::isStaff()) return '';

            // Still inside a visit: this page belongs to it, and the middleware counts it
            // once the response shows it was a page (touch()). A reader who opens nine pages
            // is one visit of nine pages, never nine visits.
            $existing = (string) ($_SESSION[self::SESSION_KEY] ?? '');
            $lastAt   = (int) ($_SESSION[self::SESSION_AT] ?? 0);
            if ($existing !== '' && Carbon::now()->getTimestamp() - $lastAt < self::IDLE_SECONDS) return $existing;
            $before = (int) ($_SESSION[self::SESSION_N] ?? 0);

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
            ] + self::sessionColumns($request, $before > 0 || $existing !== ''));

            $_SESSION[self::SESSION_KEY] = $key;
            $_SESSION[self::SESSION_AT]  = Carbon::now()->getTimestamp();
            $_SESSION[self::SESSION_N]   = $before + 1;

            return $key;
        } catch (\Throwable) {
            // Silent, and deliberately. The only thing worse than not knowing where
            // visitors came from is a white page where the home page used to be.
            return '';
        }
    }

    /**
     * Take back an arrival recorded on the way in, once the response shows it was not a page.
     *
     * ── WHY THE ROW IS WRITTEN FIRST AND TAKEN BACK AFTER ────────────────────
     *
     * The row has to exist before the handler runs, so a session that converts on its
     * first request has something to stamp (see the middleware). But the tracker cannot
     * tell from the request whether the path is a page here, and it counted every one.
     * This domain used to host a WordPress site, and feed readers, aggregators and
     * scanners still ask for its old URLs (`/feed`, `/events/feed`,
     * `/wp-json/tribe/events/v1/events`) thousands of times a week. Each one got a 404 and
     * was still counted as a visit, so the first thing on the analytics page was a site
     * that no longer exists. A 404, a 405, a 410 or a redirect is not a page anybody read.
     * A redirect's destination is counted when the browser follows it, with the same
     * referrer.
     *
     * Only a row written on THIS request is taken back, and never one that already led to
     * something.
     */
    public static function discard(string $key): void
    {
        if ($key === '') return;
        try {
            DB::table('gates_visits')->where('visit_key', $key)->whereNull('converted_kind')->delete();
        } catch (\Throwable) {}
        if (($_SESSION[self::SESSION_KEY] ?? '') === $key) {
            unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_AT]);
            // A 404 first is not a visit, so the next real one is not a "return" either.
            $n = (int) ($_SESSION[self::SESSION_N] ?? 0) - 1;
            if ($n > 0) $_SESSION[self::SESSION_N] = $n; else unset($_SESSION[self::SESSION_N]);
        }
    }

    /**
     * A further page view in the visit already open: one more page, and the visit's clock
     * restarts. Called by the middleware only after the response is a page, so a 404 a
     * reader stumbles on mid-visit is not counted as a page they read.
     */
    public static function touch(string $key): void
    {
        if ($key === '') return;
        $_SESSION[self::SESSION_AT] = Carbon::now()->getTimestamp();
        try {
            DB::table('gates_visits')->where('visit_key', $key)
                ->increment('pages', 1, ['last_seen_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) {}
    }

    /**
     * The columns added with visits (2027_04_10_visit_sessions.php), only where the table
     * has them, so a deploy that has not run the migration yet still records arrivals.
     *
     * @return array<string,mixed>
     */
    private static function sessionColumns(Request $request, bool $returning): array
    {
        if (!\AfricaGates\Support\SchemaHas::column('gates_visits', 'pages')) return [];
        return [
            'pages'        => 1,
            'last_seen_at' => Carbon::now()->toDateTimeString(),
            'is_return'    => $returning ? 1 : 0,
            'visitor_hash' => self::visitorHash($request),
        ];
    }

    /** Is somebody signed in to the admin console on this browser? */
    private static function isStaff(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    /** Was this response a page a person could read? */
    public static function isPageStatus(int $status): bool
    {
        return $status < 300 || $status >= 500;
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
                ] + (\AfricaGates\Support\SchemaHas::column('gates_visits', 'last_seen_at')
                    ? ['last_seen_at' => Carbon::now()->toDateTimeString()] : [])) > 0;
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
     * A daily-salted hash of the NETWORK, so one machine refreshing a campaign link a
     * thousand times is visible, and nobody is linkable across days.
     *
     * The salt was the date, which is not a salt: every IPv4 address is four billion
     * guesses away, a few minutes on a laptop, so anybody holding the table could recover
     * every address in it. It is now random, kept for one day and then overwritten
     * ({@see salt()}), which is what Plausible does: once the day's salt is gone, not even
     * we can recover who a hash was.
     */
    private static function ipHash(Request $request): ?string
    {
        $ip = self::ip($request);
        return $ip === '' ? null : hash_hmac('sha256', $ip, self::salt());
    }

    /**
     * A daily-salted hash of the network AND the browser: one per person per day, as near as
     * a site with no cookie for it can tell. The network alone undercounts badly here,
     * because Nigerian mobile networks put thousands of phones behind one address (carrier
     * NAT); the browser string splits them apart without identifying anybody.
     */
    private static function visitorHash(Request $request): ?string
    {
        $ip = self::ip($request);
        return $ip === '' ? null : hash_hmac('sha256', $ip . '|' . self::header($request, 'User-Agent'), self::salt());
    }

    private static function ip(Request $request): string
    {
        return trim(trim(self::header($request, 'CF-Connecting-IP'))
            ?: trim(explode(',', self::header($request, 'X-Forwarded-For'))[0])
            ?: (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
    }

    /** @var array{0:string,1:string}|null today's salt, memoised per process */
    private static ?array $salt = null;

    /**
     * Today's random salt, made on the first visit of the day and replaced the next day, so
     * yesterday's is gone for good. If it cannot be stored, a process-local one is used,
     * which only costs a visitor being counted twice: never a hash anybody can reverse.
     */
    public static function salt(): string
    {
        $day = Carbon::now()->toDateString();
        if (self::$salt !== null && self::$salt[0] === $day) return self::$salt[1];
        $fresh = bin2hex(random_bytes(32));
        try {
            $row = (string) (DB::table('gates_settings')->where('key_name', 'visits_salt')->value('value') ?? '');
            [$d, $v] = array_pad(explode(':', $row, 2), 2, '');
            if ($d === $day && strlen($v) === 64) {
                self::$salt = [$day, $v];
                return $v;
            }
            DB::table('gates_settings')->updateOrInsert(['key_name' => 'visits_salt'],
                ['value' => $day . ':' . $fresh, 'updated_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) {}
        self::$salt = [$day, $fresh];
        return $fresh;
    }

    /** For tests: forget the memoised salt. */
    public static function forgetSalt(): void
    {
        self::$salt = null;
    }

    private static function looksLikeBot(Request $request): bool
    {
        $ua = strtolower(self::header($request, 'User-Agent'));
        if ($ua === '') return true;   // no agent at all is a script, not a reader

        foreach (['bot', 'crawler', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
                  'headlesschrome', 'phantomjs', 'preview', 'monitor', 'uptime',
                  'facebookexternalhit', 'whatsapp',
                  // Scripts and HTTP libraries that name themselves, and the audit tools a
                  // developer points at a page (each a "visit" from a data centre).
                  'python-urllib', 'aiohttp', 'httpx', 'axios', 'node-fetch', 'undici',
                  'go-http-client', 'okhttp', 'java/', 'apache-httpclient', 'libwww',
                  'guzzlehttp', 'scrapy', 'httpclient', 'lighthouse', 'pagespeed',
                  'gtmetrix', 'pingdom', 'statuscake', 'site24x7', 'chrome-lighthouse',
                  'feedfetcher', 'feedly', 'inoreader', 'newsblur', 'embedly',
                  'chatgpt-user', 'perplexity', 'claude-web', 'cohere-ai', 'meta-externalagent',
                  'puppeteer', 'playwright', 'selenium', 'electron/'] as $needle) {
            // NOTE: 'whatsapp' here is WhatsApp's LINK PREVIEW fetcher, which requests
            // every URL pasted into a chat. Counting those as arrivals would make any
            // link shared in a busy group look like a hundred visitors before one person
            // had opened it. A human arriving FROM WhatsApp is identified by the referrer,
            // not by the agent, so this does not cost the channel its credit.
            if (str_contains($ua, $needle)) return true;
        }

        return false;
    }

    /**
     * Is this a person's browser opening a page? The one answer, shared with the ads
     * (Services\Ads\AdDelivery), which bill per view and must not count a bot the arrivals
     * report would refuse.
     */
    public static function isReader(Request $request): bool
    {
        return !self::looksLikeBot($request) && self::isPageNavigation($request)
            && !self::isSpeculative($request) && self::hasLanguage($request)
            && !self::referrerIsForged($request);
    }

    /**
     * A page the browser fetched in case it is wanted: a prefetch, a prerender, a link
     * preview. Chrome's speculation rules and `<link rel=prefetch>` send `Sec-Purpose:
     * prefetch` (older Chrome `Purpose`, older Firefox `X-Moz`, Safari's previews
     * `X-Purpose`). Nobody has looked at it yet, and most are never looked at. If the
     * reader then opens it, the visit is counted on the page after.
     */
    private static function isSpeculative(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Moz', 'X-Purpose'] as $h) {
            $v = strtolower(self::header($request, $h));
            if ($v !== '' && (str_contains($v, 'prefetch') || str_contains($v, 'prerender') || str_contains($v, 'preview'))) return true;
        }
        return false;
    }

    /**
     * Every browser sends `Accept-Language` with a page request; most scripts that copy a
     * browser's User-Agent and Accept do not bother with it. Cheap, and it catches the
     * scrapers that the two checks above let through.
     */
    private static function hasLanguage(Request $request): bool
    {
        return self::header($request, 'Accept-Language') !== '';
    }

    /**
     * Did a browser ask for this as a page, rather than a script asking for a resource?
     *
     * Every current browser says so in `Sec-Fetch-Mode: navigate` and
     * `Sec-Fetch-Dest: document`. A browser too old to send those still sends an `Accept`
     * naming `text/html` for a page. A feed reader asks for RSS, an API scanner for JSON or
     * anything at all (a bare star-slash-star), and a script that fakes a
     * browser's User-Agent rarely fakes these headers as well. The User-Agent check above
     * catches the bots that say what they are. This one catches the ones that do not.
     */
    private static function isPageNavigation(Request $request): bool
    {
        $mode = strtolower(self::header($request, 'Sec-Fetch-Mode'));
        $dest = strtolower(self::header($request, 'Sec-Fetch-Dest'));
        if ($mode !== '' || $dest !== '') {
            return ($mode === '' || $mode === 'navigate') && ($dest === '' || $dest === 'document');
        }

        return str_contains(strtolower(self::header($request, 'Accept')), 'text/html');
    }

    /**
     * A Referer no browser would send. Production recorded one as a SOURCE: a serialised
     * PHP array that is a known exploit probe against another shop platform's referrer
     * handling. It does nothing here, but a request carrying it is an attack script, not
     * a reader, and it put a line of exploit code on the analytics page.
     */
    private static function referrerIsForged(Request $request): bool
    {
        $ref = self::header($request, 'Referer');
        if ($ref === '') return false;

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', self::hostOf($ref)) !== 1;
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
