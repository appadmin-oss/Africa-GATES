<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Middleware\CsrfMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_URL'] = 'https://afg.local';
        $_SESSION['csrf_token'] = 'sessiontoken';
    }

    private function handler(): Handler
    {
        return new class implements Handler {
            public function handle(Req $r): Res { return new Response(200); }
        };
    }

    private function req(string $method, string $path, array $headers = [], array $body = []): Req
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, 'https://afg.local' . $path);
        foreach ($headers as $k => $v) { $r = $r->withHeader($k, $v); }
        if ($body) { $r = $r->withParsedBody($body); }
        return $r;
    }

    public function test_get_passes_through(): void
    {
        $res = (new CsrfMiddleware())($this->req('GET', '/anything'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_otp_gated_api_route_is_exempt(): void
    {
        $res = (new CsrfMiddleware())($this->req('POST', '/api/vote'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_other_api_route_blocks_cross_origin(): void
    {
        $res = (new CsrfMiddleware())(
            $this->req('POST', '/api/register', ['Origin' => 'https://evil.example']),
            $this->handler()
        );
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_other_api_route_allows_same_origin(): void
    {
        $res = (new CsrfMiddleware())(
            $this->req('POST', '/api/register', ['Origin' => 'https://afg.local']),
            $this->handler()
        );
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_non_api_post_requires_csrf_token(): void
    {
        $bad = (new CsrfMiddleware())($this->req('POST', '/nominate', [], ['_token' => 'wrong']), $this->handler());
        $this->assertSame(403, $bad->getStatusCode());

        $ok = (new CsrfMiddleware())($this->req('POST', '/nominate', [], ['_token' => 'sessiontoken']), $this->handler());
        $this->assertSame(200, $ok->getStatusCode());
    }

    public function test_payment_webhook_is_csrf_exempt(): void
    {
        // Server-to-server, signature-verified inside the controller — no token.
        $res = (new CsrfMiddleware())($this->req('POST', '/pay/webhook'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_payment_init_still_requires_csrf_token(): void
    {
        // First-party form post must carry the synchronizer token.
        $bad = (new CsrfMiddleware())($this->req('POST', '/pay/init', [], ['_token' => 'wrong']), $this->handler());
        $this->assertSame(403, $bad->getStatusCode());

        $ok = (new CsrfMiddleware())($this->req('POST', '/pay/init', [], ['_token' => 'sessiontoken']), $this->handler());
        $this->assertSame(200, $ok->getStatusCode());
    }
}
