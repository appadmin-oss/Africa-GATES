<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\EventPlan\LeadPages;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * /admin/lead — THE EVENT LEAD CONSOLE (consoles v7 handoff §5; review slice L1).
 *
 * A small console of its own, on the v7 shell, covering only the events the signed-in admin
 * holds an `event.lead` grant on: Overview, Inbox, Requests, Your events, then Deadlines, Risks,
 * Approvals and Analytics across them, and People. Each event opens its own pages — Overview,
 * Work, Risks, Budget, People — where the plan is edited.
 *
 *   · THE PAGE IS OPEN, THE CONTENT IS GRANTED. Every role may open /admin/lead; what it shows
 *     is limited to the viewer's live grants, and an admin with none is told so. Every write
 *     is re-checked against the grant on the row it touches (Plan::isLead), so a crafted post
 *     about another event is refused like a button.
 *   · EVERY CHANGE IS AUDITED, once, with what it changed — `lead.<verb>` on the audit log —
 *     so "who approved the caterer" has an answer outside the plan's own rows.
 *   · A post answers with a 303 back to the page and a sentence, so a refresh never repeats it.
 */
final class LeadController
{
    public function __construct(private readonly Twig $view, private readonly AuditService $audit) {}

    /** GET /admin/lead[/{page}[/{tab}]] */
    public function root(Request $req, Response $res, array $args): Response
    {
        $me = $this->me();
        $page = (string) ($args['page'] ?? 'overview');
        if (!isset(LeadPages::ROOT[$page])) throw new HttpNotFoundException($req);
        $tabs = LeadPages::TABS[$page] ?? [];
        // A tab is ?tab= on its page: one route per page, every one of them in the route table.
        $tab = (string) ($req->getQueryParams()['tab'] ?? array_key_first($tabs) ?? '');
        if ($tabs && !isset($tabs[$tab])) $tab = (string) array_key_first($tabs);
        $events = Plan::ready() ? Plan::leadEvents($me['id']) : [];
        // A request opens on Requests itself (?r=), the way the handoff's drawer would.
        if ($page === 'requests' && isset($req->getQueryParams()['r'])) return $this->request($req, $res, ['id' => (int) $req->getQueryParams()['r']]);
        $c = Plan::ready() ? LeadPages::root($me, $events, $page, $tab, $req->getQueryParams())
                           : ['title' => LeadPages::ROOT[$page][0], 'secs' => [['k' => 'note', 'tone' => 'warn', 'text' => 'The database needs its latest update before the lead console can open. Run the pending migrations from Settings.']]];
        return $this->render($res, $me, $c + [
            'scope' => 'root', 'nav' => LeadPages::rootNav($page, LeadPages::counts($me, $events)),
            'tabs' => $tabs ? LeadPages::tabs($page, $tab) : [],
            'crumbs' => [['label' => 'Event lead', 'href' => LeadPages::BASE], ['label' => $c['title']]],
            'purpose' => ($c['purpose'] ?? '') !== '' ? $c['purpose'] : (LeadPages::PURPOSE[$page] ?? ''),
        ]);
    }

    /** GET /admin/lead/requests?r={id} — one of the lead's own requests. */
    private function request(Request $req, Response $res, array $args): Response
    {
        $me = $this->me();
        $c = LeadPages::request($me, (int) $args['id']);
        if (!$c) throw new HttpNotFoundException($req);
        $events = Plan::leadEvents($me['id']);
        return $this->render($res, $me, $c + [
            'scope' => 'root', 'nav' => LeadPages::rootNav('requests', LeadPages::counts($me, $events)),
            'crumbs' => [['label' => 'Requests', 'href' => LeadPages::BASE . '/requests'], ['label' => $c['title']]],
            'back' => ['label' => 'Requests', 'href' => LeadPages::BASE . '/requests'], 'purpose' => '',
        ]);
    }

    /** GET /admin/lead/event/{id}[/{page}] — one event the admin leads. */
    public function event(Request $req, Response $res, array $args): Response
    {
        $me = $this->me();
        $id = (int) $args['id'];
        $e = Plan::ready() ? Plan::event($id) : null;
        if (!$e || !Plan::isLead($me['id'], $id)) throw new HttpNotFoundException($req);   // not theirs: it does not exist here
        $page = (string) ($args['page'] ?? 'overview');
        $valid = [];
        foreach (LeadPages::EVENT as [, $pages]) $valid += $pages;
        if (!isset($valid[$page])) throw new HttpNotFoundException($req);
        $c = LeadPages::event($me, $e, $page, $req->getQueryParams());
        return $this->render($res, $me, $c + [
            'scope' => 'event', 'nav' => LeadPages::eventNav($id, $page, LeadPages::eventCounts($id)),
            'instance' => ['name' => (string) $e->title, 'sub' => Workspace::day((string) $e->event_date) . ' · Event lead', 'ini' => Workspace::initials((string) $e->title), 'tone' => 'ok'],
            'back' => ['label' => 'Overview', 'href' => LeadPages::BASE],
            'crumbs' => [['label' => 'Event lead', 'href' => LeadPages::BASE], ['label' => (string) $e->title, 'href' => LeadPages::eventUrl($id)]]
                + ($page === 'overview' ? [] : [2 => ['label' => $c['title']]]),
            'kicker' => $page === 'overview' ? '' : (string) $e->title,
            'purpose' => LeadPages::PURPOSE['e.' . $page] ?? '',
        ]);
    }

