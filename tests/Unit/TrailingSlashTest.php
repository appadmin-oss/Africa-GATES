<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Middleware\TrailingSlashMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * A trailing slash must canonicalise, not 404.
 *
 * Measured before this existed: `/awards/`, `/registry/`, `/vote/`, `/privacy/`,
 * `/shop/` and `/pulse/` all returned 404. Slim matches paths exactly and nothing
 * normalised them.
 *
 * For a platform whose links travel by hand — WhatsApp, an Instagram bio, a printed
 * flyer, a radio read-out — that is a real failure, not a tidiness issue. Someone who
 * types `africagates.org/awards/` reaches a dead end and concludes the site is
 * broken, and any shared link that picked up a slash is silently dead.
 */
class TrailingSlashTest extends TestCase
{
    /** @return array{0:int,1:string} status and Location */
    private function through(string $method, string $path, string $query = ''): array
    {
        $uri = 'http://gates.test' . $path . ($query !== '' ? '?' . $query : '');
        $req = (new ServerRequestFactory())->createServerRequest($method, $uri);
        $res = (new TrailingSlashMiddleware())->process(
            $req,
            new class implements \Psr\Http\Server\RequestHandlerInterface {
                public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
                {
                    // Stand-in for the app: 200 means the request was passed through.
                    return (new Response())->withStatus(200);
                }
            }
        );
        return [$res->getStatusCode(), $res->getHeaderLine('Location')];
    }

    public function test_a_trailing_slash_redirects_permanently(): void
    {
        // 301, not 302: the slashless form is canonical permanently, so caches and
        // crawlers should consolidate rather than re-ask on every request.
        [$status, $loc] = $this->through('GET', '/awards/');

        $this->assertSame(301, $status);
        $this->assertSame('/awards', $loc);
    }

    public function test_repeated_slashes_collapse_in_one_hop(): void
    {
        // Stripping one slash at a time would redirect three times for `/awards///`,
        // which browsers and crawlers both punish.
        [$status, $loc] = $this->through('GET', '/awards///');

        $this->assertSame(301, $status);
        $this->assertSame('/awards', $loc);
    }

    public function test_the_query_string_survives(): void
    {
        // Losing it would silently drop a search, a filter, or a share token — the
        // user sees an unfiltered page and no explanation.
        [, $loc] = $this->through('GET', '/registry/', 'q=ada&page=2');

        $this->assertSame('/registry?q=ada&page=2', $loc);
    }

    public function test_the_root_is_left_alone(): void
    {
        // `/` is already canonical; rewriting it would loop forever.
        [$status, $loc] = $this->through('GET', '/');

        $this->assertSame(200, $status);
        $this->assertSame('', $loc);
    }

    public function test_a_path_without_a_slash_passes_straight_through(): void
    {
        [$status] = $this->through('GET', '/awards');
        $this->assertSame(200, $status);
    }

    public function test_a_post_is_never_redirected(): void
    {
        // A 301 invites the client to re-issue as GET and drop the body — a
        // nomination or a payment quietly lost for the sake of a tidy URL. Passing it
        // through means a form posting to a slashed path still works.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            [$status, $loc] = $this->through($method, '/nominate/');
            $this->assertSame(200, $status, "{$method} must reach the app");
            $this->assertSame('', $loc, "{$method} must not be redirected");
        }
    }

    public function test_head_is_redirected_like_get(): void
    {
        // Crawlers and link-checkers use HEAD; leaving it on the 404 path would keep
        // reporting the slashed URL as broken.
        [$status] = $this->through('HEAD', '/awards/');
        $this->assertSame(301, $status);
    }

    public function test_the_redirect_is_cacheable(): void
    {
        // A permanent redirect that forbids caching costs every visitor an extra
        // round trip on a connection where round trips are the expensive part.
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'http://gates.test/awards/');
        $res = (new TrailingSlashMiddleware())->process(
            $req,
            new class implements \Psr\Http\Server\RequestHandlerInterface {
                public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
                { return new Response(); }
            }
        );

        $this->assertStringContainsString('max-age', $res->getHeaderLine('Cache-Control'));
    }
}
