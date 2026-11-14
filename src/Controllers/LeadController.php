<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\EventPlan\EventPages;
use AfricaGates\Services\EventPlan\EventPermissions;
use AfricaGates\Services\EventPlan\EventReport;
use AfricaGates\Services\EventPlan\TeamPages;
use AfricaGates\Services\EventPlan\Teams;
use AfricaGates\Services\EventPlan\TeamSuggest;
use AfricaGates\Services\EventPlan\TeamLoop;
use AfricaGates\Services\EventPlan\Goals;
use AfricaGates\Services\EventPlan\Planner;
use AfricaGates\Services\EventPlan\Pipeline;
use AfricaGates\Services\EventPlan\Rules;
use AfricaGates\Services\EventPlan\Changes;
use AfricaGates\Services\EventPlan\LeadPages;
use AfricaGates\Services\EventPlan\Outreach;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\Scope;
use AfricaGates\Services\EventScanPass;
use AfricaGates\Services\Shell\Updates;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Attendance;
use AfricaGates\Services\Volunteer\Hours;
use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SiteUrl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * /lead — THE EVENT LEAD CONSOLE (consoles v7 handoff §5), on the v7 shell, for the member
 * accounts that lead an event.
 *
 * ── OUT OF THE ADMIN CONSOLE (owner, 29 Oct 2026) ────────────────────────────
 *
 * It lived at /admin/lead and a lead was a staff admin. That only works while every lead is
 * on our own staff: an organisation adopting the platform for its own event brings leads who
 * are not, and the admin console is the last place to send them. So a lead is a member
 * account holding an `event.lead` grant (Plan::ACTOR), signed in through the one account
 * sign-in like a volunteer, and opening "Events I lead" from the switcher. Administrators
 * give and end the role on the event's own admin page; nothing here can grant it.
 *
 *   · THE PAGE IS OPEN, THE CONTENT IS GRANTED. Any member may open /lead; what it shows is
 *     their own live grants, and with none it says so. Every write is re-checked against the
 *     grant on the row it touches (Plan::isLead), so a crafted post about another event is
 *     refused like a button.
 *   · EVERY CHANGE IS AUDITED, once, as `lead.<verb>` with the lead's name in the meta — the
 *     audit log's admin column is for staff, and a lead is not staff.
 *   · A post answers with a 303 back to the page and a sentence, so a refresh never repeats it.
 */
final class LeadController
{
    public function __construct(private readonly Twig $view, private readonly UserAccountService $accounts, private readonly AuditService $audit) {}

    /** GET /lead[/{page}] */
    public function root(Request $req, Response $res, array $args): Response
    {
        $me = $this->me();
        if ($me === null) return $res->withHeader('Location', '/account/login?next=' . LeadPages::BASE)->withStatus(303);
        $this->claim($me);
        $page = (string) ($args['page'] ?? 'overview');
        if (!isset(LeadPages::ROOT[$page])) throw new HttpNotFoundException($req);
        $tabs = LeadPages::TABS[$page] ?? [];
        // A tab is ?tab= on its page: one route per page, every one of them in the route table.
        $tab = (string) ($req->getQueryParams()['tab'] ?? array_key_first($tabs) ?? '');
        if ($tabs && !isset($tabs[$tab])) $tab = (string) array_key_first($tabs);
        $events = Plan::ready() ? Plan::leadEvents($me['id']) : [];
        // A request opens on Requests itself (?r=), the way the handoff's drawer would.
        if ($page === 'requests' && isset($req->getQueryParams()['r'])) return $this->request($req, $res, ['id' => (int) $req->getQueryParams()['r']]);
        // Find without script, or Enter on nothing chosen: the results, on the console's front door.
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
        if ($page === 'overview' && $q !== '') {
            $c = LeadPages::search($me, $events, mb_substr($q, 0, 100));
            return $this->render($res, $me, $c + ['scope' => 'root', 'nav' => LeadPages::rootNav('', LeadPages::counts($me, $events), count($events)), 'tabs' => [],
                'crumbs' => [['label' => 'Event lead', 'href' => LeadPages::BASE], ['label' => 'Find']], 'findQ' => $q]);
        }
        $c = Plan::ready() ? LeadPages::root($me, $events, $page, $tab, $req->getQueryParams())
                           : ['title' => LeadPages::ROOT[$page][0], 'secs' => [['k' => 'note', 'tone' => 'warn', 'text' => 'The database needs its latest update before the lead console can open. Run the pending migrations from Settings.']]];
        return $this->render($res, $me, $c + [
            'scope' => 'root', 'nav' => LeadPages::rootNav($page, LeadPages::counts($me, $events), count($events)),
            'tabs' => $tabs ? LeadPages::tabs($page, $tab) : [],
            'crumbs' => [['label' => 'Event lead', 'href' => LeadPages::BASE], ['label' => $c['title']]],
            'purpose' => ($c['purpose'] ?? '') !== '' ? $c['purpose'] : (LeadPages::PURPOSE[$page] ?? ''),
        ]);
    }

