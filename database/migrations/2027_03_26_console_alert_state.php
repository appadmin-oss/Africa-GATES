<?php
/**
 * What the console remembers about its alerts (AdminConsole v2, README §10b).
 *
 * An alert is still DERIVED on read — open exactly while its check fails, so it cannot
 * outlive the fact it is about (Admin\Services\ConsoleAlerts). These two tables add the
 * human half the derived list could not hold:
 *
 *   gates_console_alerts         one row per alert key: when it was first seen, last seen,
 *                                resolved (its check passed again), how many times it came
 *                                back, and who took it.
 *   gates_console_alert_events   the timeline: raised, assigned, resolved, reopened.
 *
 * "Resolved" is not a button. It is written when the check stops failing, which is the one
 * meaning of closed that cannot disagree with the platform: an alert closed by hand while
 * its check still fails would be a dashboard saying all is well about something broken.
 *
 * Plain VARCHARs rather than ENUMs for every word here: a value outside an ENUM is
 * `Data truncated` on MySQL and nothing at all on SQLite (CLAUDE.md).
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_console_alerts')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_console_alerts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              alert_key TEXT NOT NULL,
              severity TEXT NOT NULL DEFAULT 'low',
              impact TEXT NULL,
              title TEXT NOT NULL DEFAULT '',
              source TEXT NOT NULL DEFAULT '',
              first_seen_at TEXT NOT NULL,
              last_seen_at TEXT NOT NULL,
              resolved_at TEXT NULL,
              reopened_count INTEGER NOT NULL DEFAULT 0,
              owner_admin_id INTEGER NULL,
              owner_at TEXT NULL
           )"
        : "CREATE TABLE gates_console_alerts (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              alert_key VARCHAR(64) NOT NULL,
              severity VARCHAR(10) NOT NULL DEFAULT 'low',
              impact VARCHAR(10) NULL,
              title VARCHAR(255) NOT NULL DEFAULT '',
              source VARCHAR(40) NOT NULL DEFAULT '',
              first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              resolved_at TIMESTAMP NULL DEFAULT NULL,
              reopened_count INT UNSIGNED NOT NULL DEFAULT 0,
              owner_admin_id INT UNSIGNED NULL,
              owner_at TIMESTAMP NULL DEFAULT NULL
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_console_alerts\n";
}
echo '  ' . SchemaIndex::ensure('gates_console_alerts', 'uq_console_alert_key', ['alert_key'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_console_alerts', 'idx_console_alert_resolved', ['resolved_at']) . "\n";

if (!$schema->hasTable('gates_console_alert_events')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_console_alert_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              alert_key TEXT NOT NULL,
              kind TEXT NOT NULL,
              admin_id INTEGER NULL,
              at TEXT NOT NULL
           )"
        : "CREATE TABLE gates_console_alert_events (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              alert_key VARCHAR(64) NOT NULL,
              kind VARCHAR(20) NOT NULL,
              admin_id INT UNSIGNED NULL,
              at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_console_alert_events\n";
}
echo '  ' . SchemaIndex::ensure('gates_console_alert_events', 'idx_console_alert_ev_key', ['alert_key', 'at']) . "\n";

echo "console alert state OK\n";
