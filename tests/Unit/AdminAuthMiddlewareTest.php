<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Admin\Middleware\AdminAuthMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

/**
 * Access-control contract for the admin gate: authenticated admins reach
 * protected routes, unauthenticated users are bounced to login, and the
 * read-only 'viewer' role is denied any state-changing (non-GET) request —
 * least privilege, with zero impact on other roles.
 */
class AdminAuthMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_error']);
    }

    private function handler(): Handler
    {
        return new class implements Handler {
            public function handle(Req $r): Res { return new Response(200); }
        };
    }

    private function req(string $method = 'GET', string $path = '/admin/profiles', array $headers = []): Req
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, 'https://afg.local' . $path);
        foreach ($headers as $k => $v) { $r = $r->withHeader($k, $v); }
        return $r;
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $res = (new AdminAuthMiddleware())($this->req('GET', '/admin/profiles'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith('/admin/login', $res->getHeaderLine('Location'));
    }

    public function test_authenticated_admin_write_allowed(): void
    {
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'admin';
        $res = (new AdminAuthMiddleware())($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_viewer_read_allowed(): void
    {
        $_SESSION['admin_id'] = 2; $_SESSION['admin_role'] = 'viewer';
        $res = (new AdminAuthMiddleware())($this->req('GET', '/admin/profiles'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_viewer_write_denied_html_redirect(): void
    {
        $_SESSION['admin_id'] = 2; $_SESSION['admin_role'] = 'viewer';
        $res = (new AdminAuthMiddleware())($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/dashboard', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function test_viewer_write_denied_json_403(): void
    {
        $_SESSION['admin_id'] = 2; $_SESSION['admin_role'] = 'viewer';
        $res = (new AdminAuthMiddleware())($this->req('POST', '/admin/profiles/1', ['Accept' => 'application/json']), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_editor_write_allowed(): void
    {
        // 'editor' is a content-management role — writes are permitted.
        $_SESSION['admin_id'] = 3; $_SESSION['admin_role'] = 'editor';
        $res = (new AdminAuthMiddleware())($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_judge_denied_entirely_html(): void
    {
        // Judges have NO admin access — every route + method bounces to /judge/login.
        $_SESSION['admin_id'] = 4; $_SESSION['admin_role'] = 'judge';
        $res = (new AdminAuthMiddleware())($this->req('GET', '/admin/nominees'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/judge/login', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function test_unknown_role_write_denied_fail_closed(): void
    {
        // Fail closed: an unrecognised/typo'd role gets read-only, never write.
        $_SESSION['admin_id'] = 5; $_SESSION['admin_role'] = 'contributor';
        $res = (new AdminAuthMiddleware())($this->req('POST', '/admin/profiles/1', ['Accept' => 'application/json']), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_judge_denied_entirely_json_403(): void
    {
        // JSON / admin-API requests from a judge account get a hard 403.
        $_SESSION['admin_id'] = 4; $_SESSION['admin_role'] = 'judge';
        $res = (new AdminAuthMiddleware())($this->req('POST', '/admin/nominations/1/approve', ['Accept' => 'application/json']), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_viewer_logout_allowed_via_exempt(): void
    {
        // Logout is exempt — a read-only viewer must still be able to end their session.
        $_SESSION['admin_id'] = 2; $_SESSION['admin_role'] = 'viewer';
        $res = (new AdminAuthMiddleware())($this->req('POST', '/admin/logout'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }
}
