<?php
/**
 * Event referrals: a member's own code, and what it has earned.
 *
 * ── THE RULES THIS SCHEMA HAS TO MAKE TRUE ──────────────────────────────────
 *   · A code belongs to an ACCOUNT. Anonymous referral is not a thing here, because
 *     there would be nobody to pay.
 *   · A referral counts only when a ticket is actually PAID. Counting reservations
 *     would mean ten abandoned bookings unlock earning, which is a five-minute attack.
 *   · Ten paid referrals before anything is earned, then 10% of what was paid.
 *   · Exactly once per registration, forever.
 *
 * The last one is why `registration_id` is UNIQUE rather than merely indexed. Confirmation
 * can be reached three ways on this platform — the browser callback, the gateway webhook,
 * and the reconciliation sweep — and they race. A unique key means the second and third
 * arrival collide with the first instead of paying commission twice on one ticket. It is
 * the same reasoning as the idempotent pending→confirmed transition it hangs off.
 *
 * ── WHY COMMISSION IS STORED AND NOT COMPUTED ON READ ───────────────────────
 * 10% of a figure that is already in the database looks like something to derive. But the
 * RATE can change, and a rate change must not silently restate what somebody has already
 * been told they earned. The naira figure is written at the moment it is earned, against
 * the amount actually paid, and never recalculated.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_referral_codes')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_referral_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_referral_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- One code per member. UNIQUE so a double-click on 'get my link' cannot mint
            -- a second code and split somebody's own referrals across two identities.
            user_id BIGINT UNSIGNED NOT NULL,
            -- Typed by hand into the same box as a discount code, so it is stored the way
            -- PromoCode normalises: upper case, no spaces.
            code VARCHAR(32) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ref_user (user_id),
            UNIQUE KEY uq_ref_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_referral_codes created\n";
} else {
    echo "  = gates_referral_codes already present\n";
}

if (!DB::schema()->hasTable('gates_referral_credits')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_referral_credits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            registration_id INTEGER NOT NULL,
            event_id INTEGER NULL,
            paid_naira INTEGER NOT NULL DEFAULT 0,
            commission_naira INTEGER NOT NULL DEFAULT 0,
            rate_bps INTEGER NOT NULL DEFAULT 1000,
            paid_out_at TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_referral_credits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code_id BIGINT UNSIGNED NOT NULL,
            -- Denormalised from the code deliberately: a payout report reads by member, and
            -- this keeps that a single-table query.
            user_id BIGINT UNSIGNED NOT NULL,
            -- The whole idempotency guarantee. See the note above.
            registration_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NULL,
            -- What the buyer actually paid, AFTER any discount. 'What is paid for events'
            -- is the gross the platform received, not the pre-discount list price.
            paid_naira INT UNSIGNED NOT NULL DEFAULT 0,
            commission_naira INT UNSIGNED NOT NULL DEFAULT 0,
            -- Basis points, stamped per row, so changing the rate later cannot restate
            -- what somebody has already been shown.
            rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 1000,
            -- Set when an operator has actually paid this out. NULL = accrued, unpaid.
            paid_out_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_credit_once (registration_id),
            KEY idx_credit_user (user_id, paid_out_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_referral_credits created\n";
} else {
    echo "  = gates_referral_credits already present\n";
}

// Which code brought this registration in. Nullable: almost every booking has no referrer,
// and a column that must be filled would make referral a requirement of buying a ticket.
if (!DB::schema()->hasColumn('gates_event_registrations', 'referral_code')) {
    DB::statement($sqlite
        ? "ALTER TABLE gates_event_registrations ADD COLUMN referral_code TEXT NULL"
        : "ALTER TABLE gates_event_registrations ADD COLUMN referral_code VARCHAR(32) NULL");
    echo "  + gates_event_registrations.referral_code added\n";
} else {
    echo "  = gates_event_registrations.referral_code already present\n";
}

if ($sqlite) {
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_ref_user     ON gates_referral_codes (user_id)');
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_ref_code     ON gates_referral_codes (code)');
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_credit_once  ON gates_referral_credits (registration_id)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_credit_user        ON gates_referral_credits (user_id, paid_out_at)');
    echo "  + sqlite indexes ensured\n";
}
