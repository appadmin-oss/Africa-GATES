<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Canonicalise a trailing slash to a 301 instead of a 404.
 *
 * Every route on this site answered 404 for a trailing slash — `/awards/`,
 * `/registry/`, `/vote/`, `/privacy/`, `/shop/`, `/pulse/`, measured. Slim matches
 * paths exactly and nothing was normalising them.
 *
 * That is not a cosmetic problem for a platform whose links are shared by hand
 * across WhatsApp, Instagram bios, printed flyers and radio read-outs:
 *
 *  • A visitor who types `africagates.org/awards/` — a completely ordinary thing to
 *    do — reaches a dead end and has no reason to think the site works at all.
 *  • Any shared link that acquired a slash is simply broken, and nobody can tell
 *    the sharer why.
 *  • Search engines index `/awards` and `/awards/` as two URLs, one of them a 404,
 *    which splits ranking signals and surfaces the broken one.
 *
 * 301 rather than 302: the slashless form is the canonical one permanently, so
 * caches and crawlers should consolidate onto it rather than re-asking every time.
 *
 * ONLY safe methods are redirected. A 301 on a POST invites clients to re-issue it
 * as GET and silently drop the body — a nomination or payment quietly lost. For
 * POST/PUT/PATCH/DELETE the request is passed through untouched, so a form that
 * genuinely posts to a slashed path still works rather than losing its data to a
 * tidy-up.
 *
 * The root `/` is left alone: it is already canonical, and rewriting it would loop.
 */
final class TrailingSlashMiddleware implements MiddlewareInterface
{
    /** Methods where a redirect cannot lose a request body. */
    private const SAFE = ['GET', 'HEAD'];

    public function process(Request $request, Handler $handler): Response
    {
        $uri  = $request->getUri();
        $path = $uri->getPath();

        if ($path === '/' || !str_ends_with($path, '/')) {
            return $handler->handle($request);
        }
        if (!in_array(strtoupper($request->getMethod()), self::SAFE, true)) {
            // Never risk a lost nomination or payment for the sake of a tidy URL.
            return $handler->handle($request);
        }

        // Collapse any run of trailing slashes ("/awards///" → "/awards") so a single
        // hop lands on the canonical form rather than redirecting repeatedly.
        $target = rtrim($path, '/');
        if ($target === '') {
            $target = '/';
        }
        $query = $uri->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        return (new SlimResponse())
            ->withHeader('Location', $target)
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withStatus(301);
    }
}
