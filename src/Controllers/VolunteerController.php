<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Requests;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\Volunteer\Attendance;
use AfricaGates\Services\Volunteer\Contacts;
use AfricaGates\Services\Volunteer\Hours;
use AfricaGates\Services\Volunteer\Level;
use AfricaGates\Services\Volunteer\Meetings;
use AfricaGates\Services\Volunteer\NominationChecks;
use AfricaGates\Services\Volunteer\Tasks;
use AfricaGates\Services\Volunteer\Team;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use AfricaGates\Services\Shell\Find;
use AfricaGates\Services\Shell\Updates;
use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Services\Volunteer\WorkspacePages;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * /volunteer — THE VOLUNTEER WORKSPACE (consoles v7 handoff §6; review slice V1).
 *
 * A member area, behind the member session (UserAuthMiddleware), never the admin one: a
 * volunteer is a member actor and nothing here links into /admin (§0.4). Pages are built by
 * Workspace + WorkspacePages and drawn by templates/shell/, which read only the context.
 *
 * Every change is a form that POSTs to /volunteer/act with an `do` verb, then redirects back
 * with a sentence (303, so a refresh never repeats it). Each verb checks the person may do it
 * on the record itself — the service refuses a task that needs training,
 * a request that is not theirs — so a crafted post is answered exactly like a button.
 */
final class VolunteerController
{
    public function __construct(private readonly Twig $view, private readonly UserAccountService $accounts, private readonly ?\AfricaGates\Services\OtpService $mailer = null) {}

    /** GET /volunteer[/{page}[/{tab}]] */
    public function root(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer');
        if (!$v || in_array($v->status, ['declined', 'left'], true)) return $this->to($res, '/volunteer/apply');
        $role = $this->role($v);
        $page = (string) ($args['page'] ?? 'home');
        if (!isset(Workspace::ROOT[$page]) || ($page === 'team' && $role !== 'lead')
            || ($role === 'applicant' && !in_array($page, ['home', 'training'], true))) throw new HttpNotFoundException($req);
        $tabs = Workspace::TABS[$page] ?? [];
        $tab = (string) ($args['tab'] ?? array_key_first($tabs) ?? '');
        if ($tabs && !isset($tabs[$tab])) throw new HttpNotFoundException($req);

        // Find without script, or Enter on nothing chosen: the results, on Today.
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
        if ($page === 'home' && $q !== '') {
            return $this->render($res, $user, $v, $role, Find::page(Workspace::findItems($v, $role), $q) + [
                'scope' => 'root', 'nav' => Workspace::rootNav($v, $role, '', Workspace::counts($v, $role)), 'tabs' => [],
                'crumbs' => [['label' => 'My volunteering', 'href' => '/volunteer'], ['label' => 'Find']], 'findQ' => $q]);
        }
        $content = WorkspacePages::root($v, $role, $page, $tab, $req->getQueryParams() + ['_old' => (array) ($this->take('vol_old') ?? [])]);
        $counts = Workspace::counts($v, $role);
        $tabCounts = match ($page) {
            'tasks' => ['mine' => $counts['tasks']['n'] ?? 0],
            'hours' => ['review' => $counts['hours']['n'] ?? 0], 'training' => ['next' => $counts['training']['n'] ?? 0],
            'team' => ['approvals' => $counts['team']['n'] ?? 0], default => [],
        };
        return $this->render($res, $user, $v, $role, [
            'scope' => 'root', 'nav' => Workspace::rootNav($v, $role, $page, $counts),
            'tabs' => $tabs ? Workspace::tabs('/volunteer/' . $page, $tabs, $tab, $tabCounts) : [],
            'crumbs' => [['label' => 'My volunteering', 'href' => '/volunteer'], ['label' => $content['title']]],
            'purposeKey' => 'root.' . $page,
        ] + $content);
    }

    /** GET /volunteer/contacts/{id} — one contact this volunteer logged, its history and a correction form. */
    public function contact(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer/contacts');
        if (!$v || $this->role($v) === 'applicant') return $this->to($res, '/volunteer');
        $c = WorkspacePages::contact($v, (int) $args['id'], (array) ($this->take('vol_old') ?? []));
        if (!$c) throw new HttpNotFoundException($req);   // another volunteer's, or none: the same answer
        $role = $this->role($v);
        return $this->render($res, $user, $v, $role, ['scope' => 'root', 'nav' => Workspace::rootNav($v, $role, 'contacts', Workspace::counts($v, $role)),
            'crumbs' => [['label' => 'Contacts', 'href' => '/volunteer/contacts'], ['label' => $c['title']]], 'back' => ['label' => 'Contacts', 'href' => '/volunteer/contacts']] + $c);
    }

