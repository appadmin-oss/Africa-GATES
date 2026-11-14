<?php
/**
 * The rest of an event's pages for its lead (consoles v7 §5 and `nav-admin.json` scope
 * `event`: Plan › Decisions, Relationships › Sponsors and Contacts, People › Seating, The
 * night › Run of show and Safety, Money › Vendors).
 *
 *   gates_event_decisions  what was decided, why, by whom and when. A decision is REVERSED by
 *                          a stamp, never edited or deleted: "who agreed to move the start
 *                          time" must still have its answer after somebody changed their mind.
 *   gates_event_show       the run of show: one row per cue, in time order, with who runs it.
 *                          On the night a cue is marked done, so the screen knows what is NOW.
 *   gates_event_sponsors   sponsors and their stage (prospect → asked → agreed → paid, or
 *                          declined), the value in kobo, what they get and who holds the
 *                          relationship. Their contact is the Contacts tab.
 *   gates_event_checks     the safety checklist: each check with an owner and when it was done.
 *   gates_event_tables     seating: a table, its seats, and who sits at it, as typed.
 *   gates_event_budget     + vendor: who is paid on a line, so Money › Vendors can group them.
 *
 * Statuses are VARCHAR, never ENUM; money is kobo in a BIGINT; indexes go through SchemaIndex;
 * one column list builds both drivers' DDL. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

// type => [sqlite, mysql]
$T = [
    'id'    => ['INTEGER PRIMARY KEY AUTOINCREMENT', 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'],
    'ref'   => ['INTEGER', 'INT UNSIGNED'],
    'int'   => ['INTEGER', 'INT'],
    'money' => ['INTEGER', 'BIGINT'],
    's16'   => ['TEXT', 'VARCHAR(16)'],
    's190'  => ['TEXT', 'VARCHAR(190)'],
    's255'  => ['TEXT', 'VARCHAR(255)'],
    'text'  => ['TEXT', 'TEXT'],
    'dt'    => ['TEXT', 'DATETIME'],
];
$make = static function (string $table, array $cols) use ($schema, $sqlite, $T): void {
    if ($schema->hasTable($table)) return;
    $parts = [];
    foreach ($cols as $c) {
        [$name, $type] = $c;
        $null = $c[2] ?? true;
        $def = $c[3] ?? null;
        $sql = $name . ' ' . $T[$type][$sqlite ? 0 : 1];
        if ($type !== 'id') {
            $sql .= $null ? ' NULL' : ' NOT NULL';
            if ($def !== null) $sql .= ' DEFAULT ' . (is_int($def) ? (string) $def : "'" . $def . "'");
        }
        $parts[] = $sql;
    }
    DB::statement('CREATE TABLE ' . $table . ' (' . implode(', ', $parts) . ')'
        . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    echo "  + {$table}\n";
};
$who = [['owner_kind', 's16'], ['owner_id', 'ref']];

$make('gates_event_decisions', [
    ['id', 'id'], ['event_id', 'ref', false], ['title', 's255', false], ['why', 'text'],
    ['decided_kind', 's16'], ['decided_id', 'ref'], ['decided_at', 'dt', false],
    ['reversed_at', 'dt'], ['reversed_by', 'ref'], ['reversed_why', 's255'],
]);
SchemaIndex::ensure('gates_event_decisions', 'idx_evdec_event', ['event_id']);

$make('gates_event_show', array_merge([
    ['id', 'id'], ['event_id', 'ref', false], ['starts_at', 'dt', false], ['minutes', 'int', false, 5],
    ['title', 's255', false], ['notes', 's255'],
], $who, [['done_at', 'dt'], ['created_at', 'dt', false]]));
SchemaIndex::ensure('gates_event_show', 'idx_evshow_event', ['event_id', 'starts_at']);

$make('gates_event_sponsors', array_merge([
    ['id', 'id'], ['event_id', 'ref', false], ['name', 's190', false], ['stage', 's16', false, 'prospect'],
    ['value_minor', 'money', false, 0], ['benefits', 's255'],
    ['contact_name', 's190'], ['contact_email', 's190'], ['contact_phone', 's190'],
], $who, [['created_at', 'dt', false], ['updated_at', 'dt']]));
SchemaIndex::ensure('gates_event_sponsors', 'idx_evsponsor_event', ['event_id', 'stage']);

$make('gates_event_checks', array_merge([
    ['id', 'id'], ['event_id', 'ref', false], ['kind', 's16', false, 'safety'], ['title', 's255', false],
], $who, [['done_at', 'dt'], ['done_by', 'ref'], ['created_at', 'dt', false]]));
SchemaIndex::ensure('gates_event_checks', 'idx_evcheck_event', ['event_id', 'kind']);

$make('gates_event_tables', [
    ['id', 'id'], ['event_id', 'ref', false], ['label', 's190', false], ['seats', 'int', false, 10],
    ['guests', 'text'], ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_event_tables', 'idx_evtable_event', ['event_id']);

if ($schema->hasTable('gates_event_budget') && !$schema->hasColumn('gates_event_budget', 'vendor')) {
    DB::statement('ALTER TABLE gates_event_budget ADD COLUMN vendor ' . ($sqlite ? 'TEXT' : 'VARCHAR(190)') . ' NULL DEFAULT NULL');
    echo "  + gates_event_budget.vendor\n";
}
