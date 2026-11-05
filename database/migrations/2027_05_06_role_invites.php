<?php
/**
 * Roles given to an ADDRESS before anybody holds it (owner, 3 Nov 2026: "you can't invite
 * someone who has no account yet … the lead should also be able to recruit people").
 *
 *   gates_role_invites   one row per invitation: a capability at a scope (an event lead, a
 *                        door steward at an event), for an email address, until a date. It
 *                        becomes a real grant (gates_grants) the first time an account that
 *                        has CONFIRMED that address is signed in and is eligible for it — an
 *                        active account for a lead, an approved volunteer for a volunteer
 *                        role — so nobody has to come back and give it again once the person
 *                        has signed up. RoleInvites holds the rules.
 *
 * Who invited is two columns (kind + id), because a lead is a member account and an
 * administrator is not, and the record has to say which. `status` is pending | granted |
 * cancelled | expired, as VARCHAR (CLAUDE.md: an ENUM refuses a word added later, silently).
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
$ts   = $sqlite ? 'TEXT' : 'DATETIME';
$tail = $sqlite ? '' : ', PRIMARY KEY (id)';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_role_invites')) {
    DB::statement("CREATE TABLE gates_role_invites ($id,
        email {$v(190)} NOT NULL,
        capability {$v(48)} NOT NULL,
        scope_kind {$v(16)} NOT NULL,
        scope_id $int NULL,
        expires_at $ts NOT NULL,
        invited_by_kind {$v(16)} NOT NULL,
        invited_by_id $int NULL,
        invited_by_name {$v(190)} NULL,
        note {$v(255)} NULL,
        status {$v(16)} NOT NULL DEFAULT 'pending',
        grant_id $int NULL,
        created_at $ts NOT NULL,
        decided_at $ts NULL$tail)$eng");
    echo "  + gates_role_invites\n";
}
echo '  ' . SchemaIndex::ensure('gates_role_invites', 'idx_roleinv_email', ['email', 'status']) . "\n";
echo '  ' . SchemaIndex::ensure('gates_role_invites', 'idx_roleinv_scope', ['scope_kind', 'scope_id', 'status']) . "\n";
echo "role invites OK\n";
