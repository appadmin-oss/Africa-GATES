<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\JudgeRubric;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Authoring the judging rubric.
 *
 * ── THE GAP THIS CLOSES ──────────────────────────────────────────────────────
 *
 * `gates_judge_criteria` is the table the whole scoring system runs on — every ballot,
 * every weighted average, every bias check, every published result — and there was no way
 * to edit it from anywhere. It was written by the installer and by the sandbox seeder and
 * read everywhere else. An operator running a real cycle could not add a criterion, change
 * a weight, fix a description, or retire one.
 *
 * On a platform whose integrity argument is "the criteria are fixed and published before
 * judging starts", that is not a missing convenience: you cannot publish criteria you have
 * no way to author.
 *
 * ── WHY THE SCREEN IS PER-PROGRAMME WITH A GLOBAL DEFAULT ───────────────────
 *
 * Because that is what the scorer already does. {@see JudgeRubric::effective()} mirrors
 * NomineeScoringService exactly — a programme's own criterion overrides the global one with
 * the same reference — and the screen shows the RESULT of that resolution rather than the
 * raw rows, because an invisible override is how a programme ends up judged on criteria
 * nobody meant to give it.
 */
final class RubricController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
    ) {}

    private function role(): string   { return (string) ($_SESSION['admin_role'] ?? ''); }
    private function adminId(): int   { return (int) ($_SESSION['admin_id'] ?? 0); }

    /**
     * The rubric decides how every nominee is scored, so it sits behind the same permission
     * as the rest of the integrity surface rather than behind general content editing.
     */
    private function mayEdit(): bool  { return Permissions::canManageIntegrity($this->role()); }

    /** The scope being edited: a programme id, or null for the global rubric. */
    private function scope(Request $req): ?int
    {
        $raw = trim((string) ($req->getQueryParams()['programme'] ?? ''));
        if ($raw === '' || $raw === 'global') return null;

        $id = (int) $raw;
        if ($id < 1) return null;

        try {
            $exists = DB::table('gates_award_programmes')->where('id', $id)->exists();
        } catch (\Throwable) {
            $exists = false;
        }
        return $exists ? $id : null;
    }

    private function back(Response $res, ?int $programmeId): Response
    {
        $to = '/admin/rubric' . ($programmeId !== null ? '?programme=' . $programmeId : '');
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    // ═══════════════════════════════════════════════════════════════════════

    public function index(Request $req, Response $res): Response
    {
        $scope = $this->scope($req);

        try {
            $programmes = DB::table('gates_award_programmes')->orderBy('title')->get()->all();
        } catch (\Throwable) {
            $programmes = [];
        }

        // Every row in this scope, plus how many ballots each already carries — which is
        // what decides whether it can be deleted or only retired, and the operator should
        // see that BEFORE pressing anything rather than in the message afterwards.
        $rows = [];
        foreach (JudgeRubric::forScope($scope) as $r) {
            $rows[] = ['row' => $r, 'scores' => JudgeRubric::scoreCount((int) $r->id)];
        }

        return $this->view->render($res, 'admin/rubric/index.twig', [
            'page_title'  => 'Judging rubric',
            'admin_page'  => 'rubric',
            'programmes'  => $programmes,
            'scope'       => $scope,
            'rows'        => $rows,
            // What a panel on this programme will ACTUALLY be asked, after the global
            // rows and the programme's own are resolved against each other.
            'effective'   => JudgeRubric::effective($scope),
            'shares'      => JudgeRubric::shares($scope),
            'exposure'    => JudgeRubric::exposure($scope),
            'may_edit'    => $this->mayEdit(),
            'max_weight'  => JudgeRubric::MAX_WEIGHT,
            'max_per_scope' => JudgeRubric::MAX_PER_SCOPE,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $scope = $this->scope($req);
        if (!$this->mayEdit()) {
            $_SESSION['flash_error'] = 'You do not have permission to change the judging rubric.';
            return $this->back($res, $scope);
        }

        $id = (int) ($args['id'] ?? 0);
        $r  = JudgeRubric::save($scope, $id, (array) $req->getParsedBody());

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) {
            $this->audit->record($this->adminId(), 'rubric.save', 'criterion', (int) ($r['id'] ?? 0),
                                 ['programme' => $scope]);
        }
        return $this->back($res, $scope);
    }

    public function retire(Request $req, Response $res, array $args = []): Response
    {
        $scope = $this->scope($req);
        if (!$this->mayEdit()) {
            $_SESSION['flash_error'] = 'You do not have permission to change the judging rubric.';
            return $this->back($res, $scope);
        }

        $id = (int) ($args['id'] ?? 0);
        $r  = JudgeRubric::retire($scope, $id);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) {
            $this->audit->record($this->adminId(),
                                 $r['deleted'] ? 'rubric.delete' : 'rubric.retire',
                                 'criterion', $id, ['programme' => $scope]);
        }
        return $this->back($res, $scope);
    }

    public function restore(Request $req, Response $res, array $args = []): Response
    {
        $scope = $this->scope($req);
        if (!$this->mayEdit()) {
            $_SESSION['flash_error'] = 'You do not have permission to change the judging rubric.';
            return $this->back($res, $scope);
        }

        $id = (int) ($args['id'] ?? 0);
        $r  = JudgeRubric::restore($scope, $id);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) {
            $this->audit->record($this->adminId(), 'rubric.restore', 'criterion', $id,
                                 ['programme' => $scope]);
        }
        return $this->back($res, $scope);
    }
}
