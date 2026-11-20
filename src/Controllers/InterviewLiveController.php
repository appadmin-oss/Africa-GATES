<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\InterviewLive;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The three endpoints the browser extension talks to.
 *
 * ── WHY THIS IS NOT AN ADMIN ROUTE ───────────────────────────────────────────
 *
 * The caller is a browser extension's service worker running while an interviewer sits in a
 * Meet call. Its requests carry no admin cookie — the session cookie is SameSite=Lax, which
 * is what protects every other admin POST here, and loosening it to make this work would
 * weaken the whole console for one feature.
 *
 * So these live in the public API group, authenticated by a per-sitting live token, and they
 * are exempt from CSRF for the same reason the Make.com agent bridge is: there is no session
 * to protect, and an attacker holding the token does not need a forged request.
 *
 * ── EVERY ONE OF THEM IS DELIBERATELY DULL ───────────────────────────────────
 *
 * `hello` returns a fixed shape and nothing else — no contact details, no other nominee, no
 * scores. `say` appends caption lines. `finish` closes the sitting. Three verbs, one
 * sitting, and no parameter anywhere that names a different interview. A leaked live key is
 * worth one interview's question list, which is the smallest blast radius this feature can
 * have while still working.
 *
 * ── AND THEY ANSWER JSON, ALWAYS ─────────────────────────────────────────────
 *
 * Including on failure. A content script that receives an HTML error page has no way to
 * tell an operator what went wrong, and "the extension stopped working" with nothing on
 * screen is how a capture failure becomes an empty transcript nobody noticed.
 */
final class InterviewLiveController
{
    /** Connect: who is being interviewed, may we capture, and what are the questions. */
    public function hello(Request $req, Response $res): Response
    {
        $b = $this->body($req);
        return $this->json($res, InterviewLive::hello(
            (string) ($b['token'] ?? ''),
            (string) ($b['meet_code'] ?? '')
        ));
    }

    /**
     * Caption lines from the call.
     *
     * Batched by the extension every few seconds rather than sent per line: a request per
     * caption would be several hundred an interview, and the platform's own rate limiting
     * would eventually — correctly — start refusing them.
     */
    public function say(Request $req, Response $res): Response
    {
        $b     = $this->body($req);
        $lines = $b['lines'] ?? [];
        if (!is_array($lines)) $lines = [];

        // A cap here as well as in the service. The service caps what it KEEPS; this caps
        // what is parsed at all, so a runaway loop in somebody's browser cannot hand this
        // process a megabyte of JSON to walk.
        if (count($lines) > 200) $lines = array_slice($lines, -200);

        return $this->json($res, InterviewLive::append(
            (string) ($b['token'] ?? ''),
            $lines,
            (string) ($b['question_key'] ?? '')
        ));
    }

    /** The call is over: assemble the captions into a transcript and publish it. */
    public function finish(Request $req, Response $res): Response
    {
        $b = $this->body($req);
        return $this->json($res, InterviewLive::finish((string) ($b['token'] ?? '')));
    }

    /**
     * The JSON body, whichever way it arrived.
     *
     * The extension sends `application/json`, which Slim only parses into the parsed body
     * when a media-type parser is registered — so the raw stream is read as a fallback
     * rather than assuming. Getting this wrong looks like every field being empty, which
     * reads as "bad token" and sends somebody hunting the wrong problem.
     *
     * @return array<string,mixed>
     */
    private function body(Request $req): array
    {
        $parsed = $req->getParsedBody();
        if (is_array($parsed) && $parsed !== []) return $parsed;

        $raw = (string) $req->getBody();
        if ($raw === '') return [];
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /** @param array<string,mixed> $data */
    private function json(Response $res, array $data): Response
    {
        $res->getBody()->write((string) json_encode($data));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withStatus(($data['ok'] ?? false) ? 200 : 422);
    }
}