    /** GET /volunteer/meetings/{id}.ics — one meeting, repeats included, for the person's own calendar app. */
    public function meetingIcs(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer/meetings');
        $m = $v ? Meetings::get($v, (int) $args['id']) : null;
        if (!$m || $m->status !== 'scheduled') throw new HttpNotFoundException($req);
        $host = (string) (parse_url(\AfricaGates\Support\SiteUrl::base($req), PHP_URL_HOST) ?: 'africagates');
        $url = \AfricaGates\Support\SiteUrl::base($req) . '/volunteer/meetings/' . (int) $m->id;
        $lines = ['BEGIN:VEVENT', 'UID:vol-meeting-' . (int) $m->id . '@' . $host, 'DTSTAMP:' . self::icsTime('now'),
            'DTSTART:' . self::icsTime((string) $m->starts_at), 'DTEND:' . self::icsTime(date('Y-m-d H:i:s', (int) strtotime((string) $m->starts_at) + (int) $m->minutes * 60))];
        // Repeats as the calendar's own rule, so one download holds every meeting in the series.
        if ($m->repeats !== 'once') $lines[] = 'RRULE:FREQ=' . ($m->repeats === 'monthly' ? 'MONTHLY' : 'WEEKLY') . ($m->repeats === 'fortnightly' ? ';INTERVAL=2' : '') . ';COUNT=' . (int) $m->times;
        array_push($lines, 'SUMMARY:' . self::icsText((string) $m->title),
            'LOCATION:' . self::icsText($m->mode === 'place' ? (string) $m->location : (string) ($m->meet_url ?? 'Online')),
            'DESCRIPTION:' . self::icsText(trim((string) ($m->agenda ?? '') . "\n\n" . $url)), 'URL:' . $url, 'END:VEVENT');
        $res->getBody()->write(self::calendar('Volunteer meeting', $lines));
        return $res->withHeader('Content-Type', 'text/calendar; charset=utf-8')
                   ->withHeader('Content-Disposition', 'attachment; filename="meeting-' . (int) $m->id . '.ics"')
                   ->withHeader('Cache-Control', 'private, no-store');
    }

    /** GET /volunteer/meetings/{id} — a meeting, for the organiser and the people invited only. */
    public function meeting(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer/meetings');
        if (!$v || $this->role($v) === 'applicant') return $this->to($res, '/volunteer');
        $c = WorkspacePages::meeting($v, (int) $args['id']);
        if (!$c) throw new HttpNotFoundException($req);
        $role = $this->role($v);
        return $this->render($res, $user, $v, $role, ['scope' => 'root', 'nav' => Workspace::rootNav($v, $role, 'meetings', Workspace::counts($v, $role)),
            'crumbs' => [['label' => 'Meetings', 'href' => '/volunteer/meetings'], ['label' => $c['title']]], 'back' => ['label' => 'Meetings', 'href' => '/volunteer/meetings']] + $c);
    }

    /** GET /volunteer/requests/{id} — one request, with what this person may do to it. */
    public function request(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer');
        if (!$v || $this->role($v) === 'applicant') return $this->to($res, '/volunteer');
        $c = WorkspacePages::request($v, (int) $args['id']);
        if (!$c) throw new HttpNotFoundException($req);
        $role = $this->role($v);
        return $this->render($res, $user, $v, $role, ['scope' => 'root', 'nav' => Workspace::rootNav($v, $role, 'requests', Workspace::counts($v, $role)),
            'crumbs' => [['label' => 'Requests', 'href' => '/volunteer/requests'], ['label' => $c['title']]], 'back' => ['label' => 'Requests', 'href' => '/volunteer/requests']] + $c);
    }

    /** GET /volunteer/training/take/{slug} — a module and its check. */
    public function module(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer');
        if (!$v || in_array($v->status, ['declined', 'left'], true)) return $this->to($res, '/volunteer/apply');
        $c = WorkspacePages::module($v, (string) $args['slug']);
        if (!$c) throw new HttpNotFoundException($req);
        $role = $this->role($v);
        return $this->render($res, $user, $v, $role, ['scope' => 'root', 'nav' => Workspace::rootNav($v, $role, 'training', Workspace::counts($v, $role)),
            'crumbs' => [['label' => 'Training', 'href' => '/volunteer/training'], ['label' => $c['title']]], 'back' => ['label' => 'Training', 'href' => '/volunteer/training']] + $c);
    }

