<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CacheService,ProfileService,AwardService,LegacyService,OpportunityService,StatsService};

class HomeController {
    public function __construct(private readonly Twig $view,private readonly CacheService $cache,private readonly ProfileService $profiles,private readonly AwardService $awards,private readonly LegacyService $legacy,private readonly OpportunityService $opportunities,private readonly ?StatsService $stats = null){}
    public function index(Request $req,Response $res):Response {
        // ── "Votes cast" is SUM(weight), NOT COUNT(*) ────────────────────────
        //
        // A row in gates_votes is not a vote, it is a vote EVENT — and a paid or bonus
        // pack writes ONE row carrying its whole quantity in `weight` (PaidVoteService
        // ::mint, BonusVoteService). Counting rows reported a 25-vote pack as 1, so the
        // headline figure on the front page was a count of TRANSACTIONS wearing the
        // label "Votes cast", and it disagreed with every nominee card on the site —
        // those are built from the same weights.
        //
        // COALESCE via ?? 0: SUM over no rows is NULL, and a new site must show 0.
        $stats=$this->cache->remember('home:stats',3600,fn()=>['total_profiles'=>DB::table('gates_profiles')->where('status','approved')->count(),'total_votes'=>(int)(DB::table('gates_votes')->sum('weight') ?? 0),'events_count'=>DB::table('gates_legacy_events')->where('is_published',1)->count(),'awards_given'=>\AfricaGates\Services\MergeService::notMerged(DB::table('gates_nominees')->whereIn('status',['winner','runner_up']))->count()],['leaderboard','registry']);
        return $this->view->render($res,'pages/home.twig',['page_title'=>'Africa GATES — Continental Cultural Recognition | Afrovanguard','meta_description'=>'Africa GATES is a continental cultural recognition engine — recognising African excellence, live in Nigeria and building across the continent.','gates_page'=>'home','has_hero'=>true,'current_section'=>'projects','dash_stats'=>$stats,'site_stats'=>$this->stats?->summary() ?? $stats,'awards_data'=>$this->cache->remember('home:awards',1800,fn()=>$this->awards->getActiveProgrammesWithStatus()),'leaderboard'=>$this->cache->remember('home:lb',3600,fn()=>$this->profiles->getLeaderboard(8),['leaderboard']),'ticker_profiles'=>$this->cache->remember('home:ticker',3600,fn()=>$this->profiles->getTopCpiProfiles(12),['leaderboard']),'spotlight_profiles'=>$this->cache->remember('home:spotlight',3600,fn()=>$this->profiles->getFeaturedProfiles(5),['leaderboard']),'legacy_events'=>$this->cache->remember('home:legacy',7200,fn()=>$this->legacy->getRecentEvents(3)),'active_opps'=>$this->cache->remember('home:opps',3600,fn()=>$this->opportunities->getActiveOpportunities(5)),'site_events'=>$this->cache->remember('home:site_events',900,fn()=>DB::table('gates_site_events')->where('status','published')->where('event_date','>=',date('Y-m-d H:i:s'))->orderBy('event_date')->limit(3)->get()->map(fn($r)=>(array)$r)->all()),'latest_posts'=>$this->cache->remember('home:posts',900,fn()=>DB::table('gates_posts')->where('status','published')->orderByDesc('published_at')->limit(3)->get()->map(fn($r)=>(array)$r)->all())]);
    }
}