    /** GET /lead/requests?r={id} — one of the lead's own requests. */
    private function request(Request $req, Response $res, array $args): Response
    {
        $me = $this->me();
        if ($me === null) throw new HttpNotFoundException($req);
        $c = LeadPages::request($me, (int) $args['id']);
        if (!$c) throw new HttpNotFoundException($req);
        $events = Plan::leadEvents($me['id']);
        return $this->render($res, $me, $c + [
            'scope' => 'root', 'nav' => LeadPages::rootNav('requests', LeadPages::counts($me, $events), count($events)),
            'crumbs' => [['label' => 'Requests', 'href' => LeadPages::BASE . '/requests'], ['label' => $c['title']]],
            'back' => ['label' => 'Requests', 'href' => LeadPages::BASE . '/requests'], 'purpose' => '',
        ]);
    }

    /** GET /lead/event/{id}[/{page}] — one event this member leads, with ?tab= for its views. */
    public function event(Request $req, Response $res, array $args): Response
    {
        $me = $this->me();
        if ($me === null) return $res->withHeader('Location', '/account/login?next=' . LeadPages::BASE)->withStatus(303);
        $this->claim($me);
        $id = (int) $args['id'];
        $e = Plan::ready() ? Plan::event($id) : null;
        // A lead, an owner, or somebody in one of the event's approved roles; anybody else, it does not exist here.
        $st = $e ? EventPermissions::standing($me['id'], $id) : null;
        if (!$e || !$st['any']) throw new HttpNotFoundException($req);
        $page = (string) ($args['page'] ?? 'today');
        // A page of the first build is forwarded to the page and tab that replaced it.
        if (isset(EventPages::ALIASES[$page])) {
            [$to, $tab] = EventPages::ALIASES[$page];
            $q = $req->getQueryParams();
            unset($q['tab']);
            $url = EventPages::url($id, $to, $tab);
            if ($q) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($q);
            return $res->withHeader('Location', $url)->withStatus(301);
        }
        $valid = [];
        foreach (EventPages::NAV as [, $pages]) $valid += $pages;
        // A page the member's permissions do not open is not there for them (EventPermissions).
        if (!isset($valid[$page]) || !EventPermissions::allowsView($st, $page)) throw new HttpNotFoundException($req);
        $tabs = EventPermissions::tabsFor($st, $page, EventPages::TABS[$page] ?? []);
        $tab = (string) ($req->getQueryParams()['tab'] ?? array_key_first($tabs) ?? '');
        if ($tabs && !isset($tabs[$tab])) $tab = (string) array_key_first($tabs);
        $c = EventPages::page($me, $e, $page, $tab, $req->getQueryParams(), $st);
        // A scanning link exists in readable form only now, once (only its hash is stored).
        if (($link = $this->take('lead_door_link')) && $page === 'settings') {
            array_unshift($c['secs'], ['k' => 'note', 'tone' => 'info', 'text' => 'Copy this link now; it is not shown again: ' . $link]);
        }
        $label = $valid[$page][0];
        return $this->render($res, $me, $c + [
            'scope' => 'event', 'eventId' => $id, 'nav' => EventPages::nav($id, $page, EventPages::counts($me, $id), $st),
            'tabs' => $tabs ? EventPages::tabs($id, $page, $tab, $st) : [],
            'instance' => ['name' => (string) $e->title, 'sub' => Workspace::day((string) $e->event_date) . ' · ' . EventPermissions::label($st), 'ini' => Workspace::initials((string) $e->title), 'tone' => 'ok'],
            'siblings' => EventPages::siblings(EventPermissions::events($me['id']), $id, $page, $tab),
            'back' => ['label' => 'Overview', 'href' => LeadPages::BASE],
            'crumbs' => [['label' => 'Event lead', 'href' => LeadPages::BASE], ['label' => (string) $e->title, 'href' => EventPages::url($id)]]
                + ($page === 'today' ? [] : [2 => ['label' => $label]]),
            'kicker' => $page === 'today' ? '' : (string) $e->title,
            'title' => $page === 'today' ? (string) $e->title : $label,
            'purpose' => EventPages::PURPOSE[$page] ?? '',
        ]);
    }

