<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AccountChangeNotice;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\ReferralPayout;
use AfricaGates\Services\SmsService;
use AfricaGates\Services\UserAccountService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A change to where a member's money goes, or to who the account says they are, is told to
 * the member at once — by email AND by text, the text to the number on file BEFORE the
 * change. The payout hold only delays a takeover; this is what lets the member stop it.
 */
final class AccountChangeNoticeTest extends TestCase
{
    /** @var list<array{to:string, subject:string, body:string}> */
    private array $mails = [];
    /** @var list<array{to:string, body:string, optout:bool}> */
    private array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $mails = &$this->mails;
        $texts = &$this->texts;
        AccountChangeNotice::$mailer = new class(['host' => 'localhost', 'port' => 25, 'username' => 'u', 'password' => 'p',
                'from_address' => 'no@example.com', 'from_name' => 'X'], $mails) extends OtpService {
            private array $sink;
            public function __construct(array $c, array &$sink) { parent::__construct($c); $this->sink = &$sink; }
            public function smtpConfigured(): bool { return true; }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0,
                                        string $why = ''): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'body' => $plainBody];
                return ['success' => true];
            }
        };
        AccountChangeNotice::$texter = new class($texts) extends SmsService {
            private array $sink;
            public function __construct(array &$sink) { parent::__construct(); $this->sink = &$sink; }
            public function sendSms(string $toE164, string $body, string $template = 'generic', bool $respectOptOut = true): bool
            {
                $this->sink[] = ['to' => $toE164, 'body' => $body, 'optout' => $respectOptOut];
                return true;
            }
        };
        DB::table('gates_users')->insert(['id' => 41, 'name' => 'Ada Obi', 'email' => 'ada@example.com',
            'phone' => '08031234567', 'phone_e164' => '+2348031234567']);
    }

    protected function tearDown(): void
    {
        AccountChangeNotice::$mailer = null;
        AccountChangeNotice::$texter = null;
        parent::tearDown();
    }

    public function test_new_bank_details_are_told_by_email_and_text(): void
    {
        $this->assertTrue(ReferralPayout::saveBank(41, 'GTBank', 'Ada Obi', '0123456789', '058')['ok']);

        $this->assertCount(1, $this->mails);
        $this->assertSame('ada@example.com', $this->mails[0]['to']);
        $this->assertStringContainsString('ending 6789', $this->mails[0]['body']);
        $this->assertStringContainsString('/support', $this->mails[0]['body']);
        $this->assertCount(1, $this->texts);
        $this->assertSame('+2348031234567', $this->texts[0]['to']);
        $this->assertFalse($this->texts[0]['optout'], 'a security notice is not silenced by the event-text opt-out');

        // The same details saved again move nothing, and say nothing.
        ReferralPayout::saveBank(41, 'GTBank', 'Ada Obi', '0123456789', '058');
        $this->assertCount(1, $this->mails);
    }

    public function test_a_changed_phone_warns_the_old_number_not_the_new_one(): void
    {
        $this->assertTrue((new UserAccountService())->updateProfile(41, 'Ada Obi', '08099990000')['ok']);

        $this->assertCount(1, $this->texts);
        $this->assertSame('+2348031234567', $this->texts[0]['to'],
            'changing the phone and then the bank must not send the warning to whoever changed it');
        $this->assertStringContainsString('phone number', $this->mails[0]['body']);
    }

    public function test_a_changed_name_is_told_and_an_unchanged_profile_is_not(): void
    {
        (new UserAccountService())->updateProfile(41, 'Ada Obi', '08031234567');
        $this->assertSame([], $this->mails, 'saving the profile unchanged is not news');

        (new UserAccountService())->updateProfile(41, 'Chidi Okeke', '08031234567');
        $this->assertCount(1, $this->mails);
        $this->assertStringContainsString('Chidi Okeke', $this->mails[0]['body']);
    }
}
