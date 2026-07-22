<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SmsService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * SMS / WhatsApp gateway — mirrors the AiService contract: inert when
 * unconfigured, boots from gates_settings with .env fallback, off-by-default
 * master toggles, and every real send is audited to gates_messages with the
 * recipient hashed + masked. Transport is injected so tests never hit Twilio
 * or Meta.
 */
final class SmsServiceTest extends TestCase
{
    /** @param array{code:int,body:string} $reply */
    private function svc(array $reply, array $overrides = [], ?array &$calls = null): SmsService
    {
        $calls ??= [];
        $transport = function (string $url, array $headers, $body, ?string $basicAuth) use (&$calls, $reply): array {
            $calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'auth' => $basicAuth];
            return $reply;
        };
        return new SmsService(
            twilioSid:   $overrides['twilioSid']   ?? 'AC123',
            twilioToken: $overrides['twilioToken'] ?? 'tok',
            twilioFrom:  $overrides['twilioFrom']  ?? '+15550001111',
            twilioWaFrom:$overrides['twilioWaFrom']?? null,
            waPhoneId:   $overrides['waPhoneId']   ?? null,
            waToken:     $overrides['waToken']     ?? null,
            smsEnabled:  $overrides['smsEnabled']  ?? true,
            waEnabled:   $overrides['waEnabled']   ?? true,
            transport:   $transport,
        );
    }

    public function test_inert_when_unconfigured(): void
    {
        $svc = new SmsService(null, null, null, null, null, null, false, false);
        $this->assertFalse($svc->smsConfigured());
        $this->assertFalse($svc->whatsappConfigured());
        $this->assertFalse($svc->configured());
        $this->assertFalse($svc->sendSms('+2348031234567', 'hello'));
        $this->assertSame(0, DB::table('gates_messages')->count());
    }

    public function test_toggle_off_disables_even_with_keys(): void
    {
        $svc = $this->svc(['code' => 201, 'body' => '{}'], ['smsEnabled' => false, 'waEnabled' => false]);
        $this->assertFalse($svc->smsConfigured());
        $this->assertFalse($svc->whatsappConfigured());
    }

    public function test_boot_reads_settings_with_env_fallback(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'sms_twilio_sid',   'value' => 'AC999'],
            ['key_name' => 'sms_twilio_token', 'value' => 'settings-token'],
            ['key_name' => 'sms_twilio_from',  'value' => '+15557779999'],
            ['key_name' => 'sms_enabled',      'value' => '1'],
        ]);
        $_ENV['WA_PHONE_NUMBER_ID'] = '112233';
        $_ENV['WA_ACCESS_TOKEN']    = 'env-wa-token';
        $_ENV['WA_ENABLED']         = '1';
        try {
            $svc = SmsService::boot();
            $this->assertTrue($svc->smsConfigured());
            $this->assertTrue($svc->whatsappConfigured());
            $this->assertSame('meta', $svc->status()['wa_provider']);
        } finally {
            unset($_ENV['WA_PHONE_NUMBER_ID'], $_ENV['WA_ACCESS_TOKEN'], $_ENV['WA_ENABLED']);
        }
    }

    public function test_sms_success_is_audited_hashed_and_masked(): void
    {
        $calls = [];
        $svc = $this->svc(['code' => 201, 'body' => '{"sid":"SM1"}'], [], $calls);
        $this->assertTrue($svc->sendSms('+2348031234567', 'Your nomination is in.', 'nomination_nominee'));

        $this->assertCount(1, $calls);
        $this->assertStringContainsString('api.twilio.com', $calls[0]['url']);
        $this->assertSame('AC123:tok', $calls[0]['auth']);

        $row = DB::table('gates_messages')->first();
        $this->assertSame('sms', $row->channel);
        $this->assertSame('sent', $row->status);
        $this->assertSame('twilio', $row->provider);
        $this->assertSame('nomination_nominee', $row->template);
        $this->assertSame(hash('sha256', '+2348031234567'), $row->to_hash);
        $this->assertStringNotContainsString('8031234', $row->to_masked);
        $this->assertSame('SM1', $row->provider_ref);
    }

    public function test_sms_failure_logs_and_queues_retry(): void
    {
        $svc = $this->svc(['code' => 500, 'body' => 'boom']);
        $this->assertFalse($svc->sendSms('+2348031234567', 'hello', 'generic'));

        $row = DB::table('gates_messages')->first();
        $this->assertSame('failed', $row->status);

        $job = DB::table('gates_jobs')->where('type', 'notify.sms')->first();
        $this->assertNotNull($job, 'failed send must enqueue a retry job');
        $payload = json_decode((string) $job->payload, true);
        $this->assertSame('+2348031234567', $payload['to']);
        $this->assertSame('hello', $payload['body']);
    }

    public function test_whatsapp_prefers_meta_cloud_api_over_twilio(): void
    {
        $calls = [];
        $svc = $this->svc(['code' => 200, 'body' => '{"messages":[{"id":"wamid.X"}]}'], [
            'waPhoneId' => '112233', 'waToken' => 'wa-tok', 'twilioWaFrom' => '+15550001111',
        ], $calls);
        $this->assertSame('meta', $svc->status()['wa_provider']);
        $this->assertTrue($svc->sendWhatsApp('+2348031234567', 'WA hello', 'generic'));
        $this->assertStringContainsString('graph.facebook.com', $calls[0]['url']);

        $row = DB::table('gates_messages')->first();
        $this->assertSame('whatsapp', $row->channel);
        $this->assertSame('meta', $row->provider);
    }

    public function test_whatsapp_via_twilio_when_meta_absent(): void
    {
        $calls = [];
        $svc = $this->svc(['code' => 201, 'body' => '{"sid":"MM1"}'], ['twilioWaFrom' => '+15550001111'], $calls);
        $this->assertSame('twilio', $svc->status()['wa_provider']);
        $this->assertTrue($svc->sendWhatsApp('+2348031234567', 'WA hello'));
        $this->assertStringContainsString('api.twilio.com', $calls[0]['url']);
        // Twilio WhatsApp requires the whatsapp: address prefix on both sides.
        $this->assertStringContainsString('whatsapp%3A%2B234', http_build_query($calls[0]['body']));
    }

    public function test_invalid_recipient_is_refused_without_logging(): void
    {
        $svc = $this->svc(['code' => 201, 'body' => '{}']);
        $this->assertFalse($svc->sendSms('not-a-number', 'x'));
        $this->assertSame(0, DB::table('gates_messages')->count());
    }

    public function test_channel_plan_matrix(): void
    {
        $both = $this->svc(['code' => 201, 'body' => '{}'], ['twilioWaFrom' => '+15550001111']);
        // Email only → email flow only.
        $this->assertSame(['email'], SmsService::channelPlan('a@b.co', null, $both));
        // Phone only, SMS + WA configured → SMS first, then WhatsApp.
        $this->assertSame(['sms', 'whatsapp'], SmsService::channelPlan(null, '+2348031234567', $both));
        // Both contact points → email + SMS + WhatsApp (WA always sends when configured).
        $this->assertSame(['email', 'sms', 'whatsapp'], SmsService::channelPlan('a@b.co', '+2348031234567', $both));

        $waOnly = $this->svc(['code' => 201, 'body' => '{}'], ['smsEnabled' => false, 'twilioWaFrom' => '+15550001111']);
        $this->assertSame(['whatsapp'], SmsService::channelPlan(null, '+2348031234567', $waOnly));

        $none = new SmsService(null, null, null, null, null, null, false, false);
        $this->assertSame([], SmsService::channelPlan(null, '+2348031234567', $none));
        $this->assertSame(['email'], SmsService::channelPlan('a@b.co', '+2348031234567', $none));
    }
}
