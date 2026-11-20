<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\Recognitions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/admin/recognitions` — the ONE way a recognition changes after issue: withdrawal (Phase 6,
 * REFERENCE §11). A recognition is never edited and never deleted; withdrawing it needs a
 * reason, writes the public log ({@see Recognitions::withdraw()}) and an audit row.
 *
 * Linked from the result-release screen, where recognitions are issued. Unmapped in
 * `Permissions::PATH_SECTIONS`, so the guard fails it CLOSED to superadmin — withdrawing a
 * published honour is a governance act, and mapping it to a section is an access decision
 * for the owner, not something to ride along inside this feature.
 */
class RecognitionsController
{
    public function __construct(private readonly Twig $view, private readonly AuditService $audit) {}

    public function index(Request $req, Response $res): Response
    {
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
        return $this->view->render($res, 'admin/recognitions/index.twig', [
            'page_title'  => 'Recognitions — Admin',
            'admin_page'  => 'result-release',
            'q'           => $q,
            'rows'        => $q !== '' ? Recognitions::search($q, 100) : Recognitions::recent(100),
            'count'       => Recognitions::count(),
            'withdrawals' => Recognitions::withdrawals(20),
        ]);
    }

    public function withdraw(Request $req, Response $res, array $args): Response
    {
        $id     = (int) ($args['id'] ?? 0);
        $b      = (array) $req->getParsedBody();
        $reason = trim((string) ($b['reason'] ?? $b['_reason'] ?? ''));
        $admin  = (int) ($_SESSION['admin_id'] ?? 0);
        if ($reason === '') {
            $_SESSION['flash_error'] = 'A withdrawal needs a reason: it is published in the public log.';
        } elseif (Recognitions::withdraw($id, $reason, $admin)) {
            $this->audit->record($admin, 'recognition.withdraw', 'recognition', $id, ['reason' => $reason]);
            $_SESSION['flash_ok'] = 'Withdrawn. The reason is in the public log at /recognitions/withdrawn.';
        } else {
            $_SESSION['flash_error'] = 'That recognition was not withdrawn — it may already have been.';
        }
        return $res->withHeader('Location', '/admin/recognitions')->withStatus(303);
    }
}
