<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Mail\AppsScriptMail;
use AfricaGates\Services\Mail\MailConfig;
use AfricaGates\Services\Mail\RoadCapacity;
use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use Tests\TestCase;

/**
 * EVERY ROAD'S OWN ALLOWANCE (owner, 5 Nov 2026: "smarter use of the available infrastructure
 * … to overcome limitations"; Apps Script works, SMTP fails). A road that says "quota" is
 * rested so the next message goes straight to one with room; the script's own count of what it
 * may still send is recorded; the announcement cap is the combined allowance.
 */
final class MailRoadCapacityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_mail_log')->delete();
        DB::table('gates_settings')->whereIn('key_name', [RoadCapacity::REST_KEY, RoadCapacity::GAS_SEEN_KEY, RoadCapacity::GAS_KEY,
            RoadCapacity::API_KEY, 'mail_daily_limit', OtpService::SMTP_REST_KEY, SendPolicy::CAP_KEY])->delete();
        RoadCapacity::forget();
        SendPolicy::forget();
    }

    private function mailer(array $fail): OtpService
    {
        return new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'news@africagates.org', 'from_name' => 'Africa GATES',
                          'transport' => MailConfig::TRANSPORT_AUTO, 'api_key' => 'xkeysib-test', 'gas' => true], $fail) extends OtpService {
            public array $roads = [];
            public function __construct(array $smtp, private array $fail) { parent::__construct($smtp); }
            protected function transmit(PHPMailer $m): void { $this->go('smtp'); }
            protected function transmitApi(PHPMailer $m): void { $this->go('api'); }
            protected function transmitGas(PHPMailer $m): void { $this->go('gas'); }
            private function go(string $r): void { $this->roads[] = $r; if (isset($this->fail[$r])) throw new MailException($this->fail[$r]); }
        };
    }

    public function test_a_road_that_says_quota_is_rested_and_the_next_message_skips_it(): void
    {
        $m = $this->mailer(['smtp' => '550 5.4.5 Daily user sending quota exceeded.']);
        $this->assertTrue($m->sendCustom('a@africagates.org', 'Your code', '1')['success']);
        $this->assertSame('smtp', $m->roads[0]);
        $this->assertArrayHasKey('smtp', RoadCapacity::rests());

        $m->roads = [];
        $this->assertTrue($m->sendCustom('b@africagates.org', 'Your code', '2')['success']);
        $this->assertNotSame('smtp', $m->roads[0], 'a spent road is not paid for again on every message');
        $this->assertSame(1, count($m->roads), 'straight to a road with room');
        $this->assertSame('sent', DB::table('gates_mail_log')->orderByDesc('id')->value('status'));
        $this->assertNotNull(DB::table('gates_mail_log')->orderByDesc('id')->value('via'), 'the road that carried it is recorded');
    }

    public function test_a_lone_spent_road_is_still_tried(): void
    {
        RoadCapacity::rest('smtp');
        $this->assertSame(['smtp'], RoadCapacity::order(['smtp']), 'never fewer roads than there are');
        $this->assertSame(['api', 'smtp'], RoadCapacity::order(['smtp', 'api']));
    }

    public function test_the_scripts_own_count_is_recorded_and_respected(): void
    {
        $gas = new AppsScriptMail('https://script.test/exec', 'secret',
            static fn () => ['status' => 200, 'body' => (string) json_encode(['ok' => true, 'remaining' => 7]), 'error' => '']);
        $m = new PHPMailer(true);
        $m->setFrom('news@africagates.org');
        $m->addAddress('a@africagates.org');
        $m->Subject = 's';
        $m->Body = 'b';
        $gas->send($m);
        $this->assertSame(7, RoadCapacity::gasRemaining());
        $this->assertFalse(RoadCapacity::gasMayCarryBulk(), '7 left is inside the reserve: announcements wait');
        RoadCapacity::noteGasRemaining(0);
        $this->assertTrue(RoadCapacity::exhausted('gas'));
        RoadCapacity::noteGasRemaining(90);
        $this->assertTrue(RoadCapacity::gasMayCarryBulk());
    }

    public function test_the_announcement_cap_is_the_combined_allowance(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'mail_daily_limit', 'value' => '500']);
        DB::table('gates_settings')->insert(['key_name' => RoadCapacity::API_KEY, 'value' => '300']);
        $c = MailConfig::of(['host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'x@gmail.com', 'password' => 'p', 'api_key' => 'xkeysib-real-key']);
        $this->assertSame(800, RoadCapacity::bulkTotal($c), 'SMTP and the API together, not the SMTP account alone');
        DB::table('gates_settings')->where('key_name', RoadCapacity::API_KEY)->delete();
        $this->assertSame(0, RoadCapacity::bulkTotal($c), 'an API with an unknown allowance: the cap steps aside');
    }
}
