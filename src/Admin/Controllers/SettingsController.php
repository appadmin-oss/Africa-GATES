<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Support\Env;
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
            // Flash renders from the Twig globals via the layout — do not shadow them.
            'shop_regions'   => \AfricaGates\Services\ShopPricing::regions(),
            'shop_mults'     => \AfricaGates\Services\ShopPricing::multipliers(),
            // The EFFECTIVE per-order maximum, not the raw setting — it is the lower of
            // that setting and what the cash ceiling allows at the current rate, and
            // showing the raw number would tell an admin they had configured a limit the
            // checkout does not actually honour.
            'paid_max_qty'          => \AfricaGates\Services\PaidVoteService::maxQtyForOrder(),
            'paid_max_order_naira'  => \AfricaGates\Services\PaidVoteService::MAX_ORDER_NAIRA,
            // Which AI providers have a key (booleans only — keys are never echoed).
            // Raw provider state — deliberately direct, not through the gateway:
            // this is the diagnostics surface and must see the true key state
            // even when a kill switch is off.
            'ai_status'      => \AfricaGates\Services\AiService::boot()->status(),
            // Governance view. Both figures were previously impossible to
            // produce: nothing recorded token usage, and nothing recorded whether
            // a reviewer agreed with a suggestion.
            'ai_enabled_flag' => \AfricaGates\Services\AiGateway::globallyEnabled(),
            'ai_spend'        => \AfricaGates\Services\AiGateway::spendReport(),
            'ai_agreement'    => \AfricaGates\Services\AiDecisionLog::agreement(30),
            'ai_capabilities' => array_map(
                static fn (\AfricaGates\Services\AiCapability $c) => [
                    'name'       => $c->name,
                    'purpose'    => $c->purpose,
                    'model'      => $c->model,
                    'on_failure' => $c->onFailure,
                    'calls'      => $c->callsPerDay,
                    'tokens'     => $c->tokensPerDay,
                    'enabled'    => \AfricaGates\Services\AiGateway::capabilityEnabled($c->name),
                ],
                \AfricaGates\Services\AiCapability::all()
            ),
            // Whether a DEDICATED moderation Groq key is set (vs falling back to
            // the general key). Read straight from settings — never echoed.
            'ai_mod_dedicated' => trim((string) (\Illuminate\Database\Capsule\Manager::table('gates_settings')->where('key_name', 'ai_groq_key_mod')->value('value') ?? Env::get('GROQ_MODERATION_KEY', ''))) !== '',
            'ai_mod_model'   => \AfricaGates\Services\AiService::MODERATION_MODEL,
            // Placeholders for the four model fields. Read from the service so the
            // default an operator is shown is the default the request will use —
            // they were four literals in the template, and the Gemini one was stale.
            'ai_default_models' => \AfricaGates\Services\AiService::DEFAULT_MODELS,
            // Messaging channels configured state (booleans only — secrets never echoed).
            'sms_status'     => \AfricaGates\Services\SmsService::boot()->status(),
            // Email delivery health — recent sends with status/error so "links
            // aren't arriving" is diagnosable at a glance.
            'mail_health'    => $this->mailHealth(),
            // Automation / webcron status for the no-SSH setup card.
            'app_url'        => rtrim((string) Env::get('APP_URL', ''), '/'),
            'cron_last'      => (function () {
                try { return \Illuminate\Database\Capsule\Manager::table('gates_cron_log')->where('job_name', 'maintenance')->orderByDesc('id')->first(); }
                catch (\Throwable) { return null; }
            })(),
        ]);
    }

    /** @return array{sent_24h:int, failed_24h:int, dev_24h:int, recent:array, last_error:?string} */
    private function mailHealth(): array
    {
        $out = ['sent_24h' => 0, 'failed_24h' => 0, 'dev_24h' => 0, 'recent' => [], 'last_error' => null];
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $counts = \Illuminate\Database\Capsule\Manager::table('gates_mail_log')
                ->where('created_at', '>=', $since)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
            $out['sent_24h']   = (int) ($counts['sent'] ?? 0);
            $out['failed_24h'] = (int) ($counts['failed'] ?? 0);
            $out['dev_24h']    = (int) ($counts['logged_dev'] ?? 0);
            $out['recent'] = \Illuminate\Database\Capsule\Manager::table('gates_mail_log')
                ->orderByDesc('id')->limit(8)->get()->map(fn($r) => (array) $r)->all();
            $out['last_error'] = \Illuminate\Database\Capsule\Manager::table('gates_mail_log')
                ->where('status', 'failed')->orderByDesc('id')->value('error');
        } catch (\Throwable) {}
        return $out;
    }

    public function save(Request $req, Response $res): Response
    {
        $b = (array)$req->getParsedBody();
        $adminId = (int)$_SESSION['admin_id'];

        // Core site settings + email sender identity (gates_settings table)
        foreach (['announce_text','announce_url','announce_cta','site_title','contact_email',
                  'mail_from_name','mail_from_address','mail_reply_to','admin_alert_email'] as $k) {
            if (array_key_exists($k, $b)) {
                $this->settings->set($k, trim((string)$b[$k]), $adminId);
            }
        }

        // Automation / webcron: toggle opportunistic self-maintenance + manage the
        // browser-set cron token (no SSH needed). Marker field gates the section.
        if (array_key_exists('automation_settings', $b)) {
            $this->settings->set('webcron_auto', !empty($b['webcron_auto']) ? '1' : '0', $adminId);
            $current = trim((string) ($this->settings->get('cron_token') ?? ''));
            if (!empty($b['cron_token_generate']) || $current === '') {
                $this->settings->set('cron_token', bin2hex(random_bytes(24)), $adminId);
            }
        }

        // Voting-integrity: admin-extensible disposable-email blocklist + optional
        // MX deliverability check. The textarea always posts (even empty), so its
        // presence marks this section as submitted and drives the checkbox flag.
        if (array_key_exists('disposable_domains_extra', $b)) {
            $this->settings->set('disposable_domains_extra', trim((string)$b['disposable_domains_extra']), $adminId);
            $this->settings->set('disposable_require_mx', !empty($b['disposable_require_mx']) ? '1' : '0', $adminId);
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

        // Shop location-based pricing — per-region multipliers (gates_settings,
        // JSON map region=>multiplier). Only non-1.0 values are stored, so an
        // all-default config disables regional pricing entirely.
        if (array_key_exists('region_mult', $b) && is_array($b['region_mult'])) {
            $map = [];
            foreach (\AfricaGates\Services\ShopPricing::regions() as $region) {
                $v = (float)($b['region_mult'][$region] ?? 1);
                if ($v > 0 && abs($v - 1.0) > 0.0001) {
                    $map[$region] = max(0.1, min(10.0, round($v, 3)));
                }
            }
            $this->settings->set('shop_region_mult', $map ? json_encode($map) : '', $adminId);
            // Currency-conversion master toggle lives in the same shop card.
            $this->settings->set('currency_conversion', isset($b['currency_conversion']) ? '1' : '', $adminId);
        }

        // Voting points — earn/redeem rates + master toggle (gates_settings).
        if (array_key_exists('points_per_vote', $b)) {
            $this->settings->set('points_per_1000_naira', (string) max(0, (int) ($b['points_per_1000_naira'] ?? 50)), $adminId);
            $this->settings->set('points_per_vote', (string) max(1, (int) ($b['points_per_vote'] ?? 500)), $adminId);
            $this->settings->set('points_enabled', isset($b['points_enabled']) ? '1' : '', $adminId);
        }

        // Paid voting — admin-toggleable business model (OFF by default).
        // Paid votes bump the public tally only; the organic CPI signal is
        // never moved by money (enforced in PaidVoteService::mint).
        if (array_key_exists('paid_vote_settings', $b)) {
            $this->settings->set('paid_voting_enabled', isset($b['paid_voting_enabled']) ? '1' : '', $adminId);
            $this->settings->set('paid_voting_disable_free', isset($b['paid_voting_disable_free']) ? '1' : '', $adminId);
            $this->settings->set('vote_price_naira', (string) max(10, (int) ($b['vote_price_naira'] ?? \AfricaGates\Services\PaidVoteService::DEFAULT_PRICE_NAIRA)), $adminId);
            $this->settings->set('vote_votes_per_1000', (string) max(1, (int) ($b['vote_votes_per_1000'] ?? \AfricaGates\Services\PaidVoteService::DEFAULT_PER_1000)), $adminId);
            // Largest quantity ONE order may carry. Clamped to the hard ceiling here AND
            // in PaidVoteService::maxQty(), because this value ends up in a public vote
            // tally and a settings row is not a trusted input just because an admin typed
            // it — a stray zero is one keystroke away from a nine-figure order.
            $this->settings->set('vote_max_qty', (string) max(1, min(
                \AfricaGates\Services\PaidVoteService::HARD_MAX_QTY,
                (int) ($b['vote_max_qty'] ?? \AfricaGates\Services\PaidVoteService::DEFAULT_MAX_QTY)
            )), $adminId);
        }

        // AI providers — keys live in gates_settings and are WRITE-ONLY: they
        // are never rendered back to the page. A blank field leaves the stored
        // key untouched; ticking the matching "clear" box removes it. The first
        // configured provider (Groq → Gemini → Anthropic → OpenAI) is used.
        if (array_key_exists('ai_settings', $b)) {
            // Two Groq keys: 'groq' = general/public, 'groq_mod' = dedicated
            // moderation key (runs the best model; free-falls back to the
            // general key when unset).
            $providerKeys = ['groq' => 'ai_groq_key', 'groq_mod' => 'ai_groq_key_mod', 'gemini' => 'ai_gemini_key', 'anthropic' => 'ai_anthropic_key', 'openai' => 'ai_openai_key'];
            $clear = (array) ($b['ai_clear'] ?? []);
            foreach ($providerKeys as $name => $settingKey) {
                if (!empty($clear[$name])) { $this->settings->set($settingKey, '', $adminId); continue; }
                $val = trim((string) ($b[$settingKey] ?? ''));
                if ($val !== '') $this->settings->set($settingKey, $val, $adminId);
            }
            foreach (['ai_groq_model', 'ai_groq_model_mod', 'ai_gemini_model', 'ai_anthropic_model', 'ai_openai_model'] as $modelKey) {
                if (array_key_exists($modelKey, $b)) $this->settings->set($modelKey, trim((string) $b[$modelKey]), $adminId);
            }
            // Gee ↔ Make.com agent bridge: URL is plain (echoed), the shared
            // key is WRITE-ONLY like the provider keys above.
            if (array_key_exists('gee_make_agent_url', $b)) {
                $url = trim((string) $b['gee_make_agent_url']);
                if ($url === '' || str_starts_with($url, 'https://')) $this->settings->set('gee_make_agent_url', $url, $adminId);
            }
            if (!empty($clear['make_key'])) $this->settings->set('gee_make_agent_key', '', $adminId);
            else {
                $mk = trim((string) ($b['gee_make_agent_key'] ?? ''));
                if ($mk !== '') $this->settings->set('gee_make_agent_key', $mk, $adminId);
            }
        }

        // Messaging (SMS / WhatsApp) — Twilio + WhatsApp Business Cloud API.
        // Secrets are WRITE-ONLY (never rendered back); a blank field leaves the
        // stored value untouched, the matching "clear" box removes it. Master
        // toggles are OFF by default; unconfigured = the gateway stays inert.
        if (array_key_exists('messaging_settings', $b)) {
            $this->settings->set('sms_enabled', isset($b['sms_enabled']) ? '1' : '', $adminId);
            $this->settings->set('wa_enabled',  isset($b['wa_enabled'])  ? '1' : '', $adminId);
            $clear = (array) ($b['messaging_clear'] ?? []);
            foreach (['sms_twilio_sid', 'sms_twilio_token', 'wa_access_token'] as $k) {
                if (!empty($clear[$k])) { $this->settings->set($k, '', $adminId); continue; }
                $val = trim((string) ($b[$k] ?? ''));
                if ($val !== '') $this->settings->set($k, $val, $adminId);
            }
            foreach (['sms_twilio_from', 'sms_twilio_wa_from', 'wa_phone_number_id'] as $k) {
                if (array_key_exists($k, $b)) $this->settings->set($k, trim((string) $b[$k]), $adminId);
            }
        }

        // Moderation thresholds — where AI/heuristic scores flip to quarantine
        // or auto-reject. Clamped in SpamService::thresholds() so a typo can
        // never disable moderation entirely.
        if (array_key_exists('moderation_settings', $b)) {
            $q = (float) ($b['mod_threshold_quarantine'] ?? 0.30);
            $r = (float) ($b['mod_threshold_reject'] ?? 0.65);
            $this->settings->set('mod_threshold_quarantine', (string) max(0.05, min(0.90, $q)), $adminId);
            $this->settings->set('mod_threshold_reject', (string) max($q + 0.05, min(0.99, $r)), $adminId);
        }

        // Nomination eligibility — admin-toggleable "considered" threshold + min distinct locations.
        if (array_key_exists('nomination_settings', $b)) {
            $this->settings->set('nomination_eligibility_enabled', isset($b['nomination_eligibility_enabled']) ? '1' : '', $adminId);
            $this->settings->set('nomination_min_locations', (string) max(1, (int) ($b['nomination_min_locations'] ?? 5)), $adminId);
        }

        // Display constants — values that used to be hardcoded in copy across the site.
        if (array_key_exists('display_settings', $b)) {
            foreach (['nations_count', 'cpi_recompute_hours', 'review_sla_hours', 'nomination_seconds', 'otp_expiry_minutes'] as $k) {
                if (array_key_exists($k, $b)) $this->settings->set($k, (string) max(0, (int) $b[$k]), $adminId);
            }
            if (array_key_exists('processing_fee_pct', $b)) {
                $this->settings->set('processing_fee_pct', (string) max(0, (float) $b['processing_fee_pct']), $adminId);
            }
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

    /** Run the maintenance hub now, from the browser — for no-SSH hosts. */
    public function runCron(Request $req, Response $res): Response
    {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        try {
            $r = (new \AfricaGates\Support\Maintenance(null, false))->run('auto');
            $done = array_sum(array_map(static fn($x) => (int)($x[1] ?? 0), $r['ran'] ?? []));
            $_SESSION['flash_ok'] = sprintf('Maintenance ran (%d task groups, %dms). Queue delivery + integrations run on the automatic tick.', count($r['ran'] ?? []), (int)($r['runtime_ms'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[settings run-cron] ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Maintenance run failed — check the logs.';
        }
        try { $this->audit->record($adminId, 'settings.run_cron', null, null); } catch (\Throwable) {}
        return $res->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    /** Make one live AI call and report which provider answered — diagnoses "AI doesn't work". */
    public function testAi(Request $req, Response $res): Response
    {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $r = \AfricaGates\Services\AiService::boot()->selfTest();
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? sprintf('AI OK — answered by %s (%s).', $r['provider'] ?? '?', $r['model'] ?? '?')
            : 'AI test failed: ' . ($r['error'] ?? 'no response') . '. Check the provider key and that the host can reach the provider API.';
        try { $this->audit->record($adminId, 'settings.ai_test', null, null, ['ok' => $r['ok'], 'provider' => $r['provider']]); } catch (\Throwable) {}
        return $res->withHeader('Location', '/admin/settings')->withStatus(302);
    }
}
