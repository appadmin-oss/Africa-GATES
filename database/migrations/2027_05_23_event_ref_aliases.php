<?php
/**
 * References a ticket row USED to answer to, so a payment made against one still finds it.
 *
 * ── THE FAULT ────────────────────────────────────────────────────────────────
 *
 * A registration's `reference` is the only thing a gateway knows it by, and two paths
 * overwrote it while a payment against the old one could still be settling: a checkout
 * restarted at the gateway (EventTicketService::rotateReference) and a waitlist offer that
 * expired unused (EventWaitlist::expireOffers, which also cleared it to NULL). A bank
 * transfer begun under the old reference then arrived to "unmatched" — money taken, no
 * ticket, and no row anybody could find it on. (AUDIT-AND-PLAN EV-14.)
 *
 * ── THE FIX ──────────────────────────────────────────────────────────────────
 *
 * Every superseded reference is kept here against its registration, and
 * EventTicketService::byReference() falls back to it. What confirm() then does with a row
 * that is no longer waiting for that payment (a seat if one is free, otherwise the money
 * recorded as owed back and a person told) is in confirm().
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_event_ref_aliases')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_event_ref_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration_id INTEGER NOT NULL,
            reference TEXT NOT NULL,
            why TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_event_ref_aliases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(80) NOT NULL,
            why VARCHAR(60) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_event_ref_aliases created\n";
} else {
    echo "  = gates_event_ref_aliases already present\n";
}

echo SchemaIndex::ensure('gates_event_ref_aliases', 'uq_event_ref_alias', ['reference'], true) . "\n";
echo SchemaIndex::ensure('gates_event_ref_aliases', 'idx_event_ref_alias_reg', ['registration_id']) . "\n";

echo "event reference aliases OK\n";