    /** GET /volunteer/{kind:cycle|event}/{id}[/{page}[/{tab}]] — a place this person helps. */
    public function place(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer');
        if (!$v || $this->role($v) === 'applicant') return $this->to($res, '/volunteer');
        $kind = (string) $args['kind'];
        $p = Workspace::place($v, $kind, (int) $args['id']);
        if (!$p) throw new HttpNotFoundException($req);   // no grant here: not theirs to see
        $first = array_key_first(Workspace::SCOPE_PAGES[$kind][0][1]);
        $page = (string) ($args['page'] ?? $first);
        // The pages this place has for THIS person: an award's work comes from their grants,
        // so a page their grants do not carry is not there to open.
        $valid = Workspace::scopePageList($kind, $p['caps']);
        if (!isset($valid[$page])) throw new HttpNotFoundException($req);
        $tabs = Workspace::SCOPE_TABS[$page] ?? [];
        $tab = (string) ($args['tab'] ?? array_key_first($tabs) ?? '');
        if ($tabs ? !isset($tabs[$tab]) : isset($args['tab'])) throw new HttpNotFoundException($req);
        $c = WorkspacePages::place($v, $p, $page, $tab, $req->getQueryParams());
        $role = $this->role($v);
        $toCheck = $kind === 'cycle' && in_array(NominationChecks::CAPABILITY, $p['caps'], true) ? count(NominationChecks::queue($v, (int) $p['id'])) : 0;
        $base = $p['href'] . '/' . $page;
        return $this->render($res, $user, $v, $role, [
            'scope' => $kind, 'nav' => Workspace::scopeNav($kind, (int) $p['id'], $page, $p['caps'], $toCheck ? ['queue' => ['n' => $toCheck, 'tone' => 'warn']] : []),
            'tabs' => $tabs ? Workspace::tabs($base, $tabs, $tab, ['check' => $toCheck]) : [],
            'instance' => ['name' => $p['name'], 'sub' => implode(', ', $p['roles']), 'ini' => Workspace::initials($p['name']), 'tone' => $kind === 'event' ? 'ok' : 'info'],
            'siblings' => Workspace::siblings($v, $kind, (int) $p['id'], $page),
            'back' => ['label' => 'Back to my volunteering', 'href' => '/volunteer'],
            'crumbs' => array_values(array_filter([['label' => 'My volunteering', 'href' => '/volunteer'], ['label' => $p['name'], 'href' => $p['href']],
                        $page === $first ? null : ($tabs ? ['label' => $valid[$page][0], 'href' => $base] : ['label' => $valid[$page][0]]),
                        $tabs && $tab !== array_key_first($tabs) ? ['label' => $tabs[$tab]] : null])),
            'kicker' => $page === $first ? '' : $p['name'], 'purposeKey' => $kind . '.' . $page,
        ] + $c);
    }

    /** GET /volunteer/me[/{page}] — Profile and access. */
    public function me(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer');
        if (!$v) return $this->to($res, '/volunteer/apply');
        $page = (string) ($args['page'] ?? 'profile');
        if (!isset(Workspace::ME[$page])) throw new HttpNotFoundException($req);
        $role = $this->role($v);
        return $this->render($res, $user, $v, $role, ['scope' => 'me', 'nav' => Workspace::scopeNav('me', 0, $page),
            'back' => ['label' => 'Back to my volunteering', 'href' => '/volunteer'],
            'crumbs' => [['label' => 'Profile and access', 'href' => '/volunteer/me'], ['label' => Workspace::ME[$page][0]]], 'purposeKey' => 'me.' . $page]
            + WorkspacePages::me($v, $user, $page));
    }

