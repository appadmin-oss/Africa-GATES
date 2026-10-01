<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Mail\MailAlert;
use AfricaGates\Services\Mail\MailConfig;
use AfricaGates\Services\Mail\MailDiagnosis;
use AfricaGates\Services\Mail\MailFailure;
use AfricaGates\Services\Mail\MailHealth;
use AfricaGates\Services\OtpService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Email that fails has to be NOTICED, DIAGNOSED and REPORTED by the platform — not
 * discovered by somebody waiting for a sign-in code.
 *
 * Three faults are held here as well as the machinery:
 *
 *   · the sender and the status probe resolved the SMTP settings separately and
 *     disagreed about a blank host (Brevo to one, "not set" to the other);
 *   · the transport was STARTTLS whatever the port, so 465 never worked;
 *   · every alert this platform raised went out through the transport that had failed.
 *
 * Nothing here touches a network: the diagnosis takes a fake SMTP client and the alert
 * takes fake channels, and both record what they were asked to do — which is how "it
 * never sends a message" is asserted rather than promised.
 */
final class MailHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));
        DB::table('gates_mail_log')->delete();
        DB::table('gates_mail_incidents')->delete();
        DB::table('gates_settings')->whereIn('key_name', [
            'mail_probe_last', 'mail_probe_report', 'mail_smtp_host', 'mail_smtp_port',
            'mail_smtp_secure', 'mail_smtp_user', 'mail_smtp_pass',
        ])->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // One resolver, and the encryption the port needs
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_blank_host_is_the_brevo_relay_and_says_so(): void
    {
        $c = MailConfig::load(['mail_smtp_user' => 'u', 'mail_smtp_pass' => 'p']);

        $this->assertSame(MailConfig::DEFAULT_HOST, $c->host);
        $this->assertSame('default', $c->source('host'));
        $this->assertStringContainsString('built-in default', $c->describe()['host'],
            'where a value came from is the most useful thing a diagnosis can say');
    }

    /**
     * The rule rather than the instance: nothing but the resolver reads an SMTP setting.
     * The probe used to, and called a blank host "not set" while the sender used Brevo.
     * The settings controller WRITES these keys and is the one exemption.
     */
    public function test_nothing_but_the_resolver_reads_an_smtp_setting(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $bad  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if ($rel === 'Services/Mail/MailConfig.php' || $rel === 'Admin/Controllers/SettingsController.php') continue;
            // Comments stripped: the note explaining why a file STOPPED reading a key
            // names the key, and a sweep that cannot tell an explanation from a read
            // pushes people towards not writing the explanation.
            $code = implode('', array_map(
                static fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
                token_get_all((string) file_get_contents($f->getPathname()))));
            if (preg_match('~[\'"](mail_smtp_(host|port|user|pass|secure)|SMTP_(HOST|PORT|USER|PASS|SECURE))[\'"]~',
                           $code, $m)) {
                $bad[] = $rel . ' reads ' . $m[1];
            }
        }
        $this->assertSame([], $bad, "a second reader of the SMTP settings:\n" . implode("\n", $bad));
    }

    public function test_the_encryption_follows_the_port_unless_told_otherwise(): void
    {
        $sec = static fn (array $v) => MailConfig::of($v)->security();

        $this->assertSame(MailConfig::SECURE_SMTPS,    $sec(['port' => 465]));
        $this->assertSame(MailConfig::SECURE_STARTTLS, $sec(['port' => 587]));
        $this->assertSame(MailConfig::SECURE_STARTTLS, $sec(['port' => 2525]));
        // The names provider help pages use, meaning what they mean there.
        $this->assertSame(MailConfig::SECURE_SMTPS,    $sec(['port' => 2465, 'secure' => 'ssl']));
        $this->assertSame(MailConfig::SECURE_STARTTLS, $sec(['port' => 465,  'secure' => 'tls']));
        $this->assertSame(MailConfig::SECURE_NONE,     $sec(['port' => 25,   'secure' => 'none']));
    }

    /** The sender, not just the resolver: 465 used to be STARTTLS and time out. */
    public function test_the_sender_uses_implicit_tls_on_465(): void
    {
        $build = static function (int $port, string $secure = ''): PHPMailer {
            $o = OtpService::fromConfig(MailConfig::of(['port' => $port, 'secure' => $secure ?: 'auto',
                                                        'username' => 'u', 'password' => 'p']));
            $m = new \ReflectionMethod($o, 'mailer');
            return $m->invoke($o, 'someone@example.com');
        };

        $this->assertSame(PHPMailer::ENCRYPTION_SMTPS,    $build(465)->SMTPSecure);
        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $build(587)->SMTPSecure);
        $none = $build(25, 'none');
        $this->assertSame('', $none->SMTPSecure);
        $this->assertFalse($none->SMTPAutoTLS, '"none" must not be silently upgraded back to TLS');
    }

    public function test_the_password_is_never_described(): void
    {
        $c = MailConfig::of(['username' => 'login@x', 'password' => 'xsmtpsib-SECRETKEY']);
        $this->assertStringNotContainsString('SECRET', implode(' ', $c->describe()));
        $this->assertFalse(MailConfig::of(['username' => 'your_brevo_login@email.com', 'password' => 'k'])->hasCredentials(),
            'the .env.example placeholder counted as a login');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // What an error means
    // ══════════════════════════════════════════════════════════════════════════

    public function test_real_server_messages_get_the_right_cause(): void
    {
        $cases = [
            'SMTP Error: Could not authenticate.'                                        => MailFailure::AUTH,
            '535 5.7.8 Authentication failed'                                            => MailFailure::AUTH,
            'SMTP Error: Could not connect to SMTP host. Failed to connect to server'    => MailFailure::CONNECT,
            'SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting' => MailFailure::CONNECT,
            // A timeout on 465 mentions ssl:// — and is a blocked port, not a TLS fault.
            'Unable to connect to ssl://smtp.example.com:465 (Connection timed out)'     => MailFailure::CONNECT,
            'stream_socket_enable_crypto(): SSL operation failed with code 1'            => MailFailure::TLS,
            'php_network_getaddresses: getaddrinfo for smtp.brevo.con failed'            => MailFailure::DNS,
            'SMTP Error: The following From address failed: noreply@x : 550 sender not verified' => MailFailure::SENDER,
            'SMTP Error: The following recipients failed: bad@gmial.com: 550 5.1.1 User unknown' => MailFailure::RECIPIENT,
            '421 Too many messages, daily quota exceeded'                                => MailFailure::QUOTA,
            'SMTP not configured (Settings → Email & sender)'                            => MailFailure::CONFIG,
            'something nobody has seen before'                                           => MailFailure::UNKNOWN,
        ];
        foreach ($cases as $msg => $want) {
            $this->assertSame($want, MailFailure::classify($msg), $msg);
        }
        $this->assertFalse(MailFailure::isSystem(MailFailure::RECIPIENT), 'one typo is not an outage');
    }

    public function test_every_cause_tells_the_operator_what_to_do(): void
    {
        foreach (MailFailure::causes() as $c) {
            $this->assertNotSame('', MailFailure::title($c), $c);
            $this->assertGreaterThan(40, mb_strlen(MailFailure::fix($c)), "{$c} has no real fix");
        }
        $this->assertStringContainsString('SMTP KEY', MailFailure::fix(MailFailure::AUTH),
            'the commonest Brevo mistake is the account password in place of the SMTP key');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The diagnosis
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_rejected_login_is_named_and_nothing_after_it_runs(): void
    {
        $fake = new FakeSmtp(auth: false);
        $r = $this->diagnose($fake);

        $this->assertFalse($r['ok']);
        $this->assertSame(MailFailure::AUTH, $r['cause']);
        $this->assertSame('fail', self::state($r, 'login'));
        $this->assertSame('skip', self::state($r, 'sender'), 'a step after the failure ran');
        $this->assertNotContains('mail', $fake->calls, 'it offered a sender after the login failed');
    }

    public function test_a_blocked_port_names_the_one_that_is_open(): void
    {
        $fake = new FakeSmtp(connect: false);
        $r = $this->diagnose($fake, reach: static fn (string $h, int $p): bool => $p === 2525);

        $this->assertSame(MailFailure::CONNECT, $r['cause']);
        $this->assertTrue($r['ports'][2525]);
        $this->assertStringContainsString('Set Port to 2525', $r['fix'],
            'the fix should be the port that works, not "cannot connect"');
    }

    public function test_when_every_port_is_blocked_it_does_not_say_try_another(): void
    {
        $r = $this->diagnose(new FakeSmtp(connect: false), reach: static fn (): bool => false);

        $this->assertStringContainsString('Every standard mail port', $r['fix']);
        $this->assertStringContainsString('web host', $r['fix']);
        $this->assertStringNotContainsString('Try port', $r['fix'],
            'it advised retesting ports the diagnosis had just proved blocked');
    }

    public function test_a_passing_diagnosis_never_sends_a_message(): void
    {
        $fake = new FakeSmtp();
        $r = $this->diagnose($fake);

        $this->assertTrue($r['ok'], json_encode($r['steps']));
        $this->assertSame(['setTimeout', 'connect', 'getLastReply', 'hello', 'getServerExt', 'startTLS',
                           'hello', 'authenticate', 'mail', 'reset', 'quit'], $fake->calls,
            'the conversation must stop at MAIL FROM + RSET — no RCPT, no DATA');
        foreach ($r['steps'] as $s) $this->assertSame('ok', $s['state'], $s['key']);
    }

    public function test_no_login_stops_at_config_without_touching_the_network(): void
    {
        $fake = new FakeSmtp();
        $r = (new MailDiagnosis(MailConfig::of(['username' => '', 'password' => '']), static fn () => $fake))->run();

        $this->assertSame(MailFailure::CONFIG, $r['cause']);
        $this->assertSame([], $fake->calls);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Noticing, alerting, recovering
    // ══════════════════════════════════════════════════════════════════════════

    public function test_three_failures_open_an_incident_diagnose_it_and_alert(): void
    {
        $this->log('failed', 'SMTP Error: Could not authenticate.', 3);
        [$health, $alerts] = $this->health(['ok' => false, 'cause' => MailFailure::AUTH]);

        $this->assertSame(1, $health->check());

        $i = MailHealth::open();
        $this->assertNotNull($i, 'no incident');
        $this->assertSame('auth', $i->cause);
        $this->assertSame(3, (int) $i->failures);
        $this->assertNotNull($i->alerted_at);
        $this->assertSame('console,webhook,local', $i->alert_channels,
            'the channels that reached somebody must be recorded — and SMTP is not tried when the login is the fault');
        $this->assertCount(1, $alerts->sent);
        $this->assertStringContainsString('SMTP KEY', $alerts->sent[0]['text'],
            'the alert must carry the fix, not just the news');
        $this->assertStringNotContainsString('@example.com', $alerts->sent[0]['text'],
            'a recipient address reached an alert');
    }

    public function test_refused_recipients_are_not_an_outage(): void
    {
        $this->log('failed', 'SMTP Error: The following recipients failed: a@gmial.com: 550 5.1.1 User unknown', 9);
        [$health] = $this->health(['ok' => true]);
        $this->setProbedRecently();

        $this->assertSame(0, $health->check());
        $this->assertNull(MailHealth::open());
    }

    public function test_it_realerts_on_a_cadence_not_every_tick(): void
    {
        $this->log('failed', 'SMTP connect() failed.', 4);
        [$health, $alerts] = $this->health(['ok' => false, 'cause' => MailFailure::CONNECT]);
        $health->check();

        Carbon::setTestNow(Carbon::now()->addHours(1));
        $health->check();
        $this->assertCount(1, $alerts->sent, 'it alerted again within the hour');

        Carbon::setTestNow(Carbon::now()->addHours(MailHealth::REALERT_HOURS));
        $health->check();
        $this->assertCount(2, $alerts->sent);
        $this->assertStringContainsString('STILL', $alerts->sent[1]['subject']);
    }

    public function test_it_closes_when_mail_goes_out_again_and_says_so(): void
    {
        $this->log('failed', 'SMTP connect() failed.', 4);
        [$health, $alerts] = $this->health(['ok' => false, 'cause' => MailFailure::CONNECT]);
        $health->check();

        Carbon::setTestNow(Carbon::now()->addMinutes(20));
        $this->log('sent', null, MailHealth::RECOVER_SENT);
        $this->assertSame(1, $health->check());

        $this->assertNull(MailHealth::open(), 'the incident did not close');
        $this->assertSame('mail.recovered', $alerts->events[count($alerts->events) - 1]);
    }

    /**
     * The schedule that opens incidents can be the other broken thing — and then a
     * banner reading only the incident table goes quiet at the worst moment.
     */
    public function test_one_banner_and_it_never_sends_anybody_to_a_shell(): void
    {
        // No login: the banner says so before anything has failed, and points at Settings.
        $b = MailHealth::banner();
        $this->assertNotNull($b, 'an unconfigured transport drew no banner on a quiet site');
        $this->assertSame(MailFailure::title(MailFailure::CONFIG), $b['title']);
        $this->assertStringNotContainsString('.env', $b['fix'], 'there is no shell to edit .env with');

        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/layout.twig');
        $this->assertSame(1, substr_count($layout, 'mail_health()'));
        $this->assertStringNotContainsString('Email delivery is OFF', $layout,
            'the older mail banner is back beside the new one');
    }

    public function test_the_banner_reads_the_log_when_no_incident_was_opened(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'mail_smtp_user', 'value' => 'login@x'],
            ['key_name' => 'mail_smtp_pass', 'value' => 'key'],
        ]);
        $this->assertNull(MailHealth::banner());
        $this->log('failed', 'SMTP Error: Could not authenticate.', 5);

        $b = MailHealth::banner();
        $this->assertNotNull($b, 'a stalled schedule silenced the banner');
        $this->assertTrue($b['live']);
        $this->assertSame(MailFailure::title(MailFailure::AUTH), $b['title']);
    }

    public function test_a_dev_box_with_no_login_does_not_page_anybody(): void
    {
        [$health, $alerts] = $this->health(['ok' => false, 'cause' => MailFailure::CONFIG]);
        $this->assertSame(0, $health->check());
        $this->assertNull(MailHealth::open());
        $this->assertSame([], $alerts->sent);
    }

    public function test_a_webhook_nobody_accepted_is_not_counted_as_reaching_anybody(): void
    {
        $a = new MailAlert(static fn () => 0, static fn () => false, static fn () => true);
        $row = (object) ['opened_at' => '2026-10-01 11:00:00', 'failures' => 4];
        $reached = $a->failing($row, ['title' => 't', 'fix' => 'f', 'cause' => MailFailure::UNKNOWN, 'steps' => []]);

        $this->assertSame([MailAlert::CONSOLE, MailAlert::SMTP], $reached);
    }

    public function test_the_maintenance_run_checks_mail_health(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');
        $this->assertStringContainsString(
            "\$this->task('mailhealth', fn() => (new \\AfricaGates\\Services\\Mail\\MailHealth())->check())",
            $src, 'nothing runs the check, so nothing ever opens an incident');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The screen
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_page_shows_the_cause_the_fix_and_the_failing_step(): void
    {
        $this->log('failed', 'SMTP Error: Could not authenticate.', 3);
        [$health] = $this->health([
            'ok' => false, 'cause' => MailFailure::AUTH,
            'steps' => [['key' => 'login', 'label' => 'The provider accepts our login', 'state' => 'fail',
                         'detail' => 'Rejected for login@x. 535 Authentication failed']],
        ]);
        $health->check();

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctl = $b->build()->get(\AfricaGates\Admin\Controllers\MailHealthController::class);
        $html = (string) $ctl->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/settings/mail'),
                                     new Response())->getBody();

        $this->assertStringContainsString('Email is not sending', $html);
        $this->assertStringContainsString('SMTP KEY', $html, 'the fix is not on the page');
        $this->assertStringContainsString('535 Authentication failed', $html, 'the failing step is not on the page');
        $this->assertStringContainsString('action="/admin/settings/mail/diagnose"', $html);
        $this->assertStringContainsString((string) MailHealth::TRIP_FAILS . ' or more emails fail', $html,
            'the rule is typed into the page rather than read from the code');
    }

    // ══════════════════════════════════════════════════════════════════════════

    private function diagnose(FakeSmtp $fake, ?callable $reach = null): array
    {
        $c = MailConfig::of(['host' => 'smtp.test', 'port' => 587, 'username' => 'login@x', 'password' => 'k',
                             'from' => 'noreply@afrovanguard.org.ng']);
        return (new MailDiagnosis($c, static fn () => $fake, static fn () => ['203.0.113.9'],
                                  $reach ?? static fn () => false))->run();
    }

    private static function state(array $r, string $key): ?string
    {
        foreach ($r['steps'] as $s) if ($s['key'] === $key) return $s['state'];
        return null;
    }

    private function log(string $status, ?string $error, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_mail_log')->insert([
                'to_masked' => 'so***@example.com', 'subject' => 'Your code', 'category' => 'OTP',
                'status' => $status, 'error' => $error,
                'created_at' => Carbon::now()->subMinutes(5 - min(4, $i))->toDateTimeString(),
            ]);
        }
    }

    private function setProbedRecently(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'mail_probe_last'],
            ['value' => Carbon::now()->toDateTimeString()]);
    }

    /** @return array{0:MailHealth, 1:object} */
    private function health(array $report): array
    {
        $report += ['title' => MailFailure::title($report['cause'] ?? MailFailure::UNKNOWN),
                    'fix' => MailFailure::fix($report['cause'] ?? MailFailure::UNKNOWN),
                    'steps' => [], 'ports' => [], 'ran_at' => '2026-10-01 12:00:00', 'took_ms' => 1,
                    'cause' => null, 'config' => []];
        if ($report['ok']) { $report['title'] = 'Email can be sent'; $report['fix'] = ''; }

        $rec = new class { public array $sent = []; public array $events = []; };
        $alert = new MailAlert(
            static function (string $event, array $data) use ($rec): int { $rec->events[] = $event; return 1; },
            static function (string $to, string $subject, string $text) use ($rec): bool {
                $rec->sent[] = ['subject' => $subject, 'text' => $text]; return true; },
            static fn (): bool => true,
        );
        return [new MailHealth(static fn () => $report, $alert), $rec];
    }
}

