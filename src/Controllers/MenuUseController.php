<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\MenuShortcuts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `POST /account/menu-use` — one open of a Menu destination, counted for the Menu's
 * most-used tiles (MenuShortcuts; docs/handoff/MENU-SHEET.md).
 *
 * Sent by `menu-sheet.js` with `navigator.sendBeacon` as the person follows a Menu link, so
 * it must never hold a navigation: the beacon is queued by the browser and survives the page
 * unloading, and this answers 204 with no body. A beacon cannot set a header, so the CSRF
 * token travels as `_token` in the form body, which CsrfMiddleware already reads.
 *
 * Inside the `/account` group, so `UserAuthMiddleware` has refused a request with no member;
 * the member is the SESSION's — no id in the path or body — so nobody can rank another
 * member's tiles. A key the member may not open is refused rather than stored: a shortcut
 * the catalogue would never draw for them must not be earnable either.
 */
final class MenuUseController
{
    public function record(Request $req, Response $res): Response
    {
        $user = (int) ($_SESSION['user_id'] ?? 0);
        $body = $req->getParsedBody();
        $key  = is_array($body) ? (string) ($body['d'] ?? '') : '';

        $ok = MenuShortcuts::recordFor($user, $key);

        return $res->withHeader('Cache-Control', 'no-store')->withStatus($ok ? 204 : 422);
    }
}
