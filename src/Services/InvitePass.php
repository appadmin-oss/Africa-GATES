<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The rotating code on a guest of honour's mobile ID.
 *
 * ── WHAT ROTATION IS ACTUALLY FOR ────────────────────────────────────────────
 *
 * A static pass is a screenshot. One nominee's ID, photographed once and passed round a
 * car park, admits everybody holding the picture — and the door has no way to tell,
 * because every scan of it is genuine. So the code changes, on a short window, and a
 * picture of it is worth {@see STEP_SECONDS} seconds.
 *
 * ── WHY IT IS AN HMAC AND NOT A STORED NONCE ─────────────────────────────────
 *
 * The alternative is a row per code and a write per refresh: a phone left open on the ID
 * page writes twice a minute, times a thousand guests, on shared hosting, at the exact
 * moment the door is busiest. This computes instead. The door verifies a signature it
 * can derive itself and writes once, when somebody is actually admitted.
 *
 * Rotation is only worth something if the code cannot be computed by somebody holding
 * the reference — and the reference is printed in the letter, shown in the email, and
 * displayed on the ID itself. So the secret is per-invite (`gates_event_invites.id_secret`),
 * never leaves the server, and is not derived from anything the invitee can see.
 *
 * ── THE PREVIOUS WINDOW IS ACCEPTED, DELIBERATELY ────────────────────────────
 *
 * A guest holds up a phone; a steward lines up a camera; the shutter fires. That is
 * comfortably longer than one window at a door with a queue behind it, and a code that
 * expires mid-scan reads to everyone present as a broken pass rather than as security
 * working. So the window before the current one still verifies, giving a real tolerance
 * of {@see STEP_SECONDS} to twice that, and the screenshot is still dead inside a minute.
 */
final class InvitePass
{
    /** Seconds per rotation. Short enough that a screenshot dies, long enough to scan. */
    public const STEP_SECONDS = 30;

    /** Hex characters of HMAC kept. 12 hex = 48 bits, far past guessing at a door. */
    private const SIG_LEN = 12;

    /** A fresh reference. Uppercase and unambiguous, because it is read aloud and typed. */
    public static function reference(): string
    {
        // No I, O, 0 or 1: this gets read off a phone screen by a steward under a light,
        // and a reference nobody can transcribe is a reference the door types wrong.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out      = 'AGI-';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** A per-invite secret. 32 bytes of randomness, hex, never shown to anybody. */
    public static function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** The window number a moment falls in. */
    public static function window(?int $at = null): int
    {
        return intdiv($at ?? time(), self::STEP_SECONDS);
    }

    /** Seconds until the code on screen stops being the current one. */
    public static function secondsLeft(?int $at = null): int
    {
        $at = $at ?? time();

        return self::STEP_SECONDS - ($at % self::STEP_SECONDS);
    }

    /**
     * The code to put in the QR right now: `AGI-XXXXXXXX.<window>.<sig>`.
     *
     * Alphanumeric-mode-safe on purpose — uppercase, digits, and `.` and `-`, all of
     * which QR's alphanumeric charset carries. It is still encoded through
     * {@see \AfricaGates\Support\Qr::encodeBytes()} because it is longer than a version-1
     * symbol holds, but keeping it inside that charset means a steward can also type it.
     */
    public static function code(string $reference, string $secret, ?int $window = null): string
    {
        $w = $window ?? self::window();

        return $reference . '.' . $w . '.' . self::sign($reference, $secret, $w);
    }

    /**
     * Verify a scanned code and return the invite behind it.
     *
     * @return array{ok:bool, invite:?object, reason:string}
     */
    public static function verify(string $scanned): array
    {
        $no = static fn (string $why): array => ['ok' => false, 'invite' => null, 'reason' => $why];

        $scanned = strtoupper(trim($scanned));
        // A scanner may return the whole URL if somebody points it at the page rather
        // than the code. Take the last path segment, the same tolerance the ticket door has.
        if (str_contains($scanned, '/')) {
            $scanned = (string) preg_replace('~^.*/~', '', rtrim($scanned, '/'));
        }
        if ($scanned === '') return $no('Nothing was scanned.');

        $parts = explode('.', $scanned);
        if (count($parts) !== 3) return $no('That is not an invitation code.');

        [$reference, $window, $sig] = $parts;
        if (!ctype_digit($window)) return $no('That invitation code is malformed.');

        try {
            $invite = DB::table('gates_event_invites')->where('reference', $reference)->first();
        } catch (\Throwable) {
            return $no('The invitation list is unavailable.');
        }
        if (!$invite) return $no('No invitation here has that reference.');

        $now = self::window();
        $w   = (int) $window;
        // Current window, or the one before it. Never a future one: a clock-skewed phone
        // must not mint a code that stays valid after the guest has left.
        if ($w !== $now && $w !== $now - 1) {
            return $no($w > $now
                ? 'That code is not valid yet — ask them to refresh the page.'
                : 'That code has expired. Ask them to refresh their ID.');
        }

        $expected = self::sign($reference, (string) $invite->id_secret, $w);
        if (!hash_equals($expected, strtoupper($sig))) {
            return $no('That code did not verify. It may be a photograph of somebody else\'s ID.');
        }

        return ['ok' => true, 'invite' => $invite, 'reason' => ''];
    }

    /** Count a real admission. One write, at the door, not per refresh. */
    public static function touch(int $inviteId, string $via = ''): void
    {
        try {
            DB::table('gates_event_invites')->where('id', $inviteId)->update(
                OptionalColumn::filter('gates_event_invites', [
                    'scans'         => DB::raw('scans + 1'),
                    'last_scan_at'  => Carbon::now()->toDateTimeString(),
                    // WHICH GATE, which is what the column was added for and what nothing
                    // was passing. Its migration said it plainly — "guests of honour need
                    // the same pair the ticket path has, for the same reason: without it
                    // the record of an evening says a volunteer's scan was nobody's" — and
                    // then the door called this with one argument. A promise in a migration
                    // comment with no writer behind it is §19's bug exactly.
                    'last_scan_via' => $via !== '' ? mb_substr($via, 0, 60) : null,
                ], ['last_scan_at', 'last_scan_via'])
            );
        } catch (\Throwable) {
            // A door that cannot write its counter must still open. The verdict above is
            // the decision; this is the tally.
        }
    }

    private static function sign(string $reference, string $secret, int $window): string
    {
        return strtoupper(substr(
            hash_hmac('sha256', $reference . '|' . $window, $secret),
            0,
            self::SIG_LEN
        ));
    }
}
