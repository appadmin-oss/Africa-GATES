<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Volunteer\Workspace;

/**
 * Event › Team & roles, and the overview a custom role opens on. Drawn for a lead (who
 * proposes) and an owner (who decides); a custom role never sees this page — it is where
 * access is given (EventPermissions::TEAM_PAGE).
 *
 *   · structure — the teams, the roles with their ticks and risk, and who is in them.
 *   · decide    — what waits for an owner, with the fixed risk flags and the assistant's notes,
 *                 one form each. A proposal the viewer made says who decides it instead.
 *   · suggest   — "Suggest a team": the assistant's (or the templates') structure, as proposal
 *                 forms already filled in. Nothing happens until the lead presses one.
 */
final class TeamPages
{
    public const TABS = ['structure' => 'Teams and roles', 'decide' => 'Waiting for a decision', 'suggest' => 'Suggest a team'];

    /** Where a suggestion waits between the press and the page, per event. */
    public const SESSION = 'lead_team_suggest';

    public static function page(array $me, object $e, string $tab, array $st): array
    {
        $secs = match ($tab) {
            'decide' => self::decide($me, $e, $st),
            'suggest' => self::suggest($e, $st),
            default => self::structure($me, $e, $st),
        };
        return ['title' => 'Team & roles', 'secs' => $secs];
    }

    // ── structure ───────────────────────────────────────────────────────────────────────

