<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Shifts;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /admin/requests — WHERE STAFF DECIDE WHAT PEOPLE ASKED FOR (consoles v7 §2.6: the admin
 * root's Requests, Waiting on you · All open · Decided).
 *
 * Until this page, a request routed to finance or to "an administrator" was recorded and then
 * waited for ever: the requester was told who decides, and nobody could. A spend request from
 * an event lead is exactly that case, so it shipped with the lead console.
 *
 *   · WHO DECIDES IS Requests::canDecide(), not this page. Waiting on you lists only what the
 *     signed-in role may decide; the decide form is drawn only on those.
 *   · ASKING FOR INFO AND DECLINING NEED A NOTE, and the requester sees it (Requests::act
 *     refuses without one, so a crafted post is answered like the button).
 *   · A SPEND REQUEST CARRIES ITS BUDGET LINE WITH IT: deciding the request moves the line
 *     (Plan::syncRequest), so the lead's budget and the decision cannot disagree.
 *   · Mapped to `finance` in Permissions — superadmin and admin, the only roles that decide.
 */
final class RequestsController
{
    public const TABS = ['waiting' => 'Waiting on you', 'open' => 'All open', 'decided' => 'Decided'];

    public function __construct(private readonly Twig $view, private readonly AuditService $audit) {}

    public function index(Request $req, Response $res): Response
    {
        $tab = (string) ($req->getQueryParams()['tab'] ?? 'waiting');
        if (!isset(self::TABS[$tab])) $tab = 'waiting';
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $ready = Requests::ready();
        $waiting = $ready ? Requests::waitingOn('admin', $id, $role) : [];
        $rows = !$ready ? [] : match ($tab) {
            'waiting' => $waiting,
            'open' => DB::table('gates_requests')->whereIn('status', Requests::OPEN)->orderBy('id')->limit(200)->get()->all(),
            default => DB::table('gates_requests')->whereNotIn('status', Requests::OPEN)->orderByDesc('id')->limit(100)->get()->all(),
        };
        $out = [];
        foreach ($rows as $r) $out[] = $this->present($r, $id, $role);
        return $this->view->render($res, 'admin/requests.twig', [
            'page_title' => 'Requests — Admin', 'admin_page' => 'requests', 'topbar_title' => 'Requests',
            'tab' => $tab, 'tabs' => self::TABS, 'counts' => ['waiting' => count($waiting)], 'rows' => $out, 'ready' => $ready,
            'flash_ok' => $this->take('rq_admin_ok'), 'flash_error' => $this->take('rq_admin_error'),
        ]);
    }

    public function act(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $tab = isset(self::TABS[(string) ($b['tab'] ?? '')]) ? (string) $b['tab'] : 'waiting';
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $role = (string) ($_SESSION['admin_role'] ?? '');
        if (!Permissions::canWrite($role)) return $this->back($res, $tab, false, 'Your role can look here but not change anything.');
        $id = (int) ($b['request'] ?? 0);
        $action = (string) ($b['action'] ?? '');
        if (!in_array($action, ['approve', 'ask_info', 'decline'], true)) return $this->back($res, $tab, false, 'That isn’t something this page can do.');
        $r = Requests::act($id, $action, 'admin', $adminId, (string) ($_SESSION['admin_name'] ?? 'Staff'), (string) ($b['note'] ?? ''), $role);
        if (!$r['ok']) return $this->back($res, $tab, false, (string) $r['error']);
        Plan::syncRequest($id);
        $this->audit->record($adminId, 'requests.' . $action, 'request', $id, ['note' => mb_substr((string) ($b['note'] ?? ''), 0, 200)]);
        return $this->back($res, $tab, true, match ($action) {
            'approve' => 'Approved. They see it now.', 'ask_info' => 'Asked. It’s back with them until they answer.', default => 'Declined. They see your note.',
        });
    }

    /** One request as the page draws it: facts, history and whether this viewer decides it. */
    private function present(object $r, int $adminId, string $role): array
    {
        $audience = $r->from_kind === 'admin' ? 'lead' : 'volunteer';
        $type = Requests::type((string) $r->type, $audience);
        $from = (string) (DB::table($r->from_kind === 'admin' ? 'gates_admins' : 'gates_users')->where('id', $r->from_id)->value('name') ?? 'Someone');
        $where = $r->scope_kind ? Shifts::scopeName((string) $r->scope_kind, (int) $r->scope_id) : '';
        $line = $r->type === 'spend' && Plan::ready() ? DB::table('gates_event_budget')->where('request_id', $r->id)->first() : null;
        return [
            'id' => (int) $r->id, 'title' => (string) $r->title, 'status' => (string) $r->status, 'status_label' => Requests::STATUSES[$r->status] ?? $r->status,
            'type' => (string) ($type['label'] ?? $r->type), 'from' => $from . ($r->from_kind === 'admin' ? ' · event lead' : ' · volunteer'),
            'where' => $where, 'amount' => $r->amount_minor !== null ? Plan::naira((int) $r->amount_minor) : (string) ($r->amount_text ?? ''),
            'needed_by' => (string) ($r->needed_by ?? ''), 'why' => (string) $r->why, 'decides' => Requests::deciderLabel($r),
            'consequence' => $line ? 'The line “' . $line->title . '” is approved in ' . ($where !== '' ? $where : 'the event') . '’s budget, and counts in its forecast.' : '',
            'can_decide' => $r->status === 'waiting' && Requests::canDecide($r, 'admin', $adminId, $role),
            'asked' => (string) $r->created_at,
            'history' => array_map(static fn ($e) => ['who' => (string) ($e->actor_name ?? 'Someone'), 'action' => (string) $e->action,
                                                       'note' => (string) ($e->note ?? ''), 'at' => (string) $e->created_at], Requests::history((int) $r->id)),
        ];
    }

    private function back(Response $res, string $tab, bool $ok, string $msg): Response
    {
        $_SESSION[$ok ? 'rq_admin_ok' : 'rq_admin_error'] = $msg;
        return $res->withHeader('Location', '/admin/requests?tab=' . $tab)->withStatus(303);
    }

    private function take(string $k): mixed
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v;
    }
}
