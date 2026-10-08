<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\Discover;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Support\ClientIp;
use AfricaGates\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/discover` — Phase 4, design/DiscoverPage.dc.html. The page {@see Discover} resolves.
 *
 * ── ONE ADDRESS, THREE ANSWERS, ONE RENDERER ─────────────────────────────────
 *
 *  · `GET /discover` — the page, whole, server-rendered. Every tab, chip, filter and
 *    "Show older updates" is a URL, so all of it works with no JavaScript, and Back
 *    restores exactly what was on screen (REFERENCE §10: filters live in the query).
 *  · `GET /discover?…&fragment=live` — the timeline section alone, as HTML, for the Live
 *    tab's as-you-type combobox. The SAME partial the page includes, so a row cannot be
 *    drawn one way by Twig and another by a script (the old activity page carried a
 *    second copy of its row markup in JavaScript).
 *  · `GET /discover/count?…` — the number the filter panel's "Show N results" button
 *    prints while somebody is still choosing (skill §5: the live count).
 *
 * `/activity` — the timeline's old page, whose controller this replaced — is a 301 to
 * `/discover?tab=live`, keeping `q` and `literal` (routes.php).
 *
 * A request carrying a query runs every timeline source uncached, so the two
 * query-bearing answers share the search palette's limit: 120 a minute per client.
 */
final class DiscoverController
{
    private const RATE = 120;

    public function __construct(
        private readonly Twig $view,
        private readonly Discover $discover = new Discover(),
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $s        = Discover::state($req->getQueryParams());
        $fragment = ($req->getQueryParams()['fragment'] ?? '') === 'live';

        if (($s['q'] !== '' || $fragment) && !$this->allowed($req)) {
            return $this->view->render($res->withStatus(429), 'pages/discover.twig', $this->vars($s, null));
        }

        $data = $this->discover->page($s);

        if ($fragment) {
            return $this->view->render(
                $res->withHeader('Cache-Control', 'no-store'),
                'partials/discover-live.twig',
                ['s' => $s, 'live' => $data['live'], 'min_query' => ActivityFeedService::MIN_QUERY],
            );
        }

        return $this->view->render($res, 'pages/discover.twig', $this->vars($s, $data));
    }

    /** GET /discover/count — JSON `{ok, count}` for the filter panel's button. */
    public function count(Request $req, Response $res): Response
    {
        if (!$this->allowed($req)) {
            $res->getBody()->write((string) json_encode(['ok' => false]));
            return $res->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(429);
        }
        $n = $this->discover->count(Discover::state($req->getQueryParams()));
        $res->getBody()->write((string) json_encode(['ok' => true, 'count' => $n]));

        return $res->withHeader('Content-Type', 'application/json; charset=utf-8')
                   ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed>|null $data null when the request was refused for rate */
    private function vars(array $s, ?array $data): array
    {
        return [
            'page_title'       => ($s['q'] !== '' ? Translator::t('Search: %q%', ['%q%' => $s['q']]) . ' — ' : '')
                . Translator::t('Discover') . ' — Africa GATES',
            'meta_description' => Translator::t('Find people, organisations, awards, results and events on Africa GATES, and follow everything happening now.'),
            'tab'              => 'discover',
            'app_bar'          => ['variant' => 'root', 'title' => Translator::t('Discover'), 'search' => false],
            // A filtered or searched view is the same page as the bare one for a crawler.
            'meta_robots'      => ($s['q'] !== '' || $s['page'] > 1) ? 'noindex, follow' : 'index, follow',
            's'                => $s,
            'd'                => $data,
            'min_query'        => ActivityFeedService::MIN_QUERY,
        ];
    }

    private function allowed(Request $req): bool
    {
        try {
            return (new RateLimitService())->check(
                ClientIp::fingerprint(ClientIp::from($req), 'discover'), 'discover', self::RATE, 60);
        } catch (\Throwable) {
            // A limiter outage must not take a read-only public page down.
            return true;
        }
    }
}
