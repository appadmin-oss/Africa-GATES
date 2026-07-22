<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{RateLimitService,GoogleSheetsService,OtpService,Notifier,PaymentService,StatsService};

class PartnerController {
    public function __construct(
        private readonly Twig $view,
        private readonly RateLimitService $rateLimit,
        private readonly ?GoogleSheetsService $sheets = null,
        private readonly ?OtpService $mailer = null,
        private readonly ?PaymentService $payments = null,
        private readonly ?StatsService $stats = null
    ){}

    /** Common render vars — real DB stats + enabled providers on every render path. */
    private function vars(array $extra): array {
        return array_merge([
            'gates_page'      => 'partner',
            'has_hero'        => false,
            'current_section' => 'projects',
            'stats'           => $this->stats?->summary() ?? [],
            'payment_providers' => $this->payments ? $this->payments->enabledProviders() : [],
        ], $extra);
    }

    public function form(Request $req,Response $res):Response {
        return $this->view->render($res,'pages/partner.twig',$this->vars([
            'page_title'       => 'Partner With Us — Africa GATES',
            'meta_description' => 'Partner with Africa GATES to power continental cultural recognition. Sponsor award programmes, reach the verified registry community and champion African excellence.',
        ]));
    }

    public function submit(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$req->getServerParams()['REMOTE_ADDR']??'';
        // Accept both the form's field names (organisation/tier) and legacy ones (org_name/partnership_type).
        $org   = trim((string)($b['organisation'] ?? $b['org_name'] ?? ''));
        $tier  = trim((string)($b['tier'] ?? $b['partnership_type'] ?? ''));
        $name  = trim((string)($b['contact_name'] ?? ''));
        $email = strtolower(trim((string)($b['contact_email'] ?? '')));
        $msg   = trim((string)($b['message'] ?? ''));
        if(!$this->rateLimit->check(hash('sha256',$ip),'partner',3,86400)) return $this->view->render($res,'pages/partner.twig',$this->vars(['error'=>'Too many enquiries. Please try again later.','old'=>$b]))->withStatus(429);
        if($org===''||$name===''||$email===''||$msg==='') return $this->view->render($res,'pages/partner.twig',$this->vars(['error'=>'Please fill all required fields.','old'=>$b]))->withStatus(422);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return $this->view->render($res,'pages/partner.twig',$this->vars(['error'=>'Please enter a valid email address.','old'=>$b]))->withStatus(422);
        DB::table('gates_partner_enquiries')->insert(['org_name'=>$org,'contact_name'=>$name,'contact_email'=>$email,'contact_phone'=>trim((string)($b['contact_phone']??'')),'partnership_type'=>$tier,'message'=>$msg,'status'=>'new','created_at'=>Carbon::now()]);
        $this->sheets?->pushPartnerEnquiry($b);
        // Notify the team + acknowledge the enquirer.
        Notifier::adminAlert($this->mailer, 'New partnership enquiry',
            "Organisation: $org\nContact: $name <$email>\nTier: " . ($tier ?: '—') . "\n\n$msg");
        if ($this->mailer) {
            $this->mailer->sendPartnerConfirmation($email, $name, $org, $tier ?: 'General');
        }
        \AfricaGates\Services\WebhookService::dispatch('partner.enquiry', [
            'organisation' => $org,
            'tier'         => $tier ?: 'General',
        ]);
        return $res->withHeader('Location', '/partner/success')->withStatus(302);
    }
}
