<?php
/**
 * Recovering votes the PLATFORM failed to verify.
 *
 * ── THE SITUATION ────────────────────────────────────────────────────────────
 *
 * A supporter opens the ballot, picks a nominee, types their address and presses
 * the button. We write a token recording exactly that intent — who (hashed), which
 * nominee, which award, when — and then try to email them a code. Sometimes that
 * send fails: SMTP credentials expire, the relay rate-limits us, the gateway is
 * down. The person waits for a code that is never coming, and their vote does not
 * exist.
 *
 * They did everything right. We dropped it. Excluding them makes the result wrong
 * in a way that is entirely our fault, and it is invisible: a vote that never
 * happened leaves no complaint from most people, just a quieter tally.
 *
 * ── WHY THE EVIDENCE HAS TO BE OURS ──────────────────────────────────────────
 *
 * The obvious way to fix this is to let an admin add the missing votes. That is
 * also the way to turn a repair mechanism into a ballot-stuffing tool, because
 * nothing in it distinguishes "somebody we failed" from "somebody we invented".
 *
 * So no human supplies the list. It is derived from records the platform wrote
 * about ITSELF, before anybody knew they would be needed:
 *
 *   gates_otp_tokens already holds the intent — email_hash, nominee_id, award_id,
 *   created_at — for every attempt. What it did NOT hold is whether the code
 *   actually left the building. requestOtp() knew: it checked the send result,
 *   returned an error to the visitor, and threw the fact away. Three columns fix
 *   that, and they are the entire basis on which a vote may later be recovered.
 *
 * `delivery_state`:
 *   sent    — the mailer accepted it. If they did not vote, that was their choice,
 *             and their choice is not ours to overturn. NOT recoverable.
 *   failed  — we have our own log line saying we could not deliver it. Recoverable.
 *   unknown — the default, and what every row written before this migration will
 *             say forever. Deliberately NOT recoverable: we do not know that we
 *             failed those people, and "we cannot rule it out" is not evidence.
 *             The feature therefore starts working from deployment forward, which
 *             is the honest cost of not guessing.
 *
 * ── WHY THERE IS NO NEW VOTE TYPE ────────────────────────────────────────────
 *
 * A recovered vote IS an ordinary organic vote. The person asked for it, we can
 * show they asked, and only our outage stopped it — so it lands in gates_votes as
 * `standard` and counts toward the CPI like any other. Inventing a `recovered`
 * vote_type would mean every existing query that reasons about vote types has to
 * learn about it, and the ones that forget would silently exclude a real person's
 * real vote.
 *
 * Provenance instead of a category: `recovery_batch_id` points at the batch that
 * authorised it. Null on every vote cast the normal way, so "which votes did we
 * put there ourselves" stays a one-column question, and voiding a batch can find
 * exactly its own rows.
 *
 * ── AND IT IS STILL A TWO-PERSON, PUBLISHED, REVERSIBLE ACT ──────────────────
 *
 * Derived evidence removes the worst risk, not all of it. During a delivery outage
 * every send fails, including sends to addresses somebody typed in bad faith, so a
 * recovery run still gets the full treatment: a batch prepared by one admin and
 * approved by another, a fraud and IP/device cluster screen, a cap as a share of
 * the votes we did verify, public disclosure of every applied batch, and a void
 * that reverses the counts while keeping the record.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1. Did the code actually go out? ─────────────────────────────────────────
$tokenCols = [
    'delivery_state' => $sqlite
        ? "TEXT NOT NULL DEFAULT 'unknown'"
        : "ENUM('unknown','sent','failed') NOT NULL DEFAULT 'unknown'",
    'delivery_error' => $sqlite ? 'TEXT NULL' : 'VARCHAR(300) NULL',
    'delivery_at'    => $sqlite ? 'TEXT NULL' : 'TIMESTAMP NULL DEFAULT NULL',
];
foreach ($tokenCols as $col => $type) {
    if ($schema->hasColumn('gates_otp_tokens', $col)) {
        echo "  = gates_otp_tokens.$col already present\n";
        continue;
    }
    try {
        DB::statement("ALTER TABLE gates_otp_tokens ADD COLUMN $col $type");
        echo "  + gates_otp_tokens.$col added\n";
    } catch (\Throwable $e) {
        echo "  ! $col skipped: " . $e->getMessage() . "\n";
    }
}
try {
    $sqlite
        ? DB::statement('CREATE INDEX IF NOT EXISTS idx_otp_delivery ON gates_otp_tokens(purpose, delivery_state, is_used)')
        : DB::statement('ALTER TABLE gates_otp_tokens ADD KEY idx_otp_delivery (purpose, delivery_state, is_used)');
    echo "  + idx_otp_delivery in place\n";
} catch (\Throwable $e) {
    echo '  = idx_otp_delivery: ' . $e->getMessage() . "\n";
}

// ── 2. Which batch put this vote here? ───────────────────────────────────────
// A column rather than a new vote_type, on purpose — see the note at the top.
if (!$schema->hasColumn('gates_votes', 'recovery_batch_id')) {
    try {
        DB::statement($sqlite
            ? 'ALTER TABLE gates_votes ADD COLUMN recovery_batch_id INTEGER NULL'
            : 'ALTER TABLE gates_votes ADD COLUMN recovery_batch_id BIGINT UNSIGNED NULL');
        echo "  + gates_votes.recovery_batch_id added\n";
    } catch (\Throwable $e) {
        echo '  ! recovery_batch_id skipped: ' . $e->getMessage() . "\n";
    }
} else {
    echo "  = gates_votes.recovery_batch_id already present\n";
}
try {
    $sqlite
        ? DB::statement('CREATE INDEX IF NOT EXISTS idx_votes_recovery ON gates_votes(recovery_batch_id)')
        : DB::statement('ALTER TABLE gates_votes ADD KEY idx_votes_recovery (recovery_batch_id)');
} catch (\Throwable) { /* already there */ }

