<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE VOLUNTEER WORKSPACE'S SHELL CONTEXT (consoles v7 handoff §3 and §6; review slice V1).
 *
 * One call builds everything the shell draws for one person on one page: the rail for their
 * ROLE (§6.1), the "Where you help" scopes from their GRANTS (§6.2), the counts, the tabs, the
 * crumbs, the page header and the page's own sections. `templates/shell/` reads only this, so
 * the rail and the page can never disagree about a count — the review's rule for Home.
 *
 *   · THE ROLE IS COMPUTED, never stored (Volunteers::role): applicant, new, active, lead.
 *   · A SCOPE EXISTS BECAUSE A GRANT DOES. Each live `vol.*` grant at a cycle or an event is
 *     one entry; an expired grant is gone on the next request with nothing scheduled
 *     (Grants::live), and its pages answer 404, never a page that cannot be used.
 *   · NO 0 IS EVER DRAWN (§8): a count of nothing is omitted, not printed.
 *   · Volunteers are member actors. Nothing here links into /admin (§0.4).
 */
final class Workspace
{
    /**
     * THE CONTACT REPORT (owner, 31 Oct 2026: "the report is in several layers … it has to do
     * with contacts made to nominees"). What volunteers mean by "sending my report" is the record
     * of each contact they make with a nominee (Outreach::log, outreach_email), in layers:
     *
     *   1. each contact, logged on the nominee's page        → "Log a contact with a nominee"
     *   2. follow-ups the log set, due today or earlier       → the Nominees page's "Follow up" tab
     *   3. what the team said to each nominee                 → that nominee's page, newest first
     *
     * A volunteer's own list of contacts, a lead's view per volunteer and a staff view per award
     * have no reader yet (31 Oct 2026); the rows exist in gates_vol_outreach for them.
     *
     * Named once here and read by Today, the tabs, Find and the Volunteer academy, so the guide
     * can never name a button the workspace has renamed. Hours logged by hand are a separate,
     * smaller thing (HOURS_LOG): a lead confirms time, not conversations.
     */
    public const REPORT = ['log' => 'Log a contact with a nominee', 'due' => 'Follow up', 'form' => 'Log a contact', 'submit' => 'Log this contact'];

    /** Hours logged by hand for work nobody saw; a lead confirms them. */
    public const HOURS_LOG = ['action' => 'Log hours by hand', 'href' => '/volunteer/hours/log', 'status' => '/volunteer/hours/review'];

    /** The Volunteer academy: the guide, open to anybody signed out so a coordinator can share it. */
    public const GUIDE = ['label' => 'Volunteer academy', 'href' => '/volunteer/academy', 'icon' => 'doc'];

    /** Root pages, their rail label and icon (handoff §6.1, `nav-volunteer.json`). */
    public const ROOT = [
        'home'     => ['Today', 'home'],
        'tasks'    => ['Tasks', 'list'],
        'meetings' => ['Meetings', 'video'],
        'hours'    => ['Hours', 'clock'],
        'training' => ['Training', 'book'],
        'record'   => ['My record', 'star'],
        'team'     => ['My team', 'users'],
        'requests' => ['Requests', 'list'],
    ];

    /** Which root pages each role's rail carries, grouped in the handoff's order. */
    private const RAIL = [
        'active'    => [[null, ['home', 'tasks', 'meetings', 'hours']], ['@where', []], ['Grow', ['training', 'record']], ['You', ['requests', '@guide', '@me']]],
        'lead'      => [[null, ['home', 'tasks', 'meetings', 'hours']], ['@where', []], ['Your team', ['team']], ['Grow', ['training', 'record']], ['You', ['requests', '@guide', '@me']]],
        'new'       => [[null, ['home', 'tasks', 'meetings', 'training']], ['@where', []], ['You', ['requests', '@guide', '@me']]],
        'applicant' => [[null, ['home', 'training']], ['You', ['@guide', '@me']]],
    ];

