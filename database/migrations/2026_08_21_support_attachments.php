<?php
/**
 * Evidence on a support ticket.
 *
 * ── WHY THIS IS NOT JUST ANOTHER MEDIA TABLE ─────────────────────────────────
 *
 * Almost every attachment on a support ticket is going to be a screenshot of a
 * bank alert. That is a document containing somebody's account name, their
 * balance, a masked card number and a transaction they would not post publicly.
 * The platform already handles images for Pulse, and reusing that path wholesale
 * would have been the obvious move and the wrong one — Pulse media is PUBLISHED:
 * world-readable URL, served from a CDN bucket, run through a third-party AI
 * moderation API.
 *
 * Every one of those is wrong here:
 *
 *   • The file must not be world-readable. `storage_path` points OUTSIDE the web
 *     root, and the bytes are only ever served by a route that has checked who is
 *     asking. There is no URL that works without that check.
 *
 *   • It must not be sent to an AI moderator. Support evidence is volunteered
 *     under an expectation of confidence, and shipping a stranger's bank alert to
 *     a third-party classifier to find out whether it contains nudity is a worse
 *     privacy failure than the one it would be guarding against.
 *
 *   • It expires. Evidence is useful while a dispute is open and a liability
 *     forever after; `gates_support_attachments` records enough to prune old
 *     files without hunting the filesystem.
 *
 * ── WHAT IS RECORDED ─────────────────────────────────────────────────────────
 *
 * The DETECTED mime, not the one the browser claimed, and the original filename
 * only for display. The stored name is random: a person who uploads
 * `my-gtbank-statement-march.pdf` should not have that phrase sitting in a path.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_support_attachments')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_support_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL,
            message_id INTEGER NULL,
            uploader_type TEXT NOT NULL DEFAULT 'member',
            uploader_id INTEGER NULL,
            storage_path TEXT NOT NULL,
            original_name TEXT NULL,
            mime TEXT NOT NULL,
            bytes INTEGER NOT NULL DEFAULT 0,
            width INTEGER NULL,
            height INTEGER NULL,
            created_at TEXT NULL,
            deleted_at TEXT NULL
        )" : "
        CREATE TABLE gates_support_attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            -- Which message it came in with. NULL while the ticket is still being
            -- composed, set once the message exists.
            message_id BIGINT UNSIGNED NULL,
            uploader_type ENUM('member','staff') NOT NULL DEFAULT 'member',
            uploader_id BIGINT UNSIGNED NULL,
            -- Relative to the private attachment root, never a URL. Nothing under
            -- the web root, so there is no path that serves this without the
            -- access check in SupportAttachmentService::mayView().
            storage_path VARCHAR(255) NOT NULL,
            -- Shown to a reader; never used to build the path on disk. Somebody
            -- who uploads 'my-gtbank-statement.pdf' should not have that phrase
            -- become part of a filename anybody can see.
            original_name VARCHAR(200) NULL,
            -- Detected from the BYTES by finfo, never the browser's claim.
            mime VARCHAR(80) NOT NULL,
            bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            width INT NULL,
            height INT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            -- Soft delete, so removing evidence from a thread leaves a trace that
            -- it was removed rather than a gap nobody can account for.
            deleted_at TIMESTAMP NULL,
            KEY idx_att_ticket (ticket_id, deleted_at),
            KEY idx_att_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_support_attachments created\n";
} else {
    echo "  = gates_support_attachments already present\n";
}

if ($sqlite) {
    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_att_ticket ON gates_support_attachments(ticket_id, deleted_at)',
        'CREATE INDEX IF NOT EXISTS idx_att_message ON gates_support_attachments(message_id)',
    ] as $sql) {
        try { DB::statement($sql); } catch (\Throwable $e) { echo '  ! ' . $e->getMessage() . "\n"; }
    }
}

echo "support attachments OK\n";