// ── 3. The batch: the unit of authorisation ──────────────────────────────────
if (!$schema->hasTable('gates_vote_recovery_batches')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_vote_recovery_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT NULL,
            cycle_id INTEGER NOT NULL,
            window_from TEXT NOT NULL,
            window_to TEXT NOT NULL,
            incident_note TEXT NOT NULL,
            candidate_count INTEGER NOT NULL DEFAULT 0,
            applied_count INTEGER NOT NULL DEFAULT 0,
            rejected_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'draft',
            created_by INTEGER NULL,
            submitted_by INTEGER NULL,
            submitted_at TEXT NULL,
            approved_by INTEGER NULL,
            approved_at TEXT NULL,
            decision_note TEXT NULL,
            applied_at TEXT NULL,
            voided_by INTEGER NULL,
            voided_at TEXT NULL,
            void_reason TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_vote_recovery_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- AGR-XXXXXX-C. Printed on the public disclosure so a reader can name
            -- the thing that put these votes on the tally and ask about it.
            reference VARCHAR(24) NULL,
            cycle_id BIGINT UNSIGNED NOT NULL,
            -- The outage window. Only tokens issued inside it are considered, so a
            -- batch is a claim about one specific failure with a beginning and an
            -- end, not a standing licence to sweep up unredeemed codes.
            window_from TIMESTAMP NOT NULL,
            window_to   TIMESTAMP NOT NULL,
            -- What went wrong, in the operator's own words. Required: the approver
            -- is being asked to agree that the platform failed these people, and
            -- they cannot agree to that without being told what happened.
            incident_note TEXT NOT NULL,
            candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
            applied_count   INT UNSIGNED NOT NULL DEFAULT 0,
            rejected_count  INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('draft','submitted','approved','rejected','applied','voided')
                NOT NULL DEFAULT 'draft',
            created_by   BIGINT UNSIGNED NULL,
            submitted_by BIGINT UNSIGNED NULL,
            submitted_at TIMESTAMP NULL DEFAULT NULL,
            -- Must differ from created_by and submitted_by. Enforced in
            -- VoteRecoveryService; these columns are what make it auditable after.
            approved_by  BIGINT UNSIGNED NULL,
            approved_at  TIMESTAMP NULL DEFAULT NULL,
            decision_note VARCHAR(600) NULL,
            applied_at   TIMESTAMP NULL DEFAULT NULL,
            voided_by    BIGINT UNSIGNED NULL,
            voided_at    TIMESTAMP NULL DEFAULT NULL,
            void_reason  VARCHAR(600) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_recovery_ref (reference),
            KEY idx_recovery_cycle (cycle_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_vote_recovery_batches created\n";
} else {
    echo "  = gates_vote_recovery_batches already present\n";
}

// ── 4. One row per dropped attempt, pinned to the token that proves it ───────
if (!$schema->hasTable('gates_vote_recovery_rows')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_vote_recovery_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            otp_token_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            nominee_id INTEGER NOT NULL,
            voter_email_hash TEXT NOT NULL,
            requested_at TEXT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            reject_reason TEXT NULL,
            vote_id INTEGER NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_vote_recovery_rows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_id BIGINT UNSIGNED NOT NULL,
            -- THE EVIDENCE. Every recovered vote points at the exact token row that
            -- recorded the request and the failed delivery. A recovery with no token
            -- behind it is not a recovery.
            otp_token_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            nominee_id BIGINT UNSIGNED NOT NULL,
            voter_email_hash VARCHAR(64) NOT NULL,
            requested_at TIMESTAMP NULL DEFAULT NULL,
            status ENUM('pending','applied','rejected','voided') NOT NULL DEFAULT 'pending',
            reject_reason VARCHAR(200) NULL,
            vote_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            -- One recovery per token, ever, across every batch. Without this a token
            -- could be swept into two batches and the same dropped vote counted twice.
            UNIQUE KEY uq_recovery_token (otp_token_id),
            KEY idx_recovery_rows_batch (batch_id, status),
            KEY idx_recovery_rows_nominee (nominee_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_vote_recovery_rows created\n";
} else {
    echo "  = gates_vote_recovery_rows already present\n";
}

if ($sqlite) {
    foreach ([
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_recovery_token ON gates_vote_recovery_rows(otp_token_id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_recovery_ref ON gates_vote_recovery_batches(reference)',
        'CREATE INDEX IF NOT EXISTS idx_recovery_rows_batch ON gates_vote_recovery_rows(batch_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_recovery_cycle ON gates_vote_recovery_batches(cycle_id, status)',
    ] as $sql) {
        try { DB::statement($sql); } catch (\Throwable $e) { echo '  ! ' . $e->getMessage() . "\n"; }
    }
}

echo "vote recovery OK\n";
