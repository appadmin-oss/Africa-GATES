<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,ProfileService,RateLimitService,GoogleSheetsService,CommunityService,OtpService,Notifier};

class RegistryController {
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ProfileService $profiles,
        private readonly RateLimitService $rateLimit,
        private readonly ?GoogleSheetsService $sheets = null,
        private readonly ?CommunityService $community = null,
        private readonly ?OtpService $mailer = null
    ){}
    public function index(Request $req,Response $res):Response {
        $page=max(1,(int)($req->getQueryParams()['page']??1));
        $data=$this->cache->remember("reg:p{$page}",300,fn()=>$this->profiles->paginatedList($page,18,'','','','cpi_desc','',''));
        // The template derives rows/has_profiles/catset from `profiles` — pass the
        // raw array (not a JSON string) or the grid renders the empty state always.
        return $this->view->render($res,'pages/registry/index.twig',['page_title'=>'Registry — Africa GATES','meta_description'=>'Browse the Africa GATES registry — the verified record of African creatives, businesses and organisations, ranked by live Cultural Power Index scores.','gates_page'=>'registry','has_hero'=>false,'current_section'=>'projects','profiles'=>$data['profiles'],'total_profiles'=>$data['total']]);
    }
    public function profile(Request $req,Response $res,array $args):Response {
        $slug=$args['slug']??''; $p=$this->cache->remember("profile:{$slug}",1800,fn()=>$this->profiles->getBySlug($slug));
        if(!$p) throw new \Slim\Exception\HttpNotFoundException($req);
        $comments  = $this->community ? $this->community->listComments('profile', (int)$p['id']) : [];
        $cheerCount = $this->community ? $this->community->cheerCount('profile', (int)$p['id']) : 0;
        $bio=trim(strip_tags((string)($p['bio'] ?? '')));
        $meta=$p['display_name'].($p['category']?' · '.$p['category']:'').' on the Africa GATES registry'.($bio!==''?'. '.$bio:'. Track their live Cultural Power Index score, verification tier and standing.');
        $meta=mb_strlen($meta)>160?rtrim(mb_substr($meta,0,157)).'…':$meta;
        // Real "also in this category" rail — top profiles sharing the category, self excluded.
        $similar=[];
        if(!empty($p['category'])){
            $similar=array_slice(array_values(array_filter(
                $this->profiles->getLeaderboard(4,(string)$p['category']),
                fn($s)=>($s['slug']??'')!==$p['slug']
            )),0,3);
        }
        return $this->view->render($res,'pages/registry/profile.twig',['page_title'=>$p['display_name'].' — Africa GATES','meta_description'=>$meta,'og_title'=>$p['display_name'].' — Africa GATES','gates_page'=>'registry','has_hero'=>false,'current_section'=>'projects','profile'=>$p,'comments'=>$comments,'cheer_count'=>$cheerCount,'similar'=>$similar,
            // The trail this page already shows. The category link goes to /registry
            // because there is no per-category page yet — same URL as the visible
            // crumb, so the markup and the page agree.
            'breadcrumbs'=>array_values(array_filter([
                ['label'=>'Registry','url'=>'/registry'],
                !empty($p['category']) ? ['label'=>(string)$p['category'],'url'=>'/registry'] : null,
                ['label'=>(string)$p['display_name'],'url'=>null],
            ])),
            // Person JSON-LD. People search NAMES, and a name is the one query where a
            // small site outranks a large one — because the large one has no page for it.
            'schema'=>\AfricaGates\Support\Schema::person(
                $p,
                \AfricaGates\Support\SiteUrl::base($req),
                \AfricaGates\Support\SiteUrl::base($req).'/registry/'.rawurlencode((string)($p['slug']??'')),
                (string)(\AfricaGates\Support\Assets::absoluteOg($p['avatar_path']??null) ?? '')
            )]+array_filter(['og_image'=>\AfricaGates\Support\Assets::absoluteOg($p['avatar_path']??null),'og_image_alt'=>$p['display_name'].' — Africa GATES profile'],fn($v)=>$v!==null));
    }
    public function registerForm(Request $req,Response $res):Response {
        return $this->view->render($res,'pages/registry/register.twig',['page_title'=>'Register — Africa GATES','meta_description'=>'Join the Africa GATES registry. Create your verified profile, start building a Cultural Power Index score and become eligible for every awards cycle.','gates_page'=>'register','has_hero'=>false,'current_section'=>'projects']);
    }
    public function registerSubmit(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$req->getServerParams()['REMOTE_ADDR']??''; $fp=hash('sha256',$ip);
        if(!$this->rateLimit->check($fp,'register',3,3600)) return $this->view->render($res,'pages/registry/register.twig',['error'=>'Too many submissions.','gates_page'=>'register','has_hero'=>false,'current_section'=>'projects','old'=>$b])->withStatus(429);
        foreach(['display_name','email','category','profile_type','country_code'] as $f) if(empty(trim((string)($b[$f]??'')))) return $this->view->render($res,'pages/registry/register.twig',['error'=>'Please fill all required fields.','gates_page'=>'register','has_hero'=>false,'current_section'=>'projects','old'=>$b])->withStatus(422);
        if(!filter_var($b['email'],FILTER_VALIDATE_EMAIL)) return $this->view->render($res,'pages/registry/register.twig',['error'=>'Invalid email address.','gates_page'=>'register','has_hero'=>false,'current_section'=>'projects','old'=>$b])->withStatus(422);
        try{ $newId = $this->profiles->register($b); }catch(\Exception $e){ $msg=str_contains($e->getMessage(),'Duplicate')?'Email already registered.':'Registration failed.'; return $this->view->render($res,'pages/registry/register.twig',['error'=>$msg,'gates_page'=>'register','has_hero'=>false,'current_section'=>'projects','old'=>$b])->withStatus(422); }
        $this->sheets?->pushRegistration($b);
        $this->community?->recordActivity('register', trim((string)$b['display_name']), 'profile', (int)$newId, trim((string)$b['display_name']), ['country' => strtoupper((string)$b['country_code'])]);
        $dn=trim((string)$b['display_name']); $em=strtolower(trim((string)$b['email']));
        Notifier::adminAlert($this->mailer, 'New profile registration',
            "Name: $dn\nType: ".trim((string)($b['profile_type']??''))."\nCategory: ".trim((string)($b['category']??''))."\nCountry: ".strtoupper((string)$b['country_code'])."\nEmail: $em");
        Notifier::confirm($this->mailer, $em, 'Your profile is registered',
            "Hi $dn,\n\nYour Africa GATES profile has been registered. Once our team verifies it, your CPI score begins ticking and you become eligible for every cycle.\n\n— Africa GATES");
        return $res->withHeader('Location','/register/success')->withStatus(302);
    }
}
