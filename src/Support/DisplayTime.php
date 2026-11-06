<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The timezone people SEE and TYPE, which is not the timezone the database stores.
 *
 * ── WHY STORAGE STAYS UTC ────────────────────────────────────────────────────
 * The obvious way to "make the site WAT" is APP_TIMEZONE=Africa/Lagos, and it is a trap.
 * {@see Clock} pins the process clock, so every `Carbon::now()->toDateTimeString()` after
 * that writes a WAT-local string into columns whose existing rows hold UTC ones — and
 * MySQL DATETIME and SQLite TEXT carry no offset, so nothing records which is which. Every
 * comparison across the boundary is then an hour out, permanently and invisibly: a cycle
 * closes an hour early, a hold expires an hour late, and the audit trail cannot be used to
 * work out what actually happened. Clock's own docblock says it plainly — display
 * conversion belongs at the edge, not in storage — and this class is that edge.
 *
 * So: **stored is UTC, shown is WAT, typed is WAT.** One conversion in each direction, in
 * one place.
 *
 * ── AND THE ZONE IS ADMIN-CONFIGURABLE ──────────────────────────────────────
 * `display_timezone` in gates_settings, defaulting to Africa/Lagos. A platform that says
 * it is continental should not have its clock welded to one city, and the operator running
 * a cycle is the person who knows which zone the deadline was announced in.
 */
final class DisplayTime
{
    /** WAT. The platform's home zone, and the default when nothing is configured. */
    public const DEFAULT_ZONE = 'Africa/Lagos';

    private const SETTING = 'display_timezone';

    /** Cached per request: this is read by every date on every page. */
    private static ?string $zone = null;

    /** The zone to render in and to interpret admin input as. */
    public static function zone(): string
    {
        if (self::$zone !== null) return self::$zone;

        $tz = '';
        try {
            $tz = trim((string) DB::table('gates_settings')->where('key_name', self::SETTING)->value('value'));
        } catch (\Throwable) {
            // No table yet, no database, a pre-migration schema. The default is a working
            // answer, and a page that renders in the wrong zone beats one that 500s.
        }

        return self::$zone = ($tz !== '' && Clock::isValid($tz)) ? $tz : self::DEFAULT_ZONE;
    }

    /** Forget the cached zone. For tests, and for the settings screen after a save. */
    public static function forget(): void
    {
        self::$zone = null;
    }

    /**
     * A stored (UTC) datetime, rendered in the display zone.
     *
     * Returns '' for null/empty/zero dates rather than 1 January 1970 — a blank cell is
     * the truth when a column is genuinely empty, and a date nobody set showing as a real
     * date is how an operator makes a decision on a fact that was never recorded.
     */
    public static function show(string|\DateTimeInterface|null $stored, string $format = 'D j M Y, H:i'): string
    {
        $dt = self::parse($stored);

        return $dt === null ? '' : $dt->setTimezone(new \DateTimeZone(self::zone()))->format($format);
    }

    /** The same, with the zone abbreviation appended — for anything carrying a deadline. */
    public static function showZoned(string|\DateTimeInterface|null $stored, string $format = 'D j M Y, H:i'): string
    {
        $out = self::show($stored, $format);

        return $out === '' ? '' : $out . ' ' . self::abbr();
    }

    /**
     * Admin input, typed in the display zone, converted to a UTC storage string.
     *
     * This is the half that is easy to forget and expensive to get wrong: an operator
     * typing "23:59" into a cycle's voting_close means 23:59 in THEIR zone, and storing
     * that string verbatim closes the vote an hour early in WAT.
     */
    public static function toStored(?string $typed, string $format = 'Y-m-d H:i:s'): ?string
    {
        $typed = trim((string) $typed);
        if ($typed === '') return null;

        try {
            // Interpreted in the display zone, NOT the process zone — that is the whole point.
            $dt = new \DateTimeImmutable($typed, new \DateTimeZone(self::zone()));
        } catch (\Throwable) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format($format);
    }

    /**
     * A stored UTC datetime as the value for a `datetime-local` input.
     *
     * Browsers hand `datetime-local` back with no offset, so it round-trips through
     * {@see toStored} — which is why this must emit the DISPLAY zone's wall-clock time and
     * not UTC. Emitting UTC here and parsing as WAT on save shifts every deadline an hour
     * every time somebody opens the form and presses save without touching the field.
     */
    public static function forInput(string|\DateTimeInterface|null $stored): string
    {
        // SECONDS INCLUDED, on purpose. At minute precision this round-trip silently moved
        // a deadline stored as 23:59:59 back to 23:59:00 every time somebody opened the
        // form and saved without touching the field — 59 seconds of drift per save, in the
        // exact column that decides whether a vote counted. Pair with step="1" on the
        // input so the browser shows and returns the seconds it is given.
        return self::show($stored, 'Y-m-d\TH:i:s');
    }

    /** 'WAT', 'GMT', '+01:00' — whatever the zone calls itself right now. */
    public static function abbr(): string
    {
        try {
            $a = (new \DateTimeImmutable('now', new \DateTimeZone(self::zone())))->format('T');
        } catch (\Throwable) {
            return '';
        }

        return $a;
    }

    /**
     * Zones an operator can choose from.
     *
     * Africa first and complete, because that is the audience; the rest of the common ones
     * after it, because partners and judges are not all on the continent. Not the full 400+
     * identifier list — a select nobody can find their city in is worse than a short one.
     *
     * @return list<string>
     */
    public static function choices(): array
    {
        $africa = \DateTimeZone::listIdentifiers(\DateTimeZone::AFRICA);
        $extra  = ['UTC', 'Europe/London', 'Europe/Paris', 'America/New_York',
                   'America/Los_Angeles', 'Asia/Dubai', 'Asia/Shanghai', 'Australia/Sydney'];

        return array_values(array_unique(array_merge($africa, $extra)));
    }

    /** @return \DateTimeImmutable|null null for anything that is not a real datetime */
    private static function parse(string|\DateTimeInterface|null $stored): ?\DateTimeImmutable
    {
        if ($stored === null) return null;

        if ($stored instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($stored);
        }

        $s = trim($stored);
        // '0000-00-00 00:00:00' is what a legacy MySQL row holds instead of NULL, and it
        // parses to year zero rather than throwing.
        if ($s === '' || str_starts_with($s, '0000-00-00')) return null;

        try {
            // Stored values carry no offset and are UTC by this application's convention.
            $dt = new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        return (int) $dt->format('Y') > 1970 ? $dt : null;
    }
}
