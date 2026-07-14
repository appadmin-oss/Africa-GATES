<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Enforces an authenticated admin session for protected /admin routes.
 * If unauthenticated and the path is HTML, redirects to /admin/login.
 * If unauthenticated and the path is JSON (admin API), returns 401 JSON.
 */
class AdminAuthMiddleware
{
    /**
     * Roles permitted to perform state-changing (non-GET) admin requests.
     * Moderators write within their section (approve/reject); the per-section
     * SectionGuardMiddleware constrains WHERE each writer role may act.
     */
    private const WRITER_ROLES = ['superadmin', 'admin', 'editor', 'moderator'];

    /** @param string[] $exempt absolute paths that don't require auth */
    public function __construct(private readonly array $exempt = [
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
        $role = $_SESSION['admin_role'] ?? '';

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
