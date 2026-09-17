<?php
/**
 * Admin-editable system prompts, versioned.
 *
 * ── WHAT THIS IS NOT ─────────────────────────────────────────────────────────
 *
 * It is not training, and the screen that writes to this table says so in those words.
 * Fine-tuning a model needs a training run against a hosted base model, and this platform
 * calls four providers' inference endpoints from shared cPanel hosting with no GPU, no
 * pipeline and no dataset. Nothing here changes a model's weights, and a table called
 * `gates_ai_training` would have been a lie in the schema.
 *
 * What it does do is real and is most of what people actually want when they say "train
 * it": change the INSTRUCTION the model is given, per capability, without a deploy. On a
 * host with no SSH, a prompt that can only be changed by editing PHP is a prompt that never
 * gets changed — so the wording that decides how a nomination is triaged, or how a decision
 * note reads to the person receiving it, is frozen at whatever the developer first guessed.
 *
 * ── WHY EVERY SAVE IS A NEW ROW ──────────────────────────────────────────────
 *
 * Because a prompt is not a setting, it is a decision with consequences that only show up
 * later. Somebody widens the triage instruction on Tuesday, the spam scores drift on
 * Thursday, and the question is "what did it say before". An UPDATE cannot answer that.
 *
 * So: `version` increments per capability, exactly one row per capability may be active,
 * and reverting is activating an earlier row rather than retyping it. `gates_ai_calls`
 * already records which prompt version answered, so a run can be traced to its wording.
 *
 * ── AND WHY THE DEFAULT IS NOT SEEDED HERE ───────────────────────────────────
 *
 * The shipped wording lives in code, beside the parsing that depends on it. Copying it into
 * a row at migration time would fork it: the code changes in a release, the row does not,
 * and the platform quietly keeps using a prompt nobody can find in the repository. An empty
 * table means "use what the code says", which is both the safe default and the honest one.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_ai_prompts')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_ai_prompts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            -- The AiCapability name this overrides, e.g. 'nomination.triage'.
            capability TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            body TEXT NOT NULL,
            -- Why it was changed. Required by the form: a prompt edit with no note is a
            -- change nobody can explain three months later.
            note TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_ai_prompts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            capability VARCHAR(64) NOT NULL,
            version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            body MEDIUMTEXT NOT NULL,
            note VARCHAR(400) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_aiprompt_ver (capability, version),
            KEY idx_aiprompt_active (capability, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_ai_prompts created\n";

    if ($sqlite) {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_aiprompt_ver ON gates_ai_prompts(capability, version)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_aiprompt_active ON gates_ai_prompts(capability, is_active)');
    }
} else {
    echo "  = gates_ai_prompts already present\n";
}

// ── 2 · which wording answered ───────────────────────────────────────────────
//
// A version history is only half of a record. The other half is being able to take a call
// that went wrong — a triage score nobody agrees with, a decision note that read badly —
// and find out which wording produced it. Without this column, "we changed the prompt on
// the 4th" and "this call happened on the 4th" never quite meet.
//
// 0 means the shipped wording, which is also the default, so existing rows are correct
// rather than unknown.
if (DB::schema()->hasTable('gates_ai_calls')
    && !DB::schema()->hasColumn('gates_ai_calls', 'prompt_version')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_ai_calls ADD COLUMN prompt_version INTEGER NOT NULL DEFAULT 0'
        : 'ALTER TABLE gates_ai_calls ADD COLUMN prompt_version SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    echo "  + gates_ai_calls.prompt_version added\n";
} else {
    echo "  = gates_ai_calls.prompt_version already present\n";
}
