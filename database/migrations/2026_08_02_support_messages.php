<?php
/**
 * Replies on a support ticket.
 *
 * ── DATED 08-02 ON PURPOSE ───────────────────────────────────────────────────
 *
 * Migrations run in glob() order, which is alphabetical, and
 * "support_messages" sorts before "support_tickets". As 08-01 this file ran
 * FIRST, so its ALTER on gates_support_tickets hit a table that did not exist
 * yet, was skipped by the hasTable() guard, and never ran again — migrations
 * being once-only. The column was permanently missing and the ticket list
 * silently returned nothing, because the query that needed it was inside a
 * try/catch. The date is the ordering, so the date is what had to change.
 *
 * ── WHY A SECOND TABLE AND NOT A LONGER TRANSCRIPT COLUMN ────────────────────
 *
 * `gates_support_tickets.transcript` is a SNAPSHOT: what was said before the
 * ticket was opened, frozen so a human picking it up has the context. It is not a
 * conversation, and growing it by appending would conflate two different things —
 * you could no longer tell what the assistant saw at escalation time from what
 * was said afterwards, which is exactly what you need when working out whether
 * the escalation was right.
 *
 * Replies are rows. That gives each one an author, a time, a delivery flag and an
 * internal/visible distinction — none of which survive being concatenated into a
 * blob.
 *
 * ── `is_internal` ────────────────────────────────────────────────────────────
 *
 * Staff need somewhere to write "refunded manually, chased Paystack" that the
 * member never sees. Without it, that note either goes somewhere else entirely —
 * and is lost to whoever picks the ticket up next — or gets emailed to the
 * customer by accident. The column exists so the safe thing is also the easy one,
 * and the default is 0 so a plain reply is a reply TO the member.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_support_messages')) {
    if ($sqlite) {
        DB::statement(<<<'SQL'
        CREATE TABLE gates_support_messages (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          ticket_id INTEGER NOT NULL,
          author_type TEXT NOT NULL DEFAULT 'member',
          author_id INTEGER,
          author_name TEXT,
          body TEXT NOT NULL,
          is_internal INTEGER NOT NULL DEFAULT 0,
          emailed INTEGER NOT NULL DEFAULT 0,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS idx_smsg_ticket ON gates_support_messages(ticket_id, id)');
    } else {
        DB::statement(<<<'SQL'
        CREATE TABLE gates_support_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          ticket_id BIGINT UNSIGNED NOT NULL,
          author_type VARCHAR(12) NOT NULL DEFAULT 'member',
          author_id BIGINT UNSIGNED NULL,
          author_name VARCHAR(160) NULL,
          body MEDIUMTEXT NOT NULL,
          is_internal TINYINT(1) NOT NULL DEFAULT 0,
          emailed TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_smsg_ticket (ticket_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }
    echo "  + gates_support_messages created\n";
} else {
    echo "  = gates_support_messages already present\n";
}

// The member-facing ticket list needs to sort by "when did anything last happen",
// which is not the same as when the ticket was opened.
if (DB::schema()->hasTable('gates_support_tickets')
    && !DB::schema()->hasColumn('gates_support_tickets', 'last_activity')) {
    DB::statement('ALTER TABLE gates_support_tickets ADD COLUMN last_activity '
        . ($sqlite ? 'TEXT' : 'TIMESTAMP') . ' NULL DEFAULT NULL');
    // Backfill so existing tickets sort sensibly rather than sinking to the bottom.
    DB::statement('UPDATE gates_support_tickets SET last_activity = created_at WHERE last_activity IS NULL');
    echo "  + gates_support_tickets.last_activity added and backfilled\n";
}

echo "support messages OK\n";
