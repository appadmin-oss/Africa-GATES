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
            // Display timezone: the choices, and what is in force right now.
            'tz_choices'     => \AfricaGates\Support\DisplayTime::choices(),
            'tz_current'     => \AfricaGates\Support\DisplayTime::zone(),
            'tz_abbr'        => \AfricaGates\Support\DisplayTime::abbr(),
            'admin_settings' => $adminSettings,
            'smtp_configured'=> $this->mailer?->smtpConfigured() ?? false,
            // Whether a password is resolvable at all, from either source — the field
            // itself is WRITE-ONLY, like every provider key, so this only picks the
            // placeholder. Same shape as ai_mod_dedicated below.
            // Deliberately settings-only, not "configured from either source": the
            // placeholder promises that leaving the box blank KEEPS what is there, and
            // what blank keeps is the stored row. An .env password is the fallback
            // underneath, not something this form is holding on to.
            'smtp_pass_set'  => trim((string) (\Illuminate\Database\Capsule\Manager::table('gates_settings')->where('key_name', 'mail_smtp_pass')->value('value') ?? '')) !== '',
            // Image hosting. Read through the resolver, so what the card reports is
            // what an upload will actually do rather than what one source of two says.
            'cloudinary_on'     => \AfricaGates\Services\CloudinaryService::enabled(),
            'cloudinary_cloud'  => \AfricaGates\Services\CloudinaryService::cloudName(),
            'cloudinary_folder' => \AfricaGates\Services\CloudinaryService::rootFolder(),
            // Shown as the placeholder, so an operator can see the sentence they are
            // replacing rather than an empty box.
            'invite_witness_nominee_default' => \AfricaGates\Services\InviteAudience::spec(
                \AfricaGates\Services\InviteAudience::NOMINEE)['witness_default'],
            'invite_witness_judge_default'   => \AfricaGates\Services\InviteAudience::spec(
                \AfricaGates\Services\InviteAudience::JUDGE)['witness_default'],
            // The reminder schedule and its two sentences, resolved rather than read raw:
            // the screen has to show the marks that WILL be used, which for an operator
            // who has set nothing is the default list and not an empty box.
            'invite_reminder_on'    => \AfricaGates\Services\InviteReminders::enabled(),
            'invite_reminder_marks' => \AfricaGates\Services\InviteReminders::marks(),
            'invite_reminder_days_default' => implode(', ', \AfricaGates\Services\InviteReminders::DEFAULT_MARKS),
            // Resolved, not echoed: an operator who has set no time is on 09:00, and the
            // screen has to say 09:00 rather than show them an empty box beside a schedule
            // that is demonstrably running.
            'invite_reminder_time'      => \AfricaGates\Services\InviteReminders::sendTimeLabel(),
            'invite_reminder_time_zone' => \AfricaGates\Support\DisplayTime::abbr(),
            // The arc, and what an operator may write into it. The token list is READ
            // from the class rather than copied into the template: a list of placeholders
            // that lives in Twig is one that goes out of date the first time one is added.
            'invite_seq_days'    => \AfricaGates\Services\InviteSequence::DAYS,
            // Both arcs. Nominees and judges are honoured for different things, so the
            // letters are written twice and edited separately.
            'invite_seq_audiences' => \AfricaGates\Services\InviteAudience::all(),
            'visits_on'         => \AfricaGates\Services\VisitTracker::enabled(),
            'visits_days_value' => \AfricaGates\Services\VisitTracker::keepDays(),
            'invite_seq_tokens'  => \AfricaGates\Services\InviteSequence::TOKENS,
            'invite_seq_values_default'  => \AfricaGates\Services\InviteSequence::values(),
            'invite_seq_outcome_default' => \AfricaGates\Services\InviteSequence::DEFAULT_OUTCOME,
            'invite_seq_action_default'  => \AfricaGates\Services\InviteSequence::DEFAULT_ACTION,
            'invite_seq_team_default'    => \AfricaGates\Services\InviteSequence::DEFAULT_TEAM,
            'invite_reminder_line_nominee_default' => \AfricaGates\Services\InviteReminders::copy(
                \AfricaGates\Services\InviteAudience::NOMINEE)['line_default'],
            'invite_reminder_line_judge_default'   => \AfricaGates\Services\InviteReminders::copy(
                \AfricaGates\Services\InviteAudience::JUDGE)['line_default'],
            'cloudinary_secret_set' => trim((string) (\Illuminate\Database\Capsule\Manager::table('gates_settings')->where('key_name', 'cloudinary_api_secret')->value('value') ?? '')) !== '',
            // Flash renders from the Twig globals via the layout — do not shadow them.
            // Where each revenue stream settles. Resolved, so the screen shows the code that
            // WILL be used rather than a raw setting somebody has to interpret.
            'payouts'        => \AfricaGates\Services\PaymentDestination::all(),
            'payout_bearers' => \AfricaGates\Services\PaymentDestination::BEARERS,
            'payouts_routed' => \AfricaGates\Services\PaymentDestination::anyRouted(),
            'shop_regions'   => \AfricaGates\Services\ShopPricing::regions(),
            'shop_mults'     => \AfricaGates\Services\ShopPricing::multipliers(),
            // The EFFECTIVE per-order maximum, not the raw setting — it is the lower of
            // that setting and what the cash ceiling allows at the current rate, and
            // showing the raw number would tell an admin they had configured a limit the
            // checkout does not actually honour.
            'paid_max_qty'          => \AfricaGates\Services\PaidVoteService::maxQtyForOrder(),
            'paid_max_order_naira'  => \AfricaGates\Services\PaidVoteService::MAX_ORDER_NAIRA,
            // The ladder as ROWS, not as the raw JSON string. An admin editing JSON in a
            // textarea is one missing brace away from silently reverting the site's whole
            // pricing to defaults, and the ballot reads this on every page view.
            'vote_tiers'            => \AfricaGates\Services\PaidVoteService::tiers(),
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
            // WHY the failures in that report happened. The count beside each capability was
            // the end of the trail on a host with no shell: "3 failures", with the provider's
            // own refusal sitting unread in a column since the log was built. A rejected key,
            // a decommissioned model, an egress block and a summary that ran out of time all
            // showed as the same gold chip, and each is a different fix.
            'ai_failures'     => \AfricaGates\Services\AiGateway::recentFailures(),
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
            // The last per-provider probe, consumed once. Not persisted: a verdict from a
            // week ago rendered as if it were current is worse than no verdict, because it
            // is the one an operator would act on.
            'ai_probe' => (static function (): array {
                $r = $_SESSION['ai_probe'] ?? [];
                unset($_SESSION['ai_probe']);
                return is_array($r) ? $r : [];
            })(),
            // ── THE GOOGLE DOOR, NOW SETTABLE FROM HERE ─────────────────────
            //
            // Both values used to be readable only from .env, and there is no SSH on this
            // production host — so the screen that told an operator to "set GAS_SECRET in
            // .env" was describing a file they could not open, and the whole calendar and
            // Meet integration stayed off with a correct-looking explanation beside it.
            //
            // The URL is ECHOED because an operator has to be able to see which deployment
            // they are pointed at without pasting it again to find out; a stale /exec URL
            // from a previous deployment is the commonest way this integration half-works.
            // The secret is write-only, like every other credential on this page.
            'gas_url'        => \AfricaGates\Services\GoogleMeetService::gasUrl(),
            'gas_secret_set' => \AfricaGates\Services\GoogleMeetService::gasSecret() !== '',
            // Consumed once, for the same reason as the AI probe above: a verdict about a
            // deployment that has since been re-published is the one an operator would act on.
            'sync_probe' => (static function (): array {
                $r = $_SESSION['sync_probe'] ?? [];
                unset($_SESSION['sync_probe']);
                return is_array($r) ? $r : [];
            })(),
            // ElevenLabs — the voice on the nominee's questionnaire. Booleans and defaults
            // only; the key itself is write-only like every other provider key on this page.
            'voice_status'    => (static function (): array {
                $v = \AfricaGates\Services\VoiceService::boot();
                return ['configured' => $v->configured(), 'voice' => $v->voiceId(),
                        'tts' => $v->ttsModel(), 'stt' => $v->sttModel(), 'why' => $v->why()];
            })(),
            'voice_defaults'  => ['voice' => \AfricaGates\Services\VoiceService::DEFAULT_VOICE,
                                  'tts'   => \AfricaGates\Services\VoiceService::TTS_MODEL,
                                  'stt'   => \AfricaGates\Services\VoiceService::STT_MODEL],
            // Messaging channels configured state (booleans only — secrets never echoed).
            'sms_status'     => \AfricaGates\Services\SmsService::boot()->status(),
            // The shipped wording, shown as the textarea's placeholder so an operator can
            // see what "leave blank" actually sends rather than being asked to trust it.
            'checkin_sms_default' => \AfricaGates\Services\CheckInThanks::DEFAULT_TEMPLATE,
            // Email delivery health — recent sends with status/error so "links
            // aren't arriving" is diagnosable at a glance.
            'mail_health'    => $this->mailHealth(),

            // ── Community return ─────────────────────────────────────────────
            //
            // Resolved through the same helper the public pages use, so the form
            // shows literally what /integrity is publishing. This card is the ONLY
            // place the share is decided; every reader in the codebase goes through
            // RuleEngine to find out what it is.
            'community_return' => \AfricaGates\Services\CommunityReturnService::displayRules(
                (new \AfricaGates\Services\RuleEngine())->effective()
            ),

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
                  'mail_from_name','mail_from_address','mail_reply_to','admin_alert_email',
                  // Public-facing support address. Distinct from admin_alert_email:
                  // that one is internal plumbing, this one is printed on pages and
                  // quoted by the assistant, so a stranger must be able to write to it.
                  'support_email',
                  // SMTP transport. The host, port and login are echoed back like any
                  // other field; the PASSWORD is handled below and never rendered.
                  // These used to be readable only from .env, on a host with no shell.
                  'mail_smtp_host','mail_smtp_port','mail_smtp_user',
                  // Image hosting. Cloud name, key and folder are identifiers, not
                  // secrets — the API secret and the combined URL are handled below.
                  'cloudinary_cloud_name','cloudinary_api_key','cloudinary_folder',
                  // Invitation quotas and the guest discount. Clamped in InviteAudience
                  // rather than here, because they are read from settings by services that
                  // must not trust the row either.
                  'invite_quota_nominee','invite_quota_judge','invite_discount_percent',
                  // The one sentence that names why the hall is being filled. Editable
                  // because programmes honour different things — see InviteAudience.
                  'invite_witness_nominee','invite_witness_judge',
                  // The reminder run: whether it sends, how many days out it starts, and
                  // the sentence each audience reads. Parsed and clamped in
                  // InviteReminders — a free-text day list must not be trusted by the
                  // sweep any more than it is trusted here.
                  'invite_reminder_days','invite_reminder_time',
                  'invite_reminder_line_nominee','invite_reminder_line_judge',
                  // The countdown letters: the facts no database can know, and the five
                  // bodies themselves. Tokens are resolved at send time against the event,
                  // so an operator running a second gala changes none of this.
                  'invite_seq_theme','invite_seq_outcome','invite_seq_values',
                  'invite_seq_action','invite_seq_team',
                  // The legacy nominee keys stay accepted: a screen saved before the
                  // audience was in the key still posts them, and dropping them here
                  // would turn that save into a silent revert.
                  'invite_seq_body_5','invite_seq_body_4','invite_seq_body_3',
                  'invite_seq_body_2','invite_seq_body_1',
                  'invite_seq_body_nominee_5','invite_seq_body_nominee_4','invite_seq_body_nominee_3',
                  'invite_seq_body_nominee_2','invite_seq_body_nominee_1',
                  'invite_seq_body_judge_5','invite_seq_body_judge_4','invite_seq_body_judge_3',
                  'invite_seq_body_judge_2','invite_seq_body_judge_1'] as $k) {
            if (array_key_exists($k, $b)) {
                $this->settings->set($k, trim((string)$b[$k]), $adminId);
            }
        }

        // ── Credentials: WRITE-ONLY, exactly like the AI provider keys below ──
        //
        // Never rendered back to the page. A blank field leaves the stored value
        // untouched — otherwise every save of an unrelated field on this form would
        // wipe the SMTP password — and the matching "clear" box removes it.
        //
        // `cloudinary_url` is in this list rather than the echoed one above because
        // `cloudinary://key:secret@cloud` carries the secret inside it; echoing it back
        // would put the credential in the page source of every settings render.
        foreach (['mail_smtp_pass' => 'smtp_pass',
                  'cloudinary_api_secret' => 'cloudinary_secret',
                  'cloudinary_url' => 'cloudinary_url'] as $settingKey => $clearName) {
            $clear = (array) ($b['secret_clear'] ?? []);
            if (!empty($clear[$clearName])) { $this->settings->set($settingKey, '', $adminId); continue; }
            $val = trim((string) ($b[$settingKey] ?? ''));
            if ($val !== '') $this->settings->set($settingKey, $val, $adminId);
        }

        // ── Display timezone ────────────────────────────────────────────────
        //
        // What operators SEE and TYPE. Storage stays UTC — see DisplayTime for why
        // switching the process clock instead reinterprets every existing row by the
        // offset, permanently and with nothing recording which rows are which.
        //
        // Validated against the real tz database rather than stored as typed: an invalid
        // identifier here would make every date on every page throw.
        if (array_key_exists('display_timezone', $b)) {
            $tz = trim((string) $b['display_timezone']);
            if ($tz !== '' && \AfricaGates\Support\Clock::isValid($tz)) {
                $this->settings->set('display_timezone', $tz, $adminId);
                // The zone is cached per request; this request has already read it.
                \AfricaGates\Support\DisplayTime::forget();
            }
        }

        // Arrival tracking. Marker-gated like the others: an unchecked box posts nothing,
        // so a plain allowlist entry could turn it on and never off. `visits_days` always
        // posts, so its presence marks the section as submitted.
        if (array_key_exists('visits_days', $b)) {
            $this->settings->set('visits_enabled', !empty($b['visits_enabled']) ? '1' : '0', $adminId);
            $days = (int) trim((string) $b['visits_days']);
            // Clamped, and the floor matters: a retention of zero would delete every
            // arrival on the next maintenance tick, including today's.
            $this->settings->set('visits_days',
                (string) ($days > 0 ? max(7, min(730, $days)) : \AfricaGates\Services\VisitTracker::KEEP_DAYS),
                $adminId);
        }

        // Whether the reminder run sends anything. NOT in the echo list above: an
        // unchecked box posts NOTHING, so `array_key_exists` is false and the loop would
        // leave the old '1' in place — a switch that can be turned on and never off. The
        // day-marks field always posts, even empty, so its presence is what marks this
        // section as submitted, exactly as the automation and voting-integrity cards do.
        if (array_key_exists('invite_reminder_days', $b)) {
            $this->settings->set('invite_reminder_enabled',
                                 !empty($b['invite_reminder_enabled']) ? '1' : '0', $adminId);
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

        // ── REFERRAL TERMS ──────────────────────────────────────────────────
        //
        // Marker-gated like the sections above, so a save from a form without this panel
        // cannot rewrite what people earn.
        //
        // The two numbers behave differently and the screen says so. The RATE is stamped
        // onto every credit row when it is earned, so changing it governs future referrals
        // and leaves settled balances untouched. The THRESHOLD is evaluated live, so
        // lowering it makes balances payable that were locked and raising it locks
        // balances that were payable — real money owed to real people, which is why it is
        // clamped and why the help text beside it is blunt.
        if (array_key_exists('referral_settings', $b)) {
            $this->settings->set('referrals_enabled', !empty($b['referrals_enabled']) ? '1' : '0', $adminId);

            // A percentage on screen, basis points in storage: an operator thinks in "10%",
            // and the money is computed with intdiv on bps so it can never round up.
            $pct = (float) str_replace(',', '.', trim((string) ($b['referral_rate_pct'] ?? '')));
            if ($pct >= 0 && $pct <= 50) {
                $this->settings->set('referral_rate_bps', (string) (int) round($pct * 100), $adminId);
            }

            $threshold = (int) ($b['referral_threshold'] ?? 0);
            if ($threshold >= 1 && $threshold <= 1000) {
                $this->settings->set('referral_threshold', (string) $threshold, $adminId);
            }
        }

        // ── WHERE EACH KIND OF MONEY SETTLES ────────────────────────────────
        //
        // Ticket money, shop money and vote money are three kinds of money to whoever has to
        // account for them, and a subaccount answers "how much of this is ticket income" at the
        // BANK rather than from our own records. See PaymentDestination.
        //
        // A marker field gates the block, so a save from any other section cannot clear the
        // routing. And a malformed code is REFUSED and reported rather than stored: Paystack
        // rejects an initialise with a bad subaccount, so a typo here would take that stream's
        // payments offline with no visible cause. Refusing to route is recoverable; refusing to
        // sell is not.
        if (array_key_exists('payout_settings', $b)) {
            $codes = [];
            $bearers = [];
            foreach (array_keys(\AfricaGates\Services\PaymentDestination::STREAMS) as $stream) {
                if (array_key_exists('sub_' . $stream, $b)) {
                    $codes[$stream] = (string) $b['sub_' . $stream];
                }
                $bearers[$stream] = (string) ($b['bearer_' . $stream] ?? 'account');
            }
            // Paystack is ASKED whether each code exists on this integration, not merely
            // pattern-matched. A well-formed code from somebody else's integration is the
            // failure that actually takes a stream offline, and shape validation cannot see
            // it — see PaymentDestination::reportRefusal().
            $r = \AfricaGates\Services\PaymentDestination::save(
                $codes, $bearers, new \AfricaGates\Services\PaymentService()
            );

            if ($r['refused'] !== []) {
                $lines = [];
                foreach ($r['refused'] as $stream => $why) {
                    $lines[] = (\AfricaGates\Services\PaymentDestination::STREAMS[$stream] ?? $stream)
                             . ': ' . $why;
                }
                // Paystack's own words, per stream, and the old value kept. "Subaccount not
                // found" tells an operator what to do; "invalid" does not.
                $_SESSION['flash_error'] = 'Not saved — ' . implode(' · ', $lines)
                    . ' The previous setting has been kept.';
            }
            if (($r['checked'] ?? []) !== []) {
                // The business name Paystack has against the code. An operator who pasted the
                // wrong one recognises the wrong name far faster than the wrong code.
                $ok = [];
                foreach ($r['checked'] as $stream => $who) {
                    $ok[] = (\AfricaGates\Services\PaymentDestination::STREAMS[$stream] ?? $stream)
                          . ' → ' . $who;
                }
                $verified = 'Verified with Paystack: ' . implode(' · ', $ok);
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
            // Only when the editor actually posted rows. The tier inputs are rendered
            // by Alpine's x-for, so on a browser where that script did not run there
            // are no `vote_tier_qty[]` fields in the body at all — and treating that
            // absence as "the admin cleared the ladder" would reprice the whole site
            // to defaults for someone who came here to change the announcement banner.
            $tiers = $this->tiersJson($b);
            if ($tiers !== null) $this->settings->set('vote_tiers', $tiers, $adminId);
            // Largest quantity ONE order may carry. Clamped to the hard ceiling here AND
            // in PaidVoteService::maxQty(), because this value ends up in a public vote
            // tally and a settings row is not a trusted input just because an admin typed
            // it — a stray zero is one keystroke away from a nine-figure order.
            $this->settings->set('vote_max_qty', (string) max(1, min(
                \AfricaGates\Services\PaidVoteService::HARD_MAX_QTY,
                (int) ($b['vote_max_qty'] ?? \AfricaGates\Services\PaidVoteService::DEFAULT_MAX_QTY)
            )), $adminId);

            // ── THE TWO TIMING WINDOWS, NOW REACHABLE WITHOUT A SHELL ────────
            //
            // Both already existed and both were read from settings; neither had a
            // field, so in practice they were constants nobody could change.
            //
            // The grace window is the one that matters, and it is not cosmetic. It
            // decides whether an order confirmed AFTER a cycle closed can still be
            // delivered — which is exactly the question a backlog recovery runs
            // into. At the 6-hour default, a stranded payment from a cycle that
            // closed yesterday refuses with CONFIRMED_TOO_LATE and there is no way,
            // short of a database client, for the operator to say "these were paid
            // on time, deliver them".
            //
            // Clamped to the same ceilings the service applies (168 hours, 240
            // minutes), because a settings row is not a trusted input just because
            // an admin typed it.
            if (array_key_exists('paid_vote_grace_hours', $b)) {
                $this->settings->set('paid_vote_grace_hours',
                    (string) max(0, min(168, (int) $b['paid_vote_grace_hours'])), $adminId);
            }
            if (array_key_exists('paid_vote_cutoff_minutes', $b)) {
                $this->settings->set('paid_vote_cutoff_minutes',
                    (string) max(0, min(240, (int) $b['paid_vote_cutoff_minutes'])), $adminId);
            }
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
            // ElevenLabs — the voice on the nominee's questionnaire. The key is WRITE-ONLY
            // like every provider key above it; the voice and model ids are plain text and
            // are echoed back, because an operator has to be able to see which voice their
            // nominees are hearing without pasting it in again to find out.
            if (!empty($clear['voice'])) $this->settings->set('voice_elevenlabs_key', '', $adminId);
            else {
                $vk = trim((string) ($b['voice_elevenlabs_key'] ?? ''));
                if ($vk !== '') $this->settings->set('voice_elevenlabs_key', $vk, $adminId);
            }
            foreach (['voice_elevenlabs_voice', 'voice_elevenlabs_model',
                      'voice_elevenlabs_stt_model'] as $vKey) {
                if (array_key_exists($vKey, $b)) $this->settings->set($vKey, trim((string) $b[$vKey]), $adminId);
            }

            if (!empty($clear['make_key'])) $this->settings->set('gee_make_agent_key', '', $adminId);
            else {
                $mk = trim((string) ($b['gee_make_agent_key'] ?? ''));
                if ($mk !== '') $this->settings->set('gee_make_agent_key', $mk, $adminId);
            }
        }

        // ── GOOGLE CALENDAR / MEET / SHEETS: ONE APPS SCRIPT DEPLOYMENT ─────
        //
        // Marker-gated like every block above, so a save from another group cannot blank
        // the integration.
        //
        // The URL is REFUSED rather than stored when it is not a URL. A malformed /exec
        // address does not fail loudly: `curl` returns nothing, every action reports "the
        // Apps Script did not answer", and the operator goes looking at Google — which is
        // the wrong place, for weeks. Refusing to store it names the fault at the moment
        // it is made.
        if (($why = $this->saveGoogle($b, $adminId)) !== null) {
            $_SESSION['flash_error'] = $why;
        }

        // Messaging (SMS / WhatsApp) — Twilio + WhatsApp Business Cloud API.
        // Secrets are WRITE-ONLY (never rendered back); a blank field leaves the
        // stored value untouched, the matching "clear" box removes it. Master
        // toggles are OFF by default; unconfigured = the gateway stays inert.
        if (array_key_exists('messaging_settings', $b)) {
            $this->settings->set('sms_enabled', isset($b['sms_enabled']) ? '1' : '', $adminId);
            $this->settings->set('wa_enabled',  isset($b['wa_enabled'])  ? '1' : '', $adminId);
            $clear = (array) ($b['messaging_clear'] ?? []);
            foreach (['sms_twilio_sid', 'sms_twilio_token', 'wa_access_token',
                      'sms_at_api_key', 'sms_termii_api_key'] as $k) {
                if (!empty($clear[$k])) { $this->settings->set($k, '', $adminId); continue; }
                $val = trim((string) ($b[$k] ?? ''));
                if ($val !== '') $this->settings->set($k, $val, $adminId);
            }
            foreach (['sms_twilio_from', 'sms_twilio_wa_from', 'wa_phone_number_id',
                      'sms_at_username', 'sms_at_from', 'sms_termii_from'] as $k) {
                if (array_key_exists($k, $b)) $this->settings->set($k, trim((string) $b[$k]), $adminId);
            }

            // ── THE TEXT SOMEBODY GETS FOR WALKING IN ───────────────────────
            //
            // Off by default like every outbound channel here: an upgrade must never
            // start texting an existing event's attendees because a new feature shipped.
            //
            // The copy is editable because it is the platform speaking in its own voice to
            // somebody who has just paid and travelled, and a sentence written here in
            // English by a developer is not that. What an operator CANNOT edit is the
            // "Reply STOP" line — CheckInThanks appends it after the template, so a
            // rewrite cannot remove the way out of it.
            $this->settings->set(\AfricaGates\Services\CheckInThanks::K_ENABLED,
                                 isset($b['checkin_sms_enabled']) ? '1' : '', $adminId);
            if (array_key_exists('checkin_sms_template', $b)) {
                $this->settings->set(\AfricaGates\Services\CheckInThanks::K_TEMPLATE,
                                     trim((string) $b['checkin_sms_template']), $adminId);
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

        // ── Community return ─────────────────────────────────────────────────
        //
        // Unlike everything above it, this does NOT live in gates_settings. The
        // share is a scoring rule: it has to be overridable per programme and per
        // cycle, and it has to be readable by the same RuleEngine the accrual
        // consults — otherwise the admin edits one number and the ledger uses
        // another. So it is written as a GLOBAL rule set.
        //
        // MERGED, never replaced. RuleEngine::set() writes the whole JSON document
        // for a scope, and the global scope also carries the CPI weights, the fraud
        // bands and the judge quorum. Writing only these three keys would erase the
        // rest — the entire scoring configuration, silently, from a form about
        // revenue sharing.
        if (array_key_exists('community_return_settings', $b)) {
            $engine  = new \AfricaGates\Services\RuleEngine();
            $current = [];
            try {
                $row = \Illuminate\Database\Capsule\Manager::table('gates_rule_sets')
                    ->where('scope', 'global')->whereNull('scope_id')->value('rules');
                $decoded = json_decode((string) $row, true);
                if (is_array($decoded)) $current = $decoded;
            } catch (\Throwable) {}

            // Percent in the form, basis points in the store. An admin thinks in
            // "30%"; the accrual needs an integer it can multiply without a float
            // ever touching money. Two decimal places, so 12.5% is expressible.
            $pct = max(0.0, min(100.0, (float) ($b['community_return_pct'] ?? 30)));

            $engine->set('global', null, array_merge($current, [
                'community_return_bps'               => (int) round($pct * 100),
                'community_return_vote_threshold'    => max(1, (int) ($b['community_return_vote_threshold'] ?? 250)),
                // Clamped here AND in the service, because a settings row is not a
                // trusted input just because an admin typed it: 0 would lock every
                // nominee out of qualifying forever, and >100 would stop being a cap.
                'community_return_supporter_cap_pct' => max(1, min(100, (int) ($b['community_return_supporter_cap_pct'] ?? 10))),
            ]));
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
        // The subaccount confirmation rides along with the generic message rather than being
        // set earlier and clobbered by it — which is what happened the first time.
        $_SESSION['flash_ok'] = 'Settings saved.'
            . (isset($verified) ? ' ' . $verified : '');

        // Back to the GROUP they saved from. A browser drops the URL fragment when it
        // posts a form, so the page cannot preserve its own tab across the redirect —
        // only the server can put it back. Without this, grouping the settings meant
        // every save returned the admin to the first tab, several clicks from the field
        // they were in the middle of adjusting.
        //
        // Allowlisted, not echoed: this value reaches a Location header, and a posted
        // string is not somewhere to take a URL fragment from on trust.
        $section = (string) ($b['st_section'] ?? '');
        $anchor  = in_array($section, ['site', 'mail', 'money', 'integrity', 'ai', 'ops'], true)
            ? '#' . $section
            : '';

        // ── A PROBE BUTTON SAVES THE WHOLE PAGE FIRST ───────────────────────
        //
        // Both probe buttons used to post to their own route, which stored nothing. So
        // pressing "Check the sync" after editing anything on this page discarded the edit
        // silently — the redirect reloads, the unsaved-changes tracker resets with it, and
        // there is no moment at which the operator is told. Worse, the verdict then
        // described the configuration they had just replaced.
        //
        // Routed through here instead: everything saves, then the probe runs against what
        // was saved. Allowlisted rather than dispatched from the posted string.
        $probe = (string) ($b['probe'] ?? '');
        if ($probe === 'sync') return $this->probeSync($req, $res);
        if ($probe === 'ai')   return $this->probeAi($req, $res);

        return $res->withHeader('Location', '/admin/settings' . $anchor)->withStatus(302);
    }

    /**
     * Normalise the posted tier rows into the JSON `vote_tiers` string.
     *
     * ── WHY THE FORM POSTS ROWS AND NOT JSON ─────────────────────────────────
     *
     * The ladder is read on the public ballot on every page view. A textarea of raw
     * JSON puts one missing brace between an admin and the site quietly reverting to
     * default pricing — and because {@see \AfricaGates\Services\PaidVoteService::tiers()}
     * degrades to defaults rather than throwing (which is right for the ballot), the
     * admin would get no error at all. So the form posts `vote_tier_qty[]` /
     * `vote_tier_off[]` and this method is the only thing that ever writes the JSON.
     *
     * Everything invalid is DROPPED rather than rejected, matching the reader: a blank
     * row is how you delete a tier, so an empty qty cannot be an error message. What is
     * kept is clamped — the percentage to 0–90 (a 100% discount is a ₦0 order no gateway
     * will take) and the quantity to the same hard ceiling every other quantity obeys.
     *
     * Returns '' when rows were posted but none survived, which makes the reader fall
     * back to DEFAULT_TIERS — "no ladder configured" and "the default ladder" being the
     * same state is what lets an admin reset by clearing every row.
     *
     * Returns NULL when the editor did not post at all, which is a different thing and
     * must leave the stored ladder untouched. See the caller.
     *
     * @param array<string,mixed> $b the parsed request body
     */
    private function tiersJson(array $b): ?string
    {
        if (!array_key_exists('vote_tier_qty', $b)) return null;

        $qtys = (array) ($b['vote_tier_qty'] ?? []);
        $offs = (array) ($b['vote_tier_off'] ?? []);

        $rows = [];
        foreach ($qtys as $i => $rawQty) {
            $qty = (int) $rawQty;
            if ($qty < 1) continue; // a cleared row is a deleted tier
            $qty = min(\AfricaGates\Services\PaidVoteService::HARD_MAX_QTY, $qty);
            // Keyed by quantity, so two rows for the same qty collapse to the last one
            // typed instead of producing a ladder with an unreachable rung.
            $rows[$qty] = ['qty' => $qty, 'off' => max(0, min(90, (int) ($offs[$i] ?? 0)))];
        }
        if (!$rows) return '';

        ksort($rows);
        return (string) json_encode(array_values($rows));
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

    /**
     * Re-verify stale pending payments against the gateway, right now.
     *
     * WHY THIS IS ITS OWN BUTTON. "I paid and my votes did not appear" is the one
     * support message that costs trust rather than time, and the answer to it is a
     * single task — payments:reconcile — inside a maintenance pass that also drains
     * queues, prunes caches and can recompute the CPI. Making an operator run all of
     * that, and wait for it, to answer one supporter is the reason it does not get
     * run. This does the one thing and reports the count.
     *
     * It is the same idempotent reconciliation the scheduler uses: it asks the gateway
     * what actually happened and only the single winning `WHERE status='pending'`
     * UPDATE credits an order, so pressing it twice cannot double-credit anyone.
     *
     * A bank transfer to Paystack's checkout account settles minutes after the buyer
     * leaves the site, long after the callback would have fired — so those orders are
     * ALWAYS reconciled rather than confirmed live. That makes this the normal path
     * for transfer payments, not an exception.
     */
    public function reconcilePayments(Request $req, Response $res): Response
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        try {
            $r = (new \AfricaGates\Support\Maintenance(null, false))->run('payments');
            $failed = ($r['failures'] ?? []) !== [];
            $lines  = array_slice((array) ($r['lines'] ?? []), -6);
            $_SESSION[$failed ? 'flash_error' : 'flash_ok'] = ($failed
                ? 'Reconciliation reported a problem — '
                : 'Payments reconciled in ' . (int) ($r['runtime_ms'] ?? 0) . 'ms. ')
                . ($lines ? implode(' · ', array_map('strval', $lines)) : 'No stale pending orders were waiting.');
        } catch (\Throwable $e) {
            error_log('[settings reconcile] ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not reconcile: ' . $e->getMessage();
        }
        try { $this->audit->record($adminId, 'settings.reconcile_payments', null, null); } catch (\Throwable) {}
        return $res->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    /**
     * Make one live AI call and report which provider answered — diagnoses "AI doesn't work".
     *
     * This is the only way to read the provider's own refusal on this deployment,
     * because there is no shell to tail a log with. So it reports EVERY hop that
     * failed rather than one of them, and the action each code implies, and it
     * records both in the audit trail — otherwise the answer scrolls away with the
     * flash message and the next person starts from nothing.
     */
    public function testAi(Request $req, Response $res): Response
    {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $r = \AfricaGates\Services\AiService::boot()->selfTest();

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? sprintf('AI OK — answered by %s (%s).', $r['provider'] ?? '?', $r['model'] ?? '?')
            // The provider's own words first, then what to change. Never only the
            // interpretation: a guess that displaces the evidence is how somebody
            // rotates a working key while the real fault is an egress block.
            : 'AI test failed. Tried: ' . ($r['error'] ?? 'no response')
              . (($r['cause'] ?? null) !== null ? ' — ' . $r['cause'] : '');

        try {
            $this->audit->record($adminId, 'settings.ai_test', null, null, [
                'ok' => $r['ok'], 'provider' => $r['provider'],
                'hops' => $r['hops'] ?? [], 'cause' => $r['cause'] ?? null,
            ]);
        } catch (\Throwable) {}
        return $res->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    /**
     * Test EACH provider on its own, and show the four verdicts side by side.
     *
     * ── WHY THIS IS A SECOND BUTTON AND NOT A BETTER FIRST ONE ──────────────
     *
     * "Test AI now" walks the ladder and stops at the first provider that answers, which is
     * exactly how the platform behaves and exactly the wrong instrument for "Gemini is not
     * operational": a healthy Groq at the top means Gemini is never called and the console
     * reports a green tick. A provider can be unfunded, blocked by the host's egress firewall
     * or pinned to a decommissioned model for months, and the only symptom is that failover
     * quietly does nothing on the day the primary goes down.
     *
     * The result goes into the session rather than a flash string because four verdicts, four
     * models, four latencies and four causes are a table, and a table flattened into one
     * sentence is the thing nobody reads.
     */
    public function probeAi(Request $req, Response $res): Response
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $rows    = \AfricaGates\Services\AiService::boot()->probeAll();

        $_SESSION['ai_probe'] = $rows;

        $live = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['ok']));
        $set  = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['configured']));

        $_SESSION[$live === [] ? 'flash_error' : 'flash_ok'] = $set === []
            ? 'No provider key is configured, so there was nothing to test.'
            : sprintf('%d of %d configured provider%s answered. Each verdict is below.',
                      count($live), count($set), count($set) === 1 ? '' : 's');

        try {
            $this->audit->record($adminId, 'settings.ai_probe', null, null, ['rows' => $rows]);
        } catch (\Throwable) {}

        return $res->withHeader('Location', '/admin/settings#ai-probe')->withStatus(302);
    }

    /**
     * Store the two Apps Script values, from whichever button posted them.
     *
     * Shared with {@see probeSync()} deliberately. The probe button posts the same form, so
     * pressing "Check the sync" straight after pasting a secret has to test the secret the
     * operator is looking at — not the one that was stored before they typed. A probe that
     * reports the previous state as though it were current is the verdict they would act on,
     * and it is why there is no "save first" caveat on the button.
     *
     * @param array<string,mixed> $b
     * @return string|null a message for the operator, or null when there was nothing to say
     */
    private function saveGoogle(array $b, int $adminId): ?string
    {
        if (!array_key_exists('google_settings', $b)) return null;

        $why = null;

        if (array_key_exists('gas_url', $b)) {
            $url = trim((string) $b['gas_url']);
            // REFUSED rather than stored when malformed. A bad /exec address does not fail
            // loudly — curl returns nothing, every action reports "the Apps Script did not
            // answer", and the operator goes looking at Google, which is the wrong place.
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $this->settings->set('gas_url', $url, $adminId);
            } else {
                $why = 'The Apps Script address was not saved — “'
                     . mb_substr($url, 0, 80) . '” is not a URL. It should be the whole '
                     . '/exec link, starting https://script.google.com/. Everything else on '
                     . 'this page was saved.';
            }
        }

        // Write-only, and a blank field KEEPS the stored secret. An operator who opened this
        // page to correct the URL must not lose the secret by not retyping it.
        if (!empty($b['google_clear']['secret'])) {
            $this->settings->set('gas_secret', '', $adminId);
        } else {
            $sec = trim((string) ($b['gas_secret'] ?? ''));
            if ($sec !== '') $this->settings->set('gas_secret', $sec, $adminId);
        }

        return $why;
    }

    /**
     * Ask the Apps Script whether it is really there.
     *
     * Same shape as the AI probe above and for the same reason: every part of the Google
     * integration can be configured correctly and still not work, and from the .env file
     * all five failures look identical. The screen shows what was tried, what answered,
     * and the fix for each row.
     */
    public function probeSync(Request $req, Response $res): Response
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);

        // Whatever is in the boxes right now, stored BEFORE asking. See saveGoogle().
        $badUrl = $this->saveGoogle((array) $req->getParsedBody(), $adminId);

        $rows = \AfricaGates\Services\GoogleMeetService::boot()->probeAll();

        $_SESSION['sync_probe'] = $rows;

        $tested = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['tested']));
        $failed = array_values(array_filter($tested, static fn (array $r): bool => !$r['ok']));

        // A refused URL is reported ahead of the probe's own verdict: the rows below
        // describe the deployment that is still stored, and "nothing could be tested"
        // would send the operator hunting for a cause they have already been told.
        $_SESSION[$failed === [] && $badUrl === null ? 'flash_ok' : 'flash_error'] = $badUrl
            ?? ($tested === []
            ? 'Nothing could be tested — the address and the secret have to be set first.'
            : ($failed === []
                ? sprintf('All %d live check%s passed. Creating events is the one step that cannot be '
                          . 'tested without making one.', count($tested), count($tested) === 1 ? '' : 's')
                : sprintf('%d of %d live check%s failed. Each row below says what to change.',
                          count($failed), count($tested), count($tested) === 1 ? '' : 's')));

        try {
            $this->audit->record($adminId, 'settings.sync_probe', null, null, ['rows' => $rows]);
        } catch (\Throwable) {}

        return $res->withHeader('Location', '/admin/settings#sync-probe')->withStatus(302);
    }
}
