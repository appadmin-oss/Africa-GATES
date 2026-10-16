<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "GET STATUS UPDATES" — double opt-in for /status (Phase 9, StatusPageV2, GAPS §3.14).
 *
 * The old status page refused a subscribe button with nothing behind it ("no 'subscribe
 * for updates' with nothing behind it"), so this ships WITH what is behind it:
 * `Services\StatusAlert` mails a confirmed address when a problem the record measured
 * starts and when it is seen to recover, one message per (incident, address), claimed in
 * `gates_broadcast_log` before it is sent (Support\BroadcastLog). The page offers the form
 * only while an email sender is configured.
 *
 * Its own table, for the reason the award and ticket alerts give: `gates_newsletter` is
 * UNIQUE on the address, so a newsletter subscriber asking for status mail would keep
 * their old row and never be found. Same columns and the same verbs as those two, so the
 * site keeps one answer to "how does it ask before it mails you". The address is in the
 * clear because it is where the mail goes. Indexes through SchemaIndex, never the raw
 * `CREATE INDEX IF NOT EXISTS` (CLAUDE.md).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_status_alerts')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_status_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            email_hash TEXT NOT NULL,
            token TEXT NOT NULL,
            ip_hash TEXT NULL,
            created_at TEXT NULL,
            confirm_sent_at TEXT NULL,
            confirmed_at TEXT NULL,
            cancelled_at TEXT NULL
        )" : "
        CREATE TABLE gates_status_alerts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            email_hash CHAR(64) NOT NULL,
            token CHAR(32) NOT NULL,
            ip_hash CHAR(64) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            confirm_sent_at TIMESTAMP NULL DEFAULT NULL,
            confirmed_at TIMESTAMP NULL DEFAULT NULL,
            cancelled_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_status_alerts created\n";
} else {
    echo "  = gates_status_alerts already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_status_alerts', 'uq_sta_who', ['email_hash'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_status_alerts', 'uq_sta_token', ['token'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_status_alerts', 'idx_sta_live', ['confirmed_at', 'cancelled_at']) . "\n";

echo "status alerts OK\n";
