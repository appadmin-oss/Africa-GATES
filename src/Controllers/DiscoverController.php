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

        // ── NOTHING FOUND: CORRECT A SLIP, THEN COUNT WHAT IS LEFT ──────────────────────
        //
        // Roughly one search in ten carries a typo. When this one found nothing, its unknown
        // words are corrected to the nearest words the site actually holds, and the corrected
        // search is SHOWN only if it finds something — with the page saying so and offering
        // the original back (`literal=1` is "search for exactly what I typed"). What still
        // finds nothing is counted for the Analytics page, anonymously (SearchMisses).
        // As-you-type (discover.js, `X-Discover-Live`): the same page, swapped in section by
        // section. A keystroke is not a search somebody finished, so it is counted as a miss
        // only when the script says the typing has SETTLED (1.5 s idle on a result that found
        // nothing) — otherwise "c", "ch", "chi" would each be a miss on the analytics page.
        $typing  = $req->getHeaderLine('X-Discover-Live');
        $corrected = null;
        if (!$fragment && $s['q'] !== '' && self::found($s, $data) === 0) {
            $fix = $s['literal'] ? null : $this->discover->suggest($s['q']);
            if ($fix !== null) {
                $s2 = ['q' => $fix] + $s;
                $d2 = $this->discover->page($s2);
                if (self::found($s2, $d2) > 0) {
                    $corrected = ['from' => $s['q'], 'to' => $fix, 'original' => Discover::url($s, ['literal' => true, 'page' => 1])];
                    [$s, $data] = [$s2, $d2];
                }
            }
            if ($corrected === null && ($typing === '' || $typing === 'settled')) \AfricaGates\Services\SearchMisses::record($s['q'], $s['tab']);
        }

        if ($fragment) {
            return $this->view->render(
                $res->withHeader('Cache-Control', 'no-store'),
                'partials/discover-live.twig',
                ['s' => $s, 'live' => $data['live'], 'min_query' => ActivityFeedService::MIN_QUERY],
            );
        }

        return $this->view->render($res, 'pages/discover.twig', $this->vars($s, $data)
            // No ad for a keystroke: the script keeps the slot it has, and serving one per
            // request would count an impression per letter typed — money, not a display.
            + ['corrected' => $corrected, 'ad' => $typing !== '' ? null : \AfricaGates\Services\Ads\AdDelivery::forRequest('discover', $req)]);
    }

    /** What the visitor's tab found: its own count, or the updates on Live, plus Live's rows on All. */
    private static function found(array $s, array $data): int
    {
        $live = count($data['live']['rows'] ?? []);
        if ($s['tab'] === 'live') return $live;
        return (int) ($data['counts'][$s['tab']] ?? 0) + ($s['tab'] === 'all' ? $live : 0);
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
    /**
     * What each tab is, for a search engine and a link preview. Every tab used to go out as
     * "Discover — Africa GATES" with one description and no canonical, so People and
     * Organisations — the directory pages most worth finding — read to a crawler as
     * duplicates of the hub. Each tab is its own page now, with its own title and words.
     */
    private const TAB_SEO = [
        'all'     => ['Discover', 'Find people, organisations, awards, results and events on Africa GATES, and follow everything happening now.'],
        'live'    => ['What’s happening now', 'Nominations, votes, results and events across Africa GATES, as they happen.'],
        'people'  => ['People', 'Nominees standing in Africa GATES awards and the people registered on the platform, across Africa.'],
        'orgs'    => ['Organisations', 'The organisations that run Africa GATES awards and those registered on the platform.'],
        'awards'  => ['Awards open now', 'Africa GATES awards taking nominations or votes, and the categories inside them.'],
        'results' => ['Results', 'Every announced Africa GATES result: winners and runners-up, by award and category.'],
        'events'  => ['Upcoming ceremonies', 'Award ceremonies and events coming up on Africa GATES.'],
    ];

    private function vars(array $s, ?array $data): array
    {
        [$label, $about] = self::TAB_SEO[$s['tab']] ?? self::TAB_SEO['all'];
        // A search, a filter or a deeper page is the same page as the bare tab to a crawler:
        // noindex, and the canonical names the tab itself so any link equity lands there.
        $filtered = $s['q'] !== '' || $s['page'] > 1 || $s['where'] !== '' || $s['status'] !== ''
            || $s['trust'] !== [] || ($s['tab'] === 'live' && $s['kind'] !== 'all');
        return [
            'page_title'       => ($s['q'] !== '' ? Translator::t('Search: %q%', ['%q%' => $s['q']]) . ' — ' : '')
                . Translator::t($label) . ($s['tab'] !== 'all' ? ' — ' . Translator::t('Discover') : '') . ' — Africa GATES',
            'meta_description' => Translator::t($about),
            'canonical_path'   => '/discover' . ($s['tab'] !== 'all' ? '?tab=' . $s['tab'] : ''),
            'breadcrumbs'      => array_values(array_filter([
                ['label' => 'Africa GATES', 'url' => '/'],
                ['label' => Translator::t('Discover'), 'url' => '/discover'],
                $s['tab'] !== 'all' ? ['label' => Translator::t($label), 'url' => '/discover?tab=' . $s['tab']] : null,
            ])),
            'tab'              => 'discover',
            'app_bar'          => ['variant' => 'root', 'title' => Translator::t('Discover'), 'search' => false],
            'meta_robots'      => $filtered ? 'noindex, follow' : 'index, follow',
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
