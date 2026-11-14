<?php
/**
 * An event's WRITTEN report, and the owner's sign-off on it (owner, 6 Nov 2026: "build the
 * written report with owner sign-off").
 *
 *   gates_event_writeups  one row per event. A lead writes it after the night — a summary,
 *                         what went well, what went wrong, lessons for next time — and submits
 *                         it; an owner of the event approves it or sends it back with a reason.
 *                         Sent back, it is edited and submitted again on the same row; approved,
 *                         it is final. `figures_json` freezes the computed post-event figures
 *                         (who came, what was spent, work done) AT SUBMISSION, so what the owner
 *                         signed is what the report said, not what the figures read later.
 *
 * NOT `gates_event_reports`: that name is the team's quick status reports (TeamLoop), many per
 * event. The first draft of this file used it, found the table present, skipped the create and
 * then put a UNIQUE index on event_id onto THAT table — every second quick report for an event
 * would have been refused. A guard that skips on a name it does not own is not a guard.
 *
 * Every status is VARCHAR, not ENUM (CLAUDE.md: a word added later is `Data truncated` on MySQL
 * and silently fine on SQLite). Each step is also written to the audit log through the lead
 * console's one dispatcher, so Reports › Audit lists it with who and when.
 * Idempotent + driver-aware; indexes through SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
$int  = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$v    = static fn (int $n): string => $sqlite ? 'TEXT' : "VARCHAR($n)";
$text = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
$ts   = $sqlite ? 'TEXT' : 'DATETIME';
$tail = $sqlite ? '' : ', PRIMARY KEY (id)';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_event_writeups')) {
    DB::statement("CREATE TABLE gates_event_writeups ($id,
        event_id $int NOT NULL,
        status {$v(12)} NOT NULL DEFAULT 'draft',
        summary $text NULL,
        highlights $text NULL,
        problems $text NULL,
        lessons $text NULL,
        figures_json $text NULL,
        author_id $int NULL,
        author_name {$v(190)} NULL,
        submitted_at $ts NULL,
        decided_id $int NULL,
        decided_name {$v(190)} NULL,
        decided_at $ts NULL,
        decision_note {$v(1000)} NULL,
        created_at $ts NOT NULL,
        updated_at $ts NULL$tail)$eng");
    echo "  + gates_event_writeups\n";
}
echo '  ' . SchemaIndex::ensure('gates_event_writeups', 'uq_event_writeup_event', ['event_id'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_writeups', 'idx_event_writeup_status', ['status']) . "\n";
