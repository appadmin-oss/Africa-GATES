<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,AwardService};

class AwardsController {
    public function __construct(private readonly Twig $view,private readonly CacheService $cache,private readonly AwardService $awards){}
    public function index(Request $req,Response $res):Response {
        return $this->view->render($res,'pages/awards/index.twig',['page_title'=>'Awards — Africa GATES','meta_description'=>'Explore every Africa GATES award programme — open cycles, categories and how community votes and expert judges crown the continent\'s cultural best.','gates_page'=>'awards','has_hero'=>false,'current_section'=>'projects','awards_data'=>$this->cache->remember('awards:index',1800,fn()=>$this->awards->getActiveProgrammesWithStatus())]);
    }
    public function programme(Request $req,Response $res,array $args):Response {
        $slug=$args['p']??''; $data=$this->cache->remember("award:prog:{$slug}",1800,fn()=>$this->awards->getProgrammeBySlug($slug));
        if(!$data) throw new \Slim\Exception\HttpNotFoundException($req);
        $blurb=trim(strip_tags((string)($data['subtitle'] ?: $data['description'])));
        $meta=$blurb!==''?(mb_strlen($blurb)>160?rtrim(mb_substr($blurb,0,157)).'…':$blurb):($data['title'].' — an Africa GATES award programme recognising the continent\'s cultural best through community votes and expert judging.');
        return $this->view->render($res,'pages/awards/programme.twig',['page_title'=>$data['title'].' — Africa GATES','meta_description'=>$meta,'og_title'=>$data['title'].' — Africa GATES','gates_page'=>'awards','has_hero'=>false,'current_section'=>'projects','programme'=>$data]);
    }
}
