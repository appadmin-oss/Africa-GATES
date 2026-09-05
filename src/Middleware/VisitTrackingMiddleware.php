<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use AfricaGates\Services\VisitTracker;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Record one arrival per browsing session.
 *
 * ── MIDDLEWARE FOR THE SAME REASON THE REFERRAL CAPTURE IS ───────────────────
 *
 * The failure mode is FORGETTING. A `trackVisit()` call at the top of each public
 * controller is a rule somebody has to remember on the next page they add — and the next
 * page they add is exactly the one a campaign link will point at. {@see
 * ReferralCaptureMiddleware} carries the same note and the same scar.
 *
 * ── BEFORE THE HANDLER, AND NEVER IN ITS WAY ─────────────────────────────────
 *
 * The arrival is recorded on the way IN, because a session that converts on its very
 * first request — a QR scan landing straight on a pass — must already have a row to stamp
 * by the time the handler runs. {@see VisitTracker::record()} swallows everything and
 * returns '' rather than throwing: a tracker that can 500 the home page is worse than no
 * tracker at all, and this sits in front of every public request on the site.
 */
final class VisitTrackingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        VisitTracker::record($request);

        return $handler->handle($request);
    }
}