    /** GET /volunteer/me/export.json — everything we keep about this volunteer, theirs to take. */
    public function export(Request $req, Response $res): Response
    {
        [$user, $v] = $this->who();
        if (!$user || !$v) return $this->to($res, '/volunteer');
        $data = ['volunteer' => (array) $v, 'level' => Level::of($v),
                 'hours' => array_map(static fn ($r) => (array) $r, DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->orderBy('worked_on')->get()->all()),
                 'tasks' => array_map(static fn ($r) => (array) $r, Tasks::mine($v, 'done')),
                 'training' => array_map(static fn ($r) => ['title' => $r->title, 'passed_at' => $r->passed_at, 'expires_at' => $r->expires_at], Training::passed($v))];
        unset($data['volunteer']['tip_dismissed_at']);
        $res->getBody()->write((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="my-volunteering.json"')->withHeader('Cache-Control', 'no-store');
    }

    /** GET /volunteer/apply */
    public function applyForm(Request $req, Response $res): Response
    {
        [$user, $v] = $this->who();
        // An account comes first (owner, 30 Oct 2026). Somebody signed out is TOLD so, on this
        // page, with the way to make one — not bounced to a sign-in screen that never says why.
        if (!$user || !Volunteers::accountReady((int) $user->id)) {
            return $this->view->render($res, 'volunteer/apply.twig', [
                'page_title' => 'Volunteer with Africa GATES', 'need' => $user ? 'verify' : 'account', 'user' => $user, 'old' => [], 'errors' => [], 'was' => null,
                'join' => '/account/login?via=email&next=' . rawurlencode('/volunteer/apply'),
            ]);
        }
        if ($v && !in_array($v->status, ['declined', 'left'], true)) return $this->to($res, '/volunteer');
        $old = $_SESSION['vol_apply_old'] ?? [];
        unset($_SESSION['vol_apply_old']);
        // What the account already knows is not asked again.
        if (!isset($old['phone']) && trim((string) ($user->phone ?? '')) !== '') $old['phone'] = (string) $user->phone;
        return $this->view->render($res, 'volunteer/apply.twig', [
            'page_title' => 'Volunteer with Africa GATES', 'need' => '', 'user' => $user, 'old' => $old, 'was' => $v,
            'interests' => Volunteers::INTERESTS, 'skills' => Volunteers::SKILLS, 'states' => Volunteers::STATES, 'genders' => Volunteers::GENDERS,
            'occupations' => Volunteers::OCCUPATIONS, 'weekdays' => Volunteers::WEEKDAYS, 'weekends' => Volunteers::WEEKENDS, 'heard' => Volunteers::HEARD,
            'tshirts' => Volunteers::TSHIRTS, 'errors' => $this->take('vol_errors') ?? [],
            'min_age' => \AfricaGates\Services\Volunteer\Rules::get('vol_min_age'), 'guardian_under' => \AfricaGates\Services\Volunteer\Rules::get('vol_guardian_under'),
        ]);
    }

    /** POST /volunteer/apply */
    public function apply(Request $req, Response $res): Response
    {
        [$user] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer/apply');
        $b = (array) $req->getParsedBody();
        $r = Volunteers::apply((int) $user->id, $b);
        if (!$r['ok']) {
            $_SESSION['vol_errors'] = $r['errors'];
            $b['from_home'] ??= '0';   // an unticked box sends nothing; kept as a no
            $_SESSION['vol_apply_old'] = array_intersect_key($b, array_flip(['display_name', 'phone', 'dob', 'gender', 'guardian', 'occupation', 'state', 'area', 'weekdays', 'weekends', 'max_hours_week', 'travel_km', 'from_home',
                'interests', 'skills', 'languages', 'tshirt', 'experience', 'motivation', 'emergency_name', 'emergency_phone', 'access_needs', 'ref_name', 'ref_contact', 'ref_relation', 'heard_from']));
            return $this->to($res, '/volunteer/apply');
        }
        $_SESSION['vol_toast'] = 'Thank you. Your application is in; we’ve asked your referee.';
        return $this->to($res, '/volunteer');
    }

    /** POST /volunteer/act — every change on the workspace, by verb. */
    public function act(Request $req, Response $res): Response
    {
        [$user, $v] = $this->who();
        if (!$user || !$v) return $this->to($res, '/volunteer');
        $b = (array) $req->getParsedBody();
        $back = $this->safeBack((string) ($b['back'] ?? ''), $req);
        $name = Volunteers::name($v);
        $ok = static fn (string $m) => ['ok' => true, 'msg' => $m];
        $no = static fn (string $m) => ['ok' => false, 'msg' => $m];
        $active = in_array($this->role($v), ['new', 'active', 'lead'], true) || $v->status === 'paused';

        $r = match ((string) ($b['do'] ?? '')) {
            'arrive' => ($k = Attendance::arrive($v, (int) ($b['event'] ?? 0), Attendance::SELF, $name))['ok'] ? $ok('Checked in. Check out when you leave.') : $no((string) $k['error']),
            'leave' => ($k = Attendance::leave($v, (int) ($b['event'] ?? 0), Attendance::SELF, $name))['ok']
                ? $ok('Checked out. ' . Level::h((float) $k['hours']) . ($k['review'] ? ' sent to a lead to confirm.' : ' added to your hours.')) : $no((string) $k['error']),
            'contact' => $active ? (($k = Contacts::log($v, $b))['ok'] ? $ok('Logged. The team follows up.') : $no((string) $k['error'])) : $no('Contacts open once you’re approved.'),
            'contact_edit' => ($k = Contacts::edit($v, (int) ($b['contact'] ?? 0), $b))['ok'] ? $ok('Saved. The team gets the correction.') : $no((string) $k['error']),
            'forget_contact' => Contacts::forget($v, (int) ($b['contact'] ?? 0)) ? $ok('Marked. The team won’t contact them.') : $no('That contact isn’t yours.'),
            'meet' => $active ? (($k = Meetings::schedule($v, $b, $this->mailer))['ok'] ? $ok('Scheduled. ' . ($k['invited'] === 1 ? 'One person is' : $k['invited'] . ' people are') . ' invited by email.') : $no((string) $k['error'])) : $no('Meetings open once you’re approved.'),
            'meet_answer' => Meetings::answer($v, (int) ($b['meeting'] ?? 0), (string) ($b['answer'] ?? '')) ? $ok(($b['answer'] ?? '') === 'yes' ? 'Thanks. They know you’re going.' : 'Thanks. They know you can’t come.') : $no('That meeting can’t be answered now.'),
            'meet_notes' => ($k = Meetings::notes($v, (int) ($b['meeting'] ?? 0), (string) ($b['notes'] ?? '')))['ok'] ? $ok('Notes saved. Everybody invited can read them.') : $no((string) $k['error']),
            'meet_cancel' => Meetings::cancel($v, (int) ($b['meeting'] ?? 0), $this->mailer) ? $ok('Called off. Everybody invited is told.') : $no('Only the organiser can call it off, before it starts.'),
            'take' => $active ? (($t = Tasks::take($v, (int) ($b['task'] ?? 0)))['ok'] ? $ok('It’s yours. It’s in Tasks › Mine.') : $no((string) $t['error'])) : $no('Tasks open once you’re approved.'),
            'droptask' => Tasks::drop($v, (int) ($b['task'] ?? 0)) ? $ok('Dropped.') : $no('That task is not yours to drop.'),
            'log' => ($l = Hours::log($v, (string) ($b['date'] ?? ''), (string) ($b['what'] ?? ''), (float) ($b['hours'] ?? 0), (string) ($b['witness'] ?? ''), (string) ($b['proof'] ?? '')))['ok']
                ? $ok('Sent for confirming. It shows under In review.') : $no((string) $l['error']),
            'proof' => Hours::addProof($v, (int) ($b['hour'] ?? 0), (string) ($b['proof'] ?? '')) ? $ok('Proof added. Your lead sees it next to the hours.') : $no('A proof link starts with https://'),
            'train' => $this->train($v, $b),
            'referee' => Volunteers::changeReferee($v, (string) ($b['ref_name'] ?? ''), (string) ($b['ref_contact'] ?? '')) ? $ok('We’ve asked them instead.') : $no('Add their name and a phone or email.'),
            'request' => ($q = Requests::create('volunteer', (string) ($b['type'] ?? ''), 'member', (int) $user->id, $name, $b, ...$this->placeOf($v, (string) ($b['place'] ?? ''))))['ok']
                ? $ok('Sent to ' . $q['decider'] . '.') : $no((string) $q['error']),
            'request_act' => $this->requestAct($v, $user, $b),
            'nomcheck' => ($k = NominationChecks::check($v, (int) ($b['cycle'] ?? 0), (int) ($b['nomination'] ?? 0),
                    ($b['check'] ?? '') === 'complete' ? 'complete' : (str_starts_with((string) ($b['check'] ?? ''), 'flag_') ? 'flag' : ''),
                    substr((string) ($b['check'] ?? ''), 5), (string) ($b['note'] ?? '')))['ok'] ? $ok('Sent. A moderator sees it with your name.') : $no((string) $k['error']),
            'hours_confirm' => $this->confirmHours($v, $b, $name),
            'save_profile' => $this->saved(Volunteers::save($v, 'profile', $b)),
            'save_availability' => $this->saved(Volunteers::save($v, 'availability', $b)),
            'save_notifications' => $this->saved(Volunteers::save($v, 'notifications', $b)),
            'pause' => Volunteers::pause($v, (int) ($b['weeks'] ?? 0)) ? $ok('You’re on a break. Invites pause until then.') : $no('Pick 2, 4 or 8 weeks.'),
            'resume' => Volunteers::resume($v) ? $ok('Welcome back.') : $no('You’re not on a break.'),
            'leave' => Volunteers::leave($v) ? $ok('You’ve stepped back. Your record stays yours.') : $no('That could not be done.'),
            default => $no('That isn’t something this page can do.'),
        };
        $_SESSION[$r['ok'] ? 'vol_toast' : 'vol_error'] = $r['msg'];
        if (($b['do'] ?? '') === 'request' && $r['ok']) $back = '/volunteer/requests';
        if (($b['do'] ?? '') === 'leave' && $r['ok']) $back = '/volunteer/me/leave';
        if (($b['do'] ?? '') === 'contact' && $r['ok']) $back = '/volunteer/contacts';
        if (($b['do'] ?? '') === 'meet' && $r['ok']) $back = '/volunteer/meetings/' . (int) ($k['id'] ?? 0);
        if (($b['do'] ?? '') === 'meet' && !$r['ok']) {
            $_SESSION['vol_old'] = array_intersect_key($b, array_flip(['title', 'date', 'time', 'minutes', 'repeats', 'times', 'mode', 'link', 'location', 'people', 'agenda']));
        }
        // A refused contact comes back with what was typed, so nothing is typed twice. The two
        // confirmations are not kept: they are asked afresh.
        if (in_array($b['do'] ?? '', ['contact', 'contact_edit'], true) && !$r['ok']) {
            $_SESSION['vol_old'] = array_intersect_key($b, array_flip(['name', 'phone', 'email', 'interest', 'place', 'place_text', 'note']));
        }
        // A sent check opens the next one waiting (a HubSpot-style queue), so a checker works
        // through them without returning to the list each time; the list when none is left.
        if (($b['do'] ?? '') === 'nomcheck' && $r['ok']) {
            $back = (string) preg_replace('~\?.*$~', '', $back);
            $next = NominationChecks::queue($v, (int) ($b['cycle'] ?? 0))[0] ?? null;
            if ($next) { $back .= '?n=' . $next['id']; $_SESSION['vol_toast'] = 'Sent. A moderator sees it with your name. Next: ' . $next['ref'] . '.'; }
            else $_SESSION['vol_toast'] = 'Sent. A moderator sees it with your name. That was the last one waiting.';
        }
        return $this->to($res, $back);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────

    /** RFC 5545 text: backslash, semicolon, comma and newlines escaped. */
    private static function icsText(string $t): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $t);
    }

    /** A time in UTC, which every calendar reader converts to the person's own zone. */
    private static function icsTime(string $at): string
    {
        return (new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** A calendar around some events, lines over 75 octets folded as RFC 5545 requires. */
    private static function calendar(string $name, array $events): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Africa GATES//Volunteering//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
                  'X-WR-CALNAME:' . self::icsText($name), ...$events, 'END:VCALENDAR'];
        $out = '';
        foreach ($lines as $l) {
            while (strlen($l) > 75) { $cut = 75; while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) $cut--; $out .= substr($l, 0, $cut) . "\r\n "; $l = substr($l, $cut); }
            $out .= $l . "\r\n";
        }
        return $out;
    }

