<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SmsService;
use Tests\TestCase;

/**
 * The gateways a text can go out through, and the traps in two of them.
 *
 * ── WHY THESE WERE ADDED ─────────────────────────────────────────────────────
 *
 * Twilio charges roughly an order of magnitude more per message to a Nigerian handset
 * than Africa's Talking or Termii, and essentially every recipient of this platform is on
 * an African network. At a few thousand check-in texts a season that is the difference
 * between a feature that runs and one an operator switches off.
 *
 * ── AND WHY MOST OF THIS FILE IS ABOUT FAILURE ───────────────────────────────
 *
 * Both African gateways answer 2xx for messages they did NOT send. Africa's Talking puts
 * the real outcome in `SMSMessageData.Recipients[].status`, so an empty account comes back
 * as HTTP 201 with "InsufficientBalance"; Termii answers 200 with a `message` explaining
 * the failure and no `message_id`. An implementation that reads the HTTP code alone
 * reports a whole season of undelivered texts as sent — and nobody finds out, because the
 * thing that would have told them is the audit row that says "sent".
 */
final class SmsProvidersTest extends TestCase
{
    /** @var list<array{url:string,headers:array,body:mixed}> */
    private array $calls = [];

    /**
     * @param array<string,mixed> $config
     * @param array{code:int,body:string} $reply
     */
    private function service(array $config, array $reply): SmsService
    {
        $this->calls = [];

        return new SmsService(
            twilioSid:    $config['twilio_sid']    ?? null,
            twilioToken:  $config['twilio_token']  ?? null,
            twilioFrom:   $config['twilio_from']   ?? null,
            twilioWaFrom: null,
            waPhoneId:    null,
            waToken:      null,
            smsEnabled:   true,
            waEnabled:    false,
            atUsername:   $config['at_username']   ?? null,
            atApiKey:     $config['at_key']        ?? null,
            atFrom:       $config['at_from']       ?? null,
            termiiApiKey: $config['termii_key']    ?? null,
            termiiFrom:   $config['termii_from']   ?? null,
            transport: function (string $url, array $headers, $body, ?string $auth) use ($reply): array {
                $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

                return $reply;
            },
        );
    }

    private const AT_OK = ['code' => 201, 'body' => '{"SMSMessageData":{"Recipients":[{"status":"Success","messageId":"ATXid_9"}]}}'];
    private const TERMII_OK = ['code' => 200, 'body' => '{"message_id":"tm-42","message":"Successfully Sent"}'];

    // ══ the ladder ═══════════════════════════════════════════════════════════

    /**
     * Cheapest for an African number wins, even with Twilio sitting there configured.
     *
     * An operator who adds Africa's Talking and leaves old Twilio keys in place must not
     * keep paying Twilio prices because nobody deleted a field.
     */
    public function test_the_cheapest_african_gateway_is_preferred_over_twilio(): void
    {
        $sms = $this->service([
            'at_username' => 'gates', 'at_key' => 'atsk_x',
            'twilio_sid' => 'AC1', 'twilio_token' => 't', 'twilio_from' => '+15550001',
        ], self::AT_OK);

        $this->assertSame('africastalking', $sms->smsProvider());
        $this->assertTrue($sms->sendSms('+2348012345678', 'hi', 'test'));
        $this->assertStringContainsString('africastalking.com', $this->calls[0]['url']);
    }

    /** Termii sits between them. */
    public function test_termii_is_preferred_over_twilio_and_not_over_africas_talking(): void
    {
        $both = $this->service([
            'termii_key' => 'TL1', 'termii_from' => 'AfricaGATES',
            'twilio_sid' => 'AC1', 'twilio_token' => 't', 'twilio_from' => '+15550001',
        ], self::TERMII_OK);
        $this->assertSame('termii', $both->smsProvider());

        $all = $this->service([
            'at_username' => 'gates', 'at_key' => 'atsk_x',
            'termii_key' => 'TL1', 'termii_from' => 'AfricaGATES',
        ], self::AT_OK);
        $this->assertSame('africastalking', $all->smsProvider());
    }

    /**
     * A Termii key with no sender ID is not a configured gateway.
     *
     * `from` is required and must already be approved on the account. Without one Termii
     * answers 200 and sends nothing, on every send, forever — so it is better to fall
     * through to a gateway that works than to look configured and be silent.
     */
    public function test_termii_without_a_sender_id_falls_through(): void
    {
        $sms = $this->service([
            'termii_key' => 'TL1',
            'twilio_sid' => 'AC1', 'twilio_token' => 't', 'twilio_from' => '+15550001',
        ], ['code' => 201, 'body' => '{"sid":"SM1"}']);

        $this->assertSame('twilio', $sms->smsProvider());
    }

