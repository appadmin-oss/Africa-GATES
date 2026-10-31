<?php
declare(strict_types=1);

use AfricaGates\Services\Recognitions;
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * RECOGNITIONS AND THE ISSUERS WHO GIVE THEM (REFERENCE §11, GAPS §3.1, PHASE-6 item 4).
 *
 * The owner, 5 Oct 2026: "find a smart way around it — build it". The way around is that this
 * platform has ALREADY issued recognitions, and has the record of every one: an award that was
 * announced is a sealed standing in `gates_vote_snapshots` (capture_kind = 'release'), written
 * in the same transaction that crowned it. So the tables are seeded from that record and
 * nothing else — never from a live recomputation, a delayed or unreleased cycle, or the
 * sandbox. See {@see Recognitions::syncCycle()} for the rule, and why each case is excluded.
 *
 * ── THREE TABLES ──────────────────────────────────────────────────────────────
 *
 *   gates_recognition_issuers      WHO recognises. `issuer_type` is VARCHAR over ENUM (house
 *                                  rule for a new status-like column: a value outside an ENUM
 *                                  is `Data truncated` on MySQL, silently). `verified_at` is
 *                                  the platform's own act; government verification is manual,
 *                                  so nothing here ever stamps a government issuer.
 *   gates_recognitions             WHAT was given. Immutable apart from `withdrawn_at` and
 *                                  `withdrawn_reason`: the service has no update path but
 *                                  withdraw(), and RecognitionsTest sweeps src/ for a second
 *                                  writer. `reference` is UNIQUE — it is the citation code and
 *                                  the idempotency key of the seed, so a re-run adds nothing.
 *   gates_recognition_withdrawals  The PUBLIC log. A recognition is never deleted (CLAUDE.md:
 *                                  a record that has been used is retired, never deleted), and
 *                                  every withdrawal is a row here with its reason.
 *
 * `recipient_nominee_id` is beside REFERENCE's `recipient_profile_id` because most nominees
 * have no registry profile, and an award belongs to the person who won it whether or not they
 * ever registered. Indexes through SchemaIndex — never `CREATE INDEX IF NOT EXISTS`.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_recognition_issuers')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_recognition_issuers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            issuer_type TEXT NOT NULL DEFAULT 'organisation',
            name TEXT NOT NULL,
            programme_id INTEGER NULL,
            partner_org_id INTEGER NULL,
            url TEXT NULL,
            verified_at TEXT NULL,
            verified_basis TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_recognition_issuers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            issuer_type VARCHAR(20) NOT NULL DEFAULT 'organisation',
            name VARCHAR(200) NOT NULL,
            programme_id BIGINT UNSIGNED NULL,
            partner_org_id BIGINT UNSIGNED NULL,
            url VARCHAR(400) NULL,
            verified_at TIMESTAMP NULL DEFAULT NULL,
            verified_basis VARCHAR(40) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_recognition_issuers created\n";
} else {
    echo "  = gates_recognition_issuers already present\n";
}

if (!DB::schema()->hasTable('gates_recognitions')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_recognitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            issuer_id INTEGER NOT NULL,
            recipient_profile_id INTEGER NULL,
            recipient_nominee_id INTEGER NULL,
            recipient_name TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'award',
            standing TEXT NULL,
            title TEXT NOT NULL,
            citation TEXT NULL,
            issued_at TEXT NULL,
            reference TEXT NOT NULL,
            visibility TEXT NOT NULL DEFAULT 'public',
            evidence_ids TEXT NULL,
            cycle_id INTEGER NULL,
            category_id INTEGER NULL,
            withdrawn_at TEXT NULL,
            withdrawn_reason TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_recognitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            issuer_id BIGINT UNSIGNED NOT NULL,
            recipient_profile_id BIGINT UNSIGNED NULL,
            recipient_nominee_id BIGINT UNSIGNED NULL,
            recipient_name VARCHAR(200) NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'award',
            standing VARCHAR(20) NULL,
            title VARCHAR(300) NOT NULL,
            citation TEXT NULL,
            issued_at TIMESTAMP NULL DEFAULT NULL,
            reference VARCHAR(64) NOT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'public',
            evidence_ids TEXT NULL,
            cycle_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            withdrawn_at TIMESTAMP NULL DEFAULT NULL,
            withdrawn_reason TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_recognitions created\n";
} else {
    echo "  = gates_recognitions already present\n";
}

if (!DB::schema()->hasTable('gates_recognition_withdrawals')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_recognition_withdrawals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recognition_id INTEGER NOT NULL,
            reason TEXT NOT NULL,
            withdrawn_at TEXT NOT NULL,
            actor_admin_id INTEGER NULL
        )" : "
        CREATE TABLE gates_recognition_withdrawals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recognition_id BIGINT UNSIGNED NOT NULL,
            reason TEXT NOT NULL,
            withdrawn_at TIMESTAMP NOT NULL,
            actor_admin_id BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_recognition_withdrawals created\n";
} else {
    echo "  = gates_recognition_withdrawals already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_recognition_issuers', 'uq_rci_programme', ['programme_id'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_recognitions', 'uq_rec_reference', ['reference'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_recognitions', 'idx_rec_profile', ['recipient_profile_id']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_recognitions', 'idx_rec_nominee', ['recipient_nominee_id']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_recognitions', 'idx_rec_issued', ['issued_at']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_recognition_withdrawals', 'idx_rcw_rec', ['recognition_id']) . "\n";

// Seed from what the platform has already announced. Idempotent (UNIQUE reference), so this
// is safe on every database whatever it holds, and a failure here must not abort the run and
// strand every later migration (CLAUDE.md, MigrateCommand) — the release hook re-runs it.
try {
    echo '  seeded ' . Recognitions::syncAll() . " recognition(s) from sealed releases\n";
} catch (\Throwable $e) {
    echo '  ! seed skipped: ' . $e->getMessage() . "\n";
}

echo "recognitions OK\n";
