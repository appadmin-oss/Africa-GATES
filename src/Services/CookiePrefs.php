<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\CookieRegistry;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * May we? — for each of the four consent categories. One resolver, and the only place
 * that question is answered.
 *
 * Rebuilt on 3 Oct 2026 for the redesign's consent model (REFERENCE §10, §7.9; owner's
 * answer to GAPS Q11): Essential, always on, plus three a visitor may refuse —
 * Preferences, Analytics, Marketing — stored in ONE cookie, `ag_consent`, as versioned
 * JSON. It replaced a single yes/no about arrival counting (`ag_privacy`), and the rule
 * that made that one right is kept word for word.
 *
 * ── THE RULE IS ONE SENTENCE: IF ANYTHING SAID NO, THE ANSWER IS NO ─────────
 *
 * A browser signal — Global Privacy Control or Do Not Track — refuses every optional
 * category, and it beats a stored yes. The GPC specification would let a site-specific
 * opt-in override the general signal; this platform declines to, deliberately, and the
 * cost is stated rather than hidden: somebody browsing with GPC on who presses "Allow all"
 * here is still not counted, and their language is still forgotten when the browser
 * closes. The preferences sheet says so ("Respected your browser's privacy signal") rather
 * than offering a switch that would silently disagree with them.
 *
 * A rule nobody has to reason about cannot be got wrong by the next person to touch it.
 * Every reader — the tracker, the language cookie, the display store's first-paint script,
 * the notice — asks {@see allows()} and nothing else.
 *
 * ── WHAT "UNANSWERED" MEANS, PER CATEGORY ────────────────────────────────────
 *
 * · Analytics keeps the operator's POSTURE, `visits_consent_mode`. `exempt` counts unless
 *   told not to — the counting stores nothing new on the device (it reuses the session
 *   cookie that is already strictly necessary), so ePrivacy Art.5(3) is not engaged and the
 *   CNIL's exempted audience-measurement conditions are met on every limb. `consent` counts
 *   nobody until they agree. Either way the notice and the generated half of `/cookies` say
 *   which is running.
 * · Preferences is OFF until somebody says yes, whatever the posture. It is the one
 *   category that stores something persistent on the device for a reason the visitor did
 *   not just ask for (remembering a choice NEXT time), so nothing is switched on in advance.
 *   Refused or unanswered, a language or a text size still works — for this browsing
 *   session only (see {@see CookieRegistry}).
 * · Marketing is never offered: nothing on this site does it. See {@see offered()}.
 *
 * ── THE OLD COOKIE IS READ ONCE AND THEN DELETED ─────────────────────────────
 *
 * A visitor who answered the old single question keeps their answer: `ag_privacy=n` is a
 * no to Analytics, `y` a yes, and the other two stay unanswered (the old question never
 * asked about them, so the notice asks). That is behaviour preservation, not a
 * compatibility alias: {@see carryOver()} rewrites it into `ag_consent` on the first
 * response and expires `ag_privacy`, and nothing writes the old name again.
 * `CookiePrefsTest` holds that a stored no survives the move as a no.
 */
final class CookiePrefs
{
    /** The record of the visitor's answers. Versioned JSON; no identifier of any kind. */
    public const COOKIE = 'ag_consent';

    /** The retired single-switch cookie. Read to carry an answer across, then expired. */
    public const LEGACY = 'ag_privacy';

    /** Bump when the categories change, so an answer about a different set is not reused. */
    public const VERSION = 1;

    /** Long enough that a person is not asked again every season (the DC's admin: 12 months). */
    public const TTL_DAYS = 365;

    /** The operator's posture for Analytics, and its two values. */
    public const MODE_KEY     = 'visits_consent_mode';
    public const MODE_EXEMPT  = 'exempt';
    public const MODE_CONSENT = 'consent';

    public const ESSENTIAL   = 'essential';
    public const PREFERENCES = 'preferences';
    public const ANALYTICS   = 'analytics';
    public const MARKETING   = 'marketing';

    /** The four, in the order a reader meets them. */
    public const CATEGORIES = [self::ESSENTIAL, self::PREFERENCES, self::ANALYTICS, self::MARKETING];

    /** The three a visitor may refuse. */
    public const OPTIONAL = [self::PREFERENCES, self::ANALYTICS, self::MARKETING];

