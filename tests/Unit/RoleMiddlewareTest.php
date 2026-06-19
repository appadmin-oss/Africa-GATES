<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Admin\Middleware\RoleMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

class RoleMiddlewareTest extends TestCase
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

    private function req(array $headers = []): Req
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', 'https://afg.local/admin/admins');
        foreach ($headers as $k => $v) { $r = $r->withHeader($k, $v); }
        return $r;
    }

    public function test_allows_when_role_in_set(): void
    {
        $_SESSION['admin_role'] = 'superadmin';
        $res = (new RoleMiddleware('superadmin'))($this->req(), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_blocks_insufficient_role_html_redirects(): void
    {
        $_SESSION['admin_role'] = 'editor';
        $res = (new RoleMiddleware('superadmin'))($this->req(), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/dashboard', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function test_blocks_insufficient_role_json_403(): void
    {
        $_SESSION['admin_role'] = 'editor';
        $res = (new RoleMiddleware('superadmin'))($this->req(['Accept' => 'application/json']), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_multiple_roles_allowed(): void
    {
        $_SESSION['admin_role'] = 'editor';
        $res = (new RoleMiddleware('superadmin', 'editor'))($this->req(), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }
}
