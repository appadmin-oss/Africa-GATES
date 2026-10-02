<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\NewsletterController;
use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Newsletter\Newsletter;
use AfricaGates\Services\Newsletter\NewsletterAudience;
use AfricaGates\Services\Newsletter\NewsletterComposer;
use AfricaGates\Services\Newsletter\NewsletterSchedule;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\Maintenance;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The automated newsletter, end to end: who it may reach, when it is due, what it says,
 * and how a send survives being interrupted, overlapped, or started into an outage.
 *
 * Each test names the failure it exists to prevent, because an automated sender's faults
 * are all silent — a person who should not have been mailed was, or one who should have
 * been was not, and nothing on any screen looks wrong either way.
 */
final class NewsletterTest extends TestCase
{
    private const SITE = 'https://africagates.test';

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 1 October 2026, 09:30 in Lagos.
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:30:00', 'UTC'));
        DisplayTime::forget();
        SchemaHas::forget();
        foreach (['gates_newsletter', 'gates_newsletter_issues', 'gates_broadcast_log',
                  'gates_email_optout', 'gates_mail_incidents', 'gates_cycle_transitions'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('gates_settings')->whereIn('key_name', NewsletterSchedule::KEYS)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ══ fixtures ═════════════════════════════════════════════════════════════

    /** A transport that records what it was asked to send, and can be told to fail. */
    private function mailer(bool $configured = true, ?\Closure $result = null): OtpService
    {
        return new class(['username' => 'u', 'password' => 'p'], $configured, $result) extends OtpService {
            /** @var list<array<string,string>> */
            public array $raw = [];
            /** @var list<array<string,string>> */
            public array $branded = [];

            public function __construct(array $smtp, private bool $cfg, private ?\Closure $result)
            {
                parent::__construct($smtp);
            }

            public function smtpConfigured(): bool { return $this->cfg; }

            public function sendRawHtml(string $to, string $subject, string $html, string $plainBody = '',
                                        string $category = 'campaign', string $unsubscribeUrl = ''): array
            {
                $this->raw[] = compact('to', 'subject', 'html', 'plainBody', 'category', 'unsubscribeUrl');
                return $this->result ? ($this->result)($to) : ['success' => true];
            }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->branded[] = compact('to', 'subject', 'htmlBody', 'plainBody', 'category', 'unsubscribeUrl');
                return ['success' => true];
            }
        };
    }

    private function programme(string $slug, string $title, int $active = 1): int
    {
        return (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => $title, 'is_active' => $active,
        ]);
    }

    /** @param array<string,mixed> $w */
    private function cycle(int $pid, array $w): int
    {
        return (int) DB::table('gates_award_cycles')->insertGetId($w + [
            'programme_id' => $pid, 'year' => 2026, 'status' => 'upcoming',
        ]);
    }

    private function subscriber(string $email, bool $confirmed = true, ?string $source = 'homepage'): void
    {
        DB::table('gates_newsletter')->insert([
            'email' => $email, 'email_hash' => EmailOptOut::hash($email), 'source' => $source,
            'subscribed_at' => '2026-09-01 10:00:00',
            'confirmed_at' => $confirmed ? '2026-09-01 10:05:00' : null,
        ]);
    }

    /** Voting open in a real programme, closing in three days — the commonest issue. */
    private function liveAward(): int
    {
        $pid = $this->programme('celebrate', 'Celebrate Nigeria Awards');
        return $this->cycle($pid, [
            'status' => 'voting', 'voting_open' => '2026-09-20 00:00:00', 'voting_close' => '2026-10-04 22:59:00',
        ]);
    }

    private function schedule(string $mode, string $cadence = 'weekly'): NewsletterSchedule
    {
        return NewsletterSchedule::of(['newsletter_mode' => $mode, 'newsletter_cadence' => $cadence,
                                       'newsletter_weekday' => 4, 'newsletter_hour' => 9]);
    }

