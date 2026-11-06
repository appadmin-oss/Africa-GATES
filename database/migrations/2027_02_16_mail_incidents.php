<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AN EMAIL OUTAGE, AS A RECORD — opened, diagnosed, alerted, resolved.
 *
 * `gates_mail_log` already held every failure, and the platform still learned that mail
 * was down from a person who did not get a sign-in code. A log is a list of facts; an
 * outage is a state with a beginning, a cause and an end, and nothing here held that
 * state — so nothing could say "this started at 03:10", nobody was told, and nothing
 * could notice it had stopped. See `AfricaGates\Services\Mail\MailHealth`.
 *
 * One OPEN row at a time (`resolved_at IS NULL`). The automatic diagnosis that ran when
 * it opened is kept on the row as JSON, because the cause at 03:10 is the thing the
 * operator reading it at 09:00 needs, and by 09:00 a fresh diagnosis may say something
 * else entirely — or, if somebody fixed it, nothing at all.
 *
 * `opened_by`, never `trigger`: TRIGGER is a reserved word in MySQL and bare-legal in
 * SQLite, which is the `rank` trap this repo has already paid for once.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_mail_incidents')) {
    DB::statement($sqlite ? <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_mail_incidents (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          opened_at TEXT NOT NULL,
          resolved_at TEXT NULL,
          opened_by TEXT NOT NULL DEFAULT 'failures',
          cause TEXT NOT NULL DEFAULT 'unknown',
          failures INTEGER NOT NULL DEFAULT 0,
          last_error TEXT NULL,
          report_json TEXT NULL,
          alerted_at TEXT NULL,
          alert_channels TEXT NULL,
          updated_at TEXT NULL
        )
SQL : <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_mail_incidents (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          opened_at DATETIME NOT NULL,
          resolved_at DATETIME NULL,
          /* VARCHAR, not ENUM: a value outside an ENUM is `Data truncated`, and the
             vocabulary here is code-side (MailHealth::BY_*), where it can grow. */
          opened_by VARCHAR(20) NOT NULL DEFAULT 'failures',
          cause VARCHAR(20) NOT NULL DEFAULT 'unknown',
          /* INT, never TINYINT: an outage on a busy day passes 255 failures before lunch. */
          failures INT UNSIGNED NOT NULL DEFAULT 0,
          last_error VARCHAR(400) NULL,
          report_json MEDIUMTEXT NULL,
          alerted_at DATETIME NULL,
          alert_channels VARCHAR(200) NULL,
          updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + gates_mail_incidents created\n";
} else {
    echo "  = gates_mail_incidents present\n";
}

// "Is there an open incident" is asked by the admin layout on every console page.
echo SchemaIndex::ensure('gates_mail_incidents', 'idx_mail_incident_open', ['resolved_at', 'id']) . "\n";
echo "mail incidents OK\n";
