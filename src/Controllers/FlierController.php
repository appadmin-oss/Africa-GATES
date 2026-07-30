<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\FlierService;
use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The nominee's shareable flier: a page to preview it and two ways to take it away.
 *
 * `GET …/flier`      an HTML page — preview, the copy, a download button
 * `GET …/flier.svg`  the graphic itself, resolution-independent and printable
 *
 * The SVG route is what makes this work without JavaScript. It is a complete artefact:
 * right-click-save, share, print at A3. The PNG is drawn in the browser instead,
 * because that is the only place the site's real webfonts exist — see FlierService.
 */
final class FlierController
{
    public function __construct(
        private readonly Twig $view,
        private readonly FlierService $flier,
    ) {}

    /** GET /vote/{program}/{slug}/flier */
    public function page(Request $req, Response $res, array $args): Response
    {
        $f = $this->flier->forNominee($this->idFrom($args));
        if ($f === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $path = rtrim((string) $req->getUri()->getPath(), '/');
        // Absolute for og:image — a relative path is silently ignored by every crawler
        // and the preview falls back to nothing.
        $abs = (rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/')
            ?: 'https://afg.afrovanguard.org.ng') . $path;
        // The card lives beside the flier, one segment up: …/{slug}/flier → …/{slug}/card.png
        $cardAbs = preg_replace('~/flier$~', '', $abs) . '/card.png';

        return $this->view->render($res, 'pages/vote-flier.twig', [
            'page_title'       => 'Share a flier for ' . $f['name'] . ' — Africa GATES',
            'meta_description' => 'Download a ready-to-post flier asking your community to vote for '
                . $f['name'] . ' in ' . $f['category'] . '.',
            'gates_page'       => 'vote',
            'has_hero'         => false,
            'f'                => $f,
            'svg_url'          => $path . '.svg',
            'png_url'          => $path . '.png',
            // Shared with the download so the file the OS share sheet receives is named
            // the same as the one the button saves — the template previously built a
            // THIRD variant in Twig, which produced a non-ASCII filename.
            'file_name'        => $this->filename($f['name']) . '.png',
            // The 1200×630 CARD, not this page's own flier PNG. A nominee sharing the
            // flier page gets a preview built for the shape the platforms crop to —
            // otherwise the flier's bottom third (the vote URL) is cut off in exactly the
            // surface where the sharing happens. See FlierService::ogCard().
            'og_image'         => $cardAbs,
            'og_image_w'       => \AfricaGates\Services\FlierService::OG_W,
            'og_image_h'       => \AfricaGates\Services\FlierService::OG_H,
            'og_image_type'    => 'image/png',
            'og_image_alt'     => 'Vote for ' . $f['name'] . ' in ' . $f['category'] . ' — ' . $f['headline'],
        ]);
    }

    /** GET /vote/{program}/{slug}/flier.svg */
    public function svg(Request $req, Response $res, array $args): Response
    {
        $f = $this->flier->forNominee($this->idFrom($args));
        if ($f === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $res->getBody()->write($this->flier->svg($f));

        return $res
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            // Public and short: the graphic contains no per-visitor data, and it MUST go
            // stale quickly because the standing printed on it changes. Five minutes is
            // long enough for a CDN to absorb a rally and short enough that a shared
            // link never shows a rank from yesterday.
            ->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
            // An SVG is executable. Even served same-origin from a path a nominee
            // controls the name inside, it gets its own hard sandbox: no scripts, no
            // plugins, no framing. FlierService escapes every interpolation; this is the
            // layer that holds if that ever fails.
            ->withHeader('Content-Security-Policy',
                "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; sandbox")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Names the download rather than leaving the browser to invent one from the
            // path — "flier.svg" for every nominee is unusable in a downloads folder.
            ->withHeader('Content-Disposition',
                'inline; filename="' . $this->filename($f['name']) . '.svg"');
    }

    /**
     * GET /vote/{program}/{slug}/flier.png
     *
     * The raster, and the reason it is server-side: this URL is the `og:image`. WhatsApp,
     * Facebook and X do not render SVG in a link preview, and a crawler cannot run the
     * browser-side canvas that used to produce the download.
     */
    public function png(Request $req, Response $res, array $args): Response
    {
        $f = $this->flier->forNominee($this->idFrom($args));
        if ($f === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $png = $this->flier->png($f);
        if ($png === null) {
            // GD or a bundled font is unavailable. Redirect to the SVG rather than serve a
            // broken image: a browser following the link still sees the flier, and the
            // failure is visible in `app:doctor` instead of as a grey box in a chat.
            return $res
                ->withHeader('Location', rtrim($req->getUri()->getPath(), '.png') . '.svg')
                ->withStatus(302);
        }

        $res->getBody()->write($png);

        return $res
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Length', (string) strlen($png))
            // Public and short-lived. A crawler caches whatever it fetches, sometimes for
            // days, so the window is a compromise: long enough for a shared link to be
            // previewed cheaply, short enough that the standing printed on it is not
            // yesterday's. stale-while-revalidate lets an edge serve the old one while
            // fetching the new, which matters because this render is not free.
            ->withHeader('Cache-Control', 'public, max-age=600, stale-while-revalidate=1800')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Named so a downloads folder is usable. `inline` because it is also fetched
            // as an og:image, and Content-Disposition: attachment confuses some crawlers.
            ->withHeader('Content-Disposition',
                'inline; filename="' . $this->filename($f['name']) . '.png"');
    }

    /**
     * GET /vote/{program}/{slug}/card.png — the 1200×630 link-preview card.
     *
     * A separate graphic from `flier.png`, not a variant of it: the flier is 4:5 and every
     * platform crops an `og:image` to 1.91:1 or squarer, so sharing a ballot link
     * previewed with the vote URL and the rally copy sliced off the bottom. See
     * {@see FlierService::OG_W}.
     *
     * Falls back to the flier PNG when GD or a font is missing, which in turn falls back
     * to the SVG — so a link preview degrades through two steps before it can be nothing.
     */
    public function card(Request $req, Response $res, array $args): Response
    {
        $f = $this->flier->forNominee($this->idFrom($args));
        if ($f === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $png = $this->flier->ogCard($f);
        if ($png === null) {
            $path = (string) $req->getUri()->getPath();
            return $res
                ->withHeader('Location', substr($path, 0, -strlen('card.png')) . 'flier.png')
                ->withStatus(302);
        }

        $res->getBody()->write($png);

        return $res
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Length', (string) strlen($png))
            // Same window as the flier PNG and for the same reason: a crawler caches what
            // it fetches, sometimes for days, so this has to be long enough to make a
            // shared link cheap to preview and short enough that the rank printed on it is
            // not yesterday's.
            ->withHeader('Cache-Control', 'public, max-age=600, stale-while-revalidate=1800')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition',
                'inline; filename="' . $this->filename($f['name']) . '-card.png"');
    }

    /**
     * The numeric id from the canonical `{id}-{name}` slug.
     *
     * The route pattern already constrains the leading digits, so this is a cast rather
     * than a parse — but it is done in one place so the two actions cannot disagree
     * about what "the nominee" means.
     */
    private function idFrom(array $args): int
    {
        return (int) (string) ($args['slug'] ?? '0');
    }

    /**
     * ASCII, lowercase, hyphenated. A filename crosses filesystems this app never sees.
     *
     * Slug::make, because this was a SIXTH copy of the accent-deleting expression and it
     * produced `vote-l-s-nk-nm-ad-b-y.png` for Ọlásùnkànmí Adébáyọ̀ — found by watching
     * the real download rather than by reading the code. It used the `[^A-Za-z0-9]`
     * spelling rather than `[^a-z0-9]+/i`, which is why the scan that swept the other
     * five did not see it; that scan now covers both spellings.
     */
    private function filename(string $name): string
    {
        return 'vote-' . (\AfricaGates\Support\Slug::make($name, 48) ?: 'nominee');
    }
}
