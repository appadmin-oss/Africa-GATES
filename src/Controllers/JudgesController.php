<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Judge\Services\JudgeService;

/**
 * Public "Meet the Judges" showcase. Read-only listing of the active judging
 * panel (no scores, no contact details) — the human face of the integrity
 * methodology described on /integrity.
 */
class JudgesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly JudgeService $judges,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $roster = $this->judges->publicRoster();
        // Filter chips = the distinct programmes the panel actually judges.
        $filters = [];
        foreach ($roster as $j) {
            foreach ($j['programmes'] as $p) { $filters[$p['slug']] = $p['title']; }
        }
        asort($filters);
        return $this->view->render($res, 'pages/judges.twig', [
            'page_title'       => 'Meet the Judges — Africa GATES',
            'meta_description' => 'Meet the independent panel evaluating Africa GATES nominees — distinguished experts scoring documented impact, not popularity, behind the Cultural Power Index.',
            'gates_page'       => 'judges',
            'has_hero'         => false,
            'judges'           => $roster,
            'filters'          => $filters,
        ]);
    }

    /** /judges/{slug} — one judge's public profile page. Slug is "{id}-{name}" (id-resolved). */
    public function show(Request $req, Response $res, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $id = ($slug !== '' && ctype_digit($slug[0])) ? (int) $slug : 0;
        if ($id < 1) return $res->withHeader('Location', '/judges')->withStatus(302);
        $judge = $this->judges->publicJudge($id);
        if (!$judge) return $res->withHeader('Location', '/judges')->withStatus(302);
        if ($slug !== $judge['slug']) {                       // canonical URL
            return $res->withHeader('Location', '/judges/' . $judge['slug'])->withStatus(302);
        }
        // Social card: the judge's own photo (absolute URL) when they have one;
        // the branded default from the layout otherwise.
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $avatar = trim((string)($judge['avatar_path'] ?? ''));
        $ogImage = $avatar !== ''
            ? (str_starts_with($avatar, 'http') ? $avatar : $base . $avatar)
            : null;
        return $this->view->render($res, 'pages/judge.twig', array_filter([
            'page_title'       => $judge['name'] . ' — Judge — Africa GATES',
            'meta_description' => $judge['name'] . ($judge['title'] ? ', ' . $judge['title'] : '') . ' — an independent judge on the Africa GATES evaluation panel.',
            'gates_page'       => 'judges',
            'has_hero'         => false,
            'judge'            => $judge,
            'og_image'         => $ogImage,
            'og_image_alt'     => $ogImage ? ($judge['name'] . ' — Africa GATES judge') : null,
        ], fn($v) => $v !== null));
    }
}
