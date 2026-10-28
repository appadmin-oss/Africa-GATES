<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\EventTicketService;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE EVENT LEAD CONSOLE'S PAGES (consoles v7 handoff §5, `nav-admin.json` leadRoot; review
 * slice L1). Each method returns what the v7 shell draws for one page — title, purpose, actions
 * and sections — built from Plan, Grants and Requests and nothing else.
 *
 *   · EVERYTHING IS LIMITED TO THE LEAD'S EVENT GRANTS. The list of events comes from
 *     Plan::leadEvents() once per page and every query is filtered by those ids, so a lead
 *     cannot see another event's work by changing a number in the address.
 *   · NO FAKE NUMBERS (§6, §8). A readiness bar exists only when the event has work; a budget
 *     line only when there is spend; a comparison only when there is a previous edition. An
 *     event with nothing yet shows its setup steps, never a row of zeros.
 *   · THE ROOT IS READ-MOSTLY. It writes the small things a lead does from a list (done,
 *     approve, close); the plan itself is edited in the event's own pages, where the team is.
 */
final class LeadPages
{
    public const BASE = '/admin/lead';

    /** Root pages: rail label and icon, in the handoff's order and groups. */
    public const ROOT = [
        'overview' => ['Overview', 'home'], 'inbox' => ['Inbox', 'inbox'], 'requests' => ['Requests', 'list'], 'yours' => ['Your events', 'ticket'],
        'deadlines' => ['Deadlines', 'cal'], 'risks' => ['Risks', 'flag'], 'approvals' => ['Approvals', 'card'], 'analytics' => ['Analytics', 'chart'],
        'people' => ['People', 'users'],
    ];
    private const GROUPS = [[null, ['overview', 'inbox', 'requests', 'yours']], ['Across your events', ['deadlines', 'risks', 'approvals', 'analytics']], ['Team', ['people']]];
    public const TABS = ['requests' => ['open' => 'Open', 'decided' => 'Decided']];

    public const PURPOSE = [
        'overview'  => '',
        'inbox'     => 'Work given to you by name, and work nobody has yet. Soonest first.',
        'requests'  => 'What you’ve asked for, who decides, and where it stands.',
        'yours'     => 'Events you have a role on. Your access to each ends when the event does.',
        'deadlines' => 'Every date across your events, late first.',
        'risks'     => 'What could stop a night, how likely it is, and who is watching it.',
        'approvals' => 'Spend waiting for a decision, and who makes it.',
        'analytics' => 'Each event against its last edition, on the same day before the night.',
        'people'    => 'Who is carrying the work this week, and how the volunteer shifts are filling.',
    ];

    // ── shell pieces ────────────────────────────────────────────────────────────────────

    /** @param array<string,array{n:int,tone:string}> $counts */
    public static function rootNav(string $current, array $counts): array
    {
        $groups = [];
        foreach (self::GROUPS as [$label, $keys]) {
            $items = [];
            foreach ($keys as $k) {
                $items[] = ['key' => $k, 'label' => self::ROOT[$k][0], 'icon' => self::ROOT[$k][1], 'href' => self::BASE . ($k === 'overview' ? '' : '/' . $k),
                            'current' => $k === $current, 'drill' => false, 'count' => $counts[$k] ?? null];
            }
            $groups[] = ['label' => $label, 'items' => $items];
        }
        return $groups;
    }

    /** A page's tabs, each a ?tab= on the page itself; the first is the bare page. */
    public static function tabs(string $page, string $current): array
    {
        $out = [];
        $first = array_key_first(self::TABS[$page] ?? []);
        foreach (self::TABS[$page] ?? [] as $k => $label) {
            $out[] = ['key' => $k, 'label' => $label, 'href' => self::BASE . '/' . $page . ($k === $first ? '' : '?tab=' . $k), 'current' => $k === $current, 'count' => null];
        }
        return $out;
    }

    /**
     * The rail's counts, never 0 (§8): Inbox, Risks (red when any is high), Approvals, and
     * Requests that need something from the lead.
     *
     * @param list<object> $events
     * @return array<string,array{n:int,tone:string}>
     */
    public static function counts(array $me, array $events): array
    {
        $ids = self::ids($events);
        $c = [];
        $add = static function (string $k, int $n, string $tone) use (&$c): void { if ($n > 0) $c[$k] = ['n' => $n, 'tone' => $tone]; };
        $add('inbox', count(self::inboxRows($me, $ids)), 'warn');
        $risks = Plan::risks($ids);
        $add('risks', count($risks), array_filter($risks, static fn ($r) => $r->level === 'high') ? 'bad' : 'warn');
        $add('approvals', count(array_filter(Plan::lines($ids), static fn ($l) => $l->status === 'proposed')), 'warn');
        $add('requests', count(array_filter(Requests::from('admin', $me['id'], 'open'), static fn ($r) => $r->status === 'needs_info')), 'warn');
        return $c;
    }

    // ── root pages ──────────────────────────────────────────────────────────────────────

    /**
     * @param array{id:int, name:string, role:string} $me
     * @param list<object> $events the lead's events, from Plan::leadEvents()
     */
    public static function root(array $me, array $events, string $page, string $tab, array $q): array
    {
        if ($events === [] && !in_array($page, ['yours', 'requests'], true)) return self::noEvents($me, $page);
        return match ($page) {
            'overview' => self::overview($me, $events),
            'inbox' => self::inbox($me, $events),
            'requests' => self::requests($me, $events, $tab, $q),
            'yours' => self::yours($me, $events),
            'deadlines' => self::deadlines($events),
            'risks' => self::risks($me, $events),
            'approvals' => self::approvals($me, $events),
            'analytics' => self::analytics($events),
            'people' => self::people($events),
            default => ['title' => 'Overview', 'secs' => []],
        };
    }

