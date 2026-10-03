<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Mail\BrevoApi;
use AfricaGates\Services\Mail\MailConfig;
use AfricaGates\Services\Mail\MailDiagnosis;
use AfricaGates\Services\Mail\MailFailure;
use AfricaGates\Services\Mail\MailSetup;
use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use Tests\TestCase;

/**
 * How a message leaves, and what is allowed to change that.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE INPUT WAS WRONG, NOT THE CODE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Mail worked from the server's `.env` on every branch before the settings page grew
 * SMTP fields, and the transport that sends it is unchanged since. What changed is that a
 * value stored through the page outranks `.env`, and the page re-posted the SMTP login on
 * every save. So the cases here are about the INPUT and the ROADS: a login is stored only
 * once it has logged in; `.env` can be put back in charge in one press; Google's own rules
 * (an App Password shown with spaces, a daily allowance that stops everything when spent)
 * are built in; and when SMTP itself is the problem, mail still leaves by another road.
 */
final class MailTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'UTC'));
        SchemaHas::forget();
        SendPolicy::forget();
        foreach (['gates_mail_log', 'gates_mail_suppression', 'gates_email_optout', 'gates_mail_incidents'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('gates_settings')->whereIn('key_name', array_merge(MailSetup::SMTP_KEYS,
            [MailSetup::TRANSPORT_KEY, MailSetup::API_KEY, OtpService::SMTP_REST_KEY, 'mail_daily_limit', SendPolicy::CAP_KEY]))->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        SendPolicy::forget();
        parent::tearDown();
    }

    /**
     * The real pipeline, stopping at each road's one line that leaves the process.
     *
     * @param array<string,\Closure|null> $fail road => throw this
     */
    private function mailer(array $fail = [], string $transport = MailConfig::TRANSPORT_AUTO, string $apiKey = 'xkeysib-test'): OtpService
    {
        return new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'news@africagates.org', 'from_name' => 'Africa GATES',
                          'transport' => $transport, 'api_key' => $apiKey], $fail) extends OtpService {
            /** @var list<string> */
            public array $roads = [];
            public function __construct(array $smtp, private array $fail) { parent::__construct($smtp); }
            protected function transmit(PHPMailer $m): void
            {
                $road = $m->Mailer === 'mail' ? 'host' : 'smtp';
                $this->roads[] = $road;
                if (isset($this->fail[$road])) ($this->fail[$road])();
            }
            protected function transmitApi(PHPMailer $m): void
            {
                $this->roads[] = 'api';
                if (isset($this->fail['api'])) ($this->fail['api'])();
            }
        };
    }

    private static function throws(string $msg): \Closure
    {
        return static function () use ($msg): void { throw new MailException($msg); };
    }

    // ══ The roads ════════════════════════════════════════════════════════════

    public function test_when_smtp_cannot_connect_the_message_goes_by_the_api(): void
    {
        $m = $this->mailer(['smtp' => self::throws('SMTP Error: Could not connect to SMTP host. Failed to connect to server')]);
        $r = $m->sendCustom('ada@africagates.org', 'Your sign-in code', '123456');

        $this->assertTrue($r['success']);
        $this->assertSame('api', $r['via'] ?? null);
        $this->assertSame(['smtp', 'api'], $m->roads);
        $row = DB::table('gates_mail_log')->first();
        $this->assertSame('sent', $row->status);
        $this->assertStringContainsString('via api after: smtp', (string) $row->error,
            'a fallback that hides the SMTP fault leaves it unfixed for ever');
    }

    public function test_a_failing_smtp_road_is_rested_so_the_next_message_does_not_wait_on_it(): void
    {
        $m = $this->mailer(['smtp' => self::throws('SMTP connect() failed.')]);
        $m->sendCustom('ada@africagates.org', 'One', 'x');
        $m->roads = [];
        $m->sendCustom('ada@africagates.org', 'Two', 'x');
        $this->assertSame(['api'], $m->roads, 'every message paid the SMTP timeout again');

        Carbon::setTestNow(Carbon::now()->addMinutes(OtpService::SMTP_REST_MIN + 1));
        $this->assertSame('smtp', $this->mailer()->routes()[0] ?? null, 'the rest never ends');
    }

    public function test_a_refused_recipient_does_not_try_another_road(): void
    {
        $m = $this->mailer(['smtp' => self::throws('SMTP Error: The following recipients failed: nobody@x.org: 550 5.1.1 user unknown')]);
        $r = $m->sendCustom('nobody@africagates.org', 'Hello', 'x');
        $this->assertFalse($r['success']);
        $this->assertSame(['smtp'], $m->roads, 'the same mailbox refused by a second road is a second bounce');
    }

    public function test_every_road_failing_is_one_failure_naming_each(): void
    {
        $m = $this->mailer(['smtp' => self::throws('SMTP Error: Could not authenticate.'),
                            'api' => self::throws('Brevo API: the API key was not accepted (401 Unauthorized)')]);
        $r = $m->sendCustom('ada@africagates.org', 'Hello', 'x');
        $this->assertFalse($r['success']);
        $err = (string) DB::table('gates_mail_log')->value('error');
        $this->assertStringContainsString('smtp: SMTP Error: Could not authenticate.', $err);
        $this->assertStringContainsString('api: Brevo API', $err);
        $this->assertSame(1, DB::table('gates_mail_log')->count());
    }

    public function test_smtp_only_means_smtp_only(): void
    {
        $m = $this->mailer(['smtp' => self::throws('SMTP connect() failed.')], MailConfig::TRANSPORT_SMTP);
        $this->assertFalse($m->sendCustom('ada@africagates.org', 'Hello', 'x')['success']);
        $this->assertSame(['smtp'], $m->roads);
    }

    public function test_a_platform_with_only_an_api_key_can_send(): void
    {
        $m = new OtpService(['transport' => MailConfig::TRANSPORT_API, 'api_key' => 'xkeysib-1', 'username' => '', 'password' => '']);
        $this->assertFalse($m->smtpConfigured());
        $this->assertTrue($m->canSend(), 'every batch job asked smtpConfigured() and called mail off');
    }

    // ══ Google ═══════════════════════════════════════════════════════════════

    public function test_a_google_app_password_pasted_with_its_spaces_is_the_password(): void
    {
        $g = MailConfig::load(['mail_smtp_host' => 'smtp.gmail.com', 'mail_smtp_user' => 'a@gmail.com',
                               'mail_smtp_pass' => 'abcd efgh ijkl mnop']);
        $this->assertSame('abcdefghijklmnop', $g->password);
        $this->assertSame('google', $g->provider());

        $other = MailConfig::load(['mail_smtp_host' => 'smtp.example.org', 'mail_smtp_user' => 'a', 'mail_smtp_pass' => 'abcd efgh ijkl mnop']);
        $this->assertSame('abcd efgh ijkl mnop', $other->password, 'only Google’s format is normalised');
        $odd = MailConfig::load(['mail_smtp_host' => 'smtp.gmail.com', 'mail_smtp_user' => 'a', 'mail_smtp_pass' => 'my pass 1']);
        $this->assertSame('my pass 1', $odd->password, 'a value that is not an App Password is left as typed');
    }

    public function test_announcements_stop_short_of_googles_daily_allowance_and_codes_do_not(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'mail_smtp_host', 'value' => 'smtp.gmail.com'],
            ['key_name' => 'mail_smtp_user', 'value' => 'office@gmail.com'],
            ['key_name' => 'mail_smtp_pass', 'value' => 'abcdefghijklmnop'],
        ]);
        $this->assertSame(500, MailConfig::load()->dailyLimit());
        $rows = [];
        for ($i = 0; $i < (int) (500 * SendPolicy::BULK_SHARE); $i++) {
            $rows[] = ['to_masked' => 'x', 'subject' => 's', 'status' => 'sent', 'created_at' => '2026-10-02 09:00:00'];
        }
        foreach (array_chunk($rows, 200) as $c) DB::table('gates_mail_log')->insert($c);
        SendPolicy::forget();

        $this->assertSame(SendPolicy::DEFERRED, SendPolicy::decide('reader@africagates.org', true)['status'] ?? null,
            'the newsletter went on spending the allowance sign-in codes need');
        $this->assertNull(SendPolicy::decide('reader@africagates.org', false), 'a sign-in code was held by an announcement rule');
    }

    public function test_googles_refusals_are_explained_for_google(): void
    {
        $this->assertSame(MailFailure::AUTH, MailFailure::classify('535-5.7.8 Username and Password not accepted.'));
        $this->assertStringContainsString('App Password', MailFailure::fix(MailFailure::AUTH, 'google'));
        $this->assertStringNotContainsString('App Password', MailFailure::fix(MailFailure::AUTH, 'brevo'));
        $this->assertSame(MailFailure::QUOTA, MailFailure::classify('550 5.4.5 Daily user sending limit exceeded.'));
    }

    // ══ Saving ═══════════════════════════════════════════════════════════════

    public function test_a_login_the_provider_refuses_is_not_stored_and_the_old_one_stays(): void
    {
        DB::table('gates_settings')->insert([['key_name' => 'mail_smtp_user', 'value' => 'office@gmail.com'],
                                              ['key_name' => 'mail_smtp_pass', 'value' => 'abcdefghijklmnop']]);
        $no = new MailSetup(static fn (MailConfig $c): array => ['ok' => false, 'title' => 'The provider refused our login', 'fix' => 'Use an App Password.', 'steps' => []]);
        $r = $no->save(['transport' => 'auto', 'host' => 'smtp.gmail.com', 'username' => 'admin@africagates.org', 'password' => 'hunter2']);

        $this->assertFalse($r['ok']);
        $this->assertSame('office@gmail.com', DB::table('gates_settings')->where('key_name', 'mail_smtp_user')->value('value'),
            'a refused login replaced the working one');
        $this->assertSame('abcdefghijklmnop', DB::table('gates_settings')->where('key_name', 'mail_smtp_pass')->value('value'));
        $this->assertStringContainsString('NOT saved', implode(' ', $r['messages']));
    }

    public function test_a_login_the_provider_accepts_is_stored_and_the_check_saw_it(): void
    {
        $seen = null;
        $yes = new MailSetup(static function (MailConfig $c) use (&$seen): array { $seen = $c; return ['ok' => true, 'steps' => []]; });
        $r = $yes->save(['transport' => 'auto', 'host' => 'smtp.gmail.com', 'port' => '587', 'secure' => 'auto',
                         'username' => 'office@gmail.com', 'password' => 'abcd efgh ijkl mnop']);
        $this->assertTrue($r['ok'], implode(' ', $r['messages']));
        $this->assertSame('office@gmail.com', $seen->username, 'the check tried something other than what was saved');
        $this->assertSame('abcdefghijklmnop', $seen->password);
        $this->assertSame('smtp.gmail.com', MailConfig::load()->host);
    }

    public function test_an_unchanged_form_tries_nothing_and_stores_nothing(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'mail_smtp_user', 'value' => 'office@gmail.com']);
        $called = false;
        $s = new MailSetup(static function () use (&$called): array { $called = true; return ['ok' => false]; });
        $s->save(['transport' => 'auto', 'username' => 'office@gmail.com', 'password' => '']);
        $this->assertFalse($called, 'an empty password box is "keep it", not a new login to try');
    }

    public function test_an_api_key_is_stored_only_once_brevo_accepts_it(): void
    {
        $s = new MailSetup(null, static fn (string $k): array => ['ok' => $k === 'good', 'detail' => $k === 'good' ? 'ok' : 'not accepted']);
        $s->save(['transport' => 'auto', 'api_key' => 'bad']);
        $this->assertFalse(MailConfig::load()->hasApiKey());
        $s->save(['transport' => 'auto', 'api_key' => 'good']);
        $this->assertSame('good', MailConfig::load()->apiKey);
    }

    public function test_use_env_puts_the_servers_file_back_in_charge(): void
    {
        DB::table('gates_settings')->insert([['key_name' => 'mail_smtp_user', 'value' => 'admin@africagates.org'],
                                              ['key_name' => 'mail_smtp_pass', 'value' => 'stored-pass'],
                                              ['key_name' => 'mail_smtp_host', 'value' => 'smtp.wrong.example']]);
        $this->assertSame('settings', MailConfig::load()->source('username'));
        MailSetup::useEnv();
        $this->assertNotSame('settings', MailConfig::load()->source('username'));
        $this->assertSame(0, DB::table('gates_settings')->whereIn('key_name', MailSetup::SMTP_KEYS)->count());
    }

    // ══ What broke on production: half a login, and a road that loses mail ═══

    /** The `.env` login every branch before September sent with. */
    private static function withEnvLogin(\Closure $fn): void
    {
        $keys = ['SMTP_HOST' => 'smtp-relay.brevo.com', 'SMTP_PORT' => '587', 'SMTP_USER' => 'relay-login@smtp-brevo.com', 'SMTP_PASS' => 'env-key'];
        foreach ($keys as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        try { $fn(); }
        finally { foreach ($keys as $k => $_) { putenv($k); unset($_ENV[$k], $_SERVER[$k]); } }
    }

    public function test_half_a_login_in_settings_is_never_sent_with_the_other_half_from_env(): void
    {
        // What the September page left behind: it re-posted the SMTP fields on every
        // save, so a username and a host were stored and the password never was.
        DB::table('gates_settings')->insert([['key_name' => 'mail_smtp_user', 'value' => 'admin@africagates.org'],
                                              ['key_name' => 'mail_smtp_host', 'value' => 'smtp.wrong.example']]);
        self::withEnvLogin(function (): void {
            $c = MailConfig::load();
            $this->assertSame('relay-login@smtp-brevo.com', $c->username, 'a stored username went out with .env\'s password');
            $this->assertSame('smtp-relay.brevo.com', $c->host, 'a stored host went out with .env\'s login');
            $this->assertSame('env-key', $c->password);
            $this->assertSame('env', $c->source('username'));
            $this->assertNull($c->envSmtp, '.env is already the login in force');
        });
    }

    public function test_a_complete_stored_login_brings_its_own_host_and_keeps_env_as_a_second_road(): void
    {
        DB::table('gates_settings')->insert([['key_name' => 'mail_smtp_user', 'value' => 'admin@africagates.org'],
                                              ['key_name' => 'mail_smtp_pass', 'value' => 'stored-pass']]);
        self::withEnvLogin(function (): void {
            $c = MailConfig::load();
            $this->assertSame('admin@africagates.org', $c->username);
            $this->assertSame(MailConfig::DEFAULT_HOST, $c->host, 'a blank stored host means the Brevo relay, as the form says — not .env\'s host');
            $this->assertSame('default', $c->source('host'));
            $this->assertSame('relay-login@smtp-brevo.com', $c->envSmtp['username'] ?? null);
            $this->assertSame(['smtp', 'smtp-env'], OtpService::fromConfig($c)->routes());
        });
    }

    public function test_when_the_stored_login_is_refused_the_env_login_carries_the_message(): void
    {
        $m = new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'stored', 'password' => 'bad',
                        'from_address' => 'noreply@afrovanguard.org.ng', 'from_name' => 'Africa GATES',
                        'transport' => MailConfig::TRANSPORT_AUTO, 'api_key' => '',
                        'smtp_env' => ['host' => 'smtp-relay.brevo.com', 'port' => 587, 'secure' => 'auto',
                                       'username' => 'env-login', 'password' => 'env-key']]) extends OtpService {
            /** @var list<string> */
            public array $logins = [];
            protected function transmit(PHPMailer $m): void
            {
                $this->logins[] = $m->Username . '@' . $m->Host . ($m->Mailer === 'mail' ? ' (host)' : '');
                if ($m->Username === 'stored') throw new MailException('SMTP Error: Could not authenticate.');
            }
        };
        $r = $m->sendCustom('ada@africagates.org', 'Your sign-in code', '123456');
        $this->assertTrue($r['success']);
        $this->assertSame('smtp-env', $r['via'] ?? null);
        $this->assertSame(['stored@smtp.test', 'env-login@smtp-relay.brevo.com'], $m->logins);
        $this->assertStringContainsString('via smtp-env after: smtp', (string) DB::table('gates_mail_log')->value('error'),
            'the stored login is still broken, and the log must say so');
    }

    public function test_a_failed_smtp_send_is_a_failure_and_never_goes_out_as_the_server(): void
    {
        // Production: the host's mail() is available. It used to be `auto`'s last road, so
        // a failed SMTP send became a "sent" message the server sent as itself and Gmail
        // discarded unseen.
        putenv('APP_ENV=production'); $_ENV['APP_ENV'] = 'production';
        try {
            $m = $this->mailer(['smtp' => self::throws('SMTP Error: Could not authenticate.')], MailConfig::TRANSPORT_AUTO, '');
            if (!MailConfig::hostMailAvailable()) $this->markTestSkipped('mail() is not available here');
            $this->assertSame(['smtp'], $m->routes());
            $r = $m->sendCustom('ada@africagates.org', 'Your sign-in code', '123456');
            $this->assertFalse($r['success'], 'a message the server sent as itself was reported as delivered');
            $this->assertSame(['smtp'], $m->roads);
            $this->assertSame('failed', DB::table('gates_mail_log')->value('status'));

            // Resting SMTP never moves it behind the server's own mail either.
            DB::table('gates_settings')->updateOrInsert(['key_name' => OtpService::SMTP_REST_KEY],
                ['value' => Carbon::now()->addMinutes(10)->toDateTimeString()]);
            $this->assertSame(['smtp'], $this->mailer([], MailConfig::TRANSPORT_AUTO, '')->routes());
        } finally {
            putenv('APP_ENV'); unset($_ENV['APP_ENV']);
        }
    }

    public function test_the_servers_own_mail_is_a_road_only_when_nothing_else_is_set_up_or_it_is_chosen(): void
    {
        putenv('APP_ENV=production'); $_ENV['APP_ENV'] = 'production';
        try {
            if (!MailConfig::hostMailAvailable()) $this->markTestSkipped('mail() is not available here');
            $none = new OtpService(['transport' => MailConfig::TRANSPORT_AUTO, 'username' => '', 'password' => '', 'api_key' => '']);
            $this->assertSame(['host'], $none->routes());
            $chosen = new OtpService(['transport' => MailConfig::TRANSPORT_HOST, 'username' => 'u', 'password' => 'p']);
            $this->assertSame(['host'], $chosen->routes());
        } finally {
            putenv('APP_ENV'); unset($_ENV['APP_ENV']);
        }
    }

    public function test_the_check_never_calls_a_failing_smtp_road_healthy_because_mail_is_on_the_server(): void
    {
        if (!MailConfig::hostMailAvailable()) $this->markTestSkipped('mail() is not available here');
        // Port 1 on loopback refuses at once: an SMTP road that is down, with no API key.
        $c = MailConfig::of(['host' => '127.0.0.1', 'port' => 1, 'secure' => 'none', 'username' => 'u', 'password' => 'p',
                             'transport' => 'auto', 'api_key' => '']);
        $r = MailDiagnosis::roads($c, null, true);
        $this->assertFalse($r['ok'], 'the hourly check called a dead SMTP road healthy because mail() exists');
        $this->assertNotSame('host', $r['road']);

        $nothing = MailConfig::of(['transport' => 'auto', 'username' => '', 'password' => '', 'api_key' => '']);
        $this->assertSame('host', MailDiagnosis::roads($nothing, null, true)['road'], 'with nothing set up it is still the only road');
    }

    // ══ The check, and the API message ═══════════════════════════════════════

    public function test_a_fallback_carrying_the_mail_reads_as_working_and_degraded(): void
    {
        $c = MailConfig::of(['transport' => 'auto', 'api_key' => 'k', 'username' => '', 'password' => '']);
        $r = MailDiagnosis::roads($c, static fn (): array => ['ok' => true, 'detail' => 'fine'], false);
        $this->assertTrue($r['ok']);
        $this->assertSame('api', $r['road']);

        $r = MailDiagnosis::roads(MailConfig::of(['transport' => 'api', 'api_key' => 'k']),
            static fn (): array => ['ok' => false, 'detail' => '401'], false);
        $this->assertFalse($r['ok'], 'an API-only platform with a refused key reported healthy');
    }

    public function test_the_api_sends_the_message_that_was_built(): void
    {
        $m = new PHPMailer(true);
        $m->setFrom('news@africagates.org', 'Africa GATES');
        $m->addAddress('ada@africagates.org');
        $m->addReplyTo('help@africagates.org');
        $m->isHTML(true);
        $m->Subject = 'Hello';
        $m->Body = '<p>Hi</p>';
        $m->AltBody = 'Hi';
        $m->MessageID = '<abc@africagates.org>';
        $m->addCustomHeader('List-Unsubscribe', '<https://africagates.org/u/1>');
        $m->addStringAttachment('PDF', 'receipt.pdf');

        $p = BrevoApi::payload($m);
        $this->assertSame(['email' => 'news@africagates.org', 'name' => 'Africa GATES'], $p['sender']);
        $this->assertSame('ada@africagates.org', $p['to'][0]['email']);
        $this->assertSame('<p>Hi</p>', $p['htmlContent']);
        $this->assertSame('Hi', $p['textContent']);
        $this->assertSame('help@africagates.org', $p['replyTo']['email']);
        $this->assertSame('<https://africagates.org/u/1>', $p['headers']['List-Unsubscribe'], 'the one-click unsubscribe was lost on the API road');
        $this->assertSame('<abc@africagates.org>', $p['headers']['Message-Id']);
        $this->assertSame(base64_encode('PDF'), $p['attachment'][0]['content']);

        $calls = [];
        $api = new BrevoApi('key', static function (string $method, string $url, array $h, ?string $body) use (&$calls): array {
            $calls[] = compact('method', 'url', 'h');
            return ['status' => 401, 'body' => '{"message":"Key not found"}', 'error' => ''];
        });
        try {
            $api->send($m);
            $this->fail('a 401 was reported as sent');
        } catch (MailException $e) {
            $this->assertSame(MailFailure::AUTH, MailFailure::classify($e->getMessage()));
        }
        $this->assertSame('key', $calls[0]['h']['api-key']);
    }
}
