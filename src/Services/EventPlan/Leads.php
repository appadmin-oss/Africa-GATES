<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Grants;
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

    /** @return array{ok:bool, error?:string, id?:int} */
    public static function give(int $eventId, string $email, string $until, int $adminId): array
    {
        $event = Plan::event($eventId);
        if (!$event) return ['ok' => false, 'error' => 'That event is not there.'];
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Type their email address.'];
        $u = DB::table('gates_users')->whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$u || (string) ($u->status ?? 'active') !== 'active') return ['ok' => false, 'error' => 'Nobody has an Africa GATES account with that email. Ask them to sign up first, then try again.'];
        if ((int) ($u->email_verified ?? 0) !== 1) return ['ok' => false, 'error' => 'That account hasn’t confirmed its email yet. Ask them to confirm it, then try again.'];
        if (Grants::allows(Plan::ACTOR, (int) $u->id, 'event.lead', 'event', $eventId)) return ['ok' => false, 'error' => 'They already lead this event.'];
        $end = (string) ($event->end_date ?? '') ?: (string) $event->event_date;
        $until = trim($until) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($until)) ? trim($until) . ' 23:59:59' : date('Y-m-d 23:59:59', (int) strtotime($end) + 86400);
        return Grants::grant(Plan::ACTOR, (int) $u->id, 'event.lead', 'event', $eventId, $until, $adminId > 0 ? $adminId : null, 'Event lead');
    }

    public static function end(int $grantId, int $eventId, int $adminId): bool
    {
        $g = Grants::ready() ? DB::table('gates_grants')->where('id', $grantId)->where('capability', 'event.lead')
            ->where('scope_kind', 'event')->where('scope_id', $eventId)->first() : null;
        return $g !== null && Grants::revoke($grantId, $adminId);
    }
}
