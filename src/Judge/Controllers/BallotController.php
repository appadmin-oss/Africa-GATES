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
            'no_assignments' => $forceEmpty || empty($data['programmes']),
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
}
