<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Clock;

/**
 * The MySQL session timezone is pinned in the connection config, not left to the
 * server's default.
 *
 * This is not tidiness. The schema stores cycle boundaries as `DATETIME` (returned
 * verbatim) and ballot/nomination timestamps as `TIMESTAMP` (converted into the
 * session timezone on read), so `voted_at >= voting_close` shifts by the session's
 * UTC offset. Measured on MySQL 8.0 with the session at `+01:00` — WAT, the obvious
 * setting for a Nigerian deployment — a vote cast thirty minutes BEFORE a deadline
 * is reported as late; under a negative offset genuinely late votes are hidden.
 *
 * Pinning it in the config means web, console, cron and every standalone migration
 * land in the same frame without any of them having to remember. These tests cover
 * the value and the wiring; the behaviour itself was verified against a real MySQL
 * 8.0.46 server with its global default set to `+01:00` — the session came up
 * `+00:00` and the late-vote count stayed correct.
 */
class DatabaseTimezonePinTest extends TestCase
{
    private function config(array $env): array
    {
        $saved = [];
        foreach (['DB_DRIVER', 'APP_TIMEZONE', 'DB_TIMEZONE'] as $k) {
            $saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        foreach ($env as $k => $v) $_ENV[$k] = $v;

        $tzBefore = date_default_timezone_get();
        Clock::boot();
        try {
            return require dirname(__DIR__, 2) . '/config/database.php';
        } finally {
            date_default_timezone_set($tzBefore);
            foreach ($saved as $k => $v) {
                if ($v === null) unset($_ENV[$k]); else $_ENV[$k] = $v;
            }
        }
    }

    public function test_the_mysql_config_pins_a_session_timezone(): void
    {
        // Absent this key the connector issues no `SET time_zone` at all and the
        // session silently inherits whatever the server was configured with.
        $cfg = $this->config(['DB_DRIVER' => 'mysql', 'APP_TIMEZONE' => 'UTC']);

        $this->assertArrayHasKey('timezone', $cfg, 'without this the server default wins');
        $this->assertSame('+00:00', $cfg['timezone']);
    }

    public function test_the_pin_follows_php_rather_than_forcing_utc(): void
    {
        // The DATETIME boundaries were written by PHP in whatever frame
        // Clock::boot() pinned, so the database must be put in THAT frame. Hard
        // coding UTC would be correct only for the default and would silently
        // shift every comparison for an operator who chose a local zone.
        $cfg = $this->config(['DB_DRIVER' => 'mysql', 'APP_TIMEZONE' => 'Africa/Lagos']);

        $this->assertSame('+01:00', $cfg['timezone'], 'WAT — and no DST, so a fixed offset is safe here');
    }

    public function test_an_explicit_db_timezone_overrides_the_derived_offset(): void
    {
        // The escape hatch for an operator who has populated MySQL's timezone
        // tables and would rather use a zone name, or who needs to match a
        // database already written in some other frame.
        $cfg = $this->config([
            'DB_DRIVER' => 'mysql', 'APP_TIMEZONE' => 'UTC', 'DB_TIMEZONE' => '+05:30',
        ]);

        $this->assertSame('+05:30', $cfg['timezone']);
    }

    public function test_a_numeric_offset_is_the_default_not_a_zone_name(): void
    {
        // `SET time_zone = 'Africa/Lagos'` needs MySQL's timezone tables loaded
        // (mysql_tzinfo_to_sql), which on shared hosting they usually are not —
        // and the failure mode is a connection that errors on every request.
        $cfg = $this->config(['DB_DRIVER' => 'mysql', 'APP_TIMEZONE' => 'Africa/Lagos']);

        $this->assertMatchesRegularExpression('/^[+-]\d{2}:\d{2}$/', $cfg['timezone']);
    }

    public function test_sqlite_gets_no_timezone_key(): void
    {
        // SQLite has no session timezone; passing the key would be meaningless at
        // best. It stores text and compares it verbatim, which is why none of this
        // could be caught by the test suite alone.
        $cfg = $this->config(['DB_DRIVER' => 'sqlite', 'DB_PATH' => ':memory:', 'APP_TIMEZONE' => 'UTC']);

        $this->assertSame('sqlite', $cfg['driver']);
        $this->assertArrayNotHasKey('timezone', $cfg);
    }

    public function test_the_helper_works_even_if_clock_was_never_booted(): void
    {
        // config/database.php is required by 62 call sites including every
        // standalone migration. It must not depend on boot() having run first.
        $saved = $_ENV['DB_TIMEZONE'] ?? null;
        unset($_ENV['DB_TIMEZONE']);
        try {
            $this->assertMatchesRegularExpression('/^[+-]\d{2}:\d{2}$/', Clock::databaseTimezone());
        } finally {
            if ($saved !== null) $_ENV['DB_TIMEZONE'] = $saved;
        }
    }
}