    /** Tabs per root page (handoff TABS); the first is the default. */
    public const TABS = [
        'tasks'    => ['mine' => 'Mine', 'library' => 'Library', 'done' => 'Done'],
        'hours'    => ['summary' => 'Summary', 'review' => 'In review', 'log' => 'Log hours'],
        'training' => ['next' => 'Next for you', 'passed' => 'Passed'],
        'record'   => ['overview' => 'Overview', 'rewards' => 'Rewards', 'badges' => 'Badges', 'refs' => 'Reference letters'],
        'team'     => ['roster' => 'Roster', 'approvals' => 'To confirm', 'requests' => 'Requests'],
        'requests' => ['open' => 'Open', 'decided' => 'Decided'],
        'meetings' => ['coming' => 'Coming up', 'new' => 'Schedule', 'past' => 'Past'],
    ];

    /** Profile and access (the settings scope, which has no siblings and no identity block). */
    public const ME = [
        'profile'       => ['Profile', 'users', 'You'],
        'availability'  => ['Availability', 'cal', 'You'],
        'notifications' => ['Notifications', 'bell', 'You'],
        'access'        => ['Access', 'shield', 'Access and privacy'],
        'privacy'       => ['Privacy and data', 'lock', 'Access and privacy'],
        'leave'         => ['Take a break or leave', 'flag', 'Access and privacy'],
    ];

    /** Purpose lines, verbatim from the handoff's PURP table. */
    public const PURPOSE = [
        'root.home'      => 'What’s next for you, and how the awards you help on are doing.',
        'root.tasks'     => 'Work you can do from anywhere. Each task says how it’s checked.',
        'root.hours'     => 'Checking in and out at an event, and accepted tasks, count by themselves. Log hours by hand only for work nobody saw; a lead confirms them.',
        'root.training'  => 'Short modules. Each one unlocks roles.',
        'root.record'    => 'Your verified record. It’s yours to keep and to use for references.',
        'root.meetings'  => 'Meet the people you volunteer with, online or in person. Only the people invited see a meeting.',
        'root.team'      => 'The people you lead: their work and the hours to confirm.',
        'root.requests'  => 'Ask for a swap, time off, an hours correction, a role or a travel refund. Each request says who decides and how long it usually takes.',
        'cycle.overview' => 'How this award is doing, where it is, and your part in it.',
        'cycle.tasks'    => 'Tasks on this award.',
        'cycle.access'   => 'What you can see here, what stays hidden, and when it ends.',
        'cycle.nominees' => 'The nominees on this award. Reach one at a time and log each contact, so the team doesn’t call twice. Each contact you log is your report; the team and staff read it.',
        'event.today'    => 'The night, how ready it is, and your part in it.',
        'event.tasks'    => 'Tasks before the night.',
        'event.brief'    => 'What you need on the night.',
        'event.team'     => 'Who’s on with you, and your lead.',
        'event.access'   => 'What you can see here, and when it ends.',
        'me.profile'       => 'How you appear to leads and the people you help.',
        'me.availability'  => 'When and where you can help. Your lead sees this when planning.',
        'me.notifications' => 'What we tell you, and how.',
        'me.access'        => 'Every role you hold, what it lets you see, and when it ends.',
        'me.privacy'       => 'What we keep about you, and how to get a copy.',
        'me.leave'         => 'Pause or step back. Your record stays yours.',
    ];

    /**
     * Scope pages: an event, and the parts of an award edition every grant there has
     * (handoff §6.2). The award's own work is added by {@see scopePages()} from the person's
     * grants: "The menu is built from the grant's capabilities, not the scope type."
     */
    public const SCOPE_PAGES = [
        'cycle' => [[null, ['overview' => ['Overview', 'home']]], ['Your work', ['tasks' => ['Tasks', 'list']]],
                    ['Know', ['guide' => ['Role guide', 'book'], 'access' => ['Your access', 'shield']]]],
        'event' => [[null, ['today' => ['Today', 'home']]], ['Your work', ['tasks' => ['Tasks', 'list']]],
                    ['The night', ['brief' => ['Briefing', 'doc'], 'team' => ['Who’s on', 'users']]], ['Know', ['access' => ['Your access', 'shield']]]],
    ];

