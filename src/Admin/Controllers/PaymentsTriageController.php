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
        // Not just `stuck_pending`. That excluded the two populations where the
        // disagreement actually hides — rows our own three-day sweep wrote off as
        // failed, and rows a generous checkout window still called "in flight" — which
        // is why this screen could not find what the gateway ledger finds.
        // {@see PaymentTriage::recoverable()}.
        $stuck = PaymentTriage::recoverable($days);

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
            // Charged, but for LESS than the order asked. Shown rather than
            // repaired: minting the full stored quantity against a part-payment
            // would inflate a tally, and refunding somebody who did pay something
            // is not a decision to make automatically either. A person looks.
            'underpaid' => array_map(static fn ($c) => [
                'ref'      => (string) $c['order']->payment_ref,
                'ours'     => (int) $c['order']->amount_naira,
                'gateway'  => $c['amount'],
                'email'    => (string) ($c['order']->donor_email ?? ''),
            ], $r['underpaid']),
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
        // 'failed' as well as 'pending': a row our own three-day sweep wrote off is one
        // of the rows the gateway check just said was paid, and filtering it out here
        // would drop it silently between looking and repairing. PaymentTriage::repair()
        // makes the same widening on its conditional update, so a webhook that got
        // there first still wins.
        $charged = [];
        foreach ($stash['charged'] as $c) {
            $row = \Illuminate\Database\Capsule\Manager::table('gates_donations')
                ->where('id', (int) $c['id'])
                ->whereIn('status', ['pending', 'failed'])->first();
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
            // Refusals are reported, never swallowed. An order the gateway would not
            // stand behind at the moment of repair is a person still owed an answer,
            // and a silent drop is how they stop being anybody's problem.
            . ($r['refused'] ? ' Not confirmed (' . count($r['refused']) . '): '
                             . implode('; ', array_slice($r['refused'], 0, 3)) : '')
            . ($r['errors'] ? ' Problems: ' . implode('; ', array_slice($r['errors'], 0, 3)) : '');

        return $res->withHeader('Location', '/admin/payments?days=' . $days)->withStatus(302);
    }

    /**
     * Mint the votes still owed on confirmed orders — gateway-checked first.
     *
     * The console equivalent (`votes:remint`) is unreachable without a shell, and
     * the operator of this platform does not have one. Same rule on both: the
     * confirmed flag is not sufficient evidence for a manual mint, because this
     * path acts on it long after it was written.
     */
    public function deliver(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $days = max(1, (int) (((array) $req->getParsedBody())['days'] ?? 30));
        $t = new PaymentTriage();

        if (!$t->enabledProviders()) {
            $_SESSION['flash_error'] = 'No payment gateway is configured, so no order can be checked against '
                . 'Paystack — and a manual mint that has not been checked is a mint on trust. Refusing.';
            return $res->withHeader('Location', '/admin/payments?days=' . $days)->withStatus(302);
        }

        $owed = PaymentTriage::buckets($days)['buckets']['refund_owed'];
        $r = $t->deliverOwed($owed);

        try {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'payments.deliver_owed', 'donation', null,
                ['minted' => $r['minted'], 'votes' => $r['votes'], 'refused' => count($r['refused'])]);
        } catch (\Throwable) {}

        $_SESSION['flash_notice'] = $r['minted'] . ' order(s) delivered (' . $r['votes'] . ' vote(s)). '
            . count($r['refused']) . ' could not be — those stay in the refund queue.'
            . ($r['refused'] ? ' First few: ' . implode('; ', array_slice($r['refused'], 0, 3)) : '');

        return $res->withHeader('Location', '/admin/payments?days=' . $days)->withStatus(302);
    }

    /**
     * The gateway's own list, compared with ours.
     *
     * Separate page from triage on purpose. Triage answers "which of our orders
     * broke", which is a repair queue with buttons. This answers "does the money
     * Paystack has match the money we think we have", which is an audit and has
     * none — see {@see \AfricaGates\Services\GatewayLedger} on why nothing here
     * writes.
     *
     * GET renders the form only. Walking a month of transactions is up to twenty
     * paginated calls to Paystack, and a page that did that on load would fire
     * them again on every refresh, every back-button and every prefetch.
     */
    public function ledger(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $days = isset($req->getQueryParams()['days']) ? max(1, (int) $req->getQueryParams()['days']) : 30;

        return $this->view->render($res, 'admin/payments-ledger.twig', [
            'page_title' => 'Gateway ledger',
            'admin_page' => 'payments-ledger',
            'days'       => min($days, \AfricaGates\Services\GatewayLedger::MAX_DAYS),
            'max_days'   => \AfricaGates\Services\GatewayLedger::MAX_DAYS,
            'providers'  => (new PaymentTriage())->enabledProviders(),
            'result'     => $_SESSION['gateway_ledger'] ?? null,
        ]);
    }

    /** Do the pull. POST because it makes up to twenty outbound calls. */
    public function pullLedger(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $days = max(1, (int) (((array) $req->getParsedBody())['days'] ?? 30));
        $days = min($days, \AfricaGates\Services\GatewayLedger::MAX_DAYS);

        $r = (new \AfricaGates\Services\GatewayLedger())->pull($days);

        if (!$r['ok']) {
            $_SESSION['flash_error'] = 'Paystack could not be read: ' . $r['message'];
            return $res->withHeader('Location', '/admin/payments/ledger?days=' . $days)->withStatus(302);
        }

        // Rows are capped for the SCREEN, not for the counts — the totals above
        // each table are computed over everything pulled. A capped table that
        // silently shrank its own total would report a clean window.
        foreach (['agreed', 'mismatch', 'theirs', 'ours'] as $g) {
            $r['groups'][$g] = array_slice($r['groups'][$g], 0, 100);
        }
        $r['at'] = date('Y-m-d H:i:s');
        $_SESSION['gateway_ledger'] = $r;

        try {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'payments.gateway_ledger', 'donation', null,
                ['days' => $days, 'counts' => $r['counts']]);
        } catch (\Throwable) {}

        if ($r['counts']['theirs'] > 0) {
            $_SESSION['flash_error'] = $r['counts']['theirs'] . ' successful charge(s) at Paystack have no record '
                . 'on this platform at all — ₦' . number_format($r['naira']['theirs']) . ' collected from people '
                . 'nothing here knows about.';
        }

        return $res->withHeader('Location', '/admin/payments/ledger?days=' . $days)->withStatus(302);
    }

    private function render(Response $res, int $days, array $extra): Response
    {
        $data = PaymentTriage::buckets($days);

        return $this->view->render($res, 'admin/payments-triage.twig', array_merge([
            'page_title' => 'Payment triage',
            'admin_page' => 'payments',
            'days'       => $days,
            'counts'     => $data['counts'],
            'naira'      => $data['naira'],
            // The list under the counters is the same set the "ask the gateway" button
            // covers. It listed only `stuck_pending`, so an operator pressing a button
            // that asked about 40 orders saw 12 of them — and the written-off rows, the
            // ones the gateway ledger was reporting money against, appeared nowhere.
            'stuck'      => PaymentTriage::recoverableFrom($data['buckets'], 50),
            'owed'       => array_slice($data['buckets']['refund_owed'], 0, 50),
            'health'     => PaymentTriage::health(),
            'providers'  => (new PaymentTriage())->enabledProviders(),
        ], $extra));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DISPUTES — a 16-hour clock, and two buttons
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The disputes waiting on us, soonest deadline first.
     *
     * GET reads Paystack, which is one call rather than the ledger's twenty, so
     * unlike the ledger this can safely load on view — and it must, because the whole
     * problem with disputes is that nobody looks until it is too late. A screen that
     * needed a button pressed before it showed you the clock would be the same fault
     * in a new place.
     */
    public function disputes(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $days = max(1, min(180, (int) ($req->getQueryParams()['days'] ?? 30)));
        $r = (new \AfricaGates\Services\DisputeService())->queue($days);

        return $this->view->render($res, 'admin/payments-disputes.twig', [
            'page_title' => 'Disputes',
            'admin_page' => 'payments-disputes',
            'days'       => $days,
            'hours'      => \AfricaGates\Services\DisputeService::RESPOND_WITHIN_HOURS,
            'result'     => $r,
        ]);
    }

    /**
     * Contest or concede. POST, and never reachable by a link.
     *
     * Resolving a dispute is irreversible and moves money one way or the other, so it
     * cannot be a GET: a prefetch, a shared URL or a back-button would answer somebody
     * else's chargeback. Same reasoning as repair() above, with higher stakes — there
     * is no second attempt at a resolution.
     */
    public function resolveDispute(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $b2     = (array) $req->getParsedBody();
        $id     = trim((string) ($b2['dispute_id'] ?? ''));
        $ref    = trim((string) ($b2['reference'] ?? ''));
        $action = (string) ($b2['action'] ?? '');
        $note   = trim((string) ($b2['message'] ?? ''));
        $back   = '/admin/payments/disputes';

        if ($id === '') {
            $_SESSION['flash_error'] = 'No dispute was named.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $svc = new \AfricaGates\Services\DisputeService();

        if ($action === 'contest') {
            $r = $svc->contest($id, $ref, $note);
            if ($r['ok']) {
                $_SESSION['flash_ok'] = 'Dispute ' . $id . ' contested, receipt attached ('
                                      . ($r['filename'] ?? 'evidence') . ').';
            } else {
                // The STEP is named, because "Paystack refused" is four different
                // problems with four different fixes and the operator has hours, not
                // days, to work out which one they have.
                $_SESSION['flash_error'] = 'Could not contest ' . $id . ' at the "' . $r['step']
                                         . '" step: ' . $r['message'];
            }
        } elseif ($action === 'concede') {
            $amount = trim((string) ($b2['refund_naira'] ?? ''));
            $r = $svc->concede($id, $amount === '' ? null : max(0, (int) $amount), $note);
            $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
                ? 'Dispute ' . $id . ' accepted; the customer is refunded.'
                : ('Could not accept ' . $id . ': ' . $r['message']);
        } else {
            $_SESSION['flash_error'] = 'Choose either "contest" or "accept".';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // Money either way, so it goes in the audit trail with a name against it.
        //
        // This called a `log()` method that AuditService does not have. The try/catch
        // caught the resulting Error along with everything else, so resolving a dispute
        // — the most irreversible money action on the platform — was silently never
        // audited. Exactly the failure shape this codebase keeps finding: a control that
        // looks configured, runs, and records nothing.
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'dispute.' . $action, 'dispute', null, [
            'dispute_id' => $id, 'reference' => $ref, 'ok' => (bool) ($r['ok'] ?? false),
            'step'       => (string) ($r['step'] ?? ''),
        ]);

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * The receipt we would upload, as the operator sees it.
     *
     * Shown before anybody presses contest, because submitting a document to a third
     * party sight-unseen is how a receipt with "not recorded" on every line gets sent
     * as a defence.
     */
    public function disputeEvidence(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $ref   = trim((string) ($req->getQueryParams()['ref'] ?? ''));
        $bytes = \AfricaGates\Services\DisputeEvidence::jpeg($ref);
        if ($bytes === null) {
            $_SESSION['flash_error'] = 'No receipt could be produced for ' . ($ref ?: 'that reference') . '.';
            return $res->withHeader('Location', '/admin/payments/disputes')->withStatus(302);
        }
        $res->getBody()->write($bytes);
        return $res->withHeader('Content-Type', 'image/jpeg')
                   ->withHeader('Cache-Control', 'no-store, private')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
