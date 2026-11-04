<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\DisplayReadingPrefs;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `POST /account/display` — a signed-in member's Display & reading settings, saved.
 *
 * The only writer the browser has for {@see DisplayReadingPrefs}. `a11y.js` calls it on
 * every change while signed in, with the CSRF token in `X-CSRF-Token` (CsrfMiddleware), and
 * the route sits inside the `/account` group so `UserAuthMiddleware` has already refused a
 * request with no member. The member is the SESSION's — there is no id in the path or the
 * body to change, so one member can never write another's settings.
 *
 * It answers JSON and never redirects: the caller is a script that has already applied the
 * change on screen, and a failed save must not undo what the person can see. The device
 * store still holds it; the next change tries again.
 */
final class DisplayReadingController
{
    public function save(Request $req, Response $res): Response
    {
        $user = (int) ($_SESSION['user_id'] ?? 0);
        $body = $req->getParsedBody();
        $in   = is_array($body) ? $body : [];
        // A form post nests nothing; a JSON post may send the store whole under `prefs`.
        if (isset($in['prefs']) && is_array($in['prefs'])) $in = $in['prefs'];

        $saved = DisplayReadingPrefs::save($user, $in);

        $res->getBody()->write((string) json_encode(
            $saved === null ? ['ok' => false] : ['ok' => true, 'prefs' => $saved]
        ));

        return $res
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($saved === null ? 503 : 200);
    }
}
