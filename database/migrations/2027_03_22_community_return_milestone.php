<?php
/**
 * The community return, in two stages: a locked share before an edition's collective
 * milestone, the full share after it, and a way for a nominee to ask for the money.
 *
 * ── WHAT CHANGES ON THE LEDGER ───────────────────────────────────────────────
 *
 * `gates_community_returns.stage`. Under the milestone model an accrual written before
 * the edition's milestone is confirmed is LOCKED: it is the nominee's, it is shown, and it
 * cannot leave until somebody with authority confirms the milestone. NULL is the ordinary
 * case (the threshold model, and every row written before this migration), so nothing
 * already on the ledger changes meaning.
 *
 * Locked is a property of the ENTRY, not a negative row beside it. Confirmation unlocks
 * every locked entry in the edition at once, and doing that by writing a "release" per
 * entry would be thousands of rows asserting one fact. The fact lives once, in
 * gates_return_milestones, and availability is derived from it.
 *
 * VARCHAR, never ENUM — a value outside an ENUM is `Data truncated` on MySQL and a quiet
 * success on SQLite (CLAUDE.md), and a stage is read by a money path.
 *
 * ── gates_return_milestones ──────────────────────────────────────────────────
 *
 * One row per edition, written only when a person CONFIRMS the milestone. The measure
 * crossing its target is shown, never acted on: the brief asks for an official
 * confirmation, the same way a result is released by a person rather than published by a
 * date. UNIQUE (cycle_id) makes the confirmation happen once however many times it is
 * pressed, and the row records the figure the person was looking at.
 *
 * ── gates_return_withdrawals ─────────────────────────────────────────────────
 *
 * A nominee asking for available money. Shaped on gates_referral_payouts, which has run
 * the same human-reviewed bank-transfer flow for referral commission: requested →
 * approved → paid, or rejected. The bank details are copied onto the row, because the row
 * is the record of where a given transfer went. The checks the request passed are frozen
 * on it too (`checks_json`), so a reviewer reads what was true when it was asked.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require_once __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_community_returns')
    && !$schema->hasColumn('gates_community_returns', 'stage')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_community_returns ADD COLUMN stage TEXT NULL'
        : 'ALTER TABLE gates_community_returns ADD COLUMN stage VARCHAR(12) NULL AFTER rate_bps');
    echo "  + gates_community_returns.stage\n";
} else {
    echo "  = gates_community_returns.stage already present (or no ledger yet)\n";
}

if (!$schema->hasTable('gates_return_milestones')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_return_milestones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NOT NULL,
            target_kobo INTEGER NOT NULL DEFAULT 0,
            measured_kobo INTEGER NOT NULL DEFAULT 0,
            confirmed_by INTEGER NULL,
            confirmed_at TEXT NULL,
            note TEXT NULL
        )" : "
        CREATE TABLE gates_return_milestones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cycle_id BIGINT UNSIGNED NOT NULL,
            -- The target in force, and what the edition had raised, at the moment of
            -- confirmation. Both are recorded because both can later be argued about.
            target_kobo BIGINT NOT NULL DEFAULT 0,
            measured_kobo BIGINT NOT NULL DEFAULT 0,
            confirmed_by BIGINT UNSIGNED NULL,
            confirmed_at TIMESTAMP NULL,
            note VARCHAR(400) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_return_milestones created\n";
} else {
    echo "  = gates_return_milestones already present\n";
}
echo SchemaIndex::ensure('gates_return_milestones', 'uq_return_milestone_cycle', ['cycle_id'], true) . "\n";

if (!$schema->hasTable('gates_return_withdrawals')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_return_withdrawals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            user_id INTEGER NULL,
            amount_kobo INTEGER NOT NULL,
            fee_kobo INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'requested',
            bank_name TEXT NULL,
            account_name TEXT NULL,
            account_number TEXT NULL,
            checks_json TEXT NULL,
            rules_accepted_at TEXT NULL,
            requested_at TEXT NULL,
            reviewed_by INTEGER NULL,
            reviewed_at TEXT NULL,
            review_note TEXT NULL,
            payment_ref TEXT NULL,
            paid_by INTEGER NULL,
            paid_at TEXT NULL
        )" : "
        CREATE TABLE gates_return_withdrawals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            -- The signed-in member who asked: the claim holder at the time.
            user_id BIGINT UNSIGNED NULL,
            -- What leaves the ledger, and the published fee taken from it. The transfer is
            -- amount - fee; both recorded so the statement can show the arithmetic.
            amount_kobo BIGINT NOT NULL,
            fee_kobo BIGINT NOT NULL DEFAULT 0,
            -- requested | approved | paid | rejected. VARCHAR, read through
            -- CommunityReturnService::WD_* and nowhere else.
            status VARCHAR(12) NOT NULL DEFAULT 'requested',
            bank_name VARCHAR(120) NULL,
            account_name VARCHAR(160) NULL,
            account_number VARCHAR(32) NULL,
            checks_json TEXT NULL,
            rules_accepted_at TIMESTAMP NULL,
            requested_at TIMESTAMP NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at TIMESTAMP NULL,
            review_note VARCHAR(400) NULL,
            payment_ref VARCHAR(120) NULL,
            paid_by BIGINT UNSIGNED NULL,
            paid_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_return_withdrawals created\n";
} else {
    echo "  = gates_return_withdrawals already present\n";
}
echo SchemaIndex::ensure('gates_return_withdrawals', 'idx_return_wd_nominee', ['nominee_id', 'status']) . "\n";
echo SchemaIndex::ensure('gates_return_withdrawals', 'idx_return_wd_status', ['status']) . "\n";

echo "community return milestone OK\n";
