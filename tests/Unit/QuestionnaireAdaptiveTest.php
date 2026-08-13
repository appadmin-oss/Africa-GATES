<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireChat as C;
use AfricaGates\Services\QuestionnaireCoach as Coach;
use AfricaGates\Services\QuestionnaireRules as R;
use AfricaGates\Services\QuestionnaireService as Q;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A questionnaire that reads the answers it has already been given.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every nominee got the same eleven questions in the same order, and the cost landed
 * unevenly:
 *
 *   • somebody whose work stopped in 2019 was asked how it is funded, in the present tense;
 *   • somebody whose impact answer already said "1,240 farmers across 8 states" was asked the
 *     follow-up whose whole purpose is to extract a number they had just given.
 *
 * Each is a small insult, and together they say: this form is not reading your answers. A
 * questionnaire that visibly does not listen is one people stop answering honestly.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE PROPERTIES DEFENDED HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. AN UNKNOWN CONDITION FAILS OPEN. Being asked something unnecessary is a minor
 *      annoyance; silently never being asked about the thing your case rests on is a lost
 *      nomination. So a condition nobody recognises shows the question.
 *   2. EVERY CONSUMER USES THE SAME FILTERED SET. If `submit()` used the unfiltered list, a
 *      required question that was never SHOWN would block sending — a dead end whose only exit
 *      is support. If `progress()` did, the bar would promise a finish line that cannot exist.
 *   3. THE COACH NEVER SCORES ANYBODY. It says what is missing, in the second person, and
 *      stops. No mark, no percentage, and nothing written to the row a judge reads.
 */
