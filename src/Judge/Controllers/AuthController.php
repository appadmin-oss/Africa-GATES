<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Controllers;

use AfricaGates\Services\UserAccountService;
use AfricaGates\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * THE PANEL HAS NO SIGN-IN OF ITS OWN (owner, 29 Oct 2026: one login, roles given).
 *
 * Judging is a role given to the judge's email address when the judge is added; the Africa GATES
 * account holding that address opens the panel (Account\Roles, JudgeAuthMiddleware). The old
 * code sign-in's paths stay as forwarding addresses — the judges' welcome mail and every bounce
 * written before this point at them — and land on the one sign-in, which also sends an emailed
 * code, so a judge signs in exactly as they always did. Signing out is signing out.
 */
class AuthController
{
    public function __construct(private readonly UserAccountService $accounts) {}

    /** /judge/login and its two posts — all to the one sign-in. */
    public function toAccount(Request $req, Response $res): Response
    {
        $next = (string) ($req->getQueryParams()['next'] ?? '/judge/ballot');
        if (!str_starts_with($next, '/judge')) $next = '/judge/ballot';
        return $res->withHeader('Location', '/account/login?next=' . rawurlencode($next))->withStatus(302);
    }

    public function logout(Request $req, Response $res): Response
    {
        $this->accounts->logout();
        unset($_SESSION['judge_id'], $_SESSION['judge_name'], $_SESSION['judge_email']);
        // Rotate the id so the pre-logout one cannot be reused, and cycle the CSRF token.
        Session::rotate();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $res->withHeader('Location', '/')->withStatus(302);
    }
}
