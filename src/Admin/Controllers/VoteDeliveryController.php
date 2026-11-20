<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\VoteProof;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Vote delivery — the proof, and the repair, in a browser.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A PAGE AND NOT A COMMAND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no SSH on this deployment. `votes:proof` and `votes:remint` were built
 * as console commands, which made them unreachable by the only person who needs
 * them — so every supporter still owed votes stayed owed them, and the operator
 * answering those messages had no way to act.
 *
 * That is not a new constraint. It is the constraint this codebase was designed
 * around: `/__setup/migrate` exists for it, `/__setup/checkout` exists for it, and
 * {@see \AfricaGates\Services\PaymentReconciler} was extracted from its console
 * command precisely so the admin screen and the CLI could run the same engine.
 * Shipping the incident repair as a shell-only tool repeated a mistake this
 * project had already made and already fixed once.
 *
 * ── WHAT THE PAGE IS FOR, IN ORDER ───────────────────────────────────────────
 *
 *   1. ANSER "IS IT ACTUALLY FIXED?" honestly, in a number that can be shown to
 *      somebody else. It reads vote ROWS, not the order's counter, so it is
 *      capable of contradicting us.
 *   2. LET THEM FIX IT without a shell — check first, then deliver.
 *   3. GIVE THEM SOMETHING TO SEND. Every outstanding order links to the
 *      member-facing proof page, so a reply can carry a link rather than a promise.
 *
 * ── CHECK, THEN DELIVER ──────────────────────────────────────────────────────
 *
 * The same shape as reconciliation, for the same reason: the button that fixes
 * forty orders must not also be the button that quietly touches a forty-first
 * nobody looked at. Delivering carries a typed confirmation because it writes to a
 * live public tally; checking does not, because its entire purpose is to be safe
 * to press.
 *
 * The result is stashed in the SESSION and read after a redirect. Minting is a set
 * of writes with side effects on a public leaderboard, and a browser refresh must
 * not repeat it — post-redirect-get is what stops that.
 */
final class VoteDeliveryController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    /** Only a superadmin may move a public tally. */
    private function blocked(Response $res): ?Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        if ($role !== 'superadmin') {
            $_SESSION['flash_error'] = 'Only a superadmin can deliver votes — it writes to a public tally.';
            return $res->withHeader('Location', '/admin')->withStatus(302);
        }
        return null;
    }

    public function index(Request $req, Response $res): Response
    {
        $days = $req->getQueryParams()['days'] ?? null;
        $days = $days !== null && $days !== 'all' ? max(1, (int) $days) : null;

        $tally = VoteProof::ready()
            ? VoteProof::tally($days)
            : ['ok' => false, 'error' => 'The schema is not migrated yet — run /__setup/migrate first.'];

        // A single reference the operator pasted in, so they can answer one
        // person's message without leaving the page.
        $lookup = null;
        $ref    = trim((string) ($req->getQueryParams()['ref'] ?? ''));
        if ($ref !== '') $lookup = VoteProof::forReference($ref);

        // Read once and cleared, so a refresh does not re-show a stale run as if
        // it had just happened.
        $run = $_SESSION['vote_delivery_run'] ?? null;
        unset($_SESSION['vote_delivery_run']);

        return $this->view->render($res, 'admin/vote-delivery.twig', [
            'page_title' => 'Vote delivery',
            'admin_page' => 'vote-delivery',
            'tally'      => $tally,
            'days'       => $days,
            'ref'        => $ref,
            'lookup'     => $lookup,
            'run'        => $run,
            'is_super'   => ($_SESSION['admin_role'] ?? '') === 'superadmin',
        ]);
    }

    /** Check or deliver. Check is safe; deliver needs a typed confirmation. */
    public function deliver(Request $req, Response $res): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $b     = (array) $req->getParsedBody();
        $apply = ($b['mode'] ?? 'check') === 'apply';

        if ($apply && ($b['confirm'] ?? '') !== 'DELIVER') {
            $_SESSION['flash_error'] = 'Type DELIVER to confirm. This writes votes to a live public tally.';
            return $res->withHeader('Location', '/admin/vote-delivery')->withStatus(302);
        }

        try {
            $run = VoteProof::deliverOwed($apply, 200);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Delivery could not run: ' . $e->getMessage();
            return $res->withHeader('Location', '/admin/vote-delivery')->withStatus(302);
        }

        $_SESSION['vote_delivery_run'] = $run;
        if ($apply) {
            // Audited, because it is a write to a public tally taken by a person.
            // "Who delivered these and when" has to be answerable months later by
            // somebody who was not there.
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'votes.deliver', 'donation', 0, [
                'delivered' => $run['delivered'], 'votes' => $run['votes'], 'blocked' => $run['blocked'],
            ]);
            $_SESSION['flash_ok'] = $run['say'];
        } else {
            $_SESSION['flash_ok'] = $run['say'];
        }

        return $res->withHeader('Location', '/admin/vote-delivery')->withStatus(302);
    }
}
