<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\InterviewBrief;
use AfricaGates\Services\InterviewReview;
use AfricaGates\Services\InterviewService;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The judging interview, end to end.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE ACTUALLY DEFENDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_nominee_interviews` shipped in 2026_08_15 with a reader, a judge-facing renderer,
 * and no writer of any kind — so every dossier ever shown to a panel said "no interview on
 * file" and always would have. The interview flow is the door into that room, and the tests
 * that matter are the ones about what may NOT come through it:
 *
 *   1. NOTHING REACHES THE PANEL WITHOUT THE NOMINEE'S OWN CONSENT. Enforced in
 *      publish(), not in a template and not in a convention.
 *   2. NO SCORE IS EVER WRITTEN. Not by the brief, not by the review, not as a
 *      "suggestion" — and a model that returns one anyway loses its whole answer.
 *   3. THE QUESTION PACK WORKS WITH NO AI PROVIDER. The platform has already shipped a
 *      support desk that could do nothing without an API key; a panel opening the console
 *      on the morning of a sitting must not find an empty page.
 *   4. POPULARITY NEVER ENTERS. The dossier the brief reads is filtered by
 *      EvidenceService, so vote counts cannot shape the questions and carry the
 *      community's 45% into the panel's 55%.
 */
final class InterviewFlowTest extends TestCase
{
    private const PROG = 9100;
    private const CYCLE = 9100;
    private const CAT = 9100;
    private const NOM = 9101;
    private const JUDGE = 9102;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'Programme', 'slug' => 'prog-9100',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => self::CYCLE, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => self::CYCLE, 'title' => 'Teaching', 'slug' => 'cat-9100',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada Obi',
            'organisation' => 'Hope Academy', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 4200, 'organic_vote_count' => 900,
            'story' => 'She started a reading club that now reaches 500 pupils across three states.',
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => self::CYCLE, 'category_id' => self::CAT,
            'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada@example.org',
            'nominee_phone' => '08031234567', 'country_code' => 'NG',
            'reason' => 'Ada has taught for 12 years. Her reading club reaches 500 pupils across '
                      . 'three states and she funds the books from her own salary.',
            'nominator_name' => 'Ngozi', 'nominator_email' => 'ngozi@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9101',
        ]);
        DB::table('gates_judges')->insertOrIgnore([
            'id' => self::JUDGE, 'name' => 'Dr Femi', 'email' => 'femi@example.org',
            'is_active' => 1, 'programme_ids' => json_encode([self::PROG]),
        ]);
        // The rubric. Base schemas seed it only in the dev installer, so the tests carry
        // their own — and the weights matter: the brief prints them to the panel.
        foreach ([
            ['impact', 'Impact', 'Measurable difference made.', 25, 1],
            ['originality', 'Originality', 'Inventiveness of approach.', 25, 2],
            ['reach', 'Reach', 'Breadth of influence.', 25, 3],
            ['integrity', 'Integrity', 'Consistency of values.', 25, 4],
        ] as $i => [$slug, $label, $desc, $w, $sort]) {
            DB::table('gates_judge_criteria')->insertOrIgnore([
                'id' => 9110 + $i, 'programme_id' => null, 'slug' => $slug, 'label' => $label,
                'description' => $desc, 'weight' => $w, 'sort_order' => $sort, 'is_active' => 1,
            ]);
        }
    }

    private function open(array $opts = []): int
    {
        $r = InterviewService::create(self::NOM, $opts + [
            'scheduled_at'  => Carbon::now()->addDays(3)->toDateTimeString(),
            'timezone'      => 'Africa/Lagos',
            'meet_url'      => 'https://meet.google.com/abc-defg-hij',
            'panel'         => [self::JUDGE],
        ]);
        $this->assertTrue($r['ok'], (string) $r['message']);
        return (int) $r['id'];
    }

    // ══ 1. the appointment ═══════════════════════════════════════════════════

    public function test_it_opens_a_sitting_with_a_token_and_a_meet_code(): void
    {
        $id  = $this->open();
        $row = InterviewService::byId($id);

        $this->assertNotNull($row);
        $this->assertSame('draft', $row->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row->invite_token);
        $this->assertSame('abc-defg-hij', (string) $row->meet_code,
            'the bare conference code is what matches a transcript back to the sitting');
        $this->assertSame(self::CYCLE, (int) $row->cycle_id);
    }

    public function test_a_second_open_sitting_for_one_nominee_is_refused(): void
    {
        $first = $this->open();
        $r = InterviewService::create(self::NOM, ['scheduled_at' => Carbon::now()->addDays(5)->toDateTimeString()]);

        $this->assertFalse($r['ok']);
        $this->assertSame($first, (int) $r['id'], 'it names the existing one rather than just refusing');
        $this->assertStringContainsString('already has an interview', (string) $r['message']);
    }

    /**
     * A time typed by an operator in Lagos means their afternoon, and is stored as UTC.
     *
     * Storing what they typed and hoping every reader shares their clock is how a judge in
     * London joins an empty room an hour early.
     */
    public function test_the_time_is_stored_as_utc_and_shown_in_the_sittings_zone(): void
    {
        $this->assertSame('2026-09-15 13:30:00',
            InterviewService::normaliseWhen('2026-09-15 14:30', 'Africa/Lagos'), 'Lagos is UTC+1');

        // What the admin form actually sends: a bare local time, plus the zone.
        $id = $this->open(['scheduled_at' => '2026-09-15T14:30', 'timezone' => 'Africa/Lagos']);
        $this->assertSame('2026-09-15 13:30:00', (string) InterviewService::byId($id)->scheduled_at);

        $shown = InterviewService::whenText(InterviewService::byId($id));
        $this->assertStringContainsString('14:30', $shown, 'the operator must see the time they typed');
        $this->assertStringContainsString('Africa/Lagos', $shown, 'and never a naked timestamp');
    }

    /**
     * The bug this guards: the controller converted the operator's local time to UTC and
     * handed the result to create(), which converted it AGAIN — so every sitting was
     * stored an hour early in Lagos, on the invitation, the reminders, the calendar event
     * and the nominee's own page. Nothing looked broken; everybody just arrived late.
     */
    public function test_a_time_that_already_carries_an_offset_is_not_converted_twice(): void
    {
        $this->assertSame('2026-09-15 13:30:00',
            InterviewService::normaliseWhen('2026-09-15T13:30:00Z', 'Africa/Lagos'));
        $this->assertSame('2026-09-15 13:30:00',
            InterviewService::normaliseWhen('2026-09-15T14:30:00+01:00', 'Africa/Lagos'));
    }

    /** Editing the duration must not walk a Nairobi sitting an hour forward each time. */
    public function test_rescheduling_reads_the_time_in_the_sittings_own_zone(): void
    {
        $id = $this->open(['timezone' => 'Africa/Nairobi']);   // UTC+3

        InterviewService::reschedule($id, '2026-10-01 09:00');
        $this->assertSame('2026-10-01 06:00:00', (string) InterviewService::byId($id)->scheduled_at);

        // And again, with no zone passed — it must still read Nairobi, not a server default.
        InterviewService::reschedule($id, '2026-10-01 09:00', 45);
        $this->assertSame('2026-10-01 06:00:00', (string) InterviewService::byId($id)->scheduled_at);
        $this->assertSame(45, (int) InterviewService::byId($id)->duration_mins);
    }

    public function test_moving_a_confirmed_sitting_asks_again_but_keeps_consent(): void
    {
        $id    = $this->open();
        $token = (string) InterviewService::tokenFor($id);
        InterviewService::confirm($token, 'Ada Obi', true, '10.0.0.1');

        $before = InterviewService::byId($id);
        $this->assertNotEmpty($before->confirmed_at);
        $this->assertNotEmpty($before->consent_at);

        InterviewService::reschedule($id, Carbon::now()->addDays(9)->toDateTimeString());
        $after = InterviewService::byId($id);

        $this->assertSame('invited', $after->status, 'their yes was for a time that no longer exists');
        $this->assertEmpty($after->confirmed_at);
        $this->assertNotEmpty($after->consent_at,
            'consent was given for being RECORDED, which a new time does not change');
    }

    public function test_a_meet_code_is_read_out_of_any_shape_of_link(): void
    {
        $this->assertSame('abc-defg-hij', InterviewService::meetCode('https://meet.google.com/abc-defg-hij'));
        $this->assertSame('abc-defg-hij', InterviewService::meetCode('https://meet.google.com/abc-defg-hij?authuser=1'));
        $this->assertSame('abc-defg-hij', InterviewService::meetCode('meet.google.com/lookup/abc-defg-hij'));
        $this->assertSame('abc-defg-hij', InterviewService::meetCode(' ABC-DEFG-HIJ '));
        $this->assertSame('', InterviewService::meetCode('https://zoom.us/j/12345'));
    }

    // ══ 2. consent ═══════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS MOST.
     *
     * A transcript is the nominee's recorded voice, machine-written, read by the people
     * deciding their award. Publishing it without their own permission is the single worst
     * thing this feature could do, and the gate is in publish() so no screen, importer or
     * future caller can route around it.
     */
    public function test_a_transcript_cannot_reach_the_panel_without_consent(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', false, '10.0.0.1');

        $r = InterviewService::publish($id, str_repeat('She said something substantive. ', 20));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not given permission', (string) $r['message']);
        $this->assertSame(0, DB::table('gates_nominee_interviews')->where('nominee_id', self::NOM)->count(),
            'nothing at all was written');
        $this->assertNull(InterviewService::byId($id)->transcript_id);
    }

    public function test_with_consent_it_lands_in_the_dossier_the_judges_read(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '10.0.0.1');

        $text = 'Interviewer: Tell us about the reading club. Ada: We began with fifty pupils in '
              . 'one school and now it runs in 12 schools. I buy the books myself.';
        $r = InterviewService::publish($id, $text, ['source' => 'machine']);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $row = DB::table('gates_nominee_interviews')->where('id', (int) $r['transcript_id'])->first();
        $this->assertSame('published', $row->status);
        $this->assertSame(1, (int) $row->consent_given);
        $this->assertSame('machine', $row->transcript_source,
            'a judge is entitled to know a model wrote this down');
        $this->assertStringContainsString('Ada Obi', (string) $row->consent_note);

        // And it is now visible through the same reader the judge ballot uses.
        $dossier = (new \AfricaGates\Services\EvidenceService())->forJudge(self::NOM);
        $this->assertCount(1, $dossier['interviews']);
        $this->assertTrue($dossier['coverage']['has_interview'],
            'the dossier stops saying "no interview on file" — the whole point of the feature');
    }

    /**
     * The admin form posts `interviewer` and `language` as EMPTY STRINGS when left blank, and
     * `??` only falls back on null. So the panel's names never reached the column and every
     * judge saw the ballot's generic "Programme team" instead of who conducted the interview.
     */
    public function test_blank_form_fields_fall_back_instead_of_storing_nothing(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');

        $r = InterviewService::publish($id, str_repeat('A substantial transcript of the talk. ', 8), [
            'source' => 'machine', 'interviewer' => '', 'language' => '', 'transcriber' => '',
        ]);

        $row = DB::table('gates_nominee_interviews')->where('id', (int) $r['transcript_id'])->first();
        $this->assertSame('Dr Femi', (string) $row->interviewer, 'the panel is named');
        $this->assertSame('en', (string) $row->language);
        $this->assertSame('Google Meet transcription', (string) $row->transcriber);
    }

    public function test_republishing_replaces_rather_than_showing_the_panel_two_versions(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        InterviewService::publish($id, str_repeat('First version of the transcript. ', 8));
        InterviewService::publish($id, str_repeat('Corrected version of the transcript. ', 8));

        $this->assertSame(1, DB::table('gates_nominee_interviews')->where('nominee_id', self::NOM)->count());
        $this->assertStringContainsString('Corrected', InterviewService::transcriptOf($id));
    }

    public function test_withdrawing_hides_it_from_judges_but_keeps_the_record(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        InterviewService::publish($id, str_repeat('The transcript of the conversation. ', 8));

        InterviewService::withdraw($id, 'she asked us to');

        $this->assertSame(1, DB::table('gates_nominee_interviews')->where('nominee_id', self::NOM)->count(),
            'the row survives — an award result may be questioned years later');
        $this->assertCount(0, (new \AfricaGates\Services\EvidenceService())->forJudge(self::NOM)['interviews'],
            'but no judge can see it');
    }

    public function test_a_scrap_of_text_is_not_accepted_as_a_transcript(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');

        $r = InterviewService::publish($id, 'She was good.');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('too short', (string) $r['message']);
    }

    // ══ 3. the question pack ═════════════════════════════════════════════════

    /**
     * The rules path is the real path. With no AI provider configured — which is the state
     * of this deployment — the pack must still be usable, and the support desk that could
     * do nothing without an API key is the precedent nobody wants repeated.
     */
    public function test_the_pack_is_built_with_no_ai_provider_at_all(): void
    {
        $id = $this->open();
        $r  = InterviewBrief::build($id);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertSame('rules', $r['source']);
        $this->assertGreaterThanOrEqual(4, $r['questions']);

        $pack = InterviewBrief::forInterview($id);
        $labels = array_column($pack['questions'], 'criterion');
        foreach (['Impact', 'Originality', 'Reach', 'Integrity'] as $c) {
            $this->assertContains($c, $labels, 'every criterion gets at least one question, or it '
                . 'is scored out of ten on nothing');
        }
    }

    /**
     * The most valuable question in the pack, and it needs no intelligence: quote the
     * figure from the record back at the person who supposedly produced it.
     */
    public function test_a_figure_in_the_nomination_becomes_a_question_quoting_it(): void
    {
        $id = $this->open();
        InterviewBrief::build($id);

        $claims = array_filter(InterviewBrief::forInterview($id)['questions'],
            fn (array $q): bool => ($q['source'] ?? '') === 'claim');
        $this->assertNotEmpty($claims, 'no claim question was generated from a nomination full of figures');

        $joined = implode(' ', array_column($claims, 'q'));
        $this->assertStringContainsString('500 pupils', $joined);
        $this->assertStringContainsString('who else could confirm it', $joined);
    }

    /**
     * Popularity must not reach the model or the panel through this door.
     *
     * The ballot's per-judge shuffle exists because ORDERING alone was judged too strong an
     * anchor. Questions shaped by a vote count would be far worse and would look like
     * independent expert enquiry.
     */
    public function test_no_vote_count_appears_anywhere_in_the_pack(): void
    {
        $id = $this->open();
        InterviewBrief::build($id);

        $json = json_encode(InterviewBrief::forInterview($id));
        $this->assertStringNotContainsString('4200', (string) $json);
        $this->assertStringNotContainsString('vote_count', (string) $json);
        $this->assertStringNotContainsString('900', (string) $json);
    }

    public function test_the_nominee_is_told_the_themes_and_never_the_questions(): void
    {
        $id = $this->open();
        InterviewBrief::build($id);

        $themes = InterviewBrief::themes($id);
        $this->assertContains('Impact', $themes);

        // A theme is a rubric label, not a sentence. If the questions ever leaked into
        // this list, one of them would be long.
        foreach ($themes as $t) {
            $this->assertLessThan(40, mb_strlen($t), 'that looks like a question, not a theme');
        }
    }

    public function test_the_pack_admits_when_there_is_nothing_to_build_from(): void
    {
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 9199, 'category_id' => self::CAT, 'name' => 'Nobody Known', 'status' => 'approved',
        ]);
        $r = InterviewService::create(9199, ['scheduled_at' => Carbon::now()->addDay()->toDateTimeString()]);
        InterviewBrief::build((int) $r['id']);

        $pack = InterviewBrief::forInterview((int) $r['id']);
        $this->assertNotEmpty($pack['warnings'], 'a panel is entitled to know the file was empty');
        $this->assertStringContainsString('checkable figure', implode(' ', $pack['warnings']));
    }

    // ══ 4. the console ═══════════════════════════════════════════════════════

    public function test_answering_the_same_question_twice_replaces_rather_than_appends(): void
    {
        $id = $this->open();
        InterviewService::recordAnswer($id, 'crit-impact', 'Q?', 'first note', 9110);
        InterviewService::recordAnswer($id, 'crit-impact', 'Q?', 'corrected note', 9110, 'good');

        $answers = json_decode((string) InterviewService::byId($id)->answers_json, true);
        $this->assertCount(1, $answers);
        $this->assertSame('corrected note', $answers[0]['note']);
        $this->assertSame('good', $answers[0]['flag']);
        $this->assertArrayHasKey('first_at', $answers[0], 'when it was first answered survives');
    }

    public function test_a_nonsense_flag_is_dropped_rather_than_stored(): void
    {
        $id = $this->open();
        InterviewService::recordAnswer($id, 'k', 'Q?', 'note', null, 'brilliant');
        $answers = json_decode((string) InterviewService::byId($id)->answers_json, true);
        $this->assertSame('', $answers[0]['flag']);
    }

    // ══ 5. reading the transcript ════════════════════════════════════════════

    /**
     * The highest-value check in the whole feature, and it is a string comparison: the
     * nomination claims 500, the nominee says 12. Raised as a DISCREPANCY, never as
     * dishonesty — an inflated nomination, a careful nominee and a mishearing machine
     * transcriber all produce the same signal.
     */
    public function test_a_figure_that_moved_between_the_file_and_the_interview_is_flagged(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        InterviewService::publish($id,
            'Interviewer: how many pupils? Ada: it is about 60 pupils now, in 12 schools. '
            . 'We started with 20 in one classroom and I keep the register myself every term.',
            ['source' => 'machine']);

        InterviewReview::run($id);
        $review = InterviewReview::forInterview($id);

        $statuses = array_column($review['figures'], 'status');
        $this->assertContains('differs', $statuses,
            '500 in the nomination against 60 in the interview must not pass unremarked');

        $differs = array_values(array_filter($review['figures'], fn ($f) => $f['status'] === 'differs'))[0];
        $this->assertStringContainsString('check the recording', $differs['note'],
            'a machine transcript mishears numbers, so the note must say so');
        $this->assertStringNotContainsStringIgnoringCase('lie', $differs['note']);
        $this->assertStringNotContainsStringIgnoringCase('dishonest', $differs['note']);
    }

    /**
     * Three ways the figure check manufactured findings out of nothing, all found by running
     * it against a real transcript rather than reading the code:
     *
     *   • "she has taught for 11 years" was compared against "30 pupils" and reported as a
     *     figure that had changed — two unrelated numbers, one invented discrepancy.
     *   • "we started in 2019" was offered as the closest figure to a claim of 640 pupils,
     *     because 2019/640 passes an order-of-magnitude test.
     *   • "640" restated as "about 600" was reported as a discrepancy, which trains an
     *     operator to ignore the whole list.
     */
    public function test_the_figure_check_does_not_invent_findings(): void
    {
        DB::table('gates_nominations')->where('id', self::NOM)->update([
            'reason' => 'She has taught for 11 years. Her club reaches 500 pupils across 3 states '
                      . 'and has run since 2019.',
        ]);
        DB::table('gates_nominees')->where('id', self::NOM)->update(['story' => '']);

        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        InterviewService::publish($id,
            'Interviewer: how many? Ada: about 480 pupils now. We began in 2019 with 30 children '
            . 'in one classroom and I keep the register myself each term.', ['source' => 'machine']);
        InterviewReview::run($id);

        $figures = [];
        foreach (InterviewReview::forInterview($id)['figures'] as $f) {
            $figures[$f['figure']] = $f;
        }

        $this->assertArrayNotHasKey('11', $figures, 'a duration is not a quantity of anything');
        $this->assertArrayNotHasKey('2019', $figures, 'a year is not a claim');
        $this->assertSame('confirmed', $figures['500']['status'] ?? '',
            '500 said as 480 is the same claim rounded, not a discrepancy');
        $this->assertStringContainsString('480', (string) ($figures['500']['said'] ?? ''));

        // And one figure per claim, however many times the record restates it.
        $this->assertSame(count($figures), count(InterviewReview::forInterview($id)['figures']));
    }

    /**
     * The keyword pass was surfacing "Interviewer: what is different about how you teach it?"
     * as evidence under Originality. A judge reading that sees the panel's own question
     * presented as the nominee's answer — and the nominee's own words are the one thing this
     * whole feature exists to put in front of a judge.
     */
    public function test_only_the_nominee_is_quoted_never_the_panel(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        InterviewService::publish($id,
            "Interviewer: What did you do differently from how it was done before?\n"
            . "Ada Obi: We build the equipment from scrap instead of buying kits.\n"
            . "Dr Femi: And who else does it that way now?\n"
            . "Ada Obi: Two other schools copied us last year.", ['source' => 'machine']);
        InterviewReview::run($id);

        $quotes = [];
        foreach (InterviewReview::forInterview($id)['criteria'] as $c) {
            foreach ($c['quotes'] as $q) $quotes[] = $q['quote'];
        }
        $joined = implode(' | ', $quotes);

        $this->assertNotEmpty($quotes);
        $this->assertStringNotContainsString('What did you do differently', $joined);
        $this->assertStringNotContainsString('who else does it that way', $joined);
        $this->assertStringNotContainsString('Interviewer:', $joined, 'the label is stripped too');
        $this->assertStringContainsString('from scrap', $joined, 'her answer survives');
    }

    /** Publishing must leave findings on screen even when the queue never drains. */
    public function test_the_figure_check_lands_without_waiting_for_the_queue(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        InterviewService::publish($id,
            'Ada Obi: the club reaches about 480 pupils across three states and I keep the '
            . 'register every term myself, which the head teachers sign.', ['source' => 'machine']);

        // No queue worker has run at this point.
        $review = InterviewReview::forInterview($id);
        $this->assertNotEmpty($review, 'the operator would have landed on an empty panel');
        $this->assertSame('rules', $review['source']);
        $this->assertNotEmpty($review['figures']);
    }

    public function test_prepared_questions_with_no_answer_are_reported_as_a_gap(): void
    {
        $id = $this->open();
        InterviewBrief::build($id);
        $pack = InterviewBrief::forInterview($id);

        // One answered properly, one opened and left blank.
        InterviewService::recordAnswer($id, (string) $pack['questions'][0]['key'],
            (string) $pack['questions'][0]['q'], 'she gave a clear example', null);
        InterviewService::recordAnswer($id, (string) $pack['questions'][1]['key'],
            (string) $pack['questions'][1]['q'], '', null);

        $answers = json_decode((string) InterviewService::byId($id)->answers_json, true);
        $gaps    = InterviewReview::unanswered($pack, $answers);
        $keys    = array_column($pack['questions'], 'key');

        $this->assertCount(count($keys) - 1, $gaps,
            'an empty note is not an answer — counting it would hide the hole in the score');
    }

    /**
     * NO SCORE, EVER. A model that ignores the instruction loses its whole answer rather
     * than having a number quietly stripped: if it reasoned toward a verdict in one place
     * it did so everywhere, and the panel should see the plain keyword pass instead of a
     * laundered opinion.
     */
    public function test_a_model_that_returns_a_score_is_rejected_outright(): void
    {
        foreach (['Impact is 7/10 overall.', 'She scores well on reach.',
                  'A strong candidate on this criterion.', 'Rating: high.',
                  'Roughly 80% of the criterion is met.'] as $text) {
            $this->assertTrue(InterviewReview::looksLikeAScore($text), 'missed: ' . $text);
        }
        foreach (['She described a reading club in 12 schools.',
                  'No timeframe was given for the expansion.',
                  'She said she buys the books herself.'] as $text) {
            $this->assertFalse(InterviewReview::looksLikeAScore($text), 'false positive: ' . $text);
        }
    }

    public function test_reading_a_transcript_writes_no_judge_score(): void
    {
        $id = $this->open();
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        InterviewService::publish($id, str_repeat('She described the reading club at length. ', 8));
        InterviewReview::run($id);

        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->count(),
            'the expert 55% has exactly one class of writer, and it is judges');
        $this->assertStringNotContainsString('suggested_score',
            (string) json_encode(InterviewReview::forInterview($id)));
    }

    // ══ 6. the nominee's page, through the real router ═══════════════════════

    public function test_the_nominee_page_offers_consent_and_never_the_questions(): void
    {
        $id = $this->open();
        InterviewBrief::build($id);
        $token = (string) InterviewService::tokenFor($id);

        $html = $this->getPage('/interview/' . $token);

        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString('recorded and transcribed', $html);
        $this->assertStringContainsString('Impact', $html, 'the themes are shown');
        $this->assertStringNotContainsString('who else could confirm it', $html,
            'the panel’s exact wording must not leak — that interviews the rehearsal');
        // The one sentence that stops an impersonation costing somebody money.
        $this->assertStringContainsString('never ask you to pay', $html);
    }

    public function test_an_unknown_token_says_nothing_about_whether_it_exists(): void
    {
        $res = $this->request('GET', '/interview/' . str_repeat('a', 32));
        $this->assertSame(404, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('not working', $html);
        $this->assertStringNotContainsString('Ada Obi', $html);
    }

    /**
     * THE MAIL-SCANNER TRAP, again. Gmail and every link-safety scanner fetch URLs in a
     * message before a human sees them. A GET that recorded consent would manufacture
     * permission to record people who never pressed anything.
     */
    public function test_fetching_the_link_records_no_consent(): void
    {
        $id    = $this->open();
        $token = (string) InterviewService::tokenFor($id);

        $this->getPage('/interview/' . $token);

        $row = InterviewService::byId($id);
        $this->assertEmpty($row->consent_at, 'a scanner just consented on a person’s behalf');
        $this->assertEmpty($row->confirmed_at);
    }

    public function test_the_page_is_not_indexable(): void
    {
        $id = $this->open();
        $res = $this->request('GET', '/interview/' . InterviewService::tokenFor($id));
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
    }

    // ══ helpers ══════════════════════════════════════════════════════════════

    private function request(string $method, string $path)
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        return $app->handle((new ServerRequestFactory())->createServerRequest($method, $path));
    }

    private function getPage(string $path): string
    {
        return (string) $this->request('GET', $path)->getBody();
    }
}
