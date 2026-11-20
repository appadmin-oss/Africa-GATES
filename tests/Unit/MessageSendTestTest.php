<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{MessageSendTest, RateLimitService, SmsOptOut, SmsService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE ONE QUESTION A READ-ONLY PROBE CANNOT ASK.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A PAGE THAT DELIBERATELY DOES NOT SEND NEEDED SOMETHING THAT DOES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see \AfricaGates\Services\ProviderProbe} reads a balance and stops, and
 * `ProviderProbeTest` holds that line by naming every vendor's send endpoint. That is
 * right, and it leaves a hole exactly the shape of this platform's real SMS failures:
 *
 *   THE SANDBOX.       Africa's Talking gives out sandbox credentials first. They
 *                      authenticate, they report a balance, they accept a message with a
 *                      real-looking id — against `api.sandbox.africastalking.com`, whose
 *                      other end is a web simulator. No handset rings, ever.
 *   THE SENDER ID.     Termii answers 200 with the refusal in a JSON field.
 *   THE TRIAL ACCOUNT. Twilio accepts only recipients verified in its console.
 *
 * All three read as a healthy, configured gateway on every screen this platform has. So
 * the send test exists — and everything below is the set of rules that stop a diagnostic
 * that costs money from becoming a liability.
 */
final class MessageSendTestTest extends TestCase
{
    /** An Africa's Talking gateway whose wire is a closure, so nothing leaves this process. */
    private function gateway(string $username, string $responseBody, int $code = 200,
                             ?array &$seen = null): SmsService
    {
        return new SmsService(
            smsEnabled: true,
            atUsername: $username,
            atApiKey:   'k-test',
            atFrom:     'GATES',
            transport:  function (string $url, array $h, $body, $auth) use (&$seen, $responseBody, $code) {
                $seen = ['url' => $url, 'headers' => $h, 'body' => $body];
                return ['code' => $code, 'body' => $responseBody];
            },
        );
    }

    private const ACCEPTED = '{"SMSMessageData":{"Recipients":[{"status":"Success","messageId":"ATXid_9"}]}}';

    // ══ it actually sends, and says what came back ═══════════════════════════

