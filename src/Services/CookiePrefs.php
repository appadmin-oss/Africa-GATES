<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * May we count this arrival? One resolver, and the only place that question is answered.
 *
 * ── THE BUG THIS EXISTS BECAUSE OF ───────────────────────────────────────────
 *
 * {@see VisitTracker} records where every visitor came from, and the published cookie
 * policy said in bold that this platform runs "no analytics". It is first-party, it keeps
 * no IP address, it honours `Do Not Track` and it tells nobody else — all of which makes
 * it defensible and none of which makes that sentence true.
 *
 * The opt-out was worse. It was `DNT` and `Sec-GPC` and nothing else. Chrome removed the
 * Do Not Track setting, Safari removed it before that, and Global Privacy Control is a
 * Firefox and Brave setting most people have never heard of — so for the large majority of
 * this platform's visitors, on Chrome on an Android phone, there was NO WAY TO SAY NO,
 * while the same page promised "if we ever add anything that tracks you… you will be asked
 * before it runs". The mechanism was honest; the only people it could hear were the ones
 * already using a browser that spoke for them.
 *
 * ── A REFUSAL FROM EITHER SIDE WINS, AND THAT COSTS SOMETHING ────────────────
 *
 * A header saying no overrides a stored yes. The Global Privacy Control specification
 * permits a site-specific opt-in to override the general signal, so this is stricter than
 * it has to be, and the cost is real: somebody who browses with GPC on and then comes here
 * and deliberately presses "yes, count my visit" is still not counted, and the page tells
 * them so rather than silently disagreeing with them.
 *
 * It is the right way round anyway. The only thing at stake on our side is a row in a
 * report about which flier worked. The rule is one sentence — if anything said no, the
 * answer is no — and a rule nobody has to reason about is a rule that cannot be got wrong
 * by the next person to touch it.
 *
 * ── WHY THE DEFAULT IS "COUNT, UNLESS TOLD NOT TO" ───────────────────────────
 *
 * Because the counting stores NOTHING NEW on the device. It reuses the session cookie that
 * is already strictly necessary, so ePrivacy Art.5(3) — which governs storage and access,
 * not measurement — is not engaged by it, and the CNIL's exempted audience-measurement
 * conditions are met on every limb: first party, no cross-site anything, no third-party
 * recipient, aggregate reporting only, no persistent identifier (the IP hash is re-salted
 * daily and is unlinkable across days), and a bounded retention an operator sets.
 *
 * That is a POSTURE and not a law of nature, so it is a setting. `visits_consent_mode` =
 * `consent` counts nobody until they agree, and puts the question in front of a first-time
 * visitor rather than leaving a switch that quietly does nothing. Whichever is chosen, the
 * generated half of `/cookies` says which one is running, because a page describing the
 * wrong posture is the fault this class was written to end.
 */
final class CookiePrefs
{
    /** One character: `y` or `n`. No identifier, nothing to join on. */
    public const COOKIE = 'ag_privacy';

    /** Long enough that a person is not asked again every season. */
    public const TTL_DAYS = 365;

    /** The setting key, and its two postures. */
    public const MODE_KEY     = 'visits_consent_mode';
    public const MODE_EXEMPT  = 'exempt';
    public const MODE_CONSENT = 'consent';

    public const YES = 'y';
    public const NO  = 'n';

    /**
     * The operator's posture. See the class docblock for what each one means.
     *
     * Anything unrecognised reads as `exempt` rather than throwing, because this is called
     * in front of every public request and a typo in a settings row must not be able to
     * take the site down. It is clamped on WRITE too — see SettingsController.
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
     * What this visitor chose, or null if they have never been asked and never said.
     *
     * Read from the REQUEST rather than `$_COOKIE`, so a test can pose the question
     * without touching a superglobal and so the answer is the one this request carried.
     */
    public static function choice(Request $request): ?bool
    {
        $v = '';
        try {
            $v = strtolower(trim((string) ($request->getCookieParams()[self::COOKIE] ?? '')));
        } catch (\Throwable) {
            return null;
        }

        if ($v === self::YES) return true;
        if ($v === self::NO)  return false;

        return null;
    }