/** An SMTP client that answers from a script and records every call it is asked to make. */
final class FakeSmtp extends SMTP
{
    public array $calls = [];

    public function __construct(private bool $connect = true, private bool $auth = true) {}

    public function setTimeout($timeout = 0) { $this->calls[] = 'setTimeout'; }
    public function connect($host, $port = null, $timeout = 30, $options = []) { $this->calls[] = 'connect'; return $this->connect; }
    public function getLastReply() { $this->calls[] = 'getLastReply'; return '220 smtp.test ESMTP ready'; }
    public function hello($host = '') { $this->calls[] = 'hello'; return true; }
    public function getServerExt($name) { $this->calls[] = 'getServerExt'; return true; }
    public function startTLS() { $this->calls[] = 'startTLS'; return true; }
    public function authenticate($username, $password, $authtype = null, $OAuth = null) { $this->calls[] = 'authenticate'; return $this->auth; }
    public function mail($from) { $this->calls[] = 'mail'; return true; }
    public function reset() { $this->calls[] = 'reset'; return true; }
    public function quit($close_on_error = true) { $this->calls[] = 'quit'; return true; }
    public function close() { $this->calls[] = 'close'; }
    public function getError() { return ['error' => $this->auth ? 'connect failed' : 'Authentication failed', 'detail' => '', 'smtp_code' => $this->auth ? '' : '535', 'smtp_code_ex' => '']; }
    public function recipient($address, $dsn = '') { $this->calls[] = 'recipient'; return true; }
    public function data($msg_data) { $this->calls[] = 'data'; return true; }
}
