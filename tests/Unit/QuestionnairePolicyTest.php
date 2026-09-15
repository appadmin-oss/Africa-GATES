<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnairePolicy;
use AfricaGates\Services\QuestionnaireService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The questionnaire deadline, and the rule an organiser can attach to missing it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO THINGS THIS MUST NEVER DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. STOP ANYBODY ANSWERING. Settled policy on this platform: the questionnaire stays
 *    fillable past the deadline and past the close of voting, because a nominee who
 *    finally reaches a computer on the Tuesday has given the judges something and a form
 *    that locked them out has given the judges nothing. A deadline that quietly became a
 *    gate would be the regression nobody notices until a nominee emails to say the link
 *    is dead.
 *
 * 2. DISQUALIFY SOMEBODY UNANSWERABLY. The rule runs unattended. So it fires only where an
 *    organiser turned it on, only after the grace period, it records what it did, and it
 *    can be undone completely.
 */
final class QuestionnairePolicyTest extends TestCase
{
    private const CYCLE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insert([
            'id' => 1, 'slug' => 'gates', 'title' => 'Africa GATES', 'is_active' => 1, 'sort_order' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging',
            'voting_close' => '2026-06-01 23:59:00',
            'results_date' => '2026-07-15 12:00:00',
        ]);
    }

    private function submission(int $id, string $status = 'draft', array $over = []): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => 0, 'name' => 'Nominee ' . $id, 'status' => 'approved',
        ]);
        DB::table('gates_nominee_submissions')->insert($over + [
            'id' => $id, 'nominee_id' => $id, 'programme_id' => 1, 'cycle_id' => self::CYCLE,
            'status' => $status, 'invite_token' => str_repeat((string) $id, 32),
            // Warned by default, because that is the ordinary state by the time this rule
            // can act: {@see QuestionnaireReminders} runs in the same pass, ahead of it.
            // Pass `['reminded_at' => null]` for the nominee who never heard from us.
            'invited_at'  => '2026-05-01 09:00:00',
            'reminded_at' => '2026-05-18 09:00:00',
        ]);
    }

    // ══ the deadline ═════════════════════════════════════════════════════════

    /**
     * With nothing configured, the date still comes from the results date — that is what
     * deployments have been telling nominees, and this change must not silently stop
     * telling them anything.
     */
    public function test_an_unconfigured_cycle_keeps_the_derived_date_and_says_it_is_derived(): void
    {
        $p = QuestionnairePolicy::forCycle(self::CYCLE);

        $this->assertSame('derived', $p['source']);
        $this->assertStringStartsWith('2026-07-15', (string) $p['deadline_at']);
        $this->assertFalse($p['autodisqualify'], 'off is the default and must stay the default');
        $this->assertSame('15 July 2026', QuestionnairePolicy::humanFor(self::CYCLE));
    }

    /**
     * The reason an explicit deadline exists: with only the derived one, rescheduling the
     * results silently rewrote what every invitation already sent had meant.
     */
    public function test_an_explicit_deadline_does_not_move_when_the_results_are_rescheduled(): void
    {
        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-20 18:00'], 7);
        $this->assertSame('20 May 2026', QuestionnairePolicy::humanFor(self::CYCLE));

        DB::table('gates_award_cycles')->where('id', self::CYCLE)
            ->update(['results_date' => '2026-11-30 12:00:00']);

        $this->assertSame('20 May 2026', QuestionnairePolicy::humanFor(self::CYCLE),
            'the deadline followed the results date, which is the bug this replaced');
        $this->assertSame('set', QuestionnairePolicy::forCycle(self::CYCLE)['source']);
    }

    public function test_a_cycle_with_no_dates_at_all_reports_no_deadline_rather_than_guessing(): void
    {
        DB::table('gates_award_cycles')->where('id', self::CYCLE)
            ->update(['results_date' => null, 'voting_close' => null]);

        $p = QuestionnairePolicy::forCycle(self::CYCLE);

        $this->assertNull($p['deadline_at']);
        $this->assertSame('none', $p['source']);
        $this->assertSame('', QuestionnaireService::deadline(self::CYCLE),
            'the invitation must then not mention a deadline at all');
    }

    public function test_an_unreadable_date_is_refused_rather_than_stored_as_something_else(): void
    {
        $r = QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => 'next Whensday'], 7);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_questionnaire_policy')->count());
    }

    /** A rule that can never fire must not sit on the screen looking armed. */
    public function test_enforcement_cannot_be_switched_on_with_no_deadline_to_measure(): void
    {
        DB::table('gates_award_cycles')->where('id', self::CYCLE)
            ->update(['results_date' => null, 'voting_close' => null]);

        $r = QuestionnairePolicy::save(self::CYCLE, ['autodisqualify' => 1], 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Set a deadline', $r['message']);
    }

    public function test_saving_twice_updates_one_row(): void
    {
        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-20 18:00'], 7);
        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-25 18:00'], 7);

        $this->assertSame(1, DB::table('gates_questionnaire_policy')->count());
        $this->assertSame('25 May 2026', QuestionnairePolicy::humanFor(self::CYCLE));
    }

    // ══ enforcement ══════════════════════════════════════════════════════════

    public function test_nothing_is_enforced_while_the_rule_is_off(): void
    {
        $this->submission(1);
        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2020-01-01 00:00'], 7);

        $r = QuestionnairePolicy::enforce(self::CYCLE, false, 7);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['done']);
        $this->assertSame('draft', (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('status'));
    }

    /** The grace period is the point: the deadline and its enforcement are different days. */
    public function test_the_grace_period_holds_the_rule_back(): void
    {
        $this->submission(1);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'autodisqualify' => 1, 'grace_days' => 3,
        ], 7);

        $r = QuestionnairePolicy::enforce(self::CYCLE, false, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('has not passed yet', $r['message']);
        $this->assertSame('draft', (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('status'));
    }

    /** The dry run is not a courtesy — the screen shows who WOULD go before anything does. */
    public function test_a_dry_run_names_everybody_and_changes_nothing(): void
    {
        $this->submission(1);
        $this->submission(2, 'submitted');
        $this->submission(3, 'withdrawn');
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'autodisqualify' => 1, 'grace_days' => 3,
        ], 7);

        $r = QuestionnairePolicy::enforce(self::CYCLE, true);

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['would'], 'draft and withdrawn both mean "did not submit"');
        $this->assertSame(0, $r['done']);
        $this->assertSame(['Nominee 1', 'Nominee 3'], $r['names']);
        $this->assertSame(0, DB::table('gates_nominee_submissions')->where('status', 'disqualified')->count());
    }

    public function test_enforcement_disqualifies_the_right_people_and_records_why(): void
    {
        $this->submission(1);
        $this->submission(2, 'submitted');
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);

        $r = QuestionnairePolicy::enforce(self::CYCLE, false, 7);

        $this->assertSame(1, $r['done']);

        $row = DB::table('gates_nominee_submissions')->where('id', 1)->first();
        $this->assertSame('disqualified', (string) $row->status);
        $this->assertNotEmpty($row->autodisqualify_at, 'when it fired must be recorded');
        $this->assertStringContainsString('20 May 2026', (string) $row->disqualify_note,
            'the note must name the deadline that was missed, or nobody can answer "why is this nominee gone?"');

        $this->assertSame('submitted', (string) DB::table('gates_nominee_submissions')->where('id', 2)->value('status'),
            'somebody who answered must never be touched');
    }

    /** A test row has no nominee behind it, so disqualifying one disqualifies nobody. */
    public function test_a_rehearsal_row_is_never_disqualified(): void
    {
        $this->submission(1, 'draft', ['is_test' => 1]);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);

        $this->assertSame(0, QuestionnairePolicy::enforce(self::CYCLE, true)['would']);
    }

    /** Running it twice must not double-stamp or re-disqualify. */
    public function test_enforcement_is_idempotent(): void
    {
        $this->submission(1);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);

        $first = QuestionnairePolicy::enforce(self::CYCLE, false, 7);
        $stamp = (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('autodisqualify_at');

        $second = QuestionnairePolicy::enforce(self::CYCLE, false, 7);

        $this->assertSame(1, $first['done']);
        $this->assertSame(0, $second['done'], 'a disqualified row is no longer draft, so it is not picked up again');
        $this->assertSame($stamp, (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('autodisqualify_at'));
    }

    // ══ nobody is taken without a warning ════════════════════════════════════

    /**
     * THE ONE THAT MATTERS MOST IN THIS FILE.
     *
     * This rule runs unattended at 06:00 on a host with no shell, and it removes a person
     * from an award. Their only message had been the invitation — one email, months
     * earlier, to an address the NOMINATOR typed, which can be wrong, spam-filed, or sent
     * to a job they have since left. Taking a nomination from somebody who never knew
     * they had one is the most damaging thing this platform can do quietly.
     */
    public function test_a_nominee_who_was_never_warned_is_not_disqualified(): void
    {
        $this->submission(1, 'draft', ['reminded_at' => null]);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);

        $r = QuestionnairePolicy::enforce(self::CYCLE, false, 7);

        $this->assertSame(0, $r['done'], 'a nomination was taken from somebody who was never told');
        $this->assertSame('draft', (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('status'));
    }

    /** Held, not forgiven: the next sweep warns them and a later run takes them. */
    public function test_the_same_nominee_is_disqualified_once_the_warning_has_gone(): void
    {
        $this->submission(1, 'draft', ['reminded_at' => null]);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);
        $this->assertSame(0, QuestionnairePolicy::enforce(self::CYCLE, false, 7)['done']);

        DB::table('gates_nominee_submissions')->where('id', 1)
            ->update(['reminded_at' => '2026-05-18 09:00:00']);

        $this->assertSame(1, QuestionnairePolicy::enforce(self::CYCLE, false, 7)['done']);
    }

    /**
     * And the organiser is told, by name. A count that quietly shrank would read as the
     * rule being broken, and the screen gates its whole card on this list.
     */
    public function test_the_dry_run_names_who_is_being_held_back(): void
    {
        $this->submission(1, 'draft', ['reminded_at' => null]);
        $this->submission(2);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);

        $r = QuestionnairePolicy::enforce(self::CYCLE, true);

        $this->assertSame(['Nominee 2'], $r['names']);
        $this->assertSame(['Nominee 1'], $r['held']);
        $this->assertStringContainsString('held back until warned', $r['message']);
    }

    // ══ reversibility ════════════════════════════════════════════════════════

    public function test_reinstating_clears_the_disqualification_completely(): void
    {
        $this->submission(1);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);
        QuestionnairePolicy::enforce(self::CYCLE, false, 7);

        $r = QuestionnairePolicy::reinstate(1, 7);
        $this->assertTrue($r['ok']);

        $row = DB::table('gates_nominee_submissions')->where('id', 1)->first();
        $this->assertSame('draft', (string) $row->status,
            'back to draft, not submitted — they still have not answered, and now they can');
        $this->assertNull($row->autodisqualify_at);
        $this->assertNull($row->disqualify_note);
    }

    public function test_reinstating_somebody_who_is_not_disqualified_is_refused(): void
    {
        $this->submission(1, 'submitted');

        $this->assertFalse(QuestionnairePolicy::reinstate(1, 7)['ok']);
        $this->assertFalse(QuestionnairePolicy::reinstate(999, 7)['ok']);
        $this->assertSame('submitted', (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('status'));
    }

    // ══ THE DEADLINE IS NOT A GATE ═══════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS MOST. A deadline long past, enforcement on, and the nominee can
     * still open their page and save an answer.
     *
     * This is what makes the whole feature safe to ship: the deadline changes what the
     * invitation says and what {@see QuestionnairePolicy::enforce()} does, and nothing else.
     * If a future change ever wires it into the form, this test is what says so.
     */
    public function test_a_passed_deadline_does_not_stop_a_nominee_saving_their_answers(): void
    {
        $this->submission(1);
        // A real question, because `saveDraft` keeps only answers to defined ones — so a
        // test with no questions would store nothing and prove nothing.
        DB::table('gates_programme_questions')->insert([
            'programme_id' => 1, 'slug' => 'impact', 'kind' => 'textarea',
            'label' => 'What changed because of this work?', 'is_active' => 1, 'sort_order' => 1,
        ]);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2020-01-01 00:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 7);

        // Through the nominee's OWN entry point — the token — because that is the path a
        // deadline-turned-gate would sit on.
        $token = (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('invite_token');
        $r = QuestionnaireService::saveDraft($token, ['impact' => 'Answered long after the deadline'], []);

        $this->assertTrue((bool) ($r['ok'] ?? false), (string) ($r['message'] ?? 'the draft was refused'));
        $this->assertStringContainsString('long after the deadline',
            (string) DB::table('gates_nominee_submissions')->where('id', 1)->value('answers_json'));
    }

    public function test_only_armed_cycles_are_walked_by_the_cron(): void
    {
        DB::table('gates_award_cycles')->insert([
            'id' => 2, 'programme_id' => 1, 'year' => 2025, 'status' => 'archived',
            'results_date' => '2025-07-15 12:00:00',
        ]);

        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1], 7);
        QuestionnairePolicy::save(2, ['deadline_at' => '2025-05-20 18:00'], 7);

        $this->assertSame([self::CYCLE], QuestionnairePolicy::armedCycles());
    }

    // ══ what the screen reads ════════════════════════════════════════════════

    public function test_the_description_states_the_consequence_in_words(): void
    {
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 5,
        ], 7);

        $s = QuestionnairePolicy::describe(QuestionnairePolicy::forCycle(self::CYCLE));

        $this->assertStringContainsString('20 May 2026', $s);
        $this->assertStringContainsString('25 May 2026', $s, 'the enforcement date, not just the deadline');
        $this->assertStringContainsString('disqualified', $s);
    }

    public function test_the_description_says_plainly_when_nothing_is_enforced(): void
    {
        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-20 18:00'], 7);

        $this->assertStringContainsString('keeps their nomination',
            QuestionnairePolicy::describe(QuestionnairePolicy::forCycle(self::CYCLE)));
    }
}
