<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EvidenceService;
use AfricaGates\Services\QuestionnaireService as Q;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The questionnaire a nominee answers about their own work.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_nominee_evidence` shipped with `provenance = 'nominee_supplied'` as a first-class
 * value and never had a writer, so no judge has ever seen a single thing a nominee chose to
 * show them. Every word on a ballot was written by other people.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS DEFEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. IT WORKS BEFORE ANYBODY CONFIGURES IT. The default question set is derived from the
 *      programme's own rubric, so a questionnaire can be sent on day one. A feature that
 *      needs designing first is a feature that never gets used.
 *   2. A DRAFT SAVE NEVER VALIDATES. Somebody filling this in on a phone must be able to
 *      leave half a sentence — and a submit that fails validation must not throw away what
 *      was typed on the way to failing.
 *   3. NOTHING IS EVER MARKED VERIFIED. A self-uploaded document is a claim, and the dossier
 *      is built to show a judge the difference between a claim and a checked record.
 *   4. RE-SENDING REPLACES. A nominee who corrects an answer must not leave the panel reading
 *      two versions, or appear to have twice the evidence.
 *   5. A CRAFTED POST CANNOT INVENT A QUESTION, or point an evidence row at a path on the
 *      server.
 */
final class QuestionnaireTest extends TestCase
{
    private const PROG = 94;
    private const CYCLE = 9400;
    private const CAT = 9400;
    private const NOM = 9401;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'Teaching Award', 'slug' => 'prog-9400',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => self::CYCLE, 'programme_id' => self::PROG, 'year' => 2026,
            'status' => 'judging', 'results_date' => '2026-11-20 18:00:00',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => self::CYCLE, 'title' => 'Primary', 'slug' => 'cat-9400',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Bola Adeyemi',
            'organisation' => 'Green Roots', 'country_code' => 'NG', 'status' => 'approved',
            'vote_count' => 1500,
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => self::CYCLE, 'category_id' => self::CAT,
            'nominee_name' => 'Bola Adeyemi', 'nominee_email' => 'bola@example.org',
            'country_code' => 'NG', 'reason' => 'Planted 12000 trees with 40 schools.',
            'nominator_name' => 'Ade', 'nominator_email' => 'ade@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9401',
        ]);
        foreach ([['impact', 'Impact'], ['originality', 'Originality'],
                  ['reach', 'Reach'], ['integrity', 'Integrity']] as $i => [$slug, $label]) {
            DB::table('gates_judge_criteria')->insertOrIgnore([
                'id' => 9410 + $i, 'programme_id' => null, 'slug' => $slug, 'label' => $label,
                'description' => 'x', 'weight' => 25, 'sort_order' => $i + 1, 'is_active' => 1,
            ]);
        }
    }

    private function open(): array
    {
        $r = Q::open(self::NOM);
        $this->assertTrue($r['ok'], (string) $r['message']);
        return [(int) $r['id'], (string) $r['token']];
    }

    // ══ 1. it works before anybody configures it ══════════════════════════════

    public function test_the_default_questions_come_from_the_programmes_own_rubric(): void
    {
        $qs = Q::questions(self::PROG);
        $this->assertNotEmpty($qs, 'a programme with no questions of its own must still have some');

        $labels = array_column($qs, 'criterion');
        foreach (['Impact', 'Originality', 'Reach', 'Integrity'] as $c) {
            $this->assertContains($c, $labels,
                'no question is filed against ' . $c . ', so nothing a nominee writes reaches it');
        }

        // Nothing was written to the table just by reading it.
        $this->assertSame(0, DB::table('gates_programme_questions')->count(),
            'reading the questionnaire created rows nobody asked for');
    }

    public function test_seeding_makes_the_defaults_editable_and_is_not_repeatable(): void
    {
        $n = Q::seedDefaults(self::PROG);
        $this->assertGreaterThan(5, $n);
        $this->assertSame(0, Q::seedDefaults(self::PROG), 'seeding twice would duplicate the set');

        $qs = Q::questions(self::PROG);
        $this->assertNotNull($qs[0]['id'], 'a seeded question has an id and can be edited');
    }

    /** A programme's own wording beats the shared default on the same slug. */
    public function test_a_programmes_own_question_overrides_the_default(): void
    {
        DB::table('gates_programme_questions')->insert([
            'programme_id' => self::PROG, 'slug' => 'summary', 'kind' => 'textarea',
            'label' => 'Describe the school you transformed', 'is_required' => 1,
            'max_len' => 900, 'sort_order' => 1, 'is_active' => 1,
        ]);

        $qs = Q::questions(self::PROG);
        $this->assertCount(1, $qs, 'once a programme has its own set, that IS the set');
        $this->assertSame('Describe the school you transformed', $qs[0]['label']);
    }

    // ══ 2. drafts are cheap, submitting is deliberate ═════════════════════════

    public function test_a_draft_saves_without_validating_anything(): void
    {
        [$id, $token] = $this->open();

        $r = Q::saveDraft($token, ['summary' => 'Half a sen'], []);
        $this->assertTrue($r['ok'], (string) $r['message']);

        $form = Q::formFor($token);
        $this->assertSame('Half a sen', $form['answers']['summary']);
        $this->assertSame('draft', $form['status']);
        $this->assertNotEmpty(Q::byId($id)->started_at, 'the screen could not show "they opened it"');
    }

    public function test_submitting_without_the_required_answers_names_them(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, ['summary' => 'We plant trees with schools every Saturday.'], []);

        $r = Q::submit($token, 'Bola Adeyemi');

        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['missing']);
        $this->assertSame('draft', Q::byId((int) Q::byToken($token)->id)->status);
        $this->assertSame(0, DB::table('gates_nominee_evidence')
            ->where('nominee_id', self::NOM)->where('provenance', 'nominee_supplied')->count());
    }

    public function test_submitting_without_a_name_is_refused(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), []);

        $r = Q::submit($token, '   ');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('type your name', (string) $r['message']);
    }

    public function test_a_submitted_questionnaire_stops_accepting_drafts(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), []);
        Q::submit($token, 'Bola Adeyemi');

        $r = Q::saveDraft($token, ['summary' => 'changed my mind'], []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already been sent', (string) $r['message']);
    }

    // ══ 3. into the judges' dossier ══════════════════════════════════════════

    /**
     * The whole point of the feature. Before this, EvidenceService had nothing with
     * `nominee_supplied` provenance to serve, because nothing had ever written one.
     */
    public function test_submitting_puts_the_nominees_own_evidence_in_front_of_judges(): void
    {
        [$id, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), [
            ['uid' => 'w1', 'title' => 'Planting register 2024', 'kind' => 'document',
             'year' => '2024', 'description' => 'Signed by every head teacher.',
             'confirm' => 'Mrs Okonkwo, Ada Primary'],
            ['uid' => 'w2', 'title' => 'Radio interview', 'kind' => 'press',
             'link' => 'example.org/radio'],
        ]);

        $r = Q::submit($token, 'Bola Adeyemi');
        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertGreaterThan(4, (int) $r['evidence']);

        $dossier = (new EvidenceService())->forJudge(self::NOM);
        $titles  = array_column($dossier['items'], 'title');
        $this->assertContains('Planting register 2024', $titles);
        $this->assertContains('Radio interview', $titles);
        $this->assertTrue($dossier['coverage']['has_nominee'],
            'the dossier still reports the nominee as having sent nothing');

        // The bare link was made a URL rather than stored as typed.
        $press = DB::table('gates_nominee_evidence')->where('title', 'Radio interview')->first();
        $this->assertSame('https://example.org/radio', (string) $press->source_url);
        $this->assertSame('press', (string) $press->kind);

        $this->assertSame((int) $r['evidence'], (int) Q::byId($id)->evidence_count);
    }

    /**
     * NOTHING IS EVER VERIFIED. A self-uploaded document is a claim; the dossier exists to
     * show a judge the difference between that and an independently checked record.
     */
    public function test_nothing_a_nominee_sends_is_marked_verified(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), [
            ['uid' => 'w1', 'title' => 'Government letter', 'kind' => 'document'],
        ]);
        Q::submit($token, 'Bola Adeyemi');

        $rows = DB::table('gates_nominee_evidence')
            ->where('nominee_id', self::NOM)->where('provenance', 'nominee_supplied')->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame(0, (int) $r->verified, 'a nominee just verified their own claim');
            $this->assertSame('nominee_supplied', (string) $r->provenance);
            $this->assertSame(1, (int) $r->visible_to_judges);
        }
    }

    public function test_re_sending_replaces_rather_than_doubling_the_evidence(): void
    {
        [$id, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), [['uid' => 'w1', 'title' => 'First item']]);
        Q::submit($token, 'Bola Adeyemi');
        $first = DB::table('gates_nominee_evidence')->where('nominee_id', self::NOM)
            ->where('provenance', 'nominee_supplied')->count();

        Q::reopen($id);
        Q::saveDraft($token, $this->fullAnswers(), [['uid' => 'w1', 'title' => 'Corrected item']]);
        Q::submit($token, 'Bola Adeyemi');

        $after  = DB::table('gates_nominee_evidence')->where('nominee_id', self::NOM)
            ->where('provenance', 'nominee_supplied')->count();
        $titles = DB::table('gates_nominee_evidence')->where('nominee_id', self::NOM)
            ->pluck('title')->all();

        $this->assertSame($first, $after, 'the panel now sees two versions of the same submission');
        $this->assertContains('Corrected item', $titles);
        $this->assertNotContains('First item', $titles);
    }

    /**
     * Re-publishing must not touch anybody else's rows. Staff notes, verified records and the
     * nomination's own case are somebody else's assertions and are not this function's to
     * clear.
     */
    public function test_publishing_leaves_other_peoples_evidence_alone(): void
    {
        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => self::NOM, 'kind' => 'award', 'title' => 'State prize, checked by us',
            'provenance' => 'platform_verified', 'verified' => 1, 'visible_to_judges' => 1,
            'sort_order' => 1,
        ]);

        [, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), []);
        Q::submit($token, 'Bola Adeyemi');

        $this->assertSame(1, DB::table('gates_nominee_evidence')
            ->where('nominee_id', self::NOM)->where('provenance', 'platform_verified')->count(),
            'a verified record was deleted by a nominee pressing send');
    }

    public function test_a_draft_reaches_no_judge_at_all(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), [['uid' => 'w1', 'title' => 'Not sent yet']]);

        $titles = array_column((new EvidenceService())->forJudge(self::NOM)['items'], 'title');
        $this->assertNotContains('Not sent yet', $titles);
        $this->assertFalse((new EvidenceService())->forJudge(self::NOM)['coverage']['has_nominee']);
    }

    /**
     * The two stages compound, and nobody wired them together on purpose.
     *
     * {@see \AfricaGates\Services\InterviewBrief} builds its questions from the dossier, and the
     * dossier now contains the nominee's own figures. So a figure the nominee typed themselves
     * comes back at them in the interview — "you said 9,000 of 12,000 survived; how was that
     * counted?" — which is the strongest kind of question there is, because they cannot be
     * unfamiliar with their own claim and an impostor cannot be familiar with it.
     *
     * Asserted because it is a property of two features meeting, and the kind of thing a later
     * refactor of either could quietly remove.
     */
    public function test_a_nominees_own_figure_becomes_an_interview_question(): void
    {
        [, $token] = $this->open();
        $answers = $this->fullAnswers();
        $answers['impact_numbers'] = 'About 9,000 trees survived out of 12,000 planted, across '
                                  . '40 schools, and each school keeps a signed register.';
        Q::saveDraft($token, $answers, []);
        Q::submit($token, 'Bola Adeyemi');

        $iv = \AfricaGates\Services\InterviewService::create(self::NOM, [
            'scheduled_at' => '2026-10-01 10:00',
        ]);
        \AfricaGates\Services\InterviewBrief::build((int) $iv['id']);

        $questions = \AfricaGates\Services\InterviewBrief::forInterview((int) $iv['id'])['questions'];
        $claims = implode(' ', array_column(array_filter($questions,
            fn (array $q): bool => ($q['source'] ?? '') === 'claim'), 'q'));

        $this->assertStringContainsString('9,000', $claims,
            "the nominee's own figure never reached the interview pack");
        $this->assertStringContainsString('who else could confirm it', $claims);
    }

    // ══ 4. what a crafted request cannot do ══════════════════════════════════

    public function test_an_answer_to_a_question_that_does_not_exist_is_dropped(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, ['summary' => 'real', 'not_a_question' => 'injected',
                              'is_winner' => '1'], []);

        $answers = Q::formFor($token)['answers'];
        $this->assertArrayHasKey('summary', $answers);
        $this->assertArrayNotHasKey('not_a_question', $answers);
        $this->assertArrayNotHasKey('is_winner', $answers);
    }

    public function test_an_answer_is_trimmed_to_the_length_the_question_declares(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, ['started' => str_repeat('x', 5000)], []);

        $len = mb_strlen(Q::formFor($token)['answers']['started']);
        $this->assertLessThanOrEqual(200, $len, 'a 200-character question stored 5,000');
    }

    /**
     * A field holding a server path is a field somebody can type into. The file on a work row
     * is carried over from what is already stored, never accepted from the form — pointing an
     * evidence row at an arbitrary path is not a mistake worth making possible.
     */
    public function test_a_file_path_cannot_be_supplied_by_the_form(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, $this->fullAnswers(), [
            ['uid' => 'w1', 'title' => 'Nice try', 'file' => '/../../.env',
             'file_name' => 'secrets'],
        ]);

        $works = Q::formFor($token)['works'];
        $this->assertSame('', (string) $works[0]['file'], 'the form set a file path');
    }

    public function test_more_works_than_allowed_are_cut_rather_than_stored(): void
    {
        [, $token] = $this->open();
        $many = [];
        for ($i = 0; $i < 40; $i++) {
            $many[] = ['uid' => 'w' . $i, 'title' => 'Item ' . $i];
        }
        Q::saveDraft($token, [], $many);

        $this->assertLessThanOrEqual(Q::MAX_WORKS, count(Q::formFor($token)['works']));
    }

    public function test_an_empty_work_row_is_not_stored(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, [], [
            ['uid' => 'w1', 'title' => 'Real one'],
            ['uid' => 'w2', 'title' => '', 'link' => '', 'description' => ''],
        ]);

        $this->assertCount(1, Q::formFor($token)['works']);
    }

    public function test_one_questionnaire_per_nominee(): void
    {
        [$id] = $this->open();
        $again = Q::open(self::NOM);

        $this->assertTrue($again['ok']);
        $this->assertSame($id, (int) $again['id'], 'a second questionnaire was created');
        $this->assertSame(1, DB::table('gates_nominee_submissions')->count());
    }

    // ══ 5. the nominee's page, through the real router ═══════════════════════

    /**
     * The brief now stands in front of the questions, so this walks past it first.
     *
     * That gate is the point of it: somebody who has not been told how long this takes, what a
     * usable answer looks like, or that nothing is sent until they press the button is being
     * asked to describe their life's work in the dark. The test below asserts the brief itself.
     */
    public function test_the_page_shows_the_questions_and_says_nothing_costs_money(): void
    {
        [, $token] = $this->open();
        \AfricaGates\Services\QuestionnaireIntro::markSeen($token);
        $html = $this->getPage('/my-work/' . $token);

        $this->assertStringContainsString('Bola Adeyemi', $html);
        $this->assertStringContainsString('who keeps that record', $html, 'the impact question');
        $this->assertStringContainsString('Judged under', $html, 'each answer names its criterion');
        // The one sentence that stops an impersonation costing somebody money.
        $this->assertStringContainsString('never ask you to pay', $html);
        // And the one that gets an honest answer to the hardest question.
        $this->assertStringContainsString('never cost anybody an award', $html);
    }

    /** And before any of that, the page says what is expected of them. */
    public function test_the_page_opens_on_the_brief_and_not_on_a_question(): void
    {
        [, $token] = $this->open();
        $html = $this->getPage('/my-work/' . $token);

        $this->assertStringContainsString('what happens to it', $html);
        $this->assertStringContainsString('saved as you go', $html);
        $this->assertStringContainsString('I have read this', $html);
        // The questions are NOT on this page yet — that is the whole gate.
        $this->assertStringNotContainsString('who keeps that record', $html,
            'the questions were shown before anybody had been told what this is');
    }

    public function test_the_deadline_is_a_date_and_not_a_database_column(): void
    {
        [, $token] = $this->open();
        $this->assertSame('20 November 2026', Q::formFor($token)['deadline']);
    }

    public function test_an_unknown_token_says_nothing_about_whether_it_exists(): void
    {
        $res  = $this->request('GET', '/my-work/' . str_repeat('b', 32));
        $html = (string) $res->getBody();

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('not working', $html);
        $this->assertStringNotContainsString('Bola Adeyemi', $html);
    }

    /** Mail scanners fetch the URLs in a message. A GET must change nothing. */
    public function test_fetching_the_link_submits_nothing(): void
    {
        [$id, $token] = $this->open();
        $this->getPage('/my-work/' . $token);

        $this->assertSame('draft', Q::byId($id)->status);
        $this->assertEmpty(Q::byId($id)->submitted_at);
    }

    public function test_the_page_is_not_indexable(): void
    {
        [, $token] = $this->open();
        $res = $this->request('GET', '/my-work/' . $token);
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
    }

    // ══ helpers ══════════════════════════════════════════════════════════════

    /** @return array<string,string> */
    private function fullAnswers(): array
    {
        $out = [];
        foreach (Q::questions(self::PROG) as $q) {
            if ((int) $q['is_required'] === 1) {
                $out[(string) $q['slug']] = 'A real answer with enough substance in it.';
            }
        }
        return $out;
    }

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
