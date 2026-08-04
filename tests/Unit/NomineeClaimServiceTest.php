<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NomineeClaimService;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Services\SmsService;
use AfricaGates\Services\SupportTicketService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/** Records the mail it was asked to send, and can be told to fail. */
class ClaimFlowMailer extends OtpService
{
    /** @var list<array{to:string,subject:string,text:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function __construct(bool $fail = false)
    {
        parent::__construct(['username' => 'u', 'password' => 'p']);
        $this->fail = $fail;
    }

    public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = ''): array
    {
        if ($this->fail) return ['success' => false, 'error' => 'refused'];
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $plainBody];
        return ['success' => true];
    }

    /** The 6-digit code from the most recent claim-code email. */
    public function lastCode(): string
    {
        for ($i = count($this->sent) - 1; $i >= 0; $i--) {
            if (preg_match('/\b(\d{6})\b/', $this->sent[$i]['text'], $m)) return $m[1];
        }
        return '';
    }
}

/**
 * Claiming a page by one-time code — and the four people in §6 who all have to succeed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * docs/CLAIM-FAIRNESS-AND-FRAUD.md §6 names four people and says what must happen to
 * each. Three of them must END UP WITH THEIR PAGE, and only one must not — which is the
 * balance every identity system claims and most quietly abandon the first time it is
 * inconvenient. So the four are here as tests:
 *
 *   ADAEZE    — own Gmail on the nomination. Claimed in one go.
 *   BABA SULE — his CUSTOMER nominated him and typed her own email, so that channel is
 *               held; his own number is on the same row and clears it. Claimed, with no
 *               document and no email address of his own.
 *   THE IMPOSTOR — nominated her, typed his own address, confirmed his own code. His
 *               device fingerprint matches the nomination. Held.
 *   A SECOND CLAIMANT on an active page — held for a person, never auto-transferred.
 *
 * Half of these tests exist to stop the code getting STRICTER, not looser. A hold that
 * reads as a refusal, a page that stays locked after a hold, or a fee anywhere on this
 * path are all failures of the same kind, and each has a test.
 */
final class NomineeClaimServiceTest extends TestCase
{
    private const CAT = 9300;
    private int $nomineeId = 0;

    /** The device and IP the NOMINATION was submitted from, stored as the column holds them. */
    private const NOMINATOR_FP = 'sha-of-nominator-device';
    private string $nominatorIp = '';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominations')->delete();
        DB::table('gates_nominee_claims')->delete();
        DB::table('gates_otp_tokens')->delete();
        DB::table('gates_support_tickets')->delete();