    /** Award work each grant adds, in the order the rail shows it (nav-volunteer.json). */
    public const CYCLE_WORK = [
        'vol.nomination_check' => ['queue' => ['Check nominations', 'noms']],
        'vol.voter_drive'      => ['drive' => ['Voter drive', 'vote']],
        Outreach::CAPABILITY   => ['nominees' => ['Nominees', 'contact']],
    ];

    /** Tabs on a place's pages (nav-volunteer.json). */
    public const SCOPE_TABS = [
        'queue'    => ['check' => 'To check', 'done' => 'Done', 'agree' => 'Agreement'],
        'nominees' => ['all' => 'All', 'due' => self::REPORT['due'], 'new' => 'Not reached yet'],
    ];

    /** A place's rail groups, with the award work its grants carry. */
    public static function scopePages(string $kind, array $caps = []): array
    {
        $groups = self::SCOPE_PAGES[$kind];
        if ($kind === 'cycle') {
            $work = [];
            foreach (self::CYCLE_WORK as $cap => $pages) if (in_array($cap, $caps, true)) $work += $pages;
            $groups[1][1] = $work + $groups[1][1];
        }
        return $groups;
    }

    /** Every page a place has, key => [label, icon]. */
    public static function scopePageList(string $kind, array $caps = []): array
    {
        $all = [];
        foreach (self::scopePages($kind, $caps) as [, $pages]) $all += $pages;
        return $all;
    }

    /**
     * The places this person helps: one per live volunteer grant at a cycle or an event.
     * Soonest-ending first.
     *
     * @return list<array{kind:string, id:int, name:string, roles:list<string>, ends:?string, href:string}>
     */
    public static function places(object $v): array
    {
        $out = [];
        foreach (Grants::forActor('member', (int) $v->user_id, 'vol.') as $g) {
            if (!in_array($g->scope_kind, ['cycle', 'event'], true) || !$g->scope_id) continue;
            $k = $g->scope_kind . ':' . $g->scope_id;
            $out[$k] ??= ['kind' => (string) $g->scope_kind, 'id' => (int) $g->scope_id,
                          'name' => Workspace::scopeName((string) $g->scope_kind, (int) $g->scope_id), 'roles' => [], 'caps' => [], 'ends' => (string) $g->expires_at,
                          'href' => '/volunteer/' . $g->scope_kind . '/' . $g->scope_id];
            if (!in_array((string) $g->capability, $out[$k]['caps'], true)) $out[$k]['caps'][] = (string) $g->capability;
            $label = Grants::CAPABILITIES[$g->capability] ?? (string) $g->capability;
            if (!in_array($label, $out[$k]['roles'], true)) $out[$k]['roles'][] = $label;
            if ((string) $g->expires_at > (string) $out[$k]['ends']) $out[$k]['ends'] = (string) $g->expires_at;
        }
        $out = array_values($out);
        usort($out, static fn ($a, $b) => strcmp((string) ($a['ends'] ?? '9999'), (string) ($b['ends'] ?? '9999')));
        return $out;
    }

    /** One place by kind and id, or null when this person has no reason to see it. */
    public static function place(object $v, string $kind, int $id): ?array
    {
        foreach (self::places($v) as $p) if ($p['kind'] === $kind && $p['id'] === $id) return $p;
        return null;
    }

