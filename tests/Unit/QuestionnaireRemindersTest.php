<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OtpService;
use AfricaGates\Services\QuestionnairePolicy;
use AfricaGates\Services\QuestionnaireReminders as R;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The warning that goes out before a nominee loses their place.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see QuestionnairePolicy::enforce()} removes a nominee from an award for not answering,
 * unattended, out of the 06:00 maintenance run on a host with no shell. The only message
 * they had ever had was the invitation: one email, months earlier, to an address the
 * NOMINATOR typed.
 *
 * `gates_nominee_submissions.reminded_at` has been in the schema since the questionnaire
 * migration and nothing wrote it, nothing read it. Here the missing reader was not a
 * dormant feature — it was the reason the warning did not exist, and the reason the rule
 * could not tell somebody who ignored us from somebody who never heard from us.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE HOLD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One warning per mark and never a daily drip; nothing sent to somebody who has not even
 * been invited yet, or who already answered; the consequence named only where it is real;
 * and the stamp written, because the guard in enforce() reads it and a warning nobody
 * recorded is a warning that did not happen.
 */
final class QuestionnaireRemindersTest extends TestCase
{
    private const CYCLE = 1;

    /** @var list<array{to:string, subject:string, body:string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        R::forget();

        DB::table('gates_award_programmes')->insert([
            'id' => 1, 'slug' => 'gates', 'title' => 'Africa GATES', 'is_active' => 1, 'sort_order' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insert([
            'id' => 1, 'cycle_id' => self::CYCLE, 'slug' => 'c', 'title' => 'C', 'sort_order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        R::forget();
        parent::tearDown();
    }

    /** A nominee with an approved nomination, so an address can be resolved from it. */
    private function submission(int $id, array $over = []): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => 1, 'name' => 'Nominee ' . $id, 'status' => 'approved',
        ]);
        DB::table('gates_nominations')->insert([
            'id' => $id, 'cycle_id' => self::CYCLE, 'category_id' => 1,
            'nominee_name' => 'Nominee ' . $id, 'nominee_email' => 'n' . $id . '@example.org',
            'nominator_name' => 'K', 'nominator_email' => 'k@example.org', 'status' => 'approved',
        ]);
        DB::table('gates_nominee_submissions')->insert($over + [
            'id' => $id, 'nominee_id' => $id, 'programme_id' => 1, 'cycle_id' => self::CYCLE,
            'status' => 'draft', 'invite_token' => str_repeat((string) $id, 32),
            'invited_at' => '2026-05-01 09:00:00',
        ]);
    }

    /** @param int|string $deadline days from now, or an explicit datetime */
    private function policy(int|string $deadline, bool $armed = true, int $grace = 0): void
    {
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => is_int($deadline)
                ? Carbon::now()->addDays($deadline)->toDateTimeString()
                : $deadline,
            'autodisqualify' => $armed ? 1 : 0,
            'grace_days'     => $grace,
        ], 7);
    }

    private function mailer(): OtpService
    {
        return new class($this->sent) extends OtpService {
            /** @param list<array<string,string>> $sink */
            public function __construct(private array &$sink) { parent::__construct([]); }

            public function sendCustom(string $to, string $subject, string $body): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return ['success' => true];
            }
        };
    }

    private function stamp(int $id): string
    {
        return trim((string) DB::table('gates_nominee_submissions')->where('id', $id)->value('reminded_at'));
    }

    // ══ the schedule ═════════════════════════════════════════════════════════

    /**
     * The mark in play is the smallest one still ahead of the day.
     *
     * With [14, 5, 1] and nine days left, the fourteen-day window has opened and the
     * five-day one has not. Picking the largest PASSED mark instead would re-open a window
     * already served and send the same person the same message twice.
     */
    public function test_the_window_in_play_is_the_next_one_not_the_last_one(): void
    {
        $m = [14, 5, 1];

        $this->assertSame(14, R::dueMark(14, $m));
        $this->assertSame(14, R::dueMark(9, $m), 'the five-day warning went out nine days early');
        $this->assertSame(5,  R::dueMark(5, $m));
        $this->assertSame(5,  R::dueMark(2, $m));
        $this->assertSame(1,  R::dueMark(1, $m));
        $this->assertSame(1,  R::dueMark(0, $m), 'the last day still gets its warning');
    }

    /** Nothing is due while the first mark is still ahead. */
    public function test_nothing_is_due_before_the_first_mark(): void
    {
        $this->assertNull(R::dueMark(30, [14, 5, 1]));
    }

    /**
     * A deadline is a date to the person reading it. At 23:00 the night before, the honest
     * answer is "tomorrow" and "in 0 days" is not.
     */
    public function test_the_countdown_is_measured_in_days_not_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-19 23:00:00'));

        $this->assertSame(1, R::daysUntil('2026-05-20 00:30:00'));
    }

    // ══ the sweep ════════════════════════════════════════════════════════════

    public function test_a_silent_nominee_is_warned_and_the_warning_is_recorded(): void
    {
        $this->submission(1);
        $this->policy(4);

        $this->assertSame(1, R::sweep($this->mailer()));

        $this->assertSame('n1@example.org', $this->sent[0]['to']);
        $this->assertNotSame('', $this->stamp(1),
            'the warning went out and nothing recorded it — enforce() reads this column');
    }

    /** THE ONE THAT MATTERS. One warning per mark, not one a day. */
    public function test_the_same_warning_is_not_sent_twice(): void
    {
        $this->submission(1);
        $this->policy(4);

        $this->assertSame(1, R::sweep($this->mailer()));
        $this->assertSame(0, R::sweep($this->mailer()),
            'a nominee was sent the same distressing message twice in one window');
        $this->assertCount(1, $this->sent);
    }

    /** But a later mark is a new warning, and it does go. */
    public function test_a_later_mark_warns_again(): void
    {
        $this->submission(1);
        $this->policy(4);
        R::sweep($this->mailer());

        // Move the clock inside the one-day window.
        Carbon::setTestNow(Carbon::now()->addDays(4));

        $this->assertSame(1, R::sweep($this->mailer()),
            'the last warning before the deadline never went');
        $this->assertCount(2, $this->sent);
    }

    public function test_somebody_who_answered_is_never_warned(): void
    {
        $this->submission(1, ['status' => 'submitted']);
        $this->policy(4);

        $this->assertSame(0, R::sweep($this->mailer()));
    }

    /**
     * Not-yet-invited is not silence. Warning somebody about a deadline for a
     * questionnaire they have not been asked to fill in is how a platform teaches people
     * to ignore its mail.
     */
    public function test_somebody_who_was_never_invited_is_not_warned(): void
    {
        $this->submission(1, ['invited_at' => null]);
        $this->policy(4);

        $this->assertSame(0, R::sweep($this->mailer()));
    }

    /** A rehearsal row has nobody behind it. */
    public function test_a_test_row_is_never_warned(): void
    {
        $this->submission(1, ['is_test' => 1]);
        $this->policy(4);

        $this->assertSame(0, R::sweep($this->mailer()));
    }

    /** Past the enforcement moment the window is shut; enforce() has its own guard. */
    public function test_nothing_is_sent_once_the_deadline_is_behind_us(): void
    {
        $this->submission(1);
        $this->policy(-5);

        $this->assertSame(0, R::sweep($this->mailer()));
    }

    /** The grace period is part of the date the warning counts down to. */
    public function test_the_countdown_runs_to_the_deadline_plus_its_grace(): void
    {
        $this->submission(1);
        // Deadline two days out with three days' grace: five days until it bites, so the
        // 14-day window is the one in play and the 5-day one opens today.
        $this->policy(2, true, 3);

        $this->assertSame(1, R::sweep($this->mailer()));
        $this->assertStringContainsString("3 days' grace", $this->sent[0]['body'],
            'the message counted to a date the rule does not act on');
    }

    // ══ what it says ═════════════════════════════════════════════════════════

    /** Where the consequence is real, it is named plainly rather than implied by tone. */
    public function test_an_armed_cycle_says_what_will_happen(): void
    {
        $this->submission(1);
        $this->policy(4, true);
        R::sweep($this->mailer());

        $this->assertStringContainsString('the nomination is closed', $this->sent[0]['body']);
    }

    /** And where it is not, no consequence is invented. */
    public function test_an_unarmed_cycle_does_not_threaten_one(): void
    {
        $this->submission(1);
        $this->policy(4, false);
        R::sweep($this->mailer());

        $this->assertCount(1, $this->sent, 'the deadline is real either way, so the reminder still goes');
        $this->assertStringNotContainsString('the nomination is closed', $this->sent[0]['body'],
            'a nominee was threatened with a removal nobody configured');
    }

    /**
     * This is the message most likely to be the FIRST one a nominee actually reads, so it
     * carries the two sentences that protect them: nothing costs money, and an honest
     * answer about what has not worked has never cost anybody an award.
     */
    public function test_the_warning_repeats_the_two_sentences_that_protect_the_nominee(): void
    {
        $this->submission(1);
        $this->policy(4);
        R::sweep($this->mailer());

        $body = $this->sent[0]['body'];
        $this->assertStringContainsString('never ask you to pay', $body);
        $this->assertStringContainsString('has NOT worked has never cost anybody an award', $body);
        $this->assertStringContainsString('/my-work/' . str_repeat('1', 32), $body,
            'the link is the point of the message — the commonest reason a questionnaire is
             unanswered is that the first email was never found');
    }

    /** And a way out that is not silence, because silence is what this rule punishes. */
    public function test_it_offers_a_way_to_decline(): void
    {
        $this->submission(1);
        $this->policy(4);
        R::sweep($this->mailer());

        $this->assertStringContainsString('would rather not take part', $this->sent[0]['body']);
    }

    /** The countdown leads the subject, because a phone truncates at about forty characters. */
    public function test_the_subject_leads_with_the_countdown(): void
    {
        $this->assertStringStartsWith('Tomorrow', R::subject(1));
        $this->assertStringStartsWith('4 days left', R::subject(4));
        $this->assertStringContainsString('Last day', R::subject(0));
    }

    // ══ the cap ══════════════════════════════════════════════════════════════

    /** A shared host's max_execution_time is the real ceiling on an unattended run. */
    public function test_the_sweep_is_capped_per_tick(): void
    {
        for ($i = 1; $i <= 5; $i++) $this->submission($i);
        $this->policy(4);

        $this->assertSame(3, R::sweep($this->mailer(), 3));
        $this->assertCount(3, $this->sent);
        // And the rest go on the next tick rather than being lost.
        $this->assertSame(2, R::sweep($this->mailer(), 3));
    }

    // ══ the routes in ════════════════════════════════════════════════════════

    /**
     * §18: a mechanism with no route in. A sweep nothing calls is a feature that never
     * runs, and on a host with no shell there is nothing to fall back on.
     *
     * The ORDER is asserted too, and it is not tidiness. enforce() holds back anybody with
     * no `reminded_at`, so warning first means a nominee warned this morning is taken by a
     * LATER run. Warning second would delay every first warning by a day for nothing.
     */
    public function test_the_sweep_runs_before_the_rule_it_protects_people_from(): void
    {
        $m = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('QuestionnaireReminders::sweep()', $m,
            'a sweep with no caller is a feature that never runs');
        $this->assertStringContainsString("'qremind'", $m,
            'and with no way to ask for it there is no shell to fall back on');

        $warn = strpos($m, "\$ran[] = ['qremind'");
        $take = strpos($m, "\$ran[] = ['qdisqualify'");
        $this->assertIsInt($warn);
        $this->assertIsInt($take);
        $this->assertLessThan($take, $warn,
            'the daily run disqualifies before it warns, so every first warning costs a day');
    }

    /**
     * The sibling of "a declared field with no reader": a setting the code reads and no
     * screen writes is a value that can only be changed with a shell this host does not
     * have.
     */
    public function test_the_schedule_has_a_field_that_sets_it(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $save = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');

        $this->assertStringContainsString('name="questionnaire_reminder_days"', $form,
            'the sweep reads this and no field sets it');
        $this->assertStringContainsString('questionnaire_reminder_days', $save,
            'there is a field but the save does not accept it');
    }

    /** And a typed list is honoured, clamped, and never trusted raw. */
    public function test_a_typed_schedule_is_used_and_junk_falls_back_to_the_default(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'questionnaire_reminder_days', 'value' => '5, 30, 1']);
        R::forget();
        $this->assertSame([30, 5, 1], R::marks(), 'largest first, as the screen reads them');

        DB::table('gates_settings')->where('key_name', 'questionnaire_reminder_days')
            ->update(['value' => 'soon-ish']);
        R::forget();
        $this->assertSame(R::DEFAULT_MARKS, R::marks());
    }

    /**
     * Capped at four, and the four NEAREST the deadline. Keeping the largest instead would
     * drop the last warning off a long list — the one that matters most to somebody about
     * to lose their place.
     */
    public function test_a_long_list_keeps_the_warnings_nearest_the_deadline(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => 'questionnaire_reminder_days', 'value' => '90, 60, 30, 14, 7, 2, 1',
        ]);
        R::forget();

        $this->assertSame([14, 7, 2, 1], R::marks());
    }
}
