<?php
/**
 * Volunteers, scoped grants and requests (owner, 28 Oct 2026: the v7 consoles handoff,
 * docs/handoff/consoles-v7; reviewed in docs/architecture/CONSOLES-V7-REVIEW.md §4, K1–K3).
 *
 *   gates_grants            a capability at a scope, for a person, that ENDS. A volunteer's door
 *                           access at an event, a lead's grant on their event, a moderator on one
 *                           programme. `Services\Grants` is the one reader; expiry is checked on
 *                           every read, so an expired grant removes the scope on the next request
 *                           with nothing scheduled.
 *   gates_requests          somebody who cannot do a thing themselves ASKS: a swap, time off, an
 *   gates_request_events    hours correction, a role, spend over a limit. One decider; the events
 *                           are append-only, so the history is the table.
 *   gates_volunteers        one row per member who applied, keyed to gates_users. Their level is
 *                           COMPUTED from evidence (Services\Volunteer\Level), never stored.
 *   gates_vol_references    the people an applicant named.
 *   gates_vol_shifts        a block of time at an event or for an award, with slots.
 *   gates_vol_signups       who took which shift, and what happened (kept, missed, dropped).
 *   gates_vol_tasks         the task library: work from anywhere, each saying how it is checked.
 *   gates_vol_task_takes    who took which task, and how far they are.
 *   gates_vol_hours         every hour, with its SOURCE (scan, task, signup, manual) and status.
 *                           Only a manual entry needs a person to confirm it; none is edited once
 *                           confirmed (a correction is a request).
 *   gates_vol_modules       training: short pages with a check, each unlocking capabilities.
 *   gates_vol_passes        who passed which module, and when it lapses.
 *
 * Every status is a VARCHAR, never an ENUM (CLAUDE.md: a value outside an ENUM is a silent
 * truncation on MySQL). Datetimes are DATETIME, written 'Y-m-d H:i:s'. Indexes go through
 * SchemaIndex. One column list per table builds both drivers' DDL, so the two cannot drift.
 * Idempotent. NEVER exit/die here.
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
    'tiny'  => ['INTEGER', 'SMALLINT'],
    'money' => ['INTEGER', 'BIGINT'],
    'hours' => ['REAL', 'DECIMAL(6,2)'],
    's16'   => ['TEXT', 'VARCHAR(16)'],
    's32'   => ['TEXT', 'VARCHAR(32)'],
    's64'   => ['TEXT', 'VARCHAR(64)'],
    's190'  => ['TEXT', 'VARCHAR(190)'],
    's255'  => ['TEXT', 'VARCHAR(255)'],
    'text'  => ['TEXT', 'TEXT'],
    'date'  => ['TEXT', 'DATE'],
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

$make('gates_grants', [
    ['id', 'id'],
    ['actor_kind', 's16', false, 'member'],   // member | admin
    ['actor_id', 'ref', false],
    ['capability', 's64', false],             // event.lead, vol.door, vol.nomination_check …
    ['scope_kind', 's16', false],             // platform | programme | cycle | event
    ['scope_id', 'ref'],
    ['granted_by', 'ref'],
    ['note', 's255'],
    ['starts_at', 'dt'],
    ['expires_at', 'dt'],
    ['revoked_at', 'dt'],
    ['revoked_by', 'ref'],
    ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_grants', 'idx_grant_actor', ['actor_kind', 'actor_id']);
SchemaIndex::ensure('gates_grants', 'idx_grant_scope', ['scope_kind', 'scope_id', 'capability']);

$make('gates_requests', [
    ['id', 'id'],
    ['type', 's32', false],
    ['title', 's255', false],
    ['from_kind', 's16', false, 'member'],
    ['from_id', 'ref', false],
    ['scope_kind', 's16'],
    ['scope_id', 'ref'],
    ['amount_minor', 'money'],                // kobo; NULL when the type has no amount
    ['amount_text', 's64'],                   // hours or another unit, as typed
    ['needed_by', 's64'],
    ['why', 'text', false],
    ['decider_kind', 's16'],                  // member | admin | role
    ['decider_id', 'ref'],
    ['decider_role', 's32'],
    ['status', 's16', false, 'waiting'],      // waiting | needs_info | approved | declined | withdrawn
    ['created_at', 'dt', false],
    ['updated_at', 'dt'],
    ['decided_at', 'dt'],
]);
SchemaIndex::ensure('gates_requests', 'idx_req_from', ['from_kind', 'from_id', 'status']);
SchemaIndex::ensure('gates_requests', 'idx_req_decider', ['decider_kind', 'decider_id', 'status']);

$make('gates_request_events', [
    ['id', 'id'],
    ['request_id', 'ref', false],
    ['actor_kind', 's16', false],
    ['actor_id', 'ref'],
    ['actor_name', 's190'],
    ['action', 's32', false],                 // asked | approve | ask_info | decline | add_info | withdraw | undo
    ['note', 'text'],
    ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_request_events', 'idx_reqev_req', ['request_id', 'id']);

$make('gates_volunteers', [
    ['id', 'id'],
    ['user_id', 'ref', false],
    ['status', 's16', false, 'applicant'],    // applicant | active | paused | left | declined
    ['display_name', 's190'],
    ['languages', 's255'],
    ['area', 's190'],
    ['state', 's64'],
    ['weekdays', 's16', false, 'evenings'],   // none | evenings | any
    ['weekends', 's16', false, 'sat'],        // none | sat | sun | both
    ['max_hours_week', 'tiny', false, 12],
    ['travel_km', 'tiny', false, 10],
    ['from_home', 'tiny', false, 1],
    ['emergency_contact', 's255'],
    ['dob', 'date'],
    ['guardian_consent_at', 'dt'],
    ['interests', 's255'],
    ['motivation', 'text'],
    ['conduct_version', 's16'],
    ['conduct_accepted_at', 'dt'],
    ['notify_fit', 's16', false, 'weekly'],   // never | weekly | posted
    ['notify_news', 'tiny', false, 0],
    ['channel', 's32', false, 'email'],
    ['tip_dismissed_at', 'dt'],
    ['applied_at', 'dt'],
    ['approved_at', 'dt'],
    ['approved_by', 'ref'],
    ['decline_reason', 's255'],
    ['paused_until', 'dt'],
    ['left_at', 'dt'],
    ['created_at', 'dt', false],
    ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_volunteers', 'uq_vol_user', ['user_id'], true);
SchemaIndex::ensure('gates_volunteers', 'idx_vol_status', ['status']);

$make('gates_vol_references', [
    ['id', 'id'],
    ['volunteer_id', 'ref', false],
    ['name', 's190', false],
    ['contact', 's190', false],
    ['relation', 's64'],
    ['status', 's16', false, 'asked'],        // asked | replied | replaced
    ['note', 'text'],
    ['asked_at', 'dt', false],
    ['replied_at', 'dt'],
]);
SchemaIndex::ensure('gates_vol_references', 'idx_volref_vol', ['volunteer_id']);

$make('gates_vol_shifts', [
    ['id', 'id'],
    ['title', 's190', false],
    ['scope_kind', 's16', false, 'event'],    // event | cycle
    ['scope_id', 'ref', false],
    ['capability', 's64'],                    // the grant it confers on the people on it
    ['location', 's190'],
    ['from_home', 'tiny', false, 0],
    ['starts_at', 'dt', false],
    ['ends_at', 'dt', false],
    ['slots', 'tiny', false, 1],
    ['module_key', 's64'],                    // training needed, if any
    ['bring', 's255'],
    ['brief', 'text'],
    ['lead_name', 's190'],
    ['status', 's16', false, 'open'],         // open | cancelled
    ['time_changed_at', 'dt'],
    ['created_by', 'ref'],
    ['created_at', 'dt', false],
    ['updated_at', 'dt'],
]);
SchemaIndex::ensure('gates_vol_shifts', 'idx_volshift_when', ['status', 'starts_at']);
SchemaIndex::ensure('gates_vol_shifts', 'idx_volshift_scope', ['scope_kind', 'scope_id']);

$make('gates_vol_signups', [
    ['id', 'id'],
    ['shift_id', 'ref', false],
    ['volunteer_id', 'ref', false],
    ['status', 's16', false, 'on'],           // on | dropped | kept | missed
    ['joined_at', 'dt', false],
    ['dropped_at', 'dt'],
    ['change_seen_at', 'dt'],
    ['check_in_at', 'dt'],
    ['check_out_at', 'dt'],
]);
SchemaIndex::ensure('gates_vol_signups', 'uq_volsign', ['shift_id', 'volunteer_id'], true);
SchemaIndex::ensure('gates_vol_signups', 'idx_volsign_vol', ['volunteer_id', 'status']);

$make('gates_vol_tasks', [
    ['id', 'id'],
    ['title', 's190', false],
    ['body', 'text'],
    ['scope_kind', 's16'],
    ['scope_id', 'ref'],
    ['checked_by', 's32', false, 'lead'],     // lead | sample | phone | scan
    ['checked_note', 's190'],
    ['module_key', 's64'],
    ['est_minutes', 'int'],
    ['points', 'int', false, 0],
    ['target', 'int'],
    ['from_home', 'tiny', false, 1],
    ['due_at', 'dt'],
    ['status', 's16', false, 'open'],         // open | closed
    ['created_by', 'ref'],
    ['created_at', 'dt', false],
]);

$make('gates_vol_task_takes', [
    ['id', 'id'],
    ['task_id', 'ref', false],
    ['volunteer_id', 'ref', false],
    ['status', 's16', false, 'taken'],        // taken | done | dropped
    ['progress', 'int', false, 0],
    ['accepted', 'int', false, 0],
    ['taken_at', 'dt', false],
    ['done_at', 'dt'],
]);
SchemaIndex::ensure('gates_vol_task_takes', 'uq_voltake', ['task_id', 'volunteer_id'], true);

$make('gates_vol_hours', [
    ['id', 'id'],
    ['volunteer_id', 'ref', false],
    ['source', 's16', false],                 // scan | task | signup | manual
    ['ref_kind', 's16'],
    ['ref_id', 'ref'],
    ['worked_on', 'date', false],
    ['what', 's255', false],
    ['hours', 'hours', false, 0],
    ['status', 's16', false, 'verified'],     // verified | review | declined | missed
    ['proof', 's255'],
    ['witness', 's190'],
    ['confirmed_kind', 's16'],
    ['confirmed_id', 'ref'],
    ['confirmed_name', 's190'],
    ['confirmed_at', 'dt'],
    ['reason', 's255'],
    ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_vol_hours', 'idx_volhours_vol', ['volunteer_id', 'status', 'worked_on']);
// One automatic row per piece of evidence: a scan pair or an accepted task is never counted twice.
SchemaIndex::ensure('gates_vol_hours', 'idx_volhours_ref', ['ref_kind', 'ref_id']);

$make('gates_vol_modules', [
    ['id', 'id'],
    ['slug', 's64', false],                   // never `key`: a reserved word in MySQL 8
    ['title', 's190', false],
    ['minutes', 'tiny', false, 10],
    ['body', 'text'],
    ['questions', 'text'],                    // JSON: [{q, options[], answer}]
    ['pass_mark', 'tiny', false, 2],
    ['valid_days', 'int'],
    ['unlocks', 's255'],                      // capabilities, comma separated
    ['status', 's16', false, 'live'],         // live | retired
    ['created_at', 'dt', false],
]);
SchemaIndex::ensure('gates_vol_modules', 'uq_volmod_slug', ['slug'], true);

$make('gates_vol_passes', [
    ['id', 'id'],
    ['module_id', 'ref', false],
    ['volunteer_id', 'ref', false],
    ['score', 'tiny', false, 0],
    ['passed_at', 'dt', false],
    ['expires_at', 'dt'],
]);
SchemaIndex::ensure('gates_vol_passes', 'idx_volpass_vol', ['volunteer_id', 'module_id']);

echo "volunteers, grants and requests OK\n";