    private function train(object $v, array $b): array
    {
        $r = Training::take($v, (string) ($b['module'] ?? ''), (array) ($b['a'] ?? []));
        if (!$r['ok']) return ['ok' => false, 'msg' => (string) $r['error']];
        return $r['passed'] ? ['ok' => true, 'msg' => sprintf('Passed, %d of %d. It’s on your record.', $r['score'], $r['of'])]
                            : ['ok' => false, 'msg' => sprintf('%d of %d right. Read it again and have another go.', $r['score'], $r['of'])];
    }

    private function requestAct(object $v, object $user, array $b): array
    {
        $r = Requests::act((int) ($b['request'] ?? 0), (string) ($b['action'] ?? ''), 'member', (int) $user->id, Volunteers::name($v), (string) ($b['note'] ?? ''));
        return $r['ok'] ? ['ok' => true, 'msg' => 'Done. ' . (Requests::STATUSES[$r['status']] ?? '') . '.'] : ['ok' => false, 'msg' => (string) $r['error']];
    }

    /** A lead confirms or declines hours — only their own team's (Team::leads). */
    private function confirmHours(object $v, array $b, string $name): array
    {
        $h = DB::table('gates_vol_hours')->where('id', (int) ($b['hour'] ?? 0))->first();
        if (!$h || $this->role($v) !== 'lead' || !Team::leads($v, (int) $h->volunteer_id)) return ['ok' => false, 'msg' => 'Those hours are not your team’s.'];
        $r = Hours::confirm((int) $h->id, ($b['verdict'] ?? '') === 'yes', 'member', (int) $v->user_id, $name, (string) ($b['reason'] ?? ''));
        return $r['ok'] ? ['ok' => true, 'msg' => ($b['verdict'] ?? '') === 'yes' ? 'Confirmed.' : 'Declined. They see your reason.'] : ['ok' => false, 'msg' => (string) $r['error']];
    }

