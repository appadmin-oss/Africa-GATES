<?php
/**
 * An append-only record of every mark a judge sets or changes.
 *
 * ── THE ASYMMETRY THIS CLOSES ────────────────────────────────────────────────
 *
 * `gates_vote_snapshots` is hash-chained, and its docblock explains at length why:
 * altering, inserting, deleting or reordering any historical row breaks the chain, so the
 * record of how standings evolved is verifiable after the fact.
 *
 * The INPUTS to that record had no such protection. `JudgeService::saveScore()` writes with
 * `updateOrInsert`, so a judge who scored a nominee 9 and later changed it to 3 left a row
 * saying 3, an `updated_at` that moved, and nothing else. No previous value, no count of
 * revisions, no trace anywhere in the application.
 *
 * So the platform could prove that a published standing had not been edited, and could not
 * answer the question anybody actually asks when a result is disputed: did a judge change
 * their mark, and when relative to everything else that happened?
 *
 * ── WHY A LOG AND NOT VERSIONED SCORES ───────────────────────────────────────
 *
 * The scores table is read on every ballot render, every recompute, every snapshot and
 * every bias check. Turning it into an append-only history would mean every one of those
 * readers has to find the current row, and the one that forgets reads a superseded mark
 * into a published result. The live table stays exactly as it is — one row per
 * (judge, nominee, criterion), always current — and the history sits beside it where only
 * an auditor looks.
 *
 * ── AND WHY IT IS NOT HASH-CHAINED ───────────────────────────────────────────
 *
 * Deliberately, so as not to overclaim. A chain is only tamper-EVIDENT if something walks
 * it and complains, and the standings chain has that (`SnapshotService::verify()`, run by
 * the daily task). Adding a second chain nothing verifies would look like a stronger
 * guarantee than it is. This is an append-only log: no update path, no delete path, and
 * `changed_at` from the application clock. If it later earns a verifier, chaining it is a
 * migration away.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (DB::schema()->hasTable('gates_judge_score_log')) {
    echo "  · gates_judge_score_log already exists\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

DB::statement($sqlite
    ? "CREATE TABLE gates_judge_score_log (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         judge_id INTEGER NOT NULL,
         nominee_id INTEGER NOT NULL,
         criterion_id INTEGER NOT NULL,
         -- NULL means this is the first mark for the pair, not a score of zero. The
         -- distinction is the whole point: 'first scored 7' and 'changed 0 to 7' are
         -- different events and a NOT NULL default would erase the difference.
         old_score INTEGER NULL,
         new_score INTEGER NOT NULL,
         changed_at TEXT NOT NULL
       )"
    : "CREATE TABLE gates_judge_score_log (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         judge_id BIGINT UNSIGNED NOT NULL,
         nominee_id BIGINT UNSIGNED NOT NULL,
         criterion_id BIGINT UNSIGNED NOT NULL,
         old_score TINYINT NULL,
         new_score TINYINT NOT NULL,
         changed_at TIMESTAMP NOT NULL,
         PRIMARY KEY (id),
         KEY idx_jsl_nominee (nominee_id, changed_at),
         KEY idx_jsl_judge (judge_id, changed_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE INDEX IF NOT EXISTS idx_jsl_nominee ON gates_judge_score_log (nominee_id, changed_at)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_jsl_judge   ON gates_judge_score_log (judge_id, changed_at)');
}

echo "  + gates_judge_score_log created\n";
