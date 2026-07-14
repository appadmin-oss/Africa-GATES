<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\LegalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Admin editor for legal / policy documents (gates_legal_docs). Replaces the
 * copy that used to be hardcoded in the public template so operators can edit
 * policies — and add new ones — without a deploy. Rich-text body + optional AI
 * drafting (shared AiAssistController).
 */
final class LegalController
{
    /** The seeded core docs live at /privacy, /terms, /cookies; custom ones at /legal/{slug}. */
    private const CORE = ['privacy', 'terms', 'cookies'];

    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/legal/index.twig', [
            'page_title' => 'Legal & policies — Admin',
            'admin_page' => 'legal',
            'docs'       => LegalService::all(),
            'core'       => self::CORE,
            'flash_ok'   => $this->flash('flash_ok'),
            'flash_error'=> $this->flash('flash_error'),
        ]);
    }

    public function edit(Request $req, Response $res, array $args): Response
    {
        $slug = strtolower((string) ($args['slug'] ?? ''));
        $isNew = $slug === 'new';
        $doc = $isNew ? null : LegalService::find($slug);
        if (!$isNew && !$doc) {
            $_SESSION['flash_error'] = 'That document does not exist.';
            return $res->withHeader('Location', '/admin/legal')->withStatus(302);
        }
        return $this->view->render($res, 'admin/legal/form.twig', [
            'page_title' => ($isNew ? 'New document' : 'Edit · ' . ($doc['title'] ?? $slug)) . ' — Admin',
            'admin_page' => 'legal',
            'is_new'     => $isNew,
            'doc'        => $doc ?? ['slug' => '', 'title' => '', 'body_html' => '', 'updated_label' => '', 'is_published' => 1, 'sort_order' => 0],
            'is_core'    => in_array($slug, self::CORE, true),
            'flash_error'=> $this->flash('flash_error'),
        ]);
    }

    public function save(Request $req, Response $res, array $args): Response
    {
        $b = (array) $req->getParsedBody();
        $slugArg = strtolower((string) ($args['slug'] ?? ''));
        // Core docs keep their fixed slug; new/custom docs take the submitted one.
        $slug = in_array($slugArg, self::CORE, true) ? $slugArg
              : ($slugArg === 'new' ? (string) ($b['slug'] ?? '') : $slugArg);
        try {
            LegalService::save($slug, $b, (int) ($_SESSION['admin_id'] ?? 0));
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $res->withHeader('Location', '/admin/legal/' . ($slugArg ?: 'new'))->withStatus(302);
        }
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'legal.save', 'legal_doc', 0);
        $_SESSION['flash_ok'] = 'Policy saved — it is live immediately.';
        return $res->withHeader('Location', '/admin/legal')->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $slug = strtolower((string) ($args['slug'] ?? ''));
        if (in_array($slug, self::CORE, true)) {
            $_SESSION['flash_error'] = 'Core policies can be edited or unpublished, but not deleted.';
        } else {
            LegalService::delete($slug);
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'legal.delete', 'legal_doc', 0);
            $_SESSION['flash_ok'] = 'Document removed.';
        }
        return $res->withHeader('Location', '/admin/legal')->withStatus(302);
    }

    private function flash(string $k): ?string { $v = $_SESSION[$k] ?? null; unset($_SESSION[$k]); return $v; }
}
