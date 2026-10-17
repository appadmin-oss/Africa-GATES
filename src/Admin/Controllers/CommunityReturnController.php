<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\CommunityReturnWithdrawals as Wd;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Community return: each edition's rules and milestone, and the withdrawals queue.
 *
 * ── WHY THE EDITION'S RULES ARE SET HERE AND NOT ON THE SETTINGS CARD ────────
 *
 * The Settings card writes GLOBAL rules — what a new edition starts with. The two-stage
 * model is chosen per edition (owner, 17 Oct 2026: opt-in, never by deploy), and nothing
 * else in the console writes a cycle-scope rule set. So the edition's model, rates and
 * milestone target live beside the milestone they govern, and lock with the edition's
 * first ledger entry (CommunityReturnMilestone::rulesLocked()).
 *
 * ── WHO MAY ACT ──────────────────────────────────────────────────────────────
 *
 * The path is deliberately unmapped in Permissions, so the section guard admits SUPERADMIN
 * only — exactly like Payouts beside it. Confirming a milestone releases money to every
 * nominee in an edition and settling a withdrawal moves it; widening that to the `finance`
 * section is the owner's call (AdminNavTest records the page as new and unmapped). The
 * role check below is the second line, matching PayoutsController. Every act is audited,
 * and the console's confirm dialog requires a reason that travels to the audit row.
 */
final class CommunityReturnController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    private function blocked(Response $res, bool $write = true): ?Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $may  = $write ? ['superadmin', 'admin'] : ['superadmin', 'admin', 'viewer'];
        if (in_array($role, $may, true)) return null;

        $_SESSION['flash_error'] = $write
            ? 'Your role can read the community return but not act on it.'
            : 'You don’t have access to the community return.';
        return $res->withHeader('Location', '/admin')->withStatus(302);
    }

    private function back(Response $res, ?int $cycleId = null): Response
    {
        return $res->withHeader('Location', '/admin/community-return' . ($cycleId ? '?cycle=' . $cycleId : ''))
                   ->withStatus(302);
    }

    private function adminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;
        $q = $req->getQueryParams();

        $cycles = [];
        try {
            $cycles = DB::table('gates_award_cycles as cy')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->orderByDesc('cy.id')->limit(40)
                ->get(['cy.id', 'cy.year', 'cy.edition_label', 'cy.status', 'p.title as programme'])->all();
        } catch (\Throwable) {}

        $cycleId = (int) ($q['cycle'] ?? 0);
        if ($cycleId < 1 && $cycles !== []) $cycleId = (int) $cycles[0]->id;

        $status = (string) ($q['status'] ?? Wd::WD_REQUESTED);
        if (!isset(Wd::STATUSES[$status]) && $status !== 'all') $status = Wd::WD_REQUESTED;

        return $this->view->render($res, 'admin/community-return.twig', [
            'page_title'  => 'Community return — Admin',
            'admin_page'  => 'community-return',
            'cycles'      => $cycles,
            'cycle_id'    => $cycleId,
            'progress'    => $cycleId > 0 ? Milestone::progress($cycleId) : null,
            'nominees'    => $cycleId > 0 ? $this->nominees($cycleId) : [],
            'status'      => $status,
            'statuses'    => Wd::STATUSES,
            'queue'       => Wd::queue($status),
            'totals'      => Wd::totals(),
            'terms'       => Wd::terms(),
            'model_names'   => self::MODEL_NAMES,
            'model_choices' => self::MODEL_CHOICES,
            'ladder_text'   => $cycleId > 0 ? $this->ladderText($cycleId) : '',
        ]);
    }

    /** Short names for the rule tile. */
    private const MODEL_NAMES = [
        'threshold' => 'Threshold', 'milestone' => 'Two-stage',
        'flat' => 'Flat', 'tiered' => 'Tiered', 'custom' => 'Custom',
    ];

    /** The model picker, each with what it means in a phrase. */
    private const MODEL_CHOICES = [
        'threshold' => 'Threshold — nothing until qualifying support, then the full share',
        'milestone' => 'Two-stage — a locked share until the edition’s milestone, then the full share',
        'flat'      => 'Flat — the full share on every contribution',
        'tiered'    => 'Tiered — the share rises with what the nominee has raised',
        'custom'    => 'Custom — your own ladder, optionally locked until the milestone',
    ];

    /** The edition's ladder as the form shows it, or '' when it runs none. */
    private function ladderText(int $cycleId): string
    {
        $rules = Milestone::rulesForCycle($cycleId);
        $model = Ret::modelOf($rules);
        return in_array($model, ['tiered', 'custom'], true)
            ? \AfricaGates\Services\CommunityReturnLadder::format($rules, $model) : '';
    }

    /**
     * Every nominee with an entry in the edition, and their wallet.
     *
     * @return list<array{id:int, name:string, wallet:array<string,mixed>}>
     */
    private function nominees(int $cycleId): array
    {
        try {
            $rows = DB::table('gates_community_returns as r')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'r.nominee_id')
                ->where('r.cycle_id', $cycleId)
                ->groupBy('r.nominee_id', 'n.name')
                ->orderBy('n.name')->limit(200)
                ->get(['r.nominee_id', 'n.name']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r->nominee_id, 'name' => (string) ($r->name ?? ('Nominee #' . $r->nominee_id)),
                      'wallet' => Ret::wallet((int) $r->nominee_id)];
        }
        return $out;
    }

    public function saveRules(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $cycleId = (int) ($args['cycle'] ?? 0);
        $r = Milestone::saveEditionRules($cycleId, (array) $req->getParsedBody());

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit?->record($this->adminId(), 'community_return.rules_saved', 'cycle', $cycleId);
        return $this->back($res, $cycleId);
    }

    public function confirm(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $cycleId = (int) ($args['cycle'] ?? 0);
        $body    = (array) $req->getParsedBody();
        $r = Milestone::confirm($cycleId, $this->adminId(), (string) ($body['_reason'] ?? ''));

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit?->record($this->adminId(), 'community_return.milestone_confirmed', 'cycle', $cycleId,
                ['measured_kobo' => $r['measured_kobo'] ?? 0]);
            Milestone::notifyConfirmed($cycleId);
        }
        return $this->back($res, $cycleId);
    }

    public function approve(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = Wd::approve($id, $this->adminId(), (string) (((array) $req->getParsedBody())['note'] ?? ''));

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit?->record($this->adminId(), 'community_return.withdrawal_approved', 'withdrawal', $id);
        return $this->back($res);
    }

    public function pay(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = Wd::markPaid($id, (string) (((array) $req->getParsedBody())['reference'] ?? ''), $this->adminId());

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit?->record($this->adminId(), 'community_return.withdrawal_paid', 'withdrawal', $id);
        return $this->back($res);
    }

    public function reject(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = Wd::reject($id, (string) (((array) $req->getParsedBody())['why'] ?? ''), $this->adminId());

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit?->record($this->adminId(), 'community_return.withdrawal_rejected', 'withdrawal', $id);
        return $this->back($res);
    }
}
