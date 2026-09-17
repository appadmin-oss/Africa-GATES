<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,LegacyService};

class LegacyController {
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly LegacyService $legacy
    ){}
    public function index(Request $req,Response $res):Response {
        return $this->view->render($res,'pages/legacy/index.twig',['page_title'=>'Legacy Vault — Africa GATES','meta_description'=>'The Africa GATES Legacy Vault — a permanent archive of past award editions, winners and milestone cycles preserved in the continental cultural record.','gates_page'=>'legacy','events'=>$this->cache->remember('legacy:all',7200,fn()=>$this->legacy->getAllPublished())]);
    }
    public function event(Request $req,Response $res,array $args):Response {
        $slug=$args['slug']??''; $event=$this->cache->remember("legacy:ev:{$slug}",7200,fn()=>$this->legacy->getBySlug($slug));
        if(!$event) throw new \Slim\Exception\HttpNotFoundException($req);
        // NO COMMENTS OR CHEERS ARE FETCHED HERE. Two uncached queries used to run on
        // every view of every legacy event — the event row beside them IS cached — and
        // `legacy/event.twig` rendered neither: it has no comment list and no cheer
        // button, and never has. `CommunityService` still accepts `legacy` as a target
        // type, so if this page ever grows the surface, read them back then.
        $blurb=trim(strip_tags((string)($event['excerpt'] ?? '') ?: (string)($event['tagline'] ?? '')));
        $meta=$blurb!==''?(mb_strlen($blurb)>160?rtrim(mb_substr($blurb,0,157)).'…':$blurb):($event['title'].' — a milestone edition preserved in the Africa GATES Legacy Vault, with winners and highlights from the continental cultural record.');
        $meta=mb_strlen($meta)>160?rtrim(mb_substr($meta,0,157)).'…':$meta;
        return $this->view->render($res,'pages/legacy/event.twig',['page_title'=>$event['title'].' — Legacy Vault | Africa GATES','meta_description'=>$meta,'og_title'=>$event['title'].' — Africa GATES Legacy Vault','gates_page'=>'legacy','event'=>$event]+array_filter(['og_image'=>\AfricaGates\Support\Assets::absoluteOg($event['cover_path']??null),'og_image_alt'=>$event['title'].' — Africa GATES Legacy Vault'],fn($v)=>$v!==null));
    }
}