    private static function structure(array $me, object $e, array $st): array
    {
        $id = (int) $e->id;
        $teams = Teams::teams($id);
        $roles = Teams::roles($id);
        $members = Teams::members($id);
        $byTeam = [];
        foreach ($teams as $t) $byTeam[(int) $t->id] = $t;
        $secs = [];
        $owners = Teams::owners($id);
        $secs[] = ['k' => 'note', 'tone' => $owners ? 'mute' : 'warn', 'text' => $owners
            ? 'Owner: ' . implode(', ', array_map(static fn ($o) => $o->name, $owners)) . '. Leads can create teams and assign routine roles directly. Roles with high-risk permissions need a different owner’s approval before access starts.'
            : 'Leads can create teams and assign routine roles directly. This event has no owner yet, so platform staff review roles with high-risk permissions. An administrator can name an owner.'];

        if ($teams) {
            $secs[] = ['k' => 'rows', 'h' => 'Teams', 'items' => array_map(static fn ($t) => [
                't' => (string) $t->name, 'ini' => Workspace::initials((string) $t->name),
                'sub' => implode(' · ', array_filter([(string) ($t->purpose ?? ''), $t->parent_id && isset($byTeam[(int) $t->parent_id]) ? 'inside ' . $byTeam[(int) $t->parent_id]->name : '',
                          $t->reason && $t->status !== 'proposed' ? 'reason: ' . $t->reason : ''])),
                'chip' => [Teams::STATUSES[$t->status] ?? $t->status, Teams::TONE[$t->status] ?? 'mute']], $teams)];
        }
        if ($roles) {
            $secs[] = ['k' => 'rows', 'h' => 'Roles', 'items' => array_map(static fn ($r) => [
                't' => (string) $r->name, 'sub' => self::roleLine($r, $byTeam),
                'chip' => [Teams::STATUSES[$r->status] ?? $r->status, Teams::TONE[$r->status] ?? 'mute'],
                'form' => $st['owner'] && $r->status === 'approved' ? ['id' => 'ra' . $r->id, 'do' => 'role_archive', 'hidden' => ['event' => $id, 'role' => $r->id], 'submit' => 'Archive',
                    'fields' => [['name' => 'reason', 'label' => 'Why it is archived', 'required' => true]]] : null], $roles)];
        }
        if ($members) {
            $roleName = [];
            foreach ($roles as $r) $roleName[(int) $r->id] = (string) $r->name;
            $secs[] = ['k' => 'rows', 'h' => 'People', 'items' => array_map(static fn ($m) => [
                't' => Teams::who($m), 'sub' => implode(' · ', array_filter([($roleName[(int) $m->role_id] ?? 'A role') . ($m->team_role === 'lead' ? ', team lead' : ''),
                    $m->team_id && isset($byTeam[(int) $m->team_id]) ? $byTeam[(int) $m->team_id]->name : '', $m->until_at ? 'until ' . Workspace::day((string) $m->until_at) : '',
                    $m->reason && $m->status !== 'proposed' ? 'reason: ' . $m->reason : ''])),
                'chip' => [Teams::STATUSES[$m->status] ?? $m->status, Teams::TONE[$m->status] ?? 'mute'],
                'form' => ($st['owner'] || $st['lead']) && in_array($m->status, ['approved'], true) ? ['id' => 'me' . $m->id, 'do' => 'member_end', 'hidden' => ['event' => $id, 'member' => $m->id], 'submit' => 'End',
                    'fields' => [['name' => 'reason', 'label' => 'Why it ends', 'required' => true]]] : null], $members)];
        }
        if (!$teams && !$roles && $st['lead']) {
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No teams or roles yet. Create them below, or let Suggest a team draft a structure for you to change.',
                       'cta' => ['label' => 'Suggest a team', 'href' => EventPages::url($id, EventPermissions::TEAM_PAGE, 'suggest')]];
        }
        $live = array_filter($teams, static fn ($t) => in_array($t->status, ['proposed', 'approved'], true));
        $teamOpts = ['' => 'No team'];
        foreach ($live as $t) $teamOpts[(int) $t->id] = (string) $t->name;
        // The page shows the structure — teams, roles, people — and the three things you can do
        // to it are folded cards beneath, each opened when wanted. Open, they were two long forms
        // (one with seventeen permissions) stacked above the people they change.
        if ($st['lead']) {
            $secs[] = self::teamForm($id, [], $teamOpts) + ['fold' => true, 'anchor' => 'create-team', 'foldHint' => 'A committee or a crew', 'open' => !$teams && !$roles];
            $secs[] = self::roleForm($id, [], $teamOpts, 'rp') + ['fold' => true, 'anchor' => 'define-role', 'foldHint' => 'What a person may see and do'];
        }
        $roleOpts = [];
        foreach ($roles as $r) if (in_array($r->status, ['proposed', 'approved'], true)) $roleOpts[(int) $r->id] = $r->name . ($r->status === 'proposed' ? ' (waiting)' : '');
        if ($roleOpts && ($st['lead'] || $st['teamLeadOf'])) {
            // A team's own lead proposes only into the teams they lead.
            $mine = $st['lead'] ? $teamOpts : array_intersect_key($teamOpts, array_flip($st['teamLeadOf']));
            $secs[] = ['k' => 'form', 'h' => 'Put somebody in a role', 'fold' => true, 'anchor' => 'assign', 'foldHint' => 'By email address', 'do' => 'member_propose', 'hidden' => ['event' => $id], 'submit' => $st['lead'] ? 'Assign role' : 'Propose assignment', 'groups' => [
                ['label' => 'Who', 'help' => 'By email: they need not have an account yet. A lead can assign an approved role directly; other proposals wait for approval. Once approved, we email them. Access ends on the date below.',
                 'fields' => [['name' => 'email', 'label' => 'Their email', 'type' => 'email', 'required' => true, 'id' => 'mp_email'],
                              ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => $roleOpts, 'id' => 'mp_role'],
                              ['name' => 'team', 'label' => 'Team', 'type' => 'select', 'options' => $mine, 'id' => 'mp_team'],
                              ['name' => 'team_role', 'label' => 'In the team', 'type' => 'select', 'options' => $st['lead'] ? Teams::TEAM_ROLES : ['member' => 'Member'], 'id' => 'mp_tr']]],
                ['label' => 'Until', 'fields' => [['name' => 'until', 'label' => 'Ends', 'type' => 'date', 'id' => 'mp_until',
                    'value' => date('Y-m-d', (int) strtotime(((string) ($e->end_date ?? '') ?: (string) $e->event_date)) + 86400)]]],
            ]];
        }
        return $secs;
    }

    // ── decide ──────────────────────────────────────────────────────────────────────────

    private static function decide(array $me, object $e, array $st): array
    {
        $id = (int) $e->id;
        $secs = [];
        $roleName = [];
        foreach (Teams::roles($id) as $r) $roleName[(int) $r->id] = (string) $r->name;
        $any = false;
        foreach (['team' => Teams::teams($id), 'role' => Teams::roles($id), 'member' => Teams::members($id)] as $kind => $rows) {
            foreach ($rows as $r) {
                if ($r->status !== 'proposed') continue;
                $any = true;
                $mine = $r->proposed_kind === Plan::ACTOR && (int) $r->proposed_id === $me['id'];
                $title = match ($kind) { 'team' => 'Team: ' . $r->name, 'role' => 'Role: ' . $r->name, default => (string) $r->email . ' as ' . ($roleName[(int) $r->role_id] ?? 'a role') };
                $by = 'Proposed by ' . ((string) ($r->proposed_name ?? '') ?: 'a lead') . ', ' . Workspace::day((string) $r->created_at);
                $items = [['t' => $title, 'sub' => $by . ($kind === 'role' ? ' · ' . self::roleLine($r, []) : ($kind === 'team' && $r->purpose ? ' · ' . $r->purpose : '')),
                           'chip' => ['Waiting', 'warn'],
                           'cta' => $mine ? ['label' => 'Withdraw', 'post' => ['do' => 'proposal_withdraw', 'event' => $id, 'subject' => $kind, 'id' => $r->id]] : null]];
                $tier = $kind === 'role' ? Tiers::forRole((array) $r->perms) : 'medium';
                $items[0]['sub'] .= ' · ' . Tiers::LABELS[$tier] . ' change: ' . mb_strtolower(Tiers::APPROVER[$tier]);
                $secs[] = ['k' => 'rows', 'h' => $title, 'items' => $items];
                if ($kind === 'role') {
                    foreach (EventPermissions::risks($r->perms, (string) $r->kind) as $w) $secs[] = ['k' => 'note', 'tone' => 'warn', 'text' => 'Flagged: ' . $w];
                    foreach ($r->notes as $n) $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'The assistant suggests checking: ' . $n];
                }
                // A lead decides the medium ones (Tiers); a large one is an owner's.
                if (!$st['owner'] && !($st['lead'] && $tier === 'medium')) continue;
                if ($mine) {
                    $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'You proposed this, so another owner or the platform’s staff decides it.'];
                    continue;
                }
                $groups = [];
                if ($kind === 'role') {
                    $groups[] = ['label' => 'What it may do', 'help' => 'The lead’s ticks. Change any of them before you approve: the role gets exactly what is ticked here.',
                                 'fields' => [['name' => 'perms', 'label' => 'Permissions', 'type' => 'checks', 'options' => self::menuOptions(), 'value' => $r->perms],
                                              ['name' => 'ceiling', 'label' => 'Most one line may be approved for (₦), with Approve spend', 'type' => 'text', 'id' => 'dc_ceiling' . $r->id,
                                               'value' => (int) $r->spend_ceiling_minor > 0 ? (string) intdiv((int) $r->spend_ceiling_minor, 100) : '',
                                               'placeholder' => 'up to ' . Plan::naira(Plan::limitMinor())]]];
                }
                $groups[] = ['label' => 'Your decision', 'help' => 'The reason is recorded and the lead sees it.',
                             'fields' => [['name' => 'verdict', 'label' => 'Decision', 'type' => 'radio', 'options' => ['approve' => 'Approve', 'refuse' => 'Refuse'], 'required' => true],
                                          ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'id' => 'dc_reason' . $kind . $r->id]]];
                $secs[] = ['k' => 'form', 'do' => 'decide', 'hidden' => ['event' => $id, 'subject' => $kind, 'id' => $r->id], 'submit' => 'Record the decision', 'groups' => $groups];
            }
        }
        if (!$any) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing is waiting for a decision.'];
        elseif (!$st['owner']) array_unshift($secs, ['k' => 'note', 'tone' => 'mute', 'text' => 'You decide the medium changes here. Roles that can move money, message many people, run the door or change settings wait for an owner. You can withdraw your own.']);
        return $secs;
    }

    // ── suggest ─────────────────────────────────────────────────────────────────────────

    private static function suggest(object $e, array $st): array
    {
        $id = (int) $e->id;
        $s = $_SESSION[self::SESSION][$id] ?? null;
        $secs = [['k' => 'form', 'h' => 'Suggest a team', 'do' => 'team_suggest', 'hidden' => ['event' => $id], 'submit' => $s ? 'Suggest again' : 'Suggest a team', 'groups' => [
            ['label' => 'From this event', 'help' => 'Drafts teams and roles from the event’s title, size and plan. It is only a suggestion: review and save what you keep. Routine changes apply directly; high-risk roles need owner approval.', 'fields' => []],
        ]]];
        if (!is_array($s)) return $secs;
        $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => ($s['source'] === 'assistant' ? 'Suggested by the assistant. ' : 'Suggested from the platform’s templates (the assistant was not available). ')
            . ($s['note'] !== '' ? $s['note'] . ' ' : '') . 'Nothing here is applied until you propose it.'];
        $teamOpts = ['' => 'No team'];
        $byName = [];
        foreach (Teams::teams($id) as $t) if (in_array($t->status, ['proposed', 'approved'], true)) { $teamOpts[(int) $t->id] = (string) $t->name; $byName[mb_strtolower((string) $t->name)] = (int) $t->id; }
        $keyName = [];
        foreach ($s['teams'] as $i => $t) {
            $keyName[$t['key']] = $t['name'];
            if (isset($byName[mb_strtolower($t['name'])])) continue;   // already proposed
            $secs[] = self::teamForm($id, $t, [], 'st' . $i);
        }
        foreach ($s['roles'] as $i => $r) {
            foreach (EventPermissions::risks($r['perms'], $r['kind']) as $w) $secs[] = ['k' => 'note', 'tone' => 'warn', 'text' => $r['name'] . ': ' . $w];
            $r['team_id'] = $byName[mb_strtolower($keyName[$r['team']] ?? '')] ?? '';
            $secs[] = self::roleForm($id, $r, $teamOpts, 'sr' . $i);
        }
        return $secs;
    }

    // ── a role's own overview ───────────────────────────────────────────────────────────

    /**
     * Today, for somebody whose place here is a custom role: their roles, then the pages their
     * ticks open in the order the role leads with — the portal tailored to the job — and the
     * facts of the event they may see. Nothing they cannot open is drawn.
     */
    public static function roleToday(object $e, array $st): array
    {
        $id = (int) $e->id;
        $perms = $st['perms'];
        $facts = [['When', date('D j M, H:i', (int) strtotime((string) $e->event_date))]];
        if (in_array('plan.view', $perms, true) && ($r = Plan::readiness($id))) $facts[] = ['Ready', $r['pct'] . '% · ' . $r['done'] . ' of ' . $r['all'] . ' done'];
        if (array_intersect(['money.view', 'money.plan', 'money.approve'], $perms) && ($b = Plan::budgetWords($e)) !== null) $facts[] = ['Budget', $b];
        if (in_array('money.view', $perms, true) && Goals::ready()) {
            $earned = Goals::voteIncome($id)['total'] * 100 + Scope::ticketIncomeMinor($id);
            $facts[] = ['Earned from votes and tickets', Plan::naira($earned)];
        }
        if (in_array('money.approve', $perms, true)) $facts[] = ['You approve', $st['ceiling'] >= PHP_INT_MAX ? 'spend with no per-line limit' : 'up to ' . Plan::naira($st['ceiling']) . ' a line'];
        $roleNames = array_values(array_unique(array_map(static fn ($r) => (string) $r->name . ($r->team_role === 'lead' ? ' (team lead)' : ''), $st['roles'])));
        $secs = [['k' => 'hero', 'ini' => Workspace::initials((string) $e->title), 'tone' => 'ok', 'chips' => [[LeadPages::daysShort((string) $e->event_date), 'mute']],
                  't' => (string) $e->title, 'sub' => 'Your role: ' . implode(', ', $roleNames ?: ['Event owner']), 'facts' => $facts, 'acts' => []]];
        // The pages, in the order the role leads with; then any other page the ticks open.
        $order = [];
        foreach ($st['roles'] as $r) foreach (EventPermissions::leadWith(EventPermissions::ticks((string) $r->perms_json), (string) $r->lead_with) as $p) $order[$p] = true;
        $label = [];
        foreach (EventPages::NAV as [, $pages]) foreach ($pages as $k => [$l]) $label[$k] = $l;
        $cards = [];
        foreach (array_keys($order) as $p) {
            if (!isset($label[$p]) || !EventPermissions::allowsView($st, $p)) continue;
            $tabs = array_values(EventPermissions::tabsFor($st, $p, EventPages::TABS[$p] ?? []));
            $cards[] = ['t' => $label[$p], 'ini' => Workspace::initials($label[$p]), 'meta' => $tabs ? implode(' · ', $tabs) : '', 'sub' => EventPages::PURPOSE[$p] ?? '',
                        'href' => EventPages::url($id, $p, (string) (array_key_first(EventPermissions::tabsFor($st, $p, EventPages::TABS[$p] ?? [])) ?? ''))];
        }
        if ($cards) $secs[] = ['k' => 'cards', 'h' => 'Where your role works', 'items' => $cards];
        if (in_array('plan.view', $perms, true) && ($next = array_slice(Plan::work([$id], true), 0, 4))) {
            $secs[] = ['k' => 'rows', 'h' => 'Next on the plan', 'items' => array_map(static fn ($w) => ['t' => (string) $w->title, 'chip' => LeadPages::dueChip($w),
                'sub' => Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null) ?: 'Nobody yet', 'href' => EventPages::url($id, 'plan')], $next)];
        }
        if (in_array('show.run', $perms, true) && ($now = Scope::nowCue(Scope::show($id)))) {
            $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'Now: ' . $now->title . '.', 'cta' => ['label' => 'Run of show', 'href' => EventPages::url($id, 'night')]];
        }
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'This console shows only what your role may do. The event’s owner decides what a role includes; ask the lead if you need more.'];
        return ['title' => (string) $e->title, 'noHead' => true, 'secs' => $secs];
    }

    // ── pieces ──────────────────────────────────────────────────────────────────────────

    /** @return array<string,string> the menu as tick labels, each with its risk */
    public static function menuOptions(): array
    {
        $o = [];
        foreach (EventPermissions::MENU as $k => $m) $o[$k] = $m['label'] . ' — ' . $m['help'] . ' (' . strtolower(EventPermissions::RISK[$m['risk']]) . ')';
        return $o;
    }

    private static function roleLine(object $r, array $byTeam): string
    {
        $perms = $r->perms ?? EventPermissions::ticks((string) $r->perms_json);
        $parts = [];
        if ($r->team_id && isset($byTeam[(int) $r->team_id])) $parts[] = $byTeam[(int) $r->team_id]->name;
        if ($r->kind === 'volunteer') $parts[] = 'volunteer role';
        $parts[] = implode(', ', array_map(static fn ($p) => EventPermissions::MENU[$p]['label'], $perms)) ?: 'nothing ticked';
        if (in_array('money.approve', $perms, true)) $parts[] = 'approves up to ' . Plan::naira((int) $r->spend_ceiling_minor);
        if (isset($r->asked) && $r->asked != $perms && $r->status !== 'proposed') $parts[] = 'the owner changed the ticks';
        if ($r->reason && $r->status !== 'proposed') $parts[] = 'reason: ' . $r->reason;
        return implode(' · ', $parts);
    }

    private static function teamForm(int $id, array $t, array $teamOpts, string $p = 'tp'): array
    {
        $fields = [['name' => 'name', 'label' => 'Team or committee', 'type' => 'text', 'required' => true, 'value' => $t['name'] ?? '', 'placeholder' => 'Guests and hospitality', 'id' => $p . '_name'],
                   ['name' => 'purpose', 'label' => 'What it is for', 'type' => 'text', 'value' => $t['purpose'] ?? '', 'id' => $p . '_purpose']];
        if (count($teamOpts) > 1) $fields[] = ['name' => 'parent', 'label' => 'Inside', 'type' => 'select', 'options' => $teamOpts, 'id' => $p . '_parent'];
        return ['k' => 'form', 'h' => $t ? 'Create the team ' . $t['name'] : 'Create a team', 'do' => 'team_propose', 'hidden' => ['event' => $id], 'submit' => 'Create team',
                'groups' => [['label' => 'The team', 'help' => 'The team is created immediately. Give each person a role to set what they can access.', 'fields' => $fields]]];
    }

    private static function roleForm(int $id, array $r, array $teamOpts, string $p): array
    {
        $pages = [];
        foreach (EventPages::NAV as [, $ps]) foreach ($ps as $k => [$l]) if ($k !== 'today') $pages[$k] = $l;
        unset($pages[EventPermissions::TEAM_PAGE]);
        return ['k' => 'form', 'h' => $r ? 'Propose the role ' . $r['name'] : 'Define a role', 'do' => 'role_propose', 'hidden' => ['event' => $id], 'submit' => 'Save role', 'route' => 'Routine roles are ready immediately. High-risk permissions need a different owner’s approval before the role can be used.', 'groups' => [
            ['label' => 'The role', 'fields' => [
                ['name' => 'name', 'label' => 'Role', 'type' => 'text', 'required' => true, 'value' => $r['name'] ?? '', 'placeholder' => 'Hospitality lead', 'id' => $p . '_name'],
                ['name' => 'description', 'label' => 'What they do', 'type' => 'text', 'value' => $r['description'] ?? '', 'id' => $p . '_desc'],
                ['name' => 'team', 'label' => 'Team', 'type' => 'select', 'options' => $teamOpts, 'value' => (string) ($r['team_id'] ?? ''), 'id' => $p . '_team'],
                ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => Teams::KINDS, 'value' => $r['kind'] ?? 'staff', 'id' => $p . '_kind']]],
            ['label' => 'What it may do', 'help' => 'Only from this list: each is something the console enforces. A role can never give anybody access, and never approves more than a lead may.',
             'fields' => [['name' => 'perms', 'label' => 'Permissions', 'type' => 'checks', 'options' => self::menuOptions(), 'value' => $r['perms'] ?? []],
                          ['name' => 'ceiling', 'label' => 'Most one line may be approved for (₦), with Approve spend', 'type' => 'text', 'id' => $p . '_ceiling', 'placeholder' => 'up to ' . Plan::naira(Plan::limitMinor())]]],
            ['label' => 'Their console leads with', 'help' => 'The pages their overview shows first. Left empty, it follows the ticks.',
             'fields' => [['name' => 'lead_with', 'label' => 'Pages', 'type' => 'checks', 'options' => $pages, 'value' => $r['lead_with'] ?? []]]],
        ]];
    }
}