    /** POST /admin/lead/act */
    public function act(Request $req, Response $res): Response
    {
        $me = $this->me();
        $b = (array) $req->getParsedBody();
        $back = $this->safeBack((string) ($b['back'] ?? LeadPages::BASE));
        if (!Permissions::canWrite($me['role'])) return $this->back($res, $back, false, 'Your role can look here but not change anything.');
        if (!Plan::ready()) return $this->back($res, $back, false, 'The database needs its latest update first.');
        $do = (string) ($b['do'] ?? '');
        $int = static fn (string $k): int => (int) ($b[$k] ?? 0);
        [$r, $msg, $type, $target] = match ($do) {
            'work_add' => [Plan::addWork($int('event'), $b, $me['id']), 'Added to the plan.', 'event', $int('event')],
            'work_status' => [Plan::setStatus($int('work'), (string) ($b['status'] ?? ''), $me['id']),
                              match ((string) ($b['status'] ?? '')) { 'done' => 'Done. Readiness moves with it.', 'doing' => 'Started.', default => 'Reopened.' }, 'event_work', $int('work')],
            'work_owner' => [Plan::reassign($int('work'), (string) ($b['owner'] ?? ''), $me['id']), 'Given to them. It’s in their week now.', 'event_work', $int('work')],
            'risk_add' => [Plan::addRisk($int('event'), $b, $me['id']), 'Risk added.', 'event', $int('event')],
            'risk_close' => [Plan::closeRisk($int('risk'), $me['id']), 'Closed.', 'event_risk', $int('risk')],
            'line_add' => [Plan::addLine($int('event'), $b, $me['id']), 'Added. It needs approving before it counts as agreed.', 'event', $int('event')],
            'line_approve' => [Plan::approveLine($int('line'), $me['id']), 'Approved.', 'event_budget', $int('line')],
            'line_ask' => [Plan::askLine($int('line'), $me['id'], $me['name'], (string) ($b['why'] ?? ''), (string) ($b['by'] ?? '')), 'Sent to finance. It shows in Requests.', 'event_budget', $int('line')],
            'line_spend' => [Plan::setSpend($int('line'), trim((string) ($b['committed'] ?? '')), trim((string) ($b['paid'] ?? '')), $me['id']), 'Recorded.', 'event_budget', $int('line')],
            'event_settings' => [Plan::setEventSettings($int('event'), trim((string) ($b['budget'] ?? '')), (string) ($b['series'] ?? ''), $me['id']), 'Saved.', 'event', $int('event')],
            'request' => [$this->newRequest($me, $b), 'Sent. It shows in Requests with who decides.', 'request', 0],
            'request_act' => [$this->requestAct($me, $b), 'Done.', 'request', $int('request')],
            'lead_grant' => [$this->grantLead($me, $b), 'They lead it now. It shows in their console on their next page.', 'event', $int('event')],
            'lead_revoke' => [$this->revokeLead($me, $int('grant')), 'Ended. The event leaves their console on their next page.', 'grant', $int('grant')],
            default => [['ok' => false, 'error' => 'That isn’t something this page can do.'], '', '', 0],
        };
        if (!$r['ok']) return $this->back($res, $back, false, (string) ($r['error'] ?? 'That didn’t work.'));
        $target = (int) ($r['id'] ?? $target);
        $this->audit->record($me['id'], 'lead.' . $do, $type, $target ?: null,
            array_intersect_key($b, array_flip(['event', 'status', 'owner', 'title', 'likelihood', 'impact', 'estimate', 'committed', 'paid', 'budget', 'series', 'type', 'action', 'admin', 'until'])));
        if ($do === 'line_ask' && isset($r['request'])) $back = LeadPages::BASE . '/requests?r=' . $r['request'];
        if ($do === 'request' && isset($r['id'])) $back = LeadPages::BASE . '/requests?r=' . $r['id'];
        return $this->back($res, $back, true, $do === 'request_act' ? 'Done. ' . (Requests::STATUSES[$r['status'] ?? ''] ?? '') . '.' : $msg);
    }

