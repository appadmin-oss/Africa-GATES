<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\SmsInboundController;
use AfricaGates\Services\SmsOptOut;
use AfricaGates\Support\Phone;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Somebody replying STOP, and the platform actually stopping.
 *
 * ── WHY THE SIGNATURE IS THE WHOLE TEST ──────────────────────────────────────
 *
 * This endpoint records opt-outs from the `From` field of an unauthenticated POST. Get
 * the verification wrong in the permissive direction and it becomes a form anybody on the
 * internet can submit to silence somebody else's messages — including the claim codes and
 * the "someone is claiming your page" alerts, which are the two this platform sends that
 * a person is genuinely harmed by not receiving.
 *
 * Get it wrong in the strict direction and every genuine STOP is ignored, while the
 * message keeps promising that replying works.
 */
final class SmsInboundTest extends TestCase
{
    private const TOKEN = 'test_auth_token_not_a_real_one';
    private const URL   = 'https://afg.afrovanguard.org.ng/hooks/sms-inbound';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_sms_optout')->delete();
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'sms_twilio_token'], ['value' => self::TOKEN]
        );
    }

    /**
     * The signature Twilio would send: the URL with the POST fields appended, keys sorted
     * and each immediately followed by its value, HMAC-SHA1 with the auth token, base64.
     *
     * @param array<string,string> $params
     */
    private function sign(array $params, string $url = self::URL, string $token = self::TOKEN): string
    {
        ksort($params, SORT_STRING);
        $payload = $url;
        foreach ($params as $k => $v) $payload .= $k . $v;

        return base64_encode(hash_hmac('sha1', $payload, $token, true));
    }

    /** @param array<string,string> $params */
    private function post(array $params, ?string $signature = null): int
    {
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', self::URL)
            ->withParsedBody($params)
            ->withHeader('X-Twilio-Signature', $signature ?? $this->sign($params));

        return (new SmsInboundController())
            ->receive($req, new \Slim\Psr7\Response())
            ->getStatusCode();
    }

    // ══ the happy path ═══════════════════════════════════════════════════════

    public function test_a_signed_stop_is_honoured(): void
    {
        $this->assertSame(204, $this->post(['From' => '+2348012345678', 'Body' => 'STOP']));

        $this->assertTrue(SmsOptOut::suppressed('+2348012345678'),
            'the platform promised that replying STOP works');
    }

    /**
     * THE ONE THAT SHIPPED BROKEN, AND THE ONE A DRIVER CANNOT HIDE.
     *
     * `phone_masked` was VARCHAR(12). Phone::mask() emits up to fifteen characters, and
     * fourteen for any thirteen-digit E.164 number — which is every Nigerian mobile. In
     * strict mode MySQL refused the INSERT, SmsOptOut::record() caught it and returned
     * false, and the webhook still answered 204. On the platform's home market, replying
     * STOP did nothing at all, and every screen and every log agreed it had worked.
     *
     * The suite never saw it because SQLite declares that column TEXT and takes any
     * length. So this test does NOT rely on the database refusing anything: it measures
     * the value the code produces against the width the schema declares. That fails on
     * both drivers, which is the only kind of assertion that would have caught this
     * before a real person could not get away from us.
     */
    public function test_a_masked_number_fits_the_column_it_is_stored_in(): void
    {
        $declared = 0;
        $mig = (string) file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/2026_11_05_sms_optout.php');
        if (preg_match('/phone_masked VARCHAR\((\d+)\)/', $mig, $m)) $declared = (int) $m[1];

        $this->assertGreaterThan(0, $declared, 'the phone_masked column declaration moved');

        // Every shape this function can emit, not one example: a country code is one to
        // three digits and a subscriber number runs to fifteen in E.164.
        foreach ([
            '+2348012345678',      // Nigeria — thirteen digits, the case that broke
            '+15551234567',        // North America
            '+861234567890123',    // the E.164 ceiling
            '+441234567',          // short
            '+1234567',            // shorter than the mask's own floor
        ] as $e164) {
            $masked = Phone::mask($e164);
            $this->assertLessThanOrEqual($declared, mb_strlen($masked),
                $e164 . ' masks to "' . $masked . '" (' . mb_strlen($masked) . ' chars), which '
                . 'does not fit VARCHAR(' . $declared . ') — on MySQL that INSERT is refused '
                . 'and the opt-out is silently lost');
        }
    }

    /** And the round trip, which is what the person actually needs to happen. */
    public function test_a_nigerian_number_that_replies_stop_is_actually_suppressed(): void
    {
        $number = '+2348099887766';

        $this->assertSame(204, $this->post(['From' => $number, 'Body' => 'STOP']));
        $this->assertTrue(SmsOptOut::suppressed($number),
            'a Nigerian number replied STOP and is still on the list');

        // The masked form is kept for a support desk, so it has to have survived too.
        $row = DB::table('gates_sms_optout')->first();
        $this->assertNotNull($row, 'nothing was written at all');
        $this->assertStringEndsWith('766', (string) $row->phone_masked);
    }

    /** Punctuation and case are how people actually type it. */
    public function test_the_words_people_really_send_are_understood(): void
    {
        foreach (['STOP', 'stop.', 'Stop!', 'UNSUBSCRIBE', 'cancel', 'stop sending these'] as $i => $body) {
            $number = '+23480123456' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->post(['From' => $number, 'Body' => $body]);
            $this->assertTrue(SmsOptOut::suppressed($number), 'not understood: ' . $body);
        }
    }

    /**
     * And the sentence that contains the word but is not one.
     *
     * Matched on the first word, which is exactly what a whole-body `str_contains` gets
     * wrong — and getting it wrong here silences somebody who was asking for more.
     */
    public function test_a_sentence_that_merely_contains_the_word_is_not_a_stop(): void
    {
        $this->post(['From' => '+2348099999999', 'Body' => 'please do not stop inviting me']);

        $this->assertFalse(SmsOptOut::suppressed('+2348099999999'));
    }

    /** Somebody who asks to start again has asked twice to be listened to. */
    public function test_start_undoes_it(): void
    {
        SmsOptOut::record('+2348012345678', 'stop-reply');

        $this->post(['From' => '+2348012345678', 'Body' => 'START']);

        $this->assertFalse(SmsOptOut::suppressed('+2348012345678'));
    }

    // ══ and the half that matters more ═══════════════════════════════════════

    /**
     * An unsigned POST does nothing.
     *
     * Without this the endpoint is a form anybody can submit to stop somebody else's
     * messages, and the victim has no way to tell it happened.
     */
    public function test_an_unsigned_post_cannot_silence_anybody(): void
    {
        $this->assertSame(204, $this->post(['From' => '+2348012345678', 'Body' => 'STOP'], ''));

        $this->assertFalse(SmsOptOut::suppressed('+2348012345678'),
            'anybody on the internet can stop this number receiving its security alerts');
    }

    /** A signature made with the wrong token is no signature. */
    public function test_a_signature_from_the_wrong_token_is_refused(): void
    {
        $params = ['From' => '+2348012345678', 'Body' => 'STOP'];

        $this->post($params, $this->sign($params, self::URL, 'somebody-elses-token'));

        $this->assertFalse(SmsOptOut::suppressed('+2348012345678'));
    }

    /**
     * A signature for a DIFFERENT URL is no signature either.
     *
     * The URL is part of what is signed, so a valid signature captured from another
     * endpoint cannot be replayed at this one.
     */
    public function test_a_signature_for_another_url_is_refused(): void
    {
        $params = ['From' => '+2348012345678', 'Body' => 'STOP'];

        $this->post($params, $this->sign($params, 'https://afg.afrovanguard.org.ng/hooks/other'));

        $this->assertFalse(SmsOptOut::suppressed('+2348012345678'));
    }

    /** Tampering with the body after signing breaks it. */
    public function test_a_changed_field_breaks_the_signature(): void
    {
        $signed = $this->sign(['From' => '+2348011111111', 'Body' => 'HELLO']);

        $this->post(['From' => '+2348012345678', 'Body' => 'STOP'], $signed);

        $this->assertFalse(SmsOptOut::suppressed('+2348012345678'));
    }

    /**
     * With no token configured, nothing is accepted at all.
     *
     * A deployment that has not set up Twilio must not have an open opt-out endpoint
     * sitting on it — that is the state where "we have no way to verify" would otherwise
     * quietly become "so we will trust it".
     */
    public function test_with_no_token_to_verify_against_nothing_is_accepted(): void
    {
        DB::table('gates_settings')->where('key_name', 'sms_twilio_token')->delete();

        $params = ['From' => '+2348012345678', 'Body' => 'STOP'];
        $this->assertSame(204, $this->post($params, $this->sign($params)));

        $this->assertFalse(SmsOptOut::suppressed('+2348012345678'));
    }
}
