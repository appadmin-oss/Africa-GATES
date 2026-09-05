<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Middleware;

use AfricaGates\Admin\Services\AuthService;
use AfricaGates\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Enforces an authenticated admin session for protected /admin routes.
 * If unauthenticated and the path is HTML, redirects to /admin/login.
 * If unauthenticated and the path is JSON (admin API), returns 401 JSON.
 *
 * ── THE SESSION IS NOT THE ACCOUNT ───────────────────────────────────────────
 *
 * `admin_role` and the account's `is_active` were written into $_SESSION at login
 * and then never read from the database again. Everything downstream — the judge
 * refusal below, the writer allowlist below that, SectionGuardMiddleware, the
 * sidebar — read the copy.
 *
 * So the console had two buttons that did nothing to anybody already signed in.
 * Deactivating an admin ended no session: they kept full access until their cookie
 * expired, which is the whole reason the button is pressed. Demoting a superadmin
 * to viewer was the same. Neither failure is visible from anywhere — the operator
 * sees the row change, and the person they revoked keeps working.
 *
 * {@see AuthService::currentAdmin()} is the reader that closes it, and it is asked
 * on EVERY request rather than on a timer: the entire value of a revocation is that
 * it lands on the next click, and this is one primary-key SELECT on a console a
 * handful of people use. BallotGuard declines to memoise for the same reason.
 */
class AdminAuthMiddleware
{
    /**
     * Roles permitted to perform state-changing (non-GET) admin requests.
     * Moderators write within their section (approve/reject); the per-section
     * SectionGuardMiddleware constrains WHERE each writer role may act.
     */
    private const WRITER_ROLES = ['superadmin', 'admin', 'editor', 'moderator'];

    /**
     * @param AuthService $auth the one reader of the live admin row — see the class note
     * @param string[]    $exempt absolute paths that don't require auth
     */
    public function __construct(private readonly AuthService $auth, private readonly array $exempt = [
        '/admin/login',
        '/admin/login/submit',
        '/admin/magic',
        '/admin/magic/request',
        '/admin/magic/consume',
        '/admin/logout',
    ]) {}

    public function __invoke(Request $req, Handler $handler): Response
    {
        $path = $req->getUri()->getPath();
        foreach ($this->exempt as $p) {
            if ($path === $p) return $handler->handle($req);
        }
        if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
            $isJson = str_contains($req->getHeaderLine('Accept'), 'application/json')
                   || str_starts_with($path, '/admin/api/');
            if ($isJson) {
                $res = new Psr7Response(401);
                $res->getBody()->write(json_encode(['success' => false, 'message' => 'Login required.']));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $next = '?next=' . urlencode($path);
            $res = new Psr7Response(302);
            return $res->withHeader('Location', '/admin/login' . $next);
        }
        // ── RE-READ THE ACCOUNT, NOT THE COPY OF IT ─────────────────────────
        //
        // Fails CLOSED when the row cannot be read at all. The alternative is that
        // a database hiccup readmits everybody who has been revoked, and a console
        // whose database is unreachable can do nothing useful anyway.
        try {
            $live = $this->auth->currentAdmin();
        } catch (\Throwable $e) {
            error_log('[admin-auth] could not verify the signed-in account: ' . $e->getMessage());
            $live = null;
        }

        if ($live === null || (int) ($live->is_active ?? 0) !== 1) {
            unset($_SESSION['admin_id'], $_SESSION['admin_name'],
                  $_SESSION['admin_role'], $_SESSION['admin_email']);
            Session::rotate();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $isJson = str_contains($req->getHeaderLine('Accept'), 'application/json')
                   || str_starts_with($path, '/admin/api/');
            if ($isJson) {
                $res = new Psr7Response(401);
                $res->getBody()->write(json_encode(['success' => false,
                    'message' => 'This account is no longer active. Please sign in again.']));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash_error'] = 'This account is no longer active. Please sign in again.';
            return (new Psr7Response(302))->withHeader('Location', '/admin/login');
        }

        // The live role, written back so the guards below — and every screen that
        // reads $_SESSION['admin_role'] — see the demotion rather than the login.
        $role = (string) ($live->role ?? '');
        $_SESSION['admin_role'] = $role;
        $_SESSION['admin_name'] = (string) ($live->name ?? ($_SESSION['admin_name'] ?? ''));

        // Judges have no place in the admin console — they evaluate in the /judge
        // portal. Deny the entire area outright (every route + method), and send
        // them to the judges sign-in rather than leaving them on a dead end.
        if ($role === 'judge') {
            $isJson = str_contains($req->getHeaderLine('Accept'), 'application/json')
                   || str_starts_with($path, '/admin/api/');
            if ($isJson) {
                $res = new Psr7Response(403);
                $res->getBody()->write(json_encode(['success' => false, 'message' => 'This account does not have admin access.']));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash_error'] = 'That is a judges account — please use the judges portal.';
            return (new Psr7Response(302))->withHeader('Location', '/judge/login');
        }

        // Least privilege (fail CLOSED): only content-management roles may perform
        // state-changing requests. Every other role — 'viewer' or any unrecognised
        // role — is read-only here. Superadmin-only areas keep their own
        // RoleMiddleware('superadmin') gate layered on top of this.
        $isWrite = !in_array(strtoupper($req->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($isWrite && !in_array($role, self::WRITER_ROLES, true)) {
            $isJson = str_contains($req->getHeaderLine('Accept'), 'application/json')
                   || str_starts_with($path, '/admin/api/');
            if ($isJson) {
                $res = new Psr7Response(403);
                $res->getBody()->write(json_encode(['success' => false, 'message' => 'Read-only role: changes are not permitted.']));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash_error'] = 'Your role is read-only — you cannot make changes.';
            return (new Psr7Response(302))->withHeader('Location', '/admin/dashboard');
        }

        return $handler->handle($req->withAttribute('admin_id', $_SESSION['admin_id']));
    }
}
