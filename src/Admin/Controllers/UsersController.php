<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\PointsService;

/**
 * Admin actions on member accounts. Currently just the manual points
 * adjustment — the dispute-resolution lever ("your purchase should have earned
 * points and didn't", "we're compensating you") that otherwise required a raw
 * DB write. Goes through the audited, atomic, ledger-backed PointsService::award
 * so the balance and gates_points_ledger stay in lock-step.
 */
final class UsersController
{
    public function __construct(private readonly AuditService $audit) {}

    /** POST /admin/users/{id}/points — grant (positive) or deduct (negative) points. Admin+ only. */
    public function adjustPoints(Request $req, Response $res, array $args): Response
    {
        $id   = (int) $args['id'];
        $back = '/admin/data/users/' . $id;

        // Granting points mints redeemable (public-tally) votes, so it's an
        // admin+ action — a moderator/editor must not adjust balances.
        if (!Permissions::canManageIntegrity((string) ($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can adjust a member\'s points.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $b     = (array) $req->getParsedBody();
        $delta = (int) ($b['delta'] ?? 0);
        $note  = trim((string) ($b['note'] ?? ''));

        $user = DB::table('gates_users')->where('id', $id)->first();
        if (!$user)         { $_SESSION['flash_error'] = 'Member not found.'; return $res->withHeader('Location', $back)->withStatus(302); }
        if ($delta === 0)   { $_SESSION['flash_error'] = 'Enter a non-zero amount (use a negative number to deduct).'; return $res->withHeader('Location', $back)->withStatus(302); }
        if ($note === '')   { $_SESSION['flash_error'] = 'Add a short reason for the adjustment — it goes in the audit trail.'; return $res->withHeader('Location', $back)->withStatus(302); }

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $newBal  = PointsService::award($id, $delta, 'admin.adjust', 'admin', (string) $adminId, $note);
        if ($newBal === null) {
            $_SESSION['flash_error'] = $delta < 0
                ? 'That deduction would take the balance below zero — nothing was changed.'
                : 'Could not apply the adjustment. Please try again.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $this->audit->record($adminId, 'user.points_adjust', 'user', $id, ['delta' => $delta, 'note' => $note, 'balance_after' => $newBal]);
        $_SESSION['flash_ok'] = sprintf(
            '%s%d points %s %s — new balance %s.',
            $delta > 0 ? '+' : '', $delta,
            $delta > 0 ? 'granted to' : 'deducted from',
            (string) ($user->name ?? ('#' . $id)),
            number_format($newBal)
        );
        return $res->withHeader('Location', $back)->withStatus(302);
    }
}
