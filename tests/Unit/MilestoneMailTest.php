<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MilestoneService;
use AfricaGates\Services\Notifier;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The milestone alert reads the alert address from the one resolver, and a nominee's name is
 * escaped in it. And no operator's own inbox is typed into the code as a fallback.
 */
final class MilestoneMailTest extends TestCase
{
    /** @var list<array<string,string>> */
    private array $sent = [];

    private function mailer(): OtpService
    {
        return new class($this->sent) extends OtpService {
            public function __construct(private array &$sink) { parent::__construct([]); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody];
                return ['success' => true];
            }
        };
    }

    private function notify(object $nominee): void
    {
        $svc = new MilestoneService($this->mailer());
        (fn() => $this->notifyAdmin($nominee, 1000))->call($svc);
    }

    public function test_the_alert_goes_where_settings_says_and_escapes_the_nominee(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'admin_alert_email'], ['value' => 'alerts@gates-ops.ng']);
        $this->notify((object) ['id' => 1, 'name' => '<img src=x onerror=alert(1)>Ada', 'vote_count' => 1001]);

        $this->assertCount(1, $this->sent);
        $this->assertSame('alerts@gates-ops.ng', $this->sent[0]['to'], 'an address set in /admin/settings is the one used');
        $this->assertStringNotContainsString('<img', $this->sent[0]['html']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;Ada', $this->sent[0]['html']);
    }

    public function test_with_no_address_set_anywhere_nothing_is_mailed_and_nobody_is_guessed(): void
    {
        $prev = [$_ENV['ADMIN_ALERT_EMAIL'] ?? null, $_ENV['MAIL_FROM_ADDRESS'] ?? null];
        $_ENV['ADMIN_ALERT_EMAIL'] = ''; $_ENV['MAIL_FROM_ADDRESS'] = '';
        putenv('ADMIN_ALERT_EMAIL='); putenv('MAIL_FROM_ADDRESS=');
        try {
            $this->assertSame('', Notifier::adminEmail());
            $this->notify((object) ['id' => 1, 'name' => 'Ada', 'vote_count' => 1001]);
            $this->assertSame([], $this->sent);
        } finally {
            foreach (['ADMIN_ALERT_EMAIL', 'MAIL_FROM_ADDRESS'] as $i => $k) {
                if ($prev[$i] === null) unset($_ENV[$k]); else $_ENV[$k] = $prev[$i];
            }
        }
    }

    public function test_no_operator_inbox_is_typed_into_the_code_or_the_examples(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['src/Services/Notifier.php', 'src/Services/MilestoneService.php', '.env.example', 'database/setup-sqlite.php'] as $f) {
            $this->assertStringNotContainsString('app.admin@', (string) file_get_contents($root . '/' . $f), $f);
        }
        $this->assertDoesNotMatchRegularExpression("/Env::get\\('ADMIN_ALERT_EMAIL'/",
            (string) file_get_contents($root . '/src/Services/MilestoneService.php'), 'one resolver: Notifier::adminEmail()');
    }
}
