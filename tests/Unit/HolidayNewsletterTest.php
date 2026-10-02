<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Newsletter\HolidayCalendar;
use AfricaGates\Services\Newsletter\Newsletter;
use AfricaGates\Services\Newsletter\NewsletterSchedule;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Holiday issues: the right day in the right zone, the words computed where they carry a
 * fact, never a day late, and never two newsletters in two days.
 */
final class HolidayNewsletterTest extends TestCase
{
    private const SITE = 'https://africagates.test';

    protected function setUp(): void
    {
        parent::setUp();
        DisplayTime::forget();
        foreach (['gates_newsletter', 'gates_newsletter_issues', 'gates_broadcast_log', 'gates_email_optout',
                  'gates_mail_incidents', 'gates_mail_log', 'gates_mail_suppression'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('gates_settings')->whereIn('key_name', array_merge(NewsletterSchedule::KEYS,
            [HolidayCalendar::ENABLED_KEY, HolidayCalendar::dateKey('eid-al-fitr'), HolidayCalendar::dateKey('eid-al-adha')]))->delete();
        DB::table('gates_newsletter')->insert(['email' => 'reader@africagates.org',
            'email_hash' => EmailOptOut::hash('reader@africagates.org'), 'source' => 'homepage',
            'subscribed_at' => '2026-09-01 10:00:00', 'confirmed_at' => '2026-09-01 10:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mailer(): OtpService
    {
        return new class(['username' => 'u', 'password' => 'p']) extends OtpService {
            /** @var list<array<string,string>> */
            public array $raw = [];
            public function smtpConfigured(): bool { return true; }
            public function sendRawHtml(string $to, string $subject, string $html, string $plainBody = '',
                                        string $category = 'campaign', string $unsubscribeUrl = ''): array
            {
                $this->raw[] = compact('to', 'subject', 'html', 'plainBody');
                return ['success' => true];
            }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                return ['success' => true];
            }
        };
    }

    private function schedule(string $mode = 'auto', int $weekday = 4): NewsletterSchedule
    {
        return NewsletterSchedule::of(['newsletter_mode' => $mode, 'newsletter_cadence' => 'weekly',
                                       'newsletter_weekday' => $weekday, 'newsletter_hour' => 9]);
    }

    private function at(string $utc): Carbon
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        return Carbon::now();
    }

    // ══ the calendar ═════════════════════════════════════════════════════════

    public function test_easter_is_computed_without_the_calendar_extension(): void
    {
        foreach (['2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28', '2030-04-21', '2038-04-25'] as $d) {
            $this->assertSame($d, HolidayCalendar::easter((int) substr($d, 0, 4)));
        }
        if (function_exists('easter_days')) {
            for ($y = 1990; $y <= 2120; $y++) {
                $ref = (new \DateTimeImmutable("$y-03-21"))->modify('+' . easter_days($y) . ' days')->format('Y-m-d');
                $this->assertSame($ref, HolidayCalendar::easter($y), "Easter $y");
            }
        }
    }

    public function test_the_day_is_the_display_zones_day(): void
    {
        $cal = HolidayCalendar::load();
        // 23:30 UTC on 30 September is 00:30 on 1 October in Lagos.
        $this->assertSame('independence-day', $cal->today(Carbon::parse('2026-09-30 23:30:00', 'UTC'))['key'] ?? null);
        $this->assertNull($cal->today(Carbon::parse('2026-09-30 22:30:00', 'UTC')));
    }

    public function test_the_countrys_age_is_counted_not_typed(): void
    {
        $cal = HolidayCalendar::load();
        $this->assertStringContainsString('Nigeria is 66 today', $cal->describe('independence-day', 2026)['message']);
        $this->assertStringContainsString('Nigeria is 71 today', $cal->describe('independence-day', 2031)['message']);
    }

    public function test_an_eid_goes_only_once_its_declared_date_is_entered(): void
    {
        $this->assertNull(HolidayCalendar::load()->today(Carbon::parse('2027-03-10 10:00:00', 'UTC')),
            'a guessed date is a day wrong in some years, and a greeting the day before Eid reads as not knowing');
        HolidayCalendar::save(array_keys(HolidayCalendar::HOLIDAYS), ['eid-al-fitr' => '2027-03-10']);
        $this->assertSame('Eid Mubarak', HolidayCalendar::load()->today(Carbon::parse('2027-03-10 10:00:00', 'UTC'))['greeting'] ?? null);
    }

    public function test_a_computed_holiday_cannot_be_typed_onto_the_wrong_day(): void
    {
        HolidayCalendar::save(array_keys(HolidayCalendar::HOLIDAYS), ['christmas' => '2026-12-20', 'eid-al-adha' => '2027-13-45']);
        $cal = HolidayCalendar::load();
        $this->assertSame('2026-12-25', $cal->dateFor('christmas', 2026));
        $this->assertFalse(DB::table('gates_settings')->where('key_name', HolidayCalendar::dateKey('christmas'))->exists(),
            'a typed date for a computed holiday must not even be stored — the next reader may trust it');
        $this->assertNull($cal->dateFor('eid-al-adha', 2027), 'not a date');
    }

    public function test_a_holiday_switched_off_sends_nothing(): void
    {
        HolidayCalendar::save(['christmas'], []);
        $this->assertNull(HolidayCalendar::load()->today(Carbon::parse('2026-10-01 10:00:00', 'UTC')));
        $this->assertSame('christmas', HolidayCalendar::load()->today(Carbon::parse('2026-12-25 10:00:00', 'UTC'))['key'] ?? null);
    }

    /**
     * Every issue key fits the column. `h-independence-day-2026` is 23 characters and the
     * column was VARCHAR(20): MySQL refused it, the tick swallowed the refusal, and the
     * greeting never went — green on SQLite, which has no width.
     */
    public function test_every_holiday_issue_key_fits_its_column(): void
    {
        foreach (array_keys(HolidayCalendar::HOLIDAYS) as $key) {
            $this->assertLessThanOrEqual(Newsletter::PERIOD_KEY_MAX, strlen(Newsletter::holidayKey($key, 2099)), $key);
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            $len = (int) DB::selectOne("SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gates_newsletter_issues' AND COLUMN_NAME = 'period_key'")->n;
            $this->assertGreaterThanOrEqual(Newsletter::PERIOD_KEY_MAX, $len, 'the column is narrower than the keys written into it');
        }
    }

    public function test_no_greeting_mentions_money(): void
    {
        foreach (HolidayCalendar::HOLIDAYS as $key => $h) {
            $this->assertDoesNotMatchRegularExpression('~₦|NGN|\bwin\b|prize~i', $h['greeting'] . ' ' . $h['message'], $key);
        }
    }

    // ══ the issue ════════════════════════════════════════════════════════════

    public function test_the_greeting_goes_out_on_the_day_from_the_newsletter_hour(): void
    {
        $mail = $this->mailer();
        $n = new Newsletter($mail, self::SITE);

        // 07:30 in Lagos — before the hour.
        $n->tick($this->schedule(), $this->at('2026-10-01 06:30:00'));
        $this->assertSame(0, DB::table('gates_newsletter_issues')->count());

        // 09:30 in Lagos.
        $n->tick($this->schedule(), $this->at('2026-10-01 08:30:00'));
        $issue = DB::table('gates_newsletter_issues')->where('period_key', 'h-independence-day-2026')->first();
        $this->assertNotNull($issue);
        $this->assertSame('Happy Independence Day from Africa GATES', $issue->subject);
        $this->assertCount(1, $mail->raw);
        // The visible body, not the hidden preheader — which carries the same sentence when
        // there is no news, and would pass this on its own.
        $visible = (string) preg_replace('~<div style="display:none.*?</div>~s', '', $mail->raw[0]['html']);
        $this->assertStringContainsString('Nigeria is 66 today', $visible);
        $this->assertStringContainsString('Happy Independence Day', $mail->raw[0]['plainBody']);
    }

    public function test_a_greeting_is_sent_even_in_a_week_with_nothing_open(): void
    {
        $issue = Newsletter::compose('h-christmas-2026', $this->schedule(), $this->at('2026-12-25 09:00:00'),
            HolidayCalendar::load()->describe('christmas', 2026));
        $this->assertSame(Newsletter::ST_APPROVED, $issue->status);
        $this->assertStringContainsString('Wishing you joy and rest', (string) $issue->preheader);
    }

    public function test_in_review_mode_it_waits_and_says_it_must_be_approved_today(): void
    {
        (new Newsletter($this->mailer(), self::SITE))->tick($this->schedule('review'), $this->at('2026-12-25 09:30:00'));
        $issue = DB::table('gates_newsletter_issues')->where('period_key', 'h-christmas-2026')->first();
        $this->assertSame(Newsletter::ST_DRAFT, $issue->status);
        $this->assertStringContainsString('approve it today', (string) $issue->note);
    }

    public function test_a_greeting_is_never_a_day_late(): void
    {
        // Every tick on the 1st missed (the cron was down). On the 2nd, nothing.
        (new Newsletter($this->mailer(), self::SITE))->tick($this->schedule(), $this->at('2026-10-02 08:30:00'));
        $this->assertSame(0, DB::table('gates_newsletter_issues')->where('period_key', 'like', 'h-%')->count());
    }

    public function test_the_regular_issue_steps_aside_for_two_days(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'live', 'title' => 'Live Awards', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['programme_id' => $pid, 'year' => 2026, 'status' => 'voting',
            'voting_open' => '2026-09-20 00:00:00', 'voting_close' => '2026-10-20 22:00:00']);
        $mail = $this->mailer();
        $n = new Newsletter($mail, self::SITE);

        // Independence Day 2026 is a Thursday, the weekly slot's own day.
        $n->tick($this->schedule(), $this->at('2026-10-01 08:30:00'));
        $this->assertSame(Newsletter::ST_SKIPPED,
            DB::table('gates_newsletter_issues')->where('period_key', 'w2026-40')->value('status'),
            'the greeting and the weekly issue on one morning is two of the same email');
        $this->assertCount(1, $mail->raw);

        // A Friday weekly slot, the next day: still inside the spacing.
        $friday = $this->schedule('auto', 5);
        $n->tick($friday, $this->at('2026-10-02 08:30:00'));
        $this->assertSame(Newsletter::ST_SKIPPED,
            DB::table('gates_newsletter_issues')->where('period_key', 'w2026-40')->value('status'));
    }

    public function test_the_public_page_names_the_holidays_from_the_calendar(): void
    {
        NewsletterSchedule::save(['newsletter_mode' => 'auto']);
        HolidayCalendar::save(['christmas', 'new-year'], []);
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', self::SITE . '/newsletter');
        $html = (string) \Tests\Support\TestApp::build()->handle($req)->getBody();
        $this->assertStringContainsString('And a greeting on New Year’s Day and Christmas Day.', $html);
    }
}