    public function test_a_send_reports_the_gateway_that_took_it(): void
    {
        $seen = null;
        $r = MessageSendTest::send('sms', '+2348012345678', 1,
                                   $this->gateway('live-user', self::ACCEPTED, 200, $seen));

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['sent']);
        $this->assertSame('africastalking', $r['provider']);
        $this->assertNotNull($r['ref'], 'a successful send carries no reference to match it by');
        $this->assertIsArray($seen, 'nothing was put on the wire at all');
        $this->assertSame(SmsService::AT_LIVE, $seen['url']);
    }

    /**
     * THE PROVIDER'S OWN WORDS, NOT OURS.
     *
     * "Delivery failed" is a shrug. "InvalidSenderId" is an instruction. Every hour this
     * platform has lost to messaging was lost to a rewritten error hiding a specific one.
     */
    public function test_a_refusal_is_reported_in_the_gateways_own_words(): void
    {
        $body = '{"SMSMessageData":{"Recipients":[{"status":"InvalidSenderId"}]}}';
        $r = MessageSendTest::send('sms', '+2348012345678', 2, $this->gateway('live-user', $body));

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['sent'], 'a refusal by the gateway is being reported as never sent');
        $this->assertStringContainsString('InvalidSenderId', $r['detail']);
    }

    /**
     * ══════════════════════════════════════════════════════════════════════════
     * THE SANDBOX: A PERFECT SUCCESS THAT NO PHONE EVER HEARS
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This is the case the whole feature was built for. Africa's Talking hands out sandbox
     * credentials first; they authenticate, the balance probe passes, and the send returns
     * `Success` with a message id — to a simulator. An operator reading "Accepted" and
     * waiting for a phone that will never buzz is in a worse position than one who saw a
     * failure, because they now believe the gateway works.
     */
    public function test_a_sandbox_send_succeeds_and_says_no_handset_will_ring(): void
    {
        $seen = null;
        $r = MessageSendTest::send('sms', '+2348012345678', 3,
                                   $this->gateway('sandbox', self::ACCEPTED, 200, $seen));

        $this->assertTrue($r['ok'], 'the sandbox accepted it, as it always does');
        $this->assertSame(SmsService::AT_SANDBOX, $seen['url']);
        $this->assertStringContainsStringIgnoringCase('sandbox', $r['note'],
            'a sandbox send reports success with nothing to say the message went to a '
            . 'simulator — which is the exact fault this screen exists to catch');
        $this->assertStringContainsStringIgnoringCase('simulator', $r['note']);
    }

    // ══ and the rules it is bound by ═════════════════════════════════════════

    /**
     * SOMEBODY WHO REPLIED STOP DID NOT AGREE TO AN EXCEPTION FOR OUR CURIOSITY.
     *
     * `deliver()` does not consult the list — its callers do, before they reach it — so
     * this check has to be here rather than assumed. Nothing may go on the wire.
     */
    public function test_a_number_that_opted_out_is_refused_and_nothing_is_sent(): void
    {
        SmsOptOut::record('+2348012345678', 'test');

        $seen = null;
        $r = MessageSendTest::send('sms', '+2348012345678', 4,
                                   $this->gateway('live-user', self::ACCEPTED, 200, $seen));

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['sent']);
        $this->assertNull($seen, 'a number on the opt-out list was texted anyway');
        $this->assertStringContainsStringIgnoringCase('opted out', $r['detail']);
    }

    /** A number that is not E.164 is refused before any of it, and told how to fix it. */
    public function test_a_local_format_number_is_refused_with_the_shape_that_works(): void
    {
        $seen = null;
        $r = MessageSendTest::send('sms', 'not a number', 5,
                                   $this->gateway('live-user', self::ACCEPTED, 200, $seen));

        $this->assertFalse($r['sent']);
        $this->assertNull($seen);
        $this->assertStringContainsString('+234', $r['detail'],
            'the refusal does not show the shape of a number that would work');
    }

    /** Every press costs money, so the press is capped — per admin, per hour. */
    public function test_the_hourly_cap_stops_the_sends(): void
    {
        $gw = $this->gateway('live-user', self::ACCEPTED);

        for ($i = 0; $i < MessageSendTest::PER_HOUR; $i++) {
            $this->assertTrue(MessageSendTest::send('sms', '+2348012345678', 77, $gw)['ok'],
                'send ' . ($i + 1) . ' of the allowance was refused');
        }

        $over = MessageSendTest::send('sms', '+2348012345678', 77, $gw);
        $this->assertFalse($over['ok']);
        $this->assertFalse($over['sent'], 'the cap was reported after the message went out');
        $this->assertSame(0, MessageSendTest::remaining(77));
    }

    /**
     * AND THE ALLOWANCE ON SCREEN IS THE ALLOWANCE ACTUALLY ENFORCED.
     *
     * The column is `hit_count`. A reader that guessed `hits` would get null from the query
     * builder, subtract 0, and print a full allowance forever — correct-looking, wrong, and
     * only visible to somebody who counted their own presses.
     */
    public function test_the_allowance_shown_counts_the_sends_that_happened(): void
    {
        $gw = $this->gateway('live-user', self::ACCEPTED);
        $this->assertSame(MessageSendTest::PER_HOUR, MessageSendTest::remaining(78));

        MessageSendTest::send('sms', '+2348012345678', 78, $gw);
        MessageSendTest::send('sms', '+2348012345678', 78, $gw);

        $this->assertSame(MessageSendTest::PER_HOUR - 2, MessageSendTest::remaining(78),
            'the screen is reading a different counter from the one that refuses a send');
    }

    /** An unconfigured channel is "nothing to test", not a failure of the gateway. */
    public function test_an_unconfigured_channel_says_so_rather_than_failing(): void
    {
        $r = MessageSendTest::send('whatsapp', '+2348012345678', 6,
                                   new SmsService(smsEnabled: true, atUsername: 'u', atApiKey: 'k'));

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['sent']);
        $this->assertStringContainsStringIgnoringCase('whatsapp', $r['detail']);
    }

    // ══ it must never become something a page load can trigger ═══════════════

    /**
     * THE PROBE SWEEP MUST NOT BE ABLE TO REACH THIS.
     *
     * `ProviderProbe` is pressed repeatedly, on a live system, sometimes mid-event, and its
     * "Run all" button walks every row on the page. The day somebody adds a send row to
     * that catalogue is the day opening the Integrations page texts a real phone.
     */
    public function test_the_probe_sweep_cannot_reach_the_sender(): void
    {
        $probe = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ProviderProbe.php');
        $this->assertStringNotContainsString('MessageSendTest', $probe,
            'a provider check can now send a real message, so opening the page does');

        // And on the page itself: "Run all" collects rows, and the send button is not one.
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/providers.twig');
        $this->assertStringNotContainsString('data-pv-row="send', $page);
        $this->assertStringContainsString('data-ag-do="pv-send"', $page,
            'the send test has no button, so the feature is not reachable at all');
    }

    /** It is filed under its own template, so a test is never read as a real notice. */
    public function test_a_test_send_is_recorded_as_a_test(): void
    {
        MessageSendTest::send('sms', '+2348012345678', 9, $this->gateway('live-user', self::ACCEPTED));

        $row = DB::table('gates_messages')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'a test send left no trace in the message log');
        $this->assertSame(MessageSendTest::TEMPLATE, $row->template);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_rate_limits')->delete();
    }
}
