<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailCampaign;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Mail\MailEngagement;
use AfricaGates\Services\Mail\MailTraction;
use AfricaGates\Services\Mail\Outbox;
use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use PHPMailer\PHPMailer\PHPMailer;
use Tests\TestCase;

/**
 * THE EMAIL OPTIMISER (owner, 5 Nov 2026): a campaign is queued once and sends itself, at a
 * pace the account can take, the people who read us first; a link pressed is counted and what
 * they do next is credited; a second subject is tested on a sample and the winner goes to the
 * rest. Driven through the real transport, stopping at the socket.
 */
final class MailOptimiserTest extends TestCase
{
    private const SITE = 'https://site.test';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00'));
        SchemaHas::forget();
        SendPolicy::forget();
        foreach (['gates_mail_log', 'gates_mail_outbox', 'gates_mail_clicks', 'gates_mail_conversions', 'gates_broadcast_log',
                  'gates_email_optout', 'gates_mail_suppression', 'gates_mail_incidents', 'gates_email_campaigns'] as $t) {
            if (DB::schema()->hasTable($t)) DB::table($t)->delete();
        }
        DB::table('gates_settings')->whereIn('key_name', [Outbox::PACE_KEY, Outbox::STO_KEY, Outbox::QUIET_KEY, 'mail_daily_limit', SendPolicy::CAP_KEY])->delete();
        // The app always has a session (the click is remembered there); the CLI does not.
        $_SESSION = $_SESSION ?? [];
        unset($_SESSION[MailTraction::SESSION]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        unset($_SESSION[MailTraction::SESSION]);
        parent::tearDown();
    }

