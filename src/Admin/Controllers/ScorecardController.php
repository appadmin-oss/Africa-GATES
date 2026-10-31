<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\JudgeScorecard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * What the panel actually gave one nominee.
 *
 * ── THE QUESTION NONE OF THE OTHER THREE SCREENS COULD ANSWER ────────────────
 *
 * {@see ResultReleaseController} shows the index and, since the working was added, the
 * two halves it is made of. {@see JudgingAuditController} shows how a programme judges
 * and who judges it. {@see IntegrityController} shows whether a cycle's result is sound.
 *
 * Every one of them is arithmetic over the marks. None shows a mark. So "what did the
 * judges give her" — the first question anybody asks about an award, and the one a
 * nominee asks when they lose — had no screen at all, on a platform whose whole argument
 * is that its results can be checked.
 *
 * This is one nominee's card: every judge, every criterion, the number they wrote, and
 * what became of it.
 *
 * ── IT DECIDES NOTHING, AND IT HIDES NOTHING ─────────────────────────────────
 *
 * No writes. And marks that do NOT count are shown, marked, with the reason — a recused
 * judge, a judge taken off the panel, a card left short of a criterion. A screen that
 * quietly rendered only the marks that counted would be the same failure in a new place:
 * a nominee with twenty marks on record and a panel of two would look like a nominee
 * nobody judged.
 */
final class ScorecardController
{
    public function __construct(private readonly Twig $view) {}

    public function show(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        // Never throws to the screen. This reads the rubric, the scorer, the recusal
        // table and the change log, any of which a deployment may not have migrated —
        // and somebody opens this page while a result is being challenged.
        $card   = [];
        $failed = false;
        try {
            $card = JudgeScorecard::forNominee($id);
        } catch (\Throwable $e) {
            error_log('[scorecard] ' . $e->getMessage());
            $failed = true;
        }

        return $this->view->render($res, 'admin/scorecard.twig', [
            'page_title' => 'Scorecard',
            'admin_page' => 'result-release',
            'card'       => $card,
            // Said out loud rather than rendered as an empty table. "Nobody has scored
            // this nominee" and "the query failed" look identical and mean opposite
            // things — and on this screen the first is a finding.
            'failed'     => $failed,
        ]);
    }
}
