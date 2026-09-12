<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use AfricaGates\Services\ReferralService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * A referral link works anywhere on the site, not only on an events page.
 *
 * ── THE BUG THIS FIXES IS A SILENT LOSS OF SOMEBODY'S MONEY ──────────────────
 *
 * The `?ref=` capture lived inside EventsController and ran only on /events pages. So a
 * member who shared their link — to the shop, the home page, a nominee's profile, anything
 * a person would plausibly click — earned nothing. The code was dropped on the first
 * navigation and never seen again.
 *
 * From the referrer's side that is indistinguishable from the platform not paying: they did
 * everything they were asked, somebody bought something, and no credit appeared. From the
 * platform's side it produced no error at all.
 *
 * ── WHY MIDDLEWARE AND NOT ANOTHER CALL IN EACH CONTROLLER ───────────────────
 *
 * Because the failure mode is FORGETTING, and the fix has to be one that cannot be
 * forgotten. A `captureRef()` call at the top of every public controller is a rule somebody
 * has to remember on the next page they add — and the next page they add is exactly where a
 * link ends up being shared.
 *
 * ── GET ONLY, AND NOTHING ELSE TOUCHED ───────────────────────────────────────
 *
 * A `?ref=` on a POST is not somebody following a link; it is a form action that happens to
 * carry a query string, and honouring it would let a crafted form re-attribute a purchase
 * already in progress. The referral is claimed by NAVIGATION.
 *
 * This writes one session key and nothing else — no redirect, no header, no cookie. The
 * code stays in the URL, which is deliberate: stripping it would make a shared link change
 * shape when it is copied out of the address bar and pasted on again.
 */
final class ReferralCaptureMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            $params = $request->getQueryParams();

            // `ref` is the documented one. `r` because short links get shortened again by
            // the people sharing them, and a referrer who typed the short form should not
            // silently lose the commission for it.
            $raw = (string) ($params['ref'] ?? $params['r'] ?? '');

            if ($raw !== '') ReferralService::capture($raw);
        }

        return $handler->handle($request);
    }
}
