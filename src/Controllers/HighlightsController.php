<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Highlights;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * GET /highlights — members' businesses, products, services and events, free, each one checked
 * by a person before it appears and gone by itself after its fixed life (Services\Highlights).
 */
final class HighlightsController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $kind = (string) ($req->getQueryParams()['kind'] ?? '');
        if (!in_array($kind, Highlights::KINDS, true)) $kind = '';
        return $this->view->render($res, 'pages/highlights.twig', [
            'page_title'       => 'Highlights — Africa GATES',
            'meta_description' => 'Businesses, products, services and events from Africa GATES members.',
            'items'            => Highlights::live(48, $kind),
            'kind'             => $kind,
            'days'             => Highlights::LIVE_DAYS,
        ]);
    }
}
