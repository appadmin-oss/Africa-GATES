<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE SEND RULES' TWO RECORDS: who a message was for, and who must not get another.
 *
 * ── THE MAIL LOG LEARNS TWO OUTCOMES, AND THE CONSTRAINT HAS TO BE REPAIRED ──
 *
 * `gates_mail_log.status` was `ENUM('sent','failed','logged_dev')` on MySQL and a CHECK
 * of the same three on SQLite. The send policy adds `refused` (never attempted: an address
 * that cannot exist, one that bounced, one that said stop) and `deferred` (held by the
 * daily cap). Written into the old column they are `Data truncated` on MySQL — the insert
 * throws, the log's own catch swallows it, and the one record of why a message did not go
 * is the one that is missing. A corrected CREATE would fix only fresh databases, so this
 * REPAIRS: MODIFY on MySQL (idempotent), a rebuild on SQLite (a CHECK cannot be altered),
 * keyed on the CHECK actually being present.
 *
 * VARCHAR rather than a wider ENUM: the vocabulary is `Mail\MailLog::*` in code.
 *
 * ── AND IT LEARNS WHO, WITHOUT LEARNING THE ADDRESS ─────────────────────────
 *
 * `to_hash` is sha256 of the normalised address — the same hash the opt-out list keys on —
 * so "how many announcements has this person had today" is one indexed count, and the log
 * still holds no address in the clear. `bulk` says whether the message was an announcement
 * (it carried an unsubscribe link) or something the person asked for.
 *
 * ── THE SUPPRESSION LIST IS NOT THE OPT-OUT LIST ─────────────────────────────
 *
 * `gates_email_optout` is a person's choice. This is the mail system's evidence: a
 * mailbox that does not exist (a permanent bounce), or a reader who pressed "report spam".
 * Kept apart because they are lifted differently — a choice by the person, evidence by
 * newer evidence — and because a provider's complaint must outlive the person later
 * re-confirming a newsletter from a different device.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$schema = DB::schema();

if ($schema->hasTable('gates_mail_log')) {
    if ($sqlite) {
        $ddl = (string) (DB::table('sqlite_master')->where('type', 'table')
            ->where('name', 'gates_mail_log')->value('sql') ?? '');
        if (stripos($ddl, 'CHECK') !== false) {
            DB::statement('CREATE TABLE gates_mail_log_rebuilt (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              to_masked TEXT NOT NULL,
              subject TEXT NOT NULL,
              category TEXT,
              status TEXT NOT NULL,
              error TEXT,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            DB::statement('INSERT INTO gates_mail_log_rebuilt (id, to_masked, subject, category, status, error, created_at)
                           SELECT id, to_masked, subject, category, status, error, created_at FROM gates_mail_log');
            DB::statement('DROP TABLE gates_mail_log');
            DB::statement('ALTER TABLE gates_mail_log_rebuilt RENAME TO gates_mail_log');
            echo "  ~ gates_mail_log rebuilt without its status CHECK\n";
        }
    } else {
        DB::statement("ALTER TABLE gates_mail_log MODIFY status VARCHAR(16) NOT NULL");
        echo "  ~ gates_mail_log.status widened to VARCHAR(16)\n";
    }

    if (!$schema->hasColumn('gates_mail_log', 'to_hash')) {
        DB::statement($sqlite ? 'ALTER TABLE gates_mail_log ADD COLUMN to_hash TEXT NULL'
                              : 'ALTER TABLE gates_mail_log ADD COLUMN to_hash CHAR(64) NULL');
        echo "  + gates_mail_log.to_hash\n";
    }
    if (!$schema->hasColumn('gates_mail_log', 'bulk')) {
        DB::statement($sqlite ? 'ALTER TABLE gates_mail_log ADD COLUMN bulk INTEGER NOT NULL DEFAULT 0'
                              : 'ALTER TABLE gates_mail_log ADD COLUMN bulk TINYINT(1) NOT NULL DEFAULT 0');
        echo "  + gates_mail_log.bulk\n";
    }
    echo '  ' . SchemaIndex::ensure('gates_mail_log', 'idx_mail_created', ['created_at']) . "\n";
    echo '  ' . SchemaIndex::ensure('gates_mail_log', 'idx_mail_status', ['status']) . "\n";
    echo '  ' . SchemaIndex::ensure('gates_mail_log', 'idx_mail_to', ['to_hash', 'created_at']) . "\n";
}

if (!$schema->hasTable('gates_mail_suppression')) {
    DB::statement($sqlite ? <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_mail_suppression (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          email_hash TEXT NOT NULL,
          email_masked TEXT NOT NULL,
          reason TEXT NOT NULL,
          source TEXT NOT NULL,
          detail TEXT NULL,
          events INTEGER NOT NULL DEFAULT 1,
          first_at TEXT NOT NULL,
          last_at TEXT NOT NULL
        )
SQL : <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_mail_suppression (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          email_hash CHAR(64) NOT NULL,
          /* Masked like the mail log: an operator can recognise a row, nobody can mail it. */
          email_masked VARCHAR(120) NOT NULL,
          /* bounce | complaint — Mail\Suppression::REASONS. VARCHAR, not ENUM. */
          reason VARCHAR(16) NOT NULL,
          /* smtp | the provider whose webhook reported it */
          source VARCHAR(40) NOT NULL,
          detail VARCHAR(300) NULL,
          events INT UNSIGNED NOT NULL DEFAULT 1,
          first_at DATETIME NOT NULL,
          last_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + gates_mail_suppression created\n";
}
echo '  ' . SchemaIndex::ensure('gates_mail_suppression', 'uq_suppression_hash', ['email_hash'], true) . "\n";
