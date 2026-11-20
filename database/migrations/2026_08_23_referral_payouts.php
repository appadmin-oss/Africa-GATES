<?php
/**
 * Turning an owed referral balance into money that actually leaves.
 *
 * ── THE GAP THIS CLOSES ──────────────────────────────────────────────────────
 *
 * Commission accrued to `gates_referral_credits` with `paid_out_at IS NULL` meaning owed,
 * and there was no way to pay it. `HANDOFF.md` §4 left the whole thing open on one
 * question — how money actually leaves — and the Finance panel deliberately shipped with
 * no "mark as paid" button, because stamping `paid_out_at` with no transfer behind it
 * makes the ledger claim somebody was paid and destroys the evidence that they were not.
 *
 * ── SO THE ROW *IS* THE EVIDENCE ─────────────────────────────────────────────
 *
 * A payout is requested by the member, carries the bank details they gave, names the exact
 * credits it covers, and is only marked paid by a named admin recording a transfer
 * reference. `paid_out_at` on the credits is stamped from that act and from nothing else.
 *
 * Bank details live on the PAYOUT, not on the member. Each row then records where that
 * money actually went, which is what somebody reconstructing a transfer six months later
 * needs — a single mutable field on the account would show only the latest details and
 * silently misdescribe every earlier payment.
 *
 * `credit_ids` freezes the set at request time, so a credit earned after the request is
 * not swept into it and the amount cannot drift between request and payment.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_referral_payouts')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_referral_payouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount_naira INTEGER NOT NULL DEFAULT 0,
            -- The credits this request covers, frozen when it was made.
            credit_ids TEXT NULL,
            -- requested → paid, or requested → rejected. Nothing else.
            status TEXT NOT NULL DEFAULT 'requested',
            bank_name TEXT NULL,
            account_name TEXT NULL,
            account_number TEXT NULL,
            -- The operator's own transfer reference. Required to mark one paid.
            payment_ref TEXT NULL,
            note TEXT NULL,
            requested_at TEXT NULL,
            settled_at TEXT NULL,
            settled_by INTEGER NULL
        )" : "
        CREATE TABLE gates_referral_payouts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            amount_naira INT UNSIGNED NOT NULL DEFAULT 0,
            credit_ids TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'requested',
            bank_name VARCHAR(120) NULL,
            account_name VARCHAR(160) NULL,
            account_number VARCHAR(32) NULL,
            payment_ref VARCHAR(120) NULL,
            note VARCHAR(400) NULL,
            requested_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            settled_at TIMESTAMP NULL DEFAULT NULL,
            settled_by BIGINT UNSIGNED NULL,
            KEY idx_payout_status(status, requested_at),
            KEY idx_payout_user(user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_referral_payouts created\n";

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_payout_status ON gates_referral_payouts(status, requested_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_payout_user ON gates_referral_payouts(user_id, status)');
    }
}
