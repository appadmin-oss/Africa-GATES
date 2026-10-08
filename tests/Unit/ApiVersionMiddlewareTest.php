<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Middleware\ApiVersionMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

class ApiVersionMiddlewareTest extends TestCase
{
    private function handler(): Handler
    {
        return new class implements Handler {
            public function handle(Req $r): Res { return new Response(200); }
        };
    }

    private function req(): Req
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'https://afg.local/api/v1/awards');
    }

    public function test_stamps_version_header(): void
    {
        $res = (new ApiVersionMiddleware('1'))($this->req(), $this->handler());
        $this->assertSame('1', $res->getHeaderLine('X-API-Version'));
        $this->assertSame('', $res->getHeaderLine('X-API-Note')); // canonical path: no legacy note
    }

    public function test_legacy_alias_adds_guidance_note(): void
    {
        $res = (new ApiVersionMiddleware('1', true))($this->req(), $this->handler());
        $this->assertSame('1', $res->getHeaderLine('X-API-Version'));
        $this->assertStringContainsString('/api/v1', $res->getHeaderLine('X-API-Note'));
    }
}
