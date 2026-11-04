<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Display & reading, saved to a signed-in member — the "plus the member profile" half of
 * REFERENCE §7.5 / §10, which GAPS §3.7 found missing.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DEVICE STORE IS STILL THE FIRST PAINT; THIS IS WHAT FOLLOWS THE PERSON
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `localStorage["ag-a11y"]` is applied by an inline script in `<head>` before anything
 * paints (`partials/a11y-head.twig`). For a signed-in member the server already knows who
 * they are when it writes that script, so it writes their saved settings INTO it
 * ({@see forUser()}, through the `member_display()` Twig function) and the first paint is
 * theirs on a device they have never used before. Nothing is fetched to do that: a fetch
 * runs after the paint, and somebody who needs 150% text would watch the page draw at
 * 100% and jump — the exact flash the head script exists to prevent.
 *
 * Writes come from `a11y.js` on every change while signed in (`POST /account/display`).
 *
 * ── ONE NORMALISER, BOTH DIRECTIONS ─────────────────────────────────────────
 *
 * {@see normalise()} is applied on the way IN (a request body is a stranger's JSON) and
 * on the way OUT (a stored document survives the code that wrote it — CLAUDE.md, the
 * organisation page's "re-validated on the way out"). The keys are exactly the store's —
 * `a11y.js` SWITCHES plus `size` — and `DisplayReadingTest` holds the two lists equal, so a
 * switch added to the store and not here is caught rather than silently dropped on save.
 *
 * ── NULL IS "NEVER SAVED", NOT "THE DEFAULTS" ───────────────────────────────
 *
 * A member who has never touched a control has no row value, and the head script then
 * keeps whatever this browser already had. Returning the defaults instead would reset
 * somebody's carefully chosen large text to standard the moment they signed in.
 */
final class DisplayReadingPrefs
{
    /** The seven switches, in DisplayReading.dc.html's order. Booleans. */
    public const SWITCHES = ['hc', 'easy', 'space', 'ul', 'motion', 'saver', 'listen'];

    /** Text size: 0 = 100%, 1 = 125%, 2 = 150% of the root. */
    public const SIZES = 3;

    private const TABLE  = 'gates_users';
    private const COLUMN = 'display_json';

    /**
     * Exactly the known keys, each coerced to its type; anything else is dropped.
     *
     * Only TRUE switches are kept, so the stored document is the same shape the browser
     * store writes and an all-default member stores `{"size":0}` rather than eight falses.
     *
     * @param array<mixed> $in
     * @return array<string,int|bool>
     */
    public static function normalise(array $in): array
    {
        $size = (int) (is_numeric($in['size'] ?? null) ? $in['size'] : 0);
        $out  = ['size' => max(0, min(self::SIZES - 1, $size))];

        foreach (self::SWITCHES as $k) {
            $v = $in[$k] ?? false;
            if ($v === true || $v === 1 || $v === '1' || $v === 'true') $out[$k] = true;
        }

        return $out;
    }

    /**
     * The member's saved settings, or null when they have never saved any — or when this
     * database has not had the migration yet, which must read as "nothing saved" rather
     * than take the page down: this runs inside the `<head>` of every page.
     *
     * @return array<string,int|bool>|null
     */
    public static function forUser(int $userId): ?array
    {
        if ($userId <= 0 || !SchemaHas::column(self::TABLE, self::COLUMN)) return null;

        try {
            $raw = DB::table(self::TABLE)->where('id', $userId)->value(self::COLUMN);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw) || $raw === '') return null;

        $doc = json_decode($raw, true);

        return is_array($doc) ? self::normalise($doc) : null;
    }

    /**
     * Save a member's settings. Returns what was stored, or null when it could not be.
     *
     * @param array<mixed> $prefs
     * @return array<string,int|bool>|null
     */
    public static function save(int $userId, array $prefs): ?array
    {
        if ($userId <= 0 || !SchemaHas::column(self::TABLE, self::COLUMN)) return null;

        $clean = self::normalise($prefs);
        try {
            DB::table(self::TABLE)->where('id', $userId)
                ->update([self::COLUMN => (string) json_encode($clean)]);
        } catch (\Throwable) {
            return null;
        }

        return $clean;
    }
}
