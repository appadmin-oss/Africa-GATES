<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Hours;
use AfricaGates\Services\Volunteer\Level;
use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Services\Volunteer\Tasks;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /admin/volunteers — THE STAFF SIDE OF THE VOLUNTEER WORKSPACE (review slice V1: "plus the
 * staff side that feeds it, so nothing is empty").
 *
 * Applications to approve or decline, roles to give (every one with an end date — Grants
 * refuses one without), tasks to post and accept, training to write,
 * and hours logged by hand that no team lead has answered.
 *
 * ACCESS IS THE GUARD'S, NOT THIS FILE'S. `/admin/volunteers` is not mapped in
 * Permissions::PATH_SECTIONS, so SectionGuardMiddleware fails it closed to superadmin. Who
 * else may coordinate volunteers is an access decision for the owner (CLAUDE.md: an access
 * change never rides along inside a feature); this page works for whoever the guard lets in,
 * and every write is audited once, with what it changed.
 */
final class VolunteersController
{
    public const TABS = ['applications' => 'Applications', 'roster' => 'Roster', 'tasks' => 'Tasks',
                         'training' => 'Training', 'hours' => 'Hours to confirm'];

    public function __construct(private readonly Twig $view, private readonly AuditService $audit) {}

    public function index(Request $req, Response $res): Response
    {
        $tab = (string) ($req->getQueryParams()['tab'] ?? 'applications');
        if (!isset(self::TABS[$tab])) $tab = 'applications';
        $ready = Volunteers::ready();
        $counts = $ready ? [
            'applications' => (int) DB::table('gates_volunteers')->where('status', 'applicant')->count(),
            'hours' => count(Hours::waiting()),
        ] : [];
        $data = $ready ? match ($tab) {
            'applications' => ['rows' => $this->applications()],
            'roster' => ['rows' => $this->roster(), 'caps' => Grants::CAPABILITIES, 'places' => $this->places()],
            'tasks' => ['rows' => $this->tasks(), 'checked' => Tasks::CHECKED_BY, 'places' => $this->places(), 'modules' => Training::titles()],
            'training' => ['rows' => DB::table('gates_vol_modules')->orderBy('title')->get()->all(), 'caps' => Grants::CAPABILITIES],
            'hours' => ['rows' => Hours::waiting()],
            default => [],
        } : [];
        return $this->view->render($res, 'admin/volunteers.twig', [
            'page_title' => 'Volunteers — Admin', 'admin_page' => 'volunteers', 'topbar_title' => 'Volunteers',
            'tab' => $tab, 'tabs' => self::TABS, 'counts' => $counts, 'ready' => $ready,
            'flash_ok' => $this->take('vol_admin_ok'), 'flash_error' => $this->take('vol_admin_error'),
        ] + $data);
    }

    public function act(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $tab = (string) ($b['tab'] ?? 'applications');
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $adminName = (string) ($_SESSION['admin_name'] ?? 'Staff');
        if (!Permissions::canWrite((string) ($_SESSION['admin_role'] ?? ''))) return $this->back($res, $tab, false, 'Your role can look here but not change anything.');
        $do = (string) ($b['do'] ?? '');
        // The forms post a place as one "kind:id" choice; the services take the two halves.
        if (isset($b['scope']) && str_contains((string) $b['scope'], ':')) [$b['scope_kind'], $b['scope_id']] = explode(':', (string) $b['scope'], 2);
        // The form numbers answers from 1, as people count; the module stores them from 0.
        if ($do === 'module') foreach ((array) ($b['answer'] ?? []) as $i => $a) $b['answer'][$i] = max(0, (int) $a - 1);
        [$ok, $msg, $target] = match ($do) {
            'approve' => [Volunteers::approve((int) ($b['id'] ?? 0), $adminId), 'Approved. Their workspace opens now.', (int) ($b['id'] ?? 0)],
            'decline' => [Volunteers::decline((int) ($b['id'] ?? 0), $adminId, (string) ($b['reason'] ?? '')), 'Declined. They see the reason.', (int) ($b['id'] ?? 0)],
            'ref_replied' => [Volunteers::refereeReplied((int) ($b['ref'] ?? 0), (string) ($b['note'] ?? '')), 'Marked as replied.', (int) ($b['ref'] ?? 0)],
            'grant' => $this->grant($b, $adminId),
            'revoke' => [Grants::revoke((int) ($b['grant'] ?? 0), $adminId), 'Ended. It leaves their workspace on their next page.', (int) ($b['grant'] ?? 0)],
            'task' => $this->created(Tasks::create($b, $adminId), 'Posted to the task library.'),
            'accept' => [Tasks::accept((int) ($b['take'] ?? 0), (float) ($b['hours'] ?? 0), (int) ($b['accepted'] ?? 0), $adminName), 'Accepted: the hours are on their record.', (int) ($b['take'] ?? 0)],
            'module' => $this->created(Training::save($b), 'Saved. Volunteers see it in Training.'),
            'hours' => $this->hours($b, $adminId, $adminName),
            default => [false, 'That isn’t something this page can do.', 0],
        };
        if ($ok) $this->audit->record($adminId, 'volunteers.' . $do, 'volunteer', (int) $target, array_intersect_key($b, array_flip(['id', 'capability', 'scope_kind', 'scope_id', 'expires_at', 'title', 'reason', 'kept', 'verdict'])));
        return $this->back($res, $tab, $ok, $msg);
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────

    private function applications(): array
    {
        $rows = [];
        foreach (DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')->where('v.status', 'applicant')
                     ->orderBy('v.applied_at')->get(['v.*', 'u.name as user_name', 'u.email']) as $v) {
            $v->refs = Volunteers::references((int) $v->id);
            $v->age = $v->dob ? (int) floor((time() - (int) strtotime((string) $v->dob)) / (365.25 * 86400)) : null;
            $v->wants = array_map(static fn ($k) => Volunteers::INTERESTS[$k] ?? $k, array_filter(explode(',', (string) $v->interests)));
            // The rebuilt application's answers (2027_04_23_vol_application), in the words the form used.
            $v->brings = array_map(static fn ($k) => Volunteers::SKILLS[$k] ?? $k, array_filter(explode(',', (string) ($v->skills ?? ''))));
            $v->when = (Volunteers::WEEKDAYS[$v->weekdays] ?? '') . ' · ' . (Volunteers::WEEKENDS[$v->weekends] ?? '') . ' · up to ' . (int) $v->max_hours_week . 'h a week';
            $v->gender_label = Volunteers::GENDERS[$v->gender ?? ''] ?? null;
            $v->occupation_label = Volunteers::OCCUPATIONS[$v->occupation ?? ''] ?? null;
            $v->heard_label = Volunteers::HEARD[$v->heard_from ?? ''] ?? null;
            $rows[] = $v;
        }
        return $rows;
    }

    private function roster(): array
    {
        $rows = [];
        foreach (DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')->whereIn('v.status', ['active', 'paused'])
                     ->orderBy('u.name')->limit(500)->get(['v.*', 'u.name as user_name']) as $v) {
            $v->name = trim((string) $v->display_name) ?: (string) $v->user_name;
            $v->lv = Level::of($v);
            $v->grants = Grants::forActor('member', (int) $v->user_id, 'vol.');
            foreach ($v->grants as $g) $g->where = Workspace::scopeName((string) $g->scope_kind, (int) $g->scope_id);
            $rows[] = $v;
        }
        return $rows;
    }

    /** Events and award editions a role or a task can be for, newest first. @return list<array{kind:string,id:int,name:string}> */
    private function places(): array
    {
        $out = [];
        foreach (DB::table('gates_site_events')->where('event_date', '>=', date('Y-m-d', strtotime('-30 days')))->orderBy('event_date')->limit(50)->get(['id', 'title', 'event_date']) as $e) {
            $out[] = ['kind' => 'event', 'id' => (int) $e->id, 'name' => $e->title . ' · ' . date('j M Y', (int) strtotime((string) $e->event_date))];
        }
        foreach (DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                     ->whereNotIn('c.status', ['archived'])->orderByDesc('c.year')->limit(50)->get(['c.id', 'p.title', 'c.edition_label', 'c.year']) as $c) {
            $out[] = ['kind' => 'cycle', 'id' => (int) $c->id, 'name' => $c->title . ' · ' . ($c->edition_label ?: $c->year)];
        }
        return $out;
    }

    private function tasks(): array
    {
        $rows = DB::table('gates_vol_tasks')->where('status', 'open')->orderByDesc('id')->limit(100)->get()->all();
        foreach ($rows as $t) {
            $t->takes = DB::table('gates_vol_task_takes as k')->join('gates_volunteers as v', 'v.id', '=', 'k.volunteer_id')->join('gates_users as u', 'u.id', '=', 'v.user_id')
                ->where('k.task_id', $t->id)->where('k.status', 'taken')->get(['k.id', 'k.progress', 'v.display_name', 'u.name'])->all();
        }
        return $rows;
    }

    // ── writes ───────────────────────────────────────────────────────────────────────────

    private function grant(array $b, int $adminId): array
    {
        $uid = (int) DB::table('gates_volunteers')->where('id', (int) ($b['id'] ?? 0))->value('user_id');
        [$kind, $scope] = array_pad(explode(':', (string) ($b['place'] ?? '')), 2, '0');
        if (!$uid) return [false, 'Pick a volunteer.', 0];
        $r = Grants::grant('member', $uid, (string) ($b['capability'] ?? ''), $kind, (int) $scope, (string) ($b['expires_at'] ?? ''), $adminId, 'Given by staff');
        return [$r['ok'], $r['ok'] ? 'Given. It ends on its own on that date.' : (string) $r['error'], (int) ($r['id'] ?? 0)];
    }

    private function hours(array $b, int $adminId, string $name): array
    {
        $r = Hours::confirm((int) ($b['hour'] ?? 0), ($b['verdict'] ?? '') === 'yes', 'admin', $adminId, $name, (string) ($b['reason'] ?? ''));
        return [$r['ok'], $r['ok'] ? (($b['verdict'] ?? '') === 'yes' ? 'Confirmed.' : 'Declined. They see your reason.') : (string) $r['error'], (int) ($b['hour'] ?? 0)];
    }

    private function created(array $r, string $msg): array
    {
        return [$r['ok'], $r['ok'] ? $msg : implode(' ', $r['errors'] ?? ['That could not be saved.']), (int) ($r['id'] ?? 0)];
    }

    private function back(Response $res, string $tab, bool $ok, string $msg): Response
    {
        $_SESSION[$ok ? 'vol_admin_ok' : 'vol_admin_error'] = $msg;
        return $res->withHeader('Location', '/admin/volunteers?tab=' . (isset(self::TABS[$tab]) ? $tab : 'applications'))->withStatus(303);
    }

    private function take(string $k): ?string
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v;
    }
}
