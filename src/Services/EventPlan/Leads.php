<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Grants;
use AfricaGates\Services\RoleInvites;
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHO LEADS AN EVENT, and how staff give and end it (owner, 29 Oct 2026).
 *
 * A lead is a member account (Plan::ACTOR), found by the email address it signed up with, so
 * an organisation adopting the platform can have its own people lead its event without any
 * of them touching the admin console. Staff give the role here, from the event's admin page;
 * the lead opens it at /lead from their own account.
 *
 *   · BY A VERIFIED ADDRESS. Holding the address is the proof, as with every other role on the
 *     one sign-in (Account\Roles): an unverified account could be somebody who typed a stranger's
 *     address, and leading an event moves money and messages guests.
 *   · IT ENDS. The day after the event unless an earlier date is given, like every grant.
 */
final class Leads
{
    /** @return list<object> the live leads of one event, each with ->name and ->email */
    public static function of(int $eventId): array
    {
        $out = [];
        foreach (Grants::holders('event.lead', 'event', $eventId) as $g) {
            if ($g->actor_kind !== Plan::ACTOR) continue;
            $u = DB::table('gates_users')->where('id', $g->actor_id)->first(['name', 'email']);
            $g->name = (string) ($u->name ?? 'A member');
            $g->email = (string) ($u->email ?? '');
            $g->until = Workspace::day((string) $g->expires_at);
            $out[] = $g;
        }
        return $out;
    }

    /**
     * Give the role to an address. An eligible account (active, address confirmed) holds it at
     * once; anybody else is sent an invitation that switches on when they sign in with that
     * address confirmed (RoleInvites). Either way they are TOLD — it used to reach nobody.
     *
     * @return array{ok:bool, error?:string, id?:int, granted?:bool, pending?:bool, emailed?:bool}
     */
    public static function give(int $eventId, string $email, string $until, int $adminId, string $adminName = ''): array
    {
        $event = Plan::event($eventId);
        if (!$event) return ['ok' => false, 'error' => 'That event is not there.'];
        if (!RoleInvites::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        return RoleInvites::offer($email, 'event.lead', $eventId, $until, ['kind' => 'admin', 'id' => $adminId > 0 ? $adminId : null, 'name' => $adminName]);
    }

    /** Every live lead at every event, and every invitation still waiting — for staff's one list. */
    public static function everywhere(): array
    {
        $out = [];
        foreach (Grants::ready() ? DB::table('gates_grants')->where('capability', 'event.lead')->where('scope_kind', 'event')->whereNull('revoked_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))->orderBy('expires_at')->get()->all() : [] as $g) {
            if ($g->actor_kind !== Plan::ACTOR) continue;
            $u = DB::table('gates_users')->where('id', $g->actor_id)->first(['name', 'email']);
            $out[] = (object) ['event_id' => (int) $g->scope_id, 'name' => (string) ($u->name ?? 'A member'), 'email' => (string) ($u->email ?? ''),
                'until' => Workspace::day((string) $g->expires_at), 'state' => 'lead', 'grant' => (int) $g->id];
        }
        // Owners too (EventPlan\Teams): the person who decides what the leads propose.
        foreach (Grants::ready() ? DB::table('gates_grants')->where('capability', 'event.own')->where('scope_kind', 'event')->whereNull('revoked_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))->orderBy('expires_at')->get()->all() : [] as $g) {
            if ($g->actor_kind !== Plan::ACTOR) continue;
            $u = DB::table('gates_users')->where('id', $g->actor_id)->first(['name', 'email']);
            $out[] = (object) ['event_id' => (int) $g->scope_id, 'name' => (string) ($u->name ?? 'A member'), 'email' => (string) ($u->email ?? ''),
                'until' => Workspace::day((string) $g->expires_at), 'state' => 'owner', 'grant' => (int) $g->id];
        }
        foreach (RoleInvites::waiting(null, ['event.lead', 'event.own']) as $i) {
            $out[] = (object) ['event_id' => (int) $i->scope_id, 'name' => '', 'email' => (string) $i->email, 'until' => $i->until,
                'state' => $i->capability === 'event.own' ? 'owner_invited' : 'invited', 'waiting' => $i->waiting, 'invite' => (int) $i->id];
        }
        return $out;
    }

    /**
     * Name an OWNER: the person who approves what this event's leads propose about teams, roles
     * and access (EventPlan\Teams). Staff's to give, never a lead's; by address, so an outside
     * organisation's own person can be invited, and it ends like every grant.
     */
    public static function giveOwner(int $eventId, string $email, string $until, int $adminId, string $adminName = ''): array
    {
        if (!Plan::event($eventId)) return ['ok' => false, 'error' => 'That event is not there.'];
        if (!RoleInvites::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        return RoleInvites::offer($email, 'event.own', $eventId, $until, ['kind' => 'admin', 'id' => $adminId > 0 ? $adminId : null, 'name' => $adminName]);
    }

    /**
     * Name the event's FINANCE ADMIN: who decides its spend requests, approves its spend lines
     * and signs off its money gates. Staff's to give, by address, ending like every grant.
     */
    public static function giveFinance(int $eventId, string $email, string $until, int $adminId, string $adminName = ''): array
    {
        if (!Plan::event($eventId)) return ['ok' => false, 'error' => 'That event is not there.'];
        if (!RoleInvites::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        return RoleInvites::offer($email, 'event.finance', $eventId, $until, ['kind' => 'admin', 'id' => $adminId > 0 ? $adminId : null, 'name' => $adminName]);
    }

    /** @return list<object> the event's live finance admins, each with ->name, ->email, ->until */
    public static function finance(int $eventId): array
    {
        $out = [];
        foreach (Grants::holders('event.finance', 'event', $eventId) as $g) {
            if ($g->actor_kind !== Plan::ACTOR) continue;
            $u = DB::table('gates_users')->where('id', $g->actor_id)->first(['name', 'email']);
            $g->name = (string) ($u->name ?? 'A member');
            $g->email = (string) ($u->email ?? '');
            $g->until = Workspace::day((string) $g->expires_at);
            $out[] = $g;
        }
        return $out;
    }

    /** End a lead's, an owner's or a finance admin's access at this event — only those, only here. */
    public static function end(int $grantId, int $eventId, ?int $adminId, string $capability = 'event.lead'): bool
    {
        if (!in_array($capability, ['event.lead', 'event.own', 'event.finance'], true)) return false;
        $g = Grants::ready() ? DB::table('gates_grants')->where('id', $grantId)->where('capability', $capability)
            ->where('scope_kind', 'event')->where('scope_id', $eventId)->first() : null;
        return $g !== null && Grants::revoke($grantId, $adminId);
    }
}
