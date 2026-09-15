<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Stamps every API response with the version that served it. The same handlers
 * are mounted at /api/v1 (canonical, versioned) and /api (legacy alias == v1),
 * so integrations and AI agents can pin a version for stability while existing
 * first-party callers keep working unchanged. The legacy alias additionally
 * returns a guidance header pointing at the versioned path.
 */
class ApiVersionMiddleware
{
    public function __construct(
        private readonly string $version = '1',
        private readonly bool $legacyAlias = false,
    ) {}

    public function __invoke(Request $req, Handler $handler): Response
    {
        $res = $handler->handle($req)->withHeader('X-API-Version', $this->version);
        if ($this->legacyAlias) {
            $res = $res->withHeader('X-API-Note', 'Unversioned /api is an alias of /api/v1; call /api/v1 to pin a version.');
        }
        return $res;
    }
}
