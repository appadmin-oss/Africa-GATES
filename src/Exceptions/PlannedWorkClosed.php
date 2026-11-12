<?php
declare(strict_types=1);

namespace AfricaGates\Exceptions;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;

/**
 * The public site is closed for planned work an operator announced (Services\MaintenanceGate).
 *
 * Its own type, not a bare 503, because ErrorHandler answers it differently from a fault:
 * no PublicFault reference (nothing broke, and a reference invites somebody to report it),
 * a `Retry-After` taken from the window's end, and the page states the window itself.
 */
final class PlannedWorkClosed extends HttpException
{
    /** @param array<string,mixed> $planned PlannedWork::current() */
    public function __construct(ServerRequestInterface $request, public readonly array $planned)
    {
        parent::__construct($request, 'Closed for planned work.', 503);
        $this->setTitle('503 Service Unavailable');
    }

    /** Seconds until the window ends, never under a minute: what `Retry-After` says. */
    public function retryAfter(): int
    {
        $to = strtotime((string) ($this->planned['to'] ?? ''));
        return max(60, $to === false ? 600 : $to - time());
    }
}
