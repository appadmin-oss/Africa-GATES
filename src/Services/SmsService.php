<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
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
        // ── THE AFRICAN PROVIDERS ───────────────────────────────────────────
        //
        // Twilio charges roughly an order of magnitude more per message to a Nigerian
        // handset than either of these, and this platform's recipients are almost
        // entirely on African networks. At a few thousand check-in texts a season that
        // is the difference between a feature that runs and one an operator turns off.
        private readonly ?string $atUsername = null,
        private readonly ?string $atApiKey = null,
        private readonly ?string $atFrom = null,
        private readonly ?string $termiiApiKey = null,
        private readonly ?string $termiiFrom = null,
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
            $env = Env::get($envKey);
            return ($env !== null && $env !== '') ? (string) $env : null;
        };
        // One flag parser for env and Settings alike, so `on` cannot mean true here
        // and false in the two call sites that spelled the list out by hand.
        $flag = static fn(?string $v): bool => Env::truthy($v);
        return new self(
            $resolve('sms_twilio_sid', 'TWILIO_ACCOUNT_SID'),
            $resolve('sms_twilio_token', 'TWILIO_AUTH_TOKEN'),
            $resolve('sms_twilio_from', 'TWILIO_SMS_FROM'),
            $resolve('sms_twilio_wa_from', 'TWILIO_WA_FROM'),
            $resolve('wa_phone_number_id', 'WA_PHONE_NUMBER_ID'),
            $resolve('wa_access_token', 'WA_ACCESS_TOKEN'),
            $flag($resolve('sms_enabled', 'SMS_ENABLED')),
            $flag($resolve('wa_enabled', 'WA_ENABLED')),
            $resolve('sms_at_username', 'AT_USERNAME'),
            $resolve('sms_at_api_key', 'AT_API_KEY'),
            $resolve('sms_at_from', 'AT_SENDER_ID'),
            $resolve('sms_termii_api_key', 'TERMII_API_KEY'),
            $resolve('sms_termii_from', 'TERMII_SENDER_ID'),
        );
    }

    public function smsConfigured(): bool
    {
        return $this->smsEnabled && $this->smsProvider() !== null;
    }

    /**
     * Which gateway an SMS would go out through, cheapest-for-Africa first.
     *
     * ── THE ORDER IS THE DECISION ────────────────────────────────────────────
     *
     * Africa's Talking, then Termii, then Twilio. Not alphabetical and not
     * most-recently-added: it is roughly ascending cost per message to an African handset,
     * which is where essentially every recipient of this platform is. Twilio is last
     * because it is the expensive one, not because it is the worst — it is the only one of
     * the three that reaches a number anywhere on earth, which is exactly why it belongs
     * at the bottom as the fallback rather than off the list.
     *
     * An operator who wants a specific gateway configures only that one. Configuring two
     * is not ambiguous — it is a preference and a fallback, in that order.
     */
    public function smsProvider(): ?string
    {
        if ($this->atUsername && $this->atApiKey)               return 'africastalking';
        if ($this->termiiApiKey && $this->termiiFrom)           return 'termii';
        if ($this->twilioSid && $this->twilioToken && $this->twilioFrom) return 'twilio';

        return null;
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
            // WHICH gateway, not just whether one exists. An operator who has set up
            // Africa's Talking and left old Twilio keys in place needs to see which of the
            // two is actually spending their money.
            'sms_provider' => $this->smsProvider(),
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
     *
     * ── THE OPT-OUT IS CHECKED HERE, NOT AT THE CALL SITES ──────────────────
     *
     * Ten callers that must each remember to check means an eleventh — written next year
     * by somebody who has not read this file — that texts a person who asked twice to be
     * left alone. One check, in the one place every text passes through.
     *
     * @param bool $respectOptOut false for a reply somebody just asked for. Refusing to
     *                            send a login code to a number that once opted out of
     *                            EVENT texts locks a person out of their own account,
     *                            which is not what they asked for. Anything a person did
     *                            not individually request must leave this true.
     */
    public function sendSms(string $toE164, string $body, string $template = 'generic',
                            bool $respectOptOut = true): bool
    {
        if (!$this->smsConfigured() || Phone::normalize($toE164) === null) return false;
        if ($respectOptOut && SmsOptOut::suppressed($toE164)) return false;
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
    public function sendWhatsApp(string $toE164, string $body, string $template = 'generic',
                                bool $respectOptOut = true): bool
    {
        if (!$this->whatsappConfigured() || Phone::normalize($toE164) === null) return false;
        // One list for both channels: somebody who asks to stop being texted has not asked
        // to be reached the same way on a different wire.
        if ($respectOptOut && SmsOptOut::suppressed($toE164)) return false;
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
        return match ($this->smsProvider()) {
            'africastalking' => $this->attemptAfricasTalking($to, $body),
            'termii'         => $this->attemptTermii($to, $body),
            default          => $this->attemptTwilio($to, $body),
        };
    }

    /** @return array{0:string,1:bool,2:?string,3:?string} */
    private function attemptTwilio(string $to, string $body): array
    {
        $resp = $this->http(
            "https://api.twilio.com/2010-04-01/Accounts/{$this->twilioSid}/Messages.json",
            ['Content-Type: application/x-www-form-urlencoded'],
            ['To' => $to, 'From' => (string) $this->twilioFrom, 'Body' => $body],
            $this->twilioSid . ':' . $this->twilioToken,
        );
        return $this->twilioOutcome('twilio', $resp);
    }

    /**
     * Africa's Talking.
     *
     * ── TWO THINGS THAT ARE NOT LIKE THE OTHERS ─────────────────────────────
     *
     * The key travels in an `apiKey` HEADER, not a bearer token and not in the body. And
     * it answers 201 on success, so a `=== 200` check rejects every message it delivers.
     *
     * More importantly, it answers 201 for a message it did NOT send: the per-recipient
     * outcome is inside `SMSMessageData.Recipients[].status`, and a wrong number or an
     * empty account comes back as HTTP 201 with "InvalidPhoneNumber" or
     * "InsufficientBalance" in that array. Reading only the HTTP code marks those as
     * delivered, and the platform would report an unpaid account as a season of sent
     * texts.
     *
     * `from` is the sender ID and is OPTIONAL — an unregistered alphanumeric ID is
     * rejected on many African networks, so a deployment that has not registered one is
     * better off omitting it and going out on the shared shortcode.
     *
     * @return array{0:string,1:bool,2:?string,3:?string}
     */
    private function attemptAfricasTalking(string $to, string $body): array
    {
        $form = [
            'username' => (string) $this->atUsername,
            'to'       => $to,
            'message'  => $body,
        ];
        if ((string) $this->atFrom !== '') $form['from'] = (string) $this->atFrom;

        $resp = $this->http(
            'https://api.africastalking.com/version1/messaging',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
             'apiKey: ' . $this->atApiKey],
            $form,
            null,
        );

        $j = json_decode($resp['body'], true);
        $r = is_array($j) ? ($j['SMSMessageData']['Recipients'][0] ?? null) : null;

        // "Success" and "Sent" are both accepted states; anything else is the reason.
        $status = is_array($r) ? (string) ($r['status'] ?? '') : '';
        $ok     = $resp['code'] >= 200 && $resp['code'] < 300
               && in_array(strtolower($status), ['success', 'sent'], true);

        $ref = is_array($r) ? ($r['messageId'] ?? null) : null;

        return ['africastalking', $ok, is_string($ref) ? substr($ref, 0, 80) : null,
                $ok ? null : ($status !== '' ? $status : $this->errorFrom($resp))];
    }

    /**
     * Termii.
     *
     * JSON, with the key in the body rather than a header. Same trap as above and worse:
     * it answers 200 with a `message` describing the failure, so the HTTP code alone is
     * almost never the answer. A successful send carries a `message_id`, which is the one
     * field that cannot be present on a failure — so that is what is checked.
     *
     * `from` is REQUIRED and must be a sender ID already approved on the account. That is
     * why {@see smsProvider()} does not consider Termii configured without one: a key with
     * no sender ID produces a confident 200 and no message, on every send, forever.
     *
     * @return array{0:string,1:bool,2:?string,3:?string}
     */
    private function attemptTermii(string $to, string $body): array
    {
        $resp = $this->http(
            'https://api.ng.termii.com/api/sms/send',
            ['Content-Type: application/json', 'Accept: application/json'],
            (string) json_encode([
                'api_key' => $this->termiiApiKey,
                // No leading +. Termii wants the international number as digits, and a
                // plus is quietly treated as a malformed recipient.
                'to'      => ltrim($to, '+'),
                'from'    => (string) $this->termiiFrom,
                'sms'     => $body,
                'type'    => 'plain',
                'channel' => 'generic',
            ]),
            null,
        );

        $j   = json_decode($resp['body'], true);
        $ref = is_array($j) ? ($j['message_id'] ?? null) : null;
        $ok  = $resp['code'] >= 200 && $resp['code'] < 300 && is_string($ref) && $ref !== '';

        $why = is_array($j) ? (string) ($j['message'] ?? '') : '';

        return ['termii', $ok, is_string($ref) ? substr($ref, 0, 80) : null,
                $ok ? null : ($why !== '' ? $why : $this->errorFrom($resp))];
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

    /**
     * Is this inbound POST really from Twilio?
     *
     * ── WHY THIS CANNOT BE SKIPPED ───────────────────────────────────────────
     *
     * The endpoint it guards records opt-outs from the `From` number on the request. An
     * unsigned version is a form anybody on the internet can submit to stop somebody
     * else's messages — including the claim codes and security alerts this platform sends
     * — and the victim would have no way to tell it had happened.
     *
     * ── THE ALGORITHM, BECAUSE IT IS EASY TO GET SUBTLY WRONG ────────────────
     *
     * Twilio signs the full request URL with the POST fields appended: keys sorted
     * lexicographically, each key immediately followed by its value, no separators. That
     * string is HMAC-SHA1'd with the ACCOUNT AUTH TOKEN — not the API key, not the SID —
     * and base64'd.
     *
     * The URL must be exactly the one Twilio was configured with, including scheme, host
     * and query string. Behind a proxy that terminates TLS, `https` vs `http` is the
     * commonest reason a correct implementation rejects every request.
     *
     * hash_equals, not `===`: this is a MAC comparison and a timing-variable one leaks the
     * signature a byte at a time.
     *
     * @param array<string,mixed> $params the POST fields, exactly as received
     */
    public function validateWebhook(string $url, array $params, string $signature): bool
    {
        if ($this->twilioToken === null || $this->twilioToken === '' || $signature === '') {
            return false;
        }

        ksort($params, SORT_STRING);
        $payload = $url;
        foreach ($params as $k => $v) {
            $payload .= $k . (is_scalar($v) ? (string) $v : '');
        }

        $expected = base64_encode(hash_hmac('sha1', $payload, $this->twilioToken, true));

        return hash_equals($expected, $signature);
    }

    /** Is there a token to validate an inbound request against at all? */
    public function canValidateWebhook(): bool
    {
        return $this->twilioToken !== null && $this->twilioToken !== '';
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
