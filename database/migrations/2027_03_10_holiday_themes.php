<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A MEMBER'S SEASONAL GREETING (HOLIDAY-THEMES, handoff 5 Oct 2026).
 *
 *   gates_holidays            the windows an operator types each year: which theme, from and
 *                             to (inclusive), which country (NULL = everyone), and for the
 *                             teachers' greeting the award it names.
 *   gates_holiday_dismissals  "not this year", per member per theme.
 *   gates_users.seasonal_greetings  the member's own switch, on unless they turn it off.
 *
 * `slug` is VARCHAR, not an ENUM (CLAUDE.md: SQLite ignores ENUM, so a theme added later would
 * be `Data truncated` on production only). Services\HolidayTheme::THEMES validates it.
 * Indexes through SchemaIndex — `CREATE INDEX IF NOT EXISTS` is a 1064 on MySQL.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_holidays')) {
    if ($sqlite) {
        DB::statement('CREATE TABLE gates_holidays (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL,
            starts_on TEXT NOT NULL,
            ends_on TEXT NOT NULL,
            country_code TEXT NULL DEFAULT NULL,
            cta_programme_id INTEGER NULL DEFAULT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL
        )');
    } else {
        DB::statement('CREATE TABLE gates_holidays (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(32) NOT NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            country_code CHAR(2) NULL DEFAULT NULL,
            cta_programme_id INT UNSIGNED NULL DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    echo "  + gates_holidays created\n";
}
SchemaIndex::ensure('gates_holidays', 'idx_hol_window', ['active', 'starts_on', 'ends_on']);

if (!DB::schema()->hasTable('gates_holiday_dismissals')) {
    if ($sqlite) {
        DB::statement('CREATE TABLE gates_holiday_dismissals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            year INTEGER NOT NULL,
            created_at TEXT NULL
        )');
    } else {
        DB::statement('CREATE TABLE gates_holiday_dismissals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id INT UNSIGNED NOT NULL,
            slug VARCHAR(32) NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    echo "  + gates_holiday_dismissals created\n";
}
SchemaIndex::ensure('gates_holiday_dismissals', 'uq_hol_dismiss', ['member_id', 'slug', 'year'], true);

if (DB::schema()->hasTable('gates_users') && !DB::schema()->hasColumn('gates_users', 'seasonal_greetings')) {
    DB::statement('ALTER TABLE gates_users ADD COLUMN seasonal_greetings ' . ($sqlite ? 'INTEGER' : 'TINYINT(1)') . ' NOT NULL DEFAULT 1');
    echo "  + gates_users.seasonal_greetings added\n";
}

echo "holiday themes OK\n";
