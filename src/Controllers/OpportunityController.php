<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,OpportunityService};

class OpportunityController {
    public function __construct(private readonly Twig $view,private readonly CacheService $cache,private readonly OpportunityService $opportunities){}
    public function index(Request $req,Response $res):Response {
        return $this->view->render($res,'pages/opportunities.twig',['page_title'=>'Opportunities — Africa GATES','meta_description'=>'Grants, fellowships, mentorships and open calls for African talent — curated opportunities surfaced to the Africa GATES community and registry.','gates_page'=>'opportunities','has_hero'=>false,'current_section'=>'projects','opportunities'=>$this->cache->remember('opps:active',3600,fn()=>$this->opportunities->getActiveOpportunities())]);
    }
}
