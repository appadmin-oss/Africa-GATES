<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Restricts a route (group) to admins whose role is in the allowed set.
 * Runs INSIDE AdminAuthMiddleware, so the session is already authenticated.
 * Fails closed: unknown/insufficient role is denied. HTML → redirect with a
 * flash; JSON/admin-API → 403.
 *
 * Usage: ->add(new RoleMiddleware('superadmin'))
 */
class RoleMiddleware
{
    /** @var string[] */
    private array $allowed;

    public function __construct(string ...$allowed)
    {
        $this->allowed = $allowed;
    }

    public function __invoke(Request $req, Handler $handler): Response
    {
        $role = $_SESSION['admin_role'] ?? '';
        if (in_array($role, $this->allowed, true)) {
            return $handler->handle($req);
        }

        $path = $req->getUri()->getPath();
        $isJson = str_contains($req->getHeaderLine('Accept'), 'application/json')
               || str_starts_with($path, '/admin/api/');

        if ($isJson) {
            $res = new Psr7Response(403);
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'Insufficient privileges.']));
            return $res->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash_error'] = 'You do not have permission to access that area.';
        return (new Psr7Response(302))->withHeader('Location', '/admin/dashboard');
    }
}
