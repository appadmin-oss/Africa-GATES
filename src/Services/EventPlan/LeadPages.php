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

    /** An event's own pages (the lead's event scope). */
    public const EVENT = [
        [null, ['overview' => ['Overview', 'home']]],
        ['Plan', ['work' => ['Work', 'list'], 'risks' => ['Risks', 'flag']]],
        ['Money', ['money' => ['Budget', 'card']]],
        ['Team', ['people' => ['People', 'users']]],
    ];

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
        'e.overview' => '',
        'e.work'    => 'Everything that must be done before the night, who has it, and when it’s due.',
        'e.risks'   => 'What could go wrong here, and the plan for each.',
        'e.money'   => 'What this event will spend. You approve up to your limit; finance decides above it.',
        'e.people'  => 'The team on this event, their week, and the volunteer shifts.',
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

    public static function eventNav(int $eventId, string $current, array $counts): array
    {
        $groups = [];
        foreach (self::EVENT as [$label, $pages]) {
            $items = [];
            foreach ($pages as $k => [$l, $icon]) {
                $items[] = ['key' => $k, 'label' => $l, 'icon' => $icon, 'href' => self::eventUrl($eventId, $k), 'current' => $k === $current,
                            'drill' => false, 'count' => $counts[$k] ?? null];
            }
            $groups[] = ['label' => $label, 'items' => $items];
        }
        return $groups;
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

    public static function eventCounts(int $eventId): array
    {
        $c = [];
        $late = count(array_filter(Plan::work([$eventId], true), static fn ($w) => Plan::isLate($w)));
        if ($late > 0) $c['work'] = ['n' => $late, 'tone' => 'bad'];
        $high = count(array_filter(Plan::risks([$eventId]), static fn ($r) => $r->level === 'high'));
        if ($high > 0) $c['risks'] = ['n' => $high, 'tone' => 'bad'];
        $waiting = count(array_filter(Plan::lines([$eventId]), static fn ($l) => $l->status === 'proposed'));
        if ($waiting > 0) $c['money'] = ['n' => $waiting, 'tone' => 'warn'];
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

    private static function requestTable(array $rows, string $empty, string $foot): array
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
                     'withdraw' => 'withdrew it', 'undo' => 'undid the last step'][$e->action] ?? $e->action;
            $log[] = ['time' => date('j M', (int) strtotime((string) $e->created_at)), 't' => $who . ' ' . $verb, 'd' => (string) ($e->note ?? ''), 'st' => 'done'];
        }
        if ($log) $secs[] = ['k' => 'agenda', 'h' => 'Activity', 'items' => $log];
        if (in_array($r->status, Requests::OPEN, true)) {
            $opts = $r->status === 'needs_info' ? [['add_info', 'Add info', true], ['withdraw', 'Withdraw', false]] : [['withdraw', 'Withdraw', false]];
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
    private static function deadlineItems(array $events, bool $withUndated = false): array
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
    private static function riskSections(array $risks, array $byId, bool $withEvent): array
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
    private static function riskForm(array $events, array $owners, ?int $eventId = null): array
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
                          'href' => self::BASE . '/requests?r=' . $l->request_id];
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

    private static function analytics(array $events): array
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

    // ── an event's own pages ─────────────────────────────────────────────────────────────

    public static function event(array $me, object $e, string $page, array $q): array
    {
        $id = (int) $e->id;
        $team = Plan::team($id);
        $owners = ['' => 'Nobody yet'];
        foreach ($team as $p) $owners[$p['key']] = $p['name'] !== '' ? $p['name'] : $p['role'];
        return match ($page) {
            'work' => self::eventWork($e, $owners, (string) ($q['owner'] ?? '')),
            'risks' => self::eventRisks($e, $owners),
            'money' => self::eventMoney($e, (int) ($q['ask'] ?? 0)),
            'people' => self::eventPeople($e, $team),
            default => self::eventOverview($e),
        };
    }

    private static function eventOverview(object $e): array
    {
        $id = (int) $e->id;
        $r = Plan::readiness($id);
        $blockers = Plan::blockers([$id]);
        $risks = Plan::risks([$id]);
        $fill = Plan::shiftFill($id);
        $chips = [[self::daysShort((string) $e->event_date), strtotime((string) $e->event_date) - time() < 14 * 86400 ? 'warn' : 'mute']];
        if ($blockers) $chips[] = [count($blockers) === 1 ? '1 blocker' : count($blockers) . ' blockers', 'bad'];
        $facts = [
            ['Ready', $r ? $r['pct'] . '% · ' . $r['done'] . ' of ' . $r['all'] . ' done' : 'No plan yet'],
            ['Budget', Plan::budgetWords($e) ?? 'No spend planned'],
            ['Tickets', self::ticketWords($e) ?? 'None sold yet'],
            ['Open risks', $risks ? (string) count($risks) : 'None'],
        ];
        if ($fill) $facts[] = ['Volunteers', $fill['filled'] . ' of ' . $fill['slots'] . ' shifts filled'];
        $secs = [['k' => 'hero', 'ini' => Workspace::initials((string) $e->title), 'tone' => 'ok', 'chips' => $chips, 't' => (string) $e->title,
                  'sub' => trim(implode(', ', array_filter([(string) ($e->venue ?? ''), (string) ($e->location ?? '')]))) . ' · ' . date('D j M, H:i', (int) strtotime((string) $e->event_date)),
                  'facts' => $facts, 'acts' => [['label' => 'Add work', 'href' => self::eventUrl($id, 'work'), 'primary' => true], ['label' => 'Add a risk', 'href' => self::eventUrl($id, 'risks')]]]];
        if ($blockers) $secs[] = ['k' => 'rows', 'h' => 'Blocking the night', 'box' => true, 'items' => array_map(fn ($w) => self::blockerRow($w, [$e]), $blockers)];
        $secs[] = ['k' => 'form', 'h' => 'Event settings', 'do' => 'event_settings', 'hidden' => ['event' => $id], 'submit' => 'Save', 'groups' => [
            ['label' => 'Budget', 'help' => 'What the event may spend. The forecast is compared with it.',
             'fields' => [['name' => 'budget', 'label' => 'Budget (₦)', 'type' => 'text', 'value' => isset($e->budget_minor) && $e->budget_minor !== null ? (string) intdiv((int) $e->budget_minor, 100) : '',
                           'placeholder' => '6,000,000']]],
            ['label' => 'Series', 'help' => 'The name its editions share, so Analytics can compare this one with the last.',
             'fields' => [['name' => 'series', 'label' => 'Series', 'type' => 'text', 'value' => (string) ($e->series_key ?? ''), 'placeholder' => 'alimosho-ceremony']]],
        ]];
        return ['title' => (string) $e->title, 'noHead' => true, 'secs' => $secs];
    }

    /** @param array<string,string> $owners */
    private static function eventWork(object $e, array $owners, string $ownerFilter): array
    {
        $id = (int) $e->id;
        $all = Plan::work([$id]);
        if ($ownerFilter !== '') $all = array_values(array_filter($all, static fn ($w) => ($w->owner_kind . ':' . $w->owner_id) === $ownerFilter));
        $lanes = ['late' => [], 'todo' => [], 'doing' => [], 'done' => []];
        foreach ($all as $w) $lanes[Plan::isLate($w) ? 'late' : $w->status][] = $w;
        $secs = [];
        if ($ownerFilter !== '') {
            $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'Showing ' . (Plan::name(...self::split($ownerFilter)) ?: 'one person') . '’s work. Give some of it to somebody else to lighten their week.',
                       'cta' => ['label' => 'Show everybody’s', 'href' => self::eventUrl($id, 'work')]];
        }
        $next = ['todo' => ['Start', 'doing'], 'doing' => ['Done', 'done'], 'done' => ['Reopen', 'todo']];
        foreach (['late' => 'Late', 'todo' => 'Not started', 'doing' => 'In progress', 'done' => 'Done'] as $lane => $h) {
            $items = [];
            foreach (array_slice($lanes[$lane], 0, $lane === 'done' ? 20 : 200) as $w) {
                $owner = Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null);
                [$label, $to] = $next[$w->status];
                $items[] = ['t' => (string) $w->title, 'dot' => $lane === 'late' ? 'bad' : null,
                            'sub' => implode(' · ', array_filter([$owner !== '' ? $owner : 'Nobody yet', $w->due_at ? 'due ' . Workspace::day((string) $w->due_at) : 'no date',
                                     $w->est_hours ? self::hours((float) $w->est_hours) : '', (int) $w->blocks_night === 1 ? ($w->blocks_note ? $w->blocks_note . ' waits on it' : 'the night waits on it') : ''])),
                            'chip' => $lane === 'late' ? ['Late', 'bad'] : null,
                            'cta' => ['label' => $label, 'post' => ['do' => 'work_status', 'work' => $w->id, 'status' => $to], 'primary' => $to === 'done'],
                            'form' => $lane !== 'done' && count($owners) > 1 ? ['id' => $w->id, 'do' => 'work_owner', 'hidden' => ['work' => $w->id], 'submit' => 'Give it',
                                'fields' => [['name' => 'owner', 'label' => 'Owner', 'type' => 'select', 'options' => $owners, 'value' => $w->owner_kind ? $w->owner_kind . ':' . $w->owner_id : '']]] : null];
            }
            if ($items) $secs[] = ['k' => 'rows', 'h' => $h, 'items' => $items, 'box' => $lane === 'late'];
        }
        if (!$all) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing is planned for this event yet. Add the first piece of work below; readiness is counted from it.'];
        $secs[] = ['k' => 'form', 'h' => 'Add work', 'do' => 'work_add', 'hidden' => ['event' => $id], 'submit' => 'Add it', 'groups' => [
            ['label' => 'What', 'fields' => [['name' => 'title', 'label' => 'What has to be done', 'type' => 'text', 'required' => true, 'placeholder' => 'Venue floor plan'],
                                              ['name' => 'detail', 'label' => 'Details', 'type' => 'textarea']]],
            ['label' => 'Who and when', 'help' => count($owners) > 1 ? 'Work with nobody on it goes to the leads’ inboxes.' : 'Only you are on this event so far. Work with nobody on it goes to your inbox.',
             'fields' => [['name' => 'owner', 'label' => 'Owner', 'type' => 'select', 'options' => $owners],
                          ['name' => 'due_at', 'label' => 'Due', 'type' => 'date'],
                          ['name' => 'est_hours', 'label' => 'Hours it should take', 'type' => 'number', 'min' => 0, 'max' => 500, 'step' => '0.5']]],
            ['label' => 'Does the night wait on it?', 'help' => 'Late work the night waits on shows as blocking, at the top of every overview.',
             'fields' => [['name' => 'blocks_night', 'label' => 'The night waits on this', 'type' => 'switch'],
                          ['name' => 'blocks_note', 'label' => 'What waits on it', 'type' => 'text', 'placeholder' => 'the safety permit']]],
        ]];
        return ['title' => 'Work', 'secs' => $secs];
    }

    /** @param array<string,string> $owners */
    private static function eventRisks(object $e, array $owners): array
    {
        $secs = self::riskSections(Plan::risks([(int) $e->id]), [(int) $e->id => $e], false);
        if (!$secs) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'No open risks here. Add one when something could stop the night.'];
        $secs[] = self::riskForm([], $owners, (int) $e->id);
        return ['title' => 'Risks', 'secs' => $secs];
    }

    private static function eventMoney(object $e, int $ask): array
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
                       'route' => 'Goes to finance, ' . ((Requests::type('spend', 'lead')['usually'] ?? 'usually decided within a day')) . '. Where: ' . $e->title . '.',
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
            $row = ['t' => (string) $l->title, 'sub' => implode(' · ', $figs), 'chip' => [Plan::LINE_STATUSES[$l->status] ?? $l->status, $tone[$l->status] ?? 'mute']];
            if ($l->status === 'proposed' && (int) $l->estimate_minor <= $limit) {
                $row['cta'] = ['label' => 'Approve', 'post' => ['do' => 'line_approve', 'line' => $l->id], 'primary' => true];
            } elseif (in_array($l->status, ['proposed', 'declined'], true)) {
                $row['cta'] = ['label' => 'Ask finance', 'href' => self::eventUrl($id, 'money') . '?ask=' . $l->id];
            } elseif ($l->status === 'requested' && $l->request_id) {
                $row['cta'] = ['label' => 'Open request', 'href' => self::BASE . '/requests?r=' . $l->request_id];
            } elseif ($l->status === 'approved') {
                $row['form'] = ['id' => $l->id, 'do' => 'line_spend', 'hidden' => ['line' => $l->id], 'submit' => 'Record', 'fields' => [
                    ['name' => 'committed', 'label' => 'Committed (₦)', 'value' => (int) $l->committed_minor > 0 ? (string) intdiv((int) $l->committed_minor, 100) : ''],
                    ['name' => 'paid', 'label' => 'Paid (₦)', 'value' => (int) $l->paid_minor > 0 ? (string) intdiv((int) $l->paid_minor, 100) : '']]];
            }
            $items[] = $row;
        }
        $secs[] = $items ? ['k' => 'rows', 'h' => 'Budget lines', 'items' => $items]
                         : ['k' => 'note', 'tone' => 'mute', 'text' => 'No spend is planned for this event yet.'];
        $secs[] = ['k' => 'form', 'h' => 'Add a budget line', 'do' => 'line_add', 'hidden' => ['event' => $id], 'submit' => 'Add the line', 'groups' => [
            ['label' => 'What and how much', 'help' => 'It is added unapproved. You approve up to ' . Plan::naira($limit) . '; above that you ask finance.',
             'fields' => [['name' => 'title', 'label' => 'What the money is for', 'type' => 'text', 'required' => true, 'placeholder' => 'Volunteer transport'],
                          ['name' => 'estimate', 'label' => 'Estimate (₦)', 'type' => 'text', 'required' => true, 'placeholder' => '45,000']]],
        ]];
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing here pays anybody. Payments still go through the gateway; this is the plan and the record of what was agreed.'];
        return ['title' => 'Budget', 'secs' => $secs];
    }

    private static function eventPeople(object $e, array $team): array
    {
        $id = (int) $e->id;
        $cap = Plan::rule('lead_week_hours');
        $load = [];
        foreach (Plan::load([$id]) as $p) $load[$p['key']] = $p;
        $rows = [];
        foreach ($team as $p) {
            $h = $load[$p['key']]['hours'] ?? 0.0;
            $row = ['t' => $p['name'] !== '' ? $p['name'] : 'Someone', 'ini' => Workspace::initials($p['name']), 'sub' => $p['role'] . ($h > 0 ? ' · ' . self::hours($h) . ' due this week here' : '')];
            if ($h > 0 && $cap > 0) $row += ['pct' => (int) min(100, round($h * 100 / $cap)), 'barT' => self::hours($h) . ' of ' . $cap . 'h'];
            if ($h > $cap && $cap > 0) $row += ['chip' => ['Over capacity', 'bad'], 'cta' => ['label' => 'Rebalance', 'href' => self::eventUrl($id, 'work') . '?owner=' . $p['key']]];
            $rows[] = $row;
        }
        $secs = [['k' => 'rows', 'h' => 'The team', 'items' => $rows]];
        $f = Plan::shiftFill($id);
        $secs[] = $f
            ? ['k' => 'rows', 'h' => 'Volunteer shifts', 'items' => [['t' => $f['filled'] . ' of ' . $f['slots'] . ' shifts filled', 'sub' => 'First shift ' . Workspace::day($f['first']),
                'pct' => $f['slots'] > 0 ? (int) round($f['filled'] * 100 / $f['slots']) : 0, 'barT' => $f['filled'] . ' of ' . $f['slots']]]]
            : ['k' => 'note', 'tone' => 'mute', 'text' => 'No volunteer shifts are posted for this event yet. Staff post them in Team › Volunteers.'];
        $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'The team is everybody holding a role on this event. Access for somebody new is a request.',
                   'cta' => ['label' => 'Ask for access', 'href' => self::BASE . '/requests?new=access&event=' . $id]];
        return ['title' => 'People', 'secs' => $secs];
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    public static function eventUrl(int $id, string $page = 'overview'): string
    {
        return self::BASE . '/event/' . $id . ($page === 'overview' ? '' : '/' . $page);
    }

    /** @param list<object> $events @return list<int> */
    public static function ids(array $events): array
    {
        return array_map(static fn ($e) => (int) $e->id, $events);
    }

    /** @return array<int,object> */
    private static function byId(array $events): array
    {
        $o = [];
        foreach ($events as $e) $o[(int) $e->id] = $e;
        return $o;
    }

    private static function blockerRow(object $w, array $events): array
    {
        $e = self::byId($events)[(int) $w->event_id];
        $owner = Plan::name($w->owner_kind, $w->owner_id !== null ? (int) $w->owner_id : null);
        $days = (int) floor((time() - strtotime((string) $w->due_at)) / 86400);
        return ['t' => $w->title . ($days < 1 ? ' is late' : ($days === 1 ? ' is a day late' : ' is ' . $days . ' days late')),
                'sub' => implode(' · ', array_filter([(string) $e->title, $owner !== '' ? $owner : 'nobody has it', $w->blocks_note ? $w->blocks_note . ' waits on it' : ''])),
                'dot' => 'bad', 'chip' => ['Blocking', 'bad'], 'cta' => ['label' => 'Open', 'href' => self::eventUrl((int) $e->id, 'work')]];
    }

    private static function dueChip(object $w): ?array
    {
        if ($w->due_at === null) return null;
        if (Plan::isLate($w)) return ['Late', 'bad'];
        $u = Workspace::until((string) $w->due_at);
        return [$u === 'Today' ? 'Today' : ($u === 'Tomorrow' ? 'Tomorrow' : Workspace::day((string) $w->due_at)), $u === 'Today' ? 'warn' : 'mute'];
    }

    /** "in 10 days" / "tomorrow" / "tonight" / "past". */
    private static function daysWords(string $at): string
    {
        $d = (int) floor((strtotime(date('Y-m-d', (int) strtotime($at))) - strtotime(date('Y-m-d'))) / 86400);
        return match (true) { $d < 0 => 'over', $d === 0 => 'today', $d === 1 => 'tomorrow', default => 'in ' . $d . ' days' };
    }

    private static function daysShort(string $at): string
    {
        $d = (int) floor((strtotime(date('Y-m-d', (int) strtotime($at))) - strtotime(date('Y-m-d'))) / 86400);
        return match (true) { $d < 0 => 'Over', $d === 0 => 'Today', $d === 1 => 'Tomorrow', default => $d . ' days' };
    }

    private static function ticketWords(object $e): ?string
    {
        $sold = EventTicketService::soldForEvent((int) $e->id);
        if ($sold <= 0) return null;
        $cap = isset($e->capacity) && $e->capacity ? (int) $e->capacity : 0;
        return number_format($sold) . ($cap > 0 ? ' of ' . number_format($cap) : '') . ' tickets.';
    }

    /** A change against the last edition, as a chip. More spend is a warning, not a win. */
    private static function change(int $then, int $now, bool $spend): ?array
    {
        if ($then <= 0) return null;
        $pct = (int) round(($now - $then) * 100 / $then);
        $tone = $pct === 0 ? 'mute' : (($pct > 0) !== $spend ? 'ok' : 'warn');
        return [($pct > 0 ? '+' : ($pct < 0 ? '−' : '')) . abs($pct) . '%', $tone];
    }

    private static function hours(float $h): string
    {
        return rtrim(rtrim(number_format($h, 1), '0'), '.') . 'h';
    }

    private static function ago(string $at): string
    {
        $s = time() - (int) strtotime($at);
        return match (true) { $s < 3600 => 'just now', $s < 86400 => intdiv($s, 3600) . 'h ago', $s < 172800 => 'yesterday', default => date('j M', (int) strtotime($at)) };
    }

    /** "admin:3" → ['admin', 3] */
    private static function split(string $key): array
    {
        [$k, $id] = array_pad(explode(':', $key, 2), 2, '0');
        return [$k, (int) $id];
    }
}