    /**
     * The operator's posture for Analytics.
     *
     * Anything unrecognised reads as `exempt` rather than throwing: this is asked in front
     * of every public request, and a typo in a settings row must not take the site down. It
     * is clamped on WRITE too — see SettingsController.
     */
    public static function mode(): string
    {
        try {
            $v = strtolower(trim((string) (DB::table('gates_settings')
                ->where('key_name', self::MODE_KEY)->value('value') ?? '')));
        } catch (\Throwable) {
            return self::MODE_EXEMPT;
        }

        return $v === self::MODE_CONSENT ? self::MODE_CONSENT : self::MODE_EXEMPT;
    }

    /**
     * What this visitor said, per optional category: true, false, or null for never asked.
     *
     * From `ag_consent` when it is there. Otherwise from the retired `ag_privacy`, so an
     * answer given under the old single question is not thrown away: its no stays a no.
     *
     * An answer recorded under a DIFFERENT version keeps only its refusals. A yes was given
     * to a set of categories that has since changed, so it is asked again; a no is never
     * weakened by a schema change.
     *
     * @return array{preferences:?bool, analytics:?bool, marketing:?bool}
     */
    public static function answers(Request $request): array
    {
        $out = [self::PREFERENCES => null, self::ANALYTICS => null, self::MARKETING => null];

        try {
            $cookies = $request->getCookieParams();
        } catch (\Throwable) {
            return $out;
        }

        $raw = $cookies[self::COOKIE] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $doc = self::decode($raw);
            if ($doc === null) {
                // Present but unreadable: a cookie we cannot understand about whether
                // somebody agreed is read as "not agreed", never as "agreed".
                return [self::PREFERENCES => false, self::ANALYTICS => false, self::MARKETING => false];
            }
            $same = ((int) ($doc['v'] ?? 0)) === self::VERSION;
            foreach (self::OPTIONAL as $c) {
                $v = $doc[$c] ?? null;
                if ($v === false) $out[$c] = false;
                elseif ($v === true && $same) $out[$c] = true;
            }
            return $out;
        }

