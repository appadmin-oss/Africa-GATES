<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Grants;
use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\RoleInvites;
use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AN EVENT'S TEAMS, ROLES AND PEOPLE, and the owner who decides them (owner, 3 Nov 2026: "the
 * event lead manages this … can set up committees or teams, assigns lead … event owner
 * approves, event owner can set permission as they like").
 *
 *   · WHO DECIDES IS SET BY HOW BIG THE CHANGE IS (Tiers; owner, 5 Nov 2026: "Event lead should
 *     be able to assign roles and tasks"). A team, a role with no high-risk tick and a person
 *     put into an approved role are MEDIUM: a lead's own is the decision, recorded as theirs.
 *     A role that can move money, message many people, run the door or change settings is
 *     LARGE and starts `proposed` until an owner approves it. Every route to access still
 *     passes `decide()`, so the claim-then-give ordering below holds for both.
 *   · TWO PEOPLE. An owner never decides their own proposal, nor access for their own address,
 *     even when they also lead the event; another owner or the platform's staff decides it.
 *   · A DECISION HAS A REASON, and is audited by the caller with it. Refusing says why to the
 *     proposer; changing a role's ticks records what was asked (`proposed_json`) beside what
 *     was approved.
 *   · ACCESS IS STILL A GRANT. An approved person gets `event.role` (or `vol.event_role` for a
 *     volunteer role — the vetting rule stays) through RoleInvites, by email, so it ends like
 *     every grant, an outside organisation's person can be invited by address, and they are
 *     told. What the grant opens is read from the role on every request, so a role archived or
 *     re-ticked changes access on the next page.
 *   · STAFF SEE ALL OF IT and may decide or end anything, from the event's admin page.
 */
final class Teams
{
    public const STATUSES = ['proposed' => 'Waiting for the owner', 'approved' => 'Approved', 'refused' => 'Refused',
                             'archived' => 'Archived', 'withdrawn' => 'Withdrawn', 'ended' => 'Ended'];
    public const TONE = ['proposed' => 'warn', 'approved' => 'ok', 'refused' => 'bad', 'archived' => 'mute', 'withdrawn' => 'mute', 'ended' => 'mute'];
    public const KINDS = ['staff' => 'Event team', 'volunteer' => 'Volunteer role (needs an approved volunteer)'];
    public const TEAM_ROLES = ['member' => 'Member', 'lead' => 'Team lead'];
    /** What a proposal can be about, and its table. */
    public const SUBJECTS = ['team' => 'gates_event_teams', 'role' => 'gates_event_roles', 'member' => 'gates_event_team_members'];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_event_team_members') && RoleInvites::ready();
    }

    // ── owners ──────────────────────────────────────────────────────────────────────────

    /** @return list<object> the live owners of one event, each with ->name, ->email, ->until */
    public static function owners(int $eventId): array
    {
        $out = [];
        foreach (Grants::holders('event.own', 'event', $eventId) as $g) {
            if ($g->actor_kind !== Plan::ACTOR) continue;
            $u = DB::table('gates_users')->where('id', $g->actor_id)->first(['name', 'email']);
            $g->name = (string) ($u->name ?? 'A member');
            $g->email = (string) ($u->email ?? '');
            $g->until = Workspace::day((string) $g->expires_at);
            $out[] = $g;
        }
        return $out;
    }

    public static function isOwner(int $memberId, int $eventId): bool
    {
        return $memberId > 0 && Grants::allows(Plan::ACTOR, $memberId, 'event.own', 'event', $eventId);
    }

    // ── proposing ───────────────────────────────────────────────────────────────────────

    /** @param array{id:int,name:string} $me @return array{ok:bool,error?:string,id?:int,event?:int,msg?:string} */
    public static function proposeTeam(int $eventId, array $in, array $me): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::isLead($me['id'], $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can propose its teams.'];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'error' => 'Name the team.'];
        $parent = (int) ($in['parent'] ?? 0) ?: null;
        if ($parent && !DB::table('gates_event_teams')->where('id', $parent)->where('event_id', $eventId)->whereIn('status', ['proposed', 'approved'])->exists()) {
            return ['ok' => false, 'error' => 'That parent team is not at this event.'];
        }
        if (DB::table('gates_event_teams')->where('event_id', $eventId)->whereIn('status', ['proposed', 'approved'])->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return ['ok' => false, 'error' => 'This event already has a team by that name.'];
        }
        // A team gives nobody access, so it is a MEDIUM change (Tiers): the lead's own decision.
        $id = (int) DB::table('gates_event_teams')->insertGetId(['event_id' => $eventId, 'parent_id' => $parent, 'name' => mb_substr($name, 0, 120),
            'purpose' => mb_substr(trim((string) ($in['purpose'] ?? '')), 0, 500) ?: null, 'status' => 'approved'] + self::proposer($me) + self::byLead($me));
        return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Team set up. Put people in it with a role.'];
    }

    /**
     * A role, with ticks from the menu and nothing else. A key that is not on the menu is
     * refused, not dropped: the lead is told the platform cannot enforce it.
     */
    public static function proposeRole(int $eventId, array $in, array $me, ?TeamSuggest $assist = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::isLead($me['id'], $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can propose its roles.'];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'error' => 'Name the role.'];
        $team = (int) ($in['team'] ?? 0) ?: null;
        if ($team && !DB::table('gates_event_teams')->where('id', $team)->where('event_id', $eventId)->whereIn('status', ['proposed', 'approved'])->exists()) {
            return ['ok' => false, 'error' => 'That team is not at this event.'];
        }
        $kind = (string) ($in['kind'] ?? 'staff');
        if (!isset(self::KINDS[$kind])) return ['ok' => false, 'error' => 'Choose what kind of role it is.'];
        $r = self::ticksIn($in);
        if (!$r['ok']) return $r;
        $perms = $r['perms'];
        if ($perms === []) return ['ok' => false, 'error' => 'Tick at least one thing this role may do.'];
        $c = self::ceilingIn($in, $perms);
        if (!$c['ok']) return $c;
        $leadWith = implode(',', EventPermissions::leadWith($perms, implode(',', (array) ($in['lead_with'] ?? []))));
        // The assistant's notes for the owner, written once now so the owner's page waits on nothing.
        $notes = ($assist ?? new TeamSuggest())->review(Plan::event($eventId), $name, (string) ($in['description'] ?? ''), $perms, $kind);
        $id = (int) DB::table('gates_event_roles')->insertGetId(['event_id' => $eventId, 'team_id' => $team, 'name' => mb_substr($name, 0, 120),
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null, 'kind' => $kind,
            'perms_json' => json_encode($perms), 'proposed_json' => json_encode($perms), 'spend_ceiling_minor' => $c['minor'],
            'lead_with' => $leadWith ?: null, 'review_json' => $notes ? json_encode($notes) : null,
            // A role with no high-risk tick is a MEDIUM change: the lead's. One that can move
            // money, message many people, run the door or change settings is LARGE (Tiers).
            'status' => Tiers::forRole($perms) === 'medium' ? 'approved' : 'proposed'] + self::proposer($me) + (Tiers::forRole($perms) === 'medium' ? self::byLead($me) : []));
        if (Tiers::forRole($perms) === 'medium') return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Role set up. You can put people in it now.'];
        $told = self::tellOwners($eventId, 'The role ' . $name, $me);
        return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Proposed: it can ' . self::riskWords($perms) . ', so an owner decides. ' . $told];
    }

    /**
     * Somebody, by email, in a role (and a team). A lead may propose anybody into any team; a
     * team's own lead only into their team, and only as a member. An owner still decides.
     */
    public static function proposeMember(int $eventId, array $in, array $me): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $st = EventPermissions::standing($me['id'], $eventId);
        $team = (int) ($in['team'] ?? 0) ?: null;
        $teamRole = (string) ($in['team_role'] ?? 'member');
        if (!isset(self::TEAM_ROLES[$teamRole])) return ['ok' => false, 'error' => 'Choose member or team lead.'];
        if (!$st['lead']) {
            if (!$team || !in_array($team, $st['teamLeadOf'], true)) return ['ok' => false, 'error' => 'Only a lead of this event, or the lead of that team, can propose somebody for it.'];
            if ($teamRole === 'lead') return ['ok' => false, 'error' => 'A team lead is proposed by the event’s lead.'];
        }
        $role = DB::table('gates_event_roles')->where('id', (int) ($in['role'] ?? 0))->where('event_id', $eventId)->whereIn('status', ['proposed', 'approved'])->first();
        if (!$role) return ['ok' => false, 'error' => 'Choose one of this event’s roles.'];
        if ($team && !DB::table('gates_event_teams')->where('id', $team)->where('event_id', $eventId)->whereIn('status', ['proposed', 'approved'])->exists()) {
            return ['ok' => false, 'error' => 'That team is not at this event.'];
        }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Type their email address.'];
        if (DB::table('gates_event_team_members')->where('event_id', $eventId)->where('role_id', $role->id)->where('email', $email)->whereIn('status', ['proposed', 'approved'])->exists()) {
            return ['ok' => false, 'error' => 'That address is already in this role, or waiting for it.'];
        }
        $event = Plan::event($eventId);
        $until = trim((string) ($in['until'] ?? ''));
        $id = (int) DB::table('gates_event_team_members')->insertGetId(['event_id' => $eventId, 'team_id' => $team ?? ($role->team_id ? (int) $role->team_id : null),
            'role_id' => (int) $role->id, 'email' => $email, 'team_role' => $teamRole, 'until_at' => $event ? RoleInvites::until($event, $until) : null,
            'status' => 'proposed'] + self::proposer($me));
        // Putting somebody in an approved role is a MEDIUM change: a lead's is the decision
        // (owner, 5 Nov 2026: "Event lead should be able to assign roles and tasks"). A team
        // lead's proposal, or one into a role still waiting, goes to a lead or an owner.
        if ($st['lead'] && $role->status === 'approved' && (!$team || DB::table('gates_event_teams')->where('id', $team)->value('status') === 'approved')
            && strtolower((string) DB::table('gates_users')->where('id', $me['id'])->value('email')) !== $email) {
            $r = self::decide('member', $id, 'approve', 'Assigned by the lead', ['kind' => 'lead', 'id' => $me['id'], 'name' => $me['name']]);
            if ($r['ok']) return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Assigned.' . preg_replace('/^Approved\./', '', (string) $r['msg'])];
            return $r;
        }
        $told = self::tellOwners($eventId, $email . ' as ' . $role->name, $me);
        return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Proposed. Nothing reaches them until it is approved. ' . $told];
    }

    /** The proposer takes back something still waiting. */
    public static function withdraw(string $subject, int $id, array $me): array
    {
        $t = self::SUBJECTS[$subject] ?? null;
        $row = $t && self::ready() ? DB::table($t)->where('id', $id)->first() : null;
        if (!$row) return ['ok' => false, 'error' => 'That is not there.'];
        if ($row->proposed_kind !== Plan::ACTOR || (int) $row->proposed_id !== $me['id']) return ['ok' => false, 'error' => 'Only who proposed it can withdraw it.'];
        $n = DB::table($t)->where('id', $id)->where('status', 'proposed')->update(['status' => 'withdrawn', 'updated_at' => date('Y-m-d H:i:s')]);
        return $n > 0 ? ['ok' => true, 'event' => (int) $row->event_id, 'msg' => 'Withdrawn.'] : ['ok' => false, 'error' => 'That has already been decided.'];
    }

    // ── deciding ────────────────────────────────────────────────────────────────────────

    /**
     * An owner (or staff) decides one proposal: approve or refuse, with a reason. For a role the
     * decider may change the ticks and the ceiling — recorded as such — and for a person,
     * approving is what gives them access.
     *
     * @param array{kind:string,id:?int,name:string} $by 'member' (an owner) or 'admin' (staff; the caller checks the page's gate)
     * @return array{ok:bool,error?:string,event?:int,msg?:string,verdict?:string}
     */
    public static function decide(string $subject, int $id, string $verdict, string $reason, array $by, array $in = []): array
    {
        $t = self::SUBJECTS[$subject] ?? null;
        if (!$t || !self::ready()) return ['ok' => false, 'error' => 'That is not something to decide.'];
        $row = DB::table($t)->where('id', $id)->first();
        if (!$row) return ['ok' => false, 'error' => 'That is not there.'];
        $eventId = (int) $row->event_id;
        if ($by['kind'] === 'lead') {
            // A lead decides MEDIUM changes (Tiers): a person into an approved role, a team, a
            // role with no high-risk tick. Large ones stay an owner's.
            if (!Plan::isLead((int) $by['id'], $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can do that.'];
            $tier = match ($subject) { 'role' => Tiers::forRole(EventPermissions::ticks((string) ($row->perms_json ?? ''))), default => 'medium' };
            if ($tier !== 'medium') return ['ok' => false, 'error' => 'That role can ' . self::riskWords(EventPermissions::ticks((string) $row->perms_json)) . ', so an owner decides it.'];
            if ($subject === 'member' && strtolower((string) DB::table('gates_users')->where('id', (int) $by['id'])->value('email')) === strtolower((string) $row->email)) {
                return ['ok' => false, 'error' => 'Nobody approves access for themselves. An owner or the platform’s staff decides it.'];
            }
        } elseif ($by['kind'] === Plan::ACTOR) {
            if (!self::isOwner((int) $by['id'], $eventId)) return ['ok' => false, 'error' => 'Only an owner of this event decides its teams and roles.'];
            if ($row->proposed_kind === Plan::ACTOR && (int) $row->proposed_id === (int) $by['id']) {
                return ['ok' => false, 'error' => 'You proposed this, so another owner — or the platform’s staff — decides it.'];
            }
            if ($subject === 'member' && strtolower((string) DB::table('gates_users')->where('id', (int) $by['id'])->value('email')) === strtolower((string) $row->email)) {
                return ['ok' => false, 'error' => 'Nobody approves access for themselves. Another owner or the platform’s staff decides it.'];
            }
        } elseif ($by['kind'] !== 'admin') {
            return ['ok' => false, 'error' => 'That is not something you can decide.'];
        }
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Give a reason. It is recorded, and the lead sees it.'];
        if (!in_array($verdict, ['approve', 'refuse'], true)) return ['ok' => false, 'error' => 'Approve or refuse it.'];
        if ($row->status !== 'proposed') return ['ok' => false, 'error' => 'That has already been decided.'];

        $set = ['decided_kind' => $by['kind'], 'decided_id' => $by['id'] ?: null, 'decided_name' => mb_substr((string) $by['name'], 0, 190) ?: null,
                'decided_at' => date('Y-m-d H:i:s'), 'reason' => mb_substr($reason, 0, 500), 'updated_at' => date('Y-m-d H:i:s')];
        $word = $verdict === 'approve' ? 'approved' : 'refused';
        $extra = '';
        if ($verdict === 'approve' && $subject === 'role') {
            // The decider's ticks, when they sent any: the owner "sets permission as they like".
            if (array_key_exists('perms', $in)) {
                $r = self::ticksIn($in);
                if (!$r['ok']) return $r;
                if ($r['perms'] === []) return ['ok' => false, 'error' => 'Tick at least one thing, or refuse the role.'];
                $c = self::ceilingIn($in, $r['perms']);
                if (!$c['ok']) return $c;
                $was = EventPermissions::ticks((string) $row->proposed_json);
                $set['perms_json'] = json_encode($r['perms']);
                $set['spend_ceiling_minor'] = $c['minor'];
                $set['lead_with'] = implode(',', EventPermissions::leadWith($r['perms'], (string) $row->lead_with)) ?: null;
                if ($r['perms'] != $was || $c['minor'] !== (int) $row->spend_ceiling_minor) $word = 'changed';
            }
        }
        if ($verdict === 'approve' && $subject === 'member') {
            $role = DB::table('gates_event_roles')->where('id', (int) $row->role_id)->first();
            if (!$role || $role->status !== 'approved') return ['ok' => false, 'error' => 'Decide the role first: a person can only be given an approved role.'];
            if ($row->team_id && DB::table('gates_event_teams')->where('id', (int) $row->team_id)->value('status') !== 'approved') {
                return ['ok' => false, 'error' => 'Decide the team first: a person can only join an approved team.'];
            }
        }
        $set['status'] = $verdict === 'approve' ? 'approved' : 'refused';
        // CLAIM, then act. Access is given (and mailed) only by the decision that won the
        // guarded write: given first, two owners deciding at once — one approving, one
        // refusing — could tell somebody they were in while the row ended up refused.
        $n = DB::table($t)->where('id', $id)->where('status', 'proposed')->update($set);
        if ($n === 0) return ['ok' => false, 'error' => 'Somebody else decided this a moment ago.'];
        if ($verdict === 'approve' && $subject === 'member') {
            $g = self::giveAccess($row, $role, $by);
            if (!$g['ok']) {
                // Nothing was given, so the decision is handed back rather than left
                // reading "approved" over a person who holds nothing.
                DB::table($t)->where('id', $id)->update(['status' => 'proposed', 'decided_kind' => null, 'decided_id' => null,
                    'decided_name' => null, 'decided_at' => null, 'reason' => $row->reason, 'updated_at' => date('Y-m-d H:i:s')]);
                return $g;
            }
            $ids = array_intersect_key($g, array_flip(['grant_id', 'invite_id']));
            if ($ids !== []) DB::table($t)->where('id', $id)->update($ids);
            $extra = !empty($g['granted']) ? ' They have it now.' : ' They are invited; it switches on when they sign in' . ($role->kind === 'volunteer' ? ' as an approved volunteer.' : ' with that address confirmed.');
        }
        self::tellProposer($row, $subject, $word, $reason, $by);
        $msg = ['approved' => 'Approved.', 'changed' => 'Approved with your changes. The lead sees what you changed.', 'refused' => 'Refused. The lead sees your reason.'][$word];
        return ['ok' => true, 'event' => $eventId, 'verdict' => $word, 'msg' => $msg . $extra];
    }

    /**
     * End one person's place in a role: their membership stops opening anything at once, and
     * their access grant ends when nothing else holds it. An owner, a lead, or staff.
     */
    public static function endMember(int $memberId, int $eventId, string $reason, array $by): array
    {
        $m = self::ready() ? DB::table('gates_event_team_members')->where('id', $memberId)->where('event_id', $eventId)->first() : null;
        if (!$m) return ['ok' => false, 'error' => 'That person is not in a role at this event.'];
        if ($by['kind'] === Plan::ACTOR && !self::isOwner((int) $by['id'], $eventId) && !Plan::isLead((int) $by['id'], $eventId)) {
            return ['ok' => false, 'error' => 'Only an owner or a lead of this event can end a role.'];
        }
        if (trim($reason) === '') return ['ok' => false, 'error' => 'Give a reason. It is recorded.'];
        if (!in_array($m->status, ['approved', 'proposed'], true)) return ['ok' => false, 'error' => 'That has already ended.'];
        DB::table('gates_event_team_members')->where('id', $memberId)->update(['status' => 'ended', 'reason' => mb_substr(trim($reason), 0, 500),
            'decided_kind' => $by['kind'], 'decided_id' => $by['id'] ?: null, 'decided_name' => mb_substr((string) $by['name'], 0, 190) ?: null,
            'decided_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        self::release($m, $by['kind'] === 'admin' ? (int) $by['id'] : null);
        return ['ok' => true, 'event' => $eventId, 'msg' => 'Ended. It leaves their console on their next page.'];
    }

    /** Archive a role: it stops opening anything for everybody in it, at once. An owner or staff. */
    public static function archiveRole(int $roleId, int $eventId, string $reason, array $by): array
    {
        $r = self::ready() ? DB::table('gates_event_roles')->where('id', $roleId)->where('event_id', $eventId)->first() : null;
        if (!$r) return ['ok' => false, 'error' => 'That role is not at this event.'];
        if ($by['kind'] === Plan::ACTOR && !self::isOwner((int) $by['id'], $eventId)) return ['ok' => false, 'error' => 'Only an owner of this event can archive a role.'];
        if (trim($reason) === '') return ['ok' => false, 'error' => 'Give a reason. It is recorded.'];
        if ($r->status !== 'approved') return ['ok' => false, 'error' => 'Only an approved role can be archived.'];
        DB::table('gates_event_roles')->where('id', $roleId)->update(['status' => 'archived', 'reason' => mb_substr(trim($reason), 0, 500), 'updated_at' => date('Y-m-d H:i:s')]);
        foreach (DB::table('gates_event_team_members')->where('role_id', $roleId)->whereIn('status', ['approved', 'proposed'])->get()->all() as $m) {
            self::endMember((int) $m->id, $eventId, 'The role was archived: ' . trim($reason), $by);
        }
        return ['ok' => true, 'event' => $eventId, 'msg' => 'Archived. Nobody holds it from their next page.'];
    }

    // ── reading ─────────────────────────────────────────────────────────────────────────

    /** @return list<object> */
    public static function teams(int $eventId): array
    {
        return self::ready() ? DB::table('gates_event_teams')->where('event_id', $eventId)->orderBy('id')->get()->all() : [];
    }

    /** @return list<object> each with ->perms (the ticks in force) */
    public static function roles(int $eventId): array
    {
        if (!self::ready()) return [];
        return array_map(static function ($r) {
            $r->perms = EventPermissions::ticks((string) $r->perms_json);
            $r->asked = EventPermissions::ticks((string) $r->proposed_json);
            $r->notes = json_decode((string) ($r->review_json ?? ''), true) ?: [];
            return $r;
        }, DB::table('gates_event_roles')->where('event_id', $eventId)->orderBy('id')->get()->all());
    }

    /** @return list<object> */
    public static function members(int $eventId): array
    {
        return self::ready() ? DB::table('gates_event_team_members')->where('event_id', $eventId)->orderBy('id')->get()->all() : [];
    }

    /** How many proposals wait at an event: the owner's count, never 0 drawn. */
    public static function waitingCount(int $eventId): int
    {
        if (!self::ready()) return 0;
        $n = 0;
        foreach (self::SUBJECTS as $t) $n += DB::table($t)->where('event_id', $eventId)->where('status', 'proposed')->count();
        return $n;
    }

    /** One line for a member row: their name when an account holds the address, else the address. */
    public static function who(object $m): string
    {
        $n = (string) (DB::table('gates_users')->whereRaw('LOWER(email) = ?', [strtolower((string) $m->email)])->value('name') ?? '');
        return $n !== '' ? $n . ' · ' . $m->email : (string) $m->email;
    }

    // ── internals ───────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool,perms?:list<string>,error?:string} the ticks posted, each on the menu */
    public static function ticksIn(array $in): array
    {
        $out = [];
        foreach ((array) ($in['perms'] ?? []) as $p) {
            $p = (string) $p;
            if (!isset(EventPermissions::MENU[$p])) return ['ok' => false, 'error' => '“' . mb_substr($p, 0, 40) . '” is not a permission this platform can enforce.'];
            if (!in_array($p, $out, true)) $out[] = $p;
        }
        // Menu order, so the same ticks always store the same way.
        $order = array_keys(EventPermissions::MENU);
        usort($out, static fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
        return ['ok' => true, 'perms' => $out];
    }

    /** @return array{ok:bool,minor?:int,error?:string} the money ceiling: required with `money.approve`, never above the lead's limit */
    public static function ceilingIn(array $in, array $perms): array
    {
        if (!in_array('money.approve', $perms, true)) return ['ok' => true, 'minor' => 0];
        $k = Plan::kobo((string) ($in['ceiling'] ?? ''));
        if ($k === null || $k <= 0) return ['ok' => false, 'error' => 'Say the most one line may be approved for under this role, in naira.'];
        if ($k > Plan::limitMinor()) return ['ok' => false, 'error' => 'That is above the lead’s own limit of ' . Plan::naira(Plan::limitMinor()) . '. A role can never approve more than a lead.'];
        return ['ok' => true, 'minor' => $k];
    }

    /** @return array<string,mixed> */
    /** A lead's own medium change, recorded as decided by them. */
    private static function byLead(array $me): array
    {
        return ['decided_kind' => 'lead', 'decided_id' => $me['id'], 'decided_name' => mb_substr($me['name'], 0, 190) ?: null,
                'decided_at' => date('Y-m-d H:i:s'), 'reason' => 'A lead’s own medium change', 'updated_at' => date('Y-m-d H:i:s')];
    }

    /** What makes a role large, in words: "approve spend and send messages". @param list<string> $perms */
    public static function riskWords(array $perms): string
    {
        $w = [];
        foreach ($perms as $p) if ((EventPermissions::MENU[$p]['risk'] ?? '') === 'high') $w[] = mb_strtolower((string) EventPermissions::MENU[$p]['label']);
        return $w ? implode(' and ', $w) : 'do high-risk things';
    }

    private static function proposer(array $me): array
    {
        return ['proposed_kind' => Plan::ACTOR, 'proposed_id' => $me['id'], 'proposed_name' => mb_substr($me['name'], 0, 190) ?: null, 'created_at' => date('Y-m-d H:i:s')];
    }

    /**
     * Turn an approved person into access, through the one door roles use (RoleInvites). A
     * person who already holds a role grant here keeps that one grant — the membership is what
     * opens the new role's pages — and an invitation already waiting is reused.
     */
    private static function giveAccess(object $m, object $role, array $by): array
    {
        $cap = $role->kind === 'volunteer' ? 'vol.event_role' : 'event.role';
        $eventId = (int) $m->event_id;
        $event = Plan::event($eventId);
        if (!$event) return ['ok' => false, 'error' => 'That event is not there.'];
        $who = ['kind' => $by['kind'] === 'admin' ? 'admin' : 'member', 'id' => $by['id'] ?: null, 'name' => (string) $by['name']];
        $u = DB::table('gates_users')->whereRaw('LOWER(email) = ?', [strtolower((string) $m->email)])->first();
        if ($u) {
            $g = DB::table('gates_grants')->where('actor_kind', Plan::ACTOR)->where('actor_id', $u->id)->where('capability', $cap)->where('scope_kind', 'event')
                ->where('scope_id', $eventId)->where(static fn ($w) => Grants::live($w))->orderByDesc('expires_at')->first();
            if ($g) {
                RoleInvites::tell((string) $m->email, (string) ($u->name ?? ''), $cap, $event, (string) $g->expires_at, 'given', $who, (string) $role->name);
                return ['ok' => true, 'granted' => true, 'grant_id' => (int) $g->id];
            }
        }
        $inv = DB::table('gates_role_invites')->where('email', strtolower((string) $m->email))->where('capability', $cap)->where('scope_kind', 'event')
            ->where('scope_id', $eventId)->where('status', 'pending')->first();
        if ($inv) return ['ok' => true, 'invite_id' => (int) $inv->id];
        $r = RoleInvites::offer((string) $m->email, $cap, $eventId, $m->until_at ? substr((string) $m->until_at, 0, 10) : '', $who,
            'Event team role: ' . $role->name, (string) $role->name);
        if (!$r['ok']) return $r;
        return !empty($r['granted']) ? ['ok' => true, 'granted' => true, 'grant_id' => (int) $r['id']] : ['ok' => true, 'invite_id' => (int) $r['id']];
    }

    /** After a membership ends: its grant ends when no other approved membership stands on it; an invitation still waiting is withdrawn. */
    private static function release(object $m, ?int $adminId): void
    {
        if ($m->grant_id) {
            $others = DB::table('gates_event_team_members')->where('grant_id', $m->grant_id)->where('status', 'approved')->where('id', '!=', $m->id)->exists();
            if (!$others) Grants::revoke((int) $m->grant_id, $adminId);
        }
        if ($m->invite_id) {
            $inv = DB::table('gates_role_invites')->where('id', $m->invite_id)->first();
            $others = DB::table('gates_event_team_members')->where('invite_id', $m->invite_id)->where('status', 'approved')->where('id', '!=', $m->id)->exists();
            if ($inv && !$others) {
                if ($inv->status === 'pending') RoleInvites::cancel((int) $inv->id, (int) $m->event_id);
                elseif ($inv->grant_id) Grants::revoke((int) $inv->grant_id, $adminId);
            }
        }
    }

    /** Email every owner but the proposer. Returns the sentence the lead is shown. */
    private static function tellOwners(int $eventId, string $what, array $me): string
    {
        $owners = array_values(array_filter(self::owners($eventId), static fn ($o) => (int) $o->actor_id !== $me['id']));
        if ($owners === []) return 'This event has no other owner yet, so the platform’s staff decide it.';
        $event = Plan::event($eventId);
        $sent = 0;
        foreach ($owners as $o) {
            $sent += self::mail($o->email, $o->name, ['place' => (string) ($event->title ?? 'your event'), 'what' => $what, 'state' => 'proposed', 'by' => $me['name'], 'reason' => '',
                'action_url' => rtrim(SiteUrl::base(), '/') . EventPages::url($eventId, EventPermissions::TEAM_PAGE, 'decide'), 'action_label' => 'Decide it']) ? 1 : 0;
        }
        return $sent > 0 ? 'The owner has been emailed.' : 'The owner will see it in Team & roles; the email could not be sent.';
    }

    private static function tellProposer(object $row, string $subject, string $word, string $reason, array $by): void
    {
        if ($row->proposed_kind !== Plan::ACTOR || !$row->proposed_id) return;
        if ((int) $row->proposed_id === (int) ($by['id'] ?? 0)) return;   // their own decision: nothing to tell them
        $u = DB::table('gates_users')->where('id', (int) $row->proposed_id)->first(['name', 'email']);
        if (!$u || !$u->email) return;
        $what = match ($subject) {
            'team' => 'The team ' . $row->name,
            'role' => 'The role ' . $row->name,
            default => $row->email . ' as ' . (string) (DB::table('gates_event_roles')->where('id', (int) $row->role_id)->value('name') ?? 'a role'),
        };
        $event = Plan::event((int) $row->event_id);
        self::mail((string) $u->email, (string) $u->name, ['place' => (string) ($event->title ?? 'your event'), 'what' => $what, 'state' => $word, 'by' => (string) $by['name'],
            'reason' => $reason, 'action_url' => rtrim(SiteUrl::base(), '/') . EventPages::url((int) $row->event_id, EventPermissions::TEAM_PAGE), 'action_label' => 'Open Team & roles']);
    }

    private static function mail(string $to, string $name, array $vars): bool
    {
        try {
            $first = trim((string) strtok(trim($name), ' ')) ?: 'there';
            // `success`, which is what sendBranded returns. This read `ok` — a key the mailer never
            // sets — so every proposal told its lead "the email could not be sent" while it had been.
            return !empty(Emails::send(OtpService::boot(), $to, 'event-team', ['first_name' => $first] + $vars)['success']);
        } catch (\Throwable) {
            return false;
        }
    }
}
