<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Middleware;

use AfricaGates\Admin\Support\Permissions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * Per-section RBAC. Runs *inside* AdminAuthMiddleware (which has already
 * authenticated the admin and blocked judges), and denies access to console
 * sections the role isn't permitted to view — see {@see Permissions::MATRIX}.
 *
 * Unmapped / auth-exempt / utility paths carry no section and pass straight
 * through, so this never gets in the way of login, logout or the dashboard.
 */
class SectionGuardMiddleware
{
    public function __invoke(Request $req, Handler $handler): Response
    {
        $path = $req->getUri()->getPath();
        // Auth-exempt / utility routes (login, logout, magic, admin-api) carry no
        // section and pass straight through.
        if (Permissions::isUtilityPath($path)) return $handler->handle($req);

        $section = Permissions::sectionForPath($path);
        $role    = (string) ($_SESSION['admin_role'] ?? '');
        // Mapped section → matrix check. An UNMAPPED /admin path fails CLOSED:
        // only superadmin may reach an area we haven't classified, so a newly
        // added (and not-yet-mapped) route can never ship silently ungated.
        $allowed = $section !== null
            ? Permissions::canAccess($role, $section)
            : ($role === 'superadmin');
        if ($allowed) return $handler->handle($req);

        $isJson = str_contains($req->getHeaderLine('Accept'), 'application/json')
               || str_starts_with($path, '/admin/api/');
        if ($isJson) {
            $res = new Psr7Response(403);
            $res->getBody()->write(json_encode(['success' => false, 'message' => 'Your role cannot access this section.']));
            return $res->withHeader('Content-Type', 'application/json');
        }
        $_SESSION['flash_error'] = 'Your role does not have access to that section.';
        return (new Psr7Response(302))->withHeader('Location', '/admin/dashboard');
    }
}
