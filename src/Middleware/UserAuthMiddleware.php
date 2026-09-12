<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Gates the member /account dashboard. The sign-in / register / OTP / logout routes
 * are exempt; everything else requires a logged-in member session.
 */
class UserAuthMiddleware
{
    /** @param string[] $exempt absolute paths that don't require a member session */
    public function __construct(private readonly array $exempt = [
        '/account/login', '/account/login/otp', '/account/login/verify',
        // Signing in WITH a passkey happens before there is a session, so both halves of
        // the ceremony are exempt. Enrolling one (/account/passkeys) is deliberately not:
        // a device is added by somebody already signed in, which is what makes the
        // enrolment mean anything.
        '/account/login/passkey', '/account/login/passkey/options',
        '/account/register', '/account/verify', '/account/verify/resend',
        // Recovery is by definition reached without a session.
        '/account/forgot', '/account/reset',
        '/account/logout', '/account/redeem',
    ]) {}

    public function __invoke(Request $req, Handler $handler): Response
    {
        $path = $req->getUri()->getPath();
        foreach ($this->exempt as $p) {
            if ($path === $p) return $handler->handle($req);
        }
        if (empty($_SESSION['user_id'])) {
            return (new Psr7Response(302))->withHeader('Location', '/account/login?next=' . urlencode($path));
        }
        return $handler->handle($req);
    }
}
