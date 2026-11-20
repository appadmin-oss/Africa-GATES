<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuthService;
use AfricaGates\Services\UserAccountService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * THE CONSOLE HAS NO SIGN-IN OF ITS OWN (owner, 29 Oct 2026: one login, roles given).
 *
 * The staff console is a role given to an email address, and the Africa GATES account holding
 * that address opens it (Account\Roles). `/admin/login`, its form post and the magic-link paths
 * are kept only as forwarding addresses — they are in bookmarks, in old emails and in every
 * redirect written before this — and each lands on the one sign-in, carrying where the person
 * was going. Signing out of the console is signing out: with one login, closing only the
 * console would reopen it on the very next page.
 */
class AuthController
{
    public function __construct(private readonly AuthService $auth, private readonly UserAccountService $accounts) {}

    /** /admin/login, /admin/login/submit, /admin/magic… — all to the one sign-in. */
    public function toAccount(Request $req, Response $res): Response
    {
        $next = (string) ($req->getQueryParams()['next'] ?? ($req->getParsedBody()['next'] ?? '/admin'));
        if (!str_starts_with($next, '/admin')) $next = '/admin';
        return $res->withHeader('Location', '/account/login?next=' . rawurlencode($next))->withStatus(302);
    }

    public function logout(Request $req, Response $res): Response
    {
        $this->auth->logout();
        $this->accounts->logout();
        return $res->withHeader('Location', '/')->withStatus(302);
    }
}
