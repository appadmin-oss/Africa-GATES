<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

class JudgeAuthMiddleware
{
    public function __construct(private readonly array $exempt = [
        '/judge/login', '/judge/login/request', '/judge/login/verify', '/judge/logout',
    ]) {}

    public function __invoke(Request $req, Handler $handler): Response
    {
        $path = $req->getUri()->getPath();
        foreach ($this->exempt as $p) {
            if ($path === $p) return $handler->handle($req);
        }
        if (empty($_SESSION['judge_id'])) {
            $isJson = str_contains($req->getHeaderLine('Accept'), 'application/json') || str_starts_with($path, '/judge/api/');
            if ($isJson) {
                $res = new Psr7Response(401);
                $res->getBody()->write(json_encode(['success' => false, 'message' => 'Login required.']));
                return $res->withHeader('Content-Type', 'application/json');
            }
            $next = '?next=' . urlencode($path);
            $res = new Psr7Response(302);
            return $res->withHeader('Location', '/judge/login' . $next);
        }
        return $handler->handle($req);
    }
}
