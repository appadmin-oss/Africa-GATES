<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\VoteRecoveryService as Recover;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Repairing votes the platform dropped — reachable, and impossible to do quietly.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS PAGE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see \AfricaGates\Services\VoteRecoveryService} is complete, careful and fully
 * tested — twenty-three tests hold its derivation, its cap, its two-person rule and
 * its reversal. It was reachable from exactly one place: `bin/console votes:recover`.
 *
 * There is no SSH on this deployment. So the whole mechanism was inert.
 *
 * And it could not have worked even from a shell. The command's own docblock says
 * "`approve` happens in the admin panel where the approver is an authenticated
 * person, because identity on a shell is not evidence" — and there was no admin
 * panel. {@see Recover::apply()} refuses anything not `approved`, so no batch could
 * ever be applied by anybody, by any route.
 *
 * This is the same fault {@see VoteDeliveryController} was written to fix, whose own
 * docblock says shipping an incident repair as a shell-only tool "repeated a mistake
 * this project had already made and already fixed once". `votes:proof` and
 * `votes:remint` were brought into the browser. `votes:recover` was left behind.
 *
 * ── THE PART THAT IS NOT PLUMBING ────────────────────────────────────────────
 *
 * The service's doctrine lists six controls and calls none of them ceremony. Five
 * were enforced in code. The sixth — "public disclosure of every applied batch" —
 * was a method nothing called, so making this mechanism reachable WITHOUT publishing
 * its use would have been strictly worse than leaving it dead: a way to add votes to
 * a public tally, quietly. `Recover::disclosureFor()` is now read on the nominee's
 * own page, and no route here may be added without that staying true.
 *
 * ── THE SHAPE, AND WHY EVERY STEP IS A SEPARATE PRESS ────────────────────────
 *
 * open → screen → submit → approve → apply, with reject and void as the two ways
 * out. They are separate routes rather than one form with a mode, because the
 * approver is required to be a different person from the preparer: a single screen
 * that carried the whole workflow would invite one person to walk it end to end and
 * discover only at the last press that they are not allowed to.
 *
 * Applying carries a typed confirmation for the same reason delivery does — it
 * writes to a live public tally — and every action redirects, because a browser
 * refresh must never repeat a write with a leaderboard behind it.
 */
