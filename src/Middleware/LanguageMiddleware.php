<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use AfricaGates\Support\Languages;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Settle which language this request renders in, and remember the answer.
 *
 * ── MIDDLEWARE, FOR THE REASON THE OTHER TWO ARE ─────────────────────────────
 *
 * The failure mode is FORGETTING. `?lang=fr` has to work on every page — a link
 * somebody sends a friend points at whichever page they were reading, not at a
 * settings screen — so a `Languages::observe()` call at the top of each controller
 * is a rule that four hundred routes have to keep and the next new route will not.
 * {@see VisitTrackingMiddleware} and {@see ReferralCaptureMiddleware} carry the
 * same note and the same scar.
 *
 * ── ONE WRITER FOR THE COOKIE ────────────────────────────────────────────────
 *
 * Every language control on the site — the header's globe popover, the Quick
 * settings chips, the Menu's radio list, the Display & reading select and the
 * first-visit prompt — is a LINK carrying `?lang=`, and this is the only code that
 * turns one into a stored preference. Two writers of one cookie is how the halves
 * of a setting come to disagree about what was chosen, and it is also how a
 * control ends up needing JavaScript: these all work with scripting off, which for
 * a language switch matters most to exactly the people likeliest to need it.
 *
 * ── THE COOKIE IS WRITTEN ON THE WAY OUT, NOT ON THE WAY IN ──────────────────
 *
 * `observe()` runs before the handler so the page renders in the new language on
 * the very request that asked for it; the `Set-Cookie` goes onto the response that
 * comes back. Writing it on the way in would mean calling `setcookie()` against
 * the output buffer, which is invisible to every test that renders the route.
 *
 * It is written only when `?lang=` was actually present. Re-stamping it on every
 * request would refresh a year-long cookie on each page view, which is a different
 * promise from the one the cookie policy publishes.
 */
final class LanguageMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        Languages::observe($request);

        $asked = $request->getQueryParams()['lang'] ?? null;

        $response = $handler->handle($request);

        // An unsupported `?lang=` is ignored rather than corrected: nothing was
        // chosen, so nothing is stored, and the first-visit prompt may still ask.
        if (Languages::supported($asked)) {
            // Kept for a year only with the visitor's Preferences answer; otherwise for
            // this browsing session. CookiePrefs is the one resolver for that question.
            $response = Languages::apply($response, (string) $asked,
                \AfricaGates\Services\CookiePrefs::allows($request, \AfricaGates\Services\CookiePrefs::PREFERENCES));
        }

        return $response;
    }
}
