<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\EventArrivals;
use AfricaGates\Services\EventScanPass;
use AfricaGates\Services\EventTicketService;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ONE EVENT, AS ITS LEAD RUNS IT: the event scope of the v7 handoff (`nav-admin.json` scope
 * `event`, README §5), in the handoff's order — Today; Plan (Work · Timeline · Risks ·
 * Decisions) and Award; People, Relationships, Money and The night; Growth and Reports;
 * Requests and Event settings.
 *
 * Every section is read from a row: Plan and Scope for what the lead writes, and what the
 * platform already holds for the rest — the shifts, the guests of honour, the door's headcount
 * (EventArrivals), its scanning links (EventScanPass), the tickets. Where something has no
 * source, the tab says so in one sentence and offers the one action that starts it (§8);
 * nothing is drawn as a zero that was never measured.
 *
 * What a lead cannot change here says why and where it changes: the date and the venue are a
 * request, the spending limit is the platform's, the linked awards are their own setup's.
 */
final class EventPages
{
    /** The event scope's rail, verbatim from `nav-admin.json`. */
    public const NAV = [
        [null, ['today' => ['Today', 'home']]],
        ['Plan', ['plan' => ['Plan', 'list'], 'award' => ['Award', 'trophy']]],
        ['Run the event', ['people' => ['People', 'users'], 'crm' => ['Relationships', 'msg'], 'money' => ['Money', 'card'], 'night' => ['The night', 'night']]],
        ['Learn', ['growth' => ['Growth', 'grow'], 'reports' => ['Reports', 'rep']]],
        ['Admin', ['requests' => ['Requests', 'list'], 'settings' => ['Event settings', 'gear']]],
    ];

    public const TABS = [
        'plan' => ['work' => 'Work', 'timeline' => 'Timeline', 'risks' => 'Risks', 'decisions' => 'Decisions'],
        'people' => ['team' => 'Team', 'volunteers' => 'Volunteers', 'nominees' => 'Nominees', 'seating' => 'Seating', 'invites' => 'Invitations'],
        'crm' => ['sponsors' => 'Sponsors', 'contacts' => 'Contacts', 'letters' => 'Letters', 'messages' => 'Messages'],
        'money' => ['budget' => 'Budget', 'approvals' => 'Approvals', 'vendors' => 'Vendors', 'income' => 'Income'],
        'night' => ['show' => 'Run of show', 'door' => 'Door', 'safety' => 'Safety'],
        'reports' => ['status' => 'Status', 'post' => 'Post-event', 'audit' => 'Audit'],
        'settings' => ['general' => 'General', 'members' => 'Members', 'limits' => 'Limits', 'devices' => 'Devices'],
    ];

    /** Purpose lines, verbatim from `nav-admin.json` where it gives one. */
    public const PURPOSE = [
        'today' => 'What blocks the night, and how ready it is.',
        'plan' => 'Work, timeline, risks and decisions, each with an owner and a due time.',
        'award' => 'The awards this event is the ceremony for. You can read them here; they change in their own setup.',
        'people' => 'Team, volunteers, finalists, seating and invitations.',
        'crm' => 'Sponsors, contacts, letters and messages for this event.',
        'money' => 'Budget, approvals, vendors and income, from the one ledger.',
        'night' => 'Run of show, door and safety for the night.',
        'growth' => 'This edition against the last, on the same day before the night.',
        'reports' => 'Status for the committee, the post-event report and the audit trail.',
        'requests' => 'Requests about this event, from you and your team.',
        'settings' => 'Date, venue, members, limits and the devices used at the door.',
    ];

    /** Pages of the first lead build, and where each lives now (a retired address is not a dead one). */
    public const ALIASES = ['work' => ['plan', 'work'], 'risks' => ['plan', 'risks']];

    public static function url(int $id, string $page = 'today', string $tab = ''): string
    {
        $first = array_key_first(self::TABS[$page] ?? []);
        return LeadPages::BASE . '/event/' . $id . ($page === 'today' ? '' : '/' . $page) . ($tab !== '' && $tab !== $first ? '?tab=' . $tab : '');
    }

    public static function nav(int $eventId, string $current, array $counts): array
    {
        $groups = [];
        foreach (self::NAV as [$label, $pages]) {
            $items = [];
            foreach ($pages as $k => [$l, $icon]) {
                $items[] = ['key' => $k, 'label' => $l, 'icon' => $icon, 'href' => self::url($eventId, $k), 'current' => $k === $current,
                            'drill' => false, 'count' => $counts[$k] ?? null];
            }
            $groups[] = ['label' => $label, 'items' => $items];
        }
        return $groups;
    }

    public static function tabs(int $eventId, string $page, string $current): array
    {
        $out = [];
        foreach (self::TABS[$page] ?? [] as $k => $label) {
            $out[] = ['key' => $k, 'label' => $label, 'href' => self::url($eventId, $page, $k), 'current' => $k === $current, 'count' => null];
        }
        return $out;
    }

    /** The rail's counts, never 0: late work, spend to decide, open safety checks before the night. */
    public static function counts(array $me, int $eventId): array
    {
        $c = [];
        $late = count(array_filter(Plan::work([$eventId], true), static fn ($w) => Plan::isLate($w)));
        if ($late > 0) $c['plan'] = ['n' => $late, 'tone' => 'bad'];
        $waiting = count(array_filter(Plan::lines([$eventId]), static fn ($l) => $l->status === 'proposed'));
        if ($waiting > 0) $c['money'] = ['n' => $waiting, 'tone' => 'warn'];
        $open = count(array_filter(Scope::checks($eventId), static fn ($x) => $x->done_at === null));
        if ($open > 0) $c['night'] = ['n' => $open, 'tone' => 'warn'];
        $info = count(array_filter(self::eventRequests($eventId), static fn ($r) => $r->status === 'needs_info' && $r->from_kind === 'admin' && (int) $r->from_id === $me['id']));
        if ($info > 0) $c['requests'] = ['n' => $info, 'tone' => 'warn'];
        return $c;
    }

    /** The lead's other events, for the switcher. Switching keeps the page: every event has them all. */
    public static function siblings(array $events, int $current, string $page, string $tab): array
    {
        $out = [];
        foreach ($events as $e) {
            $out[] = ['name' => (string) $e->title, 'sub' => Workspace::day((string) $e->event_date), 'ini' => Workspace::initials((string) $e->title),
                      'href' => self::url((int) $e->id, $page, $tab), 'current' => (int) $e->id === $current];
        }
        return $out;
    }

    public static function page(array $me, object $e, string $page, string $tab, array $q): array
    {
        $id = (int) $e->id;
        $owners = ['' => 'Nobody yet'];
        foreach (Plan::team($id) as $p) $owners[$p['key']] = $p['name'] !== '' ? $p['name'] : $p['role'];
        return match ($page) {
            'plan' => match ($tab) {
                'timeline' => self::timeline($e), 'risks' => self::risks($e, $owners), 'decisions' => self::decisions($e),
                default => self::work($e, $owners, (string) ($q['owner'] ?? '')),
            },
            'award' => self::award($e),
            'people' => match ($tab) {
                'volunteers' => self::volunteers($e), 'nominees' => self::invitees($e, 'nominee'), 'seating' => self::seating($e),
                'invites' => self::invitees($e, null), default => self::team($e),
            },
            'crm' => match ($tab) {
                'contacts' => self::contacts($e), 'letters' => self::letters($e), 'messages' => self::messages($e), default => self::sponsors($e, $owners),
            },
            'money' => match ($tab) {
                'approvals' => self::approvals($me, $e), 'vendors' => self::vendors($e), 'income' => self::income($e), default => self::budget($e, (int) ($q['ask'] ?? 0)),
            },
            'night' => match ($tab) { 'door' => self::door($e), 'safety' => self::safety($e, $owners), default => self::show($e, $owners) },
            'growth' => LeadPages::analytics([$e]),
            'reports' => match ($tab) { 'post' => self::post($e), 'audit' => self::audit($e), default => self::status($e) },
            'requests' => self::requests($me, $e),
            'settings' => match ($tab) {
                'members' => self::members($e), 'limits' => self::limits($e), 'devices' => self::devices($e), default => self::general($e),
            },
            default => self::today($e),
        };
    }

