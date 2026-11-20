<?php
/**
 * Telling people apart from records (Admin\Services\NomineePeople, VoterPeople).
 *
 *   gates_votes.voter_canon_hash   the voter's address reduced to the inbox it reaches
 *                                  (Support\EmailCanon), hashed. Written by every organic
 *                                  vote from now on; NULL on rows cast before it, which
 *                                  only ever kept a hash of the address as typed and so
 *                                  cannot be reduced after the fact. Used to COUNT people,
 *                                  never to decide whether somebody may vote — the one-vote
 *                                  rule stays on `voter_email_hash`, unchanged, because
 *                                  moving it would change who is allowed to vote mid-cycle.
 *
 *   gates_identity_verdicts        a ruling on whether two records are one person: by a
 *                                  person, or by the AI. One row per pair (and kind), so a
 *                                  ruling is asked for once and kept. A person's ruling is
 *                                  never overwritten by the AI's; the AI's is replaced by a
 *                                  person's. "Different" rulings on nominees in ONE
 *                                  category stay in gates_merge_dismissals, which the merge
 *                                  screen already reads — this table holds the rest
 *                                  (across categories, and "same person").
 *
 * VARCHAR, not ENUM, for every word (CLAUDE.md). Idempotent + driver-aware; indexes through
 * SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_votes') && !$schema->hasColumn('gates_votes', 'voter_canon_hash')) {
    DB::statement('ALTER TABLE gates_votes ADD COLUMN voter_canon_hash ' . ($sqlite ? 'TEXT NULL' : 'VARCHAR(64) NULL DEFAULT NULL'));
    echo "  + gates_votes.voter_canon_hash\n";
}
if ($schema->hasTable('gates_votes')) {
    echo '  ' . SchemaIndex::ensure('gates_votes', 'idx_votes_canon', ['voter_canon_hash']) . "\n";
}

if (!$schema->hasTable('gates_identity_verdicts')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_identity_verdicts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              kind TEXT NOT NULL DEFAULT 'nominee',
              pair_key TEXT NOT NULL,
              a_id INTEGER NOT NULL,
              b_id INTEGER NOT NULL,
              verdict TEXT NOT NULL,
              source TEXT NOT NULL DEFAULT 'human',
              confidence REAL NULL,
              reason TEXT NULL,
              decided_by INTEGER NULL,
              created_at TEXT NOT NULL
           )"
        : "CREATE TABLE gates_identity_verdicts (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              kind VARCHAR(16) NOT NULL DEFAULT 'nominee',
              pair_key VARCHAR(48) NOT NULL,
              a_id BIGINT UNSIGNED NOT NULL,
              b_id BIGINT UNSIGNED NOT NULL,
              verdict VARCHAR(12) NOT NULL,
              source VARCHAR(8) NOT NULL DEFAULT 'human',
              confidence DECIMAL(4,3) NULL,
              reason VARCHAR(500) NULL,
              decided_by BIGINT UNSIGNED NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_identity_verdicts\n";
}
echo '  ' . SchemaIndex::ensure('gates_identity_verdicts', 'uq_identity_pair', ['kind', 'pair_key'], true) . "\n";

echo "identity resolution OK\n";
