<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Mail\MailTraction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /m/c/{token} — a link pressed in one of our announcements.
 *
 * Counts the press and forwards to the link at once (Mail\MailTraction). A token that is not
 * one we signed goes to the home page rather than an error: somebody pressed a link in an
 * email, and the least useful answer is a broken page. 302, never 301 — a browser that cached
 * the forward would skip the count next time, and each token is one person's link.
 */
final class MailClickController
{
    public function go(Request $req, Response $res, array $args): Response
    {
        $to = MailTraction::click((string) ($args['token'] ?? '')) ?? '/';
        return $res->withHeader('Location', $to)->withHeader('Cache-Control', 'no-store')
                   ->withHeader('Referrer-Policy', 'no-referrer')->withStatus(302);
    }
}