    // ══ the schedule ═════════════════════════════════════════════════════════

    public function test_it_is_off_unless_somebody_switches_it_on(): void
    {
        $s = NewsletterSchedule::load();
        $this->assertFalse($s->on(), 'a deploy must never be the act that mails the whole list');

        $this->subscriber('reader@example.com');
        $this->liveAward();
        $mail = $this->mailer();
        $this->assertSame(0, (new Newsletter($mail, self::SITE))->tick());
        $this->assertSame(0, DB::table('gates_newsletter_issues')->count());
        $this->assertSame([], $mail->raw);
    }

    public function test_the_slot_is_in_the_display_zone_not_utc(): void
    {
        $s = $this->schedule('review');

        // 08:30 UTC is 09:30 in Lagos: Thursday's 09:00 slot has come round.
        $slot = $s->dueSlot();
        $this->assertNotNull($slot);
        $this->assertSame('2026-10-01 09:00', $slot->format('Y-m-d H:i'));
        $this->assertSame('w2026-40', $s->periodKey($slot));

        // 08:15 UTC is 09:15 in Lagos: past the slot. Read in UTC it is 08:15 against a
        // 09:00 slot, which would hold Thursday's issue until 10:00 Lagos time.
        $this->assertSame('2026-10-01', $s->dueSlot(Carbon::parse('2026-10-01 08:15:00', 'UTC'))?->format('Y-m-d'));
        // 07:30 UTC is 08:30 in Lagos: before it. The slot that has come round is last week's.
        $this->assertNotSame('2026-10-01', $s->dueSlot(Carbon::parse('2026-10-01 07:30:00', 'UTC'))?->format('Y-m-d'));
    }

    public function test_a_missed_slot_goes_out_within_its_grace_and_not_a_week_late(): void
    {
        $s = $this->schedule('auto');
        $this->assertNotNull($s->dueSlot(Carbon::parse('2026-10-02 20:00:00', 'UTC')),
            'a cron down at 09:00 Thursday should still send on Friday');
        $this->assertNull($s->dueSlot(Carbon::parse('2026-10-06 08:00:00', 'UTC')),
            'five days late is last week\'s issue arriving the day before this week\'s');
    }

    public function test_each_cadence_has_one_period_per_issue(): void
    {
        $fort = $this->schedule('auto', 'fortnightly');
        $month = $this->schedule('auto', 'monthly');

        // A fortnight's two Thursdays are one slot and one non-slot.
        $a = $fort->dueSlot(Carbon::parse('2026-10-01 10:00:00', 'UTC'));
        $b = $fort->dueSlot(Carbon::parse('2026-10-08 10:00:00', 'UTC'));
        $this->assertTrue(($a === null) !== ($b === null),
            'every other Thursday means exactly one of two consecutive Thursdays');

        // The first Thursday of the month only.
        $this->assertSame('2026-10-01', $month->dueSlot()?->format('Y-m-d'));
        $this->assertNull($month->dueSlot(Carbon::parse('2026-10-08 10:00:00', 'UTC')));
        $this->assertSame('m2026-10', $month->periodKey($month->dueSlot()));
    }

    public function test_the_settings_round_trip_and_refuse_what_they_cannot_read_back(): void
    {
        $saved = NewsletterSchedule::save(['newsletter_mode' => 'auto', 'newsletter_cadence' => 'nonsense',
                                           'newsletter_weekday' => 9, 'newsletter_hour' => 31]);
        $read = NewsletterSchedule::load();

        $this->assertSame('auto', $read->mode);
        $this->assertSame(['weekly', 4, 9], [$read->cadence, $read->weekday, $read->hour]);
        $this->assertSame($saved->describe(), $read->describe());
        $this->assertSame('Every Thursday at 09:00 WAT', $read->describe());
    }

    // ══ the audience ═════════════════════════════════════════════════════════

