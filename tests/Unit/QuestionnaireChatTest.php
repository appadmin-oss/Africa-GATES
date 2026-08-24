<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireChat as C;
use AfricaGates\Services\QuestionnaireService as Q;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The questionnaire answered as a conversation.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THING THAT MATTERS MOST HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * WHAT GETS STORED AS AN ANSWER IS THE NOMINEE'S OWN WORDS, VERBATIM.
 *
 * A model that tidied a halting sentence into confident prose would be authoring a record a
 * judging panel reads as "supplied by the nominee", in a dossier whose most important column
 * is who is asserting what — and it would do it best for the nominees who needed it least.
 * The model chooses what to ASK. It never chooses what the answer SAYS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE REST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. IT IS A REAL CONVERSATION WITH NO AI KEY. The probes that matter are mechanical: an
 *      impact answer with no number, a reach answer with no place, a figure with no source.
 *   2. THE ANSWER IS STORED BEFORE THE FOLLOW-UP IS ASKED, so a nominee who closes the tab
 *      rather than answering it keeps what they already said.
 *   3. A REQUIRED QUESTION CANNOT BE SKIPPED, and an optional one can — taken at face value,
 *      because pressing somebody who has said no is how a conversation becomes a demand.
 *   4. THE CHAT NEVER SENDS. Submitting stays a separate, deliberate act with a typed name.
 */