    // ── writes that are not the plan's ──────────────────────────────────────────────────

    /** A lead's request is about one of THEIR events; nothing else is offered or accepted. */
    private function newRequest(array $me, array $b): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (!Plan::isLead($me['id'], $event)) return ['ok' => false, 'error' => 'Pick one of your events.'];
        return Requests::create('lead', (string) ($b['type'] ?? ''), 'admin', $me['id'], $me['name'], $b, 'event', $event);
    }

    private function requestAct(array $me, array $b): array
    {
        $id = (int) ($b['request'] ?? 0);
        $r = Requests::act($id, (string) ($b['action'] ?? ''), 'admin', $me['id'], $me['name'], (string) ($b['note'] ?? ''), $me['role']);
        if ($r['ok']) Plan::syncRequest($id);   // a withdrawn spend request puts its line back to "not approved yet"
        return $r;
    }

    /** Platform administrators give an event to a lead; the grant ends with the event. */
    private function grantLead(array $me, array $b): array
    {
        if (!in_array($me['role'], ['superadmin', 'admin'], true)) return ['ok' => false, 'error' => 'Only an administrator gives an event to a lead.'];
        $event = Plan::event((int) ($b['event'] ?? 0));
        $admin = DB::table('gates_admins')->where('id', (int) ($b['admin'] ?? 0))->where('is_active', 1)->first();
        if (!$event || !$admin) return ['ok' => false, 'error' => 'Pick a lead and an event.'];
        if (!Permissions::canWrite((string) $admin->role)) return ['ok' => false, 'error' => 'That account can only look, so it couldn’t run an event. Change their role first.'];
        $end = (string) ($event->end_date ?: $event->event_date);
        $until = trim((string) ($b['until'] ?? '')) !== '' ? (string) $b['until'] . ' 23:59:59' : date('Y-m-d 23:59:59', (int) strtotime($end) + 86400);
        if (Grants::allows('admin', (int) $admin->id, 'event.lead', 'event', (int) $event->id)) return ['ok' => false, 'error' => 'They already lead that event.'];
        return Grants::grant('admin', (int) $admin->id, 'event.lead', 'event', (int) $event->id, $until, $me['id'], 'Event lead');
    }

    private function revokeLead(array $me, int $grantId): array
    {
        if (!in_array($me['role'], ['superadmin', 'admin'], true)) return ['ok' => false, 'error' => 'Only an administrator ends a lead’s access.'];
        $g = Grants::ready() ? DB::table('gates_grants')->where('id', $grantId)->where('capability', 'event.lead')->first() : null;
        if (!$g) return ['ok' => false, 'error' => 'That access is not there.'];
        return Grants::revoke($grantId, $me['id']) ? ['ok' => true] : ['ok' => false, 'error' => 'That access had already ended.'];
    }

    // ── plumbing ────────────────────────────────────────────────────────────────────────

    /** @return array{id:int, name:string, role:string} */
    private function me(): array
    {
        return ['id' => (int) ($_SESSION['admin_id'] ?? 0), 'name' => (string) ($_SESSION['admin_name'] ?? 'Staff'), 'role' => (string) ($_SESSION['admin_role'] ?? '')];
    }

    private function render(Response $res, array $me, array $ctx): Response
    {
        return $this->view->render($res, 'admin/lead.twig', [
            'page_title' => ($ctx['title'] ?? 'Event lead') . ' — Event lead',
            'shell' => $ctx + ['brand' => 'Event lead', 'railLabel' => 'Event lead', 'act' => LeadPages::BASE . '/act', 'home' => LeadPages::BASE,
                'foot' => ['href' => '/admin', 'label' => 'The console'], 'me' => ['name' => $me['name'], 'ini' => Workspace::initials($me['name'])],
                'toast' => $this->take('lead_toast'), 'error' => $this->take('lead_error'),
                'here' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? LeadPages::BASE), PHP_URL_PATH) ?: LeadPages::BASE)
                    . (($q = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY)) !== '' && !str_contains($q, 'ask=') && !str_contains($q, 'new=') ? '?' . $q : '')],
        ]);
    }

    /** Only a page inside the lead console is a place to come back to. */
    private function safeBack(string $back): string
    {
        return preg_match('~^/admin/lead(/[A-Za-z0-9/_-]*)?(\?[A-Za-z0-9=&_%:.-]*)?$~', $back) ? $back : LeadPages::BASE;
    }

    private function back(Response $res, string $url, bool $ok, string $msg): Response
    {
        $_SESSION[$ok ? 'lead_toast' : 'lead_error'] = $msg;
        return $res->withHeader('Location', $url)->withStatus(303);
    }

    private function take(string $k): mixed
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v;
    }
}
