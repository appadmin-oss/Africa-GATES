<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The live activity search.
 *
 * TWO ENTRY POINTS, and the HTML one is not a fallback — it is the feature.
 *
 * `GET /activity?q=…` renders the results server-side from a plain `<form method=get>`.
 * It works with JavaScript disabled, with JavaScript that failed to load, on a browser
 * too old for `fetch`, and in a text browser. That is not a theoretical audience for a
 * platform aimed at the whole continent: a large share of traffic here is low-end
 * Android on an intermittent connection, where a script that did not arrive is a
 * routine event rather than an edge case. A search box that is only a script is a
 * search box that is sometimes simply absent.
 *
 * `GET /activity/search` returns the same data as JSON for the live layer, which
 * upgrades the working form into a combobox that answers as you type. Same service,
 * same shape, so the two can never disagree about what happened on the site.
 *
 * The JSON endpoint is rate limited and the search is uncached — see
 * {@see ActivityFeedService} for why the freshness is worth the queries, and what
 * bounds them.
 */
final class ActivityController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ActivityFeedService $feed,
    ) {}

    /** GET /activity — works with no JavaScript at all. */
    public function index(Request $req, Response $res): Response
    {
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
        // `?literal=1` opts OUT of interpretation. Offered as a link on the page beside
        // whatever the model understood, so a wrong reading is one click from a plain
        // text search rather than something a visitor has to work around.
        $literal = ($req->getQueryParams()['literal'] ?? '') !== '';
        $result  = $this->feed->search($q, 40, interpret: !$literal);

        return $this->view->render($res, 'pages/activity.twig', [
            'page_title'       => ($q !== '' ? 'Search: ' . $q . ' — ' : '') . 'Activity — Africa GATES',
            'meta_description' => 'Everything happening across Africa GATES — nominees entered, '
                . 'results published, cycles opening and closing, stories, events and discussions. '
                . 'Searchable and live.',
            'gates_page'       => 'activity',
            'has_hero'         => false,
            'q'                => $q,
            'items'            => $result['items'],
            'live'             => $result['live'],
            'sources'          => $result['sources'],
            'understood'       => $result['understood'],
            'literal'          => $literal,
            'min_query'        => ActivityFeedService::MIN_QUERY,
        ]);
    }

    /** GET /activity/search — JSON for the live layer. */
    public function search(Request $req, Response $res): Response
    {
        $json = function (array $payload, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $res
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                // no-store, not the site default: a search result is per-visitor and
                // must never be reused, and the endpoint's whole promise is that it
                // reflects the database right now.
                ->withHeader('Cache-Control', 'no-store')
                ->withStatus($code);
        };

        // Per-client budget. A live search fires on keystrokes, so the debounce is the
        // first line and this is the one that holds when the debounce is bypassed —
        // which is what a script hitting the endpoint directly does.
        try {
            if (!(new RateLimitService())->check($this->clientKey($req), 'activity_search', 120, 60)) {
                return $json(['ok' => false, 'error' => 'Too many searches — pause a moment.'], 429);
            }
        } catch (\Throwable) {
            // A rate-limiter outage must not take a read-only public search down.
        }

        $q       = trim((string) ($req->getQueryParams()['q'] ?? ''));
        $limit   = (int) ($req->getQueryParams()['limit'] ?? 20);
        $literal = ($req->getQueryParams()['literal'] ?? '') !== '';
        $result  = $this->feed->search($q, $limit, interpret: !$literal);

        return $json([
            'ok'      => true,
            'query'   => $result['query'],
            'live'    => $result['live'],
            'count'   => count($result['items']),
            'items'   => $result['items'],
            'sources' => $result['sources'],
            // What the model understood, so the live layer can show it and offer the
            // literal search. Null whenever nothing was interpreted, which is also what
            // a deployment with no AI key gets.
            'understood' => $result['understood'],
        ]);
    }

    /**
     * Rate-limit bucket for this client.
     *
     * X-Forwarded-For is only trusted when TRUST_PROXY says a proxy sets it. Behind a
     * CDN without that flag every visitor shares REMOTE_ADDR — the CDN's — and a
     * 120-per-minute budget becomes 120 for the entire continent. Hashed so the
     * limiter's storage never holds a raw address.
     */
    private function clientKey(Request $req): string
    {
        $server = $req->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        if (Env::bool('TRUST_PROXY') && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return hash('sha256', $ip . '|activity');
    }
}