    /**
     * The place a request is about, as [kind, id] — only one of the person's own places, so a
     * crafted post cannot route a request to a lead somewhere they have never helped.
     *
     * @return array{0:?string,1:?int}
     */
    private function placeOf(object $v, string $place): array
    {
        [$kind, $id] = array_pad(explode(':', $place, 2), 2, '0');
        return Workspace::place($v, $kind, (int) $id) ? [$kind, (int) $id] : [null, null];
    }

    private function saved(array $r): array
    {
        return ($r['ok'] ?? false) ? ['ok' => true, 'msg' => 'Saved.'] : ['ok' => false, 'msg' => (string) ($r['error'] ?? 'That could not be saved.')];
    }

    /** @return array{0:?object,1:?object} the member and their volunteer row */
    private function who(): array
    {
        $user = $this->accounts->current();
        if (!$user) return [null, null];
        if (!Volunteers::ready()) return [$user, null];
        $v = Volunteers::of((int) $user->id);
        // A break that has run out ends itself on the next read, like a grant does.
        if ($v && $v->status === 'paused' && $v->paused_until && strtotime((string) $v->paused_until) <= time()) { Volunteers::resume($v); $v = Volunteers::of((int) $user->id); }
        return [$user, $v];
    }

    private function role(object $v): string
    {
        $r = Volunteers::role($v);
        return $r === 'paused' ? 'active' : $r;
    }

