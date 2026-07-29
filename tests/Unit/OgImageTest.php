<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Middleware\SecurityHeadersMiddleware;
use AfricaGates\Services\FlierService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The flier as the link preview.
 *
 * WHY THE GRAPHIC HAD TO BECOME SERVER-SIDE. An `og:image` is fetched by a crawler:
 * WhatsApp, Facebook and X do not render SVG in a preview and none of them run
 * JavaScript, so the browser-side canvas that produced the download could never be the
 * preview no matter how good it looked. A nominee sharing their ballot to a WhatsApp
 * group is the highest-intent share on this platform, and it was previewing as a bare
 * portrait — no name, no category, no standing, no reason to tap.
 *
 * The tests here are about the CONTRACT with those crawlers, which is unforgiving and
 * silent: get it wrong and the preview is simply blank, with nothing logged anywhere.
 */
class OgImageTest extends TestCase
{
    private function middleware(array $responseHeaders): ResponseInterface
    {
        $handler = new class ($responseHeaders) implements RequestHandlerInterface {
            public function __construct(private readonly array $h) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $res = new Response();
                foreach ($this->h as $k => $v) $res = $res->withHeader($k, $v);
                return $res;
            }
        };
        return (new SecurityHeadersMiddleware())(
            (new ServerRequestFactory())->createServerRequest('GET', '/x'),
            $handler
        );
    }

    public function test_a_publicly_cacheable_response_carries_no_session_cookie(): void
    {
        // The bug this exists for. session_start() runs unconditionally in the bootstrap,
        // before routing, so EVERY response left with a Set-Cookie: PHPSESSID — including
        // the flier PNG, which declares `public, max-age=600` because it is fetched by
        // every chat app and re-fetched by every recipient.
        //
        // A shared cache holding a `public` response WITH a Set-Cookie either refuses to
        // cache it — losing the entire point — or caches the header and hands one
        // visitor's session cookie to everyone who fetches the image afterwards. That is
        // session fixation by CDN, and it needs no attacker.
        $res = $this->middleware([
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=600',
            'Set-Cookie'    => 'PHPSESSID=abc123; path=/',
        ]);

        $this->assertFalse($res->hasHeader('Set-Cookie'),
            'a public response must never carry a session cookie');
        $this->assertSame('public, max-age=600', $res->getHeaderLine('Cache-Control'),
            'and it stays cacheable — the cookie is what was wrong, not the caching');
    }

    public function test_a_private_html_response_keeps_its_cookie(): void
    {
        // Scoped deliberately. Every HTML page is `private`, and stripping its cookie
        // would log everyone out — a fix worse by far than the problem.
        $res = $this->middleware([
            'Content-Type' => 'text/html',
            'Set-Cookie'   => 'PHPSESSID=abc123; path=/',
        ]);

        $this->assertTrue($res->hasHeader('Set-Cookie'));
        $this->assertStringContainsString('private', $res->getHeaderLine('Cache-Control'));
    }

    public function test_the_flier_png_route_declares_public_caching(): void
    {
        // A crawler re-fetches an og:image for every recipient of a shared link. Without
        // public caching that is one full render per recipient, and this render is not
        // free.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/FlierController.php');

        $this->assertMatchesRegularExpression(
            "~'Cache-Control',\s*'public, max-age=\d+~",
            $src,
            'the PNG must be publicly cacheable'
        );
        $this->assertStringContainsString('stale-while-revalidate', $src,
            'so an edge can serve the previous render while fetching the new one');
    }

    public function test_the_png_falls_back_rather_than_serving_a_broken_image(): void
    {
        // If GD or a bundled font is missing, a zero-byte or malformed PNG in a chat is
        // worse than no image: the preview shows a grey box and the nominee assumes the
        // platform is broken. Redirecting to the SVG at least renders in a browser.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/FlierController.php');

        $this->assertMatchesRegularExpression('~\$png === null~', $src);
        $this->assertStringContainsString(".svg'", $src, 'the fallback target');
    }

    public function test_the_dimensions_the_meta_tags_declare_match_the_image(): void
    {
        // A mismatch means a cropped or letterboxed preview. Both sides read the same
        // constants, so this pins that they are the ones actually rendered.
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/gates.twig');

        $this->assertStringContainsString('og:image:width', $layout);
        $this->assertStringContainsString('og:image:height', $layout);
        $this->assertStringContainsString('og:image:type', $layout);

        foreach ([
            dirname(__DIR__, 2) . '/src/Controllers/VoteController.php',
            dirname(__DIR__, 2) . '/src/Controllers/FlierController.php',
        ] as $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringContainsString('FlierService::W', $src,
                basename($file) . ' must declare the width from the renderer, not a literal');
            $this->assertStringContainsString('FlierService::H', $src);
        }
    }

    public function test_the_og_image_url_is_absolute(): void
    {
        // A relative og:image is silently ignored by every crawler and the preview falls
        // back to nothing. The single easiest way to ship a broken preview.
        foreach ([
            dirname(__DIR__, 2) . '/src/Controllers/VoteController.php',
            dirname(__DIR__, 2) . '/src/Controllers/FlierController.php',
        ] as $file) {
            $src = (string) file_get_contents($file);
            $this->assertMatchesRegularExpression(
                "~Env::get\('APP_URL'~",
                $src,
                basename($file) . ' must build the og:image from APP_URL'
            );
        }
    }

    public function test_the_ballot_previews_as_the_flier_not_the_bare_photo(): void
    {
        // The change that matters. A nominee's photo previews as a portrait with no
        // context; the flier carries the name, the category and the standing.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/VoteController.php');
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $src);

        $this->assertMatchesRegularExpression("~'og_image'\s*=>\s*\\\$flierPng~", $code);
        $this->assertStringNotContainsString('Assets::absoluteOg', $code,
            'the bare photo must no longer be the preview image');
    }

    public function test_only_one_og_image_alt_is_declared(): void
    {
        // A duplicate array key silently wins, and the LATER one did — so a
        // standings-aware alt was dead on arrival while reading in the diff as applied.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/VoteController.php');

        $this->assertSame(1, preg_match_all("~'og_image_alt'\s*=>~", $src),
            'two og_image_alt keys means one of them does nothing');
    }

    public function test_the_svg_keeps_its_own_hardened_policy(): void
    {
        // An SVG is a document the browser EXECUTES and its text contains a
        // public-submitted nominee name, so the route sends its own sandbox CSP. The
        // middleware used to overwrite it with the site policy, which permits scripts.
        $res = $this->middleware([
            'Content-Type'            => 'image/svg+xml',
            'Cache-Control'           => 'public, max-age=300',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        $this->assertSame("default-src 'none'; sandbox", $res->getHeaderLine('Content-Security-Policy'));
    }
}
