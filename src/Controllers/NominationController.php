<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use AfricaGates\Support\Env;
use AfricaGates\Support\Name;
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

    /**
     * The programmes the wizard may offer, from the POLICY's predicate.
     *
     * Two things were wrong with the previous form. It matched the phase LABEL —
     * `in_array($p['cycle_status'], ['nominations'])` — which is a second
     * implementation of CyclePhase::isNominationsOpen() and would diverge the moment
     * another phase accepts nominations or a label changes; the symptom would be F7
     * again, the wizard offering programmes it should not or hiding ones it should.
     * And it appeared TWICE, in `form()` and in the POST re-render, so the list a
     * user saw after a validation error was derived independently of the one they
     * first saw. One helper, one predicate.
     *
     * @param list<array<string,mixed>> $progs
     * @return list<array<string,mixed>>
     */
    private static function openForNominations(array $progs): array
    {
        return array_values(array_filter(
            $progs,
            static fn (array $p): bool => !empty($p['phase']['is_nominations_open'])
        ));
    }
    public function form(Request $req,Response $res):Response {
        $progs=$this->cache->remember('awards:active',1800,fn()=>$this->awards->getActiveProgrammesWithStatus());
        $open=self::openForNominations($progs);
        // Shared prefill link (?share=<token>) — nominee-side fields land in the
        // wizard, fully editable; the opener still submits their own nomination.
        $prefill=null;
        $shareToken=trim((string)($req->getQueryParams()['share']??''));
        if($shareToken!==''){
            try{ $prefill=(new \AfricaGates\Services\NominationLinkService())->resolve($shareToken); }catch(\Throwable $e){ $prefill=null; }
        }
        return $this->view->render($res,'pages/nominate.twig',['page_title'=>'Nominate — Africa GATES','meta_description'=>'Nominate exceptional African talent for the Africa GATES awards. Put forward the creatives, businesses and changemakers who deserve continental recognition.','gates_page'=>'nominate','has_hero'=>false,'current_section'=>'projects','programmes'=>$open,'all_programmes'=>$progs,'prefill'=>$prefill,'share_expired'=>$shareToken!=='' && $prefill===null,'regions'=>\AfricaGates\Support\Regions::MAP,'member'=>\AfricaGates\Services\UserAccountService::memberForForms()]);
    }
    public function submit(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$req->getServerParams()['REMOTE_ADDR']??''; $fp=hash('sha256',$ip.strtolower(trim($b['nominator_email']??'')));
        // Real programme data for any error re-render, so the form never falls back
        // to stale hardcoded categories (which could misfile a nomination). `old`
        // feeds every typed value back into the wizard so a server-side rejection
        // never wipes the form. Defined up-front so the rate-limit path re-renders
        // the form too (never a false "success" page).
        $progs=$this->cache->remember('awards:active',1800,fn()=>$this->awards->getActiveProgrammesWithStatus());
        $open=self::openForNominations($progs);
        $rerender=fn(string $msg,int $status=422)=>$this->view->render($res,'pages/nominate.twig',['error'=>$msg,'old'=>$b,'gates_page'=>'nominate','has_hero'=>false,'current_section'=>'projects','programmes'=>$open,'all_programmes'=>$progs,'regions'=>\AfricaGates\Support\Regions::MAP,'member'=>\AfricaGates\Services\UserAccountService::memberForForms()])->withStatus($status);
        if(!$this->rateLimit->check($fp,'nominate',5,86400)) return $rerender("You've reached today's nomination limit (5 per day). Please try again tomorrow.",429);
        $required = [
            'programme_id'=>'a programme', 'nominee_name'=>"the nominee's full name", 'country_code'=>"the nominee's country",
            'nominee_state'=>"the nominee's state/region", 'nominee_lga'=>"the nominee's LGA", 'reason'=>'a reason for the nomination',
            'nominator_name'=>'your full name', 'nominator_email'=>'your email', 'nominator_phone'=>'your phone',
            'nominator_country'=>'your country', 'nominator_state'=>'your state/region', 'nominator_lga'=>'your LGA', 'nominator_age_range'=>'your age range',
        ];
        foreach ($required as $f=>$lbl) if (trim((string)($b[$f] ?? '')) === '') return $rerender('Please provide ' . $lbl . '.');
        // Full name must be at least two words (first + last) — mirrors the client rule.
        foreach (['nominee_name'=>"the nominee's full name", 'nominator_name'=>'your full name'] as $f=>$lbl)
            if (count(preg_split('/\s+/', trim((string)$b[$f]))) < 2) return $rerender('Please enter ' . $lbl . ' — first and last name.');
        if (!filter_var(strtolower(trim((string)$b['nominator_email'])), FILTER_VALIDATE_EMAIL)) return $rerender('Please enter a valid email address.');
        // Nominee contact: EMAIL OR PHONE — at least one is required; anything the
        // nominator actually typed must validate (never silently dropped).
        $neRaw = strtolower(trim((string)($b['nominee_email'] ?? '')));
        $npRaw = trim((string)($b['nominee_phone'] ?? ''));
        if ($neRaw === '' && $npRaw === '') return $rerender("Please provide the nominee's email address or phone number — at least one is required.");
        if ($neRaw !== '' && !filter_var($neRaw, FILTER_VALIDATE_EMAIL)) return $rerender("Please enter a valid email address for the nominee.");
        if ($npRaw !== '' && \AfricaGates\Support\Phone::normalize($npRaw, (string)($b['country_code'] ?? '')) === null) return $rerender("Please enter a valid phone number for the nominee — include the country code (e.g. +234 803 123 4567).");

        // Optional nominee portrait — validated image (re-encoded + size-capped) stored
        // BEFORE the insert so its path lands on the nomination row for moderators to
        // review and, on approval, seed the profile avatar. Failure never blocks the
        // nomination — the photo is optional.
        $photoNote = '';
        $photo = $req->getUploadedFiles()['nominee_photo'] ?? null;
        if ($photo instanceof \Psr\Http\Message\UploadedFileInterface && $photo->getError() === UPLOAD_ERR_OK && $photo->getSize() > 0) {
            try {
                $pic = (new \AfricaGates\Admin\Services\UploadService())->uploadImage($photo, 'nominations', 1200, 82, null, 'nomination', null, 200);
                $b['nominee_photo_path'] = $pic['path'];
                $photoNote = "\n\nPhoto: " . $pic['path'];
            } catch (\Throwable $e) {
                $photoNote = "\n\nPhoto: (attachment rejected — " . $e->getMessage() . ")";
            }
        }
        try{ $nominationId = $this->awards->submitNomination($b,$ip); }catch(\RuntimeException $e){ return $rerender($e->getMessage()); }

        // Optional supporting document — stored securely (validated PDF/image); its
        // URL is surfaced to operators in the alert for review, never shown publicly.
        $evidenceNote = '';
        $ev = $req->getUploadedFiles()['evidence'] ?? null;
        if ($ev instanceof \Psr\Http\Message\UploadedFileInterface && $ev->getError() === UPLOAD_ERR_OK && $ev->getSize() > 0) {
            try {
                $up = (new \AfricaGates\Admin\Services\UploadService())->uploadDocument($ev, 'nominations', 15, null, 'public');
                $evidenceNote = "\n\nEvidence: " . $up['url'];
            } catch (\Throwable $e) {
                $evidenceNote = "\n\nEvidence: (attachment rejected — " . $e->getMessage() . ")";
            }
        }

        // ── Everything after the insert ──────────────────────────────────────
        //
        // Moved out of this controller, because this was NOT the only door
        // nominations come through — the comment here used to claim it was.
        // POST /api/nominations is a live public endpoint that inserted the row
        // and returned `ok`, so an API nomination told no operator, sent the
        // nominator no confirmation or reference, never notified the nominee,
        // queued no triage and fired no webhook. See NominationAftercare.
        $after = \AfricaGates\Services\NominationAftercare::run(
            $b, (int) $nominationId, \AfricaGates\Support\SiteUrl::base($req), $this->mailer,
            ['evidence' => trim(str_replace("\n\nEvidence: ", '', $evidenceNote)),
             'photo'    => trim(str_replace("\n\nPhoto: ", '', $photoNote))],
            $this->sheets
        );
        $reference = $after['reference'];
        $nomName   = $after['nominee'];
        $catLine   = $after['category'];
        $nomEmail  = $after['nominee_email'];

        // Hand the real reference + names to the success page for one render
        // (server-side flash — keeps sequential IDs out of the URL).
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['nom_done'] = ['ref' => $reference, 'nominee' => $nomName, 'cat' => $catLine,
                // Nominee-side fields only — powers the "invite others to second
                // this nomination" share link on the success page (one render).
                'share' => [
                    'nominee_name'  => $nomName,
                    'nominee_email' => $nomEmail,
                    'nominee_phone' => trim((string)($b['nominee_phone'] ?? '')),
                    'country_code'  => strtoupper((string)($b['country_code'] ?? '')),
                    'nominee_state' => trim((string)($b['nominee_state'] ?? '')),
                    'nominee_lga'   => trim((string)($b['nominee_lga'] ?? '')),
                    'nominee_org'   => trim((string)($b['nominee_org'] ?? '')),
                    'programme_id'  => (int)$b['programme_id'],
                    'category_id'   => (int)($b['category_id'] ?? 0),
                ]];
        }
        return $res->withHeader('Location', '/nominate/success')->withStatus(302);
    }
}
