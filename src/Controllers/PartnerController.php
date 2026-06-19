<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{RateLimitService,GoogleSheetsService,OtpService,Notifier,PaymentService};

class PartnerController {
    public function __construct(
        private readonly Twig $view,
        private readonly RateLimitService $rateLimit,
        private readonly ?GoogleSheetsService $sheets = null,
        private readonly ?OtpService $mailer = null,
        private readonly ?PaymentService $payments = null
    ){}
    public function form(Request $req,Response $res):Response {
        // Only providers with a configured secret key are offered, so the pack CTAs
        // open the checkout modal in production yet fall back to the enquiry form in
        // dev (no keys → empty list → CTAs keep pointing at #enquiry).
        $providers = $this->payments ? $this->payments->enabledProviders() : [];
        return $this->view->render($res,'pages/partner.twig',['page_title'=>'Partner With Us — Africa GATES','meta_description'=>'Partner with Africa GATES to power continental cultural recognition. Sponsor award programmes, reach the registry community and champion African excellence.','gates_page'=>'partner','has_hero'=>false,'current_section'=>'projects','payment_providers'=>$providers]);
    }
    public function submit(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$req->getServerParams()['REMOTE_ADDR']??'';
        // Accept both the form's field names (organisation/tier) and legacy ones (org_name/partnership_type).
        $org   = trim((string)($b['organisation'] ?? $b['org_name'] ?? ''));
        $tier  = trim((string)($b['tier'] ?? $b['partnership_type'] ?? ''));
        $name  = trim((string)($b['contact_name'] ?? ''));
        $email = strtolower(trim((string)($b['contact_email'] ?? '')));
        $msg   = trim((string)($b['message'] ?? ''));
        if(!$this->rateLimit->check(hash('sha256',$ip),'partner',3,86400)) return $this->view->render($res,'pages/partner.twig',['error'=>'Too many enquiries.','gates_page'=>'partner','has_hero'=>false,'current_section'=>'projects','old'=>$b])->withStatus(429);
        if($org===''||$name===''||$email===''||$msg==='') return $this->view->render($res,'pages/partner.twig',['error'=>'Please fill all required fields.','gates_page'=>'partner','has_hero'=>false,'current_section'=>'projects','old'=>$b])->withStatus(422);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return $this->view->render($res,'pages/partner.twig',['error'=>'Please enter a valid email address.','gates_page'=>'partner','has_hero'=>false,'current_section'=>'projects','old'=>$b])->withStatus(422);
        DB::table('gates_partner_enquiries')->insert(['org_name'=>$org,'contact_name'=>$name,'contact_email'=>$email,'contact_phone'=>trim((string)($b['contact_phone']??'')),'partnership_type'=>$tier,'message'=>$msg,'status'=>'new','created_at'=>Carbon::now()]);
        $this->sheets?->pushPartnerEnquiry($b);
        // Notify the team + acknowledge the enquirer.
        Notifier::adminAlert($this->mailer, 'New partnership enquiry',
            "Organisation: $org\nContact: $name <$email>\nTier: " . ($tier ?: '—') . "\n\n$msg");
        if ($this->mailer) {
            $this->mailer->sendPartnerConfirmation($email, $name, $org, $tier ?: 'General');
        }
        return $res->withHeader('Location', '/partner/success')->withStatus(302);
    }
}
