<?php
/**
 * Bulk email to nominees: the opt-out that has to exist first, and the log that
 * stops a re-run mailing anybody twice.
 *
 * ── WHY THIS COMES BEFORE THE SEND, NOT AFTER IT ─────────────────────────────
 *
 * The "final hours" design carries an Unsubscribe and an Email-preferences link in its
 * footer, and until now this platform had nothing either could point at. The only opt-out
 * machinery here was `gates_newsletter.unsubscribed_at` (newsletter signups only) and the
 * per-request token on `gates_stock_alerts`. A broadcast to nominees is neither: a nominee
 * never signed up for anything — somebody else put their name forward — so of every
 * audience this platform mails, they are the one with the strongest claim to a working
 * "stop". Shipping the send first and the opt-out later would mean the first campaign is
 * the one with a dead link in it.
 *
 * ── SUPPRESSION IS GLOBAL AND KEYED BY HASH ──────────────────────────────────
 *
 * One opt-out, not one per campaign: somebody who says stop has said it about this kind of
 * mail, and asking them again next cycle is how a platform earns a spam complaint.
 * `email_hash` is the unique key so the list can be checked without reading addresses, and
 * the plain address is kept alongside only because an operator answering "why did X get
 * this" needs to be able to look. Same shape as gates_newsletter.
 *
 * ── AND THE LOG IS WHAT MAKES A RE-RUN SAFE ──────────────────────────────────
 *
 * A broadcast is a loop over a few thousand SMTP calls; it will be interrupted. Without a
 * per-recipient record, the safe response to an interrupted run is "do nothing", because
 * the alternative is mailing the first half twice. One row per (campaign, address) with a
 * unique key means a re-run resumes instead of repeating.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_email_optout')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_email_optout (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_hash TEXT NOT NULL,
            email TEXT NOT NULL,
            token TEXT NOT NULL,
            scope TEXT NOT NULL DEFAULT 'all',
            source TEXT NULL,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_email_optout (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            -- sha256 of the lowercased address. The column the send checks against.
            email_hash CHAR(64) NOT NULL,
            email VARCHAR(190) NOT NULL,
            -- The whole credential for the one-click link. Unique so a link identifies one
            -- address and cannot be walked to somebody else's.
            token CHAR(32) NOT NULL,
            -- Room to grow into per-category preferences later without a second table.
            -- 'all' is the only value the unsubscribe link writes today.
            scope VARCHAR(40) NOT NULL DEFAULT 'all',
            source VARCHAR(60) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_optout_hash (email_hash, scope),
            UNIQUE KEY uq_optout_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_email_optout created\n";
} else {
    echo "  = gates_email_optout already present\n";
}

if (!DB::schema()->hasTable('gates_broadcast_log')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_broadcast_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign TEXT NOT NULL,
            email_hash TEXT NOT NULL,
            email TEXT NOT NULL,
            nominee_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'sent',
            error TEXT NULL,
            sent_at TEXT NULL
        )" : "
        CREATE TABLE gates_broadcast_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            campaign VARCHAR(60) NOT NULL,
            email_hash CHAR(64) NOT NULL,
            email VARCHAR(190) NOT NULL,
            nominee_id BIGINT UNSIGNED NULL,
            -- 'sent' | 'failed'. A failure keeps its row so a re-run can retry only the
            -- failures, and so 'it never arrived' has an answer.
            status VARCHAR(12) NOT NULL DEFAULT 'sent',
            error VARCHAR(300) NULL,
            sent_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            -- The key that makes a re-run resume rather than repeat.
            UNIQUE KEY uq_bcast_once (campaign, email_hash),
            KEY idx_bcast_campaign (campaign, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_broadcast_log created\n";
} else {
    echo "  = gates_broadcast_log already present\n";
}

// SQLite gets its unique indexes separately — inline UNIQUE KEY is MySQL syntax.
if ($sqlite) {
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_optout_hash  ON gates_email_optout (email_hash, scope)');
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_optout_token ON gates_email_optout (token)');
    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_bcast_once   ON gates_broadcast_log (campaign, email_hash)');
    echo "  + sqlite indexes ensured\n";
}