    /** Did the browser itself say no — `Do Not Track`, or Global Privacy Control? */
    public static function signalledNo(Request $request): bool
    {
        try {
            return trim($request->getHeaderLine('DNT')) === '1'
                || trim($request->getHeaderLine('Sec-GPC')) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The question this class exists to answer.
     *
     * Deliberately does NOT consider whether the tracker is switched on at all — that is
     * the operator's question and {@see VisitTracker::enabled()} owns it. Two resolvers for
     * one decision is how the report and the policy come to disagree about what is running.
     */
    public static function analyticsAllowed(Request $request): bool
    {
        if (self::signalledNo($request)) return false;

        $choice = self::choice($request);
        if ($choice !== null) return $choice;

        return self::mode() !== self::MODE_CONSENT;
    }

    /**
     * Should this visitor be shown the question?
     *
     * Only in `consent` mode, only when they have not already answered, and never when the
     * browser has already answered for them — a notice asking somebody to agree to
     * something we have already decided not to do reads as a dark pattern, and it is one.
     *
     * Also never when the tracker is off: there is nothing to consent to.
     */
    public static function mustAsk(Request $request): bool
    {
        if (self::mode() !== self::MODE_CONSENT)  return false;
        if (self::signalledNo($request))          return false;
        if (self::choice($request) !== null)      return false;

        // Not on the page that carries the full control. The notice is a summary of a
        // decision; floating it over the page that explains the decision properly, with
        // its own buttons two inches below, is two controls for one answer.
        if (str_starts_with(self::path($request), '/cookies')) return false;

        return VisitTracker::enabled();
    }

    /**
     * Remember this request's answer, so a template can ask without a Request.
     *
     * ── WHY A MEMO AND NOT A SECOND READER ───────────────────────────────────
     *
     * The notice is drawn by the site layout, and Twig has no Request. The obvious
     * shortcut is a template helper that reads `$_COOKIE` and `$_SERVER` itself — and
     * that would be a SECOND answer to "may we count this person", which is the exact
     * shape of fault this class was extracted to end. A page could then show somebody the
     * consent question while the tracker had already counted them, and nothing on either
     * side would report an error.
     *
     * So the decision is made once, from the real request, by the middleware that is
     * already in front of every public page, and the template reads the answer.
     *
     * Defaults to "do not ask" when nothing called this — the admin console, the judge
     * portal, a test rendering a template in isolation. A notice that appears because a
     * memo was never primed would be a notice appearing on pages where it means nothing.
     */
    public static function observe(Request $request): void
    {
        self::$ask    = self::mustAsk($request);
        self::$return = self::path($request);
    }

    /** @var bool|null the memo primed by {@see observe()}; null means nobody asked */
    private static ?bool $ask = null;

    /** @var string where to send somebody back to after they answer from the notice */
    private static string $return = '/';

    /**
     * The current path, as a value it is safe to put in a form and redirect to later.
     *
     * PATH ONLY, and the query is dropped on purpose — this platform puts live passes and
     * sign-in tokens in query strings, and a `return` field is a value that travels through
     * a form post and into a `Location` header. It is re-validated on the way back in
     * anyway (see the route), because a hidden field is something a person can edit; this
     * is the half that stops us handing them a credential to edit in the first place.
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
     * `//evil.example` is a protocol-relative URL that browsers follow off-site, and it
     * starts with a slash — so "begins with /" is not the check, and writing it that way
     * is how an open redirect ships looking like it was guarded.
     */
    public static function safeReturn(string $path): string
    {
        $p = trim($path);
        if ($p === '' || $p[0] !== '/') return '/';
        if (str_starts_with($p, '//') || str_starts_with($p, '/\\')) return '/';
        if (str_contains($p, "\n") || str_contains($p, "\r")) return '/';

        // No query and no fragment: see path() for why the query in particular.
        $p = (string) preg_replace('/[?#].*$/', '', $p);

        return $p === '' ? '/' : mb_substr($p, 0, 300);
    }

    /** Where the notice should send somebody back to. */
    public static function returnPath(): string
    {
        return self::$return;
    }

    /** Should the layout draw the notice on this request? */
    public static function asking(): bool
    {
        return self::$ask === true;
    }

    /** Test seam: the memo is per PROCESS and the suite is one process. */
    public static function forget(): void
    {
        self::$ask    = null;
        self::$return = '/';
    }

    /**
     * Write the choice onto a response.
     *
     * A PSR-7 header rather than `setcookie()`, because the response is the thing being
     * returned and a side effect on the global output buffer is invisible to every test
     * that renders this route.
     *
     * `HttpOnly`, because nothing on any page needs to read it — the page is rendered
     * server-side from {@see choice()}, so handing scripts a privacy preference to read
     * would add a fingerprinting surface in order to save a round trip we are not making.
     */
    public static function apply(Response $response, bool $allow): Response
    {
        $parts = [
            self::COOKIE . '=' . ($allow ? self::YES : self::NO),
            'Path=/',
            'Max-Age=' . (self::TTL_DAYS * 86400),
            'HttpOnly',
            'SameSite=Lax',
        ];

        if (self::secure()) $parts[] = 'Secure';

        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }

    /**
     * Secure, exactly as the session cookie was decided to be.
     *
     * Read from the live session parameters rather than re-deriving it from `HTTPS` and
     * `APP_ENV`: public/index.php already settles that question with a tri-state that has
     * a documented fallback, and a second copy of it here would be a second answer to
     * "is this deployment on HTTPS" — which is the shape of divergence this codebase
     * keeps paying for.
     */
    private static function secure(): bool
    {
        try {
            return (bool) (session_get_cookie_params()['secure'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