    /** With nothing set up at all, nothing claims to be configured. */
    public function test_no_gateway_is_no_gateway(): void
    {
        $this->assertNull($this->service([], ['code' => 200, 'body' => '{}'])->smsProvider());
        $this->assertFalse($this->service([], ['code' => 200, 'body' => '{}'])->smsConfigured());
    }

    // ══ the wire ═════════════════════════════════════════════════════════════

    /** Africa's Talking wants the key in a header and the number with its plus. */
    public function test_africas_talking_is_called_the_way_it_expects(): void
    {
        $sms = $this->service(['at_username' => 'gates', 'at_key' => 'atsk_x', 'at_from' => 'AfricaGATES'],
                              self::AT_OK);
        $sms->sendSms('+2348012345678', 'hello', 'test');

        $call = $this->calls[0];
        $this->assertContains('apiKey: atsk_x', $call['headers'], 'the key is not in the header');
        $this->assertSame('gates', $call['body']['username']);
        $this->assertSame('+2348012345678', $call['body']['to']);
        $this->assertSame('AfricaGATES', $call['body']['from']);
    }

    /**
     * And omits the sender ID entirely when there is none.
     *
     * An empty `from` is not the same as no `from`: an unregistered alphanumeric ID is
     * rejected outright on many African networks, so a deployment without one is better
     * off on the shared shortcode than sending an empty string.
     */
    public function test_no_sender_id_means_the_field_is_absent_not_empty(): void
    {
        $sms = $this->service(['at_username' => 'gates', 'at_key' => 'atsk_x'], self::AT_OK);
        $sms->sendSms('+2348012345678', 'hello', 'test');

        $this->assertArrayNotHasKey('from', $this->calls[0]['body']);
    }

    /** Termii wants JSON, the key in the body, and the number without its plus. */
    public function test_termii_is_called_the_way_it_expects(): void
    {
        $sms = $this->service(['termii_key' => 'TL1', 'termii_from' => 'AfricaGATES'], self::TERMII_OK);
        $sms->sendSms('+2348012345678', 'hello', 'test');

        $body = json_decode((string) $this->calls[0]['body'], true);
        $this->assertSame('TL1', $body['api_key']);
        $this->assertSame('2348012345678', $body['to'], 'a leading + is a malformed recipient to Termii');
        $this->assertSame('AfricaGATES', $body['from']);
    }

    // ══ the 2xx that did not send ════════════════════════════════════════════

    /**
     * HTTP 201 with a per-recipient failure is a FAILURE.
     *
     * This is the one that would have gone unnoticed longest: an unpaid account answers
     * 201, and reading only the code records a season of undelivered texts as sent.
     */
    public function test_africas_talking_2xx_with_a_bad_recipient_status_is_a_failure(): void
    {
        foreach (['InsufficientBalance', 'InvalidPhoneNumber', 'UserInBlacklist'] as $status) {
            $sms = $this->service(['at_username' => 'gates', 'at_key' => 'atsk_x'], [
                'code' => 201,
                'body' => '{"SMSMessageData":{"Recipients":[{"status":"' . $status . '"}]}}',
            ]);

            $this->assertFalse($sms->sendSms('+2348012345678', 'hi', 'test'),
                $status . ' was recorded as a delivered message');
        }
    }

    /** Termii's 200-with-an-explanation is the same trap. */
    public function test_termii_200_without_a_message_id_is_a_failure(): void
    {
        $sms = $this->service(['termii_key' => 'TL1', 'termii_from' => 'AfricaGATES'], [
            'code' => 200,
            'body' => '{"message":"Insufficient balance"}',
        ]);

        $this->assertFalse($sms->sendSms('+2348012345678', 'hi', 'test'),
            'a 200 explaining the failure was recorded as a send');
    }

    /** And a real success is still a success on both. */
    public function test_a_genuine_send_succeeds_on_both(): void
    {
        $at = $this->service(['at_username' => 'gates', 'at_key' => 'atsk_x'], self::AT_OK);
        $this->assertTrue($at->sendSms('+2348012345678', 'hi', 'test'));

        $tm = $this->service(['termii_key' => 'TL1', 'termii_from' => 'AfricaGATES'], self::TERMII_OK);
        $this->assertTrue($tm->sendSms('+2348012345678', 'hi', 'test'));
    }
}