    /**
     * The counts on the rail, each from the same read the page uses. A 0 is left out.
     *
     * @return array<string,array{n:int, tone:string}>
     */
    public static function counts(object $v, string $role): array
    {
        $c = [];
        $add = static function (string $k, int $n, string $tone = 'mute') use (&$c): void { if ($n > 0) $c[$k] = ['n' => $n, 'tone' => $tone]; };
        if ($role === 'applicant') return [];
        $add('tasks', count(Tasks::mine($v)));
        $add('hours', (int) DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'review')->count());
        $add('training', count(array_filter(Training::next($v), static fn ($m) => $m->tone === 'warn')), 'warn');
        $add('requests', count(array_filter(Requests::from('member', (int) $v->user_id, 'open', 'volunteer'), static fn ($r) => $r->status === 'needs_info')), 'warn');
        $add('meetings', count(array_filter(Meetings::mine($v), static fn ($m) => $m->answer === 'invited')), 'warn');
        if ($role === 'lead') $add('team', count(Team::waitingHours($v)), 'warn');
        // Nominations waiting for this person's check, on the award they check for.
        foreach (self::places($v) as $p) {
            if ($p['kind'] !== 'cycle' || !in_array(NominationChecks::CAPABILITY, $p['caps'], true)) continue;
            $k = '@cycle:' . $p['id'];
            $n = count(NominationChecks::queue($v, (int) $p['id']));
            if ($n > 0 && !isset($c[$k])) $c[$k] = ['n' => $n, 'tone' => 'warn'];
        }
        // Follow-ups due on the awards this person reaches nominees on, added to the same count:
        // the award's entry in the rail says how much on it is waiting for them, whatever kind.
        foreach (Outreach::awards($v) as $cid => $_) {
            $n = count(Outreach::nominees($v, $cid, '', 'due'));
            if ($n > 0) $c['@cycle:' . $cid] = ['n' => ($c['@cycle:' . $cid]['n'] ?? 0) + $n, 'tone' => 'warn'];
        }
        return $c;
    }

    /**
     * The rail for the root and for Profile and access.
     *
     * @return list<array{label:?string, items:list<array>}>
     */
    public static function rootNav(object $v, string $role, string $current, array $counts): array
    {
        $groups = [];
        foreach (self::RAIL[$role] ?? self::RAIL['new'] as [$label, $keys]) {
            if ($label === '@where') {
                $items = [];
                foreach (self::places($v) as $p) {
                    $k = '@' . $p['kind'] . ':' . $p['id'];
                    $items[] = ['key' => $k, 'label' => $p['name'], 'icon' => $p['kind'] === 'event' ? 'ticket' : 'trophy', 'href' => $p['href'],
                                'current' => false, 'drill' => true, 'count' => $counts[$k] ?? null];
                }
                if ($items !== []) $groups[] = ['label' => 'Where you help', 'items' => $items];   // no grants: hidden (§8)
                continue;
            }
            $items = [];
            foreach ($keys as $k) {
                if ($k === '@guide') {
                    // The guide leaves the workspace for a public page, so it is a link and never "current".
                    $items[] = ['key' => 'guide', 'label' => self::GUIDE['label'], 'icon' => self::GUIDE['icon'], 'href' => self::GUIDE['href'], 'current' => false, 'drill' => false, 'count' => null];
                    continue;
                }
                if ($k === '@me') {
                    $items[] = ['key' => 'me', 'label' => 'Profile and access', 'icon' => 'gear', 'href' => '/volunteer/me', 'current' => false, 'drill' => true, 'count' => null];
                    continue;
                }
                $label2 = $k === 'home' && $role === 'applicant' ? 'Application' : self::ROOT[$k][0];
                $items[] = ['key' => $k, 'label' => $label2, 'icon' => $k === 'home' && $role === 'applicant' ? 'noms' : self::ROOT[$k][1],
                            'href' => $k === 'home' ? '/volunteer' : '/volunteer/' . $k, 'current' => $k === $current, 'drill' => false,
                            'count' => $counts[$k] ?? null];
            }
            $groups[] = ['label' => $label, 'items' => $items];
        }
        return $groups;
    }

    /** The rail inside a scope (cycle, event) or inside Profile and access. */
    public static function scopeNav(string $kind, int $id, string $current, array $caps = [], array $counts = []): array
    {
        if ($kind === 'me') {
            $groups = [];
            foreach (self::ME as $k => [$label, $icon, $group]) {
                $groups[$group] ??= ['label' => $group, 'items' => []];
                $groups[$group]['items'][] = ['key' => $k, 'label' => $label, 'icon' => $icon, 'href' => '/volunteer/me/' . $k,
                                              'current' => $k === $current, 'drill' => false, 'count' => null];
            }
            return array_values($groups);
        }
        $groups = [];
        foreach (self::scopePages($kind, $caps) as [$label, $pages]) {
            $items = [];
            foreach ($pages as $k => [$l, $icon]) {
                $items[] = ['key' => $k, 'label' => $l, 'icon' => $icon, 'href' => '/volunteer/' . $kind . '/' . $id . ($k === array_key_first(self::SCOPE_PAGES[$kind][0][1]) ? '' : '/' . $k),
                            'current' => $k === $current, 'drill' => false, 'count' => $counts[$k] ?? null];
            }
            $groups[] = ['label' => $label, 'items' => $items];
        }
        return $groups;
    }

    /**
     * Everything Find can go to for this person (§2.4): the pages their role's rail carries and
     * those pages' tabs, each place they help and its pages, Profile and access, their meetings,
     * contacts, open requests and training to do, and the things they can start. Built from the
     * same reads as the rail, so Find never offers a page the workspace would refuse.
     *
     * @return list<array{t:string, sub:string, href:string, kind:string}>
     */
    public static function findItems(object $v, string $role): array
    {
        $out = [];
        foreach (self::rootNav($v, $role, '', []) as $g) {
            foreach ($g['items'] as $i) {
                if (str_starts_with((string) $i['key'], '@')) continue;   // places are listed below, with their pages
                $out[] = ['t' => (string) $i['label'], 'sub' => 'My volunteering', 'href' => (string) $i['href'], 'kind' => 'Page'];
                foreach (self::TABS[$i['key']] ?? [] as $tk => $tl) {
                    if ($tk === array_key_first(self::TABS[$i['key']])) continue;
                    $out[] = ['t' => $tl, 'sub' => (string) $i['label'], 'href' => $i['href'] . '/' . $tk, 'kind' => 'Page'];
                }
            }
        }
        if ($role !== 'applicant') {
            foreach (self::places($v) as $p) {
                $out[] = ['t' => $p['name'], 'sub' => $p['roles'] ? implode(', ', $p['roles']) : 'Where you help', 'href' => $p['href'], 'kind' => 'Place'];
                foreach (self::scopePages($p['kind'], $p['caps']) as $gi => [, $pages]) {
                    foreach ($pages as $k => [$l]) {
                        if ($gi === 0) continue;   // the first page is the place itself, listed above
                        $out[] = ['t' => $l, 'sub' => $p['name'], 'href' => $p['href'] . '/' . $k, 'kind' => 'Page'];
                    }
                }
            }
            foreach (array_slice(Outreach::nominees($v), 0, 300) as $n) {
                $out[] = ['t' => (string) $n->name, 'sub' => $n->award . ' · Nominee', 'href' => '/volunteer/cycle/' . $n->cycle_id . '/nominees?n=' . $n->id, 'kind' => 'Nominee'];
            }
            foreach (Meetings::mine($v) as $m) {
                $out[] = ['t' => (string) $m->title, 'sub' => self::day((string) $m->next) . ' · Meeting', 'href' => '/volunteer/meetings/' . $m->id, 'kind' => 'Meeting'];
            }
            foreach (Requests::from('member', (int) $v->user_id, 'open', 'volunteer') as $r) {
                $out[] = ['t' => (string) $r->title, 'sub' => Requests::STATUSES[$r->status] ?? '', 'href' => '/volunteer/requests/' . $r->id, 'kind' => 'Request'];
            }
            // "Report" is the word volunteers use for their contacts with nominees, so Find answers it
            // per award: the log, and the running report of what they have logged there.
            foreach (Outreach::awards($v) as $cid => $award) {
                $out[] = ['t' => self::REPORT['log'], 'sub' => $award . ' · report a call, message or email', 'href' => '/volunteer/cycle/' . $cid . '/nominees', 'kind' => 'Do'];
                $out[] = ['t' => 'Follow-ups due', 'sub' => $award . ' · contact report, who to reach again', 'href' => '/volunteer/cycle/' . $cid . '/nominees/due', 'kind' => 'Report'];
            }
            $out[] = ['t' => self::HOURS_LOG['action'], 'sub' => 'Hours · for work nobody saw; a lead confirms it', 'href' => self::HOURS_LOG['href'], 'kind' => 'Do'];
            $out[] = ['t' => 'Make a request', 'sub' => 'Requests', 'href' => '/volunteer/requests?new=off', 'kind' => 'Do'];
        }
        foreach (Training::next($v) as $m) {
            $out[] = ['t' => (string) $m->title, 'sub' => 'Training', 'href' => '/volunteer/training/take/' . $m->slug, 'kind' => 'Training'];
        }
        foreach (self::ME as $k => [$l]) $out[] = ['t' => $l, 'sub' => 'Profile and access', 'href' => '/volunteer/me/' . $k, 'kind' => 'Page'];
        $out[] = ['t' => 'How to use this portal', 'sub' => self::GUIDE['label'] . ' · help, guide, step by step', 'href' => self::GUIDE['href'], 'kind' => 'Guide'];
        return $out;
    }

    /**
     * The switcher (§1): the person's other places, each opened on the same page when that
     * kind of place has it, and on its first page when it does not.
     *
     * @return list<array{name:string, sub:string, ini:string, href:string, current:bool}>
     */
    public static function siblings(object $v, string $kind, int $id, string $page): array
    {
        $out = [];
        foreach (self::places($v) as $p) {
            $pages = self::scopePageList($p['kind'], $p['caps']);
            $first = array_key_first(self::SCOPE_PAGES[$p['kind']][0][1]);
            $keep = isset($pages[$page]) && $page !== $first;
            $out[] = ['name' => $p['name'], 'sub' => $p['kind'] === 'event' ? 'Event' : 'Award', 'ini' => self::initials($p['name']),
                      'href' => $p['href'] . ($keep ? '/' . $page : ''), 'current' => $p['kind'] === $kind && $p['id'] === $id];
        }
        return $out;
    }

    /** The tab bar for a page. @return list<array{key:string,label:string,href:string,current:bool}> */
    public static function tabs(string $base, array $tabs, string $current, array $counts = []): array
    {
        $out = [];
        $first = array_key_first($tabs);
        foreach ($tabs as $k => $label) {
            $out[] = ['key' => $k, 'label' => $label, 'href' => $base . ($k === $first ? '' : '/' . $k), 'current' => $k === $current,
                      'count' => ($counts[$k] ?? 0) > 0 ? (int) $counts[$k] : null];
        }
        return $out;
    }

    /** Two letters for a tile: "Alimosho Community Awards" → "AC". */
    public static function initials(string $name): string
    {
        $w = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [], static fn ($x) => $x !== '' && !in_array(mb_strtolower($x), ['the', 'of', 'and', 'for'], true)));
        $out = '';
        foreach (array_slice($w, 0, 2) as $x) $out .= mb_strtoupper(mb_substr($x, 0, 1));
        return $out !== '' ? $out : 'AG';
    }

    /** A place in words: an event's title, or an award edition as "Programme · 2026". */
    public static function scopeName(string $kind, int $id): string
    {
        if ($kind === 'event') return (string) (DB::table('gates_site_events')->where('id', $id)->value('title') ?? 'Event');
        if ($kind === 'cycle') {
            $c = DB::table('gates_award_cycles as c')->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.id', $id)->first(['p.title', 'c.edition_label', 'c.year']);
            if ($c) return trim((string) $c->title . ' · ' . ((string) ($c->edition_label ?: $c->year)));
        }
        return 'Africa GATES';
    }

    /** "Sat 31 Oct", "10:00 to 14:00" — the handoff's date words. */
    public static function day(?string $at): string
    {
        return $at ? date('D j M', (int) strtotime($at)) : '';
    }

    public static function span(?string $a, ?string $b): string
    {
        if (!$a) return '';
        $s = date('H:i', (int) strtotime($a));
        return $b ? $s . ' to ' . date('H:i', (int) strtotime($b)) : $s;
    }

    /** "In 3 days", "Today", "Tomorrow". */
    public static function until(?string $at): string
    {
        if (!$at) return '';
        $d = (int) floor((strtotime(date('Y-m-d', (int) strtotime($at))) - strtotime(date('Y-m-d'))) / 86400);
        return match (true) { $d < 0 => 'Past', $d === 0 => 'Today', $d === 1 => 'Tomorrow', default => 'In ' . $d . ' days' };
    }
}
