<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,ProfileService};

class LeaderboardController {
    public function __construct(private readonly Twig $view,private readonly CacheService $cache,private readonly ProfileService $profiles){}
    public function index(Request $req,Response $res):Response {
        $p=$req->getQueryParams(); $cat=trim($p['category']??''); $ctr=strtoupper(trim($p['country']??''));
        $entries=$this->cache->remember("lb:50:{$cat}:{$ctr}",3600,fn()=>$this->profiles->getLeaderboard(50,$cat,$ctr));
        return $this->view->render($res,'pages/leaderboard.twig',['page_title'=>'CPI Leaderboard — Africa GATES','meta_description'=>'The live Africa GATES Cultural Power Index leaderboard — see who ranks highest across the continent, filtered by category and country in real time.','gates_page'=>'leaderboard','has_hero'=>false,'current_section'=>'projects','entries'=>$entries,'active_category'=>$cat,'active_country'=>$ctr]);
    }
}
