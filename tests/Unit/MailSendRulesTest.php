<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Mail\MailConfig;
use AfricaGates\Services\Mail\MailEvents;
use AfricaGates\Services\Mail\MailHealth;
use AfricaGates\Services\Mail\MailLog;
use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\Mail\Suppression;
use AfricaGates\Services\Newsletter\Newsletter;
use AfricaGates\Services\Newsletter\NewsletterSchedule;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use PHPMailer\PHPMailer\PHPMailer;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The send rules, held on the REAL transport.
 *
 * Every message here goes through `OtpService` exactly as production builds it — the rules,
 * the PHPMailer object, the headers, the log — and stops only at `transmit()`, where a
 * test subclass builds the MIME message instead of opening a socket, or throws the error
 * a receiving server would. So an assertion on a header is an assertion on the bytes that
 * would have left, not on a method somebody hoped was called.
 */
final class MailSendRulesTest extends TestCase
{
    private const UNSUB = 'https://africagates.test/email/unsubscribe?e=x&t=y';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'UTC'));
        SchemaHas::forget();
        foreach (['gates_mail_log', 'gates_mail_suppression', 'gates_email_optout', 'gates_mail_incidents',
                  'gates_newsletter', 'gates_newsletter_issues', 'gates_broadcast_log'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('gates_settings')->whereIn('key_name', [SendPolicy::CAP_KEY, MailEvents::TOKEN_KEY,
            'mail_postal_address', 'mail_from_address'])->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The real transport, stopping at the socket. */
    private function transport(?\Closure $fail = null): OtpService
    {
        return new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'news@africagates.org', 'from_name' => 'Africa GATES'], $fail) extends OtpService {
            /** @var list<string> */
            public array $wire = [];
            /** @var list<string> */
            public array $subjects = [];

            public function __construct(array $smtp, private ?\Closure $fail) { parent::__construct($smtp); }

            protected function transmit(PHPMailer $m): void
            {
                if ($this->fail) ($this->fail)($m);
                $m->preSend();
                $this->wire[] = $m->getSentMIMEMessage();
                $this->subjects[] = $m->Subject;
            }
        };
    }

    private function statuses(): array
    {
        return DB::table('gates_mail_log')->orderBy('id')->pluck('status')->all();
    }

    // ══ every message ════════════════════════════════════════════════════════

    public function test_a_reserved_domain_is_never_attempted_and_is_not_a_failure(): void
    {
        $t = $this->transport();
        foreach (['ada@demo.invalid', 'x@example.com', 'y@mail.example.org', 'z@box.test', 'q@host.localhost'] as $to) {
            $r = $t->sendCustom($to, 'Your code', 'body');
            $this->assertSame(SendPolicy::REFUSED, $r['held'] ?? null, $to);
        }
        $this->assertSame([], $t->wire, 'no mailbox can exist there; every attempt is a bounce against the domain');
        $this->assertSame(array_fill(0, 5, MailLog::REFUSED), $this->statuses());

        $w = MailHealth::window();
        $this->assertSame(0, $w['system'] + $w['recipient'], 'a held message is the rules working, not mail failing');
        $this->assertSame(5, $w['held']);
    }

    public function test_a_real_domain_that_merely_resembles_one_is_sent(): void
    {
        $t = $this->transport();
        foreach (['ada@testing.com', 'b@example.com.ng', 'c@invalidated.org'] as $to) {
            $this->assertTrue($t->sendCustom($to, 'Hello', 'body')['success'], $to);
        }
        $this->assertCount(3, $t->wire);
    }

    // ══ announcements ════════════════════════════════════════════════════════

    public function test_an_announcement_respects_a_stop_and_a_requested_message_does_not_need_to(): void
    {
        EmailOptOut::record('ada@africagates.org');
        $t = $this->transport();

        $this->assertSame(SendPolicy::REFUSED,
            $t->sendRawHtml('ada@africagates.org', 'Newsletter', '<p>x</p>', 'x', 'newsletter', self::UNSUB)['held'] ?? null);
        $this->assertTrue($t->sendCustom('ada@africagates.org', 'Your sign-in code', '123456')['success'],
            'an unsubscribe from announcements must never stop the code somebody asked for');
        $this->assertCount(1, $t->wire);
    }

    public function test_a_bounced_or_complaining_address_gets_no_announcements(): void
    {
        Suppression::record('gone@africagates.org', Suppression::BOUNCE, 'brevo');
        Suppression::record('angry@africagates.org', Suppression::COMPLAINT, 'brevo');
        $t = $this->transport();

        foreach (['gone@africagates.org', 'angry@africagates.org'] as $to) {
            $this->assertSame(SendPolicy::REFUSED,
                $t->sendBranded($to, 'Voting closes soon', '<p>x</p>', 'x', 'Reminder', '', self::UNSUB)['held'] ?? null, $to);
        }
        $this->assertTrue($t->sendBranded('gone@africagates.org', 'Your ticket', '<p>x</p>')['success'],
            'a bounce months ago must not lock somebody out of what they just asked for');
    }

    public function test_the_daily_cap_defers_the_next_announcement_and_lifts_after_the_window(): void
    {
        SendPolicy::saveCap(2);
        $t = $this->transport();
        $send = fn() => $t->sendRawHtml('fan@africagates.org', 'Issue', '<p>x</p>', 'x', 'newsletter', self::UNSUB);
        // Codes and receipts first: they are not announcements and must not use up the cap.
        $t->sendCustom('fan@africagates.org', 'Your sign-in code', '123456');
        $t->sendCustom('fan@africagates.org', 'Your receipt', 'x');

        $this->assertTrue($send()['success']);
        $this->assertTrue($send()['success']);
        $third = $send();
        $this->assertSame(SendPolicy::DEFERRED, $third['held'] ?? null);
        $this->assertStringContainsString('2 announcements in the last 24 hours', $third['error']);
        $this->assertTrue($t->sendCustom('fan@africagates.org', 'Your receipt', 'x')['success'],
            'the cap counts announcements, never what somebody asked for');

        Carbon::setTestNow(Carbon::now()->addHours(SendPolicy::CAP_HOURS + 1));
        $this->assertTrue($send()['success'], 'a cap is a window, not a ban');
    }

    public function test_the_cap_is_floored_and_ceilinged_where_it_is_read(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => SendPolicy::CAP_KEY], ['value' => '0']);
        $this->assertSame(SendPolicy::CAP_MIN, SendPolicy::cap(), 'nought would mean no announcements ever');
        DB::table('gates_settings')->updateOrInsert(['key_name' => SendPolicy::CAP_KEY], ['value' => '99']);
        $this->assertSame(SendPolicy::CAP_MAX, SendPolicy::cap());
        DB::table('gates_settings')->updateOrInsert(['key_name' => SendPolicy::CAP_KEY], ['value' => 'many']);
        $this->assertSame(SendPolicy::CAP_DEFAULT, SendPolicy::cap());
    }

    // ══ the headers that leave ═══════════════════════════════════════════════

    public function test_an_announcement_carries_every_list_header_and_a_requested_message_none(): void
    {
        $t = $this->transport();
        $t->sendRawHtml('reader@africagates.org', 'This week', '<p>x</p>', 'x', 'newsletter', self::UNSUB);
        $t->sendCustom('reader@africagates.org', 'Your code', '123456');
        [$bulk, $one] = $t->wire;

        foreach (['List-Unsubscribe: <' . self::UNSUB . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
                  'List-Id: Africa GATES newsletter <newsletter.africagates.org>', 'Precedence: bulk',
                  'Auto-Submitted: auto-generated', 'Feedback-ID: newsletter:bulk:africagates'] as $h) {
            $this->assertStringContainsString($h, $bulk);
            $this->assertStringNotContainsString(explode(':', $h)[0] . ':', $one,
                'a sign-in code is not a list, and a list header on it is a reason to file it under Promotions');
        }
        foreach ([$bulk, $one] as $m) {
            $this->assertMatchesRegularExpression('~^Message-ID: <[a-f0-9]{32}@africagates\.org>~mi', $m,
                'a Message-ID on the hosting server\'s name matches nothing in From');
        }
    }

    // ══ what a refusal teaches ═══════════════════════════════════════════════

    public function test_a_permanent_refusal_of_the_mailbox_is_remembered(): void
    {
        $t = $this->transport(static function (PHPMailer $m): void {
            throw new \RuntimeException('SMTP Error: The following recipients failed: gone@africagates.org: 550 5.1.1 <gone@africagates.org>: Recipient address rejected: User unknown');
        });
        $this->assertFalse($t->sendCustom('gone@africagates.org', 'x', 'y')['success']);

        $this->assertSame(Suppression::BOUNCE, Suppression::reasonFor('gone@africagates.org'));
        $this->assertSame([MailLog::FAILED], $this->statuses());
        foreach ([DB::table('gates_mail_suppression')->value('detail'), DB::table('gates_mail_log')->value('error')] as $text) {
            $this->assertStringNotContainsString('gone@africagates.org', (string) $text,
                'the server quotes the address in its refusal; stored verbatim it undoes the mask beside it');
            $this->assertStringContainsString('go***@africagates.org', (string) $text);
        }
    }

    public function test_a_temporary_or_platform_refusal_is_not_held_against_the_address(): void
    {
        foreach (['SMTP Error: The following recipients failed: a@africagates.org: 452 4.2.2 Mailbox full, try again later',
                  // A temporary refusal that IS about the recipient — greylisting — so it is
                  // the permanence test, not the cause test, that has to decline it.
                  'SMTP Error: The following recipients failed: a@africagates.org: 450 4.2.0 <a@africagates.org>: Recipient address rejected: Greylisted, try again in 5 minutes',
                  'SMTP Error: Could not authenticate. 535 5.7.8 Authentication failed',
                  'SMTP Error: MAIL FROM command failed: 553 5.7.1 Sender address not verified'] as $err) {
            DB::table('gates_mail_suppression')->delete();
            $t = $this->transport(static function () use ($err): void { throw new \RuntimeException($err); });
            $t->sendCustom('a@africagates.org', 'x', 'y');
            $this->assertNull(Suppression::reasonFor('a@africagates.org'),
                'suppressing on this deletes a real reader during somebody else\'s bad afternoon: ' . $err);
        }
    }

    public function test_a_complaint_outranks_a_later_bounce(): void
    {
        Suppression::record('x@africagates.org', Suppression::COMPLAINT, 'brevo');
        Suppression::record('x@africagates.org', Suppression::BOUNCE, 'smtp');
        $this->assertSame(Suppression::COMPLAINT, Suppression::reasonFor('x@africagates.org'));
        $this->assertSame(2, (int) DB::table('gates_mail_suppression')->value('events'));
    }

    // ══ what the provider reports afterwards ═════════════════════════════════

    public function test_each_providers_reports_are_read_and_temporary_ones_ignored(): void
    {
        $ev = static fn(mixed $p): array => array_map(
            static fn(array $e): string => $e['source'] . ':' . $e['kind'] . ':' . $e['email'], MailEvents::parse($p));

        $this->assertSame(['brevo:bounce:a@x.org'], $ev(['event' => 'hard_bounce', 'email' => 'A@x.org']));
        $this->assertSame([], $ev(['event' => 'soft_bounce', 'email' => 'a@x.org']));
        $this->assertSame(['brevo:complaint:a@x.org'], $ev(['event' => 'spam', 'email' => 'a@x.org']));

        $this->assertSame(['sendgrid:bounce:a@x.org', 'sendgrid:complaint:c@x.org'], $ev([
            ['event' => 'bounce', 'type' => 'bounce', 'email' => 'a@x.org', 'sg_event_id' => '1'],
            ['event' => 'bounce', 'type' => 'blocked', 'email' => 'b@x.org', 'sg_event_id' => '2'],
            ['event' => 'spamreport', 'email' => 'c@x.org', 'sg_event_id' => '3'],
            ['event' => 'deferred', 'email' => 'd@x.org', 'sg_event_id' => '4'],
        ]));

        $this->assertSame(['mailgun:bounce:a@x.org'], $ev(['event-data' => ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'a@x.org']]));
        $this->assertSame([], $ev(['event-data' => ['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'a@x.org']]));

        $this->assertSame(['postmark:bounce:a@x.org'], $ev(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'a@x.org']));
        $this->assertSame([], $ev(['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'a@x.org']));
        $this->assertSame(['postmark:complaint:a@x.org'], $ev(['RecordType' => 'SpamComplaint', 'Email' => 'a@x.org']));
    }

    public function test_a_provider_unsubscribe_is_the_persons_choice_and_goes_on_the_opt_out_list(): void
    {
        MailEvents::apply(MailEvents::parse(['event' => 'unsubscribed', 'email' => 'u@africagates.org']));
        $this->assertTrue(EmailOptOut::suppressed('u@africagates.org'));
        $this->assertNull(Suppression::reasonFor('u@africagates.org'));
    }

    public function test_the_webhook_answers_only_its_own_token(): void
    {
        $app = (new NewsletterTestApp())->app();
        $post = static function (string $path, array $body) use ($app) {
            $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withHeader('Content-Type', 'application/json');
            $req->getBody()->write((string) json_encode($body));
            $req->getBody()->rewind();
            return $app->handle($req);
        };
        $body = ['event' => 'hard_bounce', 'email' => 'dead@africagates.org'];

        // No token set up yet: nothing may feed the list.
        $this->assertSame(404, $post('/hooks/mail-events/' . str_repeat('a', 32), $body)->getStatusCode());

        $token = MailEvents::ensureToken();
        $this->assertSame(404, $post('/hooks/mail-events/' . str_repeat('b', 32), $body)->getStatusCode());
        $this->assertNull(Suppression::reasonFor('dead@africagates.org'));

        $ok = $post('/hooks/mail-events/' . $token, $body);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(Suppression::BOUNCE, Suppression::reasonFor('dead@africagates.org'));

        MailEvents::rotate();
        $this->assertSame(404, $post('/hooks/mail-events/' . $token, $body)->getStatusCode(),
            'a rotated token stops the old address at once');
    }

    // ══ the newsletter, under the rules ══════════════════════════════════════

    public function test_a_deferred_reader_is_released_and_does_not_pause_the_send(): void
    {
        SendPolicy::saveCap(1);
        foreach (['a', 'b', 'c', 'd'] as $x) {
            DB::table('gates_newsletter')->insert(['email' => "$x@africagates.org", 'email_hash' => EmailOptOut::hash("$x@africagates.org"),
                'source' => 'homepage', 'subscribed_at' => '2026-09-01 10:00:00', 'confirmed_at' => '2026-09-01 10:00:00']);
        }
        $t = $this->transport();
        // a, b and c have had today's announcement already.
        foreach (['a', 'b', 'c'] as $x) $t->sendRawHtml("$x@africagates.org", 'Earlier', '<p>x</p>', 'x', 'campaign', self::UNSUB);

        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'live', 'title' => 'Live Awards', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['programme_id' => $pid, 'year' => 2026, 'status' => 'voting',
            'voting_open' => '2026-09-20 00:00:00', 'voting_close' => '2026-10-04 22:00:00']);
        $issue = Newsletter::compose('w2026-40', NewsletterSchedule::of(['newsletter_mode' => 'auto']));

        $n = new Newsletter($t, 'https://africagates.test');
        $r = $n->sendBatch($issue, 10);
        $this->assertSame(1, $r['sent'], 'd was sent; three deferrals are not three failures');
        $this->assertNull($r['note'], 'deferrals must not trip the stop-after-three rule');
        $this->assertSame(3, $r['left']);
        $this->assertSame(Newsletter::ST_SENDING, Newsletter::find((int) $issue->id)->status);

        Carbon::setTestNow(Carbon::now()->addHours(SendPolicy::CAP_HOURS + 1));
        $n->sendBatch(Newsletter::find((int) $issue->id), 10);
        $this->assertSame(Newsletter::ST_SENT, Newsletter::find((int) $issue->id)->status);
        $this->assertSame(4, BroadcastLog::tally(Newsletter::campaignKey($issue))['sent'], 'each reader once, none twice');
    }

    // ══ what every message says about us ═════════════════════════════════════

    public function test_the_footer_states_who_sent_it_and_nothing_untrue(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'mail_postal_address'],
            ['value' => '12 Marina Road, Lagos Island, Lagos, Nigeria']);
        $html = $this->transport()->brandWrap('Subject', '<p>Body</p>');

        $this->assertStringContainsString('12 Marina Road, Lagos Island, Lagos, Nigeria', $html,
            'the postal address is a setting, because there is no shell to edit .env with');
        $this->assertStringNotContainsString('plain text is never stored', $html,
            'the newsletter list, the opt-out list and the send log all hold addresses — mail cannot be sent to a hash');
    }

    public function test_one_resolver_for_the_postal_address(): void
    {
        $readers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $f) {
            if (!$f->isFile() || !str_ends_with((string) $f, '.php')) continue;
            if (str_ends_with((string) $f, 'Mail/MailConfig.php')) continue;
            if (str_contains((string) file_get_contents((string) $f), "'MAIL_POSTAL_ADDRESS'")) $readers[] = basename((string) $f);
        }
        $this->assertSame([], $readers, 'a second reader of the postal address is a second answer to it');
    }

    public function test_the_log_has_one_writer(): void
    {
        $writers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $f) {
            if (!$f->isFile() || !str_ends_with((string) $f, '.php') || str_ends_with((string) $f, 'Mail/MailLog.php')) continue;
            if (preg_match("~table\\('gates_mail_log'\\)\\s*->\\s*insert~", (string) file_get_contents((string) $f))) {
                $writers[] = basename((string) $f);
            }
        }
        $this->assertSame([], $writers, 'a second writer is a second idea of what a status means');
    }

    public function test_the_voting_reminder_reads_as_a_letter_not_a_promotion(): void
    {
        $t = $this->transport();
        $t->sendVotingReminder('voter@africagates.org', 'Ade & Sons <Awards>', 'Sun 4 Oct, 23:59 WAT', [], self::UNSUB);
        $m = quoted_printable_decode($t->wire[0]);

        $this->assertSame('Voting closes Sun 4 Oct, 23:59 WAT — Ade & Sons <Awards>', $t->subjects[0]);
        $this->assertDoesNotMatchRegularExpression('~[\x{1F300}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}]~u', $t->subjects[0] . strip_tags($m),
            'an emoji in a subject is a promotion to the filter that decides which tab this lands in');
        $this->assertStringContainsString('Ade &amp; Sons &lt;Awards&gt;', $m, 'a name is text, never markup');
        $this->assertStringNotContainsString('OTP', $m, 'jargon');
        $this->assertStringContainsString('Stop these emails: ' . self::UNSUB, $m,
            'the plain-text part needs its way out as much as the HTML one');
    }

    // ══ the constraint the new outcomes needed ═══════════════════════════════

    public function test_the_migration_repairs_a_log_built_with_the_old_constraint(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        DB::statement('DROP TABLE gates_mail_log');
        DB::statement($sqlite
            ? "CREATE TABLE gates_mail_log (id INTEGER PRIMARY KEY AUTOINCREMENT, to_masked TEXT NOT NULL, subject TEXT NOT NULL,
               category TEXT, status TEXT NOT NULL CHECK(status IN ('sent','failed','logged_dev')), error TEXT,
               created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"
            : "CREATE TABLE gates_mail_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, to_masked VARCHAR(120) NOT NULL,
               subject VARCHAR(200) NOT NULL, category VARCHAR(40) NULL, status ENUM('sent','failed','logged_dev') NOT NULL,
               error VARCHAR(300) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        DB::table('gates_mail_log')->insert(['to_masked' => 'ad***@x.org', 'subject' => 'kept', 'status' => 'sent',
                                             'created_at' => '2026-10-01 09:00:00']);
        SchemaHas::forget();

        ob_start();
        include dirname(__DIR__, 2) . '/database/migrations/2027_02_20_mail_send_rules.php';
        ob_end_clean();
        SchemaHas::forget();

        $this->assertSame('kept', DB::table('gates_mail_log')->value('subject'), 'a repair keeps what it repairs');
        MailLog::write('a@africagates.org', 'held', 'newsletter', MailLog::DEFERRED, 'cap', true);
        $this->assertSame([MailLog::SENT, MailLog::DEFERRED], $this->statuses(),
            'without the repair this is Data truncated on MySQL and a CHECK failure on SQLite, swallowed by the log');
    }
}

/** The real app, as public/index.php assembles it — borrowed shape from NewsletterTest. */
final class NewsletterTestApp
{
    public function app(): \Slim\App
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($b->build());
        $app = \Slim\Factory\AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(new \AfricaGates\Middleware\CsrfMiddleware());
        $app->addBodyParsingMiddleware();
        $err = $app->addErrorMiddleware(false, false, false);
        $err->setDefaultErrorHandler(new \AfricaGates\Handlers\ErrorHandler($app));
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        return $app;
    }
}
