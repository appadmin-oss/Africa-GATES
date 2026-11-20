<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Mail\MailEvents;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /hooks/mail-events/{token} — the mail provider reporting bounces and complaints.
 *
 * A wrong token answers 404, the same as a path that does not exist, so the endpoint
 * confirms nothing to somebody guessing. A right token always answers 200 once the body
 * has been read, even when nothing in it was an event we act on: providers retry a
 * non-2xx for days, and a delivery report we chose to ignore is not a failure.
 */
final class MailEventsController
{
    /** A provider's batch is a few hundred events; anything past this is not one. */
    private const MAX_BYTES = 1_048_576;

    public function receive(Request $req, Response $res, array $args): Response
    {
        if (!MailEvents::accepts((string) ($args['token'] ?? ''))) {
            return $res->withStatus(404);
        }

        $raw = (string) $req->getBody();
        if (strlen($raw) > self::MAX_BYTES) {
            return $this->json($res->withStatus(413), ['ok' => false, 'error' => 'too large']);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $req->getParsedBody();
        }

        $applied = MailEvents::apply(MailEvents::parse($payload));
        return $this->json($res, ['ok' => true, 'applied' => $applied]);
    }

    /** @param array<string,mixed> $body */
    private function json(Response $res, array $body): Response
    {
        $res->getBody()->write((string) json_encode($body));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
