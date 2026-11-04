<?php
declare(strict_types=1);
namespace AfricaGates\Middleware;

use AfricaGates\Support\UploadLimits;
use Psr\Http\Message\{ServerRequestInterface as Request, ResponseInterface as Response};
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** PHP discards an oversized POST, including its CSRF token. Explain the real cause. */
final class UploadSizeMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $limit = UploadLimits::bytes((string) ini_get('post_max_size'));
        if ($request->getMethod() !== 'POST' || !str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'multipart/form-data')
            || $limit <= 0 || (int) ($request->getServerParams()['CONTENT_LENGTH'] ?? $request->getHeaderLine('Content-Length')) <= $limit) {
            return $handler->handle($request);
        }
        $response = new \Slim\Psr7\Response(413);
        $response->getBody()->write('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Upload too large — Africa GATES</title><main><h1>Your upload is too large</h1><p>The server accepts up to '
            . htmlspecialchars(UploadLimits::label($limit), ENT_QUOTES, 'UTF-8')
            . ' for the whole form. Go back, select fewer or smaller files and try again. Nothing from this submission was saved.</p><p><a href="/">Africa GATES home</a></p></main></html>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
