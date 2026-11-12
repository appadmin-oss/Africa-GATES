<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Grants;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT A PERSON MAY DO AT ONE EVENT — the one registry (owner, 3 Nov 2026: "custom role
 * permissions are picked from a FIXED MENU of permissions the code can actually enforce —
 * nothing invented").
 *
 * Each MENU entry names the pages and tabs it opens (`views`) and the console writes it allows
 * (`acts`, the `do` of LeadController::act). Everything else is DERIVED from it — the verb →
 * permission map, the rail and tabs a role sees, which buttons and forms are drawn, the
 * owner's tick boxes, the assistant's allowed vocabulary — so a permission cannot exist on a
 * screen without existing in the check, or the other way round (CLAUDE.md: one registry).
 *
 * Who holds what, read live on every request:
 *
 *   · A LEAD (`event.lead`) holds every MENU permission, plus the lead-only acts: recruiting
 *     and ending volunteers, proposing teams and roles. Their spend ceiling is the platform's.
 *   · An OWNER (`event.own`) SEES every page and decides: approves, changes or refuses what is
 *     proposed, and ends roles. An owner runs nothing — approving and operating are separate
 *     (somebody who is both a lead and an owner still cannot approve their own proposal).
 *   · A CUSTOM ROLE (`event.role` / `vol.event_role`) holds exactly the ticks of every APPROVED
 *     role they are an APPROVED member of, in an approved team. Nothing else — and never an
 *     act that gives anybody access: no MENU entry opens one, by construction.
 *
 * The write paths ask Plan::may(), which asks here, on the ROW's event — so a crafted post by
 * a role without the tick writes nothing, whatever the page drew.
 */
final class EventPermissions
{
    public const RISK = ['low' => 'Low risk', 'medium' => 'Medium risk', 'high' => 'High risk'];

    /**
     * The menu. `views`: page => tabs ('' = the page itself). `acts`: the console writes.
     * `ceiling`: the permission carries a money ceiling the owner sets.
     */
    public const MENU = [
        'plan.view'          => ['label' => 'See the plan', 'risk' => 'low',
                                 'help' => 'Work, timeline, risks and decisions, read only.',
                                 'views' => ['plan' => ['work', 'timeline', 'risks', 'decisions']], 'acts' => []],
        'plan.edit'          => ['label' => 'Run the plan', 'risk' => 'low',
                                 'help' => 'Add and move work, give it to somebody on the team, add and close risks.',
                                 'views' => ['plan' => ['work', 'timeline', 'risks', 'decisions'], 'people' => ['team']],
                                 'acts' => ['work_add', 'work_status', 'work_owner', 'risk_add', 'risk_close', 'work_decide', 'work_return',
                                            'goal_create', 'goal_describe', 'goal_change', 'assume_set', 'gate_sign', 'change_decide', 'rule_mode', 'rule_clear', 'digest_hour', 'contact_assign', 'rules_run']],
        'goals.view'         => ['label' => 'See the goals', 'risk' => 'low',
                                 'help' => 'Goals and gates against plan, and the numbers they rest on, read only.',
                                 'views' => ['goals' => ['progress', 'numbers']], 'acts' => []],
        'pipeline.work'      => ['label' => 'Work the pipeline', 'risk' => 'low',
                                 'help' => 'See the prospects, add them, move your own through the stages and rate their reach privately.',
                                 'views' => ['goals' => ['pipeline']], 'acts' => ['contact_add', 'contact_import', 'contact_stage', 'contact_rate']],
        'decisions.record'   => ['label' => 'Record decisions', 'risk' => 'medium',
                                 'help' => 'Write down what the team agreed, and reverse it with a reason.',
                                 'views' => ['plan' => ['decisions']], 'acts' => ['decision_add', 'decision_reverse']],
        'guests.manage'      => ['label' => 'Guests and seating', 'risk' => 'medium',
                                 'help' => 'See the guests of honour and the invitations, and seat people at tables.',
                                 'views' => ['people' => ['nominees', 'seating', 'invites']], 'acts' => ['table_save', 'seat', 'seat_free']],
        'volunteers.checkin' => ['label' => 'Check volunteers in', 'risk' => 'medium',
                                 'help' => 'See who is on the team, check volunteers in and out, and confirm their time on site.',
                                 'views' => ['people' => ['team', 'volunteers']], 'acts' => ['attend_in', 'attend_out', 'attend_confirm']],
        'volunteers.tasks'   => ['label' => 'Set volunteer tasks', 'risk' => 'low',
                                 'help' => 'Post work for this event’s volunteers, close it, and accept it as done with the hours it took.',
                                 'views' => ['people' => ['tasks']], 'acts' => ['vtask_add', 'vtask_close', 'vtask_accept']],
        'sponsors.manage'    => ['label' => 'Sponsors and contacts', 'risk' => 'medium',
                                 'help' => 'Add sponsors and move them through the stages.',
                                 'views' => ['crm' => ['sponsors', 'contacts']], 'acts' => ['sponsor_add', 'sponsor_stage']],
        'messages.send'      => ['label' => 'Send letters and messages', 'risk' => 'high',
                                 'help' => 'Send the invitation letters, and send a message an administrator has approved, to many people at once.',
                                 'views' => ['crm' => ['letters', 'messages']], 'acts' => ['letters_send', 'message_send']],
        'money.view'         => ['label' => 'See the money', 'risk' => 'medium',
                                 'help' => 'The budget, what is waiting for approval, vendors and income, read only.',
                                 'views' => ['money' => ['budget', 'approvals', 'vendors', 'income']], 'acts' => []],
        'money.plan'         => ['label' => 'Plan spend', 'risk' => 'high',
                                 'help' => 'Add budget lines, record what is committed and paid, and ask finance for a line.',
                                 'views' => ['money' => ['budget', 'approvals', 'vendors', 'income']], 'acts' => ['line_add', 'line_spend', 'line_ask']],
        'money.approve'      => ['label' => 'Approve spend', 'risk' => 'high', 'ceiling' => true,
                                 'help' => 'Approve a budget line up to the ceiling the owner sets, never above the lead’s own limit.',
                                 'views' => ['money' => ['budget', 'approvals']], 'acts' => ['line_approve']],
        'show.run'           => ['label' => 'Run the show', 'risk' => 'low',
                                 'help' => 'Write the running order and mark each cue done on the night.',
                                 'views' => ['night' => ['show']], 'acts' => ['cue_add', 'cue_done', 'cue_undo', 'cue_remove']],
        'safety.manage'      => ['label' => 'Safety checks', 'risk' => 'low',
                                 'help' => 'List what must be true before the doors open, and sign each one off.',
                                 'views' => ['night' => ['safety']], 'acts' => ['check_add', 'check_done', 'check_undo']],
        'door.manage'        => ['label' => 'Door and scanning links', 'risk' => 'high',
                                 'help' => 'See the door’s headcount, and make or turn off the links stewards scan tickets with.',
                                 'views' => ['night' => ['door'], 'settings' => ['devices']], 'acts' => ['door_issue', 'door_revoke']],
        'reports.view'       => ['label' => 'Reports', 'risk' => 'low',
                                 'help' => 'The status for the committee, the post-event report, the audit trail, growth and the award.',
                                 'views' => ['reports' => ['status', 'post', 'audit'], 'growth' => [''], 'award' => ['']], 'acts' => []],
        'requests.make'      => ['label' => 'Ask the organisers', 'risk' => 'medium',
                                 'help' => 'Make requests about this event to the platform’s staff, who decide them.',
                                 'views' => ['requests' => ['']], 'acts' => ['request', 'request_act', 'request_remind']],
        'settings.edit'      => ['label' => 'Budget and series', 'risk' => 'high',
                                 'help' => 'Change the event’s budget and the series its editions share.',
                                 'views' => ['settings' => ['general', 'limits']], 'acts' => ['event_settings']],
    ];

    /** Acts only a LEAD has: each gives or removes somebody's access, or proposes it. Never on the menu. */
    public const LEAD_ACTS = ['vol_invite', 'vol_invite_cancel', 'vol_end', 'team_propose', 'role_propose', 'team_suggest', 'member_propose', 'member_end', 'proposal_withdraw', 'decide',
                              // The written report (EventReport): a lead writes and submits it.
                              'report_save', 'report_submit'];
    /**
     * Acts ANYBODY on the event's team has (EventPlan\TeamLoop): report where things stand,
     * finish their own work with its proof, suggest work for a lead to decide. Each writer
     * checks the row again — finishing is only ever your own work, or a lead's call.
     */
    public const TEAM_ACTS = ['report_add', 'work_done', 'work_propose', 'work_change'];
    /** Acts only an OWNER has. */
    public const OWNER_ACTS = ['decide', 'member_end', 'role_archive',
                               // …and an owner signs it off or sends it back (never their own).
                               'report_decide'];
    /** What a team's own lead may do beyond their role's ticks: propose somebody for THEIR team (an owner still decides). */
    public const TEAM_LEAD_ACTS = ['member_propose'];

    /** The page an owner and a lead decide team and role questions on. Not on the menu: it is where access is given. */
    public const TEAM_PAGE = 'roles';

    /**
     * Combinations that put two duties in one pair of hands, said to the owner when they decide
     * (and re-checked whenever the ticks change). Deterministic: the assistant may add to these,
     * never replace them.
     */
    public const RISKY = [
        [['money.plan', 'money.approve'], 'Adds spend and approves it: nobody else sees a line before it is agreed. Split them between two roles if you can.'],
        [['money.approve', 'sponsors.manage'], 'Approves spend and handles the sponsors who may be paid or paying: money and the relationship in one place.'],
        [['messages.send', 'guests.manage'], 'Sees every guest and can write to all of them at once.'],
        [['door.manage', 'guests.manage'], 'Decides who scans tickets in and who is on the guest list.'],
        [['settings.edit', 'money.approve'], 'Can raise the budget and approve spend against it.'],
    ];

    // ── who holds what ──────────────────────────────────────────────────────────────────

    /**
     * Everything one member holds at one event, read live.
     *
     * @return array{lead:bool, owner:bool, perms:list<string>, roles:list<object>, teamLeadOf:list<int>, ceiling:int, any:bool}
     */
    public static function standing(int $userId, int $eventId): array
    {
        $out = ['lead' => false, 'owner' => false, 'finance' => false, 'perms' => [], 'roles' => [], 'teamLeadOf' => [], 'ceiling' => 0, 'any' => false];
        if ($userId <= 0 || $eventId <= 0 || !Grants::ready()) return $out;
        $out['lead'] = Grants::allows(Plan::ACTOR, $userId, 'event.lead', 'event', $eventId);
        $out['owner'] = Grants::allows(Plan::ACTOR, $userId, 'event.own', 'event', $eventId);
        $out['finance'] = Grants::allows(Plan::ACTOR, $userId, 'event.finance', 'event', $eventId);
        if ($out['lead']) {
            $out['perms'] = array_keys(self::MENU);
            $out['ceiling'] = Plan::limitMinor();
        }
        foreach (self::rolesHeld($userId, $eventId) as $r) {
            $out['roles'][] = $r;
            if ($r->team_role === 'lead' && $r->team_id) $out['teamLeadOf'][] = (int) $r->team_id;
            if ($out['lead']) continue;
            foreach (self::ticks($r->perms_json) as $p) {
                if (!in_array($p, $out['perms'], true)) $out['perms'][] = $p;
                if ($p === 'money.approve') $out['ceiling'] = max($out['ceiling'], min((int) $r->spend_ceiling_minor, Plan::limitMinor()));
            }
        }
        // The finance admin: the money pages, approving spend up to the finance limit, the
        // goals and the reports — and never money.plan, so who asks and who approves stay two.
        if ($out['finance']) {
            foreach (self::FINANCE_PERMS as $p) if (!in_array($p, $out['perms'], true)) $out['perms'][] = $p;
            $out['ceiling'] = max($out['ceiling'], self::financeCeiling());
        }
        $out['any'] = $out['lead'] || $out['owner'] || $out['finance'] || $out['roles'] !== [];
        return $out;
    }

    /** May this member do what this permission allows at this event, now? */
    public static function can(int $userId, int $eventId, string $perm): bool
    {
        if (!isset(self::MENU[$perm])) return false;
        return in_array($perm, self::standing($userId, $eventId)['perms'], true);
    }

    /** The most one budget line may be approved for by this member here, in kobo (0 = none). */
    /** What an event's finance admin may do (Grants 'event.finance'). */
    public const FINANCE_PERMS = ['money.view', 'money.approve', 'goals.view', 'reports.view'];
    /** The most one line a finance admin approves, in kobo; the setting `event_finance_limit_naira`, 0 or unset = no limit. */
    public static function financeCeiling(): int
    {
        try {
            $n = (int) \Illuminate\Database\Capsule\Manager::table('gates_settings')->where('key_name', 'event_finance_limit_naira')->value('value');
        } catch (\Throwable) {
            $n = 0;
        }
        return $n > 0 ? $n * 100 : PHP_INT_MAX;
    }

    public static function ceiling(int $userId, int $eventId): int
    {
        return self::standing($userId, $eventId)['ceiling'];
    }

    /** The permission a console write needs, or null for an act that is not on the menu. */
    public static function permFor(string $do): ?string
    {
        foreach (self::MENU as $k => $m) if (in_array($do, $m['acts'], true)) return $k;
        return null;
    }

    /** May somebody in this standing press this button? (Drawing only; every writer checks again.) */
    public static function allowsAct(array $st, string $do): bool
    {
        if ($st['any'] && in_array($do, self::TEAM_ACTS, true)) return true;
        // The finance admin decides the event's spend requests, signs its money gates and decides
        // its money changes; each writer checks that the row is money and theirs.
        if (($st['finance'] ?? false) && in_array($do, ['request_act', 'gate_sign', 'change_decide'], true)) return true;
        if ($st['lead'] && in_array($do, self::LEAD_ACTS, true)) return true;
        if ($st['owner'] && in_array($do, self::OWNER_ACTS, true)) return true;
        if ($st['teamLeadOf'] && in_array($do, self::TEAM_LEAD_ACTS, true)) return true;
        $p = self::permFor($do);
        return $p !== null && in_array($p, $st['perms'], true);
    }

    /** May somebody in this standing open this page and tab? Today is everybody's; the team page is a lead's and an owner's. */
    public static function allowsView(array $st, string $page, string $tab = ''): bool
    {
        if (!$st['any']) return false;
        if ($page === 'today') return true;
        if ($st['lead'] || $st['owner']) return true;
        if ($page === self::TEAM_PAGE) return false;
        // The finance admin decides money changes, so Rules > Changes opens for them — that tab only.
        if (($st['finance'] ?? false) && $page === 'rules') return in_array($tab, ['', 'changes'], true);
        foreach ($st['perms'] as $p) {
            $tabs = self::MENU[$p]['views'][$page] ?? null;
            if ($tabs !== null && ($tab === '' || in_array($tab, $tabs, true) || $tabs === [''])) return true;
        }
        return false;
    }

    /** The tabs of a page this standing may open, in the page's own order. @param array<string,string> $tabs */
    public static function tabsFor(array $st, string $page, array $tabs): array
    {
        return array_filter($tabs, static fn ($t) => self::allowsView($st, $page, (string) $t), ARRAY_FILTER_USE_KEY);
    }

    /** @return list<string> a stored tick list, kept to the menu */
    public static function ticks(?string $json): array
    {
        $a = json_decode((string) $json, true);
        return is_array($a) ? array_values(array_unique(array_filter(array_map('strval', $a), static fn ($p) => isset(self::MENU[$p])))) : [];
    }

    /** @return list<string> the warnings for one set of ticks */
    public static function risks(array $perms, string $kind = 'staff'): array
    {
        $out = [];
        foreach (self::RISKY as [$pair, $why]) if (!array_diff($pair, $perms)) $out[] = $why;
        if ($kind === 'volunteer') {
            $high = array_values(array_filter($perms, static fn ($p) => (self::MENU[$p]['risk'] ?? '') === 'high'));
            if ($high) $out[] = 'A volunteer role with ' . implode(', ', array_map(static fn ($p) => strtolower(self::MENU[$p]['label']), $high)) . ': volunteers change often, so check this is meant.';
        }
        return $out;
    }

    /**
     * The pages a role's portal leads with: what the owner set, else the role's permissions in
     * menu order. Only pages the ticks actually open.
     *
     * @return list<string>
     */
    public static function leadWith(array $perms, ?string $stored = null): array
    {
        $open = [];
        foreach ($perms as $p) foreach (array_keys(self::MENU[$p]['views'] ?? []) as $page) $open[$page] = true;
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $stored))) as $page) if (isset($open[$page]) && !in_array($page, $out, true)) $out[] = $page;
        foreach (array_keys($open) as $page) if (!in_array($page, $out, true)) $out[] = $page;
        return $out;
    }

    /**
     * Draw only what this standing may do: a form, a row's button or form, a header action,
     * a link to a page they cannot open — each is dropped rather than shown and refused.
     *
     * @param list<array<string,mixed>> $secs
     * @return list<array<string,mixed>>
     */
    public static function filter(array $secs, array $st, int $eventId): array
    {
        $out = [];
        foreach ($secs as $s) {
            if (($s['k'] ?? '') === 'form' && isset($s['do']) && !self::allowsAct($st, (string) $s['do'])) continue;
            if (($s['k'] ?? '') === 'decide' && !self::allowsAct($st, 'request_act')) continue;
            if (isset($s['cta']) && !self::actionOk($s['cta'], $st, $eventId)) unset($s['cta']);
            if (isset($s['acts'])) $s['acts'] = array_values(array_filter($s['acts'], static fn ($a) => self::actionOk($a, $st, $eventId)));
            if (isset($s['link']) && !self::actionOk($s['link'], $st, $eventId)) unset($s['link']);
            if (($s['k'] ?? '') === 'rows') {
                foreach ($s['items'] as &$r) {
                    if (!empty($r['cta']) && !self::actionOk($r['cta'], $st, $eventId)) $r['cta'] = null;
                    if (!empty($r['form']) && !self::allowsAct($st, (string) ($r['form']['do'] ?? ''))) $r['form'] = null;
                    if (!empty($r['href']) && !self::actionOk(['href' => $r['href']], $st, $eventId)) unset($r['href']);
                }
                unset($r);
            }
            $out[] = $s;
        }
        return $out;
    }

    /** @param array<string,mixed> $a a button that posts, or a link */
    public static function actionOk(array $a, array $st, int $eventId): bool
    {
        if (isset($a['post'])) return self::allowsAct($st, (string) ($a['post']['do'] ?? ''));
        $href = (string) ($a['href'] ?? '');
        if (preg_match('~^/lead/event/' . $eventId . '(?:/([a-z]+))?(?:\?(.*))?$~', $href, $m)) {
            parse_str((string) ($m[2] ?? ''), $q);
            $page = ($m[1] ?? '') !== '' ? $m[1] : 'today';
            $first = (string) (array_key_first(EventPages::TABS[$page] ?? []) ?? '');
            if (isset($q['ask'])) return self::allowsAct($st, 'line_ask');
            return self::allowsView($st, $page, (string) ($q['tab'] ?? $first));
        }
        // A request composer is the requests permission; anything else at /lead is the lead's root.
        if (str_starts_with($href, '/lead/requests')) return $st['lead'] || self::allowsAct($st, 'request');
        if (str_starts_with($href, '/lead') && !str_starts_with($href, '/lead/event/')) return $st['lead'];
        return true;
    }

    // ── reading the roles ───────────────────────────────────────────────────────────────

    /**
     * The approved roles this member holds at this event through a live role grant, each with
     * its membership's team_role and team_id. A role, team or membership not approved — or a
     * grant that ended — opens nothing.
     *
     * @return list<object>
     */
    public static function rolesHeld(int $userId, int $eventId): array
    {
        if (!SchemaHas::table('gates_event_team_members')) return [];
        $now = date('Y-m-d H:i:s');
        $grants = DB::table('gates_grants')->where('actor_kind', Plan::ACTOR)->where('actor_id', $userId)->whereIn('capability', ['event.role', 'vol.event_role'])
            ->where('scope_kind', 'event')->where('scope_id', $eventId)->whereNull('revoked_at')->where('expires_at', '>', $now)
            ->where(static fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))->pluck('id')->map(static fn ($i) => (int) $i)->all();
        if ($grants === []) return [];
        $invites = DB::table('gates_role_invites')->whereIn('grant_id', $grants)->pluck('id')->map(static fn ($i) => (int) $i)->all();
        $q = DB::table('gates_event_team_members as m')->join('gates_event_roles as r', 'r.id', '=', 'm.role_id')
            ->leftJoin('gates_event_teams as t', 't.id', '=', 'm.team_id')
            ->where('m.event_id', $eventId)->where('m.status', 'approved')->where('r.status', 'approved')
            ->where(static fn ($w) => $w->whereNull('m.team_id')->orWhere('t.status', 'approved'))
            ->where(static function ($w) use ($grants, $invites) {
                $w->whereIn('m.grant_id', $grants);
                if ($invites !== []) $w->orWhereIn('m.invite_id', $invites);
            });
        return $q->orderBy('m.id')->get(['r.*', 'm.team_role', 'm.team_id as member_team', 'm.id as member_id'])
            ->map(static function ($r) { $r->team_id = $r->member_team ?? $r->team_id; return $r; })->all();
    }

    /**
     * Every event this member may open in the console, soonest first: led, owned, or a role.
     *
     * @return list<object>
     */
    public static function events(int $userId): array
    {
        if ($userId <= 0 || !Grants::ready() || !SchemaHas::table('gates_site_events')) return [];
        $ids = [];
        foreach (Plan::leadEvents($userId) as $e) $ids[(int) $e->id] = true;
        foreach (Grants::forActor(Plan::ACTOR, $userId) as $g) {
            if (!in_array($g->capability, ['event.own', 'event.finance', 'event.role', 'vol.event_role'], true) || $g->scope_kind !== 'event' || !$g->scope_id) continue;
            $ids[(int) $g->scope_id] = true;
        }
        if ($ids === []) return [];
        return DB::table('gates_site_events')->whereIn('id', array_keys($ids))->orderBy('event_date')->get()->all();
    }

    /** What to call this member's place at this event, for the switcher. */
    public static function label(array $st): string
    {
        if ($st['lead']) return 'Event lead';
        if ($st['owner']) return 'Event owner';
        if ($st['finance'] && $st['roles'] === []) return 'Finance admin';
        $names = array_values(array_unique(array_map(static fn ($r) => (string) $r->name, $st['roles'])));
        return $names ? implode(', ', $names) : 'Team';
    }
}
