<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,AwardService,RateLimitService,GoogleSheetsService,CommunityService,OtpService,Notifier};

class NominationController {
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly AwardService $awards,
        private readonly RateLimitService $rateLimit,
        private readonly ?GoogleSheetsService $sheets = null,
        private readonly ?CommunityService $community = null,
        private readonly ?OtpService $mailer = null
    ){}
    public function form(Request $req,Response $res):Response {
        $progs=$this->cache->remember('awards:active',1800,fn()=>$this->awards->getActiveProgrammesWithStatus());
        $open=array_values(array_filter($progs,fn($p)=>in_array($p['cycle_status'],['nominations'])));
        return $this->view->render($res,'pages/nominate.twig',['page_title'=>'Nominate — Africa GATES','meta_description'=>'Nominate exceptional African talent for the Africa GATES awards. Put forward the creatives, businesses and changemakers who deserve continental recognition.','gates_page'=>'nominate','has_hero'=>false,'current_section'=>'projects','programmes'=>$open,'all_programmes'=>$progs]);
    }
    public function submit(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$req->getServerParams()['REMOTE_ADDR']??''; $fp=hash('sha256',$ip.strtolower(trim($b['nominator_email']??'')));
        if(!$this->rateLimit->check($fp,'nominate',5,86400)) return $res->withHeader('Location','/nominate/success?error=ratelimit')->withStatus(302);
        // Real programme data for any error re-render, so the form never falls back to
        // stale hardcoded categories (which could misfile a nomination).
        $progs=$this->cache->remember('awards:active',1800,fn()=>$this->awards->getActiveProgrammesWithStatus());
        $open=array_values(array_filter($progs,fn($p)=>in_array($p['cycle_status'],['nominations'])));
        $rerender=fn(string $msg)=>$this->view->render($res,'pages/nominate.twig',['error'=>$msg,'gates_page'=>'nominate','has_hero'=>false,'current_section'=>'projects','programmes'=>$open,'all_programmes'=>$progs])->withStatus(422);
        foreach(['programme_id','nominee_name','country_code','reason','nominator_name','nominator_email','nominator_phone','nominator_country','nominator_state','nominator_lga'] as $f) if(empty(trim($b[$f]??''))) return $rerender("Field '$f' is required.");
        try{ $this->awards->submitNomination($b,$ip); }catch(\RuntimeException $e){ return $rerender($e->getMessage()); }
        // Notify operators + acknowledge the nominator with branded HTML email.
        $nomName  = trim((string)$b['nominee_name']);
        $byName   = trim((string)$b['nominator_name']);
        $byEmail  = strtolower(trim((string)$b['nominator_email']));
        $progName = trim((string)($b['programme_title'] ?? ('Programme #' . (int)$b['programme_id'])));

        Notifier::adminAlert($this->mailer, 'New nomination',
            "Nominee:   $nomName\nCountry:   " . strtoupper((string)$b['country_code'])
            . "\nProgramme: $progName\nState/LGA: " . trim((string)($b['nominee_state'] ?? ''))
            . " / " . trim((string)($b['nominee_lga'] ?? ''))
            . "\nBy:        $byName <$byEmail> · " . trim((string)($b['nominator_phone'] ?? ''))
            . " · " . trim((string)($b['nominator_location'] ?? ''))
            . "\n\nReason:\n" . trim((string)($b['reason'] ?? '')));

        if ($this->mailer) {
            $this->mailer->sendNominationConfirmation($byEmail, $byName, $nomName, $progName);
        }
        return $res->withHeader('Location', '/nominate/success')->withStatus(302);
    }
}
