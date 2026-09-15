<?php
/**
 * A judge saying the dossier map misread the dossier.
 *
 * ── WHY THIS NEEDS TO EXIST ──────────────────────────────────────────────────
 *
 * `JudgeAssist` writes a map of a nominee's evidence and puts it ABOVE that evidence,
 * where it is read first and frames everything after it. That placement is deliberate and
 * it is also the whole risk: a map is not usually wrong about what it read, it is wrong
 * about what it is a map OF, and only the reader can tell.
 *
 * The one person positioned to notice is the judge, reading the map and the dossier side
 * by side — and they had no way to say so. A model artefact that shapes a judging
 * decision, is sometimes wrong, and has no correction path is the same fault this
 * codebase names in §17 from the other direction: a signal nothing collects rather than
 * one nothing reads.
 *
 * ── ONE FLAG PER JUDGE PER NOMINEE ───────────────────────────────────────────
 *
 * UNIQUE, so pressing it twice is not two complaints. And per JUDGE rather than per map:
 * the map is cached and shared across a panel, so a judge who flags it hides it for
 * themselves and tells the operator, without deciding for the other four judges what
 * they are allowed to read. Whether a map that three judges have flagged should be
 * withdrawn from the panel is a decision for a person, and the audit screen is where
 * they will see it.
 *
 * `reason` is optional and free text. The count is the signal an operator acts on; the
 * reason is what tells them WHICH way it was wrong, and a required box would mostly
 * produce empty complaints from judges in a hurry.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (DB::schema()->hasTable('gates_judge_map_flags')) {
    echo "  · gates_judge_map_flags already exists\n";
    echo "judge map flags OK\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

DB::statement($sqlite
    ? "CREATE TABLE gates_judge_map_flags (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         judge_id INTEGER NOT NULL,
         nominee_id INTEGER NOT NULL,
         reason TEXT NULL,
         created_at TEXT NOT NULL
       )"
    : "CREATE TABLE gates_judge_map_flags (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         judge_id BIGINT UNSIGNED NOT NULL,
         nominee_id BIGINT UNSIGNED NOT NULL,
         -- VARCHAR and not TEXT: this is one sentence from somebody mid-ballot, and a
         -- column that invites an essay gets one nobody reads.
         reason VARCHAR(500) NULL,
         created_at TIMESTAMP NOT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_map_flag (judge_id, nominee_id),
         -- The read the audit screen makes: which nominees' maps are being disputed.
         KEY idx_map_flag_nominee (nominee_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($sqlite) {
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_map_flag ON gates_judge_map_flags (judge_id, nominee_id)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_map_flag_nominee ON gates_judge_map_flags (nominee_id)');
}

echo "  + gates_judge_map_flags created\n";
echo "judge map flags OK\n";
