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
    private function mailer(array $fail = [], string $transport = MailConfig::TRANSPORT_AUTO, string $apiKey = 'xkeysib-test',
                            bool $gas = false): OtpService
    {
        return new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'news@africagates.org', 'from_name' => 'Africa GATES',
                          'transport' => $transport, 'api_key' => $apiKey, 'gas' => $gas], $fail) extends OtpService {
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
            protected function transmitGas(PHPMailer $m): void
            {
                $this->roads[] = 'gas';
                if (isset($this->fail['gas'])) ($this->fail['gas'])();
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
        // The cause matters now: only a provider that ANSWERED and judged the login may
        // refuse the save. See MailSetup::judgedTheLogin().
        $no = new MailSetup(static fn (MailConfig $c): array => ['ok' => false, 'cause' => MailFailure::AUTH,
            'title' => 'The provider refused our login', 'fix' => 'Use an App Password.', 'steps' => []]);
        $r = $no->save(['transport' => 'auto', 'host' => 'smtp.gmail.com', 'username' => 'admin@africagates.org', 'password' => 'hunter2']);

        $this->assertFalse($r['ok']);
        $this->assertSame('office@gmail.com', DB::table('gates_settings')->where('key_name', 'mail_smtp_user')->value('value'),
            'a refused login replaced the working one');
        $this->assertSame('abcdefghijklmnop', DB::table('gates_settings')->where('key_name', 'mail_smtp_pass')->value('value'));
        $this->assertStringContainsString('not saved', implode(' ', $r['messages']));
    }

    /**
     * ── THE GUARD WAS BLOCKING THE ONLY FIX AVAILABLE ───────────────────────────────
     *
     * The save was gated on the whole diagnosis passing, so while SMTP was broken the
     * SMTP settings could not be changed at all. The host blocks 587; the operator moves
     * to 465 or 2525, which is the one change that would fix it; the check still fails on
     * the road, so the save is refused. The only fields that can route around a blocked
     * road were the only fields a blocked road prevented changing — and the refusal told
     * them "what was working before still is", to somebody for whom nothing had worked in
     * days.
     *
     * A road failure never reaches the login, so the check has formed no opinion of it and
     * has no standing to refuse it.
     */
    public function test_a_road_failure_does_not_block_changing_the_road(): void
    {
        DB::table('gates_settings')->insert([['key_name' => 'mail_smtp_host', 'value' => 'smtp.gmail.com'],
                                              ['key_name' => 'mail_smtp_port', 'value' => '587'],
                                              ['key_name' => 'mail_smtp_user', 'value' => 'office@gmail.com'],
                                              ['key_name' => 'mail_smtp_pass', 'value' => 'abcdefghijklmnop']]);
        $blocked = new MailSetup(static fn (MailConfig $c): array => ['ok' => false, 'cause' => MailFailure::TLS,
            'title' => 'The encrypted connection failed', 'fix' => 'Something is answering in their place.', 'steps' => []]);

        $r = $blocked->save(['transport' => 'auto', 'host' => 'smtp.gmail.com', 'port' => '2525',
                             'secure' => 'auto', 'username' => 'office@gmail.com', 'password' => '']);

        $this->assertSame('2525', DB::table('gates_settings')->where('key_name', 'mail_smtp_port')->value('value'),
            'the operator could not move off a blocked port while it was blocked');
        $this->assertContains('mail_smtp_port', $r['saved']);
        // Saved is not the same as working, and the screen must not imply it is.
        $this->assertStringContainsString('unverified', implode(' ', $r['messages']));
        $this->assertStringContainsString('The encrypted connection failed', implode(' ', $r['messages']));
    }

    /** The refusal must never claim a working configuration that does not exist. */
    public function test_a_refusal_does_not_claim_something_was_working_before(): void
    {
        $no = new MailSetup(static fn (MailConfig $c): array => ['ok' => false, 'cause' => MailFailure::AUTH,
            'title' => 'The provider refused our login', 'fix' => '', 'steps' => []]);
        $r = $no->save(['transport' => 'auto', 'host' => 'smtp.gmail.com',
                        'username' => 'a@b.com', 'password' => 'hunter2']);

        $this->assertStringNotContainsString('was working before', implode(' ', $r['messages']),
            'nothing here knows whether anything was working before');
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
                                              ['key_name' => 'mail_smtp_host', 'value' => 'smtp.wrong.example']]);
        $this->assertSame('settings', MailConfig::load()->source('username'));
        MailSetup::useEnv();
        $this->assertNotSame('settings', MailConfig::load()->source('username'));
        $this->assertSame(0, DB::table('gates_settings')->whereIn('key_name', MailSetup::SMTP_KEYS)->count());
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

    // ══ Google Apps Script — the road when Google SMTP fails ══════════════════

    public function test_when_google_smtp_fails_a_sign_in_code_goes_by_apps_script(): void
    {
        $m = $this->mailer(['smtp' => self::throws('535-5.7.8 Username and Password not accepted.')],
                           MailConfig::TRANSPORT_AUTO, '', true);
        $r = $m->sendCustom('ada@africagates.org', 'Your sign-in code', '123456');
        $this->assertTrue($r['success']);
        $this->assertSame(['smtp', 'gas'], $m->roads, 'Apps Script must be the next road after Google SMTP');
        $this->assertStringContainsString('via gas after: smtp', (string) DB::table('gates_mail_log')->value('error'));
    }

    public function test_an_announcement_never_spends_the_apps_script_allowance(): void
    {
        $m = $this->mailer(['smtp' => self::throws('SMTP connect() failed.')], MailConfig::TRANSPORT_AUTO, '', true);
        $m->sendRawHtml('reader@africagates.org', 'This week', '<p>news</p>', 'news', 'newsletter',
                        'https://africagates.org/u/1');
        $this->assertNotContains('gas', $m->roads, 'a newsletter took the road sign-in codes depend on');

        $only = $this->mailer([], MailConfig::TRANSPORT_GAS, '', true);
        $r = $only->sendRawHtml('reader@africagates.org', 'This week', '<p>news</p>', 'news', 'newsletter',
                                'https://africagates.org/u/1');
        $this->assertSame('deferred', $r['held'] ?? null, 'an announcement with only Apps Script open is held, not failed');
        $this->assertSame([], $only->roads);
        $this->assertTrue($only->canSend(), 'one-to-one mail can go');
        $this->assertFalse($only->canSend(true), 'the newsletter run would start with nowhere to send it');
    }

    public function test_the_apps_script_gets_the_message_that_was_built_and_the_secret(): void
    {
        $m = new PHPMailer(true);
        $m->setFrom('news@africagates.org', 'Africa GATES');
        $m->addAddress('ada@africagates.org');
        $m->addReplyTo('help@africagates.org');
        $m->isHTML(true);
        $m->Subject = 'Your code';
        $m->Body = '<p>123456</p>';
        $m->AltBody = '123456';
        $m->addStringAttachment('PDF', 'receipt.pdf', 'base64', 'application/pdf');

        $sent = null;
        $gas = new \AfricaGates\Services\Mail\AppsScriptMail('https://script.google.com/macros/s/x/exec', 's3cret',
            static function (string $url, array $payload) use (&$sent): array {
                $sent = $payload;
                return ['status' => 200, 'body' => '{"success":true,"ok":true,"message":"Sent","remaining":97}', 'error' => ''];
            });
        $gas->send($m);

        $this->assertSame('mail.send', $sent['action']);
        $this->assertSame('s3cret', $sent['token'], 'the script refuses mail without its secret');
        $this->assertSame('ada@africagates.org', $sent['data']['to']);
        $this->assertSame('<p>123456</p>', $sent['data']['html']);
        $this->assertSame('123456', $sent['data']['text']);
        $this->assertSame('help@africagates.org', $sent['data']['reply_to']);
        $this->assertSame('application/pdf', $sent['data']['attachments'][0]['mime']);
        $this->assertSame(base64_encode('PDF'), $sent['data']['attachments'][0]['content']);
    }

    public function test_an_old_deployment_is_named_as_such(): void
    {
        $gas = new \AfricaGates\Services\Mail\AppsScriptMail('https://x/exec', 's',
            static fn (): array => ['status' => 200, 'body' => '{"success":false,"message":"Unknown action: mail.send"}', 'error' => '']);
        $r = $gas->check();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('New version', $r['detail']);

        $none = new \AfricaGates\Services\Mail\AppsScriptMail('https://x/exec', '', static fn (): array => ['status' => 200, 'body' => '{}', 'error' => '']);
        $this->assertStringContainsString('secret', $none->check()['detail']);
    }

    public function test_the_health_check_counts_apps_script_as_a_working_road(): void
    {
        $c = MailConfig::of(['transport' => 'auto', 'username' => '', 'password' => '']);
        $r = MailDiagnosis::roads($c, null, false, static fn (): array => ['ok' => true, 'detail' => 'ok', 'remaining' => 90]);
        $this->assertTrue($r['ok']);
        $this->assertSame('gas', $r['road']);
    }

    /** The shipped script carries the action, behind the secret, using MailApp. */
    public function test_the_shipped_script_sends_mail_behind_its_secret(): void
    {
        $gs = (string) file_get_contents(dirname(__DIR__, 2) . '/config/AfricaGATES_AppScript.gs');
        $this->assertStringContainsString("action === 'mail.send'", $gs);
        $this->assertStringContainsString('MailApp.sendEmail', $gs);
        $this->assertLessThan(strpos($gs, "action === 'mail.send'"), strpos($gs, "if(body.token !== SECRET)"),
            'the mail action must sit behind the token check — an open relay on a public URL');
    }

    /**
     * A failed SMTP attempt runs preSend(), which rewrites ContentType from text/html to
     * multipart/alternative — and every fallback road runs after one. The roads asked the
     * type and sent the branded HTML as plain text: a sign-in code as five kilobytes of
     * markup. Measured end to end before this test existed.
     */
    public function test_after_a_failed_smtp_attempt_the_fallback_still_sends_html(): void
    {
        $seen = [];
        $m = new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p',
                        'from_address' => 'news@africagates.org', 'transport' => 'auto', 'api_key' => 'k', 'gas' => true], $seen)
            extends OtpService {
            public function __construct(array $smtp, private array &$seen) { parent::__construct($smtp); }
            protected function transmit(PHPMailer $m): void
            {
                $m->preSend();
                throw new MailException('SMTP connect() failed.');
            }
            protected function transmitGas(PHPMailer $m): void
            {
                $this->seen['gas'] = \AfricaGates\Services\Mail\AppsScriptMail::payload($m);
                throw new MailException('Apps Script: could not reach it');
            }
            protected function transmitApi(PHPMailer $m): void
            {
                $this->seen['api'] = BrevoApi::payload($m);
            }
        };
        $r = $m->sendBranded('ada@africagates.org', 'Your sign-in code', '<p>Your code is <b>123456</b></p>', 'Your code is 123456');

        $this->assertTrue($r['success']);
        $this->assertStringContainsString('<b>123456</b>', $seen['gas']['html'], 'Apps Script got the HTML as text');
        $this->assertSame('Your code is 123456', trim($seen['gas']['text']));
        $this->assertStringContainsString('<b>123456</b>', $seen['api']['htmlContent'] ?? '', 'the API got the HTML as text');
    }

    /** The screen an operator fixes mail from: the roads, the form, and the Apps Script steps. */
    public function test_email_health_offers_every_road_and_the_apps_script_setup(): void
    {
        DB::table('gates_settings')->whereIn('key_name', ['gas_url', 'gas_secret'])->delete();
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctl = $b->build()->get(\AfricaGates\Admin\Controllers\MailHealthController::class);
        $html = (string) $ctl->index((new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/admin/settings/mail'),
                                     new \Slim\Psr7\Response())->getBody();

        $this->assertStringContainsString('action="/admin/settings/mail/sending"', $html);
        foreach (MailConfig::TRANSPORTS as $t) {
            $this->assertStringContainsString('<option value="' . $t . '"', $html, "no way to choose $t");
        }
        $this->assertStringContainsString('const SECRET', $html);
        $this->assertStringNotContainsString('name="mail_smtp_pass"', $html);
        // The two Apps Script boxes are on THIS page, in a form of their own.
        $this->assertStringContainsString('action="/admin/settings/mail/apps-script"', $html);
        $this->assertStringContainsString('name="gas_url"', $html);
        $this->assertStringContainsString('name="gas_secret"', $html);
        $this->assertStringContainsString('href="#apps-script"', $html, 'the sending card points down to it');
    }

    private const GAS = 'https://script.google.com/macros/s/AKfyTEST/exec';

    /** @return \Closure(string,string):array{ok:bool,detail:string} */
    private static function answers(array $r, ?array &$asked = null): \Closure
    {
        return static function (string $u, string $s) use ($r, &$asked): array { $asked = [$u, $s]; return $r; };
    }

    private static function stored(string $k): ?string
    {
        $v = DB::table('gates_settings')->where('key_name', $k)->value('value');
        return $v === null ? null : (string) $v;
    }

    public function test_apps_script_is_stored_once_the_script_answers(): void
    {
        DB::table('gates_settings')->whereIn('key_name', ['gas_url', 'gas_secret'])->delete();
        $r = MailSetup::saveAppsScript(self::GAS, 'long-secret', null,
            self::answers(['ok' => true, 'detail' => 'The Apps Script answered; it may send to 99 more recipients today.'], $asked));
        $this->assertTrue($r['ok']);
        $this->assertSame([self::GAS, 'long-secret'], $asked, 'the CANDIDATE is what is asked');
        $this->assertSame(self::GAS, self::stored('gas_url'));
        $this->assertSame('long-secret', self::stored('gas_secret'));
        $this->assertStringContainsString('99 more', $r['message']);
    }

    /** The calendar uses this secret too: one the script refuses must not replace one it accepts. */
    public function test_a_secret_the_script_refuses_is_not_stored(): void
    {
        DB::table('gates_settings')->whereIn('key_name', ['gas_url', 'gas_secret'])->delete();
        DB::table('gates_settings')->insert([['key_name' => 'gas_url', 'value' => self::GAS], ['key_name' => 'gas_secret', 'value' => 'the-good-one']]);
        $r = MailSetup::saveAppsScript('', 'typo', null, self::answers(['ok' => false, 'detail' => 'Bad token']));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('refused the secret', $r['message']);
        $this->assertSame('the-good-one', self::stored('gas_secret'));
    }

    public function test_a_test_address_or_a_non_address_is_refused_before_anything_is_asked(): void
    {
        DB::table('gates_settings')->whereIn('key_name', ['gas_url', 'gas_secret'])->delete();
        foreach (['https://script.google.com/macros/s/AKfyTEST/dev', 'script.google.com/exec',
                  'http://script.google.com/macros/s/AKfyTEST/exec', 'https://script.google.com/home/projects/abc/edit'] as $bad) {
            $asked = null;
            $r = MailSetup::saveAppsScript($bad, 'long-secret', null, self::answers(['ok' => true, 'detail' => ''], $asked));
            $this->assertFalse($r['ok'], $bad);
            $this->assertNull($asked, "$bad must be refused without asking");
        }
        $this->assertNull(self::stored('gas_url'));
    }

    /** Unreachable, or an older deployment: nothing judged the values, so they are kept with the fault stated. */
    public function test_a_script_that_cannot_be_reached_is_still_stored_with_the_fault_stated(): void
    {
        DB::table('gates_settings')->whereIn('key_name', ['gas_url', 'gas_secret'])->delete();
        $r = MailSetup::saveAppsScript(self::GAS, 'long-secret', null,
            self::answers(['ok' => false, 'detail' => 'the deployed script is older than the mail action']));
        $this->assertFalse($r['ok']);
        $this->assertStringStartsWith('Saved, but', $r['message']);
        $this->assertSame(self::GAS, self::stored('gas_url'));
    }

    public function test_a_blank_secret_keeps_the_stored_one(): void
    {
        DB::table('gates_settings')->whereIn('key_name', ['gas_url', 'gas_secret'])->delete();
        DB::table('gates_settings')->insert([['key_name' => 'gas_url', 'value' => self::GAS], ['key_name' => 'gas_secret', 'value' => 'kept']]);
        $asked = null;
        MailSetup::saveAppsScript('https://script.google.com/macros/s/AKfyNEW/exec', '', null,
            self::answers(['ok' => true, 'detail' => ''], $asked));
        $this->assertSame('kept', $asked[1]);
        $this->assertSame('kept', self::stored('gas_secret'));
        $this->assertSame('https://script.google.com/macros/s/AKfyNEW/exec', self::stored('gas_url'));
    }
}
