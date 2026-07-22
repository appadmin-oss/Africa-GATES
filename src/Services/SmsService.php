<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Phone;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Outbound SMS + WhatsApp gateway for Africa GATES.
 *
 * Mirrors the AiService contract: boots from admin settings (gates_settings)
 * with .env fallback, is fully inert until a superadmin configures it, and
 * degrades gracefully — a failed or unconfigured send never breaks the
 * user-facing action that triggered it.
 *
 *   • SMS       — Twilio Messages API (E.164 recipients).
 *   • WhatsApp  — Meta WhatsApp Business Cloud API preferred; Twilio's
 *                 WhatsApp channel as the alternative. First configured wins.
 *
 * Delivery model: synchronous best-effort with a short timeout; a failure is
 * audited to gates_messages and re-queued as a notify.sms / notify.whatsapp
 * job (QueueService retries with backoff, 5 attempts). Recipients are stored
 * hashed + masked — raw numbers never land in the audit table. Master toggles
 * (sms_enabled / wa_enabled) are OFF by default.
 */
class SmsService
{
    public const JOB_SMS      = 'notify.sms';
    public const JOB_WHATSAPP = 'notify.whatsapp';

    /** @var null|\Closure(string,array,array|string,?string):array{code:int,body:string} */
    private ?\Closure $transport;

    public function __construct(
        private readonly ?string $twilioSid = null,
        private readonly ?string $twilioToken = null,
        private readonly ?string $twilioFrom = null,
        private readonly ?string $twilioWaFrom = null,
        private readonly ?string $waPhoneId = null,
        private readonly ?string $waToken = null,
        private readonly bool $smsEnabled = false,
        private readonly bool $waEnabled = false,
        private readonly int $timeout = 8,
        ?callable $transport = null,
    ) {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    /** Build from admin settings (gates_settings) with .env fallback. */
    public static function boot(): self
    {
        $resolve = static function (string $settingKey, string $envKey): ?string {
            $v = null;
            try { $v = DB::table('gates_settings')->where('key_name', $settingKey)->value('value'); }
            catch (\Throwable) {}
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '') return $v;
            $env = $_ENV[$envKey] ?? null;
            return ($env !== null && $env !== '') ? (string) $env : null;
        };
        $flag = static fn(?string $v): bool => in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
        return new self(
            $resolve('sms_twilio_sid', 'TWILIO_ACCOUNT_SID'),
            $resolve('sms_twilio_token', 'TWILIO_AUTH_TOKEN'),
            $resolve('sms_twilio_from', 'TWILIO_SMS_FROM'),
            $resolve('sms_twilio_wa_from', 'TWILIO_WA_FROM'),
            $resolve('wa_phone_number_id', 'WA_PHONE_NUMBER_ID'),
            $resolve('wa_access_token', 'WA_ACCESS_TOKEN'),
            $flag($resolve('sms_enabled', 'SMS_ENABLED')),
            $flag($resolve('wa_enabled', 'WA_ENABLED')),
        );
    }

    public function smsConfigured(): bool
    {
        return $this->smsEnabled && $this->twilioSid && $this->twilioToken && $this->twilioFrom;
    }

    /** 'meta' | 'twilio' | null — which WhatsApp transport would be used. */
    public function whatsappProvider(): ?string
    {
        if (!$this->waEnabled) return null;
        if ($this->waPhoneId && $this->waToken) return 'meta';
        if ($this->twilioSid && $this->twilioToken && $this->twilioWaFrom) return 'twilio';
        return null;
    }

    public function whatsappConfigured(): bool
    {
        return $this->whatsappProvider() !== null;
    }

    public function configured(): bool
    {
        return $this->smsConfigured() || $this->whatsappConfigured();
    }

    /** Configured flags for the admin status panel (never exposes secrets). */
    public function status(): array
    {
        return [
            'sms'         => $this->smsConfigured(),
            'whatsapp'    => $this->whatsappConfigured(),
            'wa_provider' => $this->whatsappProvider(),
            'sms_enabled' => $this->smsEnabled,
            'wa_enabled'  => $this->waEnabled,
        ];
    }

