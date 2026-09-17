<?php
/**
 * Two tables: the Gemini upload cache, and the analyses themselves.
 *
 * ── WHY THE UPLOAD CACHE IS KEYED ON CONTENT ─────────────────────────────────
 *
 * Gemini's Files API keeps an uploaded document for 48 hours and is free in every region
 * it serves. So the expensive part of analysing a file is not the analysis, it is sending
 * the bytes — and sending the same bytes twice is pure waste.
 *
 * `content_hash` (SHA-256 of the file) is the key rather than `evidence_id`, for two
 * reasons that both bite in practice: an evidence id would re-upload an identical document
 * for every row that references it, and it would MISS a cache hit when the same nominee
 * re-uploads the same file after an edit. Two nominees who submit the same council letter
 * share one upload.
 *
 * `expires_at` is written at upload time rather than computed on read, because the TTL is
 * Google's and a stored expiry survives us changing our own constant.
 *
 * ── AND WHY THE ANALYSIS IS ITS OWN ROW ──────────────────────────────────────
 *
 * Not a column on `gates_nominee_evidence`. Three reasons:
 *
 *   · A file can be analysed more than once — the prompt changes, the model changes — and
 *     the previous answer is what somebody compares against when they ask whether the new
 *     prompt is better. A column keeps only the last one.
 *   · The row records WHICH model and WHICH prompt version produced it. An analysis with no
 *     provenance is a claim, and this one is shown to judges.
 *   · Evidence rows are rewritten wholesale by `publishEvidence()`, which clears its own
 *     previous rows first. An analysis stored on the row would be destroyed by a re-publish
 *     that has nothing to do with it.
 *
 * `advisory` is not a column because it is not a per-row fact: {@see AiCapability} declares
 * evidence analysis advisory for every row, and {@see AiGateway} enforces it. A column
 * would imply somebody could set it to 0.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1 · the upload cache ────────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_ai_files')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_ai_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            -- SHA-256 of the file's bytes. See the note at the top of this file.
            content_hash TEXT NOT NULL,
            file_uri TEXT NOT NULL,
            mime TEXT NULL,
            bytes INTEGER NOT NULL DEFAULT 0,
            pages INTEGER NOT NULL DEFAULT 0,
            uploaded_at TEXT NULL,
            expires_at TEXT NULL
        )" : "
        CREATE TABLE gates_ai_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            content_hash CHAR(64) NOT NULL,
            file_uri VARCHAR(500) NOT NULL,
            mime VARCHAR(80) NULL,
            bytes INT UNSIGNED NOT NULL DEFAULT 0,
            pages SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_at TIMESTAMP NULL DEFAULT NULL,
            expires_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_aifile_hash (content_hash),
            KEY idx_aifile_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_ai_files created\n";

    if ($sqlite) {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_aifile_hash ON gates_ai_files(content_hash)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_aifile_expiry ON gates_ai_files(expires_at)');
    }
} else {
    echo "  = gates_ai_files already present\n";
}

// ── 2 · the analyses ────────────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_evidence_analysis')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_evidence_analysis (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evidence_id INTEGER NOT NULL,
            nominee_id INTEGER NOT NULL,
            -- The file this describes, so a re-upload of different bytes under the same
            -- evidence row does not silently inherit the old analysis.
            content_hash TEXT NULL,
            -- What the model said, as the structured shape the schema pinned.
            summary TEXT NULL,
            doc_type TEXT NULL,
            claims_json TEXT NULL,
            dates_json TEXT NULL,
            names_json TEXT NULL,
            -- 0-100. Legibility of the DOCUMENT, never a judgement of the nominee.
            legibility INTEGER NULL,
            -- Things a human should look at. Advisory, always.
            concerns_json TEXT NULL,
            -- Provenance. An analysis without it is a claim.
            model TEXT NULL,
            prompt_version TEXT NULL,
            tokens_in INTEGER NOT NULL DEFAULT 0,
            tokens_out INTEGER NOT NULL DEFAULT 0,
            pages INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'ok',
            error TEXT NULL,
            created_at TEXT NULL,
            created_by INTEGER NULL
        )" : "
        CREATE TABLE gates_evidence_analysis (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            evidence_id BIGINT UNSIGNED NOT NULL,
            nominee_id BIGINT UNSIGNED NOT NULL,
            content_hash CHAR(64) NULL,
            summary TEXT NULL,
            doc_type VARCHAR(60) NULL,
            claims_json TEXT NULL,
            dates_json TEXT NULL,
            names_json TEXT NULL,
            legibility TINYINT UNSIGNED NULL,
            concerns_json TEXT NULL,
            model VARCHAR(80) NULL,
            prompt_version VARCHAR(20) NULL,
            tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
            tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
            pages SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'ok',
            error VARCHAR(400) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED NULL,
            KEY idx_evan_evidence (evidence_id, id),
            KEY idx_evan_nominee (nominee_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_evidence_analysis created\n";

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_evan_evidence ON gates_evidence_analysis(evidence_id, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_evan_nominee ON gates_evidence_analysis(nominee_id, id)');
    }
} else {
    echo "  = gates_evidence_analysis already present\n";
}
