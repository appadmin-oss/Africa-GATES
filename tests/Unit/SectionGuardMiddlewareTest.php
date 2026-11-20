<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Admin\Middleware\SectionGuardMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

/**
 * Per-section RBAC enforcement. The guard runs after auth and bounces a role
 * out of any section it isn't permitted to view — moderators can't reach
 * content, editors can't reach moderation, viewers can't reach configuration.
 */
class SectionGuardMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['admin_role'], $_SESSION['flash_error']);
    }

    private function handler(): Handler
    {
        return new class implements Handler {
            public function handle(Req $r): Res { return new Response(200); }
        };
    }

    private function guard(string $role, string $path, array $headers = []): Res
    {
        $_SESSION['admin_role'] = $role;
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://afg.local' . $path);
        foreach ($headers as $k => $v) { $req = $req->withHeader($k, $v); }
        return (new SectionGuardMiddleware())($req, $this->handler());
    }

    public function test_moderator_blocked_from_content(): void
    {
        $res = $this->guard('moderator', '/admin/products');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/dashboard', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function test_moderator_allowed_in_moderation(): void
    {
        $this->assertSame(200, $this->guard('moderator', '/admin/nominations')->getStatusCode());
    }

    public function test_editor_blocked_from_moderation(): void
    {
        $this->assertSame(302, $this->guard('editor', '/admin/nominations')->getStatusCode());
    }

    public function test_editor_allowed_in_content(): void
    {
        $this->assertSame(200, $this->guard('editor', '/admin/products')->getStatusCode());
    }

    public function test_viewer_blocked_from_configuration(): void
    {
        $this->assertSame(302, $this->guard('viewer', '/admin/settings')->getStatusCode());
    }

    public function test_admin_blocked_from_configuration(): void
    {
        $this->assertSame(302, $this->guard('admin', '/admin/webhooks')->getStatusCode());
    }

    public function test_superadmin_allowed_in_configuration(): void
    {
        $this->assertSame(200, $this->guard('superadmin', '/admin/settings')->getStatusCode());
    }

    public function test_json_denial_is_403(): void
    {
        $res = $this->guard('moderator', '/admin/products', ['Accept' => 'application/json']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_utility_paths_pass_through(): void
    {
        $this->assertSame(200, $this->guard('viewer', '/admin/login')->getStatusCode());
        $this->assertSame(200, $this->guard('moderator', '/admin/logout')->getStatusCode());
        $this->assertSame(200, $this->guard('editor', '/admin/dashboard')->getStatusCode()); // overview = all roles
    }

    public function test_unmapped_admin_path_fails_closed_to_superadmin(): void
    {
        // A new, not-yet-classified /admin area must NOT be reachable by lower
        // roles (fail closed); only superadmin gets through until it is mapped.
        $this->assertSame(302, $this->guard('editor', '/admin/totally-new-area')->getStatusCode());
        $this->assertSame(302, $this->guard('admin', '/admin/totally-new-area')->getStatusCode());
        $this->assertSame(200, $this->guard('superadmin', '/admin/totally-new-area')->getStatusCode());
    }
}
