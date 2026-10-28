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
    /** Root pages, their rail label and icon (handoff §6.1, `nav-volunteer.json`). */
    public const ROOT = [
        'home'     => ['Today', 'home'],
        'shifts'   => ['Shifts', 'cal'],
        'tasks'    => ['Tasks', 'list'],
        'hours'    => ['Hours', 'clock'],
        'training' => ['Training', 'book'],
        'record'   => ['My record', 'star'],
        'team'     => ['My team', 'users'],
        'requests' => ['Requests', 'list'],
    ];

    /** Which root pages each role's rail carries, grouped in the handoff's order. */
    private const RAIL = [
        'active'    => [[null, ['home', 'shifts', 'tasks', 'hours']], ['@where', []], ['Grow', ['training', 'record']], ['You', ['requests', '@me']]],
        'lead'      => [[null, ['home', 'shifts', 'tasks', 'hours']], ['@where', []], ['Your team', ['team']], ['Grow', ['training', 'record']], ['You', ['requests', '@me']]],
        'new'       => [[null, ['home', 'shifts', 'tasks', 'training']], ['You', ['requests', '@me']]],
        'applicant' => [[null, ['home', 'training']], ['You', ['@me']]],
    ];

    /** Tabs per root page (handoff TABS); the first is the default. */
    public const TABS = [
        'shifts'   => ['mine' => 'Mine', 'fits' => 'Fits you', 'all' => 'All open', 'past' => 'Past'],
        'tasks'    => ['mine' => 'Mine', 'library' => 'Library', 'done' => 'Done'],
        'hours'    => ['summary' => 'Summary', 'review' => 'In review', 'log' => 'Log hours'],
        'training' => ['next' => 'Next for you', 'passed' => 'Passed'],
        'record'   => ['overview' => 'Overview', 'badges' => 'Badges', 'refs' => 'Reference letters'],
        'team'     => ['roster' => 'Roster', 'approvals' => 'To confirm', 'requests' => 'Requests', 'gaps' => 'Gaps'],
        'requests' => ['open' => 'Open', 'decided' => 'Decided'],
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
        'root.shifts'    => 'Shifts you’re on, and ones that fit your training, time and place.',
        'root.tasks'     => 'Work you can do from anywhere. Each task says how it’s checked.',
        'root.hours'     => 'Most hours log themselves from scans and accepted work. Log by hand only for work nobody saw.',
        'root.training'  => 'Short modules. Each one unlocks roles.',
        'root.record'    => 'Your verified record. It’s yours to keep and to use for references.',
        'root.team'      => 'The people you lead: their shifts, hours to confirm and gaps to fill.',
        'root.requests'  => 'Ask for a swap, time off, an hours correction, a role or a travel refund. Each request says who decides and how long it usually takes.',
        'cycle.overview' => 'How this award is doing, where it is, and your part in it.',
        'cycle.tasks'    => 'Tasks on this award.',
        'cycle.access'   => 'What you can see here, what stays hidden, and when it ends.',
        'event.today'    => 'The night, how ready it is, and your part in it.',
        'event.shifts'   => 'Your shifts at this event.',
        'event.tasks'    => 'Tasks before the night.',
        'event.brief'    => 'What you need on the night.',
        'event.team'     => 'Who’s on with you, and your lead.',
        'event.access'   => 'What you can see here, and when it ends.',
        'me.profile'       => 'How you appear to leads and the people you help.',
        'me.availability'  => 'When and where you can help. Shift suggestions use this.',
        'me.notifications' => 'What we tell you, and how.',
        'me.access'        => 'Every role you hold, what it lets you see, and when it ends.',
        'me.privacy'       => 'What we keep about you, and how to get a copy.',
        'me.leave'         => 'Pause or step back. Your record stays yours.',
    ];

    /** Scope pages: cycle (an award edition) and event (handoff §6.2). */
    public const SCOPE_PAGES = [
        'cycle' => [[null, ['overview' => ['Overview', 'home']]], ['Your work', ['tasks' => ['Tasks', 'list']]], ['Know', ['access' => ['Your access', 'shield']]]],
        'event' => [[null, ['today' => ['Today', 'home']]], ['Your work', ['shifts' => ['Your shifts', 'cal'], 'tasks' => ['Tasks', 'list']]],
                    ['The night', ['brief' => ['Briefing', 'doc'], 'team' => ['Who’s on', 'users']]], ['Know', ['access' => ['Your access', 'shield']]]],
    ];

    /**
     * The places this person helps: one per live volunteer grant at a cycle or an event, plus
     * any event or award they hold a shift at. Soonest-ending first.
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
                          'name' => Shifts::scopeName((string) $g->scope_kind, (int) $g->scope_id), 'roles' => [], 'ends' => (string) $g->expires_at,
                          'href' => '/volunteer/' . $g->scope_kind . '/' . $g->scope_id];
            $label = Grants::CAPABILITIES[$g->capability] ?? (string) $g->capability;
            if (!in_array($label, $out[$k]['roles'], true)) $out[$k]['roles'][] = $label;
            if ((string) $g->expires_at > (string) $out[$k]['ends']) $out[$k]['ends'] = (string) $g->expires_at;
        }
        foreach (Shifts::mine($v) as $s) {
            $k = $s->scope_kind . ':' . $s->scope_id;
            $out[$k] ??= ['kind' => (string) $s->scope_kind, 'id' => (int) $s->scope_id, 'name' => (string) $s->scope_name,
                          'roles' => [], 'ends' => null, 'href' => '/volunteer/' . $s->scope_kind . '/' . $s->scope_id];
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
        $mine = Shifts::mine($v);
        $add('shifts', count(array_filter($mine, static fn ($s) => $s->time_changed)) + count(Shifts::clashes($v)), 'bad');
        $add('tasks', count(Tasks::mine($v)));
        $add('hours', (int) DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'review')->count());
        $add('training', count(array_filter(Training::next($v), static fn ($m) => $m->tone === 'warn')), 'warn');
        $add('requests', count(array_filter(Requests::from('member', (int) $v->user_id, 'open'), static fn ($r) => $r->status === 'needs_info')), 'warn');
        if ($role === 'lead') $add('team', count(Team::waitingHours($v)), 'warn');
        foreach ($mine as $s) {
            $k = '@' . $s->scope_kind . ':' . $s->scope_id;
            if ($s->time_changed) $add($k, ($c[$k]['n'] ?? 0) + 1, 'bad');
        }
        foreach (Shifts::clashes($v) as [$a]) {
            $k = '@' . $a->scope_kind . ':' . $a->scope_id;
            $c[$k] = ['n' => ($c[$k]['n'] ?? 0) + 1, 'tone' => 'bad'];
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
    public static function scopeNav(string $kind, int $id, string $current): array
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
        foreach (self::SCOPE_PAGES[$kind] as [$label, $pages]) {
            $items = [];
            foreach ($pages as $k => [$l, $icon]) {
                $items[] = ['key' => $k, 'label' => $l, 'icon' => $icon, 'href' => '/volunteer/' . $kind . '/' . $id . ($k === array_key_first(self::SCOPE_PAGES[$kind][0][1]) ? '' : '/' . $k),
                            'current' => $k === $current, 'drill' => false, 'count' => null];
            }
            $groups[] = ['label' => $label, 'items' => $items];
        }
        return $groups;
    }

    /**
     * Everything Find can go to for this person (§2.4): the pages their role's rail carries and
     * those pages' tabs, each place they help and its pages, Profile and access, their coming
     * shifts, open requests and training to do, and the things they can start. Built from the
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
                foreach (self::SCOPE_PAGES[$p['kind']] as $gi => [, $pages]) {
                    foreach ($pages as $k => [$l]) {
                        if ($gi === 0) continue;   // the first page is the place itself, listed above
                        $out[] = ['t' => $l, 'sub' => $p['name'], 'href' => $p['href'] . '/' . $k, 'kind' => 'Page'];
                    }
                }
            }
            foreach (Shifts::mine($v) as $s) {
                $out[] = ['t' => (string) $s->title, 'sub' => self::day((string) $s->starts_at) . ' · ' . $s->scope_name, 'href' => '/volunteer/shifts/' . $s->id, 'kind' => 'Shift'];
            }
            foreach (Requests::from('member', (int) $v->user_id, 'open') as $r) {
                $out[] = ['t' => (string) $r->title, 'sub' => Requests::STATUSES[$r->status] ?? '', 'href' => '/volunteer/requests/' . $r->id, 'kind' => 'Request'];
            }
            $out[] = ['t' => 'Log hours', 'sub' => 'Hours', 'href' => '/volunteer/hours/log', 'kind' => 'Do'];
            $out[] = ['t' => 'Make a request', 'sub' => 'Requests', 'href' => '/volunteer/requests?new=swap', 'kind' => 'Do'];
        }
        foreach (Training::next($v) as $m) {
            $out[] = ['t' => (string) $m->title, 'sub' => 'Training', 'href' => '/volunteer/training/take/' . $m->slug, 'kind' => 'Training'];
        }
        foreach (self::ME as $k => [$l]) $out[] = ['t' => $l, 'sub' => 'Profile and access', 'href' => '/volunteer/me/' . $k, 'kind' => 'Page'];
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
            $pages = [];
            foreach (self::SCOPE_PAGES[$p['kind']] as [, $ps]) $pages += $ps;
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
