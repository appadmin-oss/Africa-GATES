<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use AfricaGates\Support\Session;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Response as Psr7Response;

/**
 * Enforces a signed-in, STILL-ACTIVE judge on the /judge portal.
 *
 * ── THE SESSION IS NOT THE ACCOUNT ───────────────────────────────────────────
 *
 * This checked `$_SESSION['judge_id']` and nothing else, so taking a judge off the panel
 * (`is_active = 0`, the admin's "remove") ended nothing for somebody already signed in:
 * the ballot, the evidence reader and the dossier map all stayed open until the cookie
 * expired — which is the whole reason the button is pressed. The sign-in refuses an
 * inactive judge; the session it handed out did not know.
 *
 * Same answer as {@see \AfricaGates\Admin\Middleware\AdminAuthMiddleware}: the row is
 * re-read on EVERY request (one primary-key SELECT on a portal a panel uses), the session
 * is ended when it is gone or inactive, and a read that fails fails CLOSED.
 */
class JudgeAuthMiddleware
{
    public function __construct(private readonly array $exempt = [
        '/judge/login', '/judge/login/request', '/judge/login/verify', '/judge/logout',
    ]) {}

    private static function wantsJson(Request $req, string $path): bool
    {
        return str_contains($req->getHeaderLine('Accept'), 'application/json')
            || $req->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || str_starts_with($path, '/judge/api/')
            || str_starts_with($path, '/judge/score/');
    }

    public function __invoke(Request $req, Handler $handler): Response
    {
        $path = $req->getUri()->getPath();
        foreach ($this->exempt as $p) {
            if ($path === $p) return $handler->handle($req);
        }
        if (empty($_SESSION['judge_id'])) {
            // The ballot posts SCORES via fetch(); treat those as JSON so an
            // expired session returns a real 401 (the JS then redirects to login)
            // instead of a 302→login-HTML that surfaces as a confusing "Save failed".
            // Conflict declare/withdraw are plain HTML forms, so they are NOT
            // classified as JSON — they must keep the redirect-to-login behaviour.
            if (self::wantsJson($req, $path)) {
                $res = new Psr7Response(401);
                $res->getBody()->write(json_encode(['success' => false, 'message' => 'Login required.']));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $next = '?next=' . urlencode($path);
            $res = new Psr7Response(302);
            return $res->withHeader('Location', '/judge/login' . $next);
        }

        try {
            $live = DB::table('gates_judges')->where('id', (int) $_SESSION['judge_id'])
                ->first(['id', 'is_active']);
        } catch (\Throwable $e) {
            error_log('[judge-auth] could not verify the signed-in judge: ' . $e->getMessage());
            $live = null;
        }

        if (!$live || (int) ($live->is_active ?? 0) !== 1) {
            unset($_SESSION['judge_id'], $_SESSION['judge_name'], $_SESSION['judge_email']);
            Session::rotate();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $msg = 'Your judge account is no longer active. Contact the organisers if this is unexpected.';
            if (self::wantsJson($req, $path)) {
                $res = new Psr7Response(401);
                $res->getBody()->write(json_encode(['success' => false, 'message' => $msg]));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash_error'] = $msg;
            return (new Psr7Response(302))->withHeader('Location', '/judge/login');
        }

        return $handler->handle($req);
    }
}
