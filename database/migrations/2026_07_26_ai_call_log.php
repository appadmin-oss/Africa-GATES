<?php
/**
 * gates_ai_calls — the AI audit log whose absence was the governance problem.
 *
 * There was no gates_ai_* table anywhere. Which prompt ran, which provider
 * answered, what it cost, what it decided and whether a human agreed were all
 * unknowable, on a platform whose AI touches nomination eligibility and content
 * moderation. Budgets could not be enforced because spend could not be measured,
 * and no automated decision could be reviewed, explained or appealed.
 *
 * Note what is deliberately NOT stored: the prompt itself. Only a SHA-256 of it,
 * so the log is useful for deduplication and debugging without becoming a second
 * copy of every nominator's free text and every nominee's personal details.
 *
 * Also seeds the kill switches so they are discoverable in admin Settings
 * without a code deploy: ai_enabled = 1, plus a per-capability disable pattern
 * (ai_cap_disabled_<name>) documented here for operators.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_ai_calls')) {
    DB::connection()->getPdo()->exec($sqlite
        ? "CREATE TABLE gates_ai_calls (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             capability TEXT NOT NULL,
             purpose TEXT,
             provider TEXT,
             model TEXT,
             subject_type TEXT,
             subject_id INTEGER,
             input_hash TEXT,
             output_summary TEXT,
             tokens_in INTEGER NOT NULL DEFAULT 0,
             tokens_out INTEGER NOT NULL DEFAULT 0,
             latency_ms INTEGER NOT NULL DEFAULT 0,
             outcome TEXT NOT NULL DEFAULT 'OK',
             error TEXT,
             created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
           )"
        : "CREATE TABLE gates_ai_calls (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             capability VARCHAR(60) NOT NULL,
             purpose VARCHAR(20) DEFAULT NULL,
             provider VARCHAR(20) DEFAULT NULL,
             model VARCHAR(80) DEFAULT NULL,
             subject_type VARCHAR(40) DEFAULT NULL,
             subject_id BIGINT UNSIGNED DEFAULT NULL,
             input_hash CHAR(64) DEFAULT NULL,
             output_summary VARCHAR(300) DEFAULT NULL,
             tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
             tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
             latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
             outcome VARCHAR(24) NOT NULL DEFAULT 'OK',
             error VARCHAR(300) DEFAULT NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id),
             -- The budget query is (capability, created_at) every single call.
             KEY idx_ai_cap_day (capability, created_at),
             KEY idx_ai_subject (subject_type, subject_id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($sqlite) {
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_ai_cap_day ON gates_ai_calls(capability, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ai_subject ON gates_ai_calls(subject_type, subject_id)',
        ] as $ddl) {
            try { DB::statement($ddl); } catch (\Throwable $e) {}
        }
    }
    echo "created gates_ai_calls\n";
} else {
    echo "  = gates_ai_calls already present\n";
}

// Kill switches, discoverable in Settings.
if ($schema->hasTable('gates_settings')) {
    try {
        if (!DB::table('gates_settings')->where('key_name', 'ai_enabled')->exists()) {
            DB::table('gates_settings')->insert(['key_name' => 'ai_enabled', 'value' => '1']);
            echo "  + ai_enabled = 1 seeded (set to 0 to stop every AI feature at once)\n";
        } else {
            echo "  = ai_enabled already set\n";
        }
        echo "  i per-feature switch: set ai_cap_disabled_<capability> = 1 "
            . "(dots become underscores, e.g. ai_cap_disabled_nomination_triage)\n";
    } catch (\Throwable $e) {
        echo '  ! switch seed skipped: ' . $e->getMessage() . "\n";
    }
}

echo "ai call log migration OK\n";
