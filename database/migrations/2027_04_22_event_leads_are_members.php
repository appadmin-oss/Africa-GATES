<?php
/**
 * Event leads become member accounts (owner, 29 Oct 2026: the lead console moved out of the
 * admin console to /lead, because an organisation adopting the platform brings leads who are
 * not our staff). Plan::ACTOR is 'member' from now on.
 *
 * This moves what already exists, so no lead loses their event on the day of the upload:
 *
 *   · each live `event.lead` grant held by a staff admin is given, with the same scope and end,
 *     to the member account whose VERIFIED email is that admin's email — the same proof the one
 *     sign-in already uses (Account\Roles) — and the staff grant is revoked (stamped, never
 *     deleted, so who had what stays answerable);
 *   · work owned by that admin on an event plan, and their lead requests, follow them.
 *
 * An admin with no matching verified member account keeps nothing here: their grant is left
 * live, and harmless, since no console reads an admin's lead grant any more. Staff give the
 * event again from its Event leads page once that person has an account.
 *
 * Idempotent: a grant already given to the member is not given twice. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
if (!$schema->hasTable('gates_grants') || !$schema->hasTable('gates_admins')) return;
$now = date('Y-m-d H:i:s');
$moved = 0;
foreach (DB::table('gates_grants')->where('capability', 'event.lead')->where('actor_kind', 'admin')
             ->whereNull('revoked_at')->where('expires_at', '>', $now)->get() as $g) {
    $email = strtolower(trim((string) DB::table('gates_admins')->where('id', $g->actor_id)->value('email')));
    if ($email === '') continue;
    $uid = (int) DB::table('gates_users')->whereRaw('LOWER(email) = ?', [$email])->where('email_verified', 1)->value('id');
    if ($uid <= 0) continue;
    $has = DB::table('gates_grants')->where('capability', 'event.lead')->where('actor_kind', 'member')->where('actor_id', $uid)
        ->where('scope_kind', $g->scope_kind)->where('scope_id', $g->scope_id)->whereNull('revoked_at')->where('expires_at', '>', $now)->exists();
    if (!$has) {
        $row = (array) $g;
        unset($row['id']);
        $row['actor_kind'] = 'member';
        $row['actor_id'] = $uid;
        DB::table('gates_grants')->insert($row);
    }
    DB::table('gates_grants')->where('id', $g->id)->update(['revoked_at' => $now]);
    if ($schema->hasTable('gates_event_work') && $schema->hasColumn('gates_event_work', 'owner_kind')) {
        DB::table('gates_event_work')->where('owner_kind', 'admin')->where('owner_id', $g->actor_id)
            ->when($g->scope_kind === 'event', static fn ($q) => $q->where('event_id', $g->scope_id))
            ->update(['owner_kind' => 'member', 'owner_id' => $uid]);
    }
    if ($schema->hasTable('gates_requests')) {
        DB::table('gates_requests')->where('from_kind', 'admin')->where('from_id', $g->actor_id)
            ->whereIn('type', ['spend', 'access', 'message', 'venue', 'other'])
            ->update(['from_kind' => 'member', 'from_id' => $uid]);
    }
    $moved++;
}
echo "  ~ event leads moved to member accounts: $moved\n";
