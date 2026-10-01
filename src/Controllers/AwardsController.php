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
        $slug=$args['p']??''; $data=$this->cache->remember("award:prog:{$slug}",1800,fn()=>$this->awards->getProgrammeBySlug($slug));
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

        return $this->view->render($res,'pages/awards/programme.twig',['page_title'=>$data['title'].' — Africa GATES','meta_description'=>$meta,'og_title'=>$data['title'].' — Africa GATES','gates_page'=>'awards','programme'=>$data,'sponsors'=>$sponsors,'host'=>$host,'promos'=>$promos,'tiers'=>\AfricaGates\Services\ProgrammeSponsor::TIERS]);
    }
}
