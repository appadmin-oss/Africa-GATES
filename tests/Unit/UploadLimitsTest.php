<?php
declare(strict_types=1);
namespace Tests\Unit;
use AfricaGates\Support\UploadLimits;
use AfricaGates\Middleware\UploadSizeMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class UploadLimitsTest extends TestCase
{
    public function test_host_units_and_human_errors(): void
    {
        $this->assertSame(2097152, UploadLimits::bytes('2M'));
        $this->assertSame(1024, UploadLimits::bytes('1k'));
        $this->assertSame(0, UploadLimits::bytes('-1'));
        $this->assertSame('2 MB', UploadLimits::label(2097152));
        $this->assertLessThanOrEqual(10485760, UploadLimits::imageBytes());
        $this->assertStringContainsString('interrupted', UploadLimits::error(UPLOAD_ERR_PARTIAL));
    }
    public function test_oversized_multipart_is_explained_before_csrf_or_writes(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public int $calls = 0;
            public function handle(ServerRequestInterface $request): ResponseInterface { ++$this->calls; return new Response(204); }
        };
        $middleware = new UploadSizeMiddleware();
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/account/photo', ['CONTENT_LENGTH' => PHP_INT_MAX])
            ->withHeader('Content-Type', 'multipart/form-data; boundary=test');
        if (UploadLimits::bytes((string) ini_get('post_max_size')) > 0) {
            $response = $middleware($req, $handler);
            $this->assertSame(413, $response->getStatusCode());
            $this->assertSame(0, $handler->calls);
            $this->assertStringContainsString('Nothing from this submission was saved', (string) $response->getBody());
        }
        $this->assertSame(204, $middleware($req->withMethod('GET'), $handler)->getStatusCode());
        $this->assertSame(204, $middleware($req->withHeader('Content-Type', 'application/json'), $handler)->getStatusCode());
    }
}
