<?php
/**
 * The summary a nominee confirms and a panel reads.
 *
 * ── WHY IT IS STORED AND NOT GENERATED ON READ ───────────────────────────────
 *
 * Two reasons, and the second is the important one.
 *
 * Cost: a judging panel of ten opening forty entries would otherwise regenerate four
 * hundred summaries a sitting, of text that has not changed since it was sent.
 *
 * And CONSENT: the nominee is shown this summary before they press send, and pressing send
 * is them agreeing that it represents them. A summary regenerated later — from a newer
 * model, a changed prompt, a different day — is not the one they agreed to, and the panel
 * would be reading something the nominee never saw. So the text they confirmed is the text
 * that is kept.
 *
 * `content_hash` is over the answers, so a nominee who edits before sending gets a fresh
 * summary to confirm rather than the stale one.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_questionnaire_summaries')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_questionnaire_summaries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id INTEGER NOT NULL,
            nominee_id INTEGER NOT NULL,
            content_hash TEXT NULL,
            summary TEXT NULL,
            points_json TEXT NULL,
            -- Stamped when the nominee pressed send having read it. NULL means a draft
            -- summary nobody has agreed to yet, and the panel is never shown one of those.
            confirmed_at TEXT NULL,
            model TEXT NULL,
            prompt_version TEXT NULL,
            status TEXT NOT NULL DEFAULT 'ok',
            error TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_questionnaire_summaries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            submission_id BIGINT UNSIGNED NOT NULL,
            nominee_id BIGINT UNSIGNED NOT NULL,
            content_hash CHAR(64) NULL,
            summary TEXT NULL,
            points_json TEXT NULL,
            confirmed_at TIMESTAMP NULL DEFAULT NULL,
            model VARCHAR(80) NULL,
            prompt_version VARCHAR(20) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'ok',
            error VARCHAR(300) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_qsum_sub (submission_id, id),
            KEY idx_qsum_nominee (nominee_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_questionnaire_summaries created\n";

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_qsum_sub ON gates_questionnaire_summaries(submission_id, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_qsum_nominee ON gates_questionnaire_summaries(nominee_id, id)');
    }
} else {
    echo "  = gates_questionnaire_summaries already present\n";
}
