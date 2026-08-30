<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteReminders;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The reminders that go out between the invitation and the evening.
 *
 * ── WHAT MAKES THIS WORTH TESTING HARD ───────────────────────────────────────
 *
 * Every other message on this platform is sent by somebody pressing a button: an
 * operator chose the moment, watched the count, and can stop. This one is posted by a
 * cron, to people being honoured in public, on a schedule set on a different screen —
 * and there is no shell on production to go and see what it did.
 *
 * So the properties held here are the ones nobody can check by eye afterwards: that a
 * person is never written to twice for the same mark, that nobody is reminded of an
 * invitation they never received, that a missed morning does not silently swallow a
 * reminder, and that the message says how long is ACTUALLY left rather than the mark it
 * was scheduled on.
 */
final class InviteRemindersTest extends TestCase
{
    private int $eventId = 0;
    private object $event;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'like', 'invite_%')->delete();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);

        // Ten days out, so the 14-day mark is the one due and there is a mark on each
        // side of it. A fixture pinned to the exact mark would pass whether or not the
        // window logic works at all.
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'Africa GATES Gala 2026',
            'event_date' => Carbon::now()->addDays(10)->setTime(18, 0)->toDateTimeString(),
            'status' => 'published',
            'venue' => 'Eko Convention Centre', 'location' => 'Lagos',
        ]);
        EventInvites::setProgrammes($this->eventId, [$pid]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $this->eventId, 'slug' => 'supporter', 'name' => 'Supporter',
            'price_naira' => 5000, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->event = DB::table('gates_site_events')->where('id', $this->eventId)->first();
    }

    /** An invitation that has been SENT — the only kind a reminder may follow. */
    private function invited(
        string $email = 'ada@example.com',
        string $audience = InviteAudience::NOMINEE,
        bool $sent = true
    ): object {
        $inv = EventInvites::mint($this->eventId, $audience,
            ['name' => 'Ada Obi', 'email' => $email, 'nominee_id' => 0, 'judge_id' => 0]);

        if ($sent) {
            DB::table('gates_event_invites')->where('id', $inv->id)
                ->update(['sent_at' => Carbon::now()->subDays(20)->toDateTimeString()]);
        }

        return DB::table('gates_event_invites')->where('id', $inv->id)->first();
    }

    /** A transport that records instead of dialling out. */
    private function recorder(): OtpService
    {
        return new class(['host' => 'localhost', 'port' => 25,
                          'username' => 'u', 'password' => 'p',
                          'from_address' => 'no@example.com', 'from_name' => 'X']) extends OtpService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];

            public function smtpConfigured(): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sent[] = compact('to', 'subject', 'htmlBody', 'plainBody', 'category', 'attachments');

                return ['success' => true];
            }
        };
    }

    // ══ THE SCHEDULE ════════════════════════════════════════════════════════

    public function test_the_marks_default_and_are_read_largest_first(): void
    {
        $this->assertSame(InviteReminders::DEFAULT_MARKS, InviteReminders::marks());

        DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_days', 'value' => '7, 21, 1']);
        $this->assertSame([21, 7, 1], InviteReminders::marks(),
            'largest first, because the largest is the answer to "when do these start"');
    }

    /**
     * A free-text field is not a schedule until it has been clamped.
     *
     * A 0 would remind somebody on the morning of, which the eve mark already does better;
     * a four-figure typo would start mailing about a ceremony three years out; and twenty
     * marks would turn a field nobody thinks of as a mailing frequency into a month of
     * daily post.
     */
    public function test_a_nonsense_schedule_cannot_become_a_mailing_frequency(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => 'invite_reminder_days',
            'value'    => '0, 9999, -5, 30, 30, 2, 3, 4, 5, 6, 8, 9, 10',
        ]);

        $marks = InviteReminders::marks();

        $this->assertNotContains(0, $marks, 'a 0 is the morning of — the eve mark covers it');
        $this->assertNotContains(9999, $marks);
        $this->assertLessThanOrEqual(6, count($marks), 'every mark is another message to the same person');
        // The NEAREST six survive the cap, not the furthest. "Tomorrow" is worth more to
        // somebody arranging a journey than "in thirty days", and a cap that discarded the
        // eve reminder off a long list would be worse than no cap at all.
        $this->assertContains(2, $marks, 'the marks nearest the evening are the ones kept');
        $this->assertNotContains(30, $marks, 'the furthest are what a long list loses');
        $this->assertSame(array_values(array_unique($marks)), $marks, 'a repeated mark is not two reminders');

        $descending = $marks;
        rsort($descending);
        $this->assertSame($descending, $marks, 'largest first, whatever order they were typed in');
    }

    public function test_an_unreadable_schedule_falls_back_rather_than_sending_nothing(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_days', 'value' => 'soon-ish']);

        $this->assertSame(InviteReminders::DEFAULT_MARKS, InviteReminders::marks(),
            'an unparseable field must not silently disable the whole feature');
    }

    // ══ THE TIME OF DAY ═════════════════════════════════════════════════════

    /**
     * A schedule with no clock means "whenever the cron reaches it".
     *
     * Which is not an answer anybody can give when asked what time these go out — and a
     * reminder is the only mail on this platform whose moment is CHOSEN rather than
     * triggered by something the recipient did, so it is the only one where the question
     * has a real answer to give.
     */
    public function test_the_send_time_defaults_and_is_read_as_an_operator_types_it(): void
    {
        $this->assertSame(InviteReminders::DEFAULT_TIME, InviteReminders::sendTimeLabel());

        foreach ([
            '08:30'    => '08:30',
            '08:30:00' => '08:30',   // <input type="time"> posts seconds when a step is set
            '9'        => '09:00',   // a person types "9" at least as readily as "09:00"
            '23:59'    => '23:59',
            '00:00'    => '00:00',
        ] as $typed => $expected) {
            DB::table('gates_settings')->where('key_name', 'invite_reminder_time')->delete();
            DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_time', 'value' => (string) $typed]);

            $this->assertSame($expected, InviteReminders::sendTimeLabel(), 'typed: ' . $typed);
        }
    }

    /** An unreadable time must not silently stop every reminder on the platform. */
    public function test_an_unreadable_time_falls_back_rather_than_sending_nothing(): void
    {
        foreach (['morning', '', '99:99'] as $junk) {
            DB::table('gates_settings')->where('key_name', 'invite_reminder_time')->delete();
            DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_time', 'value' => $junk]);

            $this->assertSame(InviteReminders::DEFAULT_TIME, InviteReminders::sendTimeLabel(),
                'junk: "' . $junk . '"');
        }
    }

    /**
     * The set time is when reminders BEGIN, and the window runs to the end of the day.
     *
     * Not a single slot. The sweep is capped per tick, so a large hall takes several to
     * drain — and a cron on a shared host is not a guarantee, so a window that had already
     * closed would swallow a whole day's reminders with nothing to say so.
     */
    public function test_nothing_goes_before_the_hour_and_the_window_runs_to_the_end_of_the_day(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_time', 'value' => '09:30']);

        $at = static fn (string $t): Carbon => Carbon::parse('2026-12-01 ' . $t);

        $this->assertFalse(InviteReminders::dueNow($at('03:00')), 'nothing at 03:00, ever');
        $this->assertFalse(InviteReminders::dueNow($at('09:00')));
        $this->assertFalse(InviteReminders::dueNow($at('09:29')));
        $this->assertTrue(InviteReminders::dueNow($at('09:30')), 'the minute itself opens it');
        $this->assertTrue(InviteReminders::dueNow($at('14:00')), 'a capped sweep needs the afternoon');
        $this->assertTrue(InviteReminders::dueNow($at('23:45')), 'the window runs to the end of the day');
    }

    /**
     * A mark holds until the next one below it.
     *
     * THE POINT OF THE WHOLE WINDOW DESIGN. Scheduled work runs on a shared host with no
     * shell; if a mark only fired on its exact day, one missed 06:00 would swallow that
     * reminder for good and nothing would ever say so.
     */
    public function test_a_mark_holds_until_the_next_one_below_it(): void
    {
        $marks = [30, 14, 7, 1];

        $this->assertSame(30, InviteReminders::dueMark(30, $marks));
        $this->assertSame(14, InviteReminders::dueMark(14, $marks));
        $this->assertSame(14, InviteReminders::dueMark(12, $marks), 'a cron that missed day 14 still sends');
        $this->assertSame(7,  InviteReminders::dueMark(7, $marks));
        $this->assertSame(1,  InviteReminders::dueMark(1, $marks));
        $this->assertSame(1,  InviteReminders::dueMark(0, $marks), 'the morning of is still the eve mark');

        $this->assertNull(InviteReminders::dueMark(31, $marks), 'too early to be reminding anybody');
        $this->assertNull(InviteReminders::dueMark(-1, $marks), 'the evening has happened');
    }

    /** Calendar days, not 24-hour blocks — "tomorrow" has to mean tomorrow's date. */
    public function test_the_countdown_is_calendar_days_and_reads_as_english(): void
    {
        $now = Carbon::parse('2026-12-01 09:00:00');

        // 26 hours away. A duration-based count calls this two days and is wrong in the
        // one place the reader can check it: a wall calendar.
        $this->assertSame(1, InviteReminders::daysUntil('2026-12-02 11:00:00', $now));
        $this->assertSame(0, InviteReminders::daysUntil('2026-12-01 23:30:00', $now));
        $this->assertSame(-1, InviteReminders::daysUntil('2026-11-30 09:00:00', $now));

        $this->assertSame('today', InviteReminders::countdown(0));
        $this->assertSame('tomorrow', InviteReminders::countdown(1));
        $this->assertSame('in 9 days', InviteReminders::countdown(9));
    }

    // ══ THE MESSAGE ═════════════════════════════════════════════════════════

    /**
     * The framing this feature exists for: a celebration of somebody's name and legacy,
     * with the time left in the subject where an inbox shows it.
     */
    public function test_the_reminder_celebrates_the_name_and_carries_the_countdown(): void
    {
        $inv = $this->invited();
        $m   = $this->recorder();

        $r = InviteReminders::send($inv, $this->event, 9, 14, $m);

        $this->assertTrue($r['ok'], $r['error']);
        $this->assertCount(1, $m->sent);

        $sent = $m->sent[0];
        $this->assertStringContainsString('Ada Obi', $sent['subject'], 'addressed to one person');
        $this->assertStringContainsString('Grand Celebration', $sent['subject']);
        $this->assertStringContainsString('legacy', $sent['subject']);
        $this->assertStringContainsString('in 9 days', $sent['subject'], 'the whole content of a reminder');
        // Name and countdown inside the first twenty characters, because a phone shows
        // fewer than sixty and the countdown is the fact this message exists to carry.
        $this->assertStringContainsString('in 9 days', substr($sent['subject'], 0, 20),
            'the countdown must survive a mobile inbox truncating the subject');

        $html = $sent['htmlBody'];
        $this->assertStringContainsString('In 9 days', $html);
        $this->assertStringContainsString('Eko Convention Centre', $html);
        $this->assertStringContainsString((string) $inv->reference, $html, 'their guest code, so nobody hunts for the first email');
        $this->assertStringContainsString('/honour/' . $inv->reference, $html, 'the pass is a link');
        $this->assertStringContainsString('legacy', $html);
    }

    /**
     * The message never names the mark it was scheduled on.
     *
     * A "your 14-day reminder" landing on day nine tells the reader the sender is not
     * paying attention. The mark decides WHEN to send; the message says what is true.
     */
    public function test_the_message_says_the_days_left_and_never_the_mark(): void
    {
        $inv = $this->invited();
        $m   = $this->recorder();

        InviteReminders::send($inv, $this->event, 9, 14, $m);

        $this->assertStringNotContainsString('14', $m->sent[0]['subject'],
            'the mark is scheduling, not content');
        $this->assertStringContainsString('in 9 days', $m->sent[0]['subject']);
    }

    /** Each audience is reminded in its own words, and the operator can replace either. */
    public function test_each_audience_has_its_own_sentence_and_the_operator_can_replace_it(): void
    {
        $nominee = InviteReminders::copy(InviteAudience::NOMINEE);
        $judge   = InviteReminders::copy(InviteAudience::JUDGE);

        $this->assertNotSame($nominee['line'], $judge['line'],
            'a nominee is honoured for what they did and a judge for how they judged it');
        $this->assertStringContainsString('legacy', strtolower($nominee['headline']));

        DB::table('gates_settings')->insert([
            'key_name' => $nominee['line_setting'],
            'value'    => 'Your name will be read aloud in a full hall.',
        ]);

        $this->assertSame('Your name will be read aloud in a full hall.',
            InviteReminders::copy(InviteAudience::NOMINEE)['line']);
        $this->assertNotSame($nominee['line_default'],
            InviteReminders::copy(InviteAudience::NOMINEE)['line'],
            'the default is what it falls back to, not what it sends over an operator');
    }

    public function test_the_operators_sentence_reaches_the_reader_in_both_halves(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => 'invite_reminder_line_nominee',
            'value'    => 'The hall is nearly full and your chair has your name on it.',
        ]);

        $m = $this->recorder();
        InviteReminders::send($this->invited(), $this->event, 3, 7, $m);

        // BOTH halves, because this codebase has the scar: the invitation's "why" sentence
        // was assembled in the template AND in the plain-text builder, and fixing one left
        // the other sending the mangled version.
        $this->assertStringContainsString('your chair has your name on it', $m->sent[0]['htmlBody']);
        $this->assertStringContainsString('your chair has your name on it', $m->sent[0]['plainBody']);
    }

    /** A reminder is a nudge, not a re-issue: the formal letter went once. */
    public function test_no_attachment_rides_along(): void
    {
        $m = $this->recorder();
        InviteReminders::send($this->invited(), $this->event, 5, 7, $m);

        $this->assertSame([], $m->sent[0]['attachments'],
            'repeating a PDF four times is weight in an inbox for a document they already have');
    }

    // ══ WHO IS WRITTEN TO, AND HOW OFTEN ════════════════════════════════════

    public function test_nobody_is_written_to_twice_for_one_mark(): void
    {
        $inv = $this->invited();
        $m   = $this->recorder();

        $first  = InviteReminders::send($inv, $this->event, 9, 14, $m);
        $second = InviteReminders::send($inv, $this->event, 8, 14, $m);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertTrue($second['skipped']);
        $this->assertCount(1, $m->sent, 'one mark is one message');
    }

    /** A different mark IS a different message — that is what a schedule is. */
    public function test_a_later_mark_still_sends(): void
    {
        $inv = $this->invited();
        $m   = $this->recorder();

        InviteReminders::send($inv, $this->event, 9, 14, $m);
        InviteReminders::send($inv, $this->event, 5, 7, $m);

        $this->assertCount(2, $m->sent);
    }

    public function test_an_opted_out_address_is_never_written_to(): void
    {
        $inv = $this->invited('gone@example.com');
        EmailOptOut::record('gone@example.com', 'test');

        $m = $this->recorder();
        $r = InviteReminders::send($inv, $this->event, 9, 14, $m);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['skipped']);
        $this->assertSame([], $m->sent);
    }

    // ══ THE SWEEP ═══════════════════════════════════════════════════════════

    public function test_the_sweep_sends_the_due_mark_and_then_nothing_more(): void
    {
        $this->invited('ada@example.com');
        $this->invited('bola@example.com');

        $m = $this->recorder();

        $this->assertSame(2, InviteReminders::sweep($m), 'both guests of honour, at the due mark');
        $this->assertSame(0, InviteReminders::sweep($m), 'a second tick the same day sends nothing');
        $this->assertCount(2, $m->sent);
    }

    /**
     * Nobody is reminded of something they were never told.
     *
     * A minted-but-unsent invitation is a person who has heard nothing from us. "The
     * celebration is in nine days" as the FIRST thing they receive is worse than silence:
     * no pass, no reference, no letter, and no idea what it is about.
     */
    public function test_an_unsent_invitation_is_never_reminded(): void
    {
        $this->invited('never@example.com', InviteAudience::NOMINEE, false);

        $m = $this->recorder();

        $this->assertSame(0, InviteReminders::sweep($m));
        $this->assertSame([], $m->sent);
    }

    /**
     * The sandbox must never reach the public.
     *
     * `DemoSeeder` makes real rows with real flags because the sandbox exists to be walked
     * through for real — which means a rehearsal invitation is a real row that the sweep
     * would really email.
     */
    public function test_the_sandbox_is_never_written_to(): void
    {
        $this->invited('rehearsal@' . DemoSeeder::MAIL_DOMAIN);

        $m = $this->recorder();

        $this->assertSame(0, InviteReminders::sweep($m));
        $this->assertSame([], $m->sent);
    }

    public function test_an_event_too_far_out_is_left_alone(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['event_date' => Carbon::now()->addDays(200)->toDateTimeString()]);
        $this->invited();

        $m = $this->recorder();

        $this->assertSame(0, InviteReminders::sweep($m),
            'reminders start at the largest mark, not the moment a list is sent');
    }

    public function test_an_event_that_has_happened_is_left_alone(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['event_date' => Carbon::now()->subDays(2)->toDateTimeString()]);
        $this->invited();

        $m = $this->recorder();

        $this->assertSame(0, InviteReminders::sweep($m));
    }

    public function test_the_switch_stops_everything(): void
    {
        $this->invited();
        DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_enabled', 'value' => '0']);

        $m = $this->recorder();

        $this->assertFalse(InviteReminders::enabled());
        $this->assertSame(0, InviteReminders::sweep($m));
        $this->assertSame([], $m->sent);
    }

    /**
     * The cap is what one shared-host cron tick can finish, and what is left waits.
     *
     * Asserted as "the rest are still pending", not merely "it stopped": a cap that
     * dropped the remainder instead of deferring it would look identical here on the
     * first tick and lose people on every one after.
     */
    public function test_the_cap_defers_the_rest_rather_than_dropping_them(): void
    {
        for ($i = 0; $i < 4; $i++) $this->invited('guest' . $i . '@example.com');

        $m = $this->recorder();

        $this->assertSame(2, InviteReminders::sweep($m, 2));
        $this->assertSame(2, InviteReminders::sweep($m, 2), 'the rest go on the next tick');
        $this->assertSame(0, InviteReminders::sweep($m, 2));
        $this->assertCount(4, $m->sent);
    }

    // ══ THE SCREEN ══════════════════════════════════════════════════════════

    /**
     * The state has a reader.
     *
     * A declared field with no reader is the most expensive bug available in this
     * codebase — six have shipped. The reminder run is unattended, so if this panel does
     * not answer "did they go, and when is the next one", nothing does.
     */
    public function test_the_screen_can_say_what_has_gone_and_what_is_next(): void
    {
        $this->invited();

        $before = InviteReminders::status($this->eventId, (string) $this->event->event_date);
        $this->assertTrue($before['enabled']);
        $this->assertSame(10, $before['days']);
        $this->assertSame(14, $before['due'], 'ten days out, the 14-day mark is the one holding');
        $this->assertSame(7, $before['next'], 'and the panel can say which one follows it');
        $this->assertSame(1, $before['audience_count']);

        $due = array_values(array_filter($before['sent'], static fn (array $r): bool => $r['due']));
        $this->assertCount(1, $due);
        $this->assertSame(0, $due[0]['count']);

        // The 30 is behind us — distinguished from "not yet", because both render as a
        // zero and they are opposite problems: one will never fire, one is still coming.
        $past = array_values(array_filter($before['sent'], static fn (array $r): bool => $r['past']));
        $this->assertSame([30], array_column($past, 'mark'));

        InviteReminders::sweep($this->recorder());

        $after = InviteReminders::status($this->eventId, (string) $this->event->event_date);
        $sent  = array_values(array_filter($after['sent'], static fn (array $r): bool => $r['mark'] === 14));
        $this->assertSame(1, $sent[0]['count'], 'what went out has to be visible on the screen');
    }

    /**
     * The evening's time is NAMED, not just printed.
     *
     * The invitation is read once, months out, by somebody deciding whether to come. This
     * one is read on the way — by a judge flying in, a nominee working out when to leave —
     * and "18:00" with no zone is a time in nobody's particular day.
     */
    public function test_the_reminder_names_the_timezone_of_the_evening(): void
    {
        $zone = \AfricaGates\Support\DisplayTime::abbr();
        $this->assertNotSame('', $zone, 'the harness must have a zone, or this proves nothing');

        $m = $this->recorder();
        InviteReminders::send($this->invited(), $this->event, 9, 14, $m);

        $this->assertStringContainsString($zone, $m->sent[0]['htmlBody']);
        $this->assertStringContainsString($zone, $m->sent[0]['plainBody'],
            'the plain-text half is what a screen reader may be handed');
    }

    /** The screen says what time they go, and whether today's window has opened. */
    public function test_the_screen_carries_the_send_time(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_time', 'value' => '08:15']);

        $st = InviteReminders::status($this->eventId, (string) $this->event->event_date);

        $this->assertSame('08:15', $st['time']);
        $this->assertSame(\AfricaGates\Support\DisplayTime::abbr(), $st['zone'],
            'a bare 08:15 read from another country is a time in no particular day');
        $this->assertSame(InviteReminders::dueNow(), $st['open'],
            '"has not gone" and "has not gone YET" are the same zero without this');
    }

    public function test_the_preview_sends_nothing_and_logs_nothing(): void
    {
        $inv = $this->invited();
        $m   = $this->recorder();

        $html = InviteReminders::preview($inv, $this->event, 3, $m);

        $this->assertStringContainsString('In 3 days', $html);
        $this->assertSame([], $m->sent, 'a preview that sends is not a preview');
        $this->assertSame(0, (int) DB::table('gates_broadcast_log')
            ->where('campaign', 'like', 'invite-remind:%')->count());
    }

    /**
     * Every reminder setting has a field that sets it.
     *
     * The sibling of "a declared field with no reader": a setting the code reads and no
     * screen writes is a value that can only be changed with a shell this host does not
     * have. Both halves of the pair have shipped here.
     */
    public function test_every_reminder_setting_has_a_field_that_sets_it(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $save = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');

        foreach ([
            'invite_reminder_enabled',
            'invite_reminder_days',
            'invite_reminder_time',
            'invite_reminder_line_nominee',
            'invite_reminder_line_judge',
        ] as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $form,
                $key . ' is read by the sweep but no field sets it');
            $this->assertStringContainsString($key, $save,
                $key . ' has a field but the save does not accept it');
        }
    }

    /** The sweep has a route in, on a host with no shell. */
    public function test_the_sweep_is_scheduled_and_addressable_by_name(): void
    {
        $m = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('InviteReminders::sweep()', $m,
            'a sweep with no caller is a feature that never runs');
        $this->assertStringContainsString("'invite-reminders' =>", $m,
            'and with no way to ask for it there is no shell to fall back on');
        $this->assertStringContainsString('InviteReminders::dueNow($now)', $m,
            'a time an operator sets and the scheduler ignores is a control that does nothing');
    }
}
