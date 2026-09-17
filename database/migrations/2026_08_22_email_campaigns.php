<?php
/**
 * Campaign copy that lives in a row instead of a file.
 *
 * ── WHY THIS TABLE EXISTS ────────────────────────────────────────────────────
 *
 * The "final hours" campaign is a Twig file, sent from a token-gated `/__setup/broadcast`
 * page. The operator has no SSH. So changing one comma of copy is a full deploy cycle, and
 * the place they go to do it sits beside the database migrator — the wrong neighbourhood
 * for something a comms person uses weekly.
 *
 * ── WHY BLOCKS AND NOT HTML ──────────────────────────────────────────────────
 *
 * `blocks_json` is an ordered list of TYPED blocks, not a document. That is the whole
 * design and it is not a limitation to be lifted later.
 *
 * Everything that makes the campaign render in a real inbox — the fluid-hybrid wrapper,
 * the MSO conditionals, styled alt text, `role="presentation"` on every layout table, no
 * `data:` URIs, no CSP nonce — is invisible to whoever is typing. A rich-text editor emits
 * `<div>`s and inline styles that Outlook drops on the floor, and `EmailInboxCompatTest`
 * holds twelve properties that a WYSIWYG would break in an afternoon. So the skeleton
 * stays in the template and the editor edits FIELDS.
 *
 * ── AND WHY THERE IS A SECOND TABLE ──────────────────────────────────────────
 *
 * A campaign that went to eight hundred people has to be reconstructable. Not "roughly" —
 * the exact words, because the question that arrives later is always "what did you
 * actually send me". `gates_email_campaign_versions` keeps every saved state, so the row
 * can be edited freely and the send can still be quoted verbatim afterwards.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_email_campaigns')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_email_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            subject TEXT NOT NULL,
            preheader TEXT NULL,
            blocks_json TEXT NULL,
            -- draft → approved → sent. 'approved' is a deliberate stop: the blast radius
            -- is every nominee's inbox and there is no undo.
            status TEXT NOT NULL DEFAULT 'draft',
            approved_by INTEGER NULL,
            approved_at TEXT NULL,
            sent_at TEXT NULL,
            sent_count INTEGER NOT NULL DEFAULT 0,
            updated_by INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_email_campaigns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- The slug is the campaign key in gates_broadcast_log, whose
            -- UNIQUE(campaign, email_hash) is what makes a resumed send safe. It must
            -- therefore be stable and unique: renaming one mid-send would re-mail
            -- everybody already done.
            slug VARCHAR(64) NOT NULL,
            name VARCHAR(160) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            preheader VARCHAR(200) NULL,
            blocks_json MEDIUMTEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            approved_by BIGINT UNSIGNED NULL,
            approved_at TIMESTAMP NULL DEFAULT NULL,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_campaign_slug(slug),
            KEY idx_campaign_status(status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_email_campaigns created\n";

    if ($sqlite) {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_campaign_slug ON gates_email_campaigns(slug)');
    }
}

if (!DB::schema()->hasTable('gates_email_campaign_versions')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_email_campaign_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            preheader TEXT NULL,
            blocks_json TEXT NULL,
            saved_by INTEGER NULL,
            saved_at TEXT NULL
        )" : "
        CREATE TABLE gates_email_campaign_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            subject VARCHAR(200) NOT NULL,
            preheader VARCHAR(200) NULL,
            blocks_json MEDIUMTEXT NULL,
            saved_by BIGINT UNSIGNED NULL,
            saved_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_campaign_version(campaign_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_email_campaign_versions created\n";

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_campaign_version ON gates_email_campaign_versions(campaign_id, id)');
    }
}
