<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE NEWSLETTER, AS ISSUES — and a list that only holds people who said yes.
 *
 * ── CONFIRMATION, BECAUSE THE FORM ACCEPTS ANYBODY'S ADDRESS ─────────────────
 *
 * `/api/v1/newsletter/subscribe` takes any address typed into it, five an hour per
 * connection. Until now the list was mailed by one thing — the voting reminder — and the
 * welcome it sent said "Didn't subscribe? You can ignore this email and you won't hear from
 * us again", which was not true: ignoring it changed nothing. An automated newsletter
 * mailed to that list would make the promise false on a schedule, to people somebody else
 * typed in, against the sending reputation every sign-in code travels on.
 *
 * So `confirmed_at` is the gate. A row without it gets the confirmation request and
 * nothing else. `confirm_sent_at` makes that request a once-only event for the rows that
 * were here before confirmation existed: each of them is asked once, and somebody who
 * ignores the question is never asked again — which is what the old welcome promised.
 *
 * ── ONE ROW PER ISSUE, AND THE CONTENT IS FROZEN ON IT ───────────────────────
 *
 * `content_json` is what the composer saw when the issue was made. The sender renders from
 * it, never from a fresh composition, so an issue approved at 09:00 is the issue that goes
 * out at 09:40 — not whatever the award pages happen to say once the send reaches its last
 * batch. `period_key` is UNIQUE so two overlapping cron ticks cannot both compose this
 * week's issue; the second insert fails and that is the claim.
 *
 * `status` is VARCHAR, not ENUM: a value outside an ENUM is `Data truncated` on MySQL and
 * the vocabulary lives in code (`Newsletter::ST_*`), where it can grow.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$schema = DB::schema();

if ($schema->hasTable('gates_newsletter')) {
    foreach (['confirmed_at', 'confirm_sent_at'] as $col) {
        if (!$schema->hasColumn('gates_newsletter', $col)) {
            DB::statement($sqlite
                ? "ALTER TABLE gates_newsletter ADD COLUMN {$col} TEXT NULL"
                : "ALTER TABLE gates_newsletter ADD COLUMN {$col} TIMESTAMP NULL DEFAULT NULL");
            echo "  + gates_newsletter.{$col}\n";
        }
    }
    echo '  ' . SchemaIndex::ensure('gates_newsletter', 'idx_newsletter_confirmed', ['confirmed_at']) . "\n";
}

if (!$schema->hasTable('gates_newsletter_issues')) {
    DB::statement($sqlite ? <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_newsletter_issues (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          period_key TEXT NOT NULL,
          subject TEXT NOT NULL,
          preheader TEXT NULL,
          content_json TEXT NOT NULL,
          fingerprint TEXT NOT NULL,
          status TEXT NOT NULL DEFAULT 'draft',
          note TEXT NULL,
          recipients INTEGER NOT NULL DEFAULT 0,
          sent INTEGER NOT NULL DEFAULT 0,
          failed INTEGER NOT NULL DEFAULT 0,
          composed_at TEXT NOT NULL,
          approved_by INTEGER NULL,
          approved_at TEXT NULL,
          started_at TEXT NULL,
          finished_at TEXT NULL
        )
SQL : <<<'SQL'
        CREATE TABLE IF NOT EXISTS gates_newsletter_issues (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          /* w2026-40, f2026-20, m2026-10 — one issue per period, see Newsletter::periodKey(). */
          period_key VARCHAR(20) NOT NULL,
          subject VARCHAR(200) NOT NULL,
          preheader VARCHAR(250) NULL,
          /* MEDIUMTEXT: an issue is a few KB of facts, and TEXT's 65,535 bytes is the
             ceiling that silently truncates a JSON document into one that does not parse. */
          content_json MEDIUMTEXT NOT NULL,
          fingerprint CHAR(64) NOT NULL,
          status VARCHAR(12) NOT NULL DEFAULT 'draft',
          note VARCHAR(300) NULL,
          recipients INT UNSIGNED NOT NULL DEFAULT 0,
          sent INT UNSIGNED NOT NULL DEFAULT 0,
          failed INT UNSIGNED NOT NULL DEFAULT 0,
          composed_at DATETIME NOT NULL,
          approved_by BIGINT UNSIGNED NULL,
          approved_at DATETIME NULL,
          started_at DATETIME NULL,
          finished_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + gates_newsletter_issues created\n";
} else {
    echo "  = gates_newsletter_issues already present\n";
}

// SchemaIndex, never a raw `CREATE INDEX IF NOT EXISTS` — MySQL answers that with a 1064,
// the run aborts unrecorded, and the guard above is true on the next deploy.
echo '  ' . SchemaIndex::ensure('gates_newsletter_issues', 'uq_newsletter_period', ['period_key'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_newsletter_issues', 'idx_newsletter_status', ['status']) . "\n";
