<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\PaymentService;
use AfricaGates\Services\RefundDecision;
use AfricaGates\Services\RefundService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Refunds — decided on evidence, operable from a browser.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A PAGE, AND WHY IT LEADS WITH A VERDICT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The automatic path handles one narrow case and correctly refuses the rest — over
 * the per-order ceiling, out of retries, a permanently refused gateway. Every one
 * of those was then left in a state that appeared nowhere a person looks, and there
 * was no way to act on any of them without a shell this deployment does not have.
 * "Left for a human" meant left for a human who was never told and could not have
 * helped anyway.
 *
 * ── THE FRAUD PROBLEM IS THE REASON FOR THE SHAPE ────────────────────────────
 *
 * The commonest refund request on this platform is not fraud and is not a genuine
 * claim either. Somebody starts a checkout, does not finish it, and their bank
 * places a pending authorisation that looks exactly like a charge — so they ask
 * for a refund in complete good faith for money we never received. Paying that out
 * is money leaving for nothing, and a platform that does it once will be asked to
 * do it a thousand times.
 *
 * So this page never offers a refund button next to a claim. It offers a VERDICT,
 * obtained from the gateway at the moment of the click, and the button only appears
 * when that verdict is OWED. The refusal script — including the
 * pending-authorisation explanation, which is what stops the conversation becoming
 * an accusation — is on the page, ready to paste.
 *
 * The override exists, because reality produces cases no rule anticipates. It
 * costs a written reason, it is stamped on the order next to the verdict it
 * contradicted, and it lands in `refund_state = overridden` so it can never be
 * mistaken for a routine refund. Refusing to build it would only push those cases
 * into somebody's personal banking app, where there is no record at all.
 */
final class RefundsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?OtpService $mailer = null,
        private readonly ?AuditService $audit = null,
    ) {}

    /** Money out is superadmin-only. */
    private function blocked(Response $res): ?Response
    {
        if ((string) ($_SESSION['admin_role'] ?? '') !== 'superadmin') {
            $_SESSION['flash_error'] = 'Only a superadmin can issue refunds.';
            return $res->withHeader('Location', '/admin')->withStatus(302);
        }
        return null;
    }

    private function refunds(): RefundService
    {
        return new RefundService(new PaymentService(), $this->mailer);
    }

    public function index(Request $req, Response $res): Response
    {
        $ref     = trim((string) ($req->getQueryParams()['ref'] ?? ''));
        $verdict = null;

        // A verdict is only produced for a reference somebody actually asked
        // about. The queue itself reads the record, so opening this page makes no
        // outbound calls at all — otherwise a hundred-row list is a hundred HTTPS
        // requests and a page nobody opens twice.
        if ($ref !== '') {
            $verdict = (new RefundDecision(new PaymentService()))->for($ref, askGateway: true);
        }

        return $this->view->render($res, 'admin/refunds.twig', [
            'page_title' => 'Refunds',
            'admin_page' => 'refunds',
            'queue'      => RefundDecision::queue(100),
            'ref'        => $ref,
            'verdict'    => $verdict,
            'is_super'   => (string) ($_SESSION['admin_role'] ?? '') === 'superadmin',
            'auto_on'    => RefundService::autoEnabled(),
            'schema_ok'  => RefundService::ready(),
            'max_order'  => RefundService::maxOrderNaira(),
            'max_daily'  => RefundService::maxDailyNaira(),
        ]);
    }

    /**
     * Issue one refund.
     *
     * The guard lives in {@see RefundService::refundByReference()} rather than here,
     * on purpose: a controller is the wrong place for a rule about money, because
     * the next caller — a console command, a support escalation — would have to
     * re-implement it and would eventually re-implement it differently.
     */
    public function issue(Request $req, Response $res): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $b        = (array) $req->getParsedBody();
        $ref      = trim((string) ($b['reference'] ?? ''));
        $override = !empty($b['override']);
        $why      = trim((string) ($b['why'] ?? ''));
        $actor    = 'admin:' . (int) ($_SESSION['admin_id'] ?? 0);
        $back     = '/admin/refunds?ref=' . rawurlencode($ref);

        // An override is destructive in the direction that matters and is meant to
        // be rare, so it carries a typed confirmation as well as a reason.
        if ($override && (string) ($b['confirm'] ?? '') !== 'OVERRIDE') {
            $_SESSION['flash_error'] = 'Type OVERRIDE to confirm refunding against the verdict.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        try {
            $r = $this->refunds()->refundByReference($ref, $actor, $override, $why);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'The refund could not be attempted: ' . $e->getMessage();
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        if (!empty($r['ok'])) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0),
                $override ? 'refund.override' : 'refund.manual', 'donation', 0,
                ['reference' => $ref, 'why' => $why]);
            $_SESSION['flash_ok'] = $r['say'];
        } else {
            // The verdict's own wording, not a generic failure. When the answer is
            // NEVER_PAID this text is the thing that has to be relayed to a person
            // waiting on money, and rewording it here would produce a second,
            // slightly different version of a sensitive script.
            $_SESSION['flash_error'] = $r['outcome'] . ' — ' . $r['say'];
        }

        return $res->withHeader('Location', $back)->withStatus(302);
    }
}
