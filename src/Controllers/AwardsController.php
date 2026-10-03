<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,AwardService};
use AfricaGates\Admin\Services\SettingsService;
use AfricaGates\Admin\Controllers\AwardsPageController;

class AwardsController {
    public function __construct(private readonly Twig $view,private readonly CacheService $cache,private readonly AwardService $awards,private readonly ?SettingsService $settings=null){}
    public function index(Request $req,Response $res):Response {
        // Programme cards are live data (cached); page copy comes from admin-editable
        // settings (resolved fresh so edits show immediately, never cached).
        return $this->view->render($res,'pages/awards/index.twig',['page_title'=>'Awards — Africa GATES','meta_description'=>'Explore every Africa GATES award programme — open cycles, categories and how community votes and expert judges crown the continent\'s cultural best.','gates_page'=>'awards','page'=>AwardsPageController::resolved($this->settings),'awards_data'=>$this->cache->remember('awards:index',1800,fn()=>$this->awards->getActiveProgrammesWithStatus())]);
    }
    public function programme(Request $req,Response $res,array $args):Response {
        // `v2`: the entry gained the cover, the terms and the edition span. An entry cached
        // under the old key would be served for half an hour after a deploy without them.
        $slug=$args['p']??''; $data=$this->cache->remember("award:prog:v2:{$slug}",1800,fn()=>$this->awards->getProgrammeBySlug($slug));
        if(!$data) throw new \Slim\Exception\HttpNotFoundException($req);
        $blurb=trim(strip_tags((string)($data['subtitle'] ?: $data['description'])));
        $meta=$blurb!==''?(mb_strlen($blurb)>160?rtrim(mb_substr($blurb,0,157)).'…':$blurb):($data['title'].' — an Africa GATES award programme recognising the continent\'s cultural best through community votes and expert judging.');
        // ── WHO BACKS THIS PROGRAMME ─────────────────────────────────────────
        //
        // Resolved OUTSIDE the cache above, deliberately. That entry is remembered for
        // thirty minutes and a sponsorship that ends — or one an operator has just
        // unpublished — must come off the page at once rather than at the top of the next
        // half hour. It is one indexed query against a table with a handful of rows.
        $sponsors = \AfricaGates\Services\ProgrammeSponsor::forCycle(
            (int) ($data['id'] ?? 0),
            isset($data['cycle']['id']) ? (int) $data['cycle']['id'] : null);

        // ── AND WHO RUNS IT, WHICH IS A DIFFERENT QUESTION ───────────────────
        //
        // A host runs the award; a sponsor paid to be named beside it. They are two
        // relationships and the sponsors table says so in its own docblock, so they are
        // two reads and two lines on the page. Outside the cache for the same reason the
        // sponsors are: a host's name is the kind of thing that is corrected the moment
        // somebody notices it is wrong.
        $host = \AfricaGates\Support\ProgrammeHost::forProgramme((int) ($data['id'] ?? 0));

        // The promo band, scoped to THIS programme. `forPlacement` refuses the `award`
        // placement without one rather than treating it as a wildcard — see the note
        // there for why a missing banner is the safe failure and a banner on somebody
        // else's award is not.
        $promos = \AfricaGates\Services\PromoService::forPlacement(
            'award', !empty($_SESSION['user_id']), 5, (int) ($data['id'] ?? 0));

        // ── THE VIEW, FROM THE URL ───────────────────────────────────────────
        //
        // The comp's three tabs are links to `?tab=`, rendered here, not panels a script
        // swaps: a view a person can only reach by clicking is a view nobody can link to,
        // and Back must undo it. A tab with nothing to show is not offered — a Terms tab
        // over an empty page is a promise the award has not made.
        $views = ['overview' => 'Overview', 'details' => 'Award details'];
        if (!empty($data['terms'])) $views['terms'] = 'Terms';
        $tab = (string) ($req->getQueryParams()['tab'] ?? 'overview');
        if (!isset($views[$tab])) $tab = 'overview';

        $cycleId = isset($data['cycle']['id']) ? (int) $data['cycle']['id'] : null;
        $weights = (new \AfricaGates\Services\RuleEngine())->weights((int) $data['id'], $cycleId);

        return $this->view->render($res,'pages/awards/programme.twig',[
            'page_title'=>$data['title'].' — Africa GATES','meta_description'=>$meta,
            'og_title'=>$data['title'].' — Africa GATES','gates_page'=>'awards',
            'programme'=>$data,'sponsors'=>$sponsors,'host'=>$host,'promos'=>$promos,
            'tiers'=>\AfricaGates\Services\ProgrammeSponsor::TIERS,
            'tab'=>$tab,'views'=>$views,
            // Outside the cache like the sponsors: a challenge filling up must show on
            // the next view, not half an hour later.
            'challenge_strip'=>\AfricaGates\Services\ChallengeService::stripFor(
                (int) $data['id'], (int) ($_SESSION['user_id'] ?? 0)),
            'timeline'=>$data['cycle'] ? \AfricaGates\Services\AwardOverview::timeline($data['cycle'], $data['phase']) : [],
            'action'=>\AfricaGates\Services\AwardOverview::action((string) $data['slug'], $data['phase']),
            'facts'=>\AfricaGates\Services\AwardOverview::facts($host, $data['first_year'] ?? null, (int) ($data['editions'] ?? 0), $weights),
        ]);
    }
}
