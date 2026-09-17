<?php
/**
 * Support tickets — what the assistant hands to a human.
 *
 * ── WHY A TABLE AND NOT JUST AN EMAIL ────────────────────────────────────────
 *
 * An escalation that is only an email is an escalation nobody can count. There
 * is no way to answer "how many people asked for help this week", "which of them
 * never got a reply", or "did the webhook actually fire" — and on a shared host
 * where SMTP fails quietly, "we emailed the team" is a claim with no evidence
 * behind it. The row is the evidence. The email and the webhook are deliveries
 * OF the row, and each records whether it worked.
 *
 * The transcript is stored because a support ticket without the conversation is
 * a complaint with no context, and the person has already explained once.
 *
 * ── WHAT IS DELIBERATELY NOT STORED ──────────────────────────────────────────
 *
 * No payment details beyond a reference the user typed themselves, and no tool
 * OUTPUT — only the names of the tools that ran. A ticket is read by staff and
 * forwarded by email; copying someone's transaction history into it would spread
 * their financial record into an inbox for no operational gain, when whoever
 * picks the ticket up can look it up properly.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_support_tickets')) {
    if ($sqlite) {
        DB::statement(<<<'SQL'
        CREATE TABLE gates_support_tickets (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          reference TEXT NOT NULL,
          user_id INTEGER,
          email TEXT,
          name TEXT,
          subject TEXT NOT NULL,
          transcript TEXT,
          tools_used TEXT,
          severity TEXT NOT NULL DEFAULT 'normal',
          status TEXT NOT NULL DEFAULT 'open',
          emailed INTEGER NOT NULL DEFAULT 0,
          webhooked INTEGER NOT NULL DEFAULT 0,
          page_url TEXT,
          user_agent TEXT,
          ip_hash TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_activity TEXT,
          resolved_at TEXT
        )
        SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_ticket_ref ON gates_support_tickets(reference)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ticket_status ON gates_support_tickets(status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_ticket_created ON gates_support_tickets(created_at DESC)');
    } else {
        DB::statement(<<<'SQL'
        CREATE TABLE gates_support_tickets (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          reference VARCHAR(24) NOT NULL,
          user_id BIGINT UNSIGNED NULL,
          email VARCHAR(255) NULL,
          name VARCHAR(160) NULL,
          subject VARCHAR(255) NOT NULL,
          transcript MEDIUMTEXT NULL,
          tools_used VARCHAR(255) NULL,
          severity VARCHAR(16) NOT NULL DEFAULT 'normal',
          status VARCHAR(16) NOT NULL DEFAULT 'open',
          emailed TINYINT(1) NOT NULL DEFAULT 0,
          webhooked TINYINT(1) NOT NULL DEFAULT 0,
          page_url VARCHAR(500) NULL,
          user_agent VARCHAR(255) NULL,
          ip_hash CHAR(64) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_activity TIMESTAMP NULL,
          resolved_at TIMESTAMP NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_ticket_ref (reference),
          KEY idx_ticket_status (status),
          KEY idx_ticket_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }
    echo "  + gates_support_tickets created\n";
} else {
    echo "  = gates_support_tickets already present\n";
}

echo "support tickets OK\n";