    /** POST /lead/act */
    public function act(Request $req, Response $res): Response
    {
        $me = $this->me();
        if ($me === null) return $res->withHeader('Location', '/account/login?next=' . LeadPages::BASE)->withStatus(303);
        $b = (array) $req->getParsedBody();
        $back = $this->safeBack((string) ($b['back'] ?? LeadPages::BASE));
        if (!Plan::ready()) return $this->back($res, $back, false, 'The database needs its latest update first.');
        $do = (string) ($b['do'] ?? '');
        if ($do === 'goal_describe' && !Plan::may($me['id'], (int) ($b['event'] ?? 0), 'plan.edit')) {
            return $this->back($res, $back, false, 'A lead of this event sets its goals.');
        }
        if ($do === 'goal_describe') {
            $say = mb_substr(trim((string) ($b['say'] ?? '')), 0, 500);
            return $res->withHeader('Location', \AfricaGates\Services\EventPlan\EventPages::url((int) ($b['event'] ?? 0), 'goals', 'setup') . '&say=' . rawurlencode($say))->withStatus(303);
        }
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
            'decision_add' => [Scope::decide($int('event'), (string) ($b['title'] ?? ''), (string) ($b['why'] ?? ''), $me['id']), 'Recorded.', 'event', $int('event')],
            'decision_reverse' => [Scope::reverse($int('decision'), (string) ($b['why'] ?? ''), $me['id']), 'Reversed. Both stay on the record.', 'event', 0],
            'cue_add' => [Scope::addCue($int('event'), $b, $me['id']), 'Added to the running order.', 'event', $int('event')],
            'cue_done' => [Scope::cueDone($int('cue'), true, $me['id']), 'Done. The next cue is now.', 'event', 0],
            'cue_undo' => [Scope::cueDone($int('cue'), false, $me['id']), 'Taken back.', 'event', 0],
            'cue_remove' => [Scope::removeCue($int('cue'), $me['id']), 'Removed.', 'event', 0],
            'sponsor_add' => [Scope::addSponsor($int('event'), $b, $me['id']), 'Sponsor added.', 'event', $int('event')],
            'sponsor_stage' => [Scope::setStage($int('sponsor'), (string) ($b['stage'] ?? ''), $me['id']), 'Moved.', 'event', 0],
            'check_add' => [Scope::addCheck($int('event'), (string) ($b['title'] ?? ''), (string) ($b['owner'] ?? ''), $me['id']), 'Check added.', 'event', $int('event')],
            'check_done' => [Scope::checkDone($int('check'), true, $me['id']), 'Checked.', 'event', 0],
            'check_undo' => [Scope::checkDone($int('check'), false, $me['id']), 'Reopened.', 'event', 0],
            'table_save' => [Scope::saveTable($int('event'), $b, $me['id']), 'Seating saved.', 'event', $int('event')],
            'letters_send' => [Outreach::sendLetters($int('event'), $me['id']), '', 'event', $int('event')],
            'message_send' => [Outreach::sendMessage($int('request'), (string) ($b['audience'] ?? ''), (string) ($b['subject'] ?? ''), (string) ($b['body'] ?? ''), $me['id'], $me['name']), '', 'event', 0],
            'seat' => [Scope::seat($int('table'), (string) ($b['kind'] ?? '') === 'invite' ? 'invite' : 'ticket', $int('ref'), $me['id']), 'Seated.', 'event', 0],
            'seat_free' => [Scope::unseat($int('seat'), $me['id']), 'Seat freed.', 'event', 0],
            'door_issue' => [$this->issueDoor($me, $b), 'Scanning link made.', 'event', $int('event')],
            'door_revoke' => [$this->revokeDoor($me, $b), 'Turned off. It stops working now.', 'event', $int('event')],
            'request_remind' => [$this->remind($me, $int('request')), 'Reminded.', 'request', $int('request')],
            'attend_in' => [$this->attend($me, $b, true), 'Checked in.', 'event', $int('event')],
            'attend_out' => [$this->attend($me, $b, false), 'Checked out. Their hours count now.', 'event', $int('event')],
            'attend_confirm' => [$this->attendConfirm($me, $b), 'Confirmed. Their hours count now.', 'event', $int('event')],
            'vol_invite', 'vol_invite_cancel', 'vol_end', 'finance_give', 'finance_end' => [$this->recruit($me, $b, $do), '', 'event', $int('event')],
            'team_propose' => [Teams::proposeTeam($int('event'), $b, $me), '', 'event', $int('event')],
            'role_propose' => [Teams::proposeRole($int('event'), $b, $me), '', 'event', $int('event')],
            'member_propose' => [Teams::proposeMember($int('event'), $b, $me), '', 'event', $int('event')],
            'proposal_withdraw' => [Teams::withdraw((string) ($b['subject'] ?? ''), $int('id'), $me), '', 'event', $int('event')],
            'decide' => [$this->decide($me, $b), '', 'event', $int('event')],
            'member_end' => [Teams::endMember($int('member'), $int('event'), (string) ($b['reason'] ?? ''), ['kind' => Plan::ACTOR, 'id' => $me['id'], 'name' => $me['name']]), '', 'event', $int('event')],
            'role_archive' => [Teams::archiveRole($int('role'), $int('event'), (string) ($b['reason'] ?? ''), ['kind' => Plan::ACTOR, 'id' => $me['id'], 'name' => $me['name']]), '', 'event', $int('event')],
            'team_suggest' => [$this->suggest($me, $int('event')), '', 'event', $int('event')],
            // The team's loop (EventPlan\TeamLoop): reports, proof, suggestions and their decisions.
            'report_add' => [TeamLoop::report($int('event'), $int('work') ?: null, $me['id'], $me['name'], (string) ($b['state'] ?? ''), $int('confidence'), (string) ($b['note'] ?? '')), '', 'event', $int('event')],
            'work_done' => [TeamLoop::complete($int('work'), $me['id'], (string) ($b['proof'] ?? '')), '', 'event_work', $int('work')],
            'work_return' => [TeamLoop::returnProof($int('work'), $me['id'], (string) ($b['why'] ?? '')), '', 'event_work', $int('work')],
            'work_propose' => [$this->propose($me, $b), '', 'event', $int('event')],
            // Goals, the numbers, the pipeline, rules and changes (EventPlan\Goals, Planner, Pipeline, Rules, Changes).
            'goal_create' => [Goals::create($int('event'), $this->goalInput($b), $me['id']), '', 'event', $int('event')],
            'goal_change' => [$this->goalChange($me, $b), '', 'event', 0],
            'assume_set' => [Planner::setAssumption($int('assumption'), (string) ($b['value'] ?? ''), $me['id']), '', 'event', 0],
            'gate_sign' => [Goals::signGate($int('gate'), $me['id'], (string) ($b['verdict'] ?? ''), (string) ($b['note'] ?? '')), '', 'event', 0],
            'contact_add' => [Pipeline::add($int('event'), $b, $me['id']), '', 'event', $int('event')],
            'contact_import' => [Pipeline::import($int('event'), (string) ($b['list'] ?? ''), $me['id'], array_intersect_key($b, array_flip(['scope', 'owner']))), '', 'event', $int('event')],
            'contact_stage' => [Pipeline::move($int('contact'), (string) ($b['stage'] ?? ''), $me['id']), '', 'event', 0],
            'contact_rate' => [Pipeline::rate($int('contact'), $int('reach'), $me['id']), '', 'event', 0],
            'contact_assign' => [Pipeline::assign($int('contact'), (string) ($b['owner'] ?? ''), $me['id']), '', 'event', 0],
            'work_change' => [$this->workChange($me, $b), '', 'event', 0],
            'change_decide' => [Changes::decide($int('change'), $me['id'], (string) ($b['verdict'] ?? ''), (string) ($b['note'] ?? '')), '', 'event', 0],
            'rule_mode' => [Rules::setMode($int('event'), (string) ($b['rule'] ?? ''), (string) ($b['mode'] ?? ''), $me['id']), '', 'event', $int('event')],
            'rule_clear' => [Rules::clear($int('log'), $me['id']), '', 'event', 0],
            'digest_hour' => [Rules::setDigestHour($int('event'), $int('hour'), $me['id']), '', 'event', $int('event')],
            'rules_run' => [Plan::may($me['id'], $int('event'), 'plan.edit') ? ['ok' => true, 'event' => $int('event'), 'msg' => 'Looked: ' . Rules::run($int('event')) . ' new entries.']
                                                                             : ['ok' => false, 'error' => 'A lead runs the rules.'], '', 'event', $int('event')],
            'work_decide' => [TeamLoop::decide($int('work'), $me['id'], (string) ($b['verdict'] ?? ''), (string) ($b['note'] ?? ''), (string) ($b['proof'] ?? 'none')), '', 'event_work', $int('work')],
            'vtask_add', 'vtask_close', 'vtask_accept' => [$this->volTask($me, $b, $do), '', 'event', $int('event')],
            // The written report (EventPlan\EventReport): a lead saves and submits what is saved;
            // an owner signs it off or sends it back.
            'report_save' => [EventReport::save($int('event'), $b, $me), '', 'event', $int('event')],
            'report_submit' => [EventReport::save($int('event'), (array) (EventReport::of($int('event')) ?? []), $me, true), '', 'event', $int('event')],
            'report_decide' => [EventReport::decide($int('event'), (string) ($b['verdict'] ?? ''), (string) ($b['note'] ?? ''), $me), '', 'event', $int('event')],
            default => [['ok' => false, 'error' => 'That isn’t something this page can do.'], '', '', 0],
        };
        if (!$r['ok']) return $this->back($res, $back, false, (string) ($r['error'] ?? 'That didn’t work.'));
        // A change to an event is recorded AGAINST THE EVENT, so its Reports › Audit can list
        // it; the row it touched travels in the meta.
        $meta = array_intersect_key($b, array_flip(['status', 'owner', 'title', 'name', 'label', 'stage', 'likelihood', 'impact', 'estimate', 'vendor',
                                                    'committed', 'paid', 'budget', 'series', 'type', 'action', 'admin', 'until', 'why', 'capability', 'email',
                                                    'subject', 'verdict', 'reason', 'perms', 'ceiling', 'team_role', 'kind', 'state', 'confidence', 'note', 'proof', 'proof_type']));
        if ($do === 'decide' && isset($r['verdict'])) $meta['verdict'] = $r['verdict'];   // 'changed' is the decision's, not the form's
        if ($type === 'event') {
            $target = (int) ($r['event'] ?? $target);
            foreach (['id', 'work', 'risk', 'line', 'decision', 'cue', 'sponsor', 'check', 'table', 'pass', 'vol', 'hour', 'grant', 'invite', 'volunteer', 'member', 'role', 'team'] as $k) if (!empty($r[$k] ?? $b[$k] ?? null) && $k !== 'event') $meta[$k] = (int) ($r[$k] ?? $b[$k]);
        } else {
            $target = (int) ($r['id'] ?? $target);
        }
        $this->audit->record(null, 'lead.' . $do, $type, $target ?: null, $meta + ['lead' => $me['name'], 'lead_member' => $me['id']]);
        if ($do === 'line_ask' && isset($r['request'])) $back = LeadPages::BASE . '/requests?r=' . $r['request'];
        if ($do === 'request' && isset($r['id'])) $back = LeadPages::BASE . '/requests?r=' . $r['id'];
        if ($do === 'door_issue' && isset($r['link'])) $_SESSION['lead_door_link'] = $r['link'];
        if (in_array($do, ['letters_send', 'message_send'], true)) {
            // Who is left, not only how many went, so a partly-sent batch reads as queued.
            $msg = $r['sent'] . ' sent' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be sent' : '') . '. '
                 . ($r['left'] > 0 ? $r['left'] . ' still to go: press again.' : 'That is everybody.');
        }
        if (isset($r['msg'])) $msg = (string) $r['msg'];
        if ($do === 'request_remind') {
            $msg = ($r['emailed'] ?? 0) > 0 ? 'Reminded. ' . $r['emailed'] . (($r['emailed'] ?? 0) === 1 ? ' person was' : ' people were') . ' emailed.'
                                            : 'Reminder recorded, but no email could be sent. It is still at the top of their Waiting on you.';
        }
        return $this->back($res, $back, true, $do === 'request_act' ? 'Done. ' . (Requests::STATUSES[$r['status'] ?? ''] ?? '') . '.' : $msg);
    }

    // ── writes that are not the plan's ──────────────────────────────────────────────────

    /**
     * A lead recruits for THEIR event (EventPages::recruit): somebody added by email (an approved
     * volunteer at once, anybody else once approved), a role ended, an invitation withdrawn. Every one is checked against
     * the lead's grant on the event in the post — a crafted post about another event, or a
     * grant or invitation that is not this event's, writes nothing. Only the night's volunteer
     * roles: a lead never makes a lead.
     *
     * @return array{ok:bool, error?:string, msg?:string, event?:int}
     */
    private function recruit(array $me, array $b, string $do): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (!Plan::isLead($me['id'], $event)) return ['ok' => false, 'error' => 'That isn’t one of your events.'];
        if (!\AfricaGates\Services\RoleInvites::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $roles = \AfricaGates\Services\RoleInvites::EVENT_VOL_ROLES;
        $cap = (string) ($b['capability'] ?? '');
        $by = ['kind' => 'member', 'id' => $me['id'], 'name' => $me['name']];
        $mail = static fn (array $r): string => !empty($r['emailed']) ? ' We emailed them.' : ' The email could not be sent, so tell them yourself.';
        switch ($do) {
            case 'vol_invite':
                if (!isset($roles[$cap])) return ['ok' => false, 'error' => 'Pick one of the night’s roles.'];
                $r = \AfricaGates\Services\RoleInvites::offer((string) ($b['email'] ?? ''), $cap, $event, (string) ($b['until'] ?? ''), $by);
                if (!$r['ok']) return $r;
                return $r + ['event' => $event, 'msg' => (!empty($r['granted']) ? 'They were already an approved volunteer, so the role is theirs now.' : 'Invitation sent. It is waiting ' . \AfricaGates\Services\RoleInvites::reason((string) ($b['email'] ?? ''), $cap) . ', and switches on by itself after that. It is listed below until then.') . $mail($r)];
            case 'finance_give':
                // ── THE EVENT'S FINANCE ADMIN: THE LEAD ASKS, AN OWNER APPROVES ───────
                // Owner, 13 Nov 2026: the lead knows who on the team keeps the books, but who
                // approves the money is the owner's to agree. So this files a request in the
                // event's change queue (Changes::proposeFinance) and grants nothing; the
                // owner's approval gives it. Never the lead asking, never anybody who leads or
                // owns this event — whoever asks for money is never who approves it.
                $email = strtolower(trim((string) ($b['email'] ?? '')));
                $mine = strtolower(trim((string) \Illuminate\Database\Capsule\Manager::table('gates_users')->where('id', $me['id'])->value('email')));
                if ($email === $mine) {
                    return ['ok' => false, 'error' => 'You lead this event, so its money is approved by somebody else. Ask for another person.'];
                }
                $u = \Illuminate\Database\Capsule\Manager::table('gates_users')->whereRaw('LOWER(email) = ?', [$email])->first(['id']);
                if ($u && (Plan::isLead((int) $u->id, $event) || Teams::isOwner((int) $u->id, $event))) {
                    return ['ok' => false, 'error' => 'They lead or own this event, and whoever asks for money here is never the one who approves it. Choose somebody else.'];
                }
                return \AfricaGates\Services\EventPlan\Changes::proposeFinance($event, $email, (string) ($b['until'] ?? ''), (string) ($b['reason'] ?? ''), $me['id']);
            case 'finance_end':
                return \AfricaGates\Services\EventPlan\Leads::end((int) ($b['grant'] ?? 0), $event, 0, 'event.finance')
                    ? ['ok' => true, 'event' => $event, 'msg' => 'Ended. Spend requests over your limit go to the platform’s finance team again.']
                    : ['ok' => false, 'error' => 'That finance access had already ended.'];
            case 'vol_invite_cancel':
                return \AfricaGates\Services\RoleInvites::cancel((int) ($b['invite'] ?? 0), $event)
                    ? ['ok' => true, 'event' => $event, 'msg' => 'Invitation withdrawn.'] : ['ok' => false, 'error' => 'That invitation had already been used or withdrawn.'];
            default:   // vol_end — only a volunteer role at THIS event
                $g = (int) ($b['grant'] ?? 0);
                $mine = \Illuminate\Database\Capsule\Manager::table('gates_grants')->where('id', $g)->where('scope_kind', 'event')->where('scope_id', $event)
                    ->whereIn('capability', array_keys($roles))->whereNull('revoked_at')->exists();
                return $mine && \AfricaGates\Services\Grants::revoke($g, null)
                    ? ['ok' => true, 'event' => $event, 'msg' => 'Ended. It leaves their workspace on their next page.'] : ['ok' => false, 'error' => 'That role had already ended.'];
        }
    }

    /**
     * An OWNER decides a proposal (Teams::decide holds the rules: an owner of the row's event,
     * never their own proposal, never their own access, always a reason). Ticks travel only
     * when the form drew them, so approving a team cannot clear a role's.
     */
    private function decide(array $me, array $b): array
    {
        $in = array_intersect_key($b, array_flip(['perms', 'ceiling']));
        if (($b['subject'] ?? '') === 'role' && !isset($in['perms'])) $in['perms'] = [];
        // An owner decides as an owner; a lead who is not one decides the medium changes (Tiers).
        $event = (int) (\Illuminate\Database\Capsule\Manager::table(Teams::SUBJECTS[(string) ($b['subject'] ?? '')] ?? 'gates_event_teams')->where('id', (int) ($b['id'] ?? 0))->value('event_id') ?? 0);
        $kind = Teams::isOwner($me['id'], $event) || !Plan::isLead($me['id'], $event) ? Plan::ACTOR : 'lead';
        return Teams::decide((string) ($b['subject'] ?? ''), (int) ($b['id'] ?? 0), (string) ($b['verdict'] ?? ''), (string) ($b['reason'] ?? ''),
            ['kind' => $kind, 'id' => $me['id'], 'name' => $me['name']], $in);
    }

    /** The setup form's answers: a card's "something else" and "another date" win over its default. */
    private function goalInput(array $b): array
    {
        if (!empty($b['metric_other'])) $b['metric'] = $b['metric_other'];
        if (!empty($b['deadline_other'])) $b['deadline'] = $b['deadline_other'];
        $b['assume'] = array_intersect_key((array) ($b['assume'] ?? []), Planner::KEYS);
        return $b;
    }

    /** A goal's target and/or deadline: LARGE changes, each its own request for an owner. */
    private function goalChange(array $me, array $b): array
    {
        $goal = (int) ($b['goal'] ?? 0);
        $out = [];
        if (trim((string) ($b['target'] ?? '')) !== '') $out[] = Changes::propose('target', $goal, (string) $b['target'], (string) ($b['reason'] ?? ''), $me['id']);
        if (trim((string) ($b['deadline'] ?? '')) !== '') $out[] = Changes::propose('goal_deadline', $goal, (string) $b['deadline'], (string) ($b['reason'] ?? ''), $me['id']);
        if (!$out) return ['ok' => false, 'error' => 'Give a new target, a new deadline, or both.'];
        foreach ($out as $r) if (!$r['ok']) return $r;
        return $out[0] + ['msg' => 'Asked. An owner decides; the goal stays as it is until they do.'];
    }

    /** Your own work: a new name is SMALL (applies itself unless a lead objects), a new date MEDIUM. */
    private function workChange(array $me, array $b): array
    {
        $work = (int) ($b['work'] ?? 0);
        $out = [];
        if (trim((string) ($b['title'] ?? '')) !== '') $out[] = Changes::propose('reword', $work, (string) $b['title'], (string) ($b['reason'] ?? ''), $me['id']);
        if (trim((string) ($b['due_at'] ?? '')) !== '') $out[] = Changes::propose('due_shift', $work, (string) $b['due_at'], (string) ($b['reason'] ?? ''), $me['id']);
        if (!$out) return ['ok' => false, 'error' => 'A new name, a new date, or both.'];
        foreach ($out as $r) if (!$r['ok']) return $r;
        return end($out);
    }

    /** A lead's own work goes straight on the plan; anybody else on the team suggests it. */
    private function propose(array $me, array $b): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (Plan::may($me['id'], $event, 'plan.edit')) {
            $r = Plan::addWork($event, $b, $me['id']);
            return $r['ok'] ? $r + ['msg' => 'Added to the plan.'] : $r;
        }
        return TeamLoop::propose($event, $me['id'], $b);
    }

    /** "Suggest a team": kept in the session for the page to draw as proposal forms. Applies nothing. */
    private function suggest(array $me, int $event): array
    {
        if (!Plan::isLead($me['id'], $event)) return ['ok' => false, 'error' => 'Only a lead of this event can ask for a suggested team.'];
        $e = Plan::event($event);
        if (!$e) return ['ok' => false, 'error' => 'That event is not there.'];
        $s = (new TeamSuggest())->suggest($e);
        $_SESSION[TeamPages::SESSION][$event] = $s;
        return ['ok' => true, 'event' => $event, 'msg' => ($s['source'] === 'assistant' ? 'Suggested.' : 'Suggested from the templates.') . ' Change what you like, then propose what you keep.'];
    }

    /** A lead's request is about one of THEIR events; nothing else is offered or accepted. */
    private function newRequest(array $me, array $b): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (!Plan::may($me['id'], $event, 'requests.make')) return ['ok' => false, 'error' => 'Pick one of your events.'];
        return Requests::create('lead', (string) ($b['type'] ?? ''), Plan::ACTOR, $me['id'], $me['name'], $b, 'event', $event);
    }

    private function requestAct(array $me, array $b): array
    {
        $id = (int) ($b['request'] ?? 0);
        $r = Requests::act($id, (string) ($b['action'] ?? ''), Plan::ACTOR, $me['id'], $me['name'], (string) ($b['note'] ?? ''));
        if ($r['ok']) Plan::syncRequest($id);   // a withdrawn spend request puts its line back to "not approved yet"
        return $r;
    }

    /** A lead checks a volunteer with a role at THEIR event in or out (Volunteer\Attendance). */
    private function attend(array $me, array $b, bool $in): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (!Plan::may($me['id'], $event, 'volunteers.checkin')) return ['ok' => false, 'error' => 'Only a lead of this event, or a team role that includes it, can check its volunteers in.'];
        $v = \Illuminate\Database\Capsule\Manager::table('gates_volunteers')->where('id', (int) ($b['vol'] ?? 0))->first();
        if (!$v) return ['ok' => false, 'error' => 'That volunteer was not found.'];
        // A lead's tap makes hours count at once, as a witness. Nobody witnesses themselves
        // (AUDIT-AND-PLAN VO-10): their own visit waits for another lead, like anybody's.
        if ((int) $v->user_id === (int) $me['id']) return ['ok' => false, 'error' => 'Check yourself in from your own volunteer page. Another lead confirms your time.'];
        $r = $in ? Attendance::arrive($v, $event, Attendance::LEAD, $me['name']) : Attendance::leave($v, $event, Attendance::LEAD, $me['name']);
        return $r + ['event' => $event];
    }

    /**
     * Volunteer tasks for THIS event (EventPages::volTasks): post, close, accept. The member must
     * hold `volunteers.tasks` here — a lead does; a team role does if its owner ticked it — and
     * the task or take must be this event's (Tasks::*ForEvent), so a crafted post about another
     * event's work is refused like a missing one.
     */
    private function volTask(array $me, array $b, string $do): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (!Plan::may($me['id'], $event, 'volunteers.tasks')) return ['ok' => false, 'error' => 'Only a lead of this event, or a team role that includes it, can set its volunteer tasks.'];
        $T = \AfricaGates\Services\Volunteer\Tasks::class;
        $r = match ($do) {
            'vtask_add' => $T::postForEvent($event, $b, $me['id'], $me['name']) + ['msg' => 'Posted. The volunteers with a role here see it in their Tasks now.'],
            'vtask_close' => $T::closeForEvent($event, (int) ($b['task'] ?? 0)) ? ['ok' => true, 'msg' => 'Closed. Nobody new can take it.'] : ['ok' => false, 'error' => 'That task is not open on this event.'],
            default => self::takeIsMine((int) ($b['take'] ?? 0), (int) $me['id'])
                ? ['ok' => false, 'error' => 'Another lead accepts your own work.']
                : $T::acceptForEvent($event, (int) ($b['take'] ?? 0), (float) ($b['hours'] ?? 0), $me['name']) + ['msg' => 'Accepted. The hours are on their record.'],
        };
        return $r + ['event' => $event];
    }

    private static function hoursAreMine(int $hourId, int $userId): bool
    {
        return \Illuminate\Database\Capsule\Manager::table('gates_vol_hours as h')->join('gates_volunteers as v', 'v.id', '=', 'h.volunteer_id')
            ->where('h.id', $hourId)->where('v.user_id', $userId)->exists();
    }

    private static function takeIsMine(int $takeId, int $userId): bool
    {
        return \Illuminate\Database\Capsule\Manager::table('gates_vol_task_takes as k')->join('gates_volunteers as v', 'v.id', '=', 'k.volunteer_id')
            ->where('k.id', $takeId)->where('v.user_id', $userId)->exists();
    }

    /** A lead confirms a visit a volunteer recorded alone — only one at their own event. */
    private function attendConfirm(array $me, array $b): array
    {
        $event = (int) ($b['event'] ?? 0);
        $hour = (int) ($b['hour'] ?? 0);
        if (!Plan::may($me['id'], $event, 'volunteers.checkin')) return ['ok' => false, 'error' => 'Only a lead of this event, or a team role that includes it, can confirm its time on site.'];
        $here = \Illuminate\Database\Capsule\Manager::table('gates_vol_attendance')->where('event_id', $event)->where('hours_id', $hour)->exists();
        if (!$here) return ['ok' => false, 'error' => 'That time was not at this event.'];
        if (self::hoursAreMine($hour, (int) $me['id'])) return ['ok' => false, 'error' => 'Another lead confirms your own time.'];
        return Hours::confirm($hour, true, 'member', $me['id'], $me['name']) + ['event' => $event];
    }

    /** A scanning link for one door of the lead's event (EventScanPass: hashed, windowed, ends by itself). */
    private function issueDoor(array $me, array $b): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (!Plan::may($me['id'], $event, 'door.manage')) return ['ok' => false, 'error' => 'Only a lead of this event, or a team role that includes it, can make its scanning links.'];
        $token = EventScanPass::issue($event, (string) (DisplayTime::toStored($b['closes_at'] ?? null) ?? ''), (string) (DisplayTime::toStored($b['opens_at'] ?? null) ?? ''),
            trim((string) ($b['label'] ?? '')), null);
        if ($token === null) return ['ok' => false, 'error' => 'That link could not be made. Check that it closes after it opens.'];
        return ['ok' => true, 'event' => $event, 'link' => SiteUrl::base() . '/door/' . $token];
    }

    private function revokeDoor(array $me, array $b): array
    {
        $event = (int) ($b['event'] ?? 0);
        if (!Plan::may($me['id'], $event, 'door.manage')) return ['ok' => false, 'error' => 'Only a lead of this event, or a team role that includes it, can turn its scanning links off.'];
        return EventScanPass::revoke((int) ($b['pass'] ?? 0), $event) ? ['ok' => true, 'event' => $event, 'pass' => (int) ($b['pass'] ?? 0)]
                                                                     : ['ok' => false, 'error' => 'That link was already off.'];
    }

    private function remind(array $me, int $id): array
    {
        return Requests::remind($id, Plan::ACTOR, $me['id'], $me['name']);
    }

    // ── plumbing ────────────────────────────────────────────────────────────────────────

    /**
     * A role offered to this person's address becomes theirs the moment they open the console,
     * not up to five minutes later (UserAuthMiddleware's pace for every other page). This is
     * the page somebody opens straight after being told "you lead this event"; making them
     * wait, or refresh, on a page saying they lead nothing is how a lead concludes the
     * invitation was broken (owner, 5 Nov 2026: "it says no event").
     */
    private function claim(array $me): void
    {
        try {
            \AfricaGates\Services\RoleInvites::claimFor($me['id']);
            $_SESSION['role_invites_at'] = time();
        } catch (\Throwable) {
            // Never the reason the console fails to open.
        }
    }

    /** @return ?array{id:int, name:string} the signed-in member, the lead's actor (Plan::ACTOR) */
    private function me(): ?array
    {
        $u = $this->accounts->current();
        return $u ? ['id' => (int) $u->id, 'name' => trim((string) ($u->name ?? '')) ?: 'Event lead'] : null;
    }

    private function render(Response $res, array $me, array $ctx): Response
    {
        // Find on every lead page (§2.4). There is no Ask: the assistant is the staff console's,
        // and a lead is not staff.
        $events = Plan::ready() ? Plan::leadEvents($me['id']) : [];
        $ctx['find'] = ['action' => LeadPages::BASE, 'q' => (string) ($ctx['findQ'] ?? ''), 'items' => LeadPages::findItems($me, $events)];
        $ctx['bell'] = Updates::forLead($me, $events);
        return $this->view->render($res, 'lead/page.twig', [
            'page_title' => ($ctx['title'] ?? 'Event lead') . ' — Event lead',
            'shell' => $ctx + ['brand' => 'Events I lead', 'railLabel' => 'Event lead', 'act' => LeadPages::BASE . '/act', 'home' => LeadPages::BASE, 'acctHere' => 'lead',
                'foot' => ['href' => '/account', 'label' => 'Your account'], 'me' => ['name' => $me['name'], 'ini' => Workspace::initials($me['name'])],
                'toast' => $this->take('lead_toast'), 'error' => $this->take('lead_error'),
                'here' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? LeadPages::BASE), PHP_URL_PATH) ?: LeadPages::BASE)
                    . (($q = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY)) !== '' && !str_contains($q, 'ask=') && !str_contains($q, 'new=') ? '?' . $q : '')],
        ]);
    }

    /** Only a page inside the lead console is a place to come back to. */
    private function safeBack(string $back): string
    {
        return preg_match('~^/lead(/[A-Za-z0-9/_-]*)?(\?[A-Za-z0-9=&_%:.-]*)?$~', $back) ? $back : LeadPages::BASE;
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
