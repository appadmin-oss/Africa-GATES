<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ClaimNotifier;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\SmsService;
use AfricaGates\Support\Reference;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A transport that records instead of sending. Subclasses the real service so a changed
 * production signature fails here rather than passing against a hand-written double.
 */
final class ClaimMailerSpy extends OtpService
{
    /** @var list<array{to:string,subject:string,html:string,text:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function __construct(bool $fail = false)
    {
        parent::__construct(['username' => 'u', 'password' => 'p']);
        $this->fail = $fail;
    }

    public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0): array
    {
        if ($this->fail) return ['success' => false, 'error' => 'connection refused'];
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody, 'text' => $plainBody];
        return ['success' => true];
    }
}

/**
 * When a claim happens, the nominee is told — on every channel we hold.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE CONTROL THAT MATTERS MOST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * docs/CLAIM-FAIRNESS-AND-FRAUD.md §5. No remote check reliably stops somebody who
 * KNOWS their victim — the bandmate, the ex-manager, the relative who has the address
 * and the photographs. Every gate tight enough to stop them also stops a large share of
 * genuine nominees, because from the outside the two are indistinguishable.
 *
 * What stops them is that the theft is loud. A thief who claims through an email he
 * controls still sets her PHONE ringing, and one reply opens a dispute.
 *
 * ── THE TEST THAT MATTERS MOST ───────────────────────────────────────────────
 *
 * {@see test_the_channel_the_claimant_could_not_control_is_told_too}. If the fan-out
 * ever reused the independence-filtered channel list, that test goes red and this
 * control quietly stops existing in the only case it was built for.
 */
final class ClaimNotifierTest extends TestCase
{
    private const CAT = 9100;
    private int $nomineeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominations')->delete();
        DB::table('gates_nominee_claims')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 91, 'title' => 'P', 'slug' => 'p-91']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 9100, 'programme_id' => 91, 'year' => 2026,
            'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => 9100,
            'title' => 'Weaving', 'slug' => 'weaving-9100']);

        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Baba Sule', 'status' => 'approved', 'vote_count' => 0]);
    }

    /** An approved nomination naming this nominee. */
    private function nomination(array $over = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId($over + [
            'cycle_id' => 9100, 'category_id' => self::CAT,
            'nominee_name'  => 'Baba Sule',
            'nominee_email' => 'sule@example.test',
            'nominee_phone' => '08031234567',
            'country_code'  => 'NG',
            'nominator_name'  => 'Ngozi Customer',
            'nominator_email' => 'ngozi@example.test',
            'nominator_phone' => '08099998888',
            'reference' => 'AFG-NOM-' . bin2hex(random_bytes(4)),
            'reason'    => 'He taught four apprentices for nothing.',
            'status'    => 'approved',
        ]);
    }

    private function claim(array $over = []): int
    {
        return (int) DB::table('gates_nominee_claims')->insertGetId($over + [
            'nominee_id'   => $this->nomineeId,
            'status'       => 'pending',
            'method'       => 'otp',
            'channel'      => 'email',
            'channel_hint' => 's••••@example.test',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array{code:int,body:string} $reply */
    private function sms(array $reply = ['code' => 201, 'body' => '{"sid":"SM1"}'], bool $waToo = false): SmsService
    {
        return new SmsService(
            twilioSid: 'AC1', twilioToken: 'tok', twilioFrom: '+15550001111',
            twilioWaFrom: $waToo ? '+15550002222' : null,
            waPhoneId: null, waToken: null,
            smsEnabled: true, waEnabled: $waToo,
            transport: static fn(string $u, array $h, $b, ?string $a): array => $reply,
        );
    }

    // ══ the control ══════════════════════════════════════════════════════════

    /**
     * THE CASE THIS EXISTS FOR: the thief's channel is not the only one told.
     *
     * The nominator typed her OWN email into the nominee field and claimed through it,
     * so that address fails independence — it is the address a claimant controls. Her
     * victim's PHONE is on the same row and must ring anyway.
     *
     * A fan-out built on the independence-filtered channel list would skip the email
     * (not independent) and, in the common single-contact case, send nothing at all.
     * This is the test that stops that.
     */
    public function test_the_channel_the_claimant_could_not_control_is_told_too(): void
    {
        $this->nomination(['nominee_email' => 'ngozi@example.test']); // == the nominator's
        $claimId = $this->claim(['channel_hint' => 'n••••@example.test']);

        $mailer = new ClaimMailerSpy();
        $out = ClaimNotifier::fanOut($claimId, $mailer, $this->sms());

        // Both channels attempted: the one used, and the phone the claimant never touched.
        $this->assertSame(2, $out['attempted']);
        $this->assertSame(2, $out['reached']);
        $this->assertSame(['email', 'phone'], array_column($out['channels'], 'channel'));

        // The phone genuinely went out to the provider, in E.164.
        $msg = DB::table('gates_messages')->where('channel', 'sms')->first();
        $this->assertNotNull($msg);
        $this->assertSame(hash('sha256', '+2348031234567'), $msg->to_hash);
    }

    /** Every contact on file, across several nominations, deduped. */
    public function test_every_channel_on_file_is_told_once(): void
    {
        $this->nomination();
        $this->nomination(['nominee_email' => 'sule@example.test',      // duplicate — one send
                           'nominee_phone' => '08031234567']);
        $this->nomination(['nominee_email' => 'sule.workshop@example.test',
                           'nominee_phone' => '08055554444']);
        $claimId = $this->claim();

        $mailer = new ClaimMailerSpy();
        $out = ClaimNotifier::fanOut($claimId, $mailer, $this->sms());

        $this->assertSame(4, $out['attempted']);        // 2 emails + 2 phones
        $this->assertCount(2, $mailer->sent);
        $this->assertSame(2, DB::table('gates_messages')->where('channel', 'sms')->count());
    }

    // ══ what the message has to say ══════════════════════════════════════════

    /**
     * The message must be actionable by somebody with no account.
     *
     * §7.3 guarantees a human route with no account and a stated turnaround, and §7.1
     * forbids charging for anything on this path. A notification that says "someone
     * claimed your page" and stops is an alarm with no off switch.
     */
    public function test_the_message_says_what_to_do_and_that_there_is_nothing_to_pay(): void
    {
        $this->nomination();
        $claimId = $this->claim();
        $ref = Reference::claim($claimId);

        $mailer = new ClaimMailerSpy();
        ClaimNotifier::fanOut($claimId, $mailer, $this->sms());

        $text = $mailer->sent[0]['text'];
        $this->assertStringContainsString('Baba Sule', $text);
        $this->assertStringContainsString($ref, $text);
        $this->assertStringContainsString('nothing to pay', $text);
        $this->assertStringContainsString('If this was NOT you', $text);
        // §3: the two facts that make a stolen claim worthless.
        $this->assertStringContainsString('7 days', $text);
        $this->assertStringContainsString("nominee's own name", $text);
    }

    /**
     * ── AND IT SURVIVES HAVING NO FREEZE LINK ────────────────────────────────
     *
     * The plain-text "If this was NOT you" used to be conditional on there being a one-tap
     * freeze URL, and `$disputeUrl` is null whenever the site base cannot be worked out —
     * APP_URL unset with no request to infer it from, which is every send from cron or the
     * queue. So the sentence this whole email exists to deliver went missing on the automated
     * path, while the HTML half of the same message still carried it.
     *
     * Both states are asserted here because they are two different messages, and the one
     * without the link is the one that used to be wrong.
     */
    public function test_the_warning_survives_when_there_is_no_freeze_link(): void
    {
        $this->nomination();
        $claimId = $this->claim();
        $ref = Reference::claim($claimId);

        // ── NO APP_URL, FROM ANY SOURCE ──────────────────────────────────────
        //
        // `Env::raw()` reads $_ENV, then $_SERVER, then getenv(), and the .env loader writes
        // the value into the first TWO. Clearing only $_ENV — which is what this test used to
        // do — left it readable from $_SERVER, so a dispute URL was still built and every
        // assertion below ran against the wrong branch. The test then failed on the last line
        // for the right reason and the wrong cause, on any checkout whose .env sets APP_URL.
        //
        // The assertion that it is really gone is the load-bearing part: if a fourth source is
        // ever added, this fails here, loudly, instead of quietly testing nothing.
        $kept = ['env' => $_ENV['APP_URL'] ?? null, 'server' => $_SERVER['APP_URL'] ?? null];
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        $this->assertNull(\AfricaGates\Support\Env::get('APP_URL'),
            'APP_URL is still readable from somewhere, so the no-freeze-link path is not '
            . 'the path being tested');

        try {
            $mailer = new ClaimMailerSpy();
            ClaimNotifier::fanOut($claimId, $mailer, $this->sms());
            $text = $mailer->sent[0]['text'];

            $this->assertStringContainsString('If this was NOT you', $text,
                'the security instruction vanished on the path that has no freeze link — '
                . 'which is every send from cron or the queue');
            // The route that needs no configuration must be named, since it is the only one left.
            $this->assertStringContainsString('reply to this email', $text);
            $this->assertStringContainsString($ref, $text);
            // And "also" must not dangle after an option that was never offered.
            $this->assertStringNotContainsString('You can also reply', $text);
        } finally {
            if ($kept['env'] !== null)    { $_ENV['APP_URL'] = $kept['env']; }
            if ($kept['server'] !== null) { $_SERVER['APP_URL'] = $kept['server']; }
        }
    }

    /** With a freeze link, the one-tap route leads and the email route follows it. */
    public function test_the_freeze_link_leads_when_there_is_one(): void
    {
        $this->nomination();
        $claimId = $this->claim();

        $_ENV['APP_URL'] = 'https://afg.example.test';
        try {
            $mailer = new ClaimMailerSpy();
            ClaimNotifier::fanOut($claimId, $mailer, $this->sms());
            $text = $mailer->sent[0]['text'];

            $this->assertStringContainsString('If this was NOT you, stop it now', $text);
            $this->assertStringContainsString('https://afg.example.test', $text);
            $this->assertStringContainsString('You can also reply', $text,
                '"also" is right here — a first option was given');
        } finally {
            unset($_ENV['APP_URL']);
        }
    }

    /** The same promises, in an SMS, because that is the channel a thief cannot read. */
    public function test_the_sms_carries_the_reference_and_the_promises(): void
    {
        $this->nomination();
        $claimId = $this->claim();

        $bodies = [];
        $sms = new SmsService(
            twilioSid: 'AC1', twilioToken: 'tok', twilioFrom: '+15550001111',
            twilioWaFrom: null, waPhoneId: null, waToken: null,
            smsEnabled: true, waEnabled: false,
            transport: static function (string $u, array $h, $b, ?string $a) use (&$bodies): array {
                if (is_array($b)) $bodies[] = (string) ($b['Body'] ?? '');
                return ['code' => 201, 'body' => '{"sid":"SM1"}'];
            },
        );

        ClaimNotifier::fanOut($claimId, new ClaimMailerSpy(), $sms);

        $this->assertCount(1, $bodies);
        $this->assertStringContainsString(Reference::claim($claimId), $bodies[0]);
        $this->assertStringContainsString('Nothing to pay', $bodies[0]);
    }

    /**
     * Never an accusation, on any channel.
     *
     * §7.2: the wording must say "we need one more thing", never "denied". This message
     * goes to somebody who has done nothing at all — the nominee — so words like fraud
     * or theft would be describing a stranger's behaviour to a bystander, in an email
     * they may read before they understand what a claim even is.
     */
    public function test_no_channel_accuses_anybody(): void
    {
        $this->nomination();
        $claimId = $this->claim();

        $bodies = [];
        $sms = new SmsService(
            twilioSid: 'AC1', twilioToken: 'tok', twilioFrom: '+15550001111',
            twilioWaFrom: null, waPhoneId: null, waToken: null,
            smsEnabled: true, waEnabled: false,
            transport: static function (string $u, array $h, $b, ?string $a) use (&$bodies): array {
                if (is_array($b)) $bodies[] = (string) ($b['Body'] ?? '');
                return ['code' => 201, 'body' => '{"sid":"SM1"}'];
            },
        );

        $mailer = new ClaimMailerSpy();
        ClaimNotifier::fanOut($claimId, $mailer, $sms);

        $all = strtolower($mailer->sent[0]['text'] . ' ' . $mailer->sent[0]['html'] . ' ' . implode(' ', $bodies));
        foreach (['fraud', 'stolen', 'theft', 'suspicious', 'impostor', 'denied'] as $word) {
            $this->assertStringNotContainsString($word, $all, "The notification must not say '{$word}'.");
        }
    }

    // ══ honest reporting, because the claim decision reads it ════════════════

    /**
     * `reached` is 0 when nothing could be delivered — and that is not a crash.
     *
     * {@see \AfricaGates\Services\NomineeClaimService} holds the claim on this, because
     * §10 refuses to build silent claiming. So a false "reached" here would activate a
     * page nobody was told about, which is the one outcome that document rules out.
     */
    public function test_a_failed_send_is_reported_as_reaching_nobody(): void
    {
        $this->nomination(['nominee_phone' => '']);
        $claimId = $this->claim();

        $out = ClaimNotifier::fanOut($claimId, new ClaimMailerSpy(fail: true), $this->sms());

        $this->assertSame(1, $out['attempted']);
        $this->assertSame(0, $out['reached']);
        $this->assertSame('failed', $out['channels'][0]['status']);
    }

    /** Unconfigured SMS is reported as unconfigured, not as sent. */
    public function test_unconfigured_sms_is_not_counted_as_reached(): void
    {
        $this->nomination();
        $claimId = $this->claim();

        $inert = new SmsService(null, null, null, null, null, null, false, false);
        $out = ClaimNotifier::fanOut($claimId, new ClaimMailerSpy(), $inert);

        $this->assertSame(1, $out['reached']);          // the email only
        $this->assertSame(2, $out['attempted']);
        $phone = array_values(array_filter($out['channels'], static fn($c) => $c['channel'] === 'phone'));
        $this->assertSame('unconfigured', $phone[0]['status']);
    }

    /** A nominee with no readable contact at all reaches nobody, and says so. */
    public function test_no_channels_on_file_reaches_nobody(): void
    {
        $claimId = $this->claim();   // no nomination rows at all

        $out = ClaimNotifier::fanOut($claimId, new ClaimMailerSpy(), $this->sms());

        $this->assertSame(0, $out['attempted']);
        $this->assertSame(0, $out['reached']);
    }

    // ══ told once ════════════════════════════════════════════════════════════

    /**
     * A second fan-out for one claim sends nothing.
     *
     * A retried POST or a double-tapped button must not deliver "someone has claimed
     * your page" twice: to a nominee two messages read as two different attacks, and the
     * second one arrives with no way to tell it from the first.
     */
    public function test_a_claim_is_only_announced_once(): void
    {
        $this->nomination();
        $claimId = $this->claim();

        $mailer = new ClaimMailerSpy();
        $first  = ClaimNotifier::fanOut($claimId, $mailer, $this->sms());
        $second = ClaimNotifier::fanOut($claimId, $mailer, $this->sms());

        $this->assertCount(1, $mailer->sent);
        $this->assertSame(1, DB::table('gates_messages')->where('channel', 'sms')->count());
        $this->assertSame($first['reached'], $second['reached']);
        $this->assertSame($first['attempted'], $second['attempted']);
    }

    /** The outcome is recorded on the claim, masked, so it can be checked later. */
    public function test_the_fan_out_is_recorded_masked_on_the_claim(): void
    {
        $this->nomination();
        $claimId = $this->claim();

        ClaimNotifier::fanOut($claimId, new ClaimMailerSpy(), $this->sms());

        $row = DB::table('gates_nominee_claims')->where('id', $claimId)->first();
        $this->assertNotEmpty($row->notified_at);
        $this->assertSame(Reference::claim($claimId), $row->reference);

        $decoded = json_decode((string) $row->notified, true);
        $this->assertSame(2, $decoded['reached']);

        // Masked destinations only — this column must never become a contact list.
        $this->assertStringNotContainsString('sule@example.test', (string) $row->notified);
        $this->assertStringNotContainsString('08031234567', (string) $row->notified);
    }

    // ══ the reference ════════════════════════════════════════════════════════

    /** A claim reference survives the round trip a support agent would make of it. */
    public function test_a_claim_reference_resolves_back_to_its_claim(): void
    {
        foreach ([1, 2, 17, 4096, 999_999] as $id) {
            $ref = Reference::claim($id);
            $this->assertSame($id, Reference::parseClaimId($ref), "round trip failed for {$id}");
            $this->assertStringStartsWith('AGC-', $ref);
        }

        // A single mistyped character must not resolve to a DIFFERENT claim. That is what
        // the check character buys: the agent is told to re-read it, not sent to the
        // wrong person's page in the middle of a dispute.
        $ref = Reference::claim(4096);
        $this->assertNull(Reference::parseClaimId(substr($ref, 0, 5) . 'Z' . substr($ref, 6)));

        // And a nomination reference is not a claim reference, in either direction.
        $this->assertNull(Reference::parseClaimId(Reference::nomination(4096, 2026)));
    }
}
