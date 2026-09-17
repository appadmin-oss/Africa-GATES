<?php
/**
 * WHAT THE BOT WAS STOPPED FROM SAYING.
 *
 * ── WHY THIS IS A TABLE AND NOT AN error_log() LINE ──────────────────────────
 *
 * {@see \AfricaGates\Services\InterviewGuard} refuses questions that invent a fact, praise
 * the nominee, promise a result, or wander into ground a panel may not weigh. A guard that
 * silently drops those is better than no guard — and it leaves nobody able to answer the
 * one question anybody signing off on an AI-run interview will ask, which is "how often
 * does it try?"
 *
 * On this deployment `error_log()` goes to a cPanel file that is rotated, unreadable
 * without file access, and already 388 lines of one repeated fatal on a bad day. It is
 * where things go to not be counted.
 *
 * So the refusals are rows: countable by reason, readable per sitting, and available to
 * the console next to the interview they happened in. That turns "the AI is safe, trust
 * us" into a number a panel can look at.
 *
 * ── THE REFUSED TEXT IS KEPT, DELIBERATELY ───────────────────────────────────
 *
 * It would be tidier to store only the reason. But "ungrounded × 14" tells an operator
 * nothing they can act on, while the actual sentence the model tried to say tells them
 * whether the pack is badly written, the recogniser is mangling a name, or the model is
 * genuinely confabulating — three different fixes.
 *
 * This is machine output, never a nominee's words, so it carries none of the consent
 * weight the transcript does. It is capped at 600 characters because
 * {@see \AfricaGates\Services\InterviewVoice::MAX_CHARS} is the most that could ever have
 * been spoken anyway.
 *
 * ── AND THE FOREIGN KEY IS DELIBERATELY ABSENT ───────────────────────────────
 *
 * `interview_id` is nullable and unconstrained. A refusal is evidence about the SYSTEM,
 * not about the sitting, and it must survive the sitting being deleted — otherwise the
 * safety record disappears exactly when somebody tidies up the interview that produced it.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_interview_guard_log')) {
    if ($sqlite) {
        DB::statement('
            CREATE TABLE gates_interview_guard_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                interview_id INTEGER NULL,
                reason TEXT NOT NULL,
                note TEXT NULL,
                text TEXT NULL,
                created_at TEXT NULL
            )
        ');
    } else {
        DB::statement('
            CREATE TABLE gates_interview_guard_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                interview_id BIGINT UNSIGNED NULL,
                -- A closed set in practice (ungrounded, evaluative, promise, off_limits,
                -- injected, not_a_question, too_long) but VARCHAR rather than ENUM: adding
                -- a rule should be a code change, not a migration on a live table.
                reason VARCHAR(32) NOT NULL,
                note VARCHAR(400) NULL,
                text VARCHAR(600) NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY(id),
                KEY idx_guard_interview(interview_id),
                KEY idx_guard_reason(reason, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}

// The tally reads by date across all sittings; the per-sitting view reads by interview_id.
// Both are covered above on MySQL; SQLite needs them stated separately.
if ($sqlite) {
    try {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_guard_interview
                       ON gates_interview_guard_log (interview_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_guard_reason
                       ON gates_interview_guard_log (reason, created_at)');
    } catch (\Throwable) {
        // Indexes are an optimisation on a table that will hold hundreds of rows, not
        // millions. A failure here is not worth stopping a migration run for.
    }
}

echo "  interview guard log: ready\n";
