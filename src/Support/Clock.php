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
        $tz = trim((string) ($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: ''));
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

    private static function isValid(string $tz): bool
    {
        return in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }
}