final class VoteRecoveryController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    /**
     * Superadmin only, on every route including the reads.
     *
     * The queue names nominees against outage windows, which is a map of where a
     * result is softest. Same bar as refunds and vote delivery: this moves a public
     * tally.
     */
    private function blocked(Response $res): ?Response
    {
        if ((string) ($_SESSION['admin_role'] ?? '') !== 'superadmin') {
            $_SESSION['flash_error'] = 'Only a superadmin can work vote recovery — it writes to a public tally.';
            return $res->withHeader('Location', '/admin')->withStatus(302);
        }
        return null;
    }

    private function me(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    private function back(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    /** Record every step, whatever it was. A repair nobody can audit is not a repair. */
    private function note(string $action, int $batchId, array $meta = []): void
    {
        try { $this->audit?->record($this->me(), $action, 'vote_recovery_batch', $batchId, $meta); }
        catch (\Throwable) {}
    }

    // ══ the queue ════════════════════════════════════════════════════════════

    public function index(Request $req, Response $res): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $days = max(1, (int) ($req->getQueryParams()['days'] ?? 7));

        return $this->view->render($res, 'admin/vote-recovery/index.twig', [
            'page_title' => 'Vote recovery',
            'admin_page' => 'vote-recovery',
            'batches'    => Recover::recent(),
            'cycles'     => Recover::cycles(),
            // The number the whole feature exists to serve, and the one that should be
            // falling. A page that only offered the repair would let an operator patch
            // the same outage every month and never see that it is the same outage.
            'health'     => Recover::deliveryHealth($days),
            'days'       => $days,
        ]);
    }

    /** Open a draft for one outage window. */
    public function open(Request $req, Response $res): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $b = (array) $req->getParsedBody();
        $r = Recover::open(
            (int) ($b['cycle_id'] ?? 0),
            trim((string) ($b['window_from'] ?? '')),
            trim((string) ($b['window_to'] ?? '')),
            trim((string) ($b['incident_note'] ?? '')),
            $this->me(),
        );

        if (!($r['ok'] ?? false)) {
            // The service's refusals are written to be read by a person — STILL_OPEN
            // explains that re-sending is the better repair, NO_CANDIDATES that there
            // is no evidence we failed anybody. Passing them through verbatim is the
            // whole point; a generic "could not open" would throw away the answer.
            $_SESSION['flash_error'] = (string) ($r['message'] ?? 'That batch could not be opened.');
            return $this->back($res, '/admin/vote-recovery');
        }

        $this->note('recovery.open', (int) $r['batch_id'],
                    ['reference' => $r['reference'] ?? '', 'candidates' => $r['candidates'] ?? 0]);
        $_SESSION['flash_ok'] = 'Draft ' . ($r['reference'] ?? '') . ' opened with '
                              . (int) ($r['candidates'] ?? 0) . ' dropped attempts.';

        return $this->back($res, '/admin/vote-recovery/' . (int) $r['batch_id']);
    }

    // ══ one batch ════════════════════════════════════════════════════════════

    public function show(Request $req, Response $res, array $args): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $id = (int) ($args['id'] ?? 0);
        $batch = Recover::batch($id);
        if (!$batch) {
            $_SESSION['flash_error'] = 'That batch could not be found.';
            return $this->back($res, '/admin/vote-recovery');
        }

        // Screened on every view, not stored from the last one. The findings are
        // claims about live data — a nominee's cap moves as their real votes do, and
        // an approver reading a screen from three days ago is reading fiction.
        $screen = Recover::screen($id);

        $me = $this->me();

        return $this->view->render($res, 'admin/vote-recovery/show.twig', [
            'page_title' => 'Recovery ' . (string) ($batch->reference ?: '#' . $id),
            'admin_page' => 'vote-recovery',
            'batch'      => (array) $batch,
            'rows'       => Recover::rowsByNominee($id),
            // WHY each delivery failed, and why anything was refused. Both columns have
            // been written since this feature shipped and read by nothing — so the
            // two-person review, which the doctrine calls the strongest control here,
            // was made on counts alone. A mailbox that was full is a different decision
            // from an address that does not exist.
            'why_failed'   => Recover::whyItFailed($id),
            'why_rejected' => Recover::whyRejected($id),
            'screen'     => $screen,
            // Whether THIS admin may approve, worked out here rather than in the
            // template: the rule is "not the preparer, not the submitter", and a
            // template that re-derived it would be a second copy of a two-person rule.
            'may_approve' => (string) $batch->status === 'submitted'
                             && $me > 0
                             && $me !== (int) $batch->created_by
                             && $me !== (int) $batch->submitted_by,
            // Said separately from `may_approve` so the screen can explain the refusal
            // rather than just hiding the button. A button that vanishes teaches
            // nobody that a second person is required.
            'is_preparer' => $me > 0 && ($me === (int) $batch->created_by || $me === (int) $batch->submitted_by),
            'blocking'    => (int) ($screen['stats']['blocking'] ?? 0),
        ]);
    }

    public function submit(Request $req, Response $res, array $args): Response
    {
        return $this->step($req, $res, $args, 'submit');
    }

    public function approve(Request $req, Response $res, array $args): Response
    {
        return $this->step($req, $res, $args, 'approve');
    }

    public function reject(Request $req, Response $res, array $args): Response
    {
        return $this->step($req, $res, $args, 'reject');
    }

    public function void(Request $req, Response $res, array $args): Response
    {
        return $this->step($req, $res, $args, 'void');
    }

    /**
     * Write the votes.
     *
     * Separate from {@see step()} because of the typed confirmation: this is the one
     * press that puts rows on a public tally, and it is the only one that cannot be
     * undone without also being disclosed as a reversal.
     */
    public function apply(Request $req, Response $res, array $args): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $id = (int) ($args['id'] ?? 0);
        $b  = (array) $req->getParsedBody();
        $to = '/admin/vote-recovery/' . $id;

        if (trim((string) ($b['confirm'] ?? '')) !== 'APPLY') {
            $_SESSION['flash_error'] = 'Type APPLY to confirm. This writes votes to a live public tally.';
            return $this->back($res, $to);
        }

        $r = Recover::apply($id, $this->me());
        if (!($r['ok'] ?? false)) {
            $_SESSION['flash_error'] = (string) ($r['message'] ?? 'That batch could not be applied.');
            return $this->back($res, $to);
        }

        $this->note('recovery.apply', $id,
                    ['applied' => $r['applied'] ?? 0, 'rejected' => $r['rejected'] ?? 0]);
        $_SESSION['flash_ok'] = (int) ($r['applied'] ?? 0) . ' vote'
                              . ((int) ($r['applied'] ?? 0) === 1 ? '' : 's') . ' written'
                              . ((int) ($r['rejected'] ?? 0) > 0
                                  ? ', ' . (int) $r['rejected'] . ' rejected at apply time' : '')
                              . '. Every one is now named on the nominee\'s own page.';

        return $this->back($res, $to);
    }

    /**
     * The steps that differ only in which service call they make and what they need.
     *
     * Kept as one method because the surrounding work — the guard, the redirect, the
     * audit line, passing the service's own refusal through verbatim — is identical,
     * and four copies of it is four places for one of them to lose the audit line.
     */
    private function step(Request $req, Response $res, array $args, string $what): Response
    {
        if ($stop = $this->blocked($res)) return $stop;

        $id     = (int) ($args['id'] ?? 0);
        $b      = (array) $req->getParsedBody();
        $reason = trim((string) ($b['reason'] ?? ''));
        $to     = '/admin/vote-recovery/' . $id;
        $me     = $this->me();

        $r = match ($what) {
            'submit'  => Recover::submit($id, $me),
            'approve' => Recover::approve($id, $me, $reason),
            'reject'  => Recover::reject($id, $me, $reason),
            'void'    => Recover::void($id, $me, $reason),
        };

        if (!($r['ok'] ?? false)) {
            $_SESSION['flash_error'] = (string) ($r['message'] ?? 'That could not be done.');
            return $this->back($res, $to);
        }

        $this->note('recovery.' . $what, $id, array_filter([
            'reason'   => $reason,
            'reversed' => $r['reversed'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''));

        $_SESSION['flash_ok'] = match ($what) {
            'submit'  => 'Sent for approval. A second superadmin has to agree before anything is written.',
            'approve' => 'Approved. Nothing is on the tally yet — applying is a separate press.',
            'reject'  => 'Rejected, with the reason on the record.',
            'void'    => (int) ($r['reversed'] ?? 0) . ' vote' . ((int) ($r['reversed'] ?? 0) === 1 ? '' : 's')
                       . ' taken back off the tally. The batch and the reason stay on the record, and the '
                       . 'reversal is disclosed.',
        };

        return $this->back($res, $to);
    }
}