    private function transport(): OtpService
    {
        return new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'news@africagates.org', 'from_name' => 'Africa GATES']) extends OtpService {
            /** @var list<array{to:string, subject:string, body:string}> */
            public array $sent = [];
            protected function transmit(PHPMailer $m): void
            {
                $this->sent[] = ['to' => $m->getToAddresses()[0][0], 'subject' => $m->Subject, 'body' => $m->Body];
            }
        };
    }

    private function campaign(string $subjectB = ''): object
    {
        $r = EmailCampaign::create('Final hours ' . bin2hex(random_bytes(2)), 'Voting closes soon');
        $this->assertTrue($r['ok'], $r['message']);
        DB::table('gates_email_campaigns')->where('id', $r['id'])->update(['status' => 'approved', 'subject_b' => $subjectB ?: null]);
        return DB::table('gates_email_campaigns')->where('id', $r['id'])->first();
    }

    /** @return list<array{nominee:object,cycle:object,email:string}> */
    private function rows(array $emails): array
    {
        $out = [];
        foreach ($emails as $i => $e) {
            $out[] = ['email' => $e, 'nominee' => (object) ['id' => 100 + $i, 'name' => 'Ada Obi', 'category_title' => 'Innovation', 'programme_slug' => 'stem'],
                      'cycle' => (object) ['id' => 1, 'voting_close' => '2026-11-20 23:59:00']];
        }
        return $out;
    }

    private function emails(int $n, string $prefix = 'p'): array
    {
        return array_map(static fn (int $i): string => "$prefix$i@africagates.org", range(1, $n));
    }

    // ══ queued once, sent by itself ═════════════════════════════════════════

    public function test_a_campaign_is_queued_once_and_drains_itself_at_the_pace_set(): void
    {
        $c = $this->campaign();
        $this->assertSame(10, Outbox::enqueueCampaign($c, $this->rows($this->emails(10))));
        $this->assertSame(0, Outbox::enqueueCampaign($c, $this->rows($this->emails(10))), 'pressing Start twice queues nobody twice');

        Outbox::saveSettings(Outbox::PACE_MIN, true, 7);           // 20 an hour → 5 a tick
        $t = $this->transport();
        $first = Outbox::drain($t, self::SITE);
        $this->assertSame(5, $first['sent'], 'a quarter of the hourly pace per fifteen-minute tick');
        $this->assertSame(5, Outbox::status($c->slug)['queued']);
        Outbox::drain($t, self::SITE);
        $this->assertSame(10, count($t->sent));
        $this->assertSame(count(array_unique(array_column($t->sent, 'to'))), count($t->sent), 'nobody twice');
        $this->assertSame('sent', DB::table('gates_email_campaigns')->where('id', $c->id)->value('status'), 'an empty queue marks the campaign sent');
    }

    public function test_the_announcement_share_of_the_daily_allowance_is_respected(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'mail_daily_limit', 'value' => '10']);
        SendPolicy::forget();
        // 6 already sent today = the whole 60% announcement share.
        for ($i = 0; $i < 6; $i++) DB::table('gates_mail_log')->insert(['to_masked' => 'x', 'to_hash' => 'h' . $i, 'bulk' => 0, 'subject' => 's',
            'status' => 'sent', 'created_at' => Carbon::now()->subHour()->toDateTimeString()]);
        $b = Outbox::budget();
        $this->assertSame(0, $b['n']);
        $this->assertStringContainsString('sign-in codes keep the rest', $b['why']);
    }

    public function test_a_deferred_message_is_retried_later_and_a_refused_one_is_final(): void
    {
        $c = $this->campaign();
        EmailOptOut::record('p1@africagates.org');                         // unsubscribed: refused
        DB::table('gates_settings')->insert(['key_name' => SendPolicy::CAP_KEY, 'value' => '1']);
        SendPolicy::forget();
        DB::table('gates_mail_log')->insert(['to_masked' => 'x', 'to_hash' => EmailOptOut::hash('p2@africagates.org'), 'bulk' => 1,
            'subject' => 'earlier', 'status' => 'sent', 'created_at' => Carbon::now()->subHour()->toDateTimeString()]);   // at its cap: deferred
        Outbox::enqueueCampaign($c, $this->rows(['p1@africagates.org', 'p2@africagates.org', 'p3@africagates.org']));
        Outbox::drain($this->transport(), self::SITE, 10);

        $by = DB::table('gates_mail_outbox')->pluck('status', 'email')->all();
        $this->assertSame('held', $by['p1@africagates.org']);
        $this->assertSame('queued', $by['p2@africagates.org'], 'deferred is waiting, not lost');
        $this->assertSame('sent', $by['p3@africagates.org']);
        $later = DB::table('gates_mail_outbox')->where('email', 'p2@africagates.org')->value('not_before');
        $this->assertTrue(Carbon::parse((string) $later)->greaterThan(Carbon::now()), 'and waits out a backoff');
    }

    public function test_nothing_is_sent_while_mail_is_failing(): void
    {
        $c = $this->campaign();
        Outbox::enqueueCampaign($c, $this->rows($this->emails(3)));
        DB::table('gates_mail_incidents')->insert(['opened_at' => Carbon::now()->toDateTimeString(), 'opened_by' => 'probe', 'cause' => 'system']);
        $t = $this->transport();
        $r = Outbox::drain($t, self::SITE);
        $this->assertSame([], $t->sent);
        $this->assertStringContainsString('email is failing', $r['note']);
    }

    // ══ order and timing ═══════════════════════════════════════════════════

    public function test_people_who_read_us_go_first_and_the_never_responding_last(): void
    {
        $now = Carbon::now();
        $quiet = 'quiet@africagates.org';
        for ($i = 0; $i < MailEngagement::QUIET_AFTER; $i++) {
            DB::table('gates_mail_log')->insert(['to_masked' => 'x', 'to_hash' => EmailOptOut::hash($quiet), 'bulk' => 1, 'subject' => 's',
                'status' => 'sent', 'created_at' => $now->copy()->subDays(20 + $i)->toDateTimeString()]);
        }
        $keen = 'keen@africagates.org';
        DB::table('gates_mail_clicks')->insert(['campaign_key' => 'old', 'email_hash' => EmailOptOut::hash($keen), 'url' => 'https://x',
            'clicked_at' => $now->copy()->subDays(3)->toDateTimeString()]);
        DB::table('gates_mail_log')->insert(['to_masked' => 'x', 'to_hash' => EmailOptOut::hash($keen), 'bulk' => 1, 'subject' => 's',
            'status' => 'sent', 'created_at' => $now->copy()->subDays(3)->toDateTimeString()]);

        $p = MailEngagement::profile([EmailOptOut::hash($quiet), EmailOptOut::hash($keen), EmailOptOut::hash('fresh@africagates.org')]);
        $this->assertSame('quiet', $p[EmailOptOut::hash($quiet)]['tier']);
        $this->assertSame('engaged', $p[EmailOptOut::hash($keen)]['tier']);
        $this->assertSame('new', $p[EmailOptOut::hash('fresh@africagates.org')]['tier']);

        Outbox::saveSettings(Outbox::PACE_MIN, false, 0);
        $c = $this->campaign();
        Outbox::enqueueCampaign($c, $this->rows([$quiet, 'fresh@africagates.org', $keen]));
        $t = $this->transport();
        Outbox::drain($t, self::SITE);
        $this->assertSame([$keen, 'fresh@africagates.org', $quiet], array_column($t->sent, 'to'));
    }

    public function test_a_quiet_reader_is_spaced_out_and_a_habit_sets_the_hour(): void
    {
        $now = Carbon::now();
        $quiet = 'quiet@africagates.org';
        for ($i = 0; $i < MailEngagement::QUIET_AFTER; $i++) {
            DB::table('gates_mail_log')->insert(['to_masked' => 'x', 'to_hash' => EmailOptOut::hash($quiet), 'bulk' => 1, 'subject' => 's',
                'status' => 'sent', 'created_at' => $now->copy()->subDays($i + 1)->toDateTimeString()]);
        }
        $evening = 'evening@africagates.org';
        foreach ([3, 9] as $d) {
            DB::table('gates_mail_clicks')->insert(['campaign_key' => 'old', 'email_hash' => EmailOptOut::hash($evening), 'url' => 'https://x',
                'clicked_at' => $now->copy()->subDays($d)->setTimezone(\AfricaGates\Support\DisplayTime::zone())->setTime(19, 10)->setTimezone($now->getTimezone())->toDateTimeString()]);
        }
        Outbox::saveSettings(300, true, 7);
        Outbox::enqueueCampaign($this->campaign(), $this->rows([$quiet, $evening]));
        $nb = DB::table('gates_mail_outbox')->pluck('not_before', 'email')->all();
        $this->assertSame($now->copy()->subDay()->addDays(7)->toDateTimeString(), $nb[$quiet], 'one announcement a week for somebody who never responds');
        $this->assertSame(19, (int) Carbon::parse($nb[$evening])->setTimezone(\AfricaGates\Support\DisplayTime::zone())->format('G'), 'sent at the hour they read us');
    }

    // ══ traction ═══════════════════════════════════════════════════════════

    public function test_links_are_counted_and_what_they_do_next_is_credited(): void
    {
        $c = $this->campaign();
        Outbox::enqueueCampaign($c, $this->rows(['ada@africagates.org']));
        $t = $this->transport();
        Outbox::drain($t, self::SITE);
        $body = $t->sent[0]['body'];
        $this->assertMatchesRegularExpression('~href="https://site\.test/m/c/[A-Za-z0-9_-]+\.[0-9a-f]{24}"~', $body, 'links go through the counter');
        $this->assertStringContainsString('/email/unsubscribe', $body);
        $this->assertDoesNotMatchRegularExpression('~/m/c/[^"]*"[^>]*>\s*Unsubscribe~i', $body, 'the stop link is never counted');

        preg_match('~/m/c/([A-Za-z0-9_.-]+)"~', $body, $m);
        // A scanner within a minute of delivery is not a person.
        $this->assertNotNull(MailTraction::click($m[1]));
        $this->assertSame(0, DB::table('gates_mail_clicks')->count());
        $dest = MailTraction::click($m[1], Carbon::now()->addMinutes(30));
        $this->assertStringStartsWith('https://site.test', (string) $dest);
        $this->assertSame(1, DB::table('gates_mail_clicks')->count());

        $this->assertTrue(MailTraction::converted('vote', 'n1:abc'));
        $this->assertFalse(MailTraction::converted('vote', 'n1:abc'), 'once per thing done');
        $rep = MailTraction::report((string) $c->slug);
        $this->assertSame(1, $rep['sent']);
        $this->assertSame(1, $rep['clickers']);
        $this->assertSame(100.0, $rep['rate']);
        $this->assertSame(1, $rep['conversions']['vote']);
    }

    public function test_a_forged_or_edited_link_forwards_nowhere(): void
    {
        $tok = MailTraction::token(['k' => 'c', 'h' => 'h', 'v' => null, 'u' => 'https://site.test/vote']);
        $this->assertSame('https://site.test/vote', MailTraction::read($tok)['u']);
        [$p, $s] = explode('.', $tok);
        $evil = rtrim(strtr(base64_encode((string) json_encode(['k' => 'c', 'h' => 'h', 'u' => 'https://evil.test'])), '+/', '-_'), '=');
        $this->assertNull(MailTraction::read($evil . '.' . $s), 'a changed destination fails the signature');
        $this->assertNull(MailTraction::click('nonsense'));
        $js = MailTraction::token(['k' => 'c', 'h' => 'h', 'u' => 'javascript:alert(1)']);
        $this->assertNull(MailTraction::read($js), 'only http(s) is ever forwarded to');
    }

    public function test_nothing_is_credited_without_a_click_or_after_the_window(): void
    {
        $this->assertFalse(MailTraction::converted('vote', 'x'));
        $_SESSION[MailTraction::SESSION] = ['k' => 'c', 'h' => 'h', 'at' => Carbon::now()->subDays(MailTraction::WINDOW_DAYS + 1)->getTimestamp()];
        $this->assertFalse(MailTraction::converted('vote', 'x'));
        $this->assertArrayNotHasKey(MailTraction::SESSION, $_SESSION);
    }

    // ══ the subject test ═══════════════════════════════════════════════════

    public function test_a_second_subject_is_tested_on_a_sample_and_the_winner_goes_to_the_rest(): void
    {
        $c = $this->campaign('Your nominee needs you');
        Outbox::saveSettings(Outbox::PACE_MAX, false, 0);
        Outbox::enqueueCampaign($c, $this->rows($this->emails(100)));
        $sample = DB::table('gates_mail_outbox')->whereIn('variant', ['A', 'B'])->count();
        $this->assertSame(40, $sample, 'AB_MIN a side, at least');
        $this->assertSame(20, DB::table('gates_mail_outbox')->where('variant', 'B')->count());

        $t = $this->transport();
        Outbox::drain($t, self::SITE, 500);
        $this->assertCount(40, $t->sent, 'only the sample goes before the result');
        $this->assertSame(60, Outbox::status($c->slug)['waiting_ab']);

        // B is clicked more.
        foreach (DB::table('gates_mail_outbox')->where('variant', 'B')->limit(6)->pluck('email_hash') as $h) {
            DB::table('gates_mail_clicks')->insert(['campaign_key' => $c->slug, 'email_hash' => $h, 'variant' => 'B', 'url' => 'https://x',
                'clicked_at' => Carbon::now()->toDateTimeString()]);
        }
        $this->assertNull(Outbox::decideWinner((string) $c->slug, Carbon::now()), 'not before the wait');
        Carbon::setTestNow(Carbon::now()->addHours(Outbox::AB_WAIT_HOURS + 1));
        Outbox::drain($t, self::SITE, 500);
        $this->assertSame('B', DB::table('gates_email_campaigns')->where('id', $c->id)->value('ab_winner'));
        $this->assertCount(100, $t->sent);
        $rest = array_slice($t->sent, 40);
        $this->assertSame(['Your nominee needs you'], array_values(array_unique(array_column($rest, 'subject'))), 'the rest get the winner');
    }
}
