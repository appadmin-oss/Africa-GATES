<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "EMAIL ME WHEN TICKETS GO ON SALE" — the coming-soon half of the events page (owner,
 * 4 Oct 2026: "there's nothing like upcoming events or coming soon events on the site").
 *
 * ── WHY NOT `gates_newsletter` LIKE THE STAND CALL ───────────────────────────
 *
 * The stand call's "email me when it opens" writes a `stands:<slug>` row into the newsletter
 * table, and that table is UNIQUE on the address: somebody already on the newsletter who asks
 * keeps their old row, their old `source`, and is never found by the notice. One row per
 * (event, address) is what "tell ME about THIS event" means, so it gets its own table — the
 * shape `gates_stock_alerts` already has for the shop's "tell me when it's back".
 *
 * ── DOUBLE OPT-IN ─────────────────────────────────────────────────────────────
 *
 * The form takes anybody's address. `confirmed_at` is stamped only by the signed link in the
 * confirmation mail; nothing is ever sent to an unconfirmed row except that one message, at
 * most once a day (`confirm_sent_at`).
 *
 * ── CLAIMED BEFORE IT IS SENT ─────────────────────────────────────────────────
 *
 * `notified_at` is written FIRST, by a guarded UPDATE whose affected-rows count decides who
 * sends — the BroadcastLog rule — so two overlapping cron ticks cannot mail one person twice.
 *
 * `email_hash` is UNIQUE with the event; `email` is kept in the clear because it is the
 * address the notice goes to (declared, like the stock alerts, in the privacy notice's list
 * of tables holding addresses). `token` is the whole credential for confirm and stop.
 * Indexes go through SchemaIndex — never `CREATE INDEX IF NOT EXISTS` (CLAUDE.md).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_event_sale_alerts')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_event_sale_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
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
        CREATE TABLE gates_event_sale_alerts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
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
    echo "  + gates_event_sale_alerts created\n";
} else {
    echo "  = gates_event_sale_alerts already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_event_sale_alerts', 'uq_esa_who', ['event_id', 'email_hash'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_sale_alerts', 'uq_esa_token', ['token'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_sale_alerts', 'idx_esa_due', ['event_id', 'confirmed_at', 'notified_at']) . "\n";

echo "event sale alerts OK\n";
