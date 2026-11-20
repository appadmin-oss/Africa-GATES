<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Services\Recognitions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/recognitions/withdrawn` — THE PUBLIC WITHDRAWAL LOG (Phase 6, REFERENCE §11: "every
 * withdrawal is logged publicly"). Every recognition ever withdrawn, when, and the reason
 * given, newest first. A record that silently disappears is history being edited; this page
 * is what makes a withdrawal an act that announces itself.
 */
class RecognitionsController
{
    public function __construct(private readonly Twig $view) {}

    public function withdrawn(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'pages/recognitions-withdrawn.twig', [
            'page_title'       => 'Withdrawn recognitions — Africa GATES',
            'meta_description' => 'Every recognition withdrawn on Africa GATES, when, and why. A recognition is never edited or deleted; a withdrawal is published here.',
            'gates_page'       => 'recognitions',
            'rows'             => Recognitions::withdrawals(200),
            'standing'         => Recognitions::count(),
        ]);
    }
}