    private static function noEvents(array $me, string $page): array
    {
        $platform = in_array($me['role'], ['superadmin', 'admin'], true);
        $note = ['k' => 'note', 'tone' => 'mute', 'text' => 'You don’t lead an event yet. When an administrator makes you the lead on one, it shows here, with its work, risks and budget.'
            . ($platform ? ' You can give an event to a lead from Your events.' : '')];
        if ($platform) $note['cta'] = ['label' => 'Your events', 'href' => self::BASE . '/yours'];
        return ['title' => self::ROOT[$page][0] ?? 'Overview', 'secs' => [$note]];
    }

    private static function overview(array $me, array $events): array
    {
        $ids = self::ids($events);
        $blockers = Plan::blockers($ids);
        $next = null;
        foreach ($events as $e) if (strtotime((string) $e->event_date) >= strtotime(date('Y-m-d'))) { $next = $e; break; }
        $next ??= $events[0];
        $nb = count(array_filter($blockers, static fn ($w) => (int) $w->event_id === (int) $next->id));
        $purpose = count($events) . (count($events) === 1 ? ' event. ' : ' events. ') . 'The ' . $next->title . ' is ' . self::daysWords((string) $next->event_date)
            . ($nb === 1 ? ' and one thing blocks it.' : ($nb > 1 ? ' and ' . $nb . ' things block it.' : '.'));

        $secs = [];
        if ($blockers) $secs[] = ['k' => 'rows', 'h' => 'Blocking a night', 'box' => true, 'items' => array_map(fn ($w) => self::blockerRow($w, $events), $blockers)];

        $cards = [];
        foreach ($events as $e) {
            $r = Plan::readiness((int) $e->id);
            $b = count(array_filter($blockers, static fn ($w) => (int) $w->event_id === (int) $e->id));
            $risks = Plan::risks([(int) $e->id]);
            $chip = $b > 0 ? [$b === 1 ? '1 blocker' : $b . ' blockers', 'bad'] : ($risks ? [count($risks) === 1 ? '1 risk' : count($risks) . ' risks', 'warn'] : null);
            $card = ['t' => (string) $e->title, 'meta' => Workspace::day((string) $e->event_date) . ' · ' . self::daysShort((string) $e->event_date),
                     'sub' => implode(' ', array_filter([Plan::budgetWords($e), self::ticketWords($e)])), 'ini' => Workspace::initials((string) $e->title),
                     'tone' => 'ok', 'chip' => $chip, 'href' => self::eventUrl((int) $e->id)];
            if ($r) $card += ['pct' => $r['pct'], 'barT' => $r['pct'] . '% ready'];
            $cards[] = $card;
        }
        $secs[] = ['k' => 'cards', 'h' => count($events) === 1 ? 'Your event' : 'Your events', 'items' => $cards];

        $work = Plan::work($ids, true);
        $risks = Plan::risks($ids);
        $lines = Plan::lines($ids);
        if ($work === [] && $risks === [] && $lines === []) {
            // A plan with nothing in it shows how to start one, never a row of zeros (§8).
            $first = self::eventUrl((int) $next->id, 'work');
            $secs[] = ['k' => 'steps', 'h' => 'Start the plan', 'items' => [
                ['t' => 'Add the work before the night', 'sub' => 'Each piece with an owner, a due date and its hours. Readiness is counted from it.', 'st' => 'now'],
                ['t' => 'Add the risks', 'sub' => 'What could stop the night, how likely, how bad, and who watches it.', 'st' => 'next'],
                ['t' => 'Plan the spend', 'sub' => 'You approve up to ' . Plan::naira(Plan::limitMinor()) . ' a line. Finance decides above it.', 'st' => 'next'],
            ]];
            $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Start with ' . $next->title . '.', 'cta' => ['label' => 'Add work', 'href' => $first]];
        } else {
            $week = time() + 7 * 86400;
            $due = array_filter($work, static fn ($w) => $w->due_at !== null && strtotime((string) $w->due_at) <= $week);
            $late = count(array_filter($due, static fn ($w) => Plan::isLate($w)));
            $proposed = count(array_filter($lines, static fn ($l) => $l->status === 'proposed'));
            $withFinance = count(array_filter($lines, static fn ($l) => $l->status === 'requested'));
            $high = count(array_filter($risks, static fn ($r) => $r->level === 'high'));
            $tickets = array_sum(array_map(static fn ($e) => EventTicketService::soldForEvent((int) $e->id), $events));
            $secs[] = ['k' => 'stats', 'h' => count($events) === 1 ? 'This week' : 'This week across your events', 'items' => [
                ['l' => 'Work due this week', 'v' => (string) count($due), 's' => $late ? $late . ' late' : ''],
                ['l' => 'Approvals waiting', 'v' => (string) ($proposed + $withFinance), 's' => $withFinance ? $withFinance . ' with finance' : ''],
                ['l' => 'Open risks', 'v' => (string) count($risks), 's' => $high ? $high . ' high' : ''],
                ['l' => 'Tickets sold', 'v' => number_format($tickets), 's' => ''],
            ]];
        }
        $dl = self::deadlineItems($events);
        if ($dl) {
            $secs[] = ['k' => 'rows', 'h' => 'Next deadlines', 'items' => array_slice(array_map(static fn ($d) => $d['row'], $dl), 0, 3),
                       'link' => ['label' => 'All deadlines', 'href' => self::BASE . '/deadlines']];
        }
        $side = [];
        $mine = Requests::from('admin', $me['id'], 'open');
        if ($mine) {
            $side[] = ['k' => 'rows', 'h' => 'Your requests', 'items' => array_map(static fn ($r) => ['t' => (string) $r->title,
                'sub' => $r->amount_minor !== null ? Plan::naira((int) $r->amount_minor) : (string) (Requests::type((string) $r->type, 'lead')['label'] ?? ''),
                'chip' => [$r->status === 'waiting' ? 'With ' . Requests::deciderLabel($r) : 'Needs info', $r->status === 'waiting' ? 'warn' : 'info'],
                'href' => self::BASE . '/requests?r=' . $r->id], array_slice($mine, 0, 3)), 'link' => ['label' => 'All', 'href' => self::BASE . '/requests']];
        }
        return ['title' => 'Overview', 'purpose' => $purpose, 'secs' => $secs, 'side' => $side];
    }

