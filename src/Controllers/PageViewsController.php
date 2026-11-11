<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\PageViews;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /views — the beacon views.js sends once a page has been visible for
 * PageViews::ENGAGED_SECONDS. 204 when it moved the count, 202 when it did not (already
 * counted today, not a reader, not allowed to count, a ceiling reached): the page never
 * needs to know which, and a beacon must never be able to fail a page.
 */
final class PageViewsController
{
    public function engaged(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $ok = PageViews::engaged($req, (string) ($b['t'] ?? ''));
        return $res->withStatus($ok ? 204 : 202)->withHeader('Cache-Control', 'no-store');
    }
}
