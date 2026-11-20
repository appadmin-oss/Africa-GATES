<?php
/**
 * A settable questionnaire deadline, and what happens when it passes.
 *
 * ── WHY A TABLE AND NOT A DATE ON THE CYCLE ──────────────────────────────────
 *
 * `QuestionnaireService::deadline()` already tells nominees a date, and it DERIVES that
 * date from `results_date` or `voting_close`. Which means the deadline an organiser
 * communicates is a side effect of when they scheduled the results — two decisions that
 * have no business being the same field. Move the results date and every invitation
 * already sent is now wrong.
 *
 * So the deadline becomes its own fact, with the derived value kept as the fallback so
 * nothing that already worked changes on deploy.
 *
 * ── AND WHY DISQUALIFICATION IS A COLUMN AND A LEDGER, NOT A DELETE ──────────
 *
 * "Auto-disqualify if not filled" is a rule that acts on people while nobody is watching.
 * A cron tick that flips `status` and moves on leaves an organiser unable to answer the one
 * question they will be asked — "why is this nominee gone?" — so:
 *
 *   - `autodisqualify_at` on the submission records WHEN the rule fired.
 *   - `disqualify_note` records what the rule was at the time.
 *   - the status is reversible: reinstating clears both and stamps nothing new.
 *   - and the rule fires only for a cycle whose organiser explicitly turned it on.
 *
 * `grace_days` exists because a deadline and an enforcement date should not be the same
 * instant. Post arrives late, invitations land in spam, and somebody submitting four hours
 * after midnight has not declined to take part.
 *
 * ── THE STATUS ENUM ──────────────────────────────────────────────────────────
 *
 * MySQL declares `status ENUM('draft','submitted','withdrawn')`, so writing 'disqualified'
 * to it fails in strict mode and silently becomes '' otherwise. The ENUM is widened here
 * for the same reason `2026_07_26_cycle_shortlisting_phase.php` widened the cycle's: a
 * status the code writes and the column cannot hold is a bug that only appears in
 * production, because SQLite stores it as TEXT and accepts anything.
 *
 * ── AND WHY IT IS DATED 09_30 ────────────────────────────────────────────────
 *
 * Migrations run in FILENAME order, not by real date, and this one ALTERs
 * `gates_nominee_submissions` — created by `2026_08_29_questionnaire.php` and extended as
 * late as `2026_09_26_questionnaire_interview.php`. Named for today it would sort ahead of
 * the table it alters and fail on a fresh install while passing on every existing one.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_questionnaire_policy')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_questionnaire_policy (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NOT NULL,
            -- NULL = fall back to the derived date (results_date, then voting_close).
            deadline_at TEXT NULL,
            -- 1 = a nominee who has not submitted by deadline + grace is disqualified.
            autodisqualify INTEGER NOT NULL DEFAULT 0,
            grace_days INTEGER NOT NULL DEFAULT 3,
            updated_at TEXT NULL,
            updated_by INTEGER NULL
        )" : "
        CREATE TABLE gates_questionnaire_policy (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cycle_id BIGINT UNSIGNED NOT NULL,
            deadline_at DATETIME NULL DEFAULT NULL,
            autodisqualify TINYINT(1) NOT NULL DEFAULT 0,
            grace_days SMALLINT UNSIGNED NOT NULL DEFAULT 3,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            updated_by BIGINT UNSIGNED NULL,
            UNIQUE KEY uq_qpolicy_cycle (cycle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_questionnaire_policy created\n";

    if ($sqlite) {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_qpolicy_cycle ON gates_questionnaire_policy(cycle_id)');
    }
} else {
    echo "  = gates_questionnaire_policy already present\n";
}

// ── the two columns that make a disqualification answerable ─────────────────
foreach (['autodisqualify_at' => $sqlite ? 'TEXT' : 'DATETIME NULL DEFAULT NULL',
          'disqualify_note'   => $sqlite ? 'TEXT' : 'VARCHAR(300) NULL DEFAULT NULL'] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_nominee_submissions', $col)) {
        DB::statement("ALTER TABLE gates_nominee_submissions ADD COLUMN {$col} {$type}");
        echo "  + gates_nominee_submissions.{$col} added\n";
    } else {
        echo "  = gates_nominee_submissions.{$col} already present\n";
    }
}

// ── widen the status ENUM so 'disqualified' can actually be stored ──────────
//
// MySQL only. SQLite's column is TEXT and already holds anything, which is exactly why
// this gap could survive a green test suite.
if (!$sqlite) {
    try {
        DB::statement("ALTER TABLE gates_nominee_submissions
            MODIFY status ENUM('draft','submitted','withdrawn','disqualified')
            NOT NULL DEFAULT 'draft'");
        echo "  ~ gates_nominee_submissions.status widened for 'disqualified'\n";
    } catch (\Throwable $e) {
        // Already widened, or the column was created as VARCHAR by an earlier path. Either
        // way the value fits; a hard failure here would stop every later migration.
        echo "  = gates_nominee_submissions.status not modified (" . $e->getMessage() . ")\n";
    }
}
