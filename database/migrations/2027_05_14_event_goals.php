<?php
/**
 * GOALS THAT COUNT THEMSELVES, AND WHAT KEEPS THE PLAN HONEST (owner, 5 Nov 2026: "Build this
 * out" — the design plan's goals and gates, assumption register, contact pipeline, rules with
 * shadow mode, the digest, the setup conversation, and approval tiers with small changes that
 * approve themselves).
 *
 *   gates_event_goals        a measurable outcome: a METRIC this platform can count itself
 *                            (EventPlan\Goals::METRICS), a target, a deadline and an owner,
 *                            scoped to one award edition, one category or the whole event.
 *                            A goal without all three is refused, never half-saved (plan AT-1).
 *   gates_event_gates        a dated checkpoint on a goal: the value it must reach and who
 *                            signs it off. Signed off only when the count shows it reached;
 *                            a mismatch is shown, never corrected silently.
 *   gates_event_assumptions  every figure the plan rests on that nobody measured (price per
 *                            vote, conversion, nominees per award, the weekly curve): its value,
 *                            where it came from, and what would reopen it. Editing one re-solves
 *                            every target below it; changing one a target rests on is LARGE.
 *   gates_event_contacts     the pipeline: a prospect, their stage, the team member working
 *                            them and a reach rating only that member and the leads can see.
 *                            A submitted nomination matching a contact moves it to nominated.
 *   gates_event_rules        the rule catalogue switched on per event (EventPlan\Rules): every
 *                            rule starts in SHADOW mode and only records what it would have done.
 *   gates_event_rule_log     what each rule did, or would have done, with its evidence.
 *   gates_event_changes      a change waiting for its tier's approver (small, medium, large):
 *                            what, why, the evidence, and — for a small one — when it applies
 *                            itself if nobody objects.
 *
 * Every status is VARCHAR (CLAUDE.md: an ENUM truncates a word added later). Money is kobo in
 * BIGINT. Indexes through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$T = [
    'id'   => [$sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'],
    'ref'  => [$sqlite ? 'INTEGER' : 'INT UNSIGNED'],
    'big'  => [$sqlite ? 'INTEGER' : 'BIGINT'],
    'tiny' => [$sqlite ? 'INTEGER' : 'SMALLINT'],
    's16'  => [$sqlite ? 'TEXT' : 'VARCHAR(16)'],
    's32'  => [$sqlite ? 'TEXT' : 'VARCHAR(32)'],
    's64'  => [$sqlite ? 'TEXT' : 'VARCHAR(64)'],
    's190' => [$sqlite ? 'TEXT' : 'VARCHAR(190)'],
    's255' => [$sqlite ? 'TEXT' : 'VARCHAR(255)'],
    'text' => ['TEXT'],
    'dt'   => [$sqlite ? 'TEXT' : 'DATETIME'],
];
$make = static function (string $table, array $cols) use ($schema, $sqlite, $T): void {
    if ($schema->hasTable($table)) return;
    $parts = [];
    foreach ($cols as $c) {
        [$name, $type] = $c;
        $sql = $name . ' ' . $T[$type][0];
        if ($type !== 'id') {
            $sql .= ($c[2] ?? true) ? ' NULL' : ' NOT NULL';
            if (isset($c[3])) $sql .= ' DEFAULT ' . (is_int($c[3]) ? (string) $c[3] : "'" . $c[3] . "'");
        }
        $parts[] = $sql;
    }
    DB::statement('CREATE TABLE ' . $table . ' (' . implode(', ', $parts) . ')' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    echo "  + {$table}\n";
};

$make('gates_event_goals', [
    ['id', 'id'], ['event_id', 'ref', false], ['title', 's190', false], ['metric', 's32', false],
    ['scope_kind', 's16', false, 'event'], ['scope_id', 'ref'], ['target', 'big', false], ['deadline', 'dt', false],
    ['owner_id', 'ref'], ['status', 's16', false, 'active'], ['created_by', 'ref'], ['created_at', 'dt', false], ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_event_goals', 'idx_evgoal_event', ['event_id', 'status']);

$make('gates_event_gates', [
    ['id', 'id'], ['event_id', 'ref', false], ['goal_id', 'ref', false], ['title', 's190', false], ['due_at', 'dt', false],
    ['target', 'big', false], ['approver', 's16', false, 'lead'], ['status', 's16', false, 'open'],
    ['signed_by', 'ref'], ['signed_at', 'dt'], ['signed_value', 'big'], ['signed_note', 's255'], ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_event_gates', 'idx_evgate_goal', ['goal_id', 'due_at']);
SchemaIndex::ensure('gates_event_gates', 'idx_evgate_event', ['event_id', 'status']);

$make('gates_event_assumptions', [
    ['id', 'id'], ['event_id', 'ref', false], ['goal_id', 'ref'], ['akey', 's32', false], ['label', 's190', false],
    ['value', 's64', false], ['unit', 's16'], ['source', 's64'], ['reopen_when', 's255'], ['status', 's16', false, 'open'],
    ['updated_by', 'ref'], ['created_at', 'dt', false], ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_event_assumptions', 'idx_evassume_goal', ['goal_id', 'akey']);

$make('gates_event_contacts', [
    ['id', 'id'], ['event_id', 'ref', false], ['goal_id', 'ref'], ['category_id', 'ref'], ['name', 's190', false],
    ['email', 's190'], ['phone', 's32'], ['stage', 's16', false, 'prospect'], ['owner_id', 'ref'], ['reach', 'tiny'],
    ['source', 's32'], ['nomination_id', 'ref'], ['note', 's255'], ['created_at', 'dt', false], ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_event_contacts', 'idx_evcontact_event', ['event_id', 'stage']);
SchemaIndex::ensure('gates_event_contacts', 'idx_evcontact_email', ['email']);

$make('gates_event_rules', [
    ['id', 'id'], ['event_id', 'ref', false], ['rkey', 's32', false], ['mode', 's16', false, 'shadow'],
    ['params', 'text'], ['owner_id', 'ref'], ['review_at', 'dt'], ['created_at', 'dt', false], ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_event_rules', 'idx_evrule_event', ['event_id', 'rkey']);

$make('gates_event_rule_log', [
    ['id', 'id'], ['rule_id', 'ref', false], ['event_id', 'ref', false], ['mode', 's16', false], ['subject', 's190', false],
    ['did', 's255', false], ['evidence', 's255'], ['undone_at', 'dt'], ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_event_rule_log', 'idx_evrulelog_rule', ['rule_id', 'created_at']);
SchemaIndex::ensure('gates_event_rule_log', 'idx_evrulelog_subject', ['event_id', 'subject']);

$make('gates_event_changes', [
    ['id', 'id'], ['event_id', 'ref', false], ['kind', 's32', false], ['tier', 's16', false], ['subject_kind', 's16', false],
    ['subject_id', 'ref', false], ['payload', 'text'], ['summary', 's255', false], ['reason', 's255'], ['evidence', 's255'],
    ['status', 's16', false, 'pending'], ['proposed_by', 'ref'], ['decided_by', 'ref'], ['decided_at', 'dt'], ['decided_note', 's255'],
    ['lapse_at', 'dt'], ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_event_changes', 'idx_evchange_event', ['event_id', 'status']);
echo "event goals OK\n";
