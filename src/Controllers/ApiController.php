<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CacheService,ProfileService,AwardService,VoteService,OtpService,RateLimitService,GoogleSheetsService,CommunityService,TurnstileService,FraudService,EventService,MilestoneService,LegacyService,OpportunityService};

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
        private readonly ?LegacyService $legacyService = null,
        private readonly ?OpportunityService $oppService = null,
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
        $trust=Env::bool('TRUST_PROXY', false);
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
        // Paid-voting mode with free voting disabled: the OTP flow is closed at
        // the boundary — the audited vote path itself stays untouched.
        if(\AfricaGates\Services\PaidVoteService::freeVotingDisabled())
            return $this->err($res,'Voting on this platform is by paid votes — use the "Buy votes" option on the nominee page.','PAID_VOTING_ONLY',403);
        $b=(array)$req->getParsedBody(); $email=strtolower(trim($b['email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return $this->err($res,'Invalid email address.');
        // The bot check reports WHY it failed, and the voter is told that instead of
        // "Bot verification failed. Please retry." — advice that was actively wrong
        // for the commonest cause (a spent token, where the identical retry fails the
        // same way). The client resets the widget after every request so that
        // "try again" is now a real instruction. See TurnstileService::check().
        $tsToken = $b['turnstile_token'] ?? $b['cf-turnstile-response'] ?? null;
        if($this->turnstile) {
            $ts = $this->turnstile->check($tsToken,$this->ip($req));
            if(!$ts['ok']) return $this->err($res,$ts['message'],'TURNSTILE_'.$ts['code'],403);
        }
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
        if(\AfricaGates\Services\PaidVoteService::freeVotingDisabled())
            return $this->err($res,'Voting on this platform is by paid votes — use the "Buy votes" option on the nominee page.','PAID_VOTING_ONLY',403);
        $b=(array)$req->getParsedBody();
        $email=strtolower(trim($b['email']??'')); $otp=trim($b['otp']??'');
        $nId=(int)($b['nominee_id']??0); $aId=(int)($b['award_id']??0);
        $ip=$this->ip($req); $ipHash=hash('sha256',$ip);
        $deviceHash=!empty($b['device_hash'])?substr($b['device_hash'],0,64):null;
        $idemKey=$req->getHeaderLine('Idempotency-Key') ?: (isset($b['idempotency_key'])?substr((string)$b['idempotency_key'],0,80):'');
        $sessionId=$b['session_id']??$ipHash;
        if(!$email||!$otp||!$nId) return $this->err($res,'email, otp and nominee_id required.');
        if(strlen($otp)!==6||!ctype_digit($otp)) return $this->err($res,'OTP must be 6 digits.','INVALID_OTP');
        // Voting requires the voter's full name + phone (stored with the vote for accountability).
        $name=trim($b['name']??($b['full_name']??'')); $phone=trim($b['phone']??'');
        if($name===''||!preg_match('/\S+\s+\S+/u',$name)) return $this->err($res,'Please enter your full name (first and last).','INVALID_NAME');
        if(strlen((string)preg_replace('/\D+/','',$phone))<7) return $this->err($res,'Please enter a valid phone number.','INVALID_PHONE');
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

        // No show_name here on purpose: the free path REQUIRES a name (see the checks
        // above), so giving one is not a choice to be published. Only the paid ballot,
        // where the field is optional, treats filling it in as consent. See
        // \AfricaGates\Services\SupportersService.
        $r=$this->votes->castVote($email,$otp,$nId,$aId,$ip,$deviceHash,$idemKey?:null,$name,$phone);
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

        // ── THE VOTER'S MESSAGE, IF THEY WROTE ONE ───────────────────────────
        //
        // Optional, and stored AFTER the vote has already succeeded — in its own
        // statement, outside the audited vote path, with its own failure mode. A
        // sentence about a vote is not worth risking the vote for, so nothing here
        // can change the outcome above: `$msg` only ever adds fields to the reply.
        //
        // `message_show_name` is asked EXPLICITLY on this path rather than inferred
        // from the name being present, which is the opposite of the paid ballot's rule
        // and for the reason the comment above already gives: here the name is
        // REQUIRED for accountability, so supplying it says nothing about wanting it
        // published. On the paid form the name is optional, so typing one IS the
        // choice. Same principle — consent has to be a decision the voter could have
        // made differently — applied to two forms that differ.
        $msg=null;
        if(trim((string)($b['message']??''))!=='') {
            $r2=\AfricaGates\Services\VoteMessageService::submit([
                'nominee_id'  => $nId,
                'category_id' => (int)($r['category_id'] ?? 0),
                'vote_id'     => (int)($r['vote_id'] ?? 0),
                'email'       => $email,
                'body'        => (string)$b['message'],
                'name'        => $name,
                'show_name'   => !empty($b['message_show_name']),
                'source'      => 'free',
            ]);
            $msg=[
                'message_status' => $r2['ok'] ? (string)($r2['status'] ?? 'pending') : 'failed',
                'message_note'   => $r2['ok']
                    ? \AfricaGates\Controllers\VoteMessageController::statusLine((string)($r2['status'] ?? ''))
                    : (string)($r2['message'] ?? 'Your vote counted, but the message could not be saved.'),
                'message_url'    => ($r2['ok'] && ($r2['status'] ?? '')==='approved' && !empty($r2['token']))
                    ? \AfricaGates\Support\SiteUrl::base($req).'/m/'.rawurlencode((string)$r2['token'])
                    : '',
            ];
        }

        // Post-commit vote webhook — additive to the audited vote path (dispatch
        // never throws). Counts + ids only; the voter's identity stays hashed.
        \AfricaGates\Services\WebhookService::dispatch('vote.cast', [
            'vote_id'     => (int)($r['vote_id'] ?? 0),
            'nominee_id'  => $nId,
            'nominee'     => (string)($nom->name ?? ''),
            'category_id' => (int)($r['category_id'] ?? 0),
            'new_count'   => (int)($r['new_count'] ?? 0),
            'new_rank'    => (int)($r['new_rank'] ?? 0),
        ]);

        // Vote confirmation email — tells the voter their vote was recorded
        if ($this->otp && $nom && filter_var($b['email']??'', FILTER_VALIDATE_EMAIL)) {
            $nomName  = $nom->name ?? 'the nominee';
            $catTitle = DB::table('gates_award_categories')->where('id', $nom->category_id ?? 0)->value('title') ?? 'this category';
            $voteUrl  = \AfricaGates\Support\SiteUrl::base($req) . '/vote';
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
            // 'Votes' is not decoration — it is the category gates_mail_log records, and
            // without it "are voters receiving anything?" could not be answered from the
            // delivery audit even on a deployment where mail was working. app:doctor and
            // the admin email-health card both read that table.
            $this->otp->sendBranded(
                strtolower(trim($b['email'])),
                "✅ Your vote for {$nomName} is confirmed",
                $html,
                "Your vote for {$nomName} in {$catTitle} has been recorded.\n\nShare the vote page: {$voteUrl}\n\n— Africa GATES",
                'Votes'
            );
        }

        // Off the hot path: the (slow, external) Google Sheets sync is enqueued and
        // run by the cron worker, so a sluggish Sheets endpoint never delays a vote.
        if (Env::has('GAS_URL')) {
            (new \AfricaGates\Services\QueueService())->push('vote.sheets_push', ['award_id'=>$aId,'category_id'=>$nom->category_id??null,'nominee_id'=>$nId,'nominee_name'=>$nom->name??'','voter_email_hash'=>$emailHash,'country'=>$nom->country_code??'']);
        }
        $this->community?->recordActivity('vote','a community voter','nominee',$nId,$nom->name??'',['country'=>$nom->country_code??'']);

        return $this->ok($res,[
            'message'   => $r['message'],
            'new_count' => $r['new_count'],
            'new_rank'  => $r['new_rank']  ?? null,
            'days_left' => $r['days_left'] ?? 0,
            'milestone' => $milestone,
            // `message_status` / `message_note` / `message_url` ride along only when the
            // voter actually wrote something — see the block above.
        ] + ($msg ?? []));
    }

    public function trackFunnel(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody();
        $step=$b['step']??''; $sid=$b['session_id']??'';
        if(!$step||!$sid) return $this->err($res,'step and session_id required.');
        $ipHash=hash('sha256',$this->ip($req));
        // Cap funnel ingestion per IP so the analytics table can't be flooded.
        // 500/hr is far above any real user's event rate; over-limit is dropped
        // silently (the client ignores the response anyway).
        if(!$this->rateLimit->check($ipHash,'funnel',500,3600)) return $this->ok($res);
        $deviceHash=!empty($b['device_hash'])?substr($b['device_hash'],0,64):null;
        $this->events?->funnelStep($sid,$step,(int)($b['nominee_id']??0),(int)($b['award_id']??0),$deviceHash,$ipHash,$b['meta']??[]);
        return $this->ok($res);
    }

    /**
     * "Polish my story" — public AI assist on the nomination wizard. Improves
     * clarity of the nominator's own words; never invents achievements.
     * INVISIBLE limits: over budget / no provider / AI failure all return
     * {ok:true, code:'AI_OFF'} — the button quietly does nothing more, the
     * wizard never shows an error for an optional nicety.
     */
    public function polishStory(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $draft=trim((string)($b['reason']??''));
        if($draft===''||mb_strlen($draft)>3000) return $this->err($res,'Write your story first (up to 3000 characters).');
        $ip=$this->ip($req);
        $withinBudget=$this->rateLimit->check(hash('sha256',$ip.'|polish'),'story_polish',5,3600)
            && $this->rateLimit->check('global|gee','gee_ai_day',4000,86400);
        if(!$withinBudget) return $this->ok($res,['code'=>'AI_OFF']);
        // FAIL_DEGRADE: over budget, switched off, no provider or a bad reply all
        // land on the same quiet AI_OFF the client already handles — the wizard
        // must never show an error for an optional nicety.
        $r=(new \AfricaGates\Services\AiGateway())->run('nomination.polish',[
            'system'=>'You polish award-nomination stories for Africa GATES. Improve clarity, flow and specificity while KEEPING the writer\'s voice, language and every factual claim exactly as given — never add achievements, numbers or facts. Plain text only, no markdown, similar length or shorter. Reply with ONLY the improved story.',
            'user'=>$draft,
            'temperature'=>0.4,
            'schema'=>static function(string $raw): ?string {
                $t=trim($raw);
                return $t===''?null:mb_substr($t,0,3000);
            },
        ]);
        if(!$r->ok) return $this->ok($res,['code'=>'AI_OFF']);
        return $this->ok($res,['text'=>$r->value]);
    }

    /** Suggest the best-fit category for a nomination story (advisory; picks only from the wizard's real options). */
    public function suggestCategory(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody();
        $story=trim((string)($b['reason']??''));
        $cats=is_array($b['categories']??null)?$b['categories']:[];
        if($story===''||mb_strlen($story)>3000) return $this->ok($res,['code'=>'AI_OFF']);
        $ip=$this->ip($req);
        $withinBudget=$this->rateLimit->check(hash('sha256',$ip.'|catsuggest'),'cat_suggest',10,3600)
            && $this->rateLimit->check('global|gee','gee_ai_day',4000,86400);
        if(!$withinBudget) return $this->ok($res,['code'=>'AI_OFF']);
        $r=\AfricaGates\Services\CategorySuggestService::suggest($story,$cats);
        if($r===null) return $this->ok($res,['code'=>'AI_OFF']);
        return $this->ok($res,$r);
    }

    public function createShareLink(Request $req,Response $res):Response {
        // Shareable prefill link: nominee-side fields only (whitelisted in the
        // service), opaque 32-byte token, 30-day expiry. Throttled per-IP so
        // the table can't be flooded.
        if(!$this->rateLimit->check(hash('sha256',$this->ip($req).'|sharelink'),'share_link',10,3600))
            return $this->err($res,'Too many share links — try again later.','RATE_LIMITED',429);
        $b=(array)$req->getParsedBody();
        try {
            $token=(new \AfricaGates\Services\NominationLinkService())->create($b,$this->ip($req));
        } catch(\RuntimeException $e){ return $this->err($res,$e->getMessage()); }
        $base=\AfricaGates\Support\SiteUrl::base($req);
        return $this->ok($res,['token'=>$token,'url'=>$base.'/nominate?share='.$token,'expires_days'=>\AfricaGates\Services\NominationLinkService::DEFAULT_TTL_DAYS]);
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
        // Draft rows hold nomination PII and are addressed only by an opaque
        // client session_key (a capability). Throttle reads per-IP so the key
        // space can never be enumerated, even though it is already high-entropy.
        if(!$this->rateLimit->check(hash('sha256',$this->ip($req).'|draftload'),'draft_load',60,3600))
            return $this->err($res,'Too many requests.','RATE_LIMITED',429);
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
            $ag=\AfricaGates\Services\MergeService::notMerged(DB::table('gates_nominees')->whereIn('status',['winner','runner_up']))->count();
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
        $svc=$this->legacyService ?? new \AfricaGates\Services\LegacyService();
        return $this->ok($res,['events'=>$this->cache->remember('api:legacy',7200,fn()=>$svc->getAllPublished())]);
    }
    public function opportunities(Request $req,Response $res):Response {
        $svc=$this->oppService ?? new \AfricaGates\Services\OpportunityService();
        return $this->ok($res,['opportunities'=>$this->cache->remember('api:opps',3600,fn()=>$svc->getActiveOpportunities())]);
    }
    public function submitNomination(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$this->ip($req); $fp=hash('sha256',$ip.strtolower(trim($b['nominator_email']??'')));
        if(!$this->rateLimit->check($fp,'nominate',5,86400)) return $this->err($res,'Daily nomination limit reached.','RATE_LIMITED',429);
        // (string) cast before trim(). Without it this endpoint returned a 500 for the
        // most natural JSON body there is: `{"programme_id": 1, …}`. trim() rejects an
        // int under PHP 8, so a caller who sent the id as a number — as JSON encodes
        // numbers — got "An internal error occurred" instead of a nomination, while a
        // caller who quoted it as a string succeeded. Found by POSTing to it.
        foreach(['programme_id','nominee_name','country_code','reason','nominator_name','nominator_email'] as $f) if(empty(trim((string)($b[$f]??'')))) return $this->err($res,"Field '$f' is required.");
        if(!filter_var($b['nominator_email'],FILTER_VALIDATE_EMAIL)) return $this->err($res,'Invalid nominator email.');
        // Nominee contact: email OR phone — at least one; anything provided must validate.
        $ne=trim((string)($b['nominee_email']??'')); $np=trim((string)($b['nominee_phone']??''));
        if($ne==='' && $np==='') return $this->err($res,"Provide 'nominee_email' or 'nominee_phone' — at least one is required.");
        if($ne!=='' && !filter_var($ne,FILTER_VALIDATE_EMAIL)) return $this->err($res,'Invalid nominee email.');
        if($np!=='' && \AfricaGates\Support\Phone::normalize($np,(string)($b['country_code']??''))===null) return $this->err($res,'Invalid nominee phone — use E.164 (e.g. +2348031234567).');
        try{ $id=$this->awards->submitNomination($b,$ip); }catch(\RuntimeException $e){ return $this->err($res,$e->getMessage()); }
        // Everything after the insert. This endpoint used to stop at the line above
        // and return `ok`, so a nomination arriving here was invisible: no operator
        // was told, the nominator got no confirmation and no reference, the nominee
        // never learned they had been nominated, no AI triage was queued for the
        // review desk and no webhook fired. It sat in the table until somebody
        // happened to look. The web form did all of it inline; now both doors call
        // the same service. See NominationAftercare.
        $after = \AfricaGates\Services\NominationAftercare::run(
            $b, (int) $id, \AfricaGates\Support\SiteUrl::base($req), $this->otp, [], $this->sheets
        );
        // The reference is returned, not just sent: an API caller has no inbox to
        // read a confirmation in, and without it there is nothing to quote to
        // support or to look the nomination up by.
        return $this->ok($res,['message'=>'Nomination submitted.','reference'=>$after['reference']]);
    }
    public function register(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$this->ip($req); $fp=hash('sha256',$ip);
        if(!$this->rateLimit->check($fp,'register',3,3600)) return $this->err($res,'Too many registrations.','RATE_LIMITED',429);
        foreach(['display_name','email','category','profile_type','country_code'] as $f) if(empty(trim((string)($b[$f]??'')))) return $this->err($res,"Field '$f' is required.");
        if(!filter_var($b['email'],FILTER_VALIDATE_EMAIL)) return $this->err($res,'Invalid email.');
        try{ $id=$this->profiles->register($b); }catch(\Exception $e){ return $this->err($res,str_contains($e->getMessage(),'Duplicate')?'Email already registered.':'Registration failed.'); }
        $this->cache->forgetByTag('registry');
        \AfricaGates\Services\WebhookService::dispatch('profile.registered', [
            'profile_id'   => (int)$id,
            'display_name' => trim((string)($b['display_name'] ?? '')),
            'category'     => trim((string)($b['category'] ?? '')),
            'country'      => strtoupper(trim((string)($b['country_code'] ?? ''))),
        ]);
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
        $isNew = false;
        try {
            DB::table('gates_newsletter')->insert([
                'email_hash' => $hash, 'email' => $email,
                'ip_hash' => hash('sha256', $ip),
                'source' => substr((string)($b['source'] ?? 'homepage'), 0, 50),
                'subscribed_at' => date('Y-m-d H:i:s'),
            ]);
            $isNew = true;
        } catch (\Throwable $e) {
            // UNIQUE violation on re-subscribe is fine — idempotent + non-leaky.
        }
        // Welcome only a genuinely-new subscriber (re-subscribe sends nothing → no mail-bomb).
        if ($isNew && $this->otp) {
            try {
                $base = \AfricaGates\Support\SiteUrl::base($req);
                $whtml = "<h1 style=\"margin:0;font-family:'Playfair Display',Georgia,serif;font-weight:700;font-size:24px;color:#10292C\">You're on the list</h1>"
                    . "<p style=\"margin:13px 0 0;font-size:15px;line-height:1.6;color:#4a5256\">Thanks for subscribing to Africa GATES. We'll let you know when nominations open, voting goes live, and each cycle's winners are crowned.</p>"
                    . "<p style=\"text-align:center;margin:22px 0\"><a href=\"{$base}/leaderboard\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">Explore the leaderboard &rarr;</a></p>"
                    . "<p style=\"margin:0;font-size:12.5px;color:#92a6a7\">Didn't subscribe? You can ignore this email and you won't hear from us again.</p>";
                $this->otp->sendBranded($email, "You're subscribed — Africa GATES", $whtml,
                    "Thanks for subscribing to Africa GATES. We'll let you know when nominations open and voting goes live.\n\n{$base}/leaderboard\n\nDidn't subscribe? Ignore this email.",
                    'Newsletter');
            } catch (\Throwable $e) {}
        }
        return $this->ok($res, ['message' => 'Subscribed.']);
    }
}
