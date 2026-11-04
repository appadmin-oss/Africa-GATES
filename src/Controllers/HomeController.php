<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{CacheService, GlobeBand, HomeFront, HomepageImages};
use AfricaGates\Support\NationsLive;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `GET /` — the homepage, rebuilt in Phase 4 from HomePageV3.dc.html + WeAreAfrica.dc.html.
 *
 * Destroyed and written again with the page (inventory: docs/handoff/inventory/pages--home.md).
 * It hands the template exactly what the template reads and nothing else: the old controller
 * resolved and cached five datasets the page had stopped drawing, which is §17 at the view
 * layer (`TemplateContextTest`) and four wasted queries on the busiest URL on the site.
 *
 * Every figure comes from {@see HomeFront} (stats, voting, results, the featured campaign)
 * or {@see GlobeBand} (the map), so the page has two places to ask and none to type into.
 * Cached briefly and tagged like the rest of the public counts, so a vote or a registration
 * invalidates it.
 */
final class HomeController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly HomepageImages $images,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        // Home V5 (16 Oct 2026) tells stories and counts nothing: the live stats strip,
        // "Happening now" and "Who recognises" are gone with the V3 layout. What is still
        // read is real records — decided awards for the hero and the closing band, the open
        // challenge, the featured campaign — and each block follows LOW-DATA-RULES.md.
        $front = $this->cache->remember('home:front:v7', 300, static fn (): array => [
            'decided'   => HomeFront::decided(),
            'campaign'  => HomeFront::campaign(),
            'challenge' => HomeFront::featuredChallenge(),
            // V7's counter. Shown as a number only from 1,000 — below that the DC's own
            // fallback headline stands in, so a young platform never boasts a small figure.
            'honoured'  => \AfricaGates\Services\Recognitions::peopleCount(),
        ], ['leaderboard', 'registry']);

        $band = $this->cache->remember('home:globe', 900, static fn (): array => [
            'countries' => GlobeBand::countries(),
            'note'      => GlobeBand::note(),
        ], ['leaderboard', 'registry']);

        return $this->view->render($res, 'pages/home.twig', $this->images->build($front['decided']) + [
            'page_title'       => 'Africa GATES — where Africa recognises its people',
            // Computed, never typed: "live in Nigeria" was written here while NationsLive
            // existed to answer it (the fault the footer, the JSON-LD and the guide had).
            'meta_description' => 'Nominate the people who make a difference, vote for them and see '
                . 'every result decided in the open. Africa GATES is live in '
                . NationsLive::phrase() . '.',
            'gates_page'       => 'home',
            'tab'              => 'home',
            'challenge'        => $front['challenge'],
            'honoured'         => (int) ($front['honoured'] ?? 0),
            'campaign'         => $front['campaign'],
            'globe_countries'  => $band['countries'],
            'globe_note'       => $band['note'],
        ]);
    }

}