    /**
     * Channel plan for a contact: which channels to use, in send order.
     * Rules (product spec): email when present; SMS when a phone is present
     * and Twilio SMS is configured; WhatsApp ALWAYS when a phone is present
     * and WhatsApp is configured. Both providers configured → SMS first,
     * then WhatsApp.
     *
     * @return list<'email'|'sms'|'whatsapp'>
     */
    public static function channelPlan(?string $email, ?string $phoneE164, self $sms): array
    {
        $plan = [];
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) $plan[] = 'email';
        if ($phoneE164 !== null && Phone::isValid($phoneE164)) {
            if ($sms->smsConfigured())      $plan[] = 'sms';
            if ($sms->whatsappConfigured()) $plan[] = 'whatsapp';
        }
        return $plan;
    }

    /**
     * Best-effort SMS: audited, never throws; failure enqueues a retry job.
     */
    public function sendSms(string $toE164, string $body, string $template = 'generic'): bool
    {
        if (!$this->smsConfigured() || Phone::normalize($toE164) === null) return false;
        try {
            $this->deliver('sms', $toE164, $body, $template);
            return true;
        } catch (\Throwable) {
            $this->enqueueRetry(self::JOB_SMS, $toE164, $body, $template);
            return false;
        }
    }

    /**
     * Best-effort WhatsApp: audited, never throws; failure enqueues a retry job.
     */
    public function sendWhatsApp(string $toE164, string $body, string $template = 'generic'): bool
    {
        if (!$this->whatsappConfigured() || Phone::normalize($toE164) === null) return false;
        try {
            $this->deliver('whatsapp', $toE164, $body, $template);
            return true;
        } catch (\Throwable) {
            $this->enqueueRetry(self::JOB_WHATSAPP, $toE164, $body, $template);
            return false;
        }
    }

    /**
     * Single delivery attempt — audits the outcome and THROWS on failure.
     * Queue job handlers call this directly so QueueService owns the retry
     * schedule (no re-enqueue loops).
     */
    public function deliver(string $channel, string $toE164, string $body, string $template = 'generic'): void
    {
        $to = Phone::normalize($toE164);
        if ($to === null) throw new \InvalidArgumentException('Recipient is not E.164.');

        [$provider, $ok, $ref, $error] = $channel === 'whatsapp'
            ? $this->attemptWhatsApp($to, $body)
            : $this->attemptSms($to, $body);

        $this->audit($channel, $to, $template, $ok ? 'sent' : 'failed', $provider, $ref, $error);
        if (!$ok) throw new \RuntimeException($error ?? 'delivery failed');
    }

    // ── Provider attempts ──────────────────────────────────────────────────

    /** @return array{0:string,1:bool,2:?string,3:?string} provider, ok, ref, error */
    private function attemptSms(string $to, string $body): array
    {
        $resp = $this->http(
            "https://api.twilio.com/2010-04-01/Accounts/{$this->twilioSid}/Messages.json",
            ['Content-Type: application/x-www-form-urlencoded'],
            ['To' => $to, 'From' => (string) $this->twilioFrom, 'Body' => $body],
            $this->twilioSid . ':' . $this->twilioToken,
        );
        return $this->twilioOutcome('twilio', $resp);
    }

    /** @return array{0:string,1:bool,2:?string,3:?string} */
    private function attemptWhatsApp(string $to, string $body): array
    {
        if ($this->whatsappProvider() === 'meta') {
            $resp = $this->http(
                "https://graph.facebook.com/v20.0/{$this->waPhoneId}/messages",
                ['Content-Type: application/json', 'Authorization: Bearer ' . $this->waToken],
                (string) json_encode([
                    'messaging_product' => 'whatsapp',
                    'to'                => ltrim($to, '+'),
                    'type'              => 'text',
                    'text'              => ['preview_url' => false, 'body' => $body],
                ]),
                null,
            );
            $ok  = $resp['code'] >= 200 && $resp['code'] < 300;
            $j   = json_decode($resp['body'], true);
            $ref = is_array($j) ? ($j['messages'][0]['id'] ?? null) : null;
            return ['meta', $ok, is_string($ref) ? substr($ref, 0, 80) : null, $ok ? null : $this->errorFrom($resp)];
        }
        // Twilio WhatsApp channel — same Messages API with whatsapp: addressing.
        $resp = $this->http(
            "https://api.twilio.com/2010-04-01/Accounts/{$this->twilioSid}/Messages.json",
            ['Content-Type: application/x-www-form-urlencoded'],
            ['To' => 'whatsapp:' . $to, 'From' => 'whatsapp:' . $this->twilioWaFrom, 'Body' => $body],
            $this->twilioSid . ':' . $this->twilioToken,
        );
        return $this->twilioOutcome('twilio', $resp);
    }

    /** @param array{code:int,body:string} $resp @return array{0:string,1:bool,2:?string,3:?string} */
    private function twilioOutcome(string $provider, array $resp): array
    {
        $ok  = $resp['code'] >= 200 && $resp['code'] < 300;
        $j   = json_decode($resp['body'], true);
        $ref = is_array($j) ? ($j['sid'] ?? null) : null;
        return [$provider, $ok, is_string($ref) ? substr($ref, 0, 80) : null, $ok ? null : $this->errorFrom($resp)];
    }

    /** @param array{code:int,body:string} $resp */
    private function errorFrom(array $resp): string
    {
        return substr('HTTP ' . $resp['code'] . ' ' . preg_replace('/\s+/', ' ', $resp['body']), 0, 300);
    }

    // ── Audit + retry queue ────────────────────────────────────────────────

    private function audit(string $channel, string $toE164, string $template, string $status, ?string $provider, ?string $ref, ?string $error): void
    {
        try {
            DB::table('gates_messages')->insert([
                'channel'      => $channel,
                'to_hash'      => hash('sha256', $toE164),
                'to_masked'    => Phone::mask($toE164),
                'template'     => substr($template, 0, 60),
                'status'       => $status,
                'provider'     => $provider,
                'provider_ref' => $ref,
                'error'        => $error !== null ? substr($error, 0, 300) : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Audit must never break delivery or the calling flow.
        }
    }

    private function enqueueRetry(string $jobType, string $to, string $body, string $template): void
    {
        try {
            (new QueueService())->push($jobType, ['to' => $to, 'body' => $body, 'template' => $template], 60);
        } catch (\Throwable) {
            // Queue unavailable — the failed attempt is already audited.
        }
    }

    // ── HTTP transport (injectable for tests) ──────────────────────────────

    /**
     * @param array|string $body form fields (array) or raw JSON (string)
     * @return array{code:int,body:string}
     */
    private function http(string $url, array $headers, array|string $body, ?string $basicAuth): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $headers, $body, $basicAuth);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => is_array($body) ? http_build_query($body) : $body,
        ]);
        if ($basicAuth !== null) curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        $respBody = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        if ($respBody === false) return ['code' => 0, 'body' => 'curl: ' . $err];
        return ['code' => $code, 'body' => (string) $respBody];
    }
}