    private static function inbox(array $me, array $events): array
    {
        $byId = self::byId($events);
        $rows = [];
        foreach (self::inboxRows($me, self::ids($events)) as $w) {
            $e = $byId[(int) $w->event_id];
            $rows[] = ['t' => (string) $w->title, 'href' => self::eventUrl((int) $e->id, 'work'),
                       'sub' => $e->title . ($w->owner_kind ? '' : ' · nobody has it yet') . ($w->est_hours ? ' · ' . self::hours((float) $w->est_hours) : ''),
                       'ini' => Workspace::initials((string) $e->title), 'chip' => self::dueChip($w),
                       'cta' => ['label' => 'Done', 'post' => ['do' => 'work_status', 'work' => $w->id, 'status' => 'done']]];
        }
        return ['title' => 'Inbox', 'secs' => [$rows
            ? ['k' => 'rows', 'h' => 'Assigned to you', 'items' => $rows]
            : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing is waiting here for you. When work is given to you by name it shows here, soonest first.']]];
    }

    /** Open work that is the lead's by name, or nobody's (§5: unassigned work goes to the lead). */
    private static function inboxRows(array $me, array $ids): array
    {
        return array_values(array_filter(Plan::work($ids, true), static fn ($w) => !$w->owner_kind
            || ($w->owner_kind === 'admin' && (int) $w->owner_id === $me['id'])));
    }

    private static function requests(array $me, array $events, string $tab, array $q): array
    {
        $secs = [];
        $new = (string) ($q['new'] ?? '');
        if ($new !== '') $secs[] = self::composer($events, $new, (string) ($q['title'] ?? ''), (int) ($q['event'] ?? 0));
        $rows = Requests::from('admin', $me['id'], $tab === 'decided' ? 'decided' : 'open');
        $secs[] = self::requestTable($rows, $tab === 'decided' ? 'Nothing decided yet.' : 'You have no open requests.',
            'Anything over your ' . Plan::naira(Plan::limitMinor()) . ' limit, access for your team, big sends and venue changes go through here.');
        return ['title' => 'Requests', 'acts' => $new === '' && $events ? [['label' => 'New request', 'href' => self::BASE . '/requests?new=spend', 'primary' => true]] : [], 'secs' => $secs];
    }

    /** The composer (§2.6): type chips, the type's fields, where, the route line, Send. */
    private static function composer(array $events, string $type, string $title, int $eventId): array
    {
        $types = Requests::types('lead');
        $t = Requests::type($type, 'lead') ?? ($types[0] ?? null);
        $chips = [];
        foreach ($types as $x) $chips[] = ['label' => (string) $x['label'], 'href' => self::BASE . '/requests?new=' . $x['key'], 'current' => $t && $x['key'] === $t['key']];
        $fields = [];
        foreach ((array) ($t['fields'] ?? []) as $f) {
            $fields[] = ['name' => $f['key'], 'label' => (string) $f['label'], 'type' => $f['key'] === 'why' ? 'textarea' : 'text',
                         'required' => in_array($f['key'], ['title', 'why'], true), 'value' => $f['key'] === 'title' ? $title : '',
                         'placeholder' => (string) ($f['placeholder'] ?? '')];
        }
        $where = [];
        foreach ($events as $e) $where[(string) $e->id] = (string) $e->title;
        $fields[] = ['name' => 'event', 'label' => 'Where', 'type' => 'select', 'options' => $where, 'required' => true, 'value' => (string) $eventId];
        $route = $t ? 'Goes to ' . ($t['route'] === 'finance' ? 'finance' : 'an administrator') . ', ' . $t['usually'] . '.' : '';
        return ['k' => 'form', 'h' => 'New request', 'chips' => $chips, 'do' => 'request', 'hidden' => ['type' => (string) ($t['key'] ?? '')],
                'submit' => 'Send request', 'route' => $route, 'groups' => [['label' => (string) ($t['label'] ?? ''), 'fields' => $fields]]];
    }

    public static function requestTable(array $rows, string $empty, string $foot): array
    {
        $tone = ['waiting' => 'warn', 'needs_info' => 'info', 'approved' => 'ok', 'declined' => 'bad', 'withdrawn' => 'mute'];
        $out = [];
        foreach ($rows as $r) {
            $t = Requests::type((string) $r->type, 'lead');
            $out[] = ['href' => self::BASE . '/requests?r=' . $r->id, 'cells' => [
                ['t' => (string) $r->title, 'sub' => (string) ($t['label'] ?? $r->type) . ($r->amount_minor !== null ? ' · ' . Plan::naira((int) $r->amount_minor) : '')],
                ['t' => Requests::deciderLabel($r)], ['t' => self::ago((string) $r->created_at), 'mono' => true],
                ['chip' => [Requests::STATUSES[$r->status] ?? $r->status, $tone[$r->status] ?? 'mute']]]];
        }
        return ['k' => 'table', 'cols' => [['l' => 'Request'], ['l' => 'Decides', 't0' => true], ['l' => 'Asked'], ['l' => 'Status']],
                'rows' => $out, 'empty' => $empty, 'foot' => $foot];
    }

    /** One request the lead made: facts, activity, and Add info / Withdraw while it is open. */
    public static function request(array $me, int $id): ?array
    {
        $r = Requests::find($id);
        if (!$r || $r->from_kind !== 'admin' || (int) $r->from_id !== $me['id']) return null;   // a lead sees their own requests here
        $t = Requests::type((string) $r->type, 'lead');
        $where = $r->scope_kind === 'event' ? (string) (Plan::event((int) $r->scope_id)->title ?? '') : '';
        $facts = array_values(array_filter([['Type', (string) ($t['label'] ?? $r->type)], ['From', 'You'], $where !== '' ? ['Where', $where] : null,
            $r->amount_minor !== null ? ['Amount', Plan::naira((int) $r->amount_minor)] : ($r->amount_text ? ['Amount', (string) $r->amount_text] : null),
            $r->needed_by ? ['Needed by', (string) $r->needed_by] : null, ['Why', (string) $r->why],
            $r->type === 'spend' && $r->status === 'waiting' ? ['If approved', 'The line is approved in the event’s budget, and counts in its forecast.'] : null,
            ['Decides', Requests::deciderLabel($r)]]));
        $tone = ['waiting' => 'warn', 'needs_info' => 'info', 'approved' => 'ok', 'declined' => 'bad', 'withdrawn' => 'mute'];
        $secs = [['k' => 'hero', 'ini' => strtoupper(mb_substr((string) $r->type, 0, 2)), 'tone' => 'info', 't' => (string) $r->title, 'sub' => '',
                  'chips' => [[Requests::STATUSES[$r->status] ?? $r->status, $tone[$r->status] ?? 'mute']], 'facts' => $facts, 'acts' => []]];
        $log = [];
        foreach (Requests::history($id) as $e) {
            $who = $e->actor_kind === 'admin' && (int) $e->actor_id === $me['id'] ? 'You' : (string) ($e->actor_name ?? 'Someone');
            $verb = ['asked' => 'asked', 'approve' => 'approved', 'ask_info' => 'asked for information', 'decline' => 'declined', 'add_info' => 'added information',
                     'withdraw' => 'withdrew it', 'undo' => 'undid the last step', 'remind' => 'sent a reminder', 'sent' => 'sent it'][$e->action] ?? $e->action;
            $log[] = ['time' => date('j M', (int) strtotime((string) $e->created_at)), 't' => $who . ' ' . $verb, 'd' => (string) ($e->note ?? ''), 'st' => 'done'];
        }
        if ($log) $secs[] = ['k' => 'agenda', 'h' => 'Activity', 'items' => $log];
        if (in_array($r->status, Requests::OPEN, true)) {
            $opts = $r->status === 'needs_info' ? [['add_info', 'Add info', true], ['withdraw', 'Withdraw', false]] : [['withdraw', 'Withdraw', false]];
            if ($r->status === 'waiting') {
                $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Waiting on ' . Requests::deciderLabel($r) . '. You can remind them once a day; they are emailed and it shows in the history.',
                           'cta' => ['label' => 'Remind ' . Requests::deciderLabel($r), 'post' => ['do' => 'request_remind', 'request' => $id]]];
            }
            $secs[] = ['k' => 'decide', 'request' => $id, 'opts' => $opts, 'note' => $r->status === 'needs_info' ? 'Write what they asked for, then send it back.' : ''];
        } else {
            $secs[] = ['k' => 'note', 'text' => 'This request is decided, so it can’t be changed. Ask again with a new request if something has moved.', 'tone' => 'mute'];
        }
        return ['title' => (string) $r->title, 'noHead' => true, 'secs' => $secs];
    }

    private static function yours(array $me, array $events): array
    {
        $rows = [];
        foreach ($events as $e) {
            $until = '';
            foreach (Grants::forActor('admin', $me['id'], 'event.lead') as $g) {
                if ($g->scope_kind === 'event' && (int) $g->scope_id === (int) $e->id) { $until = Workspace::day((string) $g->expires_at); break; }
            }
            $rows[] = ['t' => (string) $e->title, 'sub' => Workspace::day((string) $e->event_date) . ' · Event lead' . ($until !== '' ? ' · your access ends ' . $until : ''),
                       'ini' => Workspace::initials((string) $e->title), 'href' => self::eventUrl((int) $e->id)];
        }
        $secs = [$rows ? ['k' => 'rows', 'box' => true, 'items' => $rows]
                       : ['k' => 'note', 'tone' => 'mute', 'text' => 'You don’t lead an event yet.']];
        if (in_array($me['role'], ['superadmin', 'admin'], true)) $secs = array_merge($secs, self::leadAdmin());
        return ['title' => 'Your events', 'secs' => $secs];
    }

    /**
     * For platform administrators: who leads which event, and the form to give one. A lead's
     * access ends the day after their event unless an earlier date is given (§5: the grant ends
     * with the event).
     */
    private static function leadAdmin(): array
    {
        $events = DB::table('gates_site_events')->where('event_date', '>=', date('Y-m-d 00:00:00'))->orderBy('event_date')->limit(100)->get(['id', 'title', 'event_date'])->all();
        $admins = DB::table('gates_admins')->where('is_active', 1)->whereIn('role', ['superadmin', 'admin', 'editor', 'moderator'])->orderBy('name')->get(['id', 'name', 'role'])->all();
        $current = [];
        if (Grants::ready()) {
            $now = date('Y-m-d H:i:s');
            foreach (DB::table('gates_grants')->where('capability', 'event.lead')->where('scope_kind', 'event')->where('actor_kind', 'admin')
                ->whereNull('revoked_at')->where('expires_at', '>', $now)->orderBy('expires_at')->limit(200)->get()->all() as $g) {
                $e = Plan::event((int) $g->scope_id);
                $current[] = ['t' => Plan::name('admin', (int) $g->actor_id), 'sub' => ($e->title ?? 'An event') . ' · until ' . Workspace::day((string) $g->expires_at),
                              'cta' => ['label' => 'End', 'post' => ['do' => 'lead_revoke', 'grant' => $g->id], 'confirm' => 'End this lead’s access now?']];
            }
        }
        $out = [];
        if ($current) $out[] = ['k' => 'rows', 'h' => 'Leads on events', 'items' => $current];
        if ($events && $admins) {
            $ev = [];
            foreach ($events as $e) $ev[(string) $e->id] = $e->title . ' · ' . Workspace::day((string) $e->event_date);
            $ad = [];
            foreach ($admins as $a) $ad[(string) $a->id] = (string) $a->name;
            $out[] = ['k' => 'form', 'h' => 'Give someone an event to lead', 'do' => 'lead_grant', 'submit' => 'Make them the lead', 'groups' => [
                ['label' => 'Who and where', 'help' => 'Only staff who can make changes are listed: a viewer could open the console but not run an event.',
                 'fields' => [['name' => 'admin', 'label' => 'Lead', 'type' => 'select', 'options' => $ad, 'required' => true],
                              ['name' => 'event', 'label' => 'Event', 'type' => 'select', 'options' => $ev, 'required' => true]]],
                ['label' => 'Until', 'help' => 'Leave empty and it ends the day after the event.',
                 'fields' => [['name' => 'until', 'label' => 'Access ends', 'type' => 'date']]],
            ]];
        }
        return $out;
    }

    private static function deadlines(array $events): array
    {
        $groups = ['late' => [], 'week' => [], 'later' => [], 'none' => []];
        foreach (self::deadlineItems($events, true) as $d) $groups[$d['group']][] = $d['row'];
        $secs = [];
        foreach (['late' => 'Late', 'week' => 'This week', 'later' => 'Later', 'none' => 'No date yet'] as $k => $h) {
            if ($groups[$k]) $secs[] = ['k' => 'rows', 'h' => $h, 'items' => $groups[$k]];
        }
        if (!$secs) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing is dated yet. Give work a due date and it shows here.'];
        return ['title' => 'Deadlines', 'secs' => $secs];
    }

    /**
     * Every dated thing across the events: open work and each night itself, soonest first.
     *
     * @return list<array{group:string, at:?string, row:array}>
     */
    public static function deadlineItems(array $events, bool $withUndated = false): array
    {
        $byId = self::byId($events);
        $now = time();
        $week = $now + 7 * 86400;
        $out = [];
        foreach (Plan::work(self::ids($events), true) as $w) {
            if ($w->due_at === null && !$withUndated) continue;
            $e = $byId[(int) $w->event_id];
            $owner = Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null);
            $late = Plan::isLate($w);
            $at = $w->due_at !== null ? strtotime((string) $w->due_at) : null;
            $out[] = ['group' => $at === null ? 'none' : ($late ? 'late' : ($at <= $week ? 'week' : 'later')), 'at' => $w->due_at,
                      'row' => ['t' => (string) $w->title, 'sub' => $e->title . ($owner !== '' ? ' · ' . $owner : ' · nobody yet'),
                                'meta' => $at ? Workspace::day((string) $w->due_at) : '', 'dot' => $late ? 'bad' : null,
                                'chip' => $late ? ['Late', 'bad'] : ((int) $w->blocks_night === 1 ? ['The night waits on it', 'warn'] : null),
                                'href' => self::eventUrl((int) $e->id, 'work')]];
        }
        foreach ($events as $e) {
            $at = strtotime((string) $e->event_date);
            if ($at < $now) continue;
            $out[] = ['group' => $at <= $week ? 'week' : 'later', 'at' => (string) $e->event_date,
                      'row' => ['t' => 'The night: ' . $e->title, 'sub' => trim((string) ($e->venue ?: $e->location ?: '')) . ($e->venue || $e->location ? ', ' : '') . date('H:i', $at),
                                'meta' => Workspace::day((string) $e->event_date), 'href' => self::eventUrl((int) $e->id)]];
        }
        usort($out, static fn ($a, $b) => [$a['at'] === null ? 1 : 0, (string) $a['at']] <=> [$b['at'] === null ? 1 : 0, (string) $b['at']]);
        return $out;
    }