final class QuestionnaireChatTest extends TestCase
{
    private const PROG = 9500;
    private const CAT = 9500;
    private const NOM = 9501;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'P', 'slug' => 'p-9500',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9500, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9500, 'title' => 'C', 'slug' => 'c-9500',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Grace Mensah',
            'status' => 'approved', 'vote_count' => 800,
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9500, 'category_id' => self::CAT,
            'nominee_name' => 'Grace Mensah', 'nominee_email' => 'grace@example.org',
            'country_code' => 'NG', 'reason' => 'Runs a girls coding club.',
            'nominator_name' => 'Kofi', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9501',
        ]);
        foreach ([['impact', 'Impact'], ['originality', 'Originality'],
                  ['reach', 'Reach'], ['integrity', 'Integrity']] as $i => [$slug, $label]) {
            DB::table('gates_judge_criteria')->insertOrIgnore([
                'id' => 9510 + $i, 'programme_id' => null, 'slug' => $slug, 'label' => $label,
                'description' => 'x', 'weight' => 25, 'sort_order' => $i + 1, 'is_active' => 1,
            ]);
        }
    }

    private function open(): array
    {
        $r = Q::open(self::NOM);
        return [(int) $r['id'], (string) $r['token']];
    }

    // ══ 1. it opens like a conversation ══════════════════════════════════════

    /**
     * The greeting AND the first question. The first version of push() trusted the row object
     * it was handed, so the second call read a stale `chat_json` and wrote the question over
     * the greeting — every nominee opened on a bare question with no explanation of what was
     * happening or that nothing would be sent without them.
     */
    public function test_it_opens_with_a_greeting_and_the_first_question(): void
    {
        [, $token] = $this->open();
        $st = C::start($token);

        $this->assertTrue($st['ok']);
        $this->assertCount(2, $st['turns'], 'the greeting was overwritten by the question');
        $this->assertStringContainsString('Grace', $st['turns'][0]['text'], 'greeted by name');
        $this->assertStringContainsString('nothing is sent to the judges until you press',
            $st['turns'][0]['text'], 'the one reassurance that matters most is missing');
        $this->assertStringContainsString('what is the work', $st['turns'][1]['text']);
    }

    public function test_re_opening_the_page_does_not_greet_again(): void
    {
        [, $token] = $this->open();
        C::start($token);
        $before = count(C::state($token)['turns']);

        C::start($token);

        $this->assertCount($before, C::state($token)['turns'],
            'a nominee coming back was greeted from the beginning as though nothing happened');
    }

    public function test_required_questions_are_asked_first(): void
    {
        [, $token] = $this->open();
        $st = C::start($token);

        $this->assertSame(1, (int) $st['question']['is_required'],
            'the conversation should spend a nominee\'s patience on what a judge cannot do without');
    }

    // ══ 2. what gets stored ══════════════════════════════════════════════════

    /** THE load-bearing test of this feature. */
    public function test_the_answer_stored_is_exactly_what_the_nominee_typed(): void
    {
        [, $token] = $this->open();
        C::start($token);

        $said = 'we teach girls to code on saturday, small small, since 2021 and it still runs';
        C::say($token, $said);

        $this->assertSame($said, Q::formFor($token)['answers']['summary'],
            'the answer was rewritten — a model is now authoring a record labelled as the nominee\'s');
    }

    /**
     * Store first, ask second. The opposite order loses what somebody already said when they
     * close the tab rather than answering the follow-up.
     */
    public function test_an_answer_is_stored_before_the_follow_up_is_asked(): void
    {
        [, $token] = $this->open();
        C::start($token);
        C::say($token, 'We teach girls to code on Saturday mornings at Riverside School.');
        C::say($token, 'March 2021, and it is still running every week.');

        // Impact, with no number in it — this must draw a probe.
        $r = C::say($token, 'It has changed many lives in our town and the parents are grateful.');

        $this->assertNotEmpty($r['reply']);
        $this->assertStringContainsString('how many', mb_strtolower(implode(' ', $r['reply'])));
        $this->assertStringContainsString('changed many lives',
            Q::formFor($token)['answers']['impact_numbers'],
            'the answer was thrown away while a follow-up was asked about it');
    }

    public function test_a_follow_up_answer_is_added_to_the_first_one(): void
    {
        [, $token] = $this->open();
        C::start($token);
        C::say($token, 'We teach girls to code at Riverside School on Saturdays.');
        C::say($token, 'Since March 2021, still running.');
        C::say($token, 'It has changed many lives here.');                    // probed
        C::say($token, 'About 140 girls, and the school register is signed each term.');

        $stored = Q::formFor($token)['answers']['impact_numbers'];
        $this->assertStringContainsString('changed many lives', $stored);
        $this->assertStringContainsString('140 girls', $stored,
            'the part a judge actually needed was dropped');
    }

    // ══ 3. the probes ════════════════════════════════════════════════════════

    public function test_an_impact_answer_with_no_number_is_probed(): void
    {
        $q = ['slug' => 'impact_numbers', 'criterion' => 'Impact'];
        $this->assertNotNull(C::probeFor($q, 'We have helped very many people in our community.'));
        $this->assertStringContainsString('how many',
            mb_strtolower((string) C::probeFor($q, 'We have helped very many people.')));
    }

    public function test_a_figure_with_no_source_behind_it_is_probed_for_one(): void
    {
        $q = ['slug' => 'impact_numbers', 'criterion' => 'Impact'];
        $probe = C::probeFor($q, 'We have reached 400 pupils since 2019.');
        $this->assertNotNull($probe);
        $this->assertStringContainsString('who keeps', mb_strtolower($probe));
    }

    public function test_a_figure_with_a_source_is_left_alone(): void
    {
        $q = ['slug' => 'impact_numbers', 'criterion' => 'Impact'];
        $this->assertNull(C::probeFor($q,
            'We have reached 400 pupils since 2019 and each school keeps a signed register.'));
    }

    public function test_a_reach_answer_with_no_place_named_is_probed(): void
    {
        $q = ['slug' => 'reach', 'criterion' => 'Reach'];
        $this->assertNotNull(C::probeFor($q, 'It has spread quite widely beyond where we began.'));
        $this->assertNull(C::probeFor($q, 'It now runs in Aba, Umuahia and four schools in Enugu.'));
    }

    /** One follow-up is help. Three is an interrogation. */
    public function test_only_one_follow_up_is_ever_spent_on_a_question(): void
    {
        [, $token] = $this->open();
        C::start($token);
        C::say($token, 'We teach girls to code at Riverside School.');
        C::say($token, 'Since 2021.');

        $first  = C::say($token, 'It has helped very many people indeed in this town.');
        $second = C::say($token, 'Really very many people, all over the place.');

        $this->assertStringContainsString('roughly how many',
            mb_strtolower(implode(' ', $first['reply'])), 'the first vague answer drew no probe');
        $this->assertStringNotContainsString('roughly how many',
            mb_strtolower(implode(' ', $second['reply'])),
            'the nominee is being interrogated about one question');
    }

    /**
     * "Since 2021." is eleven characters and a complete answer to "when did it start?".
     *
     * The first version applied one minimum length to every question, nagged for more, and then
     * filed whatever the nominee said next against the DATE question — so an answer about impact
     * ended up stored as the answer to "when did it start".
     */
    public function test_a_short_answer_to_a_short_question_is_accepted(): void
    {
        [, $token] = $this->open();
        C::start($token);
        C::say($token, 'We teach girls to code at Riverside School on Saturdays.');

        $r = C::say($token, 'Since 2021.');

        $this->assertSame('Since 2021.', Q::formFor($token)['answers']['started'],
            'a complete short answer was nagged instead of stored');
        $this->assertStringNotContainsString('say a little more', implode(' ', $r['reply']));
    }

    /** But a two-word shrug at a question asking for prose still gets one nudge. */
    public function test_a_shrug_at_a_prose_question_draws_one_nudge(): void
    {
        [, $token] = $this->open();
        C::start($token);

        $r = C::say($token, 'good work');

        $this->assertStringContainsString('say a little more', implode(' ', $r['reply']));
    }

    // ══ 4. skipping ══════════════════════════════════════════════════════════

    public function test_a_required_question_cannot_be_skipped(): void
    {
        [, $token] = $this->open();
        C::start($token);

        $r = C::say($token, 'skip');

        $this->assertStringContainsString('do have to ask', implode(' ', $r['reply']));
        $this->assertSame([], Q::formFor($token)['answers'], 'a skip was stored as an answer');
    }

    public function test_an_optional_question_can_be_skipped_and_is_not_asked_again(): void
    {
        [, $token] = $this->open();
        C::start($token);
        // Fill the required ones so the conversation reaches an optional question.
        foreach (['We teach girls to code at Riverside School on Saturdays.',
                  'Since March 2021 and still running.',
                  'About 140 girls, and the school keeps a signed register each term.'] as $t) {
            C::say($token, $t);
        }

        $before = C::state($token)['question']['slug'];
        $this->assertSame(0, (int) C::state($token)['question']['is_required']);

        C::say($token, 'skip');
        $after = C::state($token)['question']['slug'] ?? '';

        $this->assertNotSame($before, $after, 'a declined question was asked again');
    }

    public function test_the_words_people_use_to_decline_are_all_understood(): void
    {
        foreach (['skip', 'Skip', 'no', 'none', 'nothing', 'n/a', 'pass', 'next',
                  'I would rather not answer', 'move on', 'leave it'] as $t) {
            $this->assertTrue(C::isSkip($t), 'not read as a skip: ' . $t);
        }
        foreach (['We have no records but the school knows', 'Nothing was written down at first',
                  'About 40 pupils'] as $t) {
            $this->assertFalse(C::isSkip($t), 'wrongly read as a skip: ' . $t);
        }
    }

    // ══ 5. what the conversation must not do ═════════════════════════════════

    /**
     * A chat that could send a nominee's case to a judging panel because they typed "yes" would
     * be a chat that enters somebody for an award. Submitting stays a button with a typed name.
     */
    public function test_the_conversation_never_sends_it_to_the_judges(): void
    {
        [$id, $token] = $this->open();
        C::start($token);
        foreach (['We teach girls to code at Riverside School.', 'Since 2021, still running.',
                  'About 140 girls; the register is signed each term.',
                  'yes', 'yes send it', 'submit it now', 'I am finished'] as $t) {
            C::say($token, $t);
        }

        $this->assertSame('draft', Q::byId($id)->status, 'the chat submitted on its own');
        $this->assertSame(0, DB::table('gates_nominee_evidence')
            ->where('nominee_id', self::NOM)->where('provenance', 'nominee_supplied')->count());
    }

    public function test_a_submitted_questionnaire_stops_talking(): void
    {
        [, $token] = $this->open();
        C::start($token);
        Q::saveDraft($token, $this->fullAnswers(), []);
        Q::submit($token, 'Grace Mensah');

        $r = C::say($token, 'actually let me change something');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already been sent', (string) $r['message']);
    }

    public function test_a_chat_cannot_store_an_answer_to_a_question_that_does_not_exist(): void
    {
        [, $token] = $this->open();
        C::start($token);
        C::say($token, 'A real answer to the first question about our coding club.');

        $answers = Q::formFor($token)['answers'];
        foreach (array_keys($answers) as $slug) {
            $this->assertContains($slug, array_column(Q::questions(self::PROG), 'slug'));
        }
    }

    // ══ 6. progress and readiness ════════════════════════════════════════════

    public function test_progress_counts_what_is_answered_and_what_is_still_needed(): void
    {
        [, $token] = $this->open();
        C::start($token);
        $p0 = C::state($token)['progress'];
        $this->assertSame(0, $p0['answered']);
        $this->assertGreaterThan(0, $p0['required']);

        C::say($token, 'We teach girls to code at Riverside School on Saturday mornings.');
        $p1 = C::state($token)['progress'];

        $this->assertSame(1, $p1['answered']);
        $this->assertSame(1, $p1['required_answered']);
    }

    /**
     * The readiness check names what a judge will find thin BEFORE it is sent. Help, never a
     * score — and the difference between a fixable gap and a wasted claim.
     */
    public function test_readiness_names_the_thin_answers_without_blocking_anything(): void
    {
        [, $token] = $this->open();
        $answers = $this->fullAnswers();
        $answers['impact_numbers'] = 'We have helped very many people in this community.';
        Q::saveDraft($token, $answers, []);

        $r = C::readiness($token);

        $this->assertTrue($r['ready'], 'a thin answer must not block sending');
        $this->assertSame([], $r['missing']);
        $this->assertNotEmpty($r['thin']);
        $this->assertStringContainsString('how many', mb_strtolower($r['thin'][0]['why']));
        $this->assertSame(0, $r['works']);
    }

    public function test_readiness_reports_a_required_answer_that_is_missing(): void
    {
        [, $token] = $this->open();
        Q::saveDraft($token, ['summary' => 'Only this one.'], []);

        $r = C::readiness($token);
        $this->assertFalse($r['ready']);
        $this->assertNotEmpty($r['missing']);
    }

    // ══ 7. the endpoint ══════════════════════════════════════════════════════

    /**
     * ── THE GUIDED-CHAT ENDPOINT IS RETIRED, AND MUST STAY RETIRED ──────────
     *
     * `/my-work/<token>/chat` was a third way to answer the questionnaire, beside the live
     * interview and the form. It has been removed: three doors onto one draft is not three
     * times the help, and the form now asks one question at a time in the interview's own
     * shape, with the same microphone.
     *
     * The SERVICE is still here and still tested above — {@see QuestionnaireChat::readiness()}
     * is what tells a nominee which answers a judge will find thin, and it is used by the
     * form. What must not come back is a live HTTP route that writes answers from a UI
     * nobody renders any more.
     */
    public function test_the_retired_chat_endpoint_is_not_routed(): void
    {
        [, $token] = $this->open();

        $r = $this->post('/my-work/' . $token . '/chat', ['say' => 'hello'], true);
        $this->assertNotSame(200, $r[0], 'the retired guided-chat endpoint is answering again');
    }

    /** And the CSRF exemption for it went with it. */
    public function test_the_csrf_exemption_no_longer_names_the_chat_endpoint(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Middleware/CsrfMiddleware.php');

        $this->assertMatchesRegularExpression('~/my-work/\[a-f0-9\]\{32\}~', $src,
            'the nominee token paths are gone entirely, which is not the change that was made');
        $this->assertStringNotContainsString('upload|chat|speak', $src,
            'an exemption for a path that routes nowhere reads as policy about a live feature');
    }

    // ══ helpers ══════════════════════════════════════════════════════════════

    /** @return array<string,string> */
    private function fullAnswers(): array
    {
        $out = [];
        foreach (Q::questions(self::PROG) as $q) {
            if ((int) $q['is_required'] === 1) {
                $out[(string) $q['slug']] = 'A real answer with enough substance in it, 40 schools.';
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function post(string $path, array $payload, bool $withCsrf): array
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->add(new \AfricaGates\Middleware\CsrfMiddleware());
        $app->addErrorMiddleware(false, false, false);

        $_SESSION['csrf_token'] = 'test-token-value';

        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);
        if ($withCsrf) $req = $req->withHeader('X-CSRF-Token', 'test-token-value');

        $res  = $app->handle($req);
        $body = json_decode((string) $res->getBody(), true);
        return [$res->getStatusCode(), is_array($body) ? $body : []];
    }
}
