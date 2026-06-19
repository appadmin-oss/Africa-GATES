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
        return $handler->handle($req->withAttribute('admin_id', $_SESSION['admin_id']));
    }
}