final class QuestionnaireAdaptiveTest extends TestCase
{
    private const PROG = 9950;
    private const CAT  = 9950;
    private const NOM  = 9951;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::PROG, 'title' => 'P', 'slug' => 'p-9950']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9950, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9950, 'title' => 'C', 'slug' => 'c-9950']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Grace Mensah',
            'status' => 'approved', 'vote_count' => 10]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9950, 'category_id' => self::CAT,
            'nominee_name' => 'Grace Mensah', 'nominee_email' => 'g@example.org',
            'country_code' => 'GH', 'reason' => 'x', 'nominator_name' => 'K',
            'nominator_email' => 'k@example.org', 'status' => 'approved',
            'reference' => 'AFG-NOM-9951']);
    }

    /** @return array{0:int,1:string} */
    private function open(): array
    {
        $r = Q::open(self::NOM);
        return [(int) $r['id'], (string) $r['token']];
    }

    /** @param array<string,string> $answers */
    private function slugsFor(array $answers): array
    {
        [$id, $token] = $this->open();
        Q::saveDraft($token, $answers, []);
        return array_column(Q::questionsFor(Q::byId($id)), 'slug');
    }

    // ══ 1. it reads what has been said ═══════════════════════════════════════

    /** THE test in this file. */
    public function test_an_answer_that_already_has_a_number_is_not_asked_for_one_again(): void
    {
        $with = $this->slugsFor([
            'summary' => 'A coding club.', 'started' => 'Since 2019, still running.',
            'impact_numbers' => 'We trained 1,240 farmers across 8 states between 2019 and 2024; '
                              . 'the state agriculture office holds the register.',
        ]);
        $this->assertNotContains('impact_one', $with,
            'somebody who had already given the figures was asked to produce an anecdote as well');

        $without = $this->slugsFor([
            'summary' => 'A coding club.', 'started' => 'Since 2019, still running.',
            'impact_numbers' => 'We have reached a great many young people over the years.',
        ]);
        $this->assertContains('impact_one', $without,
            'the fallback question vanished for the people who most need it');
    }

    public function test_a_closed_project_is_not_asked_how_it_is_funded(): void
    {
        $closed = $this->slugsFor([
            'summary' => 'A school.', 'started' => 'We ran it from 2015 and closed it in 2019.',
        ]);
        $this->assertNotContains('integrity', $closed,
            'a closed project was asked how it is funded, in the present tense');

        $open = $this->slugsFor([
            'summary' => 'A school.', 'started' => 'Since 2015 and still running today.',
        ]);
        $this->assertContains('integrity', $open);
    }

    /**
     * An ambiguous answer is not a decided one. "We closed the school but the clinic is still
     * running" must produce the question rather than a guess about which half mattered.
     */
    public function test_an_ambiguous_answer_gets_the_question_anyway(): void
    {
        $slugs = $this->slugsFor([
            'summary' => 'Two projects.',
            'started' => 'We closed the school in 2019 but the clinic is still running.',
        ]);
        $this->assertContains('integrity', $slugs);
    }

    public function test_an_unanswered_dependency_holds_the_question_back(): void
    {
        // Branching on a silence is guessing. The question waits until there is something to
        // read — and the nominee has not reached it yet anyway.
        $slugs = $this->slugsFor(['summary' => 'A coding club.']);
        $this->assertNotContains('integrity', $slugs);
    }

    /**
     * The safety property. Being asked something unnecessary is an annoyance; never being
     * asked about the thing your case rests on is a lost nomination.
     */
    public function test_a_condition_nobody_recognises_shows_the_question(): void
    {
        $q = ['slug' => 'x', 'show_if_slug' => 'started', 'show_if' => 'if_the_moon_is_full'];
        $this->assertTrue(R::applies($q, ['started' => 'Since 2019.']));
    }

    public function test_a_question_with_no_condition_is_always_asked(): void
    {
        $this->assertTrue(R::applies(['slug' => 'x'], []));
        $this->assertTrue(R::applies(['slug' => 'x', 'show_if_slug' => '', 'show_if' => 'yes'], []));
    }

    public function test_a_number_written_in_words_counts_as_a_number(): void
    {
        // "We trained four hundred teachers" is a figure, and a check that could not see it
        // would nag somebody who had just answered properly — the exact failure being removed.
        $this->assertTrue(R::hasNumber('We trained four hundred teachers.'));
        $this->assertTrue(R::hasNumber('About 1,240 farmers.'));
        $this->assertFalse(R::hasNumber('We have reached a great many people.'));
    }

    public function test_the_chain_is_walked_forward_so_a_removed_question_cannot_be_branched_on(): void
    {
        // If "still running?" is never asked, nothing may branch on its answer.
        $questions = [
            ['slug' => 'a'],
            ['slug' => 'b', 'show_if_slug' => 'a', 'show_if' => 'is:yes'],
            ['slug' => 'c', 'show_if_slug' => 'b', 'show_if' => 'answered'],
        ];
        $slugs = array_column(R::filter($questions, ['a' => 'no', 'b' => 'something']), 'slug');

        $this->assertSame(['a'], $slugs,
            'a question branched on an answer to a question that was never asked');
    }

    public function test_what_was_never_asked_is_distinguishable_from_what_was_skipped(): void
    {
        // Two very different silences. A dossier that conflated them would let a panel read a
        // question the platform chose not to ask as a nominee refusing to answer it.
        $questions = [['slug' => 'a'], ['slug' => 'b', 'show_if_slug' => 'a', 'show_if' => 'is:yes']];
        $this->assertSame(['b'], R::notAsked($questions, ['a' => 'no']));
        $this->assertSame([], R::notAsked($questions, ['a' => 'yes']));
    }

    // ══ 2. every consumer agrees ═════════════════════════════════════════════

    /**
     * The one that would have been a dead end: a required question branching removed would
     * block sending with an instruction to answer something no screen displays.
     */
    public function test_submitting_does_not_wait_for_a_question_that_was_never_asked(): void
    {
        // Make a required question that only appears for a still-running project, then say the
        // work has ended.
        DB::table('gates_programme_questions')->insert([
            'programme_id' => self::PROG, 'slug' => 'funding_now', 'kind' => 'textarea',
            'label' => 'How is it funded today?', 'is_required' => 1, 'sort_order' => 90,
            'is_active' => 1, 'show_if_slug' => 'started', 'show_if' => 'yes',
        ]);
        Q::seedDefaults(self::PROG);

        [$id, $token] = $this->open();
        $answers = [];
        foreach (Q::questions(self::PROG) as $q) {
            if ((string) $q['slug'] === 'funding_now') continue;
            $answers[(string) $q['slug']] = (string) $q['slug'] === 'started'
                ? 'We closed it in 2019.'
                : 'An answer with 40 people in it, since 2019.';
        }
        Q::saveDraft($token, $answers, []);

        $r = Q::submit($token, 'Grace Mensah');

        $this->assertTrue($r['ok'],
            'a required question the nominee was never shown blocked them from sending: '
            . implode('; ', (array) ($r['missing'] ?? [])));
    }

    public function test_the_progress_bar_can_reach_the_end(): void
    {
        // "4 of 11" that can never reach 11 promises a finish line that does not exist.
        [$id, $token] = $this->open();
        C::start($token);
        C::say($token, 'A coding club for girls in Accra, teaching Saturday classes.');
        C::say($token, 'We closed it in 2019 when the funding stopped.');

        $st = C::state($token);
        $shown = count(Q::questionsFor(Q::byId($id)));

        $this->assertSame($shown, (int) $st['progress']['total'],
            'the bar counts questions this nominee will never be shown');
        $this->assertLessThanOrEqual((int) $st['progress']['total'], (int) $st['progress']['answered']);
    }

    public function test_the_form_only_renders_the_questions_that_apply(): void
    {
        [$id, $token] = $this->open();
        Q::saveDraft($token, ['started' => 'Closed in 2019.'], []);

        $slugs = array_column(Q::formFor($token)['questions'], 'slug');
        $this->assertNotContains('integrity', $slugs);
    }

    public function test_an_unasked_question_is_not_filed_as_unanswered_evidence(): void
    {
        [$id, $token] = $this->open();
        \AfricaGates\Services\QuestionnaireIntro::markSeen($token);
        $answers = [];
        foreach (Q::questions(self::PROG) as $q) {
            $answers[(string) $q['slug']] = (string) $q['slug'] === 'started'
                ? 'Closed in 2019.' : 'An answer with 40 people, since 2019.';
        }
        Q::saveDraft($token, $answers, []);
        Q::submit($token, 'Grace Mensah');

        $titles = DB::table('gates_nominee_evidence')->where('nominee_id', self::NOM)
            ->pluck('title')->all();
        foreach ($titles as $t) {
            $this->assertStringNotContainsString('funded', (string) $t,
                'a question the platform chose not to ask appeared in the dossier');
        }
    }

    // ══ 3. the coach helps and never marks ═══════════════════════════════════

    public function test_a_short_answer_is_told_it_is_short_and_what_to_add(): void
    {
        $r = Coach::read(['slug' => 'summary', 'kind' => 'textarea'], 'We help people.');

        $this->assertSame('thin', $r['state']);
        $this->assertNotSame('', $r['nudge']);
        // A suggestion, not a verdict: it says what to do rather than how bad it is.
        $this->assertStringContainsString('one more sentence', strtolower($r['nudge']));
    }

    public function test_an_impact_answer_with_no_figure_is_asked_for_one(): void
    {
        $r = Coach::read(
            ['slug' => 'impact_numbers', 'kind' => 'textarea', 'criterion' => 'Impact', 'wants_number' => 1],
            'We have reached a great many young people across the country over several years now.'
        );

        $this->assertSame('no_number', $r['state']);
        $this->assertStringContainsString('how many', strtolower($r['nudge']));
    }

    public function test_a_short_date_answer_is_left_alone(): void
    {
        // "Since 2021." is eleven characters and a complete answer. Nagging somebody for
        // answering correctly is how a questionnaire loses their trust on question two.
        $r = Coach::read(['slug' => 'started', 'kind' => 'text', 'min_words' => 0], 'Since 2021.');
        $this->assertSame('good', $r['state']);
    }

    public function test_an_answer_of_only_adjectives_is_asked_for_one_fact(): void
    {
        $r = Coach::read(
            ['slug' => 'summary', 'kind' => 'textarea'],
            'We are a leading, world-class, award-winning and innovative organisation that is '
            . 'passionate and dedicated about outstanding excellence in everything that we do.'
        );

        $this->assertSame('vague', $r['state']);
        $this->assertStringContainsString('swap one adjective', strtolower($r['nudge']));
    }

    public function test_enthusiasm_with_a_fact_in_it_is_not_treated_as_empty(): void
    {
        // Two superlatives beside a figure and a place is somebody writing warmly about
        // something real, and correcting them would be correcting the wrong thing.
        $r = Coach::read(
            ['slug' => 'summary', 'kind' => 'textarea'],
            'Our outstanding and dedicated team trained 1,240 teachers in Kano and Kaduna since 2019.'
        );
        $this->assertSame('good', $r['state']);
    }

    public function test_a_reach_answer_with_no_place_is_asked_to_name_one(): void
    {
        $r = Coach::read(
            ['slug' => 'reach', 'kind' => 'textarea', 'criterion' => 'Reach'],
            'It has spread very widely indeed and many groups now run it themselves elsewhere.'
        );

        $this->assertSame('vague', $r['state']);
        $this->assertStringContainsString('name the towns', strtolower($r['nudge']));
    }

    public function test_a_lower_case_place_name_still_counts(): void
    {
        // Plenty of people type without capitals on a phone, and punishing them for it would
        // be punishing the keyboard.
        $r = Coach::read(
            ['slug' => 'reach', 'kind' => 'textarea', 'criterion' => 'Reach'],
            'we now run it in three states, kano and kaduna and zamfara, with local teachers.'
        );
        $this->assertSame('good', $r['state']);
    }

    /** THE rule. No mark, no percentage, nothing a nominee could read as a grade. */
    public function test_nothing_the_coach_says_is_ever_a_score(): void
    {
        $samples = [
            'We help people.',
            'We have reached a great many young people.',
            'It has spread very widely indeed to many other places.',
            'We are a leading world-class award-winning innovative passionate dedicated organisation.',
            'Our team trained 1,240 teachers in Kano since 2019 and the ministry holds the register.',
        ];

        foreach ($samples as $text) {
            foreach ([['slug' => 'summary', 'kind' => 'textarea'],
                      ['slug' => 'impact_numbers', 'kind' => 'textarea', 'criterion' => 'Impact']] as $q) {
                $r = Coach::read($q, $text);
                $said = strtolower($r['note'] . ' ' . $r['nudge']);
                foreach (['%', 'score', 'grade', 'rating', 'out of 10', 'weak', 'poor', 'points'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $said,
                        'the coach graded an answer: ' . $r['note'] . ' / ' . $r['nudge']);
                }
                $this->assertContains($r['state'], ['empty', 'thin', 'no_number', 'vague', 'good']);
            }
        }
    }

    public function test_an_empty_answer_is_not_criticised(): void
    {
        // Nothing has been written yet. Saying anything at this point would be telling somebody
        // off for not having started.
        $r = Coach::read(['slug' => 'summary', 'kind' => 'textarea'], '   ');
        $this->assertSame('empty', $r['state']);
        $this->assertSame('', $r['note']);
        $this->assertSame('', $r['nudge']);
    }

    public function test_what_a_judge_looks_for_is_said_per_criterion(): void
    {
        $this->assertStringContainsString('how many',
            strtolower(Coach::lookingFor(['criterion' => 'Impact'])));
        $this->assertStringContainsString('named places',
            strtolower(Coach::lookingFor(['criterion' => 'Reach'])));
        $this->assertStringContainsString('accountable',
            strtolower(Coach::lookingFor(['criterion' => 'Integrity'])));
        // And a question with no criterion still says something useful rather than nothing.
        $this->assertNotSame('', Coach::lookingFor(['criterion' => '']));
    }

    public function test_the_coach_writes_nothing_to_the_record(): void
    {
        // It reads text the nominee has not even saved. A coach that recorded its own opinions
        // would be building a second, hidden assessment of a person beside the judges' one.
        [$id, $token] = $this->open();
        $before = (array) Q::byId($id);

        Coach::read(['slug' => 'summary', 'kind' => 'textarea'], 'We help people.');

        $this->assertSame($before, (array) Q::byId($id));
    }
}
