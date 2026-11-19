<?php
/**
 * THE EVENT TEAM'S LOOP (owner, 5 Nov 2026, with the "Event and Project Management: Design
 * Plan"): "Events leads cannot add reports, they cannot create tasks … the events and
 * volunteer features are too basic."
 *
 *   gates_event_work     + proof_type   what counts as evidence the work is done, chosen when
 *                                         the work is created: none | link | note | signoff.
 *                                         Done without its proof is HELD as status `proof`
 *                                         (plan AT-6), never counted.
 *                        + proof_text   the link or the note that was given.
 *                        + proof_by / proof_at  who gave it, when.
 *                        + proposed_by  a team member's suggestion (status `proposed`) waits
 *                                         for a lead: new work is a medium change (plan, tiers).
 *                        + decided_by / decided_at / decided_note  the lead's answer.
 *                        status gains `proof`, `proposed` and `declined` — VARCHAR, so no
 *                        repair is needed (an ENUM would have refused them silently).
 *
 *   gates_event_reports  a QUICK REPORT: one tap of state (on track | at risk | blocked),
 *                        a confidence (1–3) and an optional line, about one piece of work or
 *                        the event as a whole. Append-only: a report is never edited, the next
 *                        one supersedes it, so the history is the table.
 *
 * Idempotent; indexes through SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ref  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
$s16  = $sqlite ? 'TEXT' : 'VARCHAR(16)';
$s128 = $sqlite ? 'TEXT' : 'VARCHAR(128)';
$s255 = $sqlite ? 'TEXT' : 'VARCHAR(255)';
$dt   = $sqlite ? 'TEXT' : 'DATETIME';
$tiny = $sqlite ? 'INTEGER' : 'SMALLINT';

if ($schema->hasTable('gates_event_work')) {
    foreach ([
        'proof_type'   => "$s16 NOT NULL DEFAULT 'none'",
        'proof_text'   => 'TEXT NULL',
        'proof_by'     => "$ref NULL",
        'proof_at'     => "$dt NULL",
        'proposed_by'  => "$ref NULL",
        'decided_by'   => "$ref NULL",
        'decided_at'   => "$dt NULL",
        'decided_note' => "$s255 NULL",
    ] as $col => $def) {
        if (!$schema->hasColumn('gates_event_work', $col)) {
            DB::statement("ALTER TABLE gates_event_work ADD COLUMN $col $def");
            echo "  + gates_event_work.$col\n";
        }
    }
}

if (!$schema->hasTable('gates_event_reports')) {
    $id = $sqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    DB::statement("CREATE TABLE gates_event_reports ($id,
        event_id $ref NOT NULL,
        work_id $ref NULL,
        author_id $ref NOT NULL,
        author_name $s128 NULL,
        state $s16 NOT NULL,
        confidence $tiny NOT NULL DEFAULT 2,
        note TEXT NULL,
        created_at $dt NOT NULL)" . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    echo "  + gates_event_reports\n";
}
echo '  ' . SchemaIndex::ensure('gates_event_reports', 'idx_evrep_event', ['event_id', 'created_at']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_reports', 'idx_evrep_work', ['work_id', 'created_at']) . "\n";
echo "event team loop OK\n";