        $old = strtolower(trim((string) ($cookies[self::LEGACY] ?? '')));
        if ($old === 'n') $out[self::ANALYTICS] = false;
        if ($old === 'y') $out[self::ANALYTICS] = true;

        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $raw): ?array
    {
        foreach ([$raw, rawurldecode($raw)] as $try) {
            // A record, not merely valid JSON: `[]` or `{}` is not an answer we wrote, and
            // reading it as "unanswered" would hand Analytics to the posture's default.
            $d = json_decode($try, true);
            if (is_array($d) && is_int($d['v'] ?? null)) return $d;
        }
        return null;
    }

    /**
     * Did the browser itself say no — Global Privacy Control, or Do Not Track?
     *
     * @return 'gpc'|'dnt'|null  which one, because the sheet names the one that was sent
     */
    public static function signal(Request $request): ?string
    {
        try {
            if (trim($request->getHeaderLine('Sec-GPC')) === '1') return 'gpc';
            if (trim($request->getHeaderLine('DNT')) === '1')     return 'dnt';
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    public static function signalledNo(Request $request): bool
    {
        return self::signal($request) !== null;
    }

    /**
     * Is there anything in this category for a visitor to allow?
     *
     * Derived, never typed. Preferences and Marketing are offered when the registry
     * declares something in them that this site writes; Analytics when the tracker is
     * running. A switch for a category nothing uses changes nothing, and a control that
     * changes nothing is a control lying about a mechanism — so Marketing, which nothing
     * here does, is drawn as "Not used on this site" and has no switch at all.
     */
    public static function offered(string $category): bool
    {
        if ($category === self::ESSENTIAL) return true;
        if ($category === self::ANALYTICS) return VisitTracker::enabled();
        if (!in_array($category, self::OPTIONAL, true)) return false;

        return CookieRegistry::inCategory($category) !== [];
    }

    /**
     * THE question. Essential always; otherwise no if anything said no, else the answer,
     * else the category's default (Analytics: the posture; the rest: no).
     *
     * Deliberately does NOT ask whether the tracker is switched on — that is the operator's
     * question and {@see VisitTracker::enabled()} owns it. Two resolvers for one decision is
     * how the report and the policy come to disagree about what is running.
     *
     * `$answers` lets the POST ask about the answers it is about to store, rather than the
     * ones the request arrived with — through this same rule, not a copy of it.
     *
     * @param array{preferences:?bool, analytics:?bool, marketing:?bool}|null $answers
     */
    public static function allows(Request $request, string $category, ?array $answers = null): bool
    {
        if ($category === self::ESSENTIAL) return true;
        if (!in_array($category, self::OPTIONAL, true)) return false;
        if (self::signalledNo($request)) return false;

        $a = ($answers ?? self::answers($request))[$category] ?? null;
        if ($a !== null) return $a;

        return $category === self::ANALYTICS && self::mode() !== self::MODE_CONSENT;
    }

    /**
     * Should this visitor be shown the notice?
     *
     * When an offered category is unanswered — and never when the browser has already
     * answered for them: a notice asking somebody to agree to something we have already
     * decided not to do is a dark pattern whether or not anybody intended it. Never on
     * `/cookies` either, which carries the full control: two controls for one answer.
     */
    public static function mustAsk(Request $request): bool
    {
        if (self::signalledNo($request)) return false;
        if (str_starts_with(self::path($request), '/cookies')) return false;

        $a = self::answers($request);
        foreach (self::OPTIONAL as $c) {
            if ($a[$c] === null && self::offered($c)) return true;
        }
        return false;
    }

    /**
     * Everything the notice and the preferences sheet draw, decided once from the request.
     *
     * The words come from {@see CookieRegistry::categories()}, the same source as the
     * generated half of `/cookies`, so the sheet and the policy cannot describe a category
     * two ways.
     *
     * @return array<string,mixed>
     */
    public static function state(Request $request): array
    {
        $signal  = self::signal($request);
        $answers = self::answers($request);
        $rows    = [];

        foreach (CookieRegistry::categories() as $c) {
            $key = $c['key'];
            if ($key === self::ESSENTIAL) {
                $kind = 'always';
            } elseif (!self::offered($key)) {
                $kind = $key === self::ANALYTICS ? 'off_for_all' : 'unused';
            } elseif ($signal !== null) {
                $kind = 'signal';
            } else {
                $kind = 'switch';
            }
            $rows[] = $c + [
                'kind' => $kind,
                'on'   => self::allows($request, $key),
                'answered' => $key === self::ESSENTIAL ? true : $answers[$key] !== null,
            ];
        }

        return [
            'ask'       => self::mustAsk($request),
            'signal'    => $signal,
            'mode'      => self::mode(),
            'counting'  => self::offered(self::ANALYTICS),
            'remember'  => self::allows($request, self::PREFERENCES),
            'rows'      => $rows,
            'return'    => self::path($request),
            'cookie'    => self::COOKIE,
        ];
    }

    /**
     * Remember this request's state, so a template can draw it without a Request.
     *
     * ── WHY A MEMO AND NOT A SECOND READER ───────────────────────────────────
     *
     * The notice is drawn by the layout, and Twig has no Request. A template helper that
     * read `$_COOKIE` and `$_SERVER` itself would be a SECOND answer to "may we", which is
     * the exact fault this class exists to end: a page could show somebody the question
     * while the tracker had already counted them. So the middleware that is already in
     * front of every request decides, from the real request, and the template reads it.
     *
     * Null when nothing called this — the admin console, a test rendering a template in
     * isolation — and a null draws no notice: a notice appearing because a memo was never
     * primed would appear on pages where it means nothing.
     */
    public static function observe(Request $request): void
    {
        self::$state = self::state($request);
    }

    /** @var array<string,mixed>|null */
    private static ?array $state = null;

    /**
     * The memo, with the one-shot "saved" confirmation the POST left behind.
     *
     * The confirmation is taken out of the session HERE, when the layout draws it, and not
     * in {@see observe()}: a background fetch between the redirect and the page would
     * otherwise consume a message nobody saw.
     *
     * @return array<string,mixed>|null
     */
    public static function current(): ?array
    {
        if (self::$state === null) return null;

        // Taken once per request and kept on the memo: the layout and the partial both ask,
        // and the second asking must see what the first took, not an empty session.
        if (!array_key_exists('saved', self::$state)) {
            $saved = isset($_SESSION) ? (string) ($_SESSION['consent_saved'] ?? '') : '';
            if ($saved !== '') unset($_SESSION['consent_saved']);
            self::$state['saved'] = $saved;
        }

        return self::$state;
    }

    /** Test seam: the memo is per PROCESS and the suite is one process. */
    public static function forget(): void
    {
        self::$state = null;
    }

    /**
     * Turn a posted answer into what is stored.
     *
     * `all` — yes to every OFFERED optional category; a category nothing uses is left
     * unanswered, so the day it is used the visitor is asked rather than presumed.
     * `essential` — no to all three, offered or not: a refusal is broad, and a no recorded
     * now is still a no the day something new appears.
     * `save` — each offered category from its switch; the rest keep what they had.
     * Anything else is read as `essential`: a request we could not understand about whether
     * somebody agreed is a request in which they did not.
     *
     * @param array<string,mixed> $body
     * @return array{preferences:?bool, analytics:?bool, marketing:?bool}
     */
    public static function fromPost(Request $request, array $body): array
    {
        $answer = strtolower(trim((string) ($body['answer'] ?? '')));
        $had    = self::answers($request);
        $out    = $had;

        foreach (self::OPTIONAL as $c) {
            $offered = self::offered($c);
            if ($answer === 'all') {
                $out[$c] = $offered ? true : $had[$c];
            } elseif ($answer === 'save') {
                $out[$c] = $offered ? ((string) ($body[$c] ?? '') === '1') : $had[$c];
            } else {
                $out[$c] = false;
            }
        }

        return $out;
    }

    /**
     * Write the answers onto a response. The ONLY writer of `ag_consent` on the platform.
     *
     * A PSR-7 header rather than `setcookie()`: the response is the thing being returned,
     * and a side effect on the global output buffer is invisible to every test that renders
     * the route. `HttpOnly`, because no page reads it — the server draws everything from
     * {@see allows()} and hands the one fact a script needs (may the display store
     * persist?) to the layout as `data-ag-keep`. Handing scripts the whole record would add
     * a fingerprinting surface to save a round trip nobody is making.
     *
     * @param array{preferences:?bool, analytics:?bool, marketing:?bool} $answers
     */
    public static function write(Response $response, array $answers, bool $dropLegacy = false): Response
    {
        $doc = ['v' => self::VERSION];
        foreach (self::OPTIONAL as $c) $doc[$c] = $answers[$c] ?? null;

        $parts = [
            self::COOKIE . '=' . rawurlencode((string) json_encode($doc)),
            'Path=/',
            'Max-Age=' . (self::TTL_DAYS * 86400),
            'HttpOnly',
            'SameSite=Lax',
        ];
        if (self::secure()) $parts[] = 'Secure';

        $response = $response->withAddedHeader('Set-Cookie', implode('; ', $parts));

        if ($dropLegacy) {
            // An expiry, never a value: the old name is only ever deleted from here on.
            // CookieRegistryTest holds that a retired name is written with Max-Age=0 alone.
            $response = $response->withAddedHeader('Set-Cookie', self::LEGACY . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax');
        }

        return $response;
    }

    /**
     * The one-time move from `ag_privacy` to `ag_consent`, on whatever response comes next.
     *
     * Only when the old cookie is present and the new one is not, so it runs once per
     * browser and then never again.
     */
    public static function carryOver(Request $request, Response $response): Response
    {
        try {
            $c = $request->getCookieParams();
        } catch (\Throwable) {
            return $response;
        }
        if (!isset($c[self::LEGACY]) || isset($c[self::COOKIE])) return $response;

        return self::write($response, self::answers($request), true);
    }

    /**
     * The current path, as a value it is safe to put in a form and redirect to later.
     *
     * PATH ONLY: this platform puts live passes and sign-in tokens in query strings, and a
     * `return` field travels through a form post into a `Location` header.
     */
    private static function path(Request $request): string
    {
        try {
            $p = '/' . ltrim($request->getUri()->getPath(), '/');
        } catch (\Throwable) {
            return '/';
        }

        return self::safeReturn($p);
    }

    /**
     * A relative, same-site path, or '/'.
     *
     * `//evil.example` is a protocol-relative URL that browsers follow off-site and it
     * starts with a slash, so "begins with /" is not the check. Every control character
     * and the space too: a browser strips tab and newline from anywhere in a URL before
     * resolving it, so `/\t/evil.example` would be followed as `//evil.example`.
     */
    public static function safeReturn(string $path): string
    {
        $p = trim($path);
        if ($p === '' || $p[0] !== '/') return '/';
        if (str_starts_with($p, '//') || str_starts_with($p, '/\\')) return '/';
        if (preg_match('/[\x00-\x20\x7F]/', $p)) return '/';

        $p = (string) preg_replace('/[?#].*$/', '', $p);

        return $p === '' ? '/' : mb_substr($p, 0, 300);
    }

    /**
     * Secure, exactly as the session cookie was decided to be — read from the live session
     * parameters rather than re-deriving "is this deployment on HTTPS" a second time.
     */
    public static function secure(): bool
    {
        try {
            return (bool) (session_get_cookie_params()['secure'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
