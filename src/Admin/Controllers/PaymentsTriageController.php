<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\PaymentTriage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The missing money, in a browser.
 *
 * ── WHY THIS PAGE EXISTS AND THE COMMAND WAS NOT ENOUGH ──────────────────────
 *
 * The diagnostic that finds charges the platform never noticed shipped first as
 * `bin/console payments:triage`. The operator of this platform has no SSH access,
 * which makes that command exactly as useful as not having written it. The
 * codebase already knew this about itself — there is a token-gated webcron
 * endpoint and an HTTP asset builder for the same reason — and the diagnostic was
 * built for an operator who does not exist here.
 *
 * So the logic lives in {@see PaymentTriage} and this is one of two thin callers.
 * Same engine, same answers, reachable from a login.
 *
 * ── WHY THE DESTRUCTIVE PART IS DELIBERATELY AWKWARD ─────────────────────────
 *
 * Looking is a GET and free. Asking the gateway is a POST because it makes
 * outbound calls. Repairing is a POST that requires the operator to have SEEN the
 * verification first, because it confirms orders and moves money — and a page that
 * repairs on load would let a refresh, a prefetch or a shared link do it.
 */
final class PaymentsTriageController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    /** Money is admin+, exactly as the finance screens are. */
    private function blocked(Response $res): ?Response
    {
        if (in_array((string) ($_SESSION['admin_role'] ?? ''), ['superadmin', 'admin'], true)) return null;
        $_SESSION['flash_error'] = 'You don’t have access to payments.';
        return $res->withHeader('Location', '/admin/dashboard')->withStatus(302);
    }

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $q     = $req->getQueryParams();
        $days  = isset($q['days']) && $q['days'] !== '' ? max(1, (int) $q['days']) : 30;
        $ref   = trim((string) ($q['ref'] ?? ''));

        $t = new PaymentTriage();
        $lookup = $ref !== '' ? $t->lookup($ref) : null;

        return $this->render($res, $days, [
            'lookup'    => $lookup,
            'lookup_ref'=> $ref,
            'verified'  => $_SESSION['triage_verified'] ?? null,
        ]);
    }

    /**
     * Ask the gateway which stuck orders were really charged.
     *
     * The result is stashed in the session rather than re-fetched on the repair
     * POST, so that repairing acts on the list the operator actually looked at —
     * not on whatever a second round of network calls happens to return.
     */
    public function verify(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $days = max(1, (int) ((array) $req->getParsedBody())['days'] ?? 30);
        $t = new PaymentTriage();
        $stuck = PaymentTriage::buckets($days)['buckets']['stuck_pending'];

        if (!$t->enabledProviders()) {
            $_SESSION['flash_error'] = 'No payment gateway is configured in this environment, so it cannot be '
                . 'asked. That is very likely also why these orders were never confirmed: verification is a '
                . 'server-to-server call, and without a working key nothing can confirm a payment.';
            return $res->withHeader('Location', '/admin/payments?days=' . $days)->withStatus(302);
        }

        $r = $t->askGateway($stuck);
        $_SESSION['triage_verified'] = [
            'at'      => date('Y-m-d H:i:s'),
            'days'    => $days,
            'clean'   => $r['clean'],
            'charged' => array_map(static fn ($c) => [
                'id'        => (int) $c['order']->id,
                'ref'       => (string) $c['order']->payment_ref,
                'provider'  => $c['provider'],
                'ours'      => (int) $c['order']->amount_naira,
                'gateway'   => $c['amount'],
                'email'     => (string) ($c['order']->donor_email ?? ''),
                'created'   => (string) $c['order']->created_at,
            ], $r['charged']),
        ];

        return $res->withHeader('Location', '/admin/payments?days=' . $days)->withStatus(302);
    }

    /** Confirm the orders the gateway said were paid, and run the normal delivery. */
    public function repair(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $stash = $_SESSION['triage_verified'] ?? null;
        $days  = (int) ($stash['days'] ?? 30);

        if (!$stash || !($stash['charged'] ?? [])) {
            $_SESSION['flash_error'] = 'Nothing verified to repair. Run the gateway check first — repairing '
                                     . 'without looking is how the wrong orders get confirmed.';
            return $res->withHeader('Location', '/admin/payments?days=' . $days)->withStatus(302);
        }

        // Re-read the rows now: the stash carries the DECISION, the database carries
        // the current state, and an order confirmed by a webhook in the meantime must
        // not be touched again.
        $charged = [];
        foreach ($stash['charged'] as $c) {
            $row = \Illuminate\Database\Capsule\Manager::table('gates_donations')
                ->where('id', (int) $c['id'])->where('status', 'pending')->first();
            if ($row) $charged[] = ['order' => $row, 'provider' => (string) $c['provider'], 'amount' => (int) $c['gateway']];
        }

        $r = (new PaymentTriage())->repair($charged);
        unset($_SESSION['triage_verified']);

        try {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'payments.triage_repair', 'donation', null,
                ['fixed' => $r['fixed'], 'attempted' => count($charged)]);
        } catch (\Throwable) {}

        $_SESSION['flash_notice'] = $r['fixed'] . ' order(s) confirmed and put back on the normal path. '
            . 'Votes were credited where they still could be; anything that could not mint is now visible to '
            . 'the refund sweep.'
            . ($r['errors'] ? ' Problems: ' . implode('; ', array_slice($r['errors'], 0, 3)) : '');

        return $res->withHeader('Location', '/admin/payments?days=' . $days)->withStatus(302);
    }

    private function render(Response $res, int $days, array $extra): Response
    {
        $data = PaymentTriage::buckets($days);

        return $this->view->render($res, 'admin/payments-triage.twig', array_merge([
            'page_title' => 'Payment triage',
            'admin_page' => 'finance',
            'days'       => $days,
            'counts'     => $data['counts'],
            'naira'      => $data['naira'],
            'stuck'      => array_slice($data['buckets']['stuck_pending'], 0, 50),
            'owed'       => array_slice($data['buckets']['refund_owed'], 0, 50),
            'health'     => PaymentTriage::health(),
            'providers'  => (new PaymentTriage())->enabledProviders(),
        ], $extra));
    }
}
