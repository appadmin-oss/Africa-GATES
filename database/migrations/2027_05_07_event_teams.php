<?php
/**
 * An event's own TEAMS, ROLES and the people in them (owner, 3 Nov 2026: "the event lead
 * manages this … can set up committees or teams, assigns lead … event owner approves, event
 * owner can set permission as they like").
 *
 *   gates_event_teams         a team or committee at one event, optionally inside another
 *                             (parent_id). A lead proposes it; an event owner decides.
 *   gates_event_roles         a role the lead defines (name, description, team, kind) with a
 *                             set of permissions ticked from EventPermissions::MENU — never
 *                             typed. `perms_json` is what is in force; `proposed_json` is what
 *                             the lead asked for, kept so "the owner changed the ticks" stays
 *                             answerable. `review_json` is the assistant's notes for the owner,
 *                             written once at proposal so no page waits on a model. `spend_ceiling_minor` is the most one line may be
 *                             approved for under this role (never above the lead's own limit).
 *   gates_event_team_members  a person put in a team, in a role, as its lead or a member,
 *                             BY EMAIL ADDRESS — and nothing reaches them until an owner who
 *                             is not the proposer approves it. Then RoleInvites gives (or
 *                             offers) the access grant; `grant_id` / `invite_id` record which.
 *
 * Every status is VARCHAR, not ENUM (CLAUDE.md: a word added later is `Data truncated` on
 * MySQL and silently fine on SQLite). Who proposed and who decided are kind + id + name,
 * because a proposer is a member and a decider may be a member owner or a staff admin.
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
$text = $sqlite ? 'TEXT' : 'TEXT';
$ts   = $sqlite ? 'TEXT' : 'DATETIME';
$tail = $sqlite ? '' : ', PRIMARY KEY (id)';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

// Who proposed and who decided, the same seven columns on all three tables.
$who = "proposed_kind {$v(16)} NOT NULL,
        proposed_id $int NULL,
        proposed_name {$v(190)} NULL,
        decided_kind {$v(16)} NULL,
        decided_id $int NULL,
        decided_name {$v(190)} NULL,
        decided_at $ts NULL,
        reason {$v(500)} NULL,
        created_at $ts NOT NULL,
        updated_at $ts NULL";

if (!$schema->hasTable('gates_event_teams')) {
    DB::statement("CREATE TABLE gates_event_teams ($id,
        event_id $int NOT NULL,
        parent_id $int NULL,
        name {$v(120)} NOT NULL,
        purpose {$v(500)} NULL,
        status {$v(16)} NOT NULL DEFAULT 'proposed',
        $who$tail)$eng");
    echo "  + gates_event_teams\n";
}
if (!$schema->hasTable('gates_event_roles')) {
    DB::statement("CREATE TABLE gates_event_roles ($id,
        event_id $int NOT NULL,
        team_id $int NULL,
        name {$v(120)} NOT NULL,
        description {$v(500)} NULL,
        kind {$v(16)} NOT NULL DEFAULT 'staff',
        perms_json $text NULL,
        proposed_json $text NULL,
        spend_ceiling_minor $int NOT NULL DEFAULT 0,
        lead_with {$v(190)} NULL,
        review_json $text NULL,
        status {$v(16)} NOT NULL DEFAULT 'proposed',
        $who$tail)$eng");
    echo "  + gates_event_roles\n";
}
if (!$schema->hasTable('gates_event_team_members')) {
    DB::statement("CREATE TABLE gates_event_team_members ($id,
        event_id $int NOT NULL,
        team_id $int NULL,
        role_id $int NOT NULL,
        email {$v(190)} NOT NULL,
        team_role {$v(16)} NOT NULL DEFAULT 'member',
        until_at $ts NULL,
        grant_id $int NULL,
        invite_id $int NULL,
        status {$v(16)} NOT NULL DEFAULT 'proposed',
        $who$tail)$eng");
    echo "  + gates_event_team_members\n";
}
echo '  ' . SchemaIndex::ensure('gates_event_teams', 'idx_evteam_event', ['event_id', 'status']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_roles', 'idx_evrole_event', ['event_id', 'status']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_team_members', 'idx_evmember_event', ['event_id', 'status']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_team_members', 'idx_evmember_grant', ['grant_id']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_event_team_members', 'idx_evmember_invite', ['invite_id']) . "\n";
echo "event teams OK\n";
