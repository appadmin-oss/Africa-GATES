<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "NOTIFY ME" ON A COMING-SOON AWARD — double opt-in (Phase 5, AwardsPage view=soon, GAPS §3.3).
 *
 * An award with no open phase offered nothing at all: `AwardOverview::action()` returned
 * null and the page said "opens soon" with no way to be told. The DC asks for a notify-me
 * with double opt-in.
 *
 * Its own table, for the reason `2027_03_04_event_sale_alerts.php` gives: `gates_newsletter`
 * is UNIQUE on the address, so somebody already subscribed who asks about THIS award keeps
 * their old row and is never found by the notice. One row per (programme, address) is what
 * "tell me when THIS award opens" means. Same shape as the event alerts, read by
 * `Services\AwardAlert` (the same four verbs), so the two cannot drift into two answers to
 * "how does this site ask before it mails you".
 *
 * `confirmed_at` is stamped only by the signed link in the confirmation mail; `notified_at`
 * is CLAIMED by a guarded UPDATE before the notice goes (Support\BroadcastLog's rule). The
 * address is kept in the clear because it is where the notice goes; it is declared in the
 * privacy notice's list of tables that hold addresses. Indexes through SchemaIndex — never
 * `CREATE INDEX IF NOT EXISTS` (CLAUDE.md).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_award_alerts')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_award_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programme_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            email_hash TEXT NOT NULL,
            token TEXT NOT NULL,
            ip_hash TEXT NULL,
            created_at TEXT NULL,
            confirm_sent_at TEXT NULL,
            confirmed_at TEXT NULL,
            notified_at TEXT NULL,
            cancelled_at TEXT NULL
        )" : "
        CREATE TABLE gates_award_alerts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            programme_id INT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            email_hash CHAR(64) NOT NULL,
            token CHAR(32) NOT NULL,
            ip_hash CHAR(64) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            confirm_sent_at TIMESTAMP NULL DEFAULT NULL,
            confirmed_at TIMESTAMP NULL DEFAULT NULL,
            notified_at TIMESTAMP NULL DEFAULT NULL,
            cancelled_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_award_alerts created\n";
} else {
    echo "  = gates_award_alerts already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_award_alerts', 'uq_awa_who', ['programme_id', 'email_hash'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_award_alerts', 'uq_awa_token', ['token'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_award_alerts', 'idx_awa_due', ['programme_id', 'confirmed_at', 'notified_at']) . "\n";

echo "award alerts OK\n";
