<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A ROLE OFFERED TO AN ADDRESS, and how it becomes a role (owner, 3 Nov 2026).
 *
 * Three faults this exists for, all reported together:
 *
 *   · THE NEW LEAD WAS NEVER TOLD. Staff gave the role and the page said "they see it under
 *     Events I lead when they sign in" — while nothing reached the person. Every role given or
 *     offered here is now TOLD, by email (Emails 'role'), with what it is, where, and when it ends.
 *   · NOBODY WITHOUT AN ACCOUNT COULD BE GIVEN ONE. Staff were told "ask them to sign up first,
 *     then try again" — a round trip an organisation bringing its own people met every time.
 *     The role is now held against the ADDRESS and becomes a grant (Grants::grant) the first
 *     time an account that has confirmed that address is signed in and is eligible
 *     (claimFor), so nobody has to come back.
 *   · A LEAD COULD NOT RECRUIT. The volunteers page offered "Ask for volunteers": a request.
 *     A lead may now put an approved volunteer on their event and offer a volunteer role by
 *     email, through the same door.
 *
 * What stays true, and why:
 *
 *   · HOLDING THE ADDRESS IS THE PROOF — for a lead or an owner. Those switch on only for an
 *     account whose email is CONFIRMED — the rule Account\Roles and Leads already hold; an
 *     unconfirmed account could be somebody who typed a stranger's address, and a lead moves
 *     money and messages guests. A volunteer role rests on the volunteer approval instead
 *     (eligible()), which is the stronger proof and was otherwise asked for twice.
 *   · A VOLUNTEER ROLE NEEDS AN APPROVED VOLUNTEER. Volunteers are vetted (an application,
 *     references, the volunteer team's approval). An invitation never skips that: it waits
 *     until the account's volunteer record is active, and says so in the email.
 *   · EVERY ROLE ENDS (Grants). An invitation carries the end date of the role it offers, and
 *     an invitation whose end has passed expires rather than granting a role already over.
 *   · A LEAD MAY RECRUIT VOLUNTEERS, NEVER LEADS. Leading moves money; it stays staff's to give.
 */
final class RoleInvites
{
    /** The volunteer roles a lead may offer at their own event: the night's, as staff's event page offers. */
    public const EVENT_VOL_ROLES = ['vol.door' => 'Door steward', 'vol.usher' => 'Usher', 'vol.team_lead' => 'Team lead'];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_role_invites') && Grants::ready();
    }

    /**
     * Give a role at an event to an address: at once, when an eligible account holds it, or as
     * an invitation that switches on later. Either way the person is told.
     *
     * @param array{kind:string,id:?int,name:string} $by who is giving it ('admin' or 'member')
     * @return array{ok:bool, error?:string, granted?:bool, pending?:bool, id?:int, emailed?:bool}
     */
    public static function offer(string $email, string $capability, int $eventId, string $until, array $by, string $note = '', string $label = ''): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $event = Plan::event($eventId);
        if (!$event) return ['ok' => false, 'error' => 'That event is not there.'];
        if (!isset(Grants::CAPABILITIES[$capability])) return ['ok' => false, 'error' => 'That is not a role this platform knows.'];
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Type their email address.'];
        $expires = self::until($event, $until);
        if (strtotime($expires) <= time()) return ['ok' => false, 'error' => 'The end date has already passed.'];

        $u = DB::table('gates_users')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($u && (string) ($u->status ?? 'active') !== 'active') return ['ok' => false, 'error' => 'That account is not active, so it cannot be given a role.'];
        if ($u && Grants::allows(Plan::ACTOR, (int) $u->id, $capability, 'event', $eventId)) {
            return ['ok' => false, 'error' => match ($capability) { 'event.lead' => 'They already lead this event.', 'event.own' => 'They already own this event.', default => 'They already have that role at this event.' }];
        }
        // Eligible now: the role is theirs at once.
        if ($u && self::eligible($u, $capability)) {
            $g = Grants::grant(Plan::ACTOR, (int) $u->id, $capability, 'event', $eventId, $expires, $by['kind'] === 'admin' ? ($by['id'] ?: null) : null,
                $note !== '' ? $note : self::noteFor($by));
            if (!$g['ok']) return $g;
            $sent = self::tell($email, (string) ($u->name ?? ''), $capability, $event, $expires, 'given', $by, $label);
            return ['ok' => true, 'granted' => true, 'id' => (int) $g['id'], 'emailed' => $sent];
        }
        // Not yet: held against the address, once.
        $have = DB::table('gates_role_invites')->where('email', $email)->where('capability', $capability)->where('scope_kind', 'event')
            ->where('scope_id', $eventId)->where('status', 'pending')->first();
        if ($have) return ['ok' => false, 'error' => 'That address already has this invitation waiting.'];
        $id = (int) DB::table('gates_role_invites')->insertGetId([
            'email' => $email, 'capability' => $capability, 'scope_kind' => 'event', 'scope_id' => $eventId, 'expires_at' => $expires,
            'invited_by_kind' => $by['kind'], 'invited_by_id' => $by['id'] ?: null, 'invited_by_name' => mb_substr($by['name'], 0, 190) ?: null,
            'note' => mb_substr($note, 0, 255) ?: null, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        // What the email asks them to do: a volunteer role always needs an approved application
        // (applying starts with an account); a lead needs an account, then a confirmed address.
        $vol = str_starts_with($capability, 'vol.');
        $state = $vol ? 'apply' : ($u && (int) ($u->email_verified ?? 0) !== 1 ? 'confirm' : 'account');
        $sent = self::tell($email, (string) ($u->name ?? ''), $capability, $event, $expires, $state, $by, $label);
        return ['ok' => true, 'pending' => true, 'id' => $id, 'emailed' => $sent];
    }

    /**
     * Turn this account's waiting invitations into roles, where it is now eligible. Called as a
     * signed-in member is served (UserAuthMiddleware), at most every few minutes a session, so
     * a role offered before the account existed is simply there the next time they look.
     *
     * @return int how many became roles
     */
    public static function claimFor(int $userId): int
    {
        if ($userId <= 0 || !self::ready()) return 0;
        $u = DB::table('gates_users')->where('id', $userId)->first();
        // Not "confirmed address" here: eligible() decides per role, and a volunteer role does
        // not need it (see there). An empty or inactive account can claim nothing.
        if (!$u || trim((string) ($u->email ?? '')) === '' || (string) ($u->status ?? 'active') !== 'active') return 0;
        $n = 0;
        foreach (DB::table('gates_role_invites')->whereRaw('LOWER(email) = ?', [strtolower((string) $u->email)])->where('status', 'pending')->get()->all() as $i) {
            if (strtotime((string) $i->expires_at) <= time()) {
                DB::table('gates_role_invites')->where('id', $i->id)->update(['status' => 'expired', 'decided_at' => date('Y-m-d H:i:s')]);
                continue;
            }
            if (!self::eligible($u, (string) $i->capability)) continue;   // a volunteer role waits for the approval
            if (Grants::allows(Plan::ACTOR, $userId, (string) $i->capability, (string) $i->scope_kind, (int) $i->scope_id)) {
                DB::table('gates_role_invites')->where('id', $i->id)->update(['status' => 'granted', 'decided_at' => date('Y-m-d H:i:s')]);
                continue;
            }
            $g = Grants::grant(Plan::ACTOR, $userId, (string) $i->capability, (string) $i->scope_kind, (int) $i->scope_id, (string) $i->expires_at,
                $i->invited_by_kind === 'admin' && $i->invited_by_id ? (int) $i->invited_by_id : null,
                (string) ($i->note ?: self::noteFor(['kind' => (string) $i->invited_by_kind, 'name' => (string) $i->invited_by_name])));
            if (!$g['ok']) continue;
            DB::table('gates_role_invites')->where('id', $i->id)->where('status', 'pending')
                ->update(['status' => 'granted', 'grant_id' => (int) $g['id'], 'decided_at' => date('Y-m-d H:i:s')]);
            $n++;
        }
        return $n;
    }

    /** Stop a waiting invitation. A lead may only stop one at their own event (the caller checks). */
    public static function cancel(int $id, int $eventId): bool
    {
        return self::ready() && DB::table('gates_role_invites')->where('id', $id)->where('scope_kind', 'event')->where('scope_id', $eventId)
            ->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /**
     * The invitations still waiting at one event (or every event, null), newest first.
     *
     * @param list<string>|null $caps only these capabilities
     * @return list<object> each with ->role, ->until, ->by and ->waiting (what it waits for)
     */
    public static function waiting(?int $eventId, ?array $caps = null): array
    {
        if (!self::ready()) return [];
        $q = DB::table('gates_role_invites')->where('status', 'pending')->where('scope_kind', 'event')->where('expires_at', '>', date('Y-m-d H:i:s'));
        if ($eventId !== null) $q->where('scope_id', $eventId);
        if ($caps !== null) $q->whereIn('capability', $caps);
        $rows = $q->orderByDesc('id')->get()->all();
        foreach ($rows as $r) {
            $r->role = Grants::CAPABILITIES[$r->capability] ?? $r->capability;
            $r->until = Workspace::day((string) $r->expires_at);
            $r->by = (string) ($r->invited_by_name ?: ($r->invited_by_kind === 'admin' ? 'An administrator' : 'A lead'));
            $r->waiting = self::reason((string) $r->email, (string) $r->capability);
        }
        return $rows;
    }

    /**
     * What an invitation to this address is waiting for, in words a person can act on. One
     * answer for staff's list, a lead's list and the invited person's own page, so the three
     * cannot describe the same wait differently.
     */
    public static function reason(string $email, string $capability): string
    {
        $u = DB::table('gates_users')->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
        if (!$u) return 'for them to create an account with this address';
        if ((string) ($u->status ?? 'active') !== 'active') return 'for their account to be active again';
        $vol = str_starts_with($capability, 'vol.');
        if ($vol) {
            return DB::table('gates_volunteers')->where('user_id', (int) $u->id)->exists()
                ? 'for their volunteer application to be approved' : 'for them to apply to volunteer';
        }
        return (int) ($u->email_verified ?? 0) !== 1 ? 'for them to confirm their email address' : 'for their next sign-in';
    }

    /**
     * The invitations waiting for THIS account's own address, each with what it waits for —
     * so the person it is for is told on their own page instead of seeing "nothing yet".
     *
     * @return list<object> each with ->role, ->title (the event), ->until, ->waiting, ->capability
     */
    public static function mine(int $userId): array
    {
        if ($userId <= 0 || !self::ready()) return [];
        $email = strtolower(trim((string) DB::table('gates_users')->where('id', $userId)->value('email')));
        if ($email === '') return [];
        $out = [];
        foreach (DB::table('gates_role_invites')->whereRaw('LOWER(email) = ?', [$email])->where('status', 'pending')->where('scope_kind', 'event')
                     ->where('expires_at', '>', date('Y-m-d H:i:s'))->orderBy('id')->get()->all() as $r) {
            $r->role = Grants::CAPABILITIES[$r->capability] ?? $r->capability;
            $r->title = (string) (DB::table('gates_site_events')->where('id', (int) $r->scope_id)->value('title') ?? 'An event');
            $r->until = Workspace::day((string) $r->expires_at);
            $r->waiting = self::reason($email, (string) $r->capability);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * May this account hold this role now?
     *
     *   · A LEAD or an owner: an active account whose email address is CONFIRMED. Leading moves
     *     money and messages guests, and holding the address is the only proof of who it is.
     *   · A VOLUNTEER ROLE: an approved volunteer, confirmed address or not (owner, 5 Nov 2026:
     *     "add volunteers to an event does not work"). The volunteer team's approval —
     *     application, references, a person deciding — is the stronger proof of who somebody is,
     *     and it was being asked for twice: a volunteer who signs in with a code texted to their
     *     phone never has their email marked confirmed, so every role a lead offered them waited
     *     for ever, with the lead's page saying it was on its way.
     */
    public static function eligible(object $u, string $capability): bool
    {
        if ((string) ($u->status ?? 'active') !== 'active') return false;
        if (!str_starts_with($capability, 'vol.')) return (int) ($u->email_verified ?? 0) === 1;
        return DB::table('gates_volunteers')->where('user_id', (int) $u->id)->whereIn('status', ['active', 'paused'])->exists();
    }

    /** The end of a role at an event: the date given, else the day after the event. */
    public static function until(object $event, string $until): string
    {
        $until = trim($until);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) return $until . ' 23:59:59';
        $end = (string) ($event->end_date ?? '') ?: (string) $event->event_date;
        return date('Y-m-d 23:59:59', (int) strtotime($end) + 86400);
    }

    /**
     * Tell the person, by email. Never fails the giving: a role is real whether or not a mail
     * server answered, and the page that gave it says which.
     */
    public static function tell(string $email, string $name, string $capability, object $event, string $expires, string $state, array $by, string $roleName = ''): bool
    {
        $base = rtrim(SiteUrl::base(), '/');
        $lead = $capability === 'event.lead';
        // Every role that opens the event's console (lead, owner, a team role) is opened there;
        // a night's volunteer role in the volunteer workspace.
        $console = in_array($capability, ['event.lead', 'event.own', 'event.finance', 'event.role', 'vol.event_role'], true);
        $id = (int) $event->id;
        [$url, $label] = match ($state) {
            'given'   => $console ? [$base . '/lead/event/' . $id, 'Open the event'] : [$base . '/volunteer/event/' . $id, 'Open your volunteer workspace'],
            'account' => [$base . '/account/register', 'Create your account'],
            'confirm' => [$base . '/account/login', 'Sign in and confirm your address'],
            default   => [$base . '/volunteer/apply', 'Apply to volunteer'],
        };
        $first = trim((string) strtok(trim($name), ' ')) ?: 'there';
        try {
            $r = Emails::send(OtpService::boot(), $email, 'role', [
                'first_name' => $first, 'role' => $lead ? 'Lead' : ($roleName !== '' ? $roleName : ($capability === 'event.own' ? 'Event owner' : ($capability === 'event.finance' ? 'Finance admin' : (Grants::CAPABILITIES[$capability] ?? 'Volunteer')))),
                'place' => (string) $event->title, 'when' => !empty($event->event_date) ? date('j F Y', (int) strtotime((string) $event->event_date)) : '',
                'until' => date('j F Y', (int) strtotime($expires)), 'inviter' => (string) ($by['name'] ?? ''),
                'state' => $state, 'action_url' => $url, 'action_label' => $label,
            ]);
            return !empty($r['ok']);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function noteFor(array $by): string
    {
        return $by['kind'] === 'admin' ? 'Given on the event page' : 'Recruited by the event lead' . (($by['name'] ?? '') !== '' ? ' (' . $by['name'] . ')' : '');
    }
}