    public function test_a_signup_gets_one_confirmation_and_nothing_else(): void
    {
        $mail = $this->mailer();
        $send = NewsletterAudience::transport($mail);

        $this->assertSame('new', NewsletterAudience::join('New@Example.com ', 'homepage', null, self::SITE, $send));
        $this->assertCount(1, $mail->branded);
        $this->assertStringContainsString('/email/confirm?e=', $mail->branded[0]['htmlBody']);
        $this->assertSame('', $mail->branded[0]['unsubscribeUrl'], 'a confirmation is one-to-one, not a list mail');

        // The same address posted again the same day — by them, or by a stranger — sends
        // nothing: the form is not a way to make this platform mail somebody on demand.
        NewsletterAudience::join('new@example.com', 'homepage', null, self::SITE, $send);
        $this->assertCount(1, $mail->branded);

        // Unconfirmed is not a recipient.
        $this->assertSame([], NewsletterAudience::recipients());

        // The next day they may ask again.
        Carbon::setTestNow(Carbon::now()->addHours(NewsletterAudience::RESEND_HOURS + 1));
        NewsletterAudience::join('new@example.com', 'homepage', null, self::SITE, $send);
        $this->assertCount(2, $mail->branded);
    }

    public function test_a_confirmed_subscriber_is_not_mailed_by_a_stranger_retyping_them(): void
    {
        $this->subscriber('ada@example.com');
        $mail = $this->mailer();
        $this->assertSame('confirmed',
            NewsletterAudience::join('ada@example.com', 'homepage', null, self::SITE, NewsletterAudience::transport($mail)));
        $this->assertSame([], $mail->branded);
    }

    public function test_the_link_confirms_only_its_own_address(): void
    {
        $url = NewsletterAudience::confirmUrl(self::SITE, 'ada@example.com');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('ada@example.com', NewsletterAudience::verify($q['e'], $q['t']));
        $this->assertNull(NewsletterAudience::verify(EmailOptOut::encode('eve@example.com'), $q['t']),
            'a token must not confirm a different address');
        $this->assertNull(NewsletterAudience::verify($q['e'], EmailOptOut::token('ada@example.com')),
            'the unsubscribe token must not double as a confirmation');
    }

    public function test_confirming_is_a_yes_given_after_the_no(): void
    {
        $this->subscriber('back@example.com', false);
        EmailOptOut::record('back@example.com');
        $this->assertSame([], NewsletterAudience::recipients());

        NewsletterAudience::confirm('back@example.com');

        $this->assertSame(['back@example.com'], array_column(NewsletterAudience::recipients(), 'email'),
            'otherwise somebody who once unsubscribed could never come back, and nothing would say why');
    }

    public function test_an_opt_out_from_any_bulk_mail_removes_a_recipient(): void
    {
        $this->subscriber('a@example.com');
        $this->subscriber('b@example.com');
        EmailOptOut::record('b@example.com');

        $this->assertSame(['a@example.com'], array_column(NewsletterAudience::recipients(), 'email'));
    }

    public function test_a_stand_call_request_is_not_a_newsletter_subscription(): void
    {
        $mail = $this->mailer();
        NewsletterAudience::join('vendor@example.com', 'stands:lagos-market', null, self::SITE,
            NewsletterAudience::transport($mail));

        $this->assertSame([], $mail->branded,
            'somebody who asked about one market must not be asked to join a newsletter');
        $this->assertSame(1, DB::table('gates_newsletter')->where('source', 'stands:lagos-market')->count(),
            'the stand-call notice still needs the row');
        $this->assertSame([], NewsletterAudience::unasked(10),
            'nor asked later, as a legacy subscriber');
    }

