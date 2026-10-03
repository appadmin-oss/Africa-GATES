<?php
/**
 * The community return — a share of what supporters raised in a nominee's name.
 *
 * ── WHAT THIS IS ─────────────────────────────────────────────────────────────
 *
 * Not a prize, and not winnings. When somebody contributes to the programme in a
 * nominee's name, a percentage of that contribution is set aside FOR the nominee.
 * It does not depend on winning, because they raised the money either way.
 *
 * That framing decides most of the design. It means the return is a share of
 * CONTRIBUTIONS, never of votes: a nominee with ten thousand free votes raised no
 * money, and paying them from the platform's own pocket would be an unfunded
 * liability that grows with popularity. Every entry in this ledger traces to a
 * confirmed payment.
 *
 * ── WHY A LEDGER AND NOT A BALANCE COLUMN ────────────────────────────────────
 *
 * Because money runs backwards as often as forwards here. A contribution can be
 * refunded, charged back, or belong to a batch that gets voided — and the share
 * accrued on it has to come off again, possibly after the nominee has already been
 * told about it. A mutable `balance` column cannot survive that honestly: it can be
 * decremented, but it cannot say WHY, and the first dispute becomes unanswerable.
 *
 * So: append-only signed entries, balance = SUM(amount_kobo). An accrual is
 * positive, a reversal is negative, and both stay on the record forever. This is
 * the same doctrine gates_points_ledger already uses, and the same reason the
 * standings are hash-chained rather than recomputed.
 *
 * ── KOBO, AS INTEGERS ────────────────────────────────────────────────────────
 *
 * All amounts are kobo in a signed BIGINT. Never naira, never a float. A
 * percentage of ₦1,000 at 30% is exact in kobo (30,000) and a rounding argument in
 * anything else — and this is a ledger somebody will one day reconcile against a
 * bank statement.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ────────────────────────────────────────────
 *
 * No withdrawal. Entry types for payout exist so the schema does not have to change
 * when it is switched on, but nothing writes them yet, and by design: cash-out
 * cannot open while contributions are still going missing on the way IN. Accrue
 * now, show people what they have raised, pay out once the payin path is trusted.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_community_returns')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_community_returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            profile_id INTEGER NULL,
            cycle_id INTEGER NULL,
            entry_type TEXT NOT NULL,
            amount_kobo INTEGER NOT NULL,
            basis_kobo INTEGER NOT NULL DEFAULT 0,
            rate_bps INTEGER NOT NULL DEFAULT 0,
            donation_id INTEGER NULL,
            note TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_community_returns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            -- Denormalised so a return survives the nominee row being archived, and
            -- so a person's total across cycles is one query.
            profile_id BIGINT UNSIGNED NULL,
            -- Which cycle earned it. The withdrawal gate is per cycle: nothing is
            -- payable until that cycle's results are out.
            cycle_id BIGINT UNSIGNED NULL,
            -- accrual  (+) a share set aside from a confirmed contribution
            -- reversal (-) that contribution was refunded, charged back or voided
            -- adjustment(±) a human correction, which must carry a note
            -- hold     (-) frozen pending an integrity finding
            -- release  (+) the hold lifted
            -- forfeit  (-) permanently removed after a finding was upheld
            -- payout   (-) paid to the member. NOTHING WRITES THIS YET.
            entry_type ENUM('accrual','reversal','adjustment','hold','release','forfeit','payout')
                NOT NULL,
            -- SIGNED, and in kobo. The balance is the SUM of this column and nothing
            -- else; there is no cached total to drift out of step with it.
            amount_kobo BIGINT NOT NULL,
            -- What it was computed FROM, and at what rate, both recorded on the row.
            -- The rate is configurable and will change between cycles; without these
            -- an old entry could never be explained, only asserted.
            basis_kobo BIGINT NOT NULL DEFAULT 0,
            rate_bps INT NOT NULL DEFAULT 0,
            donation_id BIGINT UNSIGNED NULL,
            note VARCHAR(400) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            -- ONE ACCRUAL AND ONE REVERSAL PER CONTRIBUTION, EVER. This is what makes
            -- the whole thing safe to re-run: a retried mint, a replayed webhook or a
            -- second reconciliation sweep collides here instead of paying twice.
            UNIQUE KEY uq_return_entry (donation_id, entry_type),
            KEY idx_return_nominee (nominee_id, entry_type),
            KEY idx_return_cycle (cycle_id, entry_type),
            KEY idx_return_profile (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_community_returns created\n";
} else {
    echo "  = gates_community_returns already present\n";
}

if ($sqlite) {
    foreach ([
        // Partial index: SQLite allows several NULL donation_ids (adjustments carry
        // none), while still refusing a second accrual for the same contribution.
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_return_entry ON gates_community_returns(donation_id, entry_type) WHERE donation_id IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_return_nominee ON gates_community_returns(nominee_id, entry_type)',
        'CREATE INDEX IF NOT EXISTS idx_return_cycle ON gates_community_returns(cycle_id, entry_type)',
        'CREATE INDEX IF NOT EXISTS idx_return_profile ON gates_community_returns(profile_id)',
    ] as $sql) {
        try { DB::statement($sql); } catch (\Throwable $e) { echo '  ! ' . $e->getMessage() . "\n"; }
    }
}

echo "community return OK\n";
