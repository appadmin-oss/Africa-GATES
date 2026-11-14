<?php
/**
 * One row per stand payment, keyed by its reference.
 *
 * ── THE FAULT ────────────────────────────────────────────────────────────────
 *
 * A stand fee can be paid as a deposit and then a balance, and StandFee::confirm() kept ONE
 * number: it set `paid_naira` to the latest payment rather than adding to it, and treated a
 * second payment no larger than the first as "already recorded". A ₦10,000 deposit then a
 * ₦25,000 balance on a ₦35,000 fee recorded ₦25,000, and the vendor was asked for ₦10,000
 * they had already paid. And `payment_ref` held one reference: a vendor who opened Pay in
 * two tabs and paid the first had it answered "does not belong to a stand". (AUDIT-AND-PLAN
 * EV-12.)
 *
 * ── THE FIX ──────────────────────────────────────────────────────────────────
 *
 * Every reference handed to a gateway gets a row here when it is minted; crediting it is a
 * claim on that row (status `started` → `paid`), so each payment is counted exactly once
 * however many times the callback, the webhook and the sweep report it, and every reference
 * ever minted stays matchable.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_stand_payments')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_stand_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            application_id INTEGER NOT NULL,
            reference TEXT NOT NULL,
            provider TEXT NULL,
            status TEXT NOT NULL DEFAULT 'started',
            amount_naira INTEGER NULL,
            created_at TEXT NULL,
            paid_at TEXT NULL
        )" : "
        CREATE TABLE gates_stand_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(80) NOT NULL,
            provider VARCHAR(30) NULL,
            -- started | paid. VARCHAR, not ENUM (CLAUDE.md: a word outside an ENUM is silent).
            status VARCHAR(16) NOT NULL DEFAULT 'started',
            amount_naira INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_stand_payments created\n";
} else {
    echo "  = gates_stand_payments already present\n";
}

echo SchemaIndex::ensure('gates_stand_payments', 'uq_stand_pay_ref', ['reference'], true) . "\n";
echo SchemaIndex::ensure('gates_stand_payments', 'idx_stand_pay_app', ['application_id']) . "\n";

echo "stand payments OK\n";