    private static function risks(array $me, array $events): array
    {
        $byId = self::byId($events);
        $secs = self::riskSections(Plan::risks(self::ids($events)), $byId, true);
        if (!$secs) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No open risks at your events. Add one when something could stop a night.'];
        $ev = [];
        foreach ($events as $e) $ev[(string) $e->id] = (string) $e->title;
        $secs[] = self::riskForm($ev, ['' => 'Nobody yet', 'admin:' . $me['id'] => 'Me']);
        return ['title' => 'Risks', 'secs' => $secs];
    }

    /** @param array<int,object> $byId */
    public static function riskSections(array $risks, array $byId, bool $withEvent): array
    {
        $secs = [];
        foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $lvl => $h) {
            $items = [];
            foreach ($risks as $r) {
                if ($r->level !== $lvl) continue;
                $e = $byId[(int) $r->event_id] ?? null;
                $owner = Plan::name($r->owner_kind, $r->owner_id !== null ? (int) $r->owner_id : null);
                $items[] = ['t' => (string) $r->title, 'dot' => $lvl === 'high' ? 'bad' : ($lvl === 'medium' ? 'warn' : null),
                            'sub' => implode(' · ', array_filter([$withEvent && $e ? (string) $e->title : '', strtolower(Plan::LIKELIHOOD[$r->likelihood] ?? ''),
                                     strtolower(Plan::IMPACT[$r->impact] ?? ''), $owner, (string) ($r->plan ?? '')])),
                            'chip' => [$h, $lvl === 'high' ? 'bad' : ($lvl === 'medium' ? 'warn' : 'mute')],
                            'cta' => ['label' => 'Close', 'post' => ['do' => 'risk_close', 'risk' => $r->id], 'confirm' => 'Close this risk? It leaves the list.']];
            }
            if ($items) $secs[] = ['k' => 'rows', 'h' => $h, 'items' => $items, 'box' => $lvl === 'high'];
        }
        return $secs;
    }

    /** @param array<string,string> $events @param array<string,string> $owners */
    public static function riskForm(array $events, array $owners, ?int $eventId = null): array
    {
        $where = $eventId ? [] : [['name' => 'event', 'label' => 'Event', 'type' => 'select', 'options' => $events, 'required' => true]];
        return ['k' => 'form', 'h' => 'Add a risk', 'do' => 'risk_add', 'submit' => 'Add the risk', 'hidden' => $eventId ? ['event' => $eventId] : [],
                'groups' => [
                    ['label' => 'What could happen', 'fields' => array_merge($where, [['name' => 'title', 'label' => 'The risk', 'type' => 'text', 'required' => true,
                                                                                    'placeholder' => 'Rain at the entrance']])],
                    ['label' => 'How likely, how bad', 'help' => 'The level is worked out from these two, so High means the same on every screen.',
                     'fields' => [['name' => 'likelihood', 'label' => 'Likelihood', 'type' => 'select', 'options' => Plan::LIKELIHOOD, 'value' => 'possible'],
                                  ['name' => 'impact', 'label' => 'Impact', 'type' => 'select', 'options' => Plan::IMPACT, 'value' => 'medium']]],
                    ['label' => 'Who watches it', 'fields' => [['name' => 'owner', 'label' => 'Owner', 'type' => 'select', 'options' => $owners],
                                                               ['name' => 'plan', 'label' => 'The plan', 'type' => 'text', 'placeholder' => 'Canopy quoted ₦120,000']]],
                ]];
    }

    private static function approvals(array $me, array $events): array
    {
        $byId = self::byId($events);
        $limit = Plan::limitMinor();
        $lines = Plan::lines(self::ids($events));
        $secs = [];
        $finance = [];
        foreach ($lines as $l) {
            if ($l->status !== 'requested' || !$l->request_id) continue;
            $r = Requests::find((int) $l->request_id);
            $finance[] = ['t' => (string) $l->title, 'sub' => $byId[(int) $l->event_id]->title . ' · ' . Plan::naira((int) $l->estimate_minor) . ($r ? ' · asked ' . self::ago((string) $r->created_at) : ''),
                          'chip' => [$r && $r->status === 'needs_info' ? 'Needs info' : 'With finance', $r && $r->status === 'needs_info' ? 'info' : 'warn'],
                          'href' => self::BASE . '/requests?r=' . $l->request_id,
                          // Remind finance (§5): only the person who asked, and only while it waits.
                          'cta' => $r && $r->status === 'waiting' && $r->from_kind === 'admin' && (int) $r->from_id === $me['id']
                              ? ['label' => 'Remind finance', 'post' => ['do' => 'request_remind', 'request' => $r->id]] : null];
        }
        if ($finance) $secs[] = ['k' => 'rows', 'h' => 'Waiting on finance', 'items' => $finance];
        $under = $over = [];
        foreach ($lines as $l) {
            if ($l->status !== 'proposed') continue;
            $who = Plan::name($l->proposed_kind, $l->proposed_id !== null ? (int) $l->proposed_id : null);
            $row = ['t' => (string) $l->title, 'sub' => $byId[(int) $l->event_id]->title . ' · ' . Plan::naira((int) $l->estimate_minor) . ($who !== '' ? ' · ' . $who : '')];
            if ((int) $l->estimate_minor <= $limit) {
                $under[] = $row + ['cta' => ['label' => 'Approve', 'post' => ['do' => 'line_approve', 'line' => $l->id], 'primary' => true]];
            } else {
                $over[] = $row + ['cta' => ['label' => 'Ask finance', 'href' => self::eventUrl((int) $l->event_id, 'money') . '?ask=' . $l->id]];
            }
        }
        if ($under) $secs[] = ['k' => 'rows', 'h' => 'You can approve', 'items' => $under];
        if ($over) $secs[] = ['k' => 'rows', 'h' => 'Over your limit', 'items' => $over];
        if (!$secs) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No spend is waiting for a decision. Add a budget line on an event’s Budget page.'];
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'You approve up to ' . Plan::naira($limit) . ' per item. Anything above that is a request to finance, and shows in Requests.'];
        return ['title' => 'Approvals', 'acts' => [['label' => 'Ask to spend', 'href' => self::BASE . '/requests?new=spend', 'primary' => true]], 'secs' => $secs];
    }

    public static function analytics(array $events): array
    {
        $secs = [];
        $now = date('Y-m-d H:i:s');
        $stats = [];
        foreach ($events as $e) {
            $prev = Plan::previousEdition($e);
            // Calendar days, the same count the overview prints ("10 days"), not hours ÷ 24.
            $days = max(0, (int) floor((strtotime(date('Y-m-d', (int) strtotime((string) $e->event_date))) - strtotime(date('Y-m-d'))) / 86400));
            if ($prev) {
                $then = date('Y-m-d H:i:s', (int) strtotime((string) $prev->event_date) - $days * 86400);
                $rows = [];
                $a = Plan::ticketsBy((int) $prev->id, $then);
                $b = Plan::ticketsBy((int) $e->id, $now);
                $rows[] = ['t' => 'Tickets sold', 'sub' => number_format($a) . ' → ' . number_format($b), 'chip' => self::change($a, $b, false)];
                $fa = Plan::forecastBy((int) $prev->id, $then);
                $fb = Plan::forecastBy((int) $e->id, $now);
                if ($fa !== null && $fb !== null) $rows[] = ['t' => 'Budget forecast', 'sub' => Plan::naira($fa) . ' → ' . Plan::naira($fb), 'chip' => self::change($fa, $fb, true)];
                $secs[] = ['k' => 'rows', 'box' => true, 'h' => $e->title . ' · against ' . $prev->title . ', ' . $days . ($days === 1 ? ' day' : ' days') . ' out', 'items' => $rows];
            } else {
                $secs[] = ['k' => 'rows', 'h' => (string) $e->title, 'items' => [['t' => 'First edition', 'sub' => trim((string) ($e->series_key ?? '')) === ''
                    ? 'Nothing to compare yet. Name its series on the event’s Overview, and its next edition is compared with this one.'
                    : 'Nothing to compare yet. Its next edition is compared with this one, on the same day before the night.']]];
            }
            $week = Plan::ticketsBy((int) $e->id, $now) - Plan::ticketsBy((int) $e->id, date('Y-m-d H:i:s', time() - 7 * 86400));
            $stats[] = ['l' => 'Ticket sales, last 7 days', 'v' => number_format($week), 's' => (string) $e->title];
        }
        if ($stats) $secs[] = ['k' => 'stats', 'h' => 'This week', 'items' => $stats];
        return ['title' => 'Analytics', 'secs' => $secs];
    }

    private static function people(array $events): array
    {
        $byId = self::byId($events);
        $cap = Plan::rule('lead_week_hours');
        $rows = [];
        foreach (Plan::load(self::ids($events)) as $p) {
            $over = $cap > 0 && $p['hours'] > $cap;
            $names = implode(' and ', array_map(static fn ($id) => (string) $byId[$id]->title, $p['events']));
            $row = ['t' => $p['name'] !== '' ? $p['name'] : 'Someone', 'ini' => Workspace::initials($p['name']),
                    'sub' => $names . ' · ' . self::hours($p['hours']) . ($cap > 0 ? ' of ' . $cap : '') . ' this week · ' . $p['items'] . ($p['items'] === 1 ? ' piece of work' : ' pieces of work')];
            if ($cap > 0) $row += ['pct' => (int) min(100, round($p['hours'] * 100 / $cap)), 'barT' => $over ? 'Over capacity' : self::hours($p['hours']) . ' of ' . $cap . 'h'];
            if ($over) $row += ['chip' => ['Over capacity', 'bad'], 'cta' => ['label' => 'Rebalance', 'href' => self::eventUrl($p['events'][0], 'work') . '?owner=' . $p['key']]];
            $rows[] = $row;
        }
        $secs = [$rows ? ['k' => 'rows', 'h' => 'Across your events', 'items' => $rows]
                       : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nobody has work due this week. Give work an owner and its hours, and their week shows here.']];
        $vol = [];
        foreach ($events as $e) {
            $f = Plan::shiftFill((int) $e->id);
            if (!$f) continue;
            $vol[] = ['t' => (string) $e->title, 'sub' => $f['filled'] . ' of ' . $f['slots'] . ' shifts filled · first ' . Workspace::day($f['first']),
                      'pct' => $f['slots'] > 0 ? (int) round($f['filled'] * 100 / $f['slots']) : 0, 'barT' => $f['filled'] . ' of ' . $f['slots'],
                      'href' => self::eventUrl((int) $e->id, 'people')];
        }
        if ($vol) $secs[] = ['k' => 'rows', 'h' => 'Volunteers', 'items' => $vol];
        return ['title' => 'People', 'secs' => $secs];
    }

    // ── Find (§2.4) ─────────────────────────────────────────────────────────────────

    /**
     * Everything Find can go to, for this lead: their pages, their events and every page and
     * tab of each, open work, their open requests, and the things they can start. The page
     * carries it as data so Find matches as somebody types; the same list answers the search
     * page when there is no script.
     *
     * @return list<array{t:string, sub:string, href:string, kind:string}>
     */
    public static function findItems(array $me, array $events): array
    {
        $out = [];
        foreach (self::ROOT as $k => [$label]) $out[] = ['t' => $label, 'sub' => 'Event lead', 'href' => self::BASE . ($k === 'overview' ? '' : '/' . $k), 'kind' => 'Page'];
        foreach ($events as $e) {
            $id = (int) $e->id;
            $title = (string) $e->title;
            $out[] = ['t' => $title, 'sub' => Workspace::day((string) $e->event_date), 'href' => EventPages::url($id), 'kind' => 'Event'];
            foreach (EventPages::NAV as [, $pages]) {
                foreach ($pages as $k => [$label]) {
                    if ($k !== 'today') $out[] = ['t' => $label, 'sub' => $title, 'href' => EventPages::url($id, $k), 'kind' => 'Page'];
                    foreach (EventPages::TABS[$k] ?? [] as $tk => $tl) {
                        if ($tl !== $label) $out[] = ['t' => $tl, 'sub' => $label . ' · ' . $title, 'href' => EventPages::url($id, $k, $tk), 'kind' => 'Page'];
                    }
                }
            }
            $out[] = ['t' => 'Add work to ' . $title, 'sub' => 'Plan', 'href' => EventPages::url($id, 'plan'), 'kind' => 'Do'];
            $out[] = ['t' => 'Add a risk to ' . $title, 'sub' => 'Plan › Risks', 'href' => EventPages::url($id, 'plan', 'risks'), 'kind' => 'Do'];
            $out[] = ['t' => 'Add a cue to ' . $title, 'sub' => 'The night › Run of show', 'href' => EventPages::url($id, 'night'), 'kind' => 'Do'];
        }
        $byId = self::byId($events);
        foreach (array_slice(Plan::work(self::ids($events), true), 0, 200) as $w) {
            $out[] = ['t' => (string) $w->title, 'sub' => (string) ($byId[(int) $w->event_id]->title ?? ''), 'href' => EventPages::url((int) $w->event_id, 'plan'), 'kind' => 'Work'];
        }
        foreach (Requests::from('admin', $me['id'], 'open') as $r) {
            $out[] = ['t' => (string) $r->title, 'sub' => Requests::STATUSES[$r->status] ?? '', 'href' => self::BASE . '/requests?r=' . $r->id, 'kind' => 'Request'];
        }
        if ($events) $out[] = ['t' => 'Make a request', 'sub' => 'Requests', 'href' => self::BASE . '/requests?new=spend', 'kind' => 'Do'];
        return $out;
    }

    /** The search page: what Find shows, for a browser with no script or for Enter on nothing. */
    public static function search(array $me, array $events, string $q): array
    {
        return \AfricaGates\Services\Shell\Find::page(self::findItems($me, $events), $q);
    }

    /** What Ask offers to start with, for where the lead is. @return list<string> */
    public static function starters(?object $event): array
    {
        if ($event) {
            $t = (string) $event->title;
            return ['What is blocking ' . $t . '?', 'What is late on ' . $t . '?', 'How are ticket sales going for ' . $t . '?'];
        }
        return ['What needs me today?', 'Which of my events is least ready?', 'What is waiting on finance?'];
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    /** An event page's address, by the names the root pages use. */
    public static function eventUrl(int $id, string $page = 'overview'): string
    {
        [$p, $t] = match ($page) {
            'overview' => ['today', ''], 'work' => ['plan', ''], 'risks' => ['plan', 'risks'], 'people' => ['people', 'volunteers'],
            default => [$page, ''],
        };
        return EventPages::url($id, $p, $t);
    }

    /** @param list<object> $events @return list<int> */
    public static function ids(array $events): array
    {
        return array_map(static fn ($e) => (int) $e->id, $events);
    }

    /** @return array<int,object> */
    public static function byId(array $events): array
    {
        $o = [];
        foreach ($events as $e) $o[(int) $e->id] = $e;
        return $o;
    }

    public static function blockerRow(object $w, array $events): array
    {
        $e = self::byId($events)[(int) $w->event_id];
        $owner = Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null);
        $days = (int) floor((time() - strtotime((string) $w->due_at)) / 86400);
        return ['t' => $w->title . ($days < 1 ? ' is late' : ($days === 1 ? ' is a day late' : ' is ' . $days . ' days late')),
                'sub' => implode(' · ', array_filter([(string) $e->title, $owner !== '' ? $owner : 'nobody has it', $w->blocks_note ? $w->blocks_note . ' waits on it' : ''])),
                'dot' => 'bad', 'chip' => ['Blocking', 'bad'], 'cta' => ['label' => 'Open', 'href' => self::eventUrl((int) $e->id, 'work')]];
    }

    public static function dueChip(object $w): ?array
    {
        if ($w->due_at === null) return null;
        if (Plan::isLate($w)) return ['Late', 'bad'];
        $u = Workspace::until((string) $w->due_at);
        return [$u === 'Today' ? 'Today' : ($u === 'Tomorrow' ? 'Tomorrow' : Workspace::day((string) $w->due_at)), $u === 'Today' ? 'warn' : 'mute'];
    }

    /** "in 10 days" / "tomorrow" / "tonight" / "past". */
    public static function daysWords(string $at): string
    {
        $d = (int) floor((strtotime(date('Y-m-d', (int) strtotime($at))) - strtotime(date('Y-m-d'))) / 86400);
        return match (true) { $d < 0 => 'over', $d === 0 => 'today', $d === 1 => 'tomorrow', default => 'in ' . $d . ' days' };
    }

    public static function daysShort(string $at): string
    {
        $d = (int) floor((strtotime(date('Y-m-d', (int) strtotime($at))) - strtotime(date('Y-m-d'))) / 86400);
        return match (true) { $d < 0 => 'Over', $d === 0 => 'Today', $d === 1 => 'Tomorrow', default => $d . ' days' };
    }

    public static function ticketWords(object $e): ?string
    {
        $sold = EventTicketService::soldForEvent((int) $e->id);
        if ($sold <= 0) return null;
        $cap = isset($e->capacity) && $e->capacity ? (int) $e->capacity : 0;
        return number_format($sold) . ($cap > 0 ? ' of ' . number_format($cap) : '') . ' tickets.';
    }

    /** A change against the last edition, as a chip. More spend is a warning, not a win. */
    public static function change(int $then, int $now, bool $spend): ?array
    {
        if ($then <= 0) return null;
        $pct = (int) round(($now - $then) * 100 / $then);
        $tone = $pct === 0 ? 'mute' : (($pct > 0) !== $spend ? 'ok' : 'warn');
        return [($pct > 0 ? '+' : ($pct < 0 ? '−' : '')) . abs($pct) . '%', $tone];
    }

    public static function hours(float $h): string
    {
        return rtrim(rtrim(number_format($h, 1), '0'), '.') . 'h';
    }

    public static function ago(string $at): string
    {
        $s = time() - (int) strtotime($at);
        return match (true) { $s < 3600 => 'just now', $s < 86400 => intdiv($s, 3600) . 'h ago', $s < 172800 => 'yesterday', default => date('j M', (int) strtotime($at)) };
    }

    /** "admin:3" → ['admin', 3] */
    public static function split(string $key): array
    {
        [$k, $id] = array_pad(explode(':', $key, 2), 2, '0');
        return [$k, (int) $id];
    }
}
