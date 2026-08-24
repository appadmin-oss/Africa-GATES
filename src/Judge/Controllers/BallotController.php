<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Judge\Services\JudgeService;

class BallotController
{
    public function __construct(
        private readonly Twig $view,
        private readonly JudgeService $judges,
    ) {}

    public function dashboard(Request $req, Response $res): Response
    {
        $judgeId = (int)$_SESSION['judge_id'];
        return $this->renderHome($res, $judgeId);
    }

    /** Shared judge-home render — used by both /judge and the no-assignment ballot fallback. */
    private function renderHome(Response $res, int $judgeId, bool $forceEmpty = false): Response
    {
        $data  = $this->judges->dashboard($judgeId);
        $judge = $this->judges->findById($judgeId);
        return $this->view->render($res, 'judge/dashboard.twig', array_merge($data, [
            'page_title'     => 'Judges — Africa GATES',
            'judge'          => $judge ? (array)$judge : null,
            // Counted from the REAL panels, not from every card on the page. The practice
            // programme is appended to every active judge, so `empty($data['programmes'])`
            // stopped ever being true — and a judge who has not been rostered onto anything
            // would have been shown a practice ballot with no hint that it was the only
            // thing there.
            'no_assignments' => $forceEmpty || ($data['overview']['programmes'] ?? 0) < 1,
            'has_practice'   => !$forceEmpty && array_filter(
                (array) ($data['programmes'] ?? []),
                static fn (array $b): bool => !empty($b['is_practice'])
            ) !== [],
        ]));
    }

    public function ballot(Request $req, Response $res, array $args = []): Response
    {
        $judgeId = (int)$_SESSION['judge_id'];
        $progs = $this->judges->programmes($judgeId);
        if (!$progs) {
            return $this->renderHome($res, $judgeId, true);
        }
        $progId = isset($args['programmeId']) ? (int)$args['programmeId'] : (int)$progs[0]['id'];
        // Ensure the judge is assigned to this programme
        $assigned = array_filter($progs, fn($p) => (int)$p['id'] === $progId);
        if (!$assigned) {
            return $res->withHeader('Location', '/judge/ballot')->withStatus(302);
        }
        $ballot = $this->judges->ballot($judgeId, $progId);
        return $this->view->render($res, 'judge/ballot.twig', [
            'page_title' => 'Score nominees — Africa GATES Judges',
            'programme' => reset($assigned),
            'programmes' => $progs,
            'ballot' => $ballot,
        ]);
    }

    /**
     * Produce (or return) the dossier map for one nominee.
     *
     * ── THE AUTHORISATION IS THE INTERESTING PART ───────────────────────────
     *
     * A judge may only ask about a nominee on a panel they sit on. Not because the map is
     * secret — the dossier behind it is already visible to them — but because without the
     * check this endpoint would summarise ANY nominee by id, including entries in a
     * programme this judge was deliberately not assigned to, and including ones withheld
     * for a conflict of interest. That is Broken Access Control wearing a helpful hat.
     *
     * `evidenceFor()` already resolves evidence → nominee → category → cycle → programme
     * against this judge's assignments, so the same question is asked here directly.
     */
    public function orient(Request $req, Response $res, array $args): Response
    {
        $judgeId   = (int) ($_SESSION['judge_id'] ?? 0);
        $nomineeId = (int) ($args['nomineeId'] ?? 0);

        $json = static function (Response $res, array $body, int $code = 200): Response {
            $res->getBody()->write((string) json_encode($body));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };

        if (!$this->judges->mayJudgeNominee($judgeId, $nomineeId)) {
            // Deliberately the same answer as "no such nominee". Distinguishing them would
            // let somebody enumerate which entries exist in a programme they cannot see.
            return $json($res, ['ok' => false,
                'message' => 'That nominee is not on your ballot.'], 404);
        }

        $r = \AfricaGates\Services\JudgeAssist::forNominee($nomineeId);

        return $json($res, [
            'ok'      => (bool) $r['ok'],
            'map'     => $r['map'],
            'message' => (string) $r['message'],
        ]);
    }

    public function saveScore(Request $req, Response $res, array $args): Response
    {
        $judgeId = (int)$_SESSION['judge_id'];
        $nomineeId = (int)$args['nomineeId'];
        $b = (array)$req->getParsedBody();
        $scores = (array)($b['scores'] ?? []);
        $notes = isset($b['notes']) ? trim((string)$b['notes']) : null;
        $result = $this->judges->saveScore($judgeId, $nomineeId, $scores, $notes);
        $res->getBody()->write(json_encode($result));
        return $res->withHeader('Content-Type', 'application/json');
    }

    /** Record a programme-level conflict-of-interest recusal (server-side). */
    public function declareConflict(Request $req, Response $res, array $args): Response
    {
        $judgeId = (int)$_SESSION['judge_id'];
        $programmeId = (int)$args['programmeId'];
        $b = (array)$req->getParsedBody();
        $this->judges->declareConflict($judgeId, $programmeId, isset($b['reason']) ? (string)$b['reason'] : null);
        $_SESSION['flash_ok'] = 'Conflict of interest recorded — you are recused from scoring this programme.';
        return $res->withHeader('Location', '/judge')->withStatus(302);
    }

    /** Withdraw a conflict-of-interest recusal declared in error, re-opening scoring. */
    public function withdrawConflict(Request $req, Response $res, array $args): Response
    {
        $judgeId = (int)$_SESSION['judge_id'];
        $programmeId = (int)$args['programmeId'];
        $this->judges->withdrawConflict($judgeId, $programmeId);
        $_SESSION['flash_ok'] = 'Conflict of interest withdrawn — you can score this programme again.';
        return $res->withHeader('Location', '/judge/ballot/' . $programmeId)->withStatus(302);
    }
}
