<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CacheService,ProfileService,AwardService,VoteService,OtpService,RateLimitService,GoogleSheetsService,CommunityService,TurnstileService,FraudService,EventService,MilestoneService};

class ApiController {
    public function __construct(
        private readonly CacheService $cache, private readonly ProfileService $profiles,
        private readonly AwardService $awards, private readonly VoteService $votes,
        private readonly OtpService $otp, private readonly RateLimitService $rateLimit,
        private readonly ?GoogleSheetsService $sheets = null,
        private readonly ?CommunityService $community = null,
        private readonly ?TurnstileService $turnstile = null,
        private readonly ?FraudService $fraud = null,
        private readonly ?EventService $events = null,
        private readonly ?MilestoneService $milestones = null,
    ){}
    private function json(Response $r,array $d,int $s=200):Response{ $r->getBody()->write(json_encode($d,JSON_UNESCAPED_UNICODE)); return $r->withHeader('Content-Type','application/json')->withStatus($s); }
    private function ok(Response $r,array $d=[]):Response{ return $this->json($r,['success'=>true]+$d); }
    private function err(Response $r,string $m,string $c='ERROR',int $s=400):Response{ return $this->json($r,['success'=>false,'message'=>$m,'code'=>$c],$s); }
    /**
     * Client IP for rate-limiting. X-Forwarded-For is client-spoofable, so only
     * trust it when TRUST_PROXY is enabled (i.e. the app sits behind a known
     * reverse proxy that sets it). Otherwise use REMOTE_ADDR.
     */
    private function ip(Request $r):string{
        $s=$r->getServerParams();
        $trust=in_array(strtolower((string)($_ENV['TRUST_PROXY']??'')),['1','true','yes'],true);
        if($trust && !empty($s['HTTP_X_FORWARDED_FOR'])){
            return trim(explode(',',(string)$s['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return (string)($s['REMOTE_ADDR']??'');
    }

    public function registry(Request $req,Response $res):Response {
        $p=$req->getQueryParams(); $page=max(1,(int)($p['page']??1)); $pp=min(36,max(6,(int)($p['per_page']??18)));
        $cat=trim($p['category']??''); $tier=trim($p['tier']??''); $search=trim($p['search']??'');
        $sort=$p['sort']??'cpi_desc'; $region=trim($p['region']??''); $country=strtoupper(trim($p['country']??''));
        $key="api:reg:{$page}:{$pp}:{$cat}:{$tier}:{$sort}:{$region}:{$country}";
        $data=$search?$this->profiles->paginatedList($page,$pp,$cat,$tier,$search,$sort,$region,$country):$this->cache->remember($key,300,fn()=>$this->profiles->paginatedList($page,$pp,$cat,$tier,$search,$sort,$region,$country),['registry']);
        return $this->ok($res,$data+['page'=>$page,'per_page'=>$pp]);
    }
    public function profileBySlug(Request $req,Response $res,array $args):Response {
        $slug=$args['slug']??''; $p=$this->cache->remember("api:profile:{$slug}",1800,fn()=>$this->profiles->getBySlug($slug));
        return $p?$this->ok($res,['profile'=>$p]):$this->err($res,'Profile not found.','NOT_FOUND',404);
    }
    public function nominees(Request $req,Response $res):Response {
        $p=$req->getQueryParams(); $pgId=(int)($p['award']??0); $catId=(int)($p['category']??0); $yr=(int)($p['year']??date('Y'));
        if(!$pgId) return $this->err($res,'award param required.');
        return $this->ok($res,['nominees'=>$this->cache->remember("api:nom:{$pgId}:{$catId}:{$yr}",300,fn()=>$this->awards->getNominees($pgId,$catId,$yr))]);
    }
    public function awardsIndex(Request $req,Response $res):Response {
        return $this->ok($res,['awards'=>$this->cache->remember('api:awards',1800,fn()=>$this->awards->getActiveProgrammesWithStatus())]);
    }
    public function otpRequest(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $email=strtolower(trim($b['email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return $this->err($res,'Invalid email address.');
        $tsToken = $b['turnstile_token'] ?? $b['cf-turnstile-response'] ?? null;
        if($this->turnstile && !$this->turnstile->verify($tsToken,$this->ip($req)))
            return $this->err($res,'Bot verification failed. Please retry.','TURNSTILE_FAILED',403);
        $ipFp=hash('sha256',$this->ip($req));
        if(!$this->rateLimit->check($ipFp,'otp_ip',10,3600)) return $this->err($res,'Too many requests from this network. Try again later.','RATE_LIMITED',429);
        $fp=hash('sha256',$email);
        if(!$this->rateLimit->check($fp,'otp_request',3,600)){ $w=$this->rateLimit->retryAfter($fp,'otp_request',600); return $this->err($res,"Too many requests. Try again in {$w}s.",'RATE_LIMITED',429); }
        $nId=(int)($b['nominee_id']??0); $aId=(int)($b['award_id']??0);
        $r=$this->otp->generate($email,$nId,$aId,'vote');
        if($r['success']) {
            $this->events?->otpRequested($fp,$nId,$ipFp);
            $this->events?->funnelStep($b['session_id']??$fp,'otp_requested',$nId,$aId,null,$ipFp);
        }
        return $r['success']?$this->ok($res,['message'=>'Code sent.']):$this->err($res,$r['message']??'Failed to send code.');
    }

    public function castVote(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody();
        $email=strtolower(trim($b['email']??'')); $otp=trim($b['otp']??'');
        $nId=(int)($b['nominee_id']??0); $aId=(int)($b['award_id']??0);
        $ip=$this->ip($req); $ipHash=hash('sha256',$ip);
        $deviceHash=!empty($b['device_hash'])?substr($b['device_hash'],0,64):null;
        $idemKey=$req->getHeaderLine('Idempotency-Key') ?: (isset($b['idempotency_key'])?substr((string)$b['idempotency_key'],0,80):'');
        $sessionId=$b['session_id']??$ipHash;
        if(!$email||!$otp||!$nId) return $this->err($res,'email, otp and nominee_id required.');
        if(strlen($otp)!==6||!ctype_digit($otp)) return $this->err($res,'OTP must be 6 digits.','INVALID_OTP');
        if(!$this->rateLimit->check($ipHash,'vote_attempt',10,3600)) return $this->err($res,'Too many attempts.','RATE_LIMITED',429);

        // Pre-vote fraud scoring
        $emailHash=hash('sha256',$email);
        if($this->fraud) {
            // Score only against a real, APPROVED nominee — otherwise category_id
            // is 0 and the device/category signal silently mis-fires.
            $nom=DB::table('gates_nominees')->where('id',$nId)->where('status','approved')->first();
            if($nom) {
                $fs=$this->fraud->scoreVoteAttempt($emailHash,$ipHash,$deviceHash,$nId,(int)$nom->category_id);
                if($fs['decision']==='block') {
                    $this->events?->fraudFlagged($nId,$fs['score'],'block');
                    return $this->err($res,'This vote could not be recorded due to suspicious activity.','FRAUD_BLOCKED',403);
                }
            }
        }

        $r=$this->votes->castVote($email,$otp,$nId,$aId,$ip,$deviceHash,$idemKey?:null);
        if(!$r['success']) return $this->err($res,$r['message'],$r['code']);

        // Post-vote: record fraud score, events, milestones, cache invalidation
        if($this->fraud && isset($fs)) {
            $this->fraud->record($r['vote_id']??null,$emailHash,$ipHash,$deviceHash,$fs['score'],$fs['decision'],$fs['signals']);
        }
        $this->events?->voteCast($nId,$r['category_id']??0,$emailHash,$ipHash,$deviceHash);
        $this->events?->funnelStep($sessionId,'vote_cast',$nId,$aId,$deviceHash,$ipHash);

        $milestone=$this->milestones?->checkAndNotify($nId);
        $this->cache->forgetByTag('leaderboard');

        $nom=DB::table('gates_nominees')->where('id',$nId)->first();

        // Vote confirmation email — tells the voter their vote was recorded
        if ($this->otp && $nom && filter_var($b['email']??'', FILTER_VALIDATE_EMAIL)) {
            $nomName  = $nom->name ?? 'the nominee';
            $catTitle = DB::table('gates_award_categories')->where('id', $nom->category_id ?? 0)->value('title') ?? 'this category';
            $voteUrl  = ($_ENV['APP_URL'] ?? 'https://afg.afrovanguard.org.ng') . '/vote';
            // Escape admin/nomination-supplied names before they enter the email HTML.
            $nomNameHtml  = htmlspecialchars($nomName, ENT_QUOTES, 'UTF-8');
            $catTitleHtml = htmlspecialchars($catTitle, ENT_QUOTES, 'UTF-8');
            $html = <<<HTML
<p>Your vote has been counted. Here's your impact:</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 24px;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 10px 10px 0;padding:16px 20px">
  <tr><td style="font-size:15px;color:#166534;line-height:1.75">
    You voted for: <strong style="color:#14532d">{$nomNameHtml}</strong><br>
    Category: <strong>{$catTitleHtml}</strong><br>
    Platform: <strong>Africa GATES 2026</strong>
  </td></tr>
</table>
<p style="font-size:15px;color:#374151">Help <strong>{$nomNameHtml}</strong> reach more voters by sharing the vote page with your network. Community votes make up 45% of the final score.</p>
<p style="text-align:center;margin:24px 0">
  <a href="{$voteUrl}" style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">
    View the live shortlist →
  </a>
</p>
<p style="font-size:13px;color:#9ca3af">Didn't cast this vote? Your email was hashed before storage — contact integrity@afrovanguard.org.ng if something seems wrong.</p>
HTML;
            $this->otp->sendBranded(
                strtolower(trim($b['email'])),
                "✅ Your vote for {$nomName} is confirmed",
                $html,
                "Your vote for {$nomName} in {$catTitle} has been recorded.\n\nShare the vote page: {$voteUrl}\n\n— Africa GATES"
            );
        }

        // Off the hot path: the (slow, external) Google Sheets sync is enqueued and
        // run by the cron worker, so a sluggish Sheets endpoint never delays a vote.
        if (!empty($_ENV['GAS_URL'])) {
            (new \AfricaGates\Services\QueueService())->push('vote.sheets_push', ['award_id'=>$aId,'category_id'=>$nom->category_id??null,'nominee_id'=>$nId,'nominee_name'=>$nom->name??'','voter_email_hash'=>$emailHash,'country'=>$nom->country_code??'']);
        }
        $this->community?->recordActivity('vote','a community voter','nominee',$nId,$nom->name??'',['country'=>$nom->country_code??'']);

        return $this->ok($res,[
            'message'   => $r['message'],
            'new_count' => $r['new_count'],
            'new_rank'  => $r['new_rank']  ?? null,
            'days_left' => $r['days_left'] ?? 0,
            'milestone' => $milestone,
        ]);
    }

    public function trackFunnel(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody();
        $step=$b['step']??''; $sid=$b['session_id']??'';
        if(!$step||!$sid) return $this->err($res,'step and session_id required.');
        $ipHash=hash('sha256',$this->ip($req));
        $deviceHash=!empty($b['device_hash'])?substr($b['device_hash'],0,64):null;
        $this->events?->funnelStep($sid,$step,(int)($b['nominee_id']??0),(int)($b['award_id']??0),$deviceHash,$ipHash,$b['meta']??[]);
        return $this->ok($res);
    }

    public function saveDraft(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody();
        $key=trim($b['session_key']??'');
        $payload=$b['payload']??[];
        if(!$key||!$payload) return $this->err($res,'session_key and payload required.');
        try {
            DB::table('gates_nomination_drafts')->updateOrInsert(
                ['session_key'=>substr($key,0,64)],
                ['payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'updated_at'=>\Illuminate\Support\Carbon::now()->toDateTimeString()]
            );
        } catch(\Throwable) {}
        return $this->ok($res);
    }

    public function loadDraft(Request $req,Response $res):Response {
        $key=trim($req->getQueryParams()['session_key']??'');
        if(!$key) return $this->ok($res,['draft'=>null]);
        try {
            $row=DB::table('gates_nomination_drafts')->where('session_key',substr($key,0,64))->first();
            return $this->ok($res,['draft'=>$row?json_decode($row->payload,true):null]);
        } catch(\Throwable) {
            return $this->ok($res,['draft'=>null]);
        }
    }
    public function leaderboard(Request $req,Response $res):Response {
        $p=$req->getQueryParams(); $lim=min(100,max(5,(int)($p['limit']??20))); $cat=trim($p['category']??''); $ctr=strtoupper(trim($p['country']??''));
        $data=$this->cache->remember("api:lb:{$lim}:{$cat}:{$ctr}",3600,fn()=>$this->profiles->getLeaderboard($lim,$cat,$ctr),['leaderboard']);
        return $this->ok($res,['entries'=>$data,'total'=>count($data)]);
    }
    public function dashboard(Request $req,Response $res):Response {
        $data=$this->cache->remember('api:dashboard',14400,function(){
            $tp=DB::table('gates_profiles')->where('status','approved')->count();
            $tv=DB::table('gates_votes')->count();
            $ag=DB::table('gates_nominees')->whereIn('status',['winner','runner_up'])->count();
            $rd=DB::table('gates_profiles')->where('status','approved')->selectRaw('region,COUNT(*) as count')->groupBy('region')->orderByDesc('count')->get()->map(fn($r)=>['region'=>$r->region,'count'=>(int)$r->count])->toArray();
            $td=DB::table('gates_profiles')->where('status','approved')->whereNotIn('cpi_tier',['unranked'])->selectRaw('cpi_tier as tier,COUNT(*) as count')->groupBy('cpi_tier')->get()->map(fn($r)=>['tier'=>$r->tier,'count'=>(int)$r->count])->toArray();
            $cats=DB::table('gates_profiles')->where('status','approved')->selectRaw('category,COUNT(*) as count')->groupBy('category')->orderByDesc('count')->limit(10)->get()->map(fn($r)=>['category'=>$r->category,'count'=>(int)$r->count])->toArray();
            $cs=DB::table('gates_profiles')->where('status','approved')->selectRaw('country_code,COUNT(*) as profiles,MAX(cpi_score) as top_cpi')->groupBy('country_code')->get()->mapWithKeys(fn($r)=>[$r->country_code=>['profiles'=>(int)$r->profiles,'top_cpi'=>(int)$r->top_cpi]])->toArray();
            return['total_profiles'=>$tp,'total_votes'=>$tv,'awards_given'=>$ag,'region_dist'=>$rd,'tier_dist'=>$td,'categories'=>$cats,'country_stats'=>$cs];
        },['leaderboard']);
        return $this->ok($res,$data);
    }
    public function mapPins(Request $req,Response $res):Response {
        return $this->ok($res,['pins'=>$this->cache->remember('api:map_pins',7200,fn()=>$this->profiles->getMapPins(),['registry'])]);
    }
    public function legacy(Request $req,Response $res):Response {
        $svc=new \AfricaGates\Services\LegacyService();
        return $this->ok($res,['events'=>$this->cache->remember('api:legacy',7200,fn()=>$svc->getAllPublished())]);
    }
    public function opportunities(Request $req,Response $res):Response {
        $svc=new \AfricaGates\Services\OpportunityService();
        return $this->ok($res,['opportunities'=>$this->cache->remember('api:opps',3600,fn()=>$svc->getActiveOpportunities())]);
    }
    public function submitNomination(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$this->ip($req); $fp=hash('sha256',$ip.strtolower(trim($b['nominator_email']??'')));
        if(!$this->rateLimit->check($fp,'nominate',5,86400)) return $this->err($res,'Daily nomination limit reached.','RATE_LIMITED',429);
        foreach(['programme_id','nominee_name','country_code','reason','nominator_name','nominator_email'] as $f) if(empty(trim($b[$f]??''))) return $this->err($res,"Field '$f' is required.");
        if(!filter_var($b['nominator_email'],FILTER_VALIDATE_EMAIL)) return $this->err($res,'Invalid nominator email.');
        try{ $this->awards->submitNomination($b,$ip); }catch(\RuntimeException $e){ return $this->err($res,$e->getMessage()); }
        return $this->ok($res,['message'=>'Nomination submitted.']);
    }
    public function register(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$this->ip($req); $fp=hash('sha256',$ip);
        if(!$this->rateLimit->check($fp,'register',3,3600)) return $this->err($res,'Too many registrations.','RATE_LIMITED',429);
        foreach(['display_name','email','category','profile_type','country_code'] as $f) if(empty(trim($b[$f]??''))) return $this->err($res,"Field '$f' is required.");
        if(!filter_var($b['email'],FILTER_VALIDATE_EMAIL)) return $this->err($res,'Invalid email.');
        try{ $id=$this->profiles->register($b); }catch(\Exception $e){ return $this->err($res,str_contains($e->getMessage(),'Duplicate')?'Email already registered.':'Registration failed.'); }
        $this->cache->forgetByTag('registry');
        return $this->ok($res,['message'=>'Registration successful.','id'=>$id]);
    }

    /**
     * Minimal newsletter subscribe — idempotent on email_hash (UNIQUE),
     * IP-throttled, same-origin enforced upstream by CsrfMiddleware. We
     * deliberately return success on duplicate to avoid revealing membership.
     */
    public function newsletterSubscribe(Request $req, Response $res): Response {
        $b = (array)$req->getParsedBody();
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $ip = $this->ip($req);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->err($res, 'Invalid email.');
        }
        if (!$this->rateLimit->check(hash('sha256', $ip), 'newsletter_subscribe', 5, 3600)) {
            return $this->err($res, 'Too many requests. Try again later.', 'RATE_LIMITED', 429);
        }
        $hash = hash('sha256', $email);
        try {
            DB::table('gates_newsletter')->insert([
                'email_hash' => $hash, 'email' => $email,
                'ip_hash' => hash('sha256', $ip),
                'source' => substr((string)($b['source'] ?? 'homepage'), 0, 50),
                'subscribed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // UNIQUE violation on re-subscribe is fine — idempotent + non-leaky.
        }
        return $this->ok($res, ['message' => 'Subscribed.']);
    }
}