    private function render(Response $res, object $user, object $v, string $role, array $ctx): Response
    {
        $ctx['purpose'] = ($ctx['purpose'] ?? '') !== '' ? $ctx['purpose'] : (Workspace::PURPOSE[$ctx['purposeKey'] ?? ''] ?? '');
        // Find (§2.4) on every workspace page. There is no Ask here: the assistant is the
        // console's, and a volunteer is never an admin actor (§0.4).
        $ctx['find'] = ['action' => '/volunteer', 'q' => (string) ($ctx['findQ'] ?? ''), 'items' => Workspace::findItems($v, $role)];
        $ctx['bell'] ??= Updates::forVolunteer($v);
        return $this->view->render($res, 'volunteer/page.twig', [
            'page_title' => ($ctx['title'] ?? 'Volunteering') . ' — Africa GATES volunteers',
            'shell' => $ctx + ['brand' => Volunteers::roleLabel($v), 'me' => ['name' => Volunteers::name($v), 'ini' => Workspace::initials(Volunteers::name($v))],
                'paused' => $v->status === 'paused' ? Workspace::day($v->paused_until) : null, 'role' => $role,
                'toast' => $this->take('vol_toast'), 'error' => $this->take('vol_error'), 'here' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/volunteer'), PHP_URL_PATH) ?: '/volunteer'],
        ]);
    }

    /** Only a path inside the workspace is a place to come back to. */
    private function safeBack(string $back, Request $req): string
    {
        return preg_match('~^/volunteer(/[A-Za-z0-9/_-]*)?(\?[A-Za-z0-9=&_%.-]*)?$~', $back) ? $back : '/volunteer';
    }

    private function take(string $k): mixed
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v;
    }

    private function to(Response $res, string $url): Response
    {
        return $res->withHeader('Location', $url)->withStatus(303);
    }
}
