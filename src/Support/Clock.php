<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Pins the process timezone. Call once, first thing, from every entrypoint.
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS. Now that a cycle's phase is computed
 * from its date windows against the current time ({@see \AfricaGates\Services\CyclePolicy}),
 * every process that asks "is voting open?" must agree on what time it is. The
 * codebase previously set no timezone anywhere — not in php.ini, not in any of
 * the four bootstraps (public/index.php, bin/console, cron/maintenance.php,
 * tests/bootstrap.php) — so every `Carbon::now()` and `Carbon::parse()` fell
 * back to whatever ambient default that SAPI happened to have.
 *
 * On shared cPanel hosting the CLI and web SAPIs routinely read DIFFERENT
 * php.ini files. If those disagree, the cron materialiser and the web request
 * compute different phases from identical rows — not transiently, but
 * permanently and silently. It would present as drift that the materialiser can
 * never fix, and as a cycle that closes an hour early or late for no visible
 * reason.
 *
 * Stored datetimes carry no offset (MySQL DATETIME / SQLite TEXT), so their
 * meaning is a convention, and the convention is: everything is UTC.
 * APP_TIMEZONE exists as an escape hatch for an operator who has already
 * populated their database in local time, but UTC is strongly preferred —
 * display conversion belongs at the edge, not in storage.
 */
final class Clock
{
    public const DEFAULT_TIMEZONE = 'UTC';

    /**
     * Set the process timezone from APP_TIMEZONE (default UTC). Idempotent and
     * safe to call from every entrypoint. Returns the timezone actually applied.
     */
    public static function boot(): string
    {
        $tz = (string) Env::get('APP_TIMEZONE', '');
        if ($tz === '' || !self::isValid($tz)) {
            $tz = self::DEFAULT_TIMEZONE;
        }
        date_default_timezone_set($tz);
        return $tz;
    }

    /** The timezone every stored datetime in this application is expressed in. */
    public static function timezone(): string
    {
        return date_default_timezone_get();
    }

    /**
     * The value to pin the MySQL session `time_zone` to, so the database agrees
     * with this process about what a stored timestamp means.
     *
     * WHY A DATABASE SESSION NEEDS PINNING AT ALL. This schema stores the two
     * sides of every deadline comparison in different MySQL types — cycle
     * boundaries as `DATETIME`, ballot and nomination timestamps as `TIMESTAMP`.
     * MySQL returns a `DATETIME` exactly as written but converts a `TIMESTAMP`
     * into the SESSION timezone on read, so `voted_at >= voting_close` shifts by
     * the session's UTC offset. Measured on MySQL 8.0: with the session at
     * `+01:00` — WAT, the natural setting for a Nigerian deployment — a vote cast
     * thirty minutes BEFORE a deadline is reported as late, and under a negative
     * offset genuinely late votes are hidden instead.
     *
     * A FIXED OFFSET, not a zone name, by default. `SET time_zone = 'Africa/Lagos'`
     * requires MySQL's timezone tables to have been populated
     * (`mysql_tzinfo_to_sql`), which on shared hosting they usually are not, and
     * the failure mode is a connection that errors on every request. The numeric
     * offset always works. Set `DB_TIMEZONE` explicitly if you have loaded the
     * tables and would rather use a name.
     *
     * DST CAVEAT: the offset is resolved once per process, when the connection
     * config is built. A long-running worker that crosses a DST transition keeps
     * the old offset until it restarts. That is harmless for the two timezones
     * this application actually recommends — UTC and WAT both have no DST — but
     * it is a real reason to prefer UTC storage over a DST-observing local zone,
     * which is what this class already advises above.
     */
    public static function databaseTimezone(): string
    {
        $explicit = (string) Env::get('DB_TIMEZONE', '');
        if ($explicit !== '') return $explicit;

        // Whatever THIS process thinks the time is — which is what boot() pinned,
        // and is the frame the DATETIME boundaries were written in. Deliberately
        // read from PHP rather than from APP_TIMEZONE, so the two cannot disagree
        // if boot() was never called.
        return (new \DateTimeImmutable('now'))->format('P');
    }

    /** Public because {@see DisplayTime} validates the admin-chosen display zone with it. */
    public static function isValid(string $tz): bool
    {
        return in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }
}
