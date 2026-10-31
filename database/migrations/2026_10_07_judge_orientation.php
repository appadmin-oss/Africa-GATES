<?php
/**
 * The cached dossier map a judge sees before reading an entry.
 *
 * ── WHY IT IS CACHED PER NOMINEE AND NOT PER JUDGE ───────────────────────────
 *
 * Ten judges on one category would otherwise make ten identical requests about the same
 * dossier. Cost is the obvious reason and the weaker one.
 *
 * The real reason is fairness: every judge on a panel sees the SAME map. If it were
 * generated per judge, the orientation would be one more thing that differed between them —
 * and a panel whose members were oriented differently is a panel whose disagreements are
 * partly an artefact of the tooling. {@see JudgeBiasService} would then be measuring us.
 *
 * ── AND WHY THE KEY IS THE DOSSIER'S CONTENT ─────────────────────────────────
 *
 * A nominee who uploads another document has a different dossier. Keyed on the nominee id
 * alone, the map would go on describing an entry that no longer exists — and describing a
 * missing document as a gap when it has since been supplied is the specific way this
 * feature could actively mislead a panel.
 *
 * Rows accumulate rather than being replaced: `status = 'failed'` rows are the record of an
 * outage, and an earlier map is what somebody compares against when a prompt changes.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_judge_orientation')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_judge_orientation (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nominee_id INTEGER NOT NULL,
            -- SHA-256 of the whole dossier, so added evidence invalidates the map.
            content_hash TEXT NULL,
            rests_on TEXT NULL,
            evidenced_json TEXT NULL,
            asserted_json TEXT NULL,
            gaps_json TEXT NULL,
            check_json TEXT NULL,
            prompt_version TEXT NULL,
            status TEXT NOT NULL DEFAULT 'ok',
            error TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_judge_orientation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nominee_id BIGINT UNSIGNED NOT NULL,
            content_hash CHAR(64) NULL,
            rests_on VARCHAR(400) NULL,
            evidenced_json TEXT NULL,
            asserted_json TEXT NULL,
            gaps_json TEXT NULL,
            check_json TEXT NULL,
            prompt_version VARCHAR(20) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'ok',
            error VARCHAR(300) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_jorient_nominee (nominee_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_judge_orientation created\n";

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_jorient_nominee ON gates_judge_orientation(nominee_id, id)');
    }
} else {
    echo "  = gates_judge_orientation already present\n";
}