    // ── Today ───────────────────────────────────────────────────────────────────────────

    private static function today(object $e): array
    {
        $id = (int) $e->id;
        $r = Plan::readiness($id);
        $blockers = Plan::blockers([$id]);
        $risks = Plan::risks([$id]);
        $fill = Plan::shiftFill($id);
        $chips = [[LeadPages::daysShort((string) $e->event_date), strtotime((string) $e->event_date) - time() < 14 * 86400 ? 'warn' : 'mute']];
        if ($blockers) $chips[] = [count($blockers) === 1 ? '1 blocker' : count($blockers) . ' blockers', 'bad'];
        $facts = [
            ['Ready', $r ? $r['pct'] . '% · ' . $r['done'] . ' of ' . $r['all'] . ' done' : 'No plan yet'],
            ['Budget', Plan::budgetWords($e) ?? 'No spend planned'],
            ['Tickets', LeadPages::ticketWords($e) ?? 'None sold yet'],
            ['Open risks', $risks ? (string) count($risks) : 'None'],
        ];
        if ($fill) $facts[] = ['Volunteers', $fill['filled'] . ' of ' . $fill['slots'] . ' shifts filled'];
        $secs = [['k' => 'hero', 'ini' => Workspace::initials((string) $e->title), 'tone' => 'ok', 'chips' => $chips, 't' => (string) $e->title,
                  'sub' => self::where($e) . ' · ' . date('D j M, H:i', (int) strtotime((string) $e->event_date)),
                  'facts' => $facts, 'acts' => [['label' => 'Add work', 'href' => self::url($id, 'plan'), 'primary' => true], ['label' => 'Run of show', 'href' => self::url($id, 'night')]]]];
        if ($blockers) $secs[] = ['k' => 'rows', 'h' => 'Blocking the night', 'box' => true, 'items' => array_map(static fn ($w) => LeadPages::blockerRow($w, [$e]), $blockers)];
        $cues = Scope::show($id);
        if (date('Y-m-d') === date('Y-m-d', (int) strtotime((string) $e->event_date)) && ($now = Scope::nowCue($cues))) {
            $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'Now: ' . $now->title . ', from ' . date('H:i', (int) strtotime((string) $now->starts_at)) . '.',
                       'cta' => ['label' => 'Run of show', 'href' => self::url($id, 'night')]];
        }
        $next = array_slice(Plan::work([$id], true), 0, 4);
        if ($next) {
            $secs[] = ['k' => 'rows', 'h' => 'Next on the plan', 'items' => array_map(static fn ($w) => ['t' => (string) $w->title, 'chip' => LeadPages::dueChip($w),
                'sub' => (Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null) ?: 'Nobody yet'), 'href' => self::url($id, 'plan')], $next),
                'link' => ['label' => 'The whole plan', 'href' => self::url($id, 'plan')]];
        } elseif (!$r) {
            $secs[] = ['k' => 'steps', 'h' => 'Start the plan', 'items' => [
                ['t' => 'Add the work before the night', 'sub' => 'Each piece with an owner, a due date and its hours.', 'st' => 'now'],
                ['t' => 'Write the run of show', 'sub' => 'Every cue on the night, with who runs it.', 'st' => 'next'],
                ['t' => 'Plan the spend', 'sub' => 'You approve up to ' . Plan::naira(Plan::limitMinor()) . ' a line; finance decides above it.', 'st' => 'next'],
            ]];
        }
        $agenda = [];
        foreach (array_slice(LeadPages::deadlineItems([$e]), 0, 5) as $d) {
            $agenda[] = ['time' => $d['at'] ? date('D j', (int) strtotime((string) $d['at'])) : '', 't' => $d['row']['t'], 'd' => $d['row']['sub'],
                         'st' => $d['group'] === 'late' ? 'late' : 'next'];
        }
        return ['title' => (string) $e->title, 'noHead' => true, 'secs' => $secs, 'side' => $agenda ? [['k' => 'agenda', 'h' => 'Key dates', 'items' => $agenda]] : []];
    }

    // ── Plan ────────────────────────────────────────────────────────────────────────────

    private static function work(object $e, array $owners, string $ownerFilter): array
    {
        $id = (int) $e->id;
        $all = Plan::work([$id]);
        if ($ownerFilter !== '') $all = array_values(array_filter($all, static fn ($w) => ($w->owner_kind . ':' . $w->owner_id) === $ownerFilter));
        $lanes = ['late' => [], 'todo' => [], 'doing' => [], 'done' => []];
        foreach ($all as $w) $lanes[Plan::isLate($w) ? 'late' : $w->status][] = $w;
        $secs = [];
        if ($ownerFilter !== '') {
            $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'Showing ' . (Plan::name(...LeadPages::split($ownerFilter)) ?: 'one person') . '’s work. Give some of it to somebody else to lighten their week.',
                       'cta' => ['label' => 'Show everybody’s', 'href' => self::url($id, 'plan')]];
        }
        $next = ['todo' => ['Start', 'doing'], 'doing' => ['Done', 'done'], 'done' => ['Reopen', 'todo']];
        foreach (['late' => 'Late', 'todo' => 'Not started', 'doing' => 'In progress', 'done' => 'Done'] as $lane => $h) {
            $items = [];
            foreach (array_slice($lanes[$lane], 0, $lane === 'done' ? 20 : 200) as $w) {
                $owner = Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null);
                [$label, $to] = $next[$w->status];
                $items[] = ['t' => (string) $w->title, 'dot' => $lane === 'late' ? 'bad' : null,
                            'sub' => implode(' · ', array_filter([$owner !== '' ? $owner : 'Nobody yet', $w->due_at ? 'due ' . Workspace::day((string) $w->due_at) : 'no date',
                                     $w->est_hours ? LeadPages::hours((float) $w->est_hours) : '', (int) $w->blocks_night === 1 ? ($w->blocks_note ? $w->blocks_note . ' waits on it' : 'the night waits on it') : ''])),
                            'chip' => $lane === 'late' ? ['Late', 'bad'] : null,
                            'cta' => ['label' => $label, 'post' => ['do' => 'work_status', 'work' => $w->id, 'status' => $to], 'primary' => $to === 'done'],
                            'form' => $lane !== 'done' && count($owners) > 1 ? ['id' => 'w' . $w->id, 'do' => 'work_owner', 'hidden' => ['work' => $w->id], 'submit' => 'Give it',
                                'fields' => [['name' => 'owner', 'label' => 'Owner', 'type' => 'select', 'options' => $owners, 'value' => $w->owner_kind ? $w->owner_kind . ':' . $w->owner_id : '']]] : null];
            }
            if ($items) $secs[] = ['k' => 'rows', 'h' => $h, 'items' => $items, 'box' => $lane === 'late'];
        }
        if (!$all) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing is planned for this event yet. Add the first piece of work below; readiness is counted from it.'];
        $secs[] = ['k' => 'form', 'h' => 'Add work', 'do' => 'work_add', 'hidden' => ['event' => $id], 'submit' => 'Add it', 'groups' => [
            ['label' => 'What', 'fields' => [['name' => 'title', 'label' => 'What has to be done', 'type' => 'text', 'required' => true, 'placeholder' => 'Venue floor plan'],
                                              ['name' => 'detail', 'label' => 'Details', 'type' => 'textarea']]],
            ['label' => 'Who and when', 'help' => 'Work with nobody on it goes to the leads’ inboxes.',
             'fields' => [['name' => 'owner', 'label' => 'Owner', 'type' => 'select', 'options' => $owners],
                          ['name' => 'due_at', 'label' => 'Due', 'type' => 'date'],
                          ['name' => 'est_hours', 'label' => 'Hours it should take', 'type' => 'number', 'min' => 0, 'max' => 500, 'step' => '0.5']]],
            ['label' => 'Does the night wait on it?', 'help' => 'Late work the night waits on shows as blocking, at the top of every overview.',
             'fields' => [['name' => 'blocks_night', 'label' => 'The night waits on this', 'type' => 'switch'],
                          ['name' => 'blocks_note', 'label' => 'What waits on it', 'type' => 'text', 'placeholder' => 'the safety permit']]],
        ]];
        return ['title' => 'Plan', 'secs' => $secs];
    }

    /** Every dated thing on one line of time: work, cues and the night, done / late / now / next. */
    private static function timeline(object $e): array
    {
        $id = (int) $e->id;
        $items = [];
        $today = date('Y-m-d');
        foreach (Plan::work([$id]) as $w) {
            if ($w->due_at === null) continue;
            $st = $w->status === 'done' ? 'done' : (Plan::isLate($w) ? 'late' : (date('Y-m-d', (int) strtotime((string) $w->due_at)) === $today ? 'now' : 'next'));
            $items[] = ['at' => (string) $w->due_at, 'time' => date('D j M', (int) strtotime((string) $w->due_at)), 't' => (string) $w->title,
                        'd' => Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null) ?: 'Nobody yet', 'st' => $st];
        }
        foreach (Scope::decisions($id) as $d) {
            $items[] = ['at' => (string) $d->decided_at, 'time' => date('D j M', (int) strtotime((string) $d->decided_at)), 't' => 'Decided: ' . $d->title,
                        'd' => $d->reversed_at ? 'Reversed ' . Workspace::day((string) $d->reversed_at) : '', 'st' => 'done'];
        }
        $items[] = ['at' => (string) $e->event_date, 'time' => date('D j M', (int) strtotime((string) $e->event_date)), 't' => 'The night', 'd' => self::where($e) . ', ' . date('H:i', (int) strtotime((string) $e->event_date)),
                    'st' => strtotime((string) $e->event_date) < time() ? 'done' : 'next'];
        usort($items, static fn ($a, $b) => strcmp($a['at'], $b['at']));
        return ['title' => 'Plan', 'secs' => [['k' => 'agenda', 'h' => 'Timeline', 'items' => $items],
            ['k' => 'note', 'tone' => 'mute', 'text' => 'Work shows here once it has a due date. The night’s own running order is in The night › Run of show.']]];
    }

    private static function risks(object $e, array $owners): array
    {
        $secs = LeadPages::riskSections(Plan::risks([(int) $e->id]), [(int) $e->id => $e], false);
        if (!$secs) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No open risks here. Add one when something could stop the night.'];
        $secs[] = LeadPages::riskForm([], $owners, (int) $e->id);
        return ['title' => 'Plan', 'secs' => $secs];
    }

    private static function decisions(object $e): array
    {
        $rows = [];
        foreach (Scope::decisions((int) $e->id) as $d) {
            $by = Plan::name((string) $d->decided_kind, $d->decided_id !== null ? (int) $d->decided_id : null);
            $row = ['t' => (string) $d->title, 'sub' => implode(' · ', array_filter([(string) ($d->why ?? ''), ($by !== '' ? $by : 'A lead') . ', ' . Workspace::day((string) $d->decided_at)]))];
            if ($d->reversed_at) {
                $row['chip'] = ['Reversed', 'mute'];
                $row['sub'] .= ' · reversed ' . Workspace::day((string) $d->reversed_at) . ': ' . $d->reversed_why;
            } else {
                $row['chip'] = ['Stands', 'ok'];
                $row['form'] = ['id' => 'd' . $d->id, 'do' => 'decision_reverse', 'hidden' => ['decision' => $d->id], 'submit' => 'Reverse',
                                'fields' => [['name' => 'why', 'label' => 'Why it no longer stands', 'required' => true]]];
            }
            $rows[] = $row;
        }
        $secs = [$rows ? ['k' => 'rows', 'h' => 'Decisions', 'items' => $rows]
                       : ['k' => 'note', 'tone' => 'mute', 'text' => 'No decisions recorded yet. Write one down when the team agrees something it would otherwise argue about again.']];
        $secs[] = ['k' => 'form', 'h' => 'Record a decision', 'do' => 'decision_add', 'hidden' => ['event' => (int) $e->id], 'submit' => 'Record it', 'groups' => [
            ['label' => 'What was decided', 'help' => 'A decision is never edited. If it changes, it is reversed with a reason, and both stay on the record.',
             'fields' => [['name' => 'title', 'label' => 'The decision', 'type' => 'text', 'required' => true, 'placeholder' => 'Doors open at 17:00, not 16:30'],
                          ['name' => 'why', 'label' => 'Why', 'type' => 'textarea']]],
        ]];
        return ['title' => 'Plan', 'secs' => $secs];
    }

    // ── Award ───────────────────────────────────────────────────────────────────────────

    private static function award(object $e): array
    {
        $rows = [];
        foreach (Scope::awards((int) $e->id) as $a) {
            $rows[] = ['t' => (string) $a->title, 'ini' => Workspace::initials((string) $a->title),
                       'sub' => 'Read-only here. Its dates, categories and results change in the award’s own setup.',
                       'cta' => $a->slug ? ['label' => 'View', 'href' => '/awards/' . rawurlencode((string) $a->slug)] : null];
        }
        return ['title' => 'Award', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'This is the ceremony for', 'items' => $rows]
            : ['k' => 'note', 'tone' => 'mute', 'text' => 'This event is not the ceremony for an award. An administrator links awards to an event in Events.']]];
    }

    // ── People ──────────────────────────────────────────────────────────────────────────

    private static function team(object $e): array
    {
        $id = (int) $e->id;
        $cap = Plan::rule('lead_week_hours');
        $load = [];
        foreach (Plan::load([$id]) as $p) $load[$p['key']] = $p;
        $rows = [];
        foreach (Plan::team($id) as $p) {
            $h = $load[$p['key']]['hours'] ?? 0.0;
            $row = ['t' => $p['name'] !== '' ? $p['name'] : 'Someone', 'ini' => Workspace::initials($p['name']), 'sub' => $p['role'] . ($h > 0 ? ' · ' . LeadPages::hours($h) . ' due this week here' : '')];
            if ($h > 0 && $cap > 0) $row += ['pct' => (int) min(100, round($h * 100 / $cap)), 'barT' => LeadPages::hours($h) . ' of ' . $cap . 'h'];
            if ($h > $cap && $cap > 0) $row += ['chip' => ['Over capacity', 'bad'], 'cta' => ['label' => 'Rebalance', 'href' => self::url($id, 'plan') . '?owner=' . $p['key']]];
            $rows[] = $row;
        }
        return ['title' => 'People', 'secs' => [['k' => 'rows', 'h' => 'The team', 'items' => $rows],
            ['k' => 'note', 'tone' => 'mute', 'text' => 'The team is everybody holding a role on this event. Access for somebody new is a request.',
             'cta' => ['label' => 'Ask for access', 'href' => LeadPages::BASE . '/requests?new=access&event=' . $id]]]];
    }

    private static function volunteers(object $e): array
    {
        $id = (int) $e->id;
        $secs = [];
        $shifts = DB::table('gates_vol_shifts')->where('scope_kind', 'event')->where('scope_id', $id)->where('status', 'open')->orderBy('starts_at')->get()->all();
        $items = [];
        foreach ($shifts as $s) {
            $names = DB::table('gates_vol_signups as x')->join('gates_volunteers as v', 'v.id', '=', 'x.volunteer_id')->join('gates_users as u', 'u.id', '=', 'v.user_id')
                ->where('x.shift_id', $s->id)->whereIn('x.status', ['on', 'kept'])->orderBy('x.id')->get(['v.display_name', 'u.name'])->all();
            $n = count($names);
            $items[] = ['t' => (string) $s->title, 'sub' => Workspace::day((string) $s->starts_at) . ', ' . Workspace::span((string) $s->starts_at, (string) $s->ends_at)
                        . ($s->location ? ' · ' . $s->location : '') . ($n ? ' · ' . implode(', ', array_map(static fn ($x) => (string) ($x->display_name ?: $x->name), $names)) : ''),
                        'pct' => (int) round(min($n, (int) $s->slots) * 100 / max(1, (int) $s->slots)), 'barT' => min($n, (int) $s->slots) . ' of ' . $s->slots,
                        'chip' => $n >= (int) $s->slots ? ['Full', 'ok'] : [((int) $s->slots - $n) . ' to fill', 'warn']];
        }
        $secs[] = $items ? ['k' => 'rows', 'h' => 'Shifts', 'items' => $items]
                         : ['k' => 'note', 'tone' => 'mute', 'text' => 'No volunteer shifts are posted for this event yet. Post the first below; volunteers whose training and time fit see it in their workspace.'];
        $caps = ['' => 'No role at the door'];
        foreach (Grants::CAPABILITIES as $k => $l) if (str_starts_with($k, 'vol.') && $k !== 'vol.team_lead') $caps[$k] = $l;
        $day = date('Y-m-d', (int) strtotime((string) $e->event_date));
        $secs[] = ['k' => 'form', 'h' => 'Post a shift', 'do' => 'shift_add', 'hidden' => ['event' => $id], 'submit' => 'Post it', 'groups' => [
            ['label' => 'What and where', 'fields' => [['name' => 'title', 'label' => 'Shift', 'type' => 'text', 'required' => true, 'placeholder' => 'Door, Gate B'],
                                                         ['name' => 'location', 'label' => 'Where', 'type' => 'text', 'placeholder' => (string) ($e->venue ?? '')]]],
            ['label' => 'When', 'fields' => [['name' => 'starts_at', 'label' => 'Starts', 'type' => 'datetime-local', 'step' => '1', 'required' => true, 'value' => $day . 'T15:00:00'],
                                              ['name' => 'ends_at', 'label' => 'Ends', 'type' => 'datetime-local', 'step' => '1', 'required' => true, 'value' => $day . 'T21:00:00']]],
            ['label' => 'How many, and what it gives', 'help' => 'The role is given to each volunteer on the shift, and ends the day after it.',
             'fields' => [['name' => 'slots', 'label' => 'Volunteers needed', 'type' => 'number', 'min' => 1, 'max' => 500, 'value' => '4'],
                          ['name' => 'capability', 'label' => 'Role on the night', 'type' => 'select', 'options' => $caps]]],
        ]];
        return ['title' => 'People', 'secs' => $secs];
    }

    /** Guests of honour: nominees only, or everybody invited. */
    private static function invitees(object $e, ?string $audience): array
    {
        $rows = Scope::invites((int) $e->id, $audience);
        $sent = count(array_filter($rows, static fn ($r) => $r->sent_at !== null));
        $opened = count(array_filter($rows, static fn ($r) => $r->opened_at !== null));
        $in = count(array_filter($rows, static fn ($r) => (int) $r->scans > 0));
        $items = array_map(static fn ($r) => ['t' => (string) $r->name, 'sub' => ($r->audience === 'judge' ? 'Judge' : 'Nominee')
            . ((int) $r->guest_quota > 0 ? ' · may bring ' . $r->guest_quota : ''),
            'chip' => (int) $r->scans > 0 ? ['In the room', 'ok'] : ($r->opened_at ? ['Opened', 'info'] : ($r->sent_at ? ['Sent', 'mute'] : ['Not sent', 'warn']))], $rows);
        $secs = [];
        if ($rows) {
            $secs[] = ['k' => 'stats', 'items' => [['l' => 'Invited', 'v' => (string) count($rows), 's' => ''], ['l' => 'Letters sent', 'v' => (string) $sent, 's' => ''],
                                                   ['l' => 'Opened', 'v' => (string) $opened, 's' => ''], ['l' => 'At the door', 'v' => (string) $in, 's' => '']]];
            $secs[] = ['k' => 'rows', 'items' => $items];
        } else {
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => $audience === 'nominee'
                ? 'No nominees are invited to this event yet. Staff build the list from an award’s shortlist in Events › Invitations.'
                : 'Nobody is invited as a guest of honour yet. Staff send invitations from Events › Invitations.'];
        }
        return ['title' => 'People', 'secs' => $secs];
    }

    private static function seating(object $e): array
    {
        $id = (int) $e->id;
        $tables = Scope::tables($id);
        $seats = array_sum(array_map(static fn ($t) => (int) $t->seats, $tables));
        $placed = array_sum(array_map(static fn ($t) => count(Scope::guestList((string) $t->guests)), $tables));
        $secs = [];
        if ($tables) {
            $secs[] = ['k' => 'stats', 'items' => [['l' => 'Tables', 'v' => (string) count($tables), 's' => ''], ['l' => 'Seats', 'v' => (string) $seats, 's' => ''],
                                                   ['l' => 'Guests placed', 'v' => (string) $placed, 's' => $seats > 0 ? ($seats - $placed) . ' seats free' : '']]];
            $secs[] = ['k' => 'rows', 'items' => array_map(static function ($t) {
                $g = Scope::guestList((string) $t->guests);
                return ['t' => (string) $t->label, 'sub' => $g ? implode(', ', $g) : 'Nobody placed yet', 'pct' => (int) round(count($g) * 100 / max(1, (int) $t->seats)),
                        'barT' => count($g) . ' of ' . $t->seats,
                        'form' => ['id' => 't' . $t->id, 'do' => 'table_save', 'hidden' => ['table' => $t->id], 'submit' => 'Save',
                                   'fields' => [['name' => 'guests', 'label' => 'Guests, separated by commas', 'value' => implode(', ', $g)]]]];
            }, $tables)];
        } else {
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No seating plan yet. Add the tables, then put guests at them.'];
        }
        $secs[] = ['k' => 'form', 'h' => 'Add a table', 'do' => 'table_save', 'hidden' => ['event' => $id], 'submit' => 'Add it', 'groups' => [
            ['label' => 'The table', 'help' => 'More guests than seats is refused, and says by how many.',
             'fields' => [['name' => 'label', 'label' => 'Table', 'type' => 'text', 'required' => true, 'placeholder' => 'Table 1, finalists'],
                          ['name' => 'seats', 'label' => 'Seats', 'type' => 'number', 'min' => 1, 'max' => 500, 'value' => '10'],
                          ['name' => 'guests', 'label' => 'Guests, separated by commas', 'type' => 'text']]],
        ]];
        return ['title' => 'People', 'secs' => $secs];
    }

    // ── Relationships ───────────────────────────────────────────────────────────────────

    private static function sponsors(object $e, array $owners): array
    {
        $id = (int) $e->id;
        $rows = Scope::sponsors($id);
        $secs = [];
        if ($rows) {
            $sum = static fn (array $st) => array_sum(array_map(static fn ($s) => (int) $s->value_minor, array_filter($rows, static fn ($s) => in_array($s->stage, $st, true))));
            $secs[] = ['k' => 'stats', 'items' => [['l' => 'Agreed', 'v' => Plan::naira($sum(['agreed', 'paid'])), 's' => ''], ['l' => 'Paid', 'v' => Plan::naira($sum(['paid'])), 's' => ''],
                                                   ['l' => 'Being asked', 'v' => Plan::naira($sum(['asked'])), 's' => '']]];
            $tone = ['prospect' => 'mute', 'asked' => 'info', 'agreed' => 'warn', 'paid' => 'ok', 'declined' => 'bad'];
            $secs[] = ['k' => 'rows', 'h' => 'Sponsors', 'items' => array_map(static fn ($s) => ['t' => (string) $s->name, 'ini' => Workspace::initials((string) $s->name),
                'sub' => implode(' · ', array_filter([(int) $s->value_minor > 0 ? Plan::naira((int) $s->value_minor) : '', (string) ($s->benefits ?? ''),
                         Plan::name($s->owner_kind, $s->owner_id !== null ? (int) $s->owner_id : null)])),
                'chip' => [Scope::STAGES[$s->stage] ?? $s->stage, $tone[$s->stage] ?? 'mute'],
                'form' => ['id' => 's' . $s->id, 'do' => 'sponsor_stage', 'hidden' => ['sponsor' => $s->id], 'submit' => 'Move',
                           'fields' => [['name' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => Scope::STAGES, 'value' => (string) $s->stage]]]], $rows)];
        } else {
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No sponsors yet. Add the first, even as a prospect: the list is how nobody gets asked twice.'];
        }
        $secs[] = ['k' => 'form', 'h' => 'Add a sponsor', 'do' => 'sponsor_add', 'hidden' => ['event' => $id], 'submit' => 'Add the sponsor', 'groups' => [
            ['label' => 'Who and how much', 'fields' => [['name' => 'name', 'label' => 'Sponsor', 'type' => 'text', 'required' => true, 'placeholder' => 'Lagos Water Corporation'],
                ['name' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => Scope::STAGES, 'value' => 'prospect'],
                ['name' => 'value', 'label' => 'Value (₦)', 'type' => 'text', 'placeholder' => '1,500,000'],
                ['name' => 'benefits', 'label' => 'What they get', 'type' => 'text', 'placeholder' => 'Logo on the stage screen, 10 seats']]],
            ['label' => 'Their contact', 'help' => 'Shown on Contacts, to the event’s leads only.',
             'fields' => [['name' => 'contact_name', 'label' => 'Name', 'type' => 'text'], ['name' => 'contact_email', 'label' => 'Email', 'type' => 'email'],
                          ['name' => 'contact_phone', 'label' => 'Phone', 'type' => 'tel'], ['name' => 'owner', 'label' => 'Who holds the relationship', 'type' => 'select', 'options' => $owners]]],
        ]];
        return ['title' => 'Relationships', 'secs' => $secs];
    }

    private static function contacts(object $e): array
    {
        $rows = array_values(array_filter(Scope::sponsors((int) $e->id), static fn ($s) => $s->contact_name || $s->contact_email || $s->contact_phone));
        return ['title' => 'Relationships', 'secs' => [$rows
            ? ['k' => 'rows', 'h' => 'Sponsor contacts', 'items' => array_map(static fn ($s) => ['t' => (string) ($s->contact_name ?: $s->name),
                'sub' => implode(' · ', array_filter(['For ' . $s->name, (string) ($s->contact_email ?? ''), (string) ($s->contact_phone ?? '')]))], $rows)]
            : ['k' => 'note', 'tone' => 'mute', 'text' => 'No contacts yet. A sponsor’s contact, added on Sponsors, shows here.']]];
    }

    private static function letters(object $e): array
    {
        $rows = Scope::invites((int) $e->id);
        $sent = count(array_filter($rows, static fn ($r) => $r->sent_at !== null));
        $secs = [$rows
            ? ['k' => 'rows', 'h' => 'Invitation letters', 'items' => [
                ['t' => $sent . ' of ' . count($rows) . ' sent', 'sub' => 'Each guest of honour’s letter carries their own reference and door pass.',
                 'pct' => (int) round($sent * 100 / max(1, count($rows))), 'barT' => $sent . ' of ' . count($rows)]]]
            : ['k' => 'note', 'tone' => 'mute', 'text' => 'No invitation letters for this event yet.']];
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Staff send the letters from Events › Invitations, so every guest of honour gets one letter, once. This shows what went.'];
        return ['title' => 'Relationships', 'secs' => $secs];
    }

    private static function messages(object $e): array
    {
        $rows = array_values(array_filter(self::eventRequests((int) $e->id), static fn ($r) => $r->type === 'message'));
        $tone = ['waiting' => 'warn', 'needs_info' => 'info', 'approved' => 'ok', 'declined' => 'bad', 'withdrawn' => 'mute'];
        return ['title' => 'Relationships', 'acts' => [['label' => 'Ask to send a message', 'href' => LeadPages::BASE . '/requests?new=message&event=' . $e->id, 'primary' => true]],
            'secs' => [$rows
                ? ['k' => 'rows', 'h' => 'Big sends', 'items' => array_map(static fn ($r) => ['t' => (string) $r->title, 'sub' => (string) ($r->needed_by ? 'Send on ' . $r->needed_by : ''),
                    'chip' => [Requests::STATUSES[$r->status] ?? $r->status, $tone[$r->status] ?? 'mute']], $rows)]
                : ['k' => 'note', 'tone' => 'mute', 'text' => 'No messages to a big audience yet. A send to many people is a request, so an administrator checks it before it goes.']]];
    }

    // ── Money ───────────────────────────────────────────────────────────────────────────

    private static function budget(object $e, int $ask): array
    {
        $id = (int) $e->id;
        $limit = Plan::limitMinor();
        $lines = Plan::lines([$id]);
        $secs = [];
        $live = array_filter($lines, static fn ($l) => $l->status !== 'declined');
        if ($live) {
            $sum = static fn (string $col) => array_sum(array_map(static fn ($l) => (int) $l->{$col}, $live));
            $stats = [];
            if (isset($e->budget_minor) && $e->budget_minor) $stats[] = ['l' => 'Budget', 'v' => Plan::naira((int) $e->budget_minor), 's' => ''];
            $stats[] = ['l' => 'Forecast', 'v' => Plan::naira((int) Plan::forecastMinor($id)), 's' => (string) (Plan::budgetWords($e) ?? '')];
            if ($sum('committed_minor') > 0) $stats[] = ['l' => 'Committed', 'v' => Plan::naira($sum('committed_minor')), 's' => ''];
            if ($sum('paid_minor') > 0) $stats[] = ['l' => 'Paid', 'v' => Plan::naira($sum('paid_minor')), 's' => ''];
            $secs[] = ['k' => 'stats', 'items' => $stats];
        }
        $line = $ask > 0 ? current(array_filter($lines, static fn ($l) => (int) $l->id === $ask && in_array($l->status, ['proposed', 'declined'], true))) : false;
        if ($line) {
            $secs[] = ['k' => 'form', 'h' => 'Ask finance: ' . $line->title, 'do' => 'line_ask', 'hidden' => ['line' => $line->id], 'submit' => 'Send request',
                       'route' => 'Goes to finance, ' . (Requests::type('spend', 'lead')['usually'] ?? 'usually decided within a day') . '. Where: ' . $e->title . '.',
                       'groups' => [['label' => Plan::naira((int) $line->estimate_minor), 'help' => 'Over your limit of ' . Plan::naira($limit) . ', so finance decides.',
                                     'fields' => [['name' => 'why', 'label' => 'Why, and what happens if it waits', 'type' => 'textarea', 'required' => true],
                                                  ['name' => 'by', 'label' => 'Needed by', 'type' => 'text', 'placeholder' => 'Fri 30 Oct']]]]];
        }
        $items = [];
        $tone = ['proposed' => 'warn', 'approved' => 'ok', 'requested' => 'info', 'declined' => 'bad'];
        foreach ($lines as $l) {
            $figs = ['estimate ' . Plan::naira((int) $l->estimate_minor)];
            if ((int) $l->committed_minor > 0) $figs[] = 'committed ' . Plan::naira((int) $l->committed_minor);
            if ((int) $l->paid_minor > 0) $figs[] = 'paid ' . Plan::naira((int) $l->paid_minor);
            if (!empty($l->vendor)) array_unshift($figs, (string) $l->vendor);
            $row = ['t' => (string) $l->title, 'sub' => implode(' · ', $figs), 'chip' => [Plan::LINE_STATUSES[$l->status] ?? $l->status, $tone[$l->status] ?? 'mute']];
            if ($l->status === 'proposed' && (int) $l->estimate_minor <= $limit) {
                $row['cta'] = ['label' => 'Approve', 'post' => ['do' => 'line_approve', 'line' => $l->id], 'primary' => true];
            } elseif (in_array($l->status, ['proposed', 'declined'], true)) {
                $row['cta'] = ['label' => 'Ask finance', 'href' => self::url($id, 'money') . '?ask=' . $l->id];
            } elseif ($l->status === 'requested' && $l->request_id) {
                $row['cta'] = ['label' => 'Open request', 'href' => LeadPages::BASE . '/requests?r=' . $l->request_id];
            } elseif ($l->status === 'approved') {
                $row['form'] = ['id' => 'l' . $l->id, 'do' => 'line_spend', 'hidden' => ['line' => $l->id], 'submit' => 'Record', 'fields' => [
                    ['name' => 'committed', 'label' => 'Committed (₦)', 'value' => (int) $l->committed_minor > 0 ? (string) intdiv((int) $l->committed_minor, 100) : ''],
                    ['name' => 'paid', 'label' => 'Paid (₦)', 'value' => (int) $l->paid_minor > 0 ? (string) intdiv((int) $l->paid_minor, 100) : '']]];
            }
            $items[] = $row;
        }
        $secs[] = $items ? ['k' => 'rows', 'h' => 'Budget lines', 'items' => $items] : ['k' => 'note', 'tone' => 'mute', 'text' => 'No spend is planned for this event yet.'];
        $secs[] = ['k' => 'form', 'h' => 'Add a budget line', 'do' => 'line_add', 'hidden' => ['event' => $id], 'submit' => 'Add the line', 'groups' => [
            ['label' => 'What and how much', 'help' => 'It is added unapproved. You approve up to ' . Plan::naira($limit) . '; above that you ask finance.',
             'fields' => [['name' => 'title', 'label' => 'What the money is for', 'type' => 'text', 'required' => true, 'placeholder' => 'Volunteer transport'],
                          ['name' => 'estimate', 'label' => 'Estimate (₦)', 'type' => 'text', 'required' => true, 'placeholder' => '45,000'],
                          ['name' => 'vendor', 'label' => 'Who is paid', 'type' => 'text', 'placeholder' => 'Ikotun Buses Ltd']]],
        ]];
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing here pays anybody. Payments still go through the gateway; this is the plan and the record of what was agreed.'];
        return ['title' => 'Money', 'secs' => $secs];
    }

    private static function approvals(array $me, object $e): array
    {
        $c = LeadPages::root($me, [$e], 'approvals', '', []);
        unset($c['acts']);
        return ['title' => 'Money', 'acts' => [['label' => 'Ask to spend', 'href' => LeadPages::BASE . '/requests?new=spend&event=' . $e->id, 'primary' => true]], 'secs' => $c['secs']];
    }

    private static function vendors(object $e): array
    {
        $by = [];
        foreach (Plan::lines([(int) $e->id]) as $l) {
            if ($l->status === 'declined') continue;
            $k = trim((string) ($l->vendor ?? '')) ?: '';
            $by[$k] ??= ['lines' => 0, 'est' => 0, 'committed' => 0, 'paid' => 0];
            $by[$k]['lines']++;
            $by[$k]['est'] += (int) $l->estimate_minor;
            $by[$k]['committed'] += (int) $l->committed_minor;
            $by[$k]['paid'] += (int) $l->paid_minor;
        }
        uksort($by, static fn ($a, $b) => [$a === '', $a] <=> [$b === '', $b]);
        $rows = [];
        foreach ($by as $v => $x) {
            $rows[] = ['t' => $v !== '' ? $v : 'No vendor named', 'sub' => $x['lines'] . ($x['lines'] === 1 ? ' line' : ' lines') . ' · estimate ' . Plan::naira($x['est'])
                . ($x['committed'] ? ' · committed ' . Plan::naira($x['committed']) : '') . ($x['paid'] ? ' · paid ' . Plan::naira($x['paid']) : '')];
        }
        return ['title' => 'Money', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'Who is paid', 'items' => $rows]
            : ['k' => 'note', 'tone' => 'mute', 'text' => 'No vendors yet. Name who is paid when you add a budget line, and they are grouped here.']]];
    }

    private static function income(object $e): array
    {
        $id = (int) $e->id;
        $tickets = Scope::ticketIncomeMinor($id);
        $sp = Scope::sponsors($id);
        $agreed = array_sum(array_map(static fn ($s) => (int) $s->value_minor, array_filter($sp, static fn ($s) => in_array($s->stage, ['agreed', 'paid'], true))));
        $paid = array_sum(array_map(static fn ($s) => (int) $s->value_minor, array_filter($sp, static fn ($s) => $s->stage === 'paid')));
        $stats = [['l' => 'Tickets', 'v' => Plan::naira($tickets), 's' => number_format(EventTicketService::soldForEvent($id)) . ' sold']];
        if ($sp) {
            $stats[] = ['l' => 'Sponsors agreed', 'v' => Plan::naira($agreed), 's' => ''];
            $stats[] = ['l' => 'Sponsors paid', 'v' => Plan::naira($paid), 's' => ''];
        }
        return ['title' => 'Money', 'secs' => [['k' => 'stats', 'items' => $stats],
            ['k' => 'note', 'tone' => 'mute', 'text' => 'Ticket income is what confirmed tickets were sold for, before gateway fees. Sponsor money counts once a sponsor is at Agreed.']]];
    }

    // ── The night ───────────────────────────────────────────────────────────────────────

    private static function show(object $e, array $owners): array
    {
        $id = (int) $e->id;
        $cues = Scope::show($id);
        $isNight = date('Y-m-d') === date('Y-m-d', (int) strtotime((string) $e->event_date));
        $now = $isNight ? Scope::nowCue($cues) : null;
        $secs = [];
        if ($now) $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'Now: ' . $now->title . '.', 'cta' => ['label' => 'Done', 'post' => ['do' => 'cue_done', 'cue' => $now->id]]];
        if ($cues) {
            $secs[] = ['k' => 'rows', 'h' => 'Running order', 'items' => array_map(static function ($c) use ($now) {
                $who = Plan::name($c->owner_kind, $c->owner_id !== null ? (int) $c->owner_id : null);
                return ['t' => date('H:i', (int) strtotime((string) $c->starts_at)) . '  ' . $c->title, 'dot' => $now && $now->id === $c->id ? 'bad' : ($c->done_at ? 'ok' : null),
                        'sub' => implode(' · ', array_filter([$c->minutes . ' min', $who, (string) ($c->notes ?? '')])),
                        'chip' => $c->done_at ? ['Done', 'ok'] : ($now && $now->id === $c->id ? ['Now', 'bad'] : null),
                        'cta' => $c->done_at ? ['label' => 'Undo', 'post' => ['do' => 'cue_undo', 'cue' => $c->id]]
                                             : ['label' => 'Remove', 'post' => ['do' => 'cue_remove', 'cue' => $c->id], 'confirm' => 'Take this cue out of the running order?']];
            }, $cues)];
        } else {
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No running order yet. Add the cues of the night in any order; they sort by time.'];
        }
        $secs[] = ['k' => 'form', 'h' => 'Add a cue', 'do' => 'cue_add', 'hidden' => ['event' => $id], 'submit' => 'Add the cue', 'groups' => [
            ['label' => 'When', 'help' => 'A time like 18:30 is on the night, ' . Workspace::day((string) $e->event_date) . '.',
             'fields' => [['name' => 'at', 'label' => 'Starts', 'type' => 'text', 'required' => true, 'placeholder' => '18:30'],
                          ['name' => 'minutes', 'label' => 'Minutes', 'type' => 'number', 'min' => 1, 'max' => 600, 'value' => '5']]],
            ['label' => 'What and who', 'fields' => [['name' => 'title', 'label' => 'What happens', 'type' => 'text', 'required' => true, 'placeholder' => 'Welcome from the MC'],
                                                       ['name' => 'owner', 'label' => 'Who runs it', 'type' => 'select', 'options' => $owners],
                                                       ['name' => 'notes', 'label' => 'Notes', 'type' => 'text', 'placeholder' => 'Mic 2, lights to half']]],
        ]];
        return ['title' => 'The night', 'secs' => $secs];
    }

    private static function door(object $e): array
    {
        $id = (int) $e->id;
        $s = EventArrivals::summary($id);
        $passes = EventScanPass::forEvent($id);
        $open = count(array_filter($passes, static fn ($p) => $p['state'] === 'open'));
        $secs = [['k' => 'stats', 'items' => [
            ['l' => 'In the room', 'v' => number_format($s['in']), 's' => $s['expected'] > 0 ? 'of ' . number_format($s['expected']) . ' expected' : ''],
            ['l' => 'On tickets', 'v' => number_format($s['tickets_in']), 's' => ''],
            ['l' => 'Guests of honour', 'v' => number_format($s['honoured_in']), 's' => ''],
            ['l' => 'Scanning links open', 'v' => (string) $open, 's' => count($passes) ? count($passes) . ' made' : ''],
        ]]];
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'The door decides every ticket itself. Scanning links for stewards are made in Event settings › Devices.',
                   'cta' => ['label' => 'Devices', 'href' => self::url($id, 'settings', 'devices')]];
        return ['title' => 'The night', 'secs' => $secs];
    }

    private static function safety(object $e, array $owners): array
    {
        $id = (int) $e->id;
        $checks = Scope::checks($id);
        $done = count(array_filter($checks, static fn ($c) => $c->done_at !== null));
        $secs = [];
        if ($checks) {
            $secs[] = ['k' => 'rows', 'h' => $done . ' of ' . count($checks) . ' checked', 'items' => array_map(static fn ($c) => ['t' => (string) $c->title,
                'sub' => implode(' · ', array_filter([Plan::name($c->owner_kind, $c->owner_id !== null ? (int) $c->owner_id : null),
                         $c->done_at ? 'checked ' . Workspace::day((string) $c->done_at) . ' by ' . Plan::name('admin', (int) $c->done_by) : ''])),
                'chip' => $c->done_at ? ['Checked', 'ok'] : ['Open', 'warn'],
                'cta' => $c->done_at ? ['label' => 'Undo', 'post' => ['do' => 'check_undo', 'check' => $c->id]]
                                     : ['label' => 'Checked', 'post' => ['do' => 'check_done', 'check' => $c->id], 'primary' => true]], $checks)];
        } else {
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No safety checks yet. List what must be true before the doors open: exits clear, first aid on site, the permit in hand.'];
        }
        $secs[] = ['k' => 'form', 'h' => 'Add a check', 'do' => 'check_add', 'hidden' => ['event' => $id], 'submit' => 'Add it', 'groups' => [
            ['label' => 'The check', 'fields' => [['name' => 'title', 'label' => 'What must be true', 'type' => 'text', 'required' => true, 'placeholder' => 'Fire exits clear and lit'],
                                                    ['name' => 'owner', 'label' => 'Who checks it', 'type' => 'select', 'options' => $owners]]],
        ]];
        return ['title' => 'The night', 'secs' => $secs];
    }

    // ── Reports ─────────────────────────────────────────────────────────────────────────

    /** Status for the committee: the same facts as Today, as sentences somebody can paste. */
    private static function status(object $e): array
    {
        $id = (int) $e->id;
        $r = Plan::readiness($id);
        $blockers = Plan::blockers([$id]);
        $risks = Plan::risks([$id]);
        $high = array_values(array_filter($risks, static fn ($x) => $x->level === 'high'));
        $recent = array_filter(Scope::decisions($id), static fn ($d) => !$d->reversed_at && strtotime((string) $d->decided_at) > time() - 7 * 86400);
        $p = [$e->title . ', ' . date('l j F', (int) strtotime((string) $e->event_date)) . ' at ' . self::where($e) . ': ' . LeadPages::daysWords((string) $e->event_date) . '.'];
        $p[] = $r ? 'The plan is ' . $r['pct'] . '% done (' . $r['done'] . ' of ' . $r['all'] . ' pieces of work).' : 'There is no plan yet.';
        if ($blockers) $p[] = 'Blocking the night: ' . implode('; ', array_map(static fn ($w) => $w->title . ($w->blocks_note ? ' (' . $w->blocks_note . ' waits on it)' : ''), $blockers)) . '.';
        $p[] = $risks ? count($risks) . ' open ' . (count($risks) === 1 ? 'risk' : 'risks') . ($high ? ', high: ' . implode('; ', array_map(static fn ($x) => (string) $x->title, $high)) : '') . '.' : 'No open risks.';
        if (($b = Plan::budgetWords($e)) !== null) $p[] = $b;
        if (($t = LeadPages::ticketWords($e)) !== null) $p[] = $t;
        if ($recent) $p[] = 'Decided this week: ' . implode('; ', array_map(static fn ($d) => (string) $d->title, $recent)) . '.';
        return ['title' => 'Reports', 'secs' => [['k' => 'article', 'paras' => $p],
            ['k' => 'note', 'tone' => 'mute', 'text' => 'Written from the plan as it stands right now. Copy it into the committee’s update.']]];
    }

    private static function post(object $e): array
    {
        $id = (int) $e->id;
        if (strtotime((string) $e->event_date) > time()) {
            return ['title' => 'Reports', 'secs' => [['k' => 'note', 'tone' => 'mute',
                'text' => 'The post-event report fills in after the night, ' . date('D j M', (int) strtotime((string) $e->event_date)) . ': who came, what was spent, what was done.']]];
        }
        $s = EventArrivals::summary($id);
        $r = Plan::readiness($id);
        $paid = array_sum(array_map(static fn ($l) => (int) $l->paid_minor, Plan::lines([$id])));
        $cues = Scope::show($id);
        $stats = [['l' => 'Came', 'v' => number_format($s['in']), 's' => $s['expected'] ? 'of ' . number_format($s['expected']) . ' expected' : '']];
        $stats[] = ['l' => 'Spent', 'v' => Plan::naira($paid), 's' => isset($e->budget_minor) && $e->budget_minor ? 'of ' . Plan::naira((int) $e->budget_minor) . ' budget' : ''];
        $stats[] = ['l' => 'Ticket income', 'v' => Plan::naira(Scope::ticketIncomeMinor($id)), 's' => ''];
        if ($r) $stats[] = ['l' => 'Work done', 'v' => $r['done'] . ' of ' . $r['all'], 's' => ''];
        if ($cues) $stats[] = ['l' => 'Cues run', 'v' => count(array_filter($cues, static fn ($c) => $c->done_at)) . ' of ' . count($cues), 's' => ''];
        $undone = array_values(array_filter(Plan::work([$id]), static fn ($w) => $w->status !== 'done'));
        $secs = [['k' => 'stats', 'items' => $stats]];
        if ($undone) $secs[] = ['k' => 'rows', 'h' => 'Left undone', 'items' => array_map(static fn ($w) => ['t' => (string) $w->title,
            'sub' => Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null) ?: 'Nobody'], $undone)];
        return ['title' => 'Reports', 'secs' => $secs];
    }

    private static function audit(object $e): array
    {
        $words = ['work_add' => 'added work', 'work_status' => 'moved work', 'work_owner' => 'gave work to somebody', 'risk_add' => 'added a risk', 'risk_close' => 'closed a risk',
                  'line_add' => 'added a budget line', 'line_approve' => 'approved spend', 'line_ask' => 'asked finance', 'line_spend' => 'recorded spend',
                  'event_settings' => 'changed the settings', 'decision_add' => 'recorded a decision', 'decision_reverse' => 'reversed a decision',
                  'cue_add' => 'added a cue', 'cue_done' => 'ran a cue', 'cue_undo' => 'took a cue back', 'cue_remove' => 'removed a cue',
                  'sponsor_add' => 'added a sponsor', 'sponsor_stage' => 'moved a sponsor', 'check_add' => 'added a safety check', 'check_done' => 'signed off a check',
                  'check_undo' => 'reopened a check', 'table_save' => 'changed the seating', 'shift_add' => 'posted a shift', 'door_issue' => 'made a scanning link',
                  'door_revoke' => 'turned off a scanning link', 'request' => 'made a request', 'lead_grant' => 'gave the event a lead'];
        $rows = [];
        foreach (Scope::audit((int) $e->id) as $a) {
            $verb = substr((string) $a->action, 5);
            $meta = json_decode((string) ($a->meta ?? ''), true) ?: [];
            $detail = trim((string) ($meta['title'] ?? $meta['name'] ?? $meta['label'] ?? ''));
            $rows[] = ['t' => ($a->name ?: 'Someone') . ' ' . ($words[$verb] ?? $verb), 'sub' => $detail, 'meta' => date('j M, H:i', (int) strtotime((string) $a->created_at))];
        }
        return ['title' => 'Reports', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'Who did what', 'items' => $rows]
            : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing has been changed here yet. Every change a lead makes is recorded, once, with who and when.']]];
    }

    // ── Requests and settings ───────────────────────────────────────────────────────────

    /** @return list<object> requests made about this event, newest first */
    public static function eventRequests(int $eventId): array
    {
        if (!Requests::ready()) return [];
        return DB::table('gates_requests')->where('scope_kind', 'event')->where('scope_id', $eventId)->orderByDesc('id')->limit(100)->get()->all();
    }

    private static function requests(array $me, object $e): array
    {
        $rows = self::eventRequests((int) $e->id);
        $tone = ['waiting' => 'warn', 'needs_info' => 'info', 'approved' => 'ok', 'declined' => 'bad', 'withdrawn' => 'mute'];
        $out = [];
        foreach ($rows as $r) {
            $mine = $r->from_kind === 'admin' && (int) $r->from_id === $me['id'];
            $t = Requests::type((string) $r->type, $r->from_kind === 'admin' ? 'lead' : 'volunteer');
            $out[] = ['href' => $mine ? LeadPages::BASE . '/requests?r=' . $r->id : null, 'cells' => [
                ['t' => (string) $r->title, 'sub' => (string) ($t['label'] ?? $r->type) . ($r->amount_minor !== null ? ' · ' . Plan::naira((int) $r->amount_minor) : '')],
                ['t' => $mine ? 'You' : (Plan::name((string) $r->from_kind, (int) $r->from_id) ?: 'Someone')], ['t' => Requests::deciderLabel($r)],
                ['chip' => [Requests::STATUSES[$r->status] ?? $r->status, $tone[$r->status] ?? 'mute']]]];
        }
        // A table row with no link draws as plain text; unset the key so the macro sees none.
        foreach ($out as &$o) if ($o['href'] === null) unset($o['href']);
        unset($o);
        return ['title' => 'Requests', 'acts' => [['label' => 'New request', 'href' => LeadPages::BASE . '/requests?new=spend&event=' . $e->id, 'primary' => true]],
            'secs' => [['k' => 'table', 'cols' => [['l' => 'Request'], ['l' => 'From'], ['l' => 'Decides', 't0' => true], ['l' => 'Status']], 'rows' => $out,
                        'empty' => 'No requests about this event yet.', 'foot' => 'Requests from the event’s volunteers about their shifts show here too.']]];
    }

    private static function general(object $e): array
    {
        $id = (int) $e->id;
        return ['title' => 'Event settings', 'secs' => [
            ['k' => 'rows', 'h' => 'The event', 'items' => [
                ['t' => 'Date', 'sub' => date('l j F Y, H:i', (int) strtotime((string) $e->event_date)), 'chip' => ['Locked', 'mute']],
                ['t' => 'Venue', 'sub' => self::where($e), 'chip' => ['Locked', 'mute']],
            ]],
            ['k' => 'note', 'tone' => 'mute', 'text' => 'The date and the venue are on tickets, letters and the public page, so changing either is a request.',
             'cta' => ['label' => 'Ask to change them', 'href' => LeadPages::BASE . '/requests?new=venue&event=' . $id]],
            ['k' => 'form', 'h' => 'Budget and series', 'do' => 'event_settings', 'hidden' => ['event' => $id], 'submit' => 'Save', 'groups' => [
                ['label' => 'Budget', 'help' => 'What the event may spend. The forecast is compared with it.',
                 'fields' => [['name' => 'budget', 'label' => 'Budget (₦)', 'type' => 'text', 'value' => isset($e->budget_minor) && $e->budget_minor !== null ? (string) intdiv((int) $e->budget_minor, 100) : '',
                               'placeholder' => '6,000,000']]],
                ['label' => 'Series', 'help' => 'The name its editions share, so Growth can compare this one with the last.',
                 'fields' => [['name' => 'series', 'label' => 'Series', 'type' => 'text', 'value' => (string) ($e->series_key ?? ''), 'placeholder' => 'alimosho-ceremony']]],
            ]],
        ]];
    }

    private static function members(object $e): array
    {
        $id = (int) $e->id;
        $now = date('Y-m-d H:i:s');
        $rows = [];
        if (Grants::ready()) {
            foreach (DB::table('gates_grants')->where('scope_kind', 'event')->where('scope_id', $id)->whereNull('revoked_at')->where('expires_at', '>', $now)
                ->orderBy('capability')->orderBy('expires_at')->get()->all() as $g) {
                $rows[] = ['t' => Plan::name((string) $g->actor_kind, (int) $g->actor_id) ?: 'Someone', 'sub' => (Grants::CAPABILITIES[$g->capability] ?? $g->capability)
                    . ' · until ' . Workspace::day((string) $g->expires_at) . ($g->actor_kind === 'member' ? ' · volunteer' : ''), 'chip' => ['Ends on its own', 'mute']];
            }
        }
        return ['title' => 'Event settings', 'secs' => [
            $rows ? ['k' => 'rows', 'h' => 'Who has access', 'items' => $rows] : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nobody else has access to this event.'],
            ['k' => 'note', 'tone' => 'mute', 'text' => 'Every role here ends by itself, with the event or the shift. Somebody new is a request to an administrator.',
             'cta' => ['label' => 'Ask for access', 'href' => LeadPages::BASE . '/requests?new=access&event=' . $id]],
        ]];
    }

    private static function limits(object $e): array
    {
        $rows = [
            ['t' => 'Spend you approve', 'sub' => 'Up to ' . Plan::naira(Plan::limitMinor()) . ' a line. Above it, finance decides.', 'chip' => ['Locked', 'mute']],
            ['t' => 'A week of work', 'sub' => Plan::rule('lead_week_hours') . ' hours: People turns red above it.', 'chip' => ['Locked', 'mute']],
        ];
        if (!empty($e->capacity)) $rows[] = ['t' => 'Capacity', 'sub' => number_format((int) $e->capacity) . ' people.', 'chip' => ['Locked', 'mute']];
        return ['title' => 'Event settings', 'secs' => [['k' => 'rows', 'h' => 'Limits', 'items' => $rows],
            ['k' => 'note', 'tone' => 'mute', 'text' => 'These are the platform’s, set by an administrator for every event, so a lead cannot raise their own.']]];
    }

    private static function devices(object $e): array
    {
        $id = (int) $e->id;
        $passes = EventScanPass::forEvent($id);
        $tone = ['open' => 'ok', 'scheduled' => 'info', 'closed' => 'mute', 'revoked' => 'mute'];
        $words = ['open' => 'Working', 'scheduled' => 'Not open yet', 'closed' => 'Closed', 'revoked' => 'Turned off'];
        $secs = [];
        if ($passes) {
            $secs[] = ['k' => 'rows', 'h' => 'Scanning links', 'items' => array_map(static fn ($p) => ['t' => $p['label'] !== '' ? $p['label'] : 'Door',
                'sub' => ($p['opens_at'] !== '' ? 'from ' . date('D j M, H:i', (int) strtotime($p['opens_at'])) . ' ' : '') . 'until ' . date('D j M, H:i', (int) strtotime($p['closes_at']))
                         . ' · ' . number_format($p['scans']) . ' scans',
                'chip' => [$words[$p['state']] ?? $p['state'], $tone[$p['state']] ?? 'mute'],
                'cta' => in_array($p['state'], ['open', 'scheduled'], true) ? ['label' => 'Turn off', 'post' => ['do' => 'door_revoke', 'event' => $id, 'pass' => $p['id']], 'confirm' => 'Turn this scanning link off now?'] : null], $passes)];
        } else {
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No scanning links yet. Make one per door; each works on any phone, for this event only, between two times.'];
        }
        $day = date('Y-m-d', (int) strtotime((string) $e->event_date));
        $secs[] = ['k' => 'form', 'h' => 'Make a scanning link', 'do' => 'door_issue', 'hidden' => ['event' => $id], 'submit' => 'Make the link', 'groups' => [
            ['label' => 'Which door, and when', 'help' => 'The link is shown once, right after you make it. It can scan tickets in and nothing else, and it stops by itself.',
             'fields' => [['name' => 'label', 'label' => 'Door', 'type' => 'text', 'placeholder' => 'Main gate'],
                          ['name' => 'opens_at', 'label' => 'Opens', 'type' => 'datetime-local', 'step' => '1', 'value' => $day . 'T15:00:00'],
                          ['name' => 'closes_at', 'label' => 'Closes', 'type' => 'datetime-local', 'step' => '1', 'required' => true, 'value' => $day . 'T23:59:00']]],
        ]];
        return ['title' => 'Event settings', 'secs' => $secs];
    }

    private static function where(object $e): string
    {
        return trim(implode(', ', array_filter([(string) ($e->venue ?? ''), (string) ($e->location ?? '')]))) ?: 'Venue to be confirmed';
    }
}
