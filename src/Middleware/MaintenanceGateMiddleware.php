<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use AfricaGates\Exceptions\PlannedWorkClosed;
use AfricaGates\Services\MaintenanceGate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Closes the public site during planned work an operator chose to close it for
 * ({@see MaintenanceGate}). Throws rather than rendering, so the one error page — its
 * language, its chrome, its JSON branch for an API caller — answers it.
 *
 * Fails OPEN: a settings table that cannot be read leaves the site up. A gate that can
 * close the site by failing is an outage generator.
 */
final class MaintenanceGateMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $planned = null;
        try {
            if (!MaintenanceGate::open($request->getUri()->getPath()) && !MaintenanceGate::isStaff()) {
                $planned = MaintenanceGate::closed();
            }
        } catch (\Throwable) {
            $planned = null;
        }
        if ($planned !== null) throw new PlannedWorkClosed($request, $planned);
        return $handler->handle($request);
    }
}