    public function test_legacy_subscribers_are_each_asked_once(): void
    {
        $this->subscriber('old1@example.com', false);
        $this->subscriber('old2@example.com', false);
        NewsletterSchedule::save(['newsletter_mode' => 'review']);
        $mail = $this->mailer();
        $n = new Newsletter($mail, self::SITE);

        $n->tick();
        $n->tick();

        $this->assertCount(2, $mail->branded, 'each asked exactly once across two ticks');
        $this->assertStringContainsString('We will not ask again', $mail->branded[0]['htmlBody'],
            'their welcome promised that ignoring it meant never hearing from us — this one must say so too');
        $this->assertSame(2, NewsletterAudience::counts()['awaiting']);
    }

    // ══ what an issue says ═══════════════════════════════════════════════════

    public function test_an_issue_states_what_the_award_pages_state(): void
    {
        $this->liveAward();
        $nom = $this->programme('principals', 'Incredible Principal Awards');
        $this->cycle($nom, ['status' => 'nominations', 'nominations_open' => '2026-09-01 00:00:00',
                            'nominations_close' => '2026-10-20 22:59:00']);

        $c = NewsletterComposer::compose(Carbon::now(), Carbon::now()->subDays(30));
        $titles = array_column($c['sections'], 'title');

        $this->assertSame(['Voting closes soon', 'Nominations open'], $titles);
        $closing = $c['sections'][0]['items'][0];
        $this->assertSame('/vote/celebrate', $closing['path']);
        $this->assertSame('Voting closes Sun 4 Oct 2026, 23:59 WAT.', $closing['line'],
            'every deadline is in the display zone and says which zone');
        $this->assertSame('Voting closes soon: Celebrate Nigeria Awards 2026', $c['subject']);
        $this->assertStringContainsString('Incredible Principal Awards', $c['preheader']);
    }

    public function test_the_sandbox_never_reaches_an_inbox(): void
    {
        // Active, with live-looking windows — the shape the rehearsal is seeded in, and so
        // exactly the shape the composer selects.
        $demo = $this->programme(DemoSeeder::PROGRAMME_SLUG, 'Rehearsal Awards');
        $this->cycle($demo, ['status' => 'voting', 'voting_open' => '2026-09-20 00:00:00',
                             'voting_close' => '2026-10-03 22:59:00']);

        $c = NewsletterComposer::compose(Carbon::now(), Carbon::now()->subDays(30));
        $this->assertSame([], $c['keys']);
    }

    public function test_a_results_date_is_not_an_announcement(): void
    {
        $pid = $this->programme('celebrate', 'Celebrate Nigeria Awards');
        // Past its results date, still judging: /results does not show it, nor may we.
        $late = $this->cycle($pid, ['status' => 'judging', 'voting_close' => '2026-09-10 00:00:00',
                                    'results_date' => '2026-09-28 00:00:00']);
        // Released and announced last week.
        $done = $this->cycle($pid, ['status' => 'results', 'year' => 2025, 'results_date' => '2026-09-25 00:00:00']);
        $row = ['cycle_id' => $done, 'to_status' => 'results', 'created_at' => '2026-09-25 09:00:00'];
        if (SchemaHas::column('gates_cycle_transitions', 'notify')) $row['notify'] = 1;
        DB::table('gates_cycle_transitions')->insert($row);

        $c = NewsletterComposer::compose(Carbon::now(), Carbon::now()->subDays(30));
        $keys = $c['keys'];
        $this->assertContains("result:$done", $keys);
        $this->assertNotContains("result:$late", $keys);
        $this->assertSame('Results announced', $c['sections'][0]['title']);
    }

    public function test_a_release_the_platform_chose_not_to_announce_is_not_news(): void
    {
        if (!SchemaHas::column('gates_cycle_transitions', 'notify')) {
            $this->markTestSkipped('ledger has no notify column');
        }
        $pid = $this->programme('celebrate', 'Celebrate Nigeria Awards');
        $quiet = $this->cycle($pid, ['status' => 'results', 'results_date' => '2026-09-25 00:00:00']);
        DB::table('gates_cycle_transitions')->insert(['cycle_id' => $quiet, 'to_status' => 'results',
            'created_at' => '2026-09-25 09:00:00', 'notify' => 0]);

        $this->assertNotContains("result:$quiet",
            NewsletterComposer::compose(Carbon::now(), Carbon::now()->subDays(30))['keys'],
            'a stale cycle released without notification must not be announced by the newsletter instead');
    }

