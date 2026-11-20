<?php
/**
 * The event plan: the tables the event lead console stands on (owner, 28 Oct 2026: the v7
 * consoles handoff §5; reviewed in docs/architecture/CONSOLES-V7-REVIEW.md §4, slice L1, and
 * gaps G7–G9).
 *
 *   gates_event_work     a thing that must be done before the night, with one owner, a due
 *                        date, the hours it should take and whether the night WAITS on it.
 *                        Readiness is computed from these rows (done ÷ all), never typed, so an
 *                        event with no plan shows its days to go and nothing else (G7).
 *   gates_event_risks    what could go wrong: how likely, how bad, who watches it and the plan.
 *                        The level (high, medium, low) is COMPUTED from the two answers, so two
 *                        screens cannot disagree about which risks are high.
 *   gates_event_budget   a line of spend: the estimate, what is committed and what was paid.
 *                        A lead approves a line up to their limit; above it the line becomes a
 *                        spend REQUEST (gates_requests, §2.6) and finance decides. The money
 *                        itself still moves on the existing gateway — nothing here pays anybody.
 *   gates_site_events    + series_key: the name an event shares with its other editions, so a
 *                          second edition can be compared with the first (G8).
 *                        + budget_minor: what the event may spend, so a forecast can say
 *                          "over" or "within" rather than print a bare total.
 *
 * Every status is a VARCHAR, never an ENUM (CLAUDE.md: a value outside an ENUM is a silent
 * truncation on MySQL). Money is kobo in a BIGINT. Indexes go through SchemaIndex. One column
 * list per table builds both drivers' DDL. Idempotent. NEVER exit/die here.
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
    'tiny'  => ['INTEGER', 'SMALLINT'],
    'money' => ['INTEGER', 'BIGINT'],
    'hours' => ['REAL', 'DECIMAL(6,2)'],
    's16'   => ['TEXT', 'VARCHAR(16)'],
    's64'   => ['TEXT', 'VARCHAR(64)'],
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

$make('gates_event_work', [
    ['id', 'id'],
    ['event_id', 'ref', false],
    ['title', 's255', false],
    ['detail', 'text'],
    ['owner_kind', 's16'],                    // admin | member; NULL = unassigned (goes to the leads)
    ['owner_id', 'ref'],
    ['due_at', 'dt'],
    ['est_hours', 'hours'],
    ['status', 's16', false, 'todo'],         // todo | doing | done
    ['blocks_night', 'tiny', false, 0],       // 1 = the night waits on this
    ['blocks_note', 's255'],                  // what waits on it ("the safety permit")
    ['done_at', 'dt'],
    ['created_by', 'ref'],
    ['created_at', 'dt', false],
    ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_event_work', 'idx_evwork_event', ['event_id', 'status']);
SchemaIndex::ensure('gates_event_work', 'idx_evwork_owner', ['owner_kind', 'owner_id']);

$make('gates_event_risks', [
    ['id', 'id'],
    ['event_id', 'ref', false],
    ['title', 's255', false],
    ['likelihood', 's16', false, 'possible'], // unlikely | possible | likely
    ['impact', 's16', false, 'medium'],       // low | medium | high
    ['owner_kind', 's16'],
    ['owner_id', 'ref'],
    ['plan', 's255'],                         // what we do about it
    ['status', 's16', false, 'open'],         // open | closed
    ['closed_at', 'dt'],
    ['created_by', 'ref'],
    ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_event_risks', 'idx_evrisk_event', ['event_id', 'status']);

$make('gates_event_budget', [
    ['id', 'id'],
    ['event_id', 'ref', false],
    ['title', 's255', false],
    ['estimate_minor', 'money', false, 0],
    ['committed_minor', 'money', false, 0],
    ['paid_minor', 'money', false, 0],
    ['status', 's16', false, 'proposed'],     // proposed | approved | requested | declined
    ['proposed_kind', 's16'],
    ['proposed_id', 'ref'],
    ['approved_kind', 's16'],                 // who approved: admin (a lead, under their limit) | role (finance, by request)
    ['approved_id', 'ref'],
    ['approved_at', 'dt'],
    ['request_id', 'ref'],                    // the spend request, when it was over the limit
    ['created_at', 'dt', false],
    ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_event_budget', 'idx_evbudget_event', ['event_id', 'status']);
SchemaIndex::ensure('gates_event_budget', 'idx_evbudget_request', ['request_id']);

if ($schema->hasTable('gates_site_events')) {
    foreach (['series_key' => $sqlite ? 'TEXT' : 'VARCHAR(64)', 'budget_minor' => $sqlite ? 'INTEGER' : 'BIGINT'] as $col => $type) {
        if (!$schema->hasColumn('gates_site_events', $col)) {
            DB::statement("ALTER TABLE gates_site_events ADD COLUMN {$col} {$type} NULL DEFAULT NULL");
            echo "  + gates_site_events.{$col}\n";
        }
    }
    SchemaIndex::ensure('gates_site_events', 'idx_site_events_series', ['series_key']);
}
