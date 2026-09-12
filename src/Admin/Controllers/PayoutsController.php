<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\ReferralPayout;
use AfricaGates\Services\ReferralService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Paying members what their referrals earned.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS SCREEN AND NOT A BUTTON ON THE FINANCE PANEL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Finance → Referrals panel shows what is owed and deliberately carries no "mark as
 * paid" control. `HANDOFF.md` §4 left the payout route open on a question a button cannot
 * answer — how money actually leaves — and stamping `paid_out_at` without a transfer behind
 * it makes the ledger claim somebody was paid while destroying the evidence that they were
 * not.
 *
 * So paying is its own act with its own record: the member asks, naming the account; an
 * admin transfers the money by whatever means the organisation uses; and marking it paid
 * requires the transfer REFERENCE. That reference is the whole mechanism — it is what a
 * bank statement can be reconciled against, and without it "paid" means only that somebody
 * clicked.
 *
 * Superadmin and admin only. A moderator runs programmes; moving money is not that.
 */
final class PayoutsController
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
            ? 'Your role can read payouts but not settle them.'
            : 'You don’t have access to payouts.';
        return $res->withHeader('Location', '/admin')->withStatus(302);
    }

    private function back(Response $res): Response
    {
        return $res->withHeader('Location', '/admin/payouts')->withStatus(302);
    }

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        $status = (string) (($req->getQueryParams()['status'] ?? 'requested'));
        if (!isset(ReferralPayout::STATUSES[$status]) && $status !== 'all') $status = 'requested';

        return $this->view->render($res, 'admin/payouts.twig', [
            'page_title' => 'Referral payouts — Admin',
            'admin_page' => 'payouts',
            'status'     => $status,
            'statuses'   => ReferralPayout::STATUSES,
            'rows'       => ReferralPayout::queue($status),
            'totals'     => ReferralPayout::totals(),
            // What is owed overall, so this screen and the Finance panel cannot disagree.
            'liability'  => ReferralService::liability(1),
            'min_naira'  => ReferralPayout::MIN_NAIRA,
        ]);
    }

    public function pay(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $id = (int) ($args['id'] ?? 0);
        $r  = ReferralPayout::markPaid(
            $id, (string) (((array) $req->getParsedBody())['reference'] ?? ''),
            (int) ($_SESSION['admin_id'] ?? 0)
        );

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'referral.payout_paid', 'payout', $id);
        }
        return $this->back($res);
    }

    public function reject(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $id = (int) ($args['id'] ?? 0);
        $r  = ReferralPayout::reject(
            $id, (string) (((array) $req->getParsedBody())['why'] ?? ''),
            (int) ($_SESSION['admin_id'] ?? 0)
        );

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'referral.payout_rejected', 'payout', $id);
        }
        return $this->back($res);
    }
}