    public function test_a_challenge_line_never_carries_its_prize(): void
    {
        DB::table('gates_challenges')->insert([
            'slug' => 'independence', 'title' => 'Celebrate Nigeria', 'kicker' => 'Nominate three',
            'status' => 'open', 'prize_amount' => 250000, 'prize_currency' => 'NGN',
            'prize_label' => 'Win ₦250,000', 'ends_at' => '2026-10-15 22:59:00',
            'created_at' => '2026-09-01 00:00:00',
        ]);
        $c = NewsletterComposer::compose(Carbon::now(), Carbon::now()->subDays(30));
        $flat = json_encode($c, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Celebrate Nigeria', (string) $flat);
        foreach (['250', '₦', 'NGN', 'Win'] as $money) {
            $this->assertStringNotContainsString($money, (string) $flat,
                'a prize is stated on the challenge page beside its terms, never in a summary');
        }
    }

    // ══ the life of an issue ═════════════════════════════════════════════════

    public function test_an_empty_or_unchanged_issue_is_skipped_and_says_why(): void
    {
        $empty = Newsletter::compose('w2026-40', $this->schedule('auto'));
        $this->assertSame(Newsletter::ST_SKIPPED, $empty->status);
        $this->assertStringContainsString('Nothing open', (string) $empty->note);

        $this->liveAward();
        $first = Newsletter::compose('w2026-41', $this->schedule('auto'));
        DB::table('gates_newsletter_issues')->where('id', $first->id)->update(['status' => Newsletter::ST_SENT]);

        $again = Newsletter::compose('w2026-42', $this->schedule('auto'));
        $this->assertSame(Newsletter::ST_SKIPPED, $again->status,
            'the same facts a second week running is repetition, not news');
        $this->assertStringContainsString('#' . $first->id, (string) $again->note);
    }

    public function test_a_period_is_composed_once_however_many_ticks_ask(): void
    {
        $this->liveAward();
        $a = Newsletter::compose('w2026-40', $this->schedule('review'));
        $b = Newsletter::compose('w2026-40', $this->schedule('review'));

        $this->assertSame((int) $a->id, (int) $b->id);
        $this->assertSame(1, DB::table('gates_newsletter_issues')->count());
        $this->assertSame(Newsletter::ST_DRAFT, $a->status, 'review mode waits for a person');
    }

    public function test_review_mode_composes_and_sends_nothing_until_approved(): void
    {
        $this->liveAward();
        $this->subscriber('reader@example.com');
        $mail = $this->mailer();
        $n = new Newsletter($mail, self::SITE);

        $n->tick($this->schedule('review'));
        $issue = Newsletter::recent(1)[0];
        $this->assertSame(Newsletter::ST_DRAFT, $issue->status);
        $this->assertSame([], $mail->raw);

        Newsletter::approve((int) $issue->id, 7);
        $n->tick($this->schedule('review'));
        $this->assertCount(1, $mail->raw);
        $this->assertSame(Newsletter::ST_SENT, Newsletter::find((int) $issue->id)->status);
    }

    public function test_auto_mode_sends_to_confirmed_subscribers_only_with_a_way_out(): void
    {
        $this->liveAward();
        $this->subscriber('yes@example.com');
        $this->subscriber('maybe@example.com', false);
        $this->subscriber('stopped@example.com');
        EmailOptOut::record('stopped@example.com');
        $mail = $this->mailer();

        (new Newsletter($mail, self::SITE))->tick($this->schedule('auto'));

        $this->assertSame(['yes@example.com'], array_column($mail->raw, 'to'));
        $m = $mail->raw[0];
        $this->assertSame(EmailOptOut::url(self::SITE, 'yes@example.com'), $m['unsubscribeUrl'],
            'without the list headers Gmail and Yahoo file a newsletter as spam');
        $this->assertStringContainsString(self::SITE . '/vote/celebrate', $m['html']);
        $this->assertStringContainsString('confirmed a subscription on 1 September 2026', $m['html'],
            'a newsletter that does not say who asked for it reads as spam to whoever forgot');
        $this->assertStringContainsString('Unsubscribe: ', $m['plainBody']);
    }

    public function test_a_resumed_or_overlapping_send_mails_nobody_twice(): void
    {
        $this->liveAward();
        foreach (range(1, 5) as $i) $this->subscriber("r$i@example.com");
        $issue = Newsletter::compose('w2026-40', $this->schedule('auto'));
        $mail = $this->mailer();
        $n = new Newsletter($mail, self::SITE);

        // Another tick already owns r2 — it claimed the address and is mid-send.
        $this->assertTrue(BroadcastLog::claim(Newsletter::campaignKey($issue), 'r2@example.com'));

        $first = $n->sendBatch($issue, 2);
        $this->assertSame(2, $first['sent']);
        $n->sendBatch(Newsletter::find((int) $issue->id), 10);
        $n->sendBatch(Newsletter::find((int) $issue->id), 10);

        $to = array_column($mail->raw, 'to');
        $this->assertSame(count($to), count(array_unique($to)), 'somebody was mailed twice');
        $this->assertNotContains('r2@example.com', $to, 'a claimed address belongs to whoever claimed it');
        $this->assertCount(4, $to);
        $this->assertSame(Newsletter::ST_SENT, Newsletter::find((int) $issue->id)->status);
    }

    public function test_an_address_another_tick_claims_mid_batch_is_left_to_it(): void
    {
        $this->liveAward();
        foreach (range(1, 3) as $i) $this->subscriber("r$i@example.com");
        $issue = Newsletter::compose('w2026-40', $this->schedule('auto'));
        $key = Newsletter::campaignKey($issue);

        // A second tick that read the same queue claims r3 while this one is sending r1 —
        // after this batch decided who was left, which is the window the claim exists for.
        $mail = $this->mailer(true, static function (string $to) use ($key): array {
            if ($to === 'r1@example.com') BroadcastLog::claim($key, 'r3@example.com');
            return ['success' => true];
        });
        (new Newsletter($mail, self::SITE))->sendBatch($issue, 10);

        $this->assertSame(['r1@example.com', 'r2@example.com'], array_column($mail->raw, 'to'),
            'two overlapping ticks would each mail r3 without the claim');
    }

    public function test_it_holds_while_mail_is_failing_and_says_so(): void
    {
        $this->liveAward();
        $this->subscriber('reader@example.com');
        DB::table('gates_mail_incidents')->insert(['opened_at' => '2026-10-01 08:00:00',
            'opened_by' => 'failures', 'cause' => 'auth']);
        $mail = $this->mailer();

        (new Newsletter($mail, self::SITE))->tick($this->schedule('auto'));

        $issue = Newsletter::recent(1)[0];
        $this->assertSame([], $mail->raw, 'a list sent into a broken transport is a few thousand failures');
        $this->assertSame(Newsletter::ST_APPROVED, $issue->status, 'held, not lost');
        $this->assertStringContainsString('email is failing', (string) $issue->note);
    }

    public function test_it_holds_when_there_is_no_transport(): void
    {
        $this->liveAward();
        $this->subscriber('reader@example.com');
        $mail = $this->mailer(false);

        (new Newsletter($mail, self::SITE))->tick($this->schedule('auto'));

        $this->assertSame([], $mail->raw);
        $this->assertStringContainsString('not configured', (string) Newsletter::recent(1)[0]->note);
    }

    public function test_three_failures_in_a_row_stop_the_batch(): void
    {
        $this->liveAward();
        foreach (range(1, 6) as $i) $this->subscriber("r$i@example.com");
        $mail = $this->mailer(true, static fn() => ['success' => false, 'error' => '550 mailbox quota exceeded']);

        (new Newsletter($mail, self::SITE))->tick($this->schedule('auto'));

        $this->assertCount(Newsletter::STOP_AFTER, $mail->raw,
            'a transport refusing everything must not be walked through the whole list');
        $issue = Newsletter::recent(1)[0];
        $this->assertSame(Newsletter::ST_SENDING, $issue->status);
        $this->assertStringContainsString('550 mailbox quota exceeded', (string) $issue->note);
    }

    public function test_an_issue_that_has_started_cannot_be_recorded_as_skipped(): void
    {
        $this->liveAward();
        $this->subscriber('a@example.com');
        $this->subscriber('b@example.com');
        $issue = Newsletter::compose('w2026-40', $this->schedule('auto'));
        (new Newsletter($this->mailer(), self::SITE))->sendBatch($issue, 1);

        $this->assertFalse(Newsletter::skip((int) $issue->id)['ok'],
            'somebody already has it; "skipped" would be a false record of what went out');
    }

    // ══ the voting reminder, which already mailed this list ══════════════════

    private function remind(OtpService $mail): void
    {
        $c = new class($mail) implements ContainerInterface {
            public function __construct(private OtpService $m) {}
            public function get(string $id): mixed { return $this->m; }
            public function has(string $id): bool { return $id === OtpService::class; }
        };
        $_ENV['APP_URL'] = self::SITE;
        $m = new Maintenance($c);
        $r = new \ReflectionMethod($m, 'sendVotingReminders');
        $r->invoke($m);
    }

    public function test_the_voting_reminder_honours_the_stop_and_never_repeats(): void
    {
        $pid = $this->programme('celebrate', 'Celebrate Nigeria Awards');
        $this->cycle($pid, ['status' => 'voting', 'voting_close' => '2026-10-02 20:00:00']);
        $this->subscriber('yes@example.com');
        $this->subscriber('typed-in@example.com', false);
        $this->subscriber('stopped@example.com');
        EmailOptOut::record('stopped@example.com');
        // Profiles are the reminder's other half, and the half the newsletter list's own
        // filter never sees — so the stop has to be checked here, not inherited.
        foreach (['profile@example.com', 'profile-stopped@example.com'] as $i => $e) {
            DB::table('gates_profiles')->insert(['slug' => "p$i", 'display_name' => "P$i",
                'email' => $e, 'status' => 'approved']);
        }
        EmailOptOut::record('profile-stopped@example.com');
        $mail = $this->mailer();

        $this->remind($mail);
        // The 06:00 window is fifteen minutes and the webcron ticks every five.
        $this->remind($mail);
        $this->remind($mail);

        $to = array_column($mail->branded, 'to');
        sort($to);
        $this->assertSame(['profile@example.com', 'yes@example.com'], $to,
            'once each, to a profile or a confirmed address, never to one that said stop or never said yes');
        $this->assertSame(EmailOptOut::url(self::SITE, $mail->branded[0]['to']), $mail->branded[0]['unsubscribeUrl'],
            'the one bulk mail here nobody could switch off');
    }

    // ══ the way in ═══════════════════════════════════════════════════════════

    private function controller(?OtpService $mail = null): NewsletterController
    {
        return new NewsletterController(
            \Slim\Views\Twig::create(__DIR__ . '/../../templates', ['cache' => false]),
            new RateLimitService(), $mail);
    }

    /** The real app, through the real container and middleware, as public/index.php builds it. */
    private function app(): \Slim\App
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($b->build());
        $app = \Slim\Factory\AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(\Slim\Views\TwigMiddleware::createFromContainer($app, \Slim\Views\Twig::class));
        $app->add(new \AfricaGates\Middleware\CsrfMiddleware());
        $app->addBodyParsingMiddleware();
        $err = $app->addErrorMiddleware(false, false, false);
        $err->setDefaultErrorHandler(new \AfricaGates\Handlers\ErrorHandler($app));
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        return $app;
    }

