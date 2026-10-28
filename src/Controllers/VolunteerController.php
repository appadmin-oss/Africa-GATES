<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Requests;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\Volunteer\Hours;
use AfricaGates\Services\Volunteer\Level;
use AfricaGates\Services\Volunteer\Shifts;
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
 * on the record itself — the service refuses a shift that is full, a task that needs training,
 * a request that is not theirs — so a crafted post is answered exactly like a button.
 */
final class VolunteerController
{
    public function __construct(private readonly Twig $view, private readonly UserAccountService $accounts) {}

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
        $content = WorkspacePages::root($v, $role, $page, $tab, $req->getQueryParams());
        $counts = Workspace::counts($v, $role);
        $tabCounts = match ($page) {
            'shifts' => ['mine' => $counts['shifts']['n'] ?? 0], 'tasks' => ['mine' => $counts['tasks']['n'] ?? 0],
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

    /** GET /volunteer/shifts/{id} — a shift as a record page. */
    public function shift(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer');
        if (!$v || $this->role($v) === 'applicant') return $this->to($res, '/volunteer');
        $c = WorkspacePages::shift($v, (int) $args['id']);
        if (!$c) throw new HttpNotFoundException($req);
        $role = $this->role($v);
        return $this->render($res, $user, $v, $role, ['scope' => 'root', 'nav' => Workspace::rootNav($v, $role, 'shifts', Workspace::counts($v, $role)),
            'crumbs' => [['label' => 'Shifts', 'href' => '/volunteer/shifts'], ['label' => $c['title']]], 'back' => ['label' => 'Shifts', 'href' => '/volunteer/shifts']] + $c);
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

    /** GET /volunteer/{kind:cycle|event}/{id}[/{page}] — a place this person helps. */
    public function place(Request $req, Response $res, array $args): Response
    {
        [$user, $v] = $this->who();
        if (!$user) return $this->to($res, '/account/login?next=/volunteer');
        if (!$v || $this->role($v) === 'applicant') return $this->to($res, '/volunteer');
        $kind = (string) $args['kind'];
        $p = Workspace::place($v, $kind, (int) $args['id']);
        if (!$p) throw new HttpNotFoundException($req);   // no grant and no shift here: not theirs to see
        $first = array_key_first(Workspace::SCOPE_PAGES[$kind][0][1]);
        $page = (string) ($args['page'] ?? $first);
        $valid = [];
        foreach (Workspace::SCOPE_PAGES[$kind] as [, $pages]) $valid += $pages;
        if (!isset($valid[$page])) throw new HttpNotFoundException($req);
        $c = WorkspacePages::place($v, $p, $page, '');
        $role = $this->role($v);
        return $this->render($res, $user, $v, $role, [
            'scope' => $kind, 'nav' => Workspace::scopeNav($kind, (int) $p['id'], $page),
            'instance' => ['name' => $p['name'], 'sub' => implode(', ', $p['roles']), 'ini' => Workspace::initials($p['name']), 'tone' => $kind === 'event' ? 'ok' : 'info'],
            'siblings' => Workspace::siblings($v, $kind, (int) $p['id'], $page),
            'back' => ['label' => 'Back to my volunteering', 'href' => '/volunteer'],
            'crumbs' => [['label' => 'My volunteering', 'href' => '/volunteer'], ['label' => $p['name'], 'href' => $p['href']], ['label' => $valid[$page][0]]],
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
                 'shifts' => array_map(static fn ($r) => (array) $r, DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
                     ->where('s.volunteer_id', $v->id)->get(['h.title', 'h.starts_at', 'h.ends_at', 's.status', 's.joined_at'])->all()),
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
        if (!$user) return $this->to($res, '/account/login?next=/volunteer/apply');
        if ($v && !in_array($v->status, ['declined', 'left'], true)) return $this->to($res, '/volunteer');
        $old = $_SESSION['vol_apply_old'] ?? [];
        unset($_SESSION['vol_apply_old']);
        return $this->view->render($res, 'volunteer/apply.twig', [
            'page_title' => 'Volunteer with Africa GATES', 'user' => $user, 'old' => $old, 'was' => $v,
            'interests' => Volunteers::INTERESTS, 'errors' => $this->take('vol_errors') ?? [],
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
            $_SESSION['vol_apply_old'] = array_intersect_key($b, array_flip(['display_name', 'area', 'state', 'languages', 'motivation', 'dob', 'ref_name', 'ref_contact', 'ref_relation', 'interests']));
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
            'join' => $active ? (($j = Shifts::join($v, (int) ($b['shift'] ?? 0)))['ok'] ? $ok('You’re on it. It’s in Shifts › Mine.') : $no((string) $j['error'])) : $no('Shifts open once you’re approved.'),
            'drop' => Shifts::drop($v, (int) ($b['shift'] ?? 0)) ? $ok('Dropped. The shift goes back to the team.') : $no('That shift is not yours to drop.'),
            'accept' => Shifts::acceptChange($v, (int) ($b['shift'] ?? 0)) ? $ok('Thanks. The new time is noted.') : $no('That shift is not yours.'),
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
        return $this->to($res, $back);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────

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
