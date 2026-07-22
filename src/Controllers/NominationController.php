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
        $open=array_values(array_filter($progs,fn($p)=>in_array($p['cycle_status'],['nominations'])));
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

        // ── Notifications ────────────────────────────────────────────────────
        $nomName   = trim((string)$b['nominee_name']);
        $nomEmail  = strtolower(trim((string)($b['nominee_email'] ?? '')));
        $byName    = trim((string)$b['nominator_name']);
        $byEmail   = strtolower(trim((string)$b['nominator_email']));
        $progName  = trim((string)($b['programme_title'] ?? ('Programme #' . (int)$b['programme_id'])));
        $reference = \AfricaGates\Support\Reference::nomination((int)$nominationId);
        $base      = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $watchUrl  = $base . '/leaderboard';

        // Resolve the award CATEGORY name so every message names it (not just the programme).
        $catName = '';
        if (!empty($b['category_id'])) {
            try { $catName = (string)(\Illuminate\Database\Capsule\Manager::table('gates_award_categories')->where('id', (int)$b['category_id'])->value('title') ?? ''); }
            catch (\Throwable $ex) {}
        }
        $catLine  = $catName !== '' ? ($progName . ' · ' . $catName) : $progName;
        $esc      = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $evidence = trim(str_replace("\n\nEvidence: ", '', $evidenceNote));
        $photo    = trim(str_replace("\n\nPhoto: ", '', $photoNote));

        if ($this->mailer) {
            // 1) Operators — a clean, fully-formatted HTML brief (replaces the old plain text).
            $rows = [
                'Nominee'            => $nomName,
                'Category'           => $catLine,
                'Country'            => strtoupper((string)$b['country_code']),
                'State / LGA'        => trim((string)($b['nominee_state'] ?? '')) . ' / ' . trim((string)($b['nominee_lga'] ?? '')),
                'Organisation'       => trim((string)($b['nominee_org'] ?? '')) ?: '—',
                'Nominee email'      => $nomEmail ?: '—',
                'Nominee phone'      => trim((string)($b['nominee_phone'] ?? '')) ?: '—',
                'Nominator'          => $byName . ' <' . $byEmail . '>',
                'Nominator phone'    => trim((string)($b['nominator_phone'] ?? '')),
                'Nominator age range'=> trim((string)($b['nominator_age_range'] ?? '')) ?: '—',
                'Nominator location' => trim((string)($b['nominator_state'] ?? '')) . ', ' . trim((string)($b['nominator_lga'] ?? '')) . ', ' . strtoupper((string)($b['nominator_country'] ?? '')),
                'Reference'          => $reference,
            ];
            $tbl = '';
            foreach ($rows as $k => $v) {
                $tbl .= '<tr><td style="padding:6px 16px 6px 0;color:#6b7674;font-size:13px;white-space:nowrap;vertical-align:top">' . $esc($k)
                      . '</td><td style="padding:6px 0;color:#10292c;font-size:14px;font-weight:600">' . $esc($v) . '</td></tr>';
            }
            $adminHtml = '<p>A new nomination has been submitted and is awaiting review.</p>'
                . '<table style="border-collapse:collapse;margin:6px 0 16px">' . $tbl . '</table>'
                . '<p style="margin:0 0 5px;color:#6b7674;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Reason</p>'
                . '<div style="background:#f6f7f6;border-radius:10px;padding:12px 14px;font-size:14px;line-height:1.6;color:#10292c;white-space:pre-wrap">' . $esc(trim((string)($b['reason'] ?? ''))) . '</div>'
                . ($evidence ? '<p style="font-size:13px;margin-top:12px">Supporting document: ' . $esc($evidence) . '</p>' : '')
                . ($photo ? '<p style="font-size:13px">Photo: ' . $esc($photo) . '</p>' : '')
                . '<p style="margin-top:16px"><a href="' . $esc($base . '/admin/nominations') . '" style="color:#237b22;font-weight:600">Review in the admin console &rarr;</a></p>';
            try { $this->mailer->sendBranded(Notifier::adminEmail(), 'New nomination · ' . $nomName, $adminHtml, strip_tags($adminHtml), 'Nominations'); } catch (\Throwable $ex) {}

            // 2) Nominator — confirmation + a "view entry / watch the cycle" link.
            $byHtml = '<p>Hi ' . $esc($byName) . ',</p>'
                . '<p>Thank you for nominating <strong>' . $esc($nomName) . '</strong> for <strong>' . $esc($catLine) . '</strong>. We&rsquo;ve logged your entry (reference <strong>' . $esc($reference) . '</strong>), and our panel reviews every profile before it joins the cycle.</p>'
                . '<p><a href="' . $esc($watchUrl) . '" style="display:inline-block;background:#237b22;color:#fff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:999px">View your entry &amp; watch the cycle &rarr;</a></p>'
                . '<p style="color:#6b7674;font-size:13.5px">We&rsquo;ll email you the moment the profile goes live and voting opens.</p>';
            try { $this->mailer->sendBranded($byEmail, 'Your nomination is in — ' . $nomName, $byHtml, strip_tags($byHtml), 'Nominations'); } catch (\Throwable $ex) {}

            // 3) Nominee — notify them they were nominated (only when an email was provided).
            if ($nomEmail !== '' && filter_var($nomEmail, FILTER_VALIDATE_EMAIL)) {
                $nomHtml = '<p>Hello ' . $esc($nomName) . ',</p>'
                    . '<p>Wonderful news &mdash; you&rsquo;ve been nominated for <strong>' . $esc($catLine) . '</strong> on Africa GATES, the continental Cultural Power Index.</p>'
                    . '<p>Our panel verifies every profile before it joins the cycle. Once it&rsquo;s live, the community can vote and your Cultural Power Index begins to build.</p>'
                    . '<p><a href="' . $esc($watchUrl) . '" style="display:inline-block;background:#237b22;color:#fff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:999px">Watch the cycle &rarr;</a> &nbsp; <a href="' . $esc($base . '/register') . '" style="color:#237b22;font-weight:600">Claim &amp; verify your profile</a></p>';
                try { $this->mailer->sendBranded($nomEmail, "You've been nominated — Africa GATES", $nomHtml, strip_tags($nomHtml), 'Nominations'); } catch (\Throwable $ex) {}
            }
        }

        // ── SMS / WhatsApp (best-effort, admin-configured, off by default) ──
        // Spec: email → email flow; phone → Twilio SMS then WhatsApp; both
        // contacts → email + SMS; WhatsApp ALWAYS sends when configured and a
        // phone exists. Failures audit + re-queue, never block the nomination.
        try {
            $sms = \AfricaGates\Services\SmsService::boot();
            if ($sms->configured()) {
                $nomPhone = \AfricaGates\Support\Phone::normalize((string)($b['nominee_phone'] ?? ''), (string)($b['country_code'] ?? ''));
                if ($nomPhone !== null) {
                    $plan = \AfricaGates\Services\SmsService::channelPlan($nomEmail ?: null, $nomPhone, $sms);
                    $msg  = 'Africa GATES: ' . $nomName . ', you have been nominated for ' . $catLine . ' (ref ' . $reference . '). Our panel reviews every profile before it goes live. ' . $watchUrl;
                    if (in_array('sms', $plan, true))      $sms->sendSms($nomPhone, $msg, 'nomination_nominee');
                    if (in_array('whatsapp', $plan, true)) $sms->sendWhatsApp($nomPhone, $msg, 'nomination_nominee');
                }
                $byPhone = \AfricaGates\Support\Phone::normalize((string)($b['nominator_phone'] ?? ''), (string)($b['nominator_country'] ?? ''));
                if ($byPhone !== null) {
                    $msg = 'Africa GATES: your nomination of ' . $nomName . ' is in (ref ' . $reference . '). We will notify you the moment the profile goes live. ' . $watchUrl;
                    if ($sms->smsConfigured())      $sms->sendSms($byPhone, $msg, 'nomination_nominator');
                    if ($sms->whatsappConfigured()) $sms->sendWhatsApp($byPhone, $msg, 'nomination_nominator');
                }
            }
        } catch (\Throwable $ex) {}

        // Queue advisory AI triage (score/summary/duplicates for the review desk).
        \AfricaGates\Services\NominationTriageService::enqueue((int) $nominationId);

        // New-nomination webhook — ids + labels only, never raw contact details.
        \AfricaGates\Services\WebhookService::dispatch('nomination.submitted', [
            'nomination_id' => (int) $nominationId,
            'reference'     => $reference,
            'nominee'       => $nomName,
            'programme'     => $progName,
            'category_id'   => (int)($b['category_id'] ?? 0),
            'category'      => $catName,
            'country'       => strtoupper((string)($b['country_code'] ?? '')),
            'has_email'     => $nomEmail !== '',
            'has_phone'     => trim((string)($b['nominee_phone'] ?? '')) !== '',
        ]);

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
