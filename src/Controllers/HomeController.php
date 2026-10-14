<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{CacheService, GlobeBand, HomeFront};
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
    public function __construct(private readonly Twig $view, private readonly CacheService $cache) {}

    public function index(Request $req, Response $res): Response
    {
        $front = $this->cache->remember('home:front', 300, static fn (): array => [
            'stats'        => HomeFront::stats(),
            'voting'       => HomeFront::voting(),
            'decided'      => HomeFront::decided(),
            'campaign'     => HomeFront::campaign(),
            'recognitions' => \AfricaGates\Services\Recognitions::recent(12)
                              ?: HomeFront::asRecognitions(HomeFront::decided()),
            'hero_slides'  => \AfricaGates\Support\SchemaHas::table('gates_home_features') ? \AfricaGates\Models\HomeFeature::getActiveByType('hero_slide') : [],
            'topic_cards'  => \AfricaGates\Support\SchemaHas::table('gates_home_features') ? \AfricaGates\Models\HomeFeature::getActiveByType('topic') : [],
            'featured_wide' => \AfricaGates\Support\SchemaHas::table('gates_home_features') ? \AfricaGates\Models\HomeFeature::getActiveByType('featured_wide')->first() : null,
        ], ['leaderboard', 'registry']);

        $band = $this->cache->remember('home:globe', 900, static fn (): array => [
            'countries' => GlobeBand::countries(),
            'note'      => GlobeBand::note(),
        ], ['leaderboard', 'registry']);

        return $this->view->render($res, 'pages/home.twig', [
            'page_title'       => 'Africa GATES — where Africa recognises its people',
            // Computed, never typed: "live in Nigeria" was written here while NationsLive
            // existed to answer it (the fault the footer, the JSON-LD and the guide had).
            'meta_description' => 'Nominate the people who make a difference, vote for them and see '
                . 'every result decided in the open. Africa GATES is live in '
                . NationsLive::phrase() . '.',
            'gates_page'       => 'home',
            'tab'              => 'home',
            'stats'            => $front['stats'],
            'voting'           => $front['voting'],
            'decided'          => $front['decided'],
            'campaign'         => $front['campaign'],
            'recognitions'     => $front['recognitions'] ?? [],
            'hero_slides'      => $front['hero_slides'] ?? [],
            'topic_cards'      => $front['topic_cards'] ?? [],
            'featured_wide'    => $front['featured_wide'] ?? null,
            'globe_countries'  => $band['countries'],
            'globe_note'       => $band['note'],
        ]);
    }
}
