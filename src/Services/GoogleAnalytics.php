<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Google Analytics 4, as an option the operator switches on (owner, 28 Oct 2026).
 *
 * Off until a measurement ID is saved in Settings → Arrivals (or `GA_MEASUREMENT_ID` in
 * `.env`). Our own visit counting (VisitTracker) runs beside it either way: it answers
 * "did this link lead to a vote" from our own tables, which Google cannot see.
 *
 * ── STRICTER THAN OUR OWN COUNTING, ON PURPOSE ──────────────────────────────
 *
 * Our tracker stores nothing on the device and sends nothing to anybody, so it may run
 * under the "count unless told not to" posture (CookiePrefs::MODE_EXEMPT). Google
 * Analytics is a third party that sets `_ga` cookies and receives the visitor's address,
 * which under the NDPA 2023 and the GDPR needs consent. So it loads only on an explicit
 * YES to Analytics: never on the default, never past Global Privacy Control or Do Not
 * Track, and never for staff signed in to the console (who are not the audience).
 *
 * ── WHAT IT IS SENT ─────────────────────────────────────────────────────────
 *
 * gtag.js reports `location.href` and `document.referrer` by default, and links here carry
 * passes and sign-in tokens in the path and the query (VisitTracker::safePath()). So the
 * page we give it is the redacted path plus the utm tags and nothing else, and the
 * referrer is cut to its origin: Google is told no more than our own table keeps. Google
 * signals and ad personalisation are off; this is measurement, not advertising.
 */
final class GoogleAnalytics
{
    public const SETTING = 'ga_measurement_id';

    /** GA4's own shape: "G-" and ten or so capitals and digits. */
    public const PATTERN = '/^G-[A-Z0-9]{4,16}$/';

    /** The measurement ID, or null when the integration is off (or the value is not one). */
    public static function measurementId(): ?string
    {
        $v = '';
        try {
            $v = (string) (DB::table('gates_settings')->where('key_name', self::SETTING)->value('value') ?? '');
        } catch (\Throwable) {}
        if (trim($v) === '') $v = (string) Env::get('GA_MEASUREMENT_ID', '');
        return self::clean($v);
    }

    public static function enabled(): bool
    {
        return self::measurementId() !== null;
    }

    /** A typed value made safe, or null. The save and the read share it. */
    public static function clean(string $v): ?string
    {
        $v = strtoupper(trim($v));
        return preg_match(self::PATTERN, $v) === 1 ? $v : null;
    }

    /**
     * What the page loads for this request: the ID and the redacted page, or null.
     *
     * @return array{id:string, path:string}|null
     */
    public static function forRequest(Request $request): ?array
    {
        $id = self::measurementId();
        if ($id === null) return null;
        if (!empty($_SESSION['admin_id'])) return null;
        if (CookiePrefs::signalledNo($request)) return null;
        if ((CookiePrefs::answers($request)[CookiePrefs::ANALYTICS] ?? null) !== true) return null;

        $path = VisitTracker::safePath($request->getUri()->getPath());
        $q = [];
        foreach ($request->getQueryParams() as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'utm_') && is_string($v)) $q[$k] = mb_substr($v, 0, 80);
        }
        return ['id' => $id, 'path' => $path . ($q !== [] ? '?' . http_build_query($q) : '')];
    }
}