    /** @param array<string,string> $body */
    private function hit(string $method, string $uri, array $body = []): \Psr\Http\Message\ResponseInterface
    {
        $_SESSION['csrf_token'] = 'tok';
        $req = (new ServerRequestFactory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => '10.1.1.1']);
        if ($method === 'POST') $req = $req->withParsedBody($body + ['_token' => 'tok']);
        return $this->app()->handle($req);
    }

    public function test_the_pages_render_through_the_real_app(): void
    {
        NewsletterSchedule::save(['newsletter_mode' => 'auto', 'newsletter_weekday' => 4, 'newsletter_hour' => 9]);

        $page = $this->hit('GET', '/newsletter');
        $html = (string) $page->getBody();
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('action="/newsletter"', $html);
        $this->assertStringContainsString('Every Thursday at 09:00 WAT', $html,
            'the day the page promises is read from the setting that sends on it');
        foreach (NewsletterComposer::SECTIONS as $title) $this->assertStringContainsString($title, $html);

        $bad = $this->hit('POST', '/newsletter', ['email' => 'not-an-address']);
        $this->assertSame(422, $bad->getStatusCode());
        $b = (string) $bad->getBody();
        $this->assertStringContainsString('aria-invalid="true"', $b);
        $this->assertStringContainsString('value="not-an-address"', $b, 'never clear what somebody typed');

        // The confirmation link, as a mail scanner fetches it: it shows, it does not act.
        $url = NewsletterAudience::confirmUrl('', 'scan@example.com');
        $this->subscriber('scan@example.com', false);
        $shown = $this->hit('GET', $url);
        $this->assertSame(200, $shown->getStatusCode());
        $this->assertStringContainsString('Yes, send it to me', (string) $shown->getBody());
        $this->assertSame([], NewsletterAudience::recipients(), 'a GET must never confirm');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $done = $this->hit('POST', '/email/confirm', ['e' => $q['e'], 't' => $q['t']]);
        $this->assertStringContainsString('You are on the list', (string) $done->getBody());
        $this->assertSame(['scan@example.com'], array_column(NewsletterAudience::recipients(), 'email'));
    }

    public function test_the_signup_form_posts_and_lands_on_what_to_do_next(): void
    {
        $mail = $this->mailer();
        $req = (new ServerRequestFactory())->createServerRequest('POST', self::SITE . '/newsletter',
                ['REMOTE_ADDR' => '10.0.0.9'])->withParsedBody(['email' => 'join@example.com']);

        $res = $this->controller($mail)->join($req, new Response());

        $this->assertSame(303, $res->getStatusCode(), 'a refresh must not resubmit');
        $this->assertSame('/newsletter?sent=1', $res->getHeaderLine('Location'));
        $this->assertCount(1, $mail->branded);
        $this->assertSame('newsletter-page',
            DB::table('gates_newsletter')->where('email_hash', EmailOptOut::hash('join@example.com'))->value('source'));
    }

    public function test_the_stand_call_form_reads_the_field_the_api_actually_sends(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/pages/stands/call.twig');
        $this->assertStringNotContainsString('j && j.ok', $tpl,
            'the API answers `success`; reading `ok` told every person who asked that it had failed');
        $this->assertStringContainsString('j && j.success', $tpl);
    }

    public function test_the_newsletter_is_linked_from_every_page(): void
    {
        $this->assertStringContainsString('href="/newsletter"',
            (string) file_get_contents(__DIR__ . '/../../templates/layout/footer.twig'),
            'a newsletter nobody can find is a newsletter with no way in');
    }
}
