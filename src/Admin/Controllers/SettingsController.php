<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Admin\Services\{SettingsService, AuditService};
use AfricaGates\Services\OtpService;

class SettingsController
{
    public function __construct(
        private readonly Twig           $view,
        private readonly SettingsService $settings,
        private readonly AuditService   $audit,
        private readonly ?OtpService    $mailer = null,
    ) {}

    public function form(Request $req, Response $res): Response
    {
        $adminSettings = [];
        try {
            if (\Illuminate\Database\Capsule\Manager::getSchemaBuilder()->hasTable('gates_admin_settings')) {
                $rows = \Illuminate\Database\Capsule\Manager::table('gates_admin_settings')->get();
                foreach ($rows as $r) $adminSettings[$r->setting_key] = $r->setting_value;
            }
        } catch (\Throwable) {}

        return $this->view->render($res, 'admin/settings.twig', [
            'page_title'     => 'Settings — Admin',
            'admin_page'     => 'settings',
            'values'         => $this->settings->all(),
            'admin_settings' => $adminSettings,
            'smtp_configured'=> $this->mailer?->smtpConfigured() ?? false,
            'flash_ok'       => $_SESSION['flash_ok']   ?? null,
            'flash_error'    => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function save(Request $req, Response $res): Response
    {
        $b = (array)$req->getParsedBody();
        $adminId = (int)$_SESSION['admin_id'];

        // Core site settings (gates_settings table)
        foreach (['announce_text','announce_url','announce_cta','site_title','contact_email'] as $k) {
            if (array_key_exists($k, $b)) {
                $this->settings->set($k, (string)$b[$k], $adminId);
            }
        }

        // Admin-configurable settings (gates_admin_settings table)
        $adminKeys = [
            'donation_votes_per_1000' => 'integer',
            'donation_vote_enabled'   => 'bool',
            'donation_vote_min_amount'=> 'integer',
        ];
        foreach ($adminKeys as $key => $type) {
            if (!array_key_exists($key, $b)) continue;
            $val = $b[$key];
            if ($type === 'integer') $val = (string)max(0, (int)$val);
            if ($type === 'bool')    $val = (string)(int)(bool)$val;
            \Illuminate\Database\Capsule\Manager::table('gates_admin_settings')
                ->updateOrInsert(
                    ['setting_key' => $key],
                    ['setting_value' => $val, 'updated_at' => \Illuminate\Support\Carbon::now()->toDateTimeString()]
                );
        }

        $this->audit->record($adminId, 'settings.update', null, null);
        $_SESSION['flash_ok'] = 'Settings saved.';
        return $res->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    /** POST /admin/settings/smtp-test — sends a test email to the logged-in admin. */
    public function smtpTest(Request $req, Response $res): Response
    {
        $adminId = (int)$_SESSION['admin_id'];
        $adminEmail = (string)\Illuminate\Database\Capsule\Manager::table('gates_admins')
            ->where('id', $adminId)->value('email');

        if (!$this->mailer) {
            $_SESSION['flash_error'] = 'Mail service is not available.';
            return $res->withHeader('Location', '/admin/settings')->withStatus(302);
        }

        $result = $this->mailer->selfTest($adminEmail);
        if ($result['success']) {
            $msg = isset($result['fallback'])
                ? 'SMTP not configured — test output written to var/logs/outgoing-mail.log'
                : "Test email sent to $adminEmail successfully.";
            $_SESSION['flash_ok'] = $msg;
        } else {
            $_SESSION['flash_error'] = 'SMTP test failed: ' . ($result['error'] ?? 'Unknown error');
        }

        $this->audit->record($adminId, 'settings.smtp_test', null, null);
        return $res->withHeader('Location', '/admin/settings')->withStatus(302);
    }
}
