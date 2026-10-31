<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;

/**
 * The credential that lets one person render one flier.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE URL CANNOT CARRY A REGISTRATION ID
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The flier prints a name. `/events/gala/flier.png?reg=8814` is an enumerable address, so
 * anybody who can count could render a flier with a stranger's name and tier on it, over
 * this event's branding, and post it — and the platform would have generated it. That is not
 * a data leak in the usual sense; it is worse, because the artefact is designed to be shared
 * and looks official.
 *
 * So the payload travels INSIDE a signed token. Nothing about it is guessable and nothing
 * about it is enumerable: a token names one event, one name, and — when the person actually
 * holds a ticket — one registration, and it is signed with `APP_KEY`.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT EXPIRES, AND WHY THE WINDOW IS HOURS AND NOT MINUTES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A short-lived token is what stops one leaked URL becoming a permanent renderer for that
 * name. But the flier's whole point is being shared: somebody generates it, saves it, and
 * posts it that evening. If the token expired in ten minutes, the IMAGE would still work —
 * it is a file by then — but the "regenerate in another format" path would break in the
 * middle of what somebody was doing, and they would read that as the feature being broken.
 *
 * {@see TTL} is six hours: long enough to cover one sitting at the task, short enough that a
 * URL pasted into a group chat stops rendering long before anybody notices it is there.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS NOT IN HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * No email, no phone. The flier needs a display name and whether there is a ticket; it does
 * not need contact details, so they never enter the token and cannot leak from one. The tier
 * is looked up from the registration at render time rather than carried, so a token cannot
 * assert a tier its registration does not have.
 */
final class EventFlierToken
{
    /** How long a token renders for. See the class note. */
    public const TTL = 21600;

    /** Longest display name a token will carry. */
    public const NAME_MAX = 60;

    /**
     * Mint a token.
     *
     * `$registrationId` is 0 for the ungated path — anybody, no account, no ticket. That is
     * deliberate and is the entry point the whole feature exists for: the referral programme
     * already sat behind a sign-in as a read-only text field, which is precisely why nobody
     * used it.
     */
    public static function mint(int $eventId, string $name, int $registrationId = 0): string
    {
        $payload = implode('|', [
            $eventId,
            $registrationId,
            // Base64url so the separator cannot appear inside the name and shift the fields —
            // a name containing a pipe would otherwise be read as a registration id.
            self::b64(self::cleanName($name)),
            time() + self::TTL,
        ]);

        return self::b64($payload) . '.' . self::sign($payload);
    }

    /**
     * Read a token back, or null.
     *
     * Null for every failure with no distinction between them: expired, forged, malformed and
     * truncated all mean "do not render", and a caller that behaved differently for a bad
     * signature than for an expired token would be an oracle for guessing at the key.
     *
     * @return array{event:int, registration:int, name:string}|null
     */
    public static function read(string $token): ?array
    {
        $parts = explode('.', trim($token), 2);
        if (count($parts) !== 2) return null;

        $payload = self::unb64($parts[0]);
        if ($payload === null) return null;

        // hash_equals, not `===`: this compares a secret-derived value against attacker-
        // supplied input, and a timing-variable comparison is the standard way that leaks.
        if (!hash_equals(self::sign($payload), $parts[1])) return null;

        $f = explode('|', $payload);
        if (count($f) !== 4) return null;

        [$event, $registration, $name64, $expires] = $f;
        if ((int) $expires < time()) return null;

        $name = self::unb64($name64);
        if ($name === null || trim($name) === '') return null;

        return [
            'event'        => (int) $event,
            'registration' => (int) $registration,
            'name'         => self::cleanName($name),
        ];
    }

    /**
     * The name as it will be printed.
     *
     * Control characters stripped and whitespace collapsed: this string is drawn onto an
     * image by GD, and a newline inside it draws nothing visible while pushing the rest of
     * the line off the canvas — a blank flier with no error anywhere.
     *
     * Length-capped here rather than at the layout, so the cap is one number and a token
     * cannot carry a name the renderer will then have to truncate silently.
     */
    public static function cleanName(string $name): string
    {
        $n = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
        $n = trim((string) preg_replace('/\s+/u', ' ', $n));

        return mb_substr($n, 0, self::NAME_MAX);
    }

    private static function sign(string $payload): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            // Same fallback shape as EmailOptOut: weak, and a weak signature still beats an
            // enumerable id. `app:doctor` is where a missing APP_KEY is surfaced.
            $key = 'ag-flier-fallback|' . (string) Env::get('DB_NAME', 'africa_gates');
        }

        return substr(hash_hmac('sha256', 'event-flier|' . $payload, $key), 0, 40);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        if ($s === '' || preg_match('~[^A-Za-z0-9\-_]~', $s)) return null;
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        return $out === false ? null : $out;
    }
}