        $this->nominatorIp = hash('sha256', 'nominator-ip');

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 93, 'title' => 'P', 'slug' => 'p-93']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 9300, 'programme_id' => 93, 'year' => 2026,
            'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => 9300,
            'title' => 'Craft', 'slug' => 'craft-9300']);

        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Baba Sule', 'status' => 'approved', 'vote_count' => 0]);
    }

    private function nomination(array $over = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId($over + [
            'cycle_id' => 9300, 'category_id' => self::CAT,
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
            'device_fp' => self::NOMINATOR_FP,
            'ip_hash'   => $this->nominatorIp,
        ]);
    }

    private function sms(bool $configured = true): SmsService
    {
        if (!$configured) return new SmsService(null, null, null, null, null, null, false, false);
        return new SmsService(
            twilioSid: 'AC1', twilioToken: 'tok', twilioFrom: '+15550001111',
            twilioWaFrom: null, waPhoneId: null, waToken: null,
            smsEnabled: true, waEnabled: false,
            transport: static fn(string $u, array $h, $b, ?string $a): array
                => ['code' => 201, 'body' => '{"sid":"SM1"}'],
        );
    }

    /** @param list<string> $smsBodies */
    private function smsRecording(array &$smsBodies): SmsService
    {
        return new SmsService(
            twilioSid: 'AC1', twilioToken: 'tok', twilioFrom: '+15550001111',
            twilioWaFrom: null, waPhoneId: null, waToken: null,
            smsEnabled: true, waEnabled: false,
            transport: static function (string $u, array $h, $b, ?string $a) use (&$smsBodies): array {
                if (is_array($b)) $smsBodies[] = (string) ($b['Body'] ?? '');
                return ['code' => 201, 'body' => '{"sid":"SM1"}'];
            },
        );
    }

    private function service(?ClaimFlowMailer $mailer = null, ?SmsService $sms = null,
                            ?RateLimitService $limits = null, bool $tickets = true): NomineeClaimService
    {
        return new NomineeClaimService(
            $mailer ?? new ClaimFlowMailer(),
            $sms ?? $this->sms(),
            $tickets ? new SupportTicketService(null) : null,
            $limits,
        );
    }

    /** @return array{channel:string,key:string,hint:string,independent:bool} */
    private function channel(NomineeClaimService $svc, string $kind, string $deviceFp = '', string $ipHash = ''): array
    {
        foreach ($svc->channels($this->nomineeId, $deviceFp, $ipHash) as $c) {
            if ($c['channel'] === $kind) return $c;
        }
        $this->fail("no {$kind} channel offered");
    }

    // ══ §6: ADAEZE — ninety seconds, no document ═════════════════════════════

    public function test_adaeze_claims_her_page_with_one_code(): void
    {
        $this->nomination();                                  // her own email, her own number
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $start = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $this->assertTrue($start['ok'], $start['message']);
        $this->assertFalse($start['will_hold']);
        $this->assertStringContainsString('•', $start['hint'], 'the destination must be masked');

        $done = $svc->confirm($start['claim_id'], $mailer->lastCode());

        $this->assertSame('active', $done['status'], $done['message']);
        $row = DB::table('gates_nominee_claims')->where('id', $start['claim_id'])->first();
        $this->assertSame('active', $row->status);
        $this->assertSame($this->nomineeId, (int) $row->active_nominee_id);
        $this->assertNotEmpty($row->activated_at);
        // §5 — and she was told on every channel, including the number she did not use.
        $this->assertNotEmpty($row->notified_at);
    }

    // ══ §6: BABA SULE — held, then through on his own number ═════════════════

    /**
     * His customer filled the form in with HER email. That channel is held — and the
     * message must not read as an accusation, because he has done nothing.
     */
    public function test_baba_sule_is_held_when_the_code_goes_to_his_nominators_address(): void
    {
        $this->nomination(['nominee_email' => 'ngozi@example.test']);  // == the nominator
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $email = $this->channel($svc, 'email');
        $this->assertFalse($email['independent']);

        $start = $svc->start($this->nomineeId, $email['key']);
        $this->assertTrue($start['ok']);
        // Told BEFORE the wait, not sprung afterwards.
        $this->assertTrue($start['will_hold']);

        $done = $svc->confirm($start['claim_id'], $mailer->lastCode());

        $this->assertTrue($done['ok'], 'a hold is not an error');
        $this->assertSame('held', $done['status']);
        $this->assertStringContainsString('one more thing', $done['message']);
        $this->assertStringContainsString('nothing to pay', $done['message']);
        $this->assertSame('held', DB::table('gates_nominee_claims')
            ->where('id', $start['claim_id'])->value('status'));
    }

    /** And then his OWN number, on the same nomination, clears it. No document, no email. */
    public function test_baba_sule_then_claims_on_his_own_number(): void
    {
        $this->nomination(['nominee_email' => 'ngozi@example.test']);
        $bodies = [];
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer, $this->smsRecording($bodies));

        $phone = $this->channel($svc, 'phone');
        $this->assertTrue($phone['independent'], "his own number is nobody else's");

        $start = $svc->start($this->nomineeId, $phone['key']);
        $this->assertTrue($start['ok'], $start['message']);

        preg_match('/\b(\d{6})\b/', $bodies[0] ?? '', $m);
        $done = $svc->confirm($start['claim_id'], $m[1] ?? '');

        $this->assertSame('active', $done['status'], $done['message']);
    }

    // ══ §6: THE IMPOSTOR ═════════════════════════════════════════════════════

    /**
     * He nominated her, typed his own address, and confirmed his own code.
     *
     * The code proves nothing — he can read his own inbox. What catches him is the
     * device fingerprint on the nomination he submitted, compared at confirm time.
     */
    public function test_the_impostor_confirms_his_own_code_and_is_still_held(): void
    {
        $this->nomination(['nominee_email' => 'impostor@example.test',
                           'nominator_email' => 'impostor@example.test']);
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $start = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $done  = $svc->confirm($start['claim_id'], $mailer->lastCode(),
                               deviceFp: self::NOMINATOR_FP);

        $this->assertSame('held', $done['status']);
        $this->assertSame($this->nomineeId, (int) DB::table('gates_nominees')
            ->where('id', $this->nomineeId)->value('id'));
        $this->assertNull(DB::table('gates_nominee_claims')
            ->where('nominee_id', $this->nomineeId)->value('active_nominee_id'));
    }

    /**
     * A claim STARTED on a clean device and CONFIRMED on the nominator's is still caught.
     *
     * This is why the device verdict is recomputed at confirm rather than read back from
     * the row: a stored verdict would have been decided before the machine that mattered
     * ever appeared.
     */
    public function test_the_device_is_judged_at_confirm_not_at_send(): void
    {
        $this->nomination(['nominee_email' => 'sule@example.test']);
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $start = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key'],
                             deviceFp: 'a-clean-phone');
        $this->assertFalse($start['will_hold']);

        $done = $svc->confirm($start['claim_id'], $mailer->lastCode(),
                              deviceFp: self::NOMINATOR_FP);

        $this->assertSame('held', $done['status']);
    }

    // ══ §10: a second claim on an active page goes to a human ════════════════

    public function test_a_second_claim_on_a_claimed_page_is_not_offered_a_code(): void
    {
        $this->nomination();
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $first = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $this->assertSame('active', $svc->confirm($first['claim_id'], $mailer->lastCode())['status']);

        $before  = count($mailer->sent);
        $second  = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);

        $this->assertFalse($second['ok']);
        $this->assertSame('ALREADY_CLAIMED', $second['code']);
        $this->assertCount($before, $mailer->sent, 'no code may be sent for a page already claimed');
        // It still tells them how to reach a person, with the reference to quote.
        $this->assertStringContainsString('AGC-', $second['message']);
        $this->assertStringContainsString('nothing to pay', $second['message']);
    }

    /**
     * The DATABASE settles the race, not this class.
     *
     * The `ALREADY_CLAIMED` check in start() is a courtesy — it saves somebody a wasted
     * code. It cannot be the guarantee, because a claim can be activated in the seconds
     * between another claimant's start() and their confirm(), and at that moment both have
     * passed every check in this file.
     *
     * So this test does what a race does: a claim is already pending and holds a live code
     * when a DIFFERENT claim goes active underneath it. Confirming then has to lose, and
     * lose in the shape §10 demands — HELD for a person, never a silent second owner and
     * never a 500 at somebody who did nothing wrong.
     */
    public function test_a_claim_that_loses_the_race_is_held_not_crashed(): void
    {
        $this->nomination();
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        // The claimant who is mid-flow: code issued, not yet confirmed.
        $mine = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $myCode = $mailer->lastCode();

        // Somebody else's claim goes ACTIVE in the meantime — this is the row that owns
        // active_nominee_id, and the UNIQUE index on it is now the whole guarantee.
        DB::table('gates_nominee_claims')->insert([
            'nominee_id' => $this->nomineeId, 'status' => 'active', 'method' => 'admin',
            'active_nominee_id' => $this->nomineeId, 'activated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $done = $svc->confirm($mine['claim_id'], $myCode);

        $this->assertTrue($done['ok'], 'losing a race is not the claimant\'s error');
        $this->assertSame('held', $done['status']);
        $this->assertStringContainsString('nothing to pay', $done['message']);

        // Exactly one owner, and it is not the loser.
        $this->assertSame(1, DB::table('gates_nominee_claims')
            ->whereNotNull('active_nominee_id')->count());
        $this->assertSame('held', DB::table('gates_nominee_claims')
            ->where('id', $mine['claim_id'])->value('status'));
    }

    // ══ the claimant may not choose the destination ══════════════════════════

    /**
     * THE ATTACK ONE LAYER UP: "send my claim code to an address I typed".
     *
     * If that were possible, every nominee on the platform could be claimed by anybody,
     * and the code would prove only that the claimant can read their own inbox — the
     * failure §1 is about, reintroduced in the transport.
     */
    public function test_a_made_up_channel_key_is_refused(): void
    {
        $this->nomination();
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        foreach (['', 'deadbeefdeadbeef', hash('sha256', 'attacker@example.test')] as $forged) {
            $out = $svc->start($this->nomineeId, $forged);
            $this->assertFalse($out['ok'], "forged key '{$forged}' must not be honoured");
            $this->assertSame('CHANNEL_UNKNOWN', $out['code']);
        }
        $this->assertCount(0, $mailer->sent);
        $this->assertSame(0, DB::table('gates_nominee_claims')->count());
    }

    /** A key is bound to its nominee: it cannot be lifted from one page and used on another. */
    public function test_a_channel_key_from_another_page_is_refused(): void
    {
        $this->nomination();
        $other = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Someone Else', 'status' => 'approved', 'vote_count' => 0]);
        $this->nomination(['nominee_name' => 'Someone Else', 'nominee_email' => 'other@example.test',
                           'nominee_phone' => '', 'nominator_email' => 'n@example.test']);

        $svc = $this->service();
        $keyForOther = '';
        foreach ($svc->channels($other) as $c) { $keyForOther = $c['key']; break; }
        $this->assertNotSame('', $keyForOther);

        $out = $svc->start($this->nomineeId, $keyForOther);
        $this->assertFalse($out['ok']);
        $this->assertSame('CHANNEL_UNKNOWN', $out['code']);
    }

    /** An unclaimed page must never disclose the contact details it holds. */
    public function test_no_raw_contact_detail_is_ever_returned(): void
    {
        $this->nomination();
        $svc = $this->service();

        $blob = json_encode($svc->channels($this->nomineeId));
        $this->assertStringNotContainsString('sule@example.test', (string) $blob);
        $this->assertStringNotContainsString('08031234567', (string) $blob);
    }

    // ══ the code ═════════════════════════════════════════════════════════════

    /** A wrong code does not burn the real person's code. They mistyped; they retry. */
    public function test_a_wrong_code_can_be_retried(): void
    {
        $this->nomination();
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);
        $start  = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);

        $bad = $svc->confirm($start['claim_id'], '000000');
        $this->assertFalse($bad['ok']);
        $this->assertSame('INVALID_CODE', $bad['code']);

        $good = $svc->confirm($start['claim_id'], $mailer->lastCode());
        $this->assertSame('active', $good['status'], 'the right code must still work');
    }

    /** But a code cannot be walked. */
    public function test_a_code_dies_after_too_many_wrong_guesses(): void
    {
        $this->nomination();
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);
        $start  = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $real   = $mailer->lastCode();

        for ($i = 0; $i < 6; $i++) $svc->confirm($start['claim_id'], '000001');

        $out = $svc->confirm($start['claim_id'], $real);
        $this->assertFalse($out['ok']);
        $this->assertContains($out['code'], ['TOO_MANY_ATTEMPTS', 'INVALID_CODE']);
    }

    /**
     * One live code per PAGE — asking for a second invalidates the first.
     *
     * Otherwise a claimant could hold an outstanding code on every channel a nominee has
     * and confirm whichever arrived, which quietly turns "pick a channel" into "try them
     * all".
     */
    public function test_a_new_code_invalidates_the_previous_one(): void
    {
        $this->nomination();
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $first     = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $firstCode = $mailer->lastCode();
        $second    = $svc->start($this->nomineeId, $this->channel($svc, 'phone')['key']);
        $this->assertTrue($second['ok']);

        $out = $svc->confirm($first['claim_id'], $firstCode);
        $this->assertFalse($out['ok'], 'the superseded code must not work');
    }

    /** A code issued for one page cannot settle a claim on another. */
    public function test_a_code_is_bound_to_its_nominee(): void
    {
        $this->nomination();
        $other = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Other Person', 'status' => 'approved', 'vote_count' => 0]);

        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);
        $start  = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);

        // A pending claim on a DIFFERENT page, offered the code from this one.
        $foreign = (int) DB::table('gates_nominee_claims')->insertGetId([
            'nominee_id' => $other, 'status' => 'pending', 'method' => 'otp',
            'channel' => 'email', 'channel_hint' => 'x••••@example.test',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $out = $svc->confirm($foreign, $mailer->lastCode());
        $this->assertFalse($out['ok']);
    }

    // ══ nobody is quietly excluded ═══════════════════════════════════════════

    /**
     * A hold NEVER locks the page. §7.6 — the real person must always be able to try again.
     *
     * This is the safeguard that makes the whole design survivable: if a hold were
     * terminal, every false positive would be a permanent theft of somebody's own name.
     */
    public function test_a_held_claim_does_not_lock_the_page_against_a_later_one(): void
    {
        $this->nomination(['nominee_email' => 'ngozi@example.test']);  // will hold
        $bodies = [];
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer, $this->smsRecording($bodies));

        $held = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $this->assertSame('held', $svc->confirm($held['claim_id'], $mailer->lastCode())['status']);

        // Now the real person, on his own number.
        $again = $svc->start($this->nomineeId, $this->channel($svc, 'phone')['key']);
        $this->assertTrue($again['ok'], $again['message']);
        preg_match('/\b(\d{6})\b/', end($bodies) ?: '', $m);

        $this->assertSame('active', $svc->confirm($again['claim_id'], $m[1] ?? '')['status']);
        // Both rows coexist: one held, one active. The schema allows any number of the
        // former and exactly one of the latter.
        $this->assertSame(1, DB::table('gates_nominee_claims')
            ->where('nominee_id', $this->nomineeId)->where('status', 'held')->count());
        $this->assertSame(1, DB::table('gates_nominee_claims')
            ->where('nominee_id', $this->nomineeId)->where('status', 'active')->count());
    }

    /** §7.2: no message on this path may read as a refusal or an accusation. */
    public function test_nothing_on_this_path_says_no(): void
    {
        $this->nomination(['nominee_email' => 'ngozi@example.test']);
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $start = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $held  = $svc->confirm($start['claim_id'], $mailer->lastCode());
        $claimed = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);

        $words = strtolower($start['message'] . ' ' . $held['message'] . ' ' . $claimed['message']);
        foreach (['denied', 'refused', 'rejected', 'fraud', 'failed verification', 'not allowed',
                  'suspicious', 'impostor'] as $word) {
            $this->assertStringNotContainsString($word, $words, "This path must not say '{$word}'.");
        }
        // And it says the opposite, twice over.
        $this->assertStringContainsString('nothing to pay', strtolower($held['message']));
    }

    /**
     * A channel we cannot send on is not offered.
     *
     * The alternative is a button that mails nothing and a nominee waiting for a code
     * that was never going to arrive — which reads to them as being ignored, and reads to
     * the operator as no problem at all.
     */
    public function test_a_channel_that_cannot_be_delivered_is_not_offered(): void
    {
        $this->nomination();
        $svc = $this->service(new ClaimFlowMailer(), $this->sms(configured: false));

        $kinds = array_column($svc->channels($this->nomineeId), 'channel');
        $this->assertSame(['email'], $kinds, 'no SMS credentials means no phone option');
    }

    /** With NO deliverable channel at all, the answer is the human route — not a blank page. */
    public function test_a_nominee_with_no_deliverable_channel_gets_no_dead_ends(): void
    {
        $this->nomination(['nominee_email' => '']);        // phone only
        $svc = $this->service(new ClaimFlowMailer(), $this->sms(configured: false));

        $this->assertSame([], $svc->channels($this->nomineeId));
        $out = $svc->start($this->nomineeId, 'anything');
        $this->assertFalse($out['ok']);
        $this->assertSame('CHANNEL_UNKNOWN', $out['code']);
    }

    // ══ §10: no silent claiming ══════════════════════════════════════════════

    /**
     * A confirmed code that reached nobody is HELD, not activated.
     *
     * §10 refuses to build silent claiming, and a page handed over with nobody told is
     * exactly that. The usual cause is one contact channel and no SMS credentials, not an
     * attack — so it is a hold with a person on the other end, never a refusal.
     */
    public function test_a_claim_nobody_could_be_told_about_is_held(): void
    {
        $this->nomination(['nominee_phone' => '']);         // email only

        // The mailer sends the CODE, then refuses the announcement.
        $mailer = new class extends ClaimFlowMailer {
            public int $calls = 0;
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = ''): array
            {
                $this->calls++;
                if ($this->calls > 1) return ['success' => false, 'error' => 'refused'];
                return parent::sendBranded($to, $subject, $htmlBody, $plainBody, $category, $hero);
            }
        };
        $svc = $this->service($mailer, $this->sms(configured: false));

        $start = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $done  = $svc->confirm($start['claim_id'], $mailer->lastCode());

        $this->assertSame('held', $done['status'], 'a page nobody was told about must not change hands');
        $this->assertStringContainsString('could not reach you', $done['message']);
    }

    // ══ the assisted path is real ════════════════════════════════════════════

    /**
     * A held claim opens a ticket with the checklist — and one the claimant can answer.
     *
     * §7.3 requires a human route WITHOUT an account. A ticket opened on somebody's behalf
     * that they cannot reply to is a monologue, which is what the ticket-link work exists
     * to end, so a no-account reply link is issued to the address that received the code.
     */
    public function test_a_hold_opens_an_answerable_ticket_with_the_checklist(): void
    {
        $this->nomination(['nominee_email' => 'ngozi@example.test']);
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer);

        $start = $svc->start($this->nomineeId, $this->channel($svc, 'email')['key']);
        $done  = $svc->confirm($start['claim_id'], $mailer->lastCode());

        $this->assertNotNull($done['ticket']);
        $ticket = DB::table('gates_support_tickets')->where('reference', $done['ticket'])->first();
        $this->assertNotNull($ticket);
        $this->assertStringContainsString($done['reference'], (string) $ticket->subject);
        $this->assertStringContainsString('video call', (string) $ticket->transcript);
        $this->assertStringContainsString('not a refusal', (string) $ticket->transcript);

        // Answerable without an account.
        $this->assertSame(1, DB::table('gates_ticket_links')->where('ticket_id', $ticket->id)->count());
    }

    // ══ §4 attacker 5: the farmer ════════════════════════════════════════════

    /** One page cannot be walked, however many browsers the caller brings. */
    public function test_a_page_stops_issuing_codes_after_the_daily_run(): void
    {
        $this->nomination();
        $mailer = new ClaimFlowMailer();
        $svc    = $this->service($mailer, null, new RateLimitService());
        $key    = $this->channel($svc, 'email')['key'];

        $refused = 0;
        for ($i = 0; $i < 12; $i++) {
            // A fresh client fingerprint each time — the per-page limit is the one that
            // does not care.
            if (!$svc->start($this->nomineeId, $key, clientKey: hash('sha256', "browser-{$i}"))['ok']) {
                $refused++;
            }
        }
        $this->assertGreaterThan(0, $refused, 'the per-page limit must bite');
    }

    /** And the refusal still points at a person. A limit is not a dead end either. */
    public function test_a_rate_limited_claimant_is_told_how_to_reach_a_person(): void
    {
        $this->nomination();
        $svc = $this->service(new ClaimFlowMailer(), null, new RateLimitService());
        $key = $this->channel($svc, 'email')['key'];

        $last = ['ok' => true, 'message' => ''];
        for ($i = 0; $i < 12 && $last['ok']; $i++) {
            $last = $svc->start($this->nomineeId, $key, clientKey: hash('sha256', "b-{$i}"));
        }
        $this->assertFalse($last['ok']);
        $this->assertStringContainsString('@', $last['message'], 'a limit must still name a way out');
    }

    // ══ only a real page can be claimed ══════════════════════════════════════

    public function test_an_unapproved_or_missing_nominee_cannot_be_claimed(): void
    {
        $this->nomination();
        $pending = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Not Yet', 'status' => 'pending', 'vote_count' => 0]);

        $svc = $this->service();
        foreach ([0, -1, 987654, $pending] as $id) {
            $out = $svc->start($id, 'whatever');
            $this->assertFalse($out['ok']);
            $this->assertSame('NO_NOMINEE', $out['code'], "nominee {$id} must not be claimable");
        }
    }
}
