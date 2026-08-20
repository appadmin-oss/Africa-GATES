<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\QuestionnairesController;
use AfricaGates\Services\QuestionnaireRules;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The questionnaire builder — what an operator can actually change.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DEFECT THIS FILE WAS WRITTEN AROUND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_programme_questions` has carried `show_if_slug`, `show_if`, `min_words` and
 * `wants_number` since the adaptive migration, and `QuestionnaireRules` has always read them.
 * The editor never WROTE them.
 *
 * So the shipped defaults branched correctly, and the moment an operator pressed "copy the
 * defaults in" — which is the only way to change a single word of any question — every branch
 * was silently dropped. From then on a nominee who had said their project closed in 2019 was
 * asked, in the present tense, how it is funded. That is precisely the failure the adaptive
 * work was done to remove, reintroduced by the screen meant to maintain it, with nothing
 * anywhere to say it had happened.
 *
 * The first test below is the regression: seed, save, and assert the branch survives.
 */
final class QuestionnaireBuilderTest extends TestCase
{
    private const PROG = 9700;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'P', 'slug' => 'p-9700',
        ]);
        DB::table('gates_judge_criteria')->insertOrIgnore([
            'id' => 9710, 'programme_id' => null, 'slug' => 'impact', 'label' => 'Impact',
            'description' => 'x', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1,
        ]);
        $_SESSION['admin_role'] = 'admin';
        $_SESSION['admin_id']   = 1;
        unset($_SESSION['flash'], $_SESSION['flash_error']);
    }

    /** Call a controller action directly — the routing and CSRF layers are tested elsewhere. */
    private function call(string $method, array $body): Response
    {
        $c   = new QuestionnairesController(
            $this->createMock(\Slim\Views\Twig::class));
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody($body);
        return $c->{$method}($req, (new ResponseFactory())->createResponse(), ['id' => self::PROG]);
    }

    /** The stored questions as the reader sees them, keyed by slug. */
    private function stored(): array
    {
        $by = [];
        foreach (Q::questions(self::PROG) as $q) $by[(string) $q['slug']] = $q;
        return $by;
    }

    // ══ 1. the regression ════════════════════════════════════════════════════

    public function test_branching_survives_being_seeded_and_saved(): void
    {
        Q::seedDefaults(self::PROG);
        $before = $this->stored();
        $this->assertSame('started', $before['integrity']['show_if_slug'],
            'the shipped default did not branch — this test is asserting nothing');

        // Round-trip every question exactly as the screen submits it.
        $rows = [];
        foreach (Q::questions(self::PROG) as $q) {
            $rows[] = [
                'id' => $q['id'], 'slug' => $q['slug'], 'label' => $q['label'],
                'help' => $q['help'], 'kind' => $q['kind'],
                'criterion_id' => $q['criterion_id'], 'evidence_kind' => $q['evidence_kind'],
                'max_len' => $q['max_len'], 'is_required' => $q['is_required'] ? '1' : '',
                'show_if_slug' => $q['show_if_slug'] ?? '', 'show_if' => $q['show_if'] ?? '',
                'min_words' => $q['min_words'] ?? '', 'wants_number' => ($q['wants_number'] ?? 0) ? '1' : '',
                'placeholder' => '', 'options' => '',
            ];
        }
        $this->call('saveQuestions', ['q' => $rows]);

        $after = $this->stored();
        $this->assertSame('started', $after['integrity']['show_if_slug'],
            'the branch was dropped by the editor that is supposed to maintain it');
        $this->assertSame('yes', $after['integrity']['show_if']);
        $this->assertSame(1, (int) $after['impact_numbers']['wants_number'],
            'the figure nudge was dropped');
        $this->assertSame(0, (int) $after['started']['min_words'],
            'a min_words of zero is a decision and must not be read back as "unset"');
    }

    public function test_a_branch_actually_hides_the_question_after_a_save(): void
    {
        // The end-to-end property: what the operator configured is what the nominee meets.
        // Deliberately NOT seeded first — two rows sharing a slug is a different bug, and
        // this test is about the branch.
        $this->call('saveQuestions', [
            'q' => [
                ['id' => 0, 'slug' => 'started', 'label' => 'Is it still running?',
                 'kind' => 'text', 'max_len' => 200],
                ['id' => 0, 'slug' => 'integrity', 'label' => 'How is it funded?',
                 'kind' => 'textarea', 'max_len' => 900,
                 'show_if_slug' => 'started', 'show_if' => 'yes'],
            ],
        ]);

        $all = Q::questions(self::PROG);
        $open   = QuestionnaireRules::filter($all, ['started' => 'Yes, it runs every week.']);
        $closed = QuestionnaireRules::filter($all, ['started' => 'No, we closed it in 2019.']);

        $this->assertContains('integrity', array_column($open, 'slug'));
        $this->assertNotContains('integrity', array_column($closed, 'slug'),
            'a closed project was still asked how it is funded, in the present tense');
    }

    // ══ 2. the other newly-writable fields ═══════════════════════════════════

    public function test_choices_are_typed_one_per_line_and_stored_as_json(): void
    {
        $this->call('saveQuestions', ['q' => [[
            'id' => 0, 'slug' => 'sector', 'label' => 'Which sector?', 'kind' => 'select',
            'options' => "Farming\n  Health  \n\nEducation\n",
        ]]]);
        // Blank lines and stray spaces removed: nobody types a clean list into a textarea.
        $this->assertSame(['Farming', 'Health', 'Education'], $this->stored()['sector']['options']);
    }

    public function test_an_unrecognised_branch_condition_is_stored_as_nothing(): void
    {
        // applies() fails open on an unknown condition, which is right at read time and is the
        // wrong thing to lean on at write time — a typo would sit in the database looking like
        // a rule that works.
        $this->call('saveQuestions', ['q' => [[
            'id' => 0, 'slug' => 'x', 'label' => 'X', 'kind' => 'text',
            'show_if_slug' => 'started', 'show_if' => 'wehn they say yes',
        ]]]);
        $this->assertNull($this->stored()['x']['show_if']);
    }

    public function test_an_exact_match_condition_is_kept(): void
    {
        $this->call('saveQuestions', ['q' => [[
            'id' => 0, 'slug' => 'x', 'label' => 'X', 'kind' => 'text',
            'show_if_slug' => 'sector', 'show_if' => 'is: Farming',
        ]]]);
        $this->assertSame('is:farming', $this->stored()['x']['show_if']);
    }

    public function test_emptying_the_wording_still_deletes_the_question(): void
    {
        Q::seedDefaults(self::PROG);
        $id = (int) DB::table('gates_programme_questions')
            ->where('programme_id', self::PROG)->where('slug', 'setback')->value('id');

        $this->call('saveQuestions', ['q' => [['id' => $id, 'slug' => 'setback', 'label' => '']]]);
        $this->assertArrayNotHasKey('setback', $this->stored());
    }

    // ══ 3. the interview half ════════════════════════════════════════════════

    public function test_the_style_and_the_brief_save_together(): void
    {
        $this->call('saveStyle', [
            'style' => 'interview', 'brief' => 'Ask about the seed bank.',
            'max_turns' => 30, 'token_ceiling' => 90000, 'kb_token_budget' => 2000,
        ]);
        S::forget();
        $cfg = S::config(self::PROG);

        $this->assertSame('interview', $cfg['style']);
        $this->assertSame('Ask about the seed bank.', $cfg['brief']);
        $this->assertSame(30, $cfg['max_turns']);
        // The route is not set, so it inherits the pin rather than becoming empty.
        $this->assertSame(S::DEFAULT_ROUTE, $cfg['route']);
    }

    public function test_a_mistyped_ceiling_is_clamped_rather_than_losing_the_brief(): void
    {
        // Refusing the whole save over a typo in a number would throw away the paragraph the
        // operator wrote in the same submission.
        $this->call('saveStyle', ['style' => 'interview', 'brief' => 'Keep me.',
                                  'token_ceiling' => 5000000, 'max_turns' => 1]);
        S::forget();
        $cfg = S::config(self::PROG);
        $this->assertSame('Keep me.', $cfg['brief']);
        $this->assertSame(2_000_000, $cfg['token_ceiling']);
        $this->assertSame(4, $cfg['max_turns']);
    }

    public function test_a_programme_row_beats_the_platform_row(): void
    {
        S::saveConfig(null, ['style' => 'form', 'brief' => 'the platform default']);
        S::saveConfig(self::PROG, ['style' => 'interview', 'brief' => 'this programme']);
        S::forget();

        $this->assertSame('this programme', S::config(self::PROG)['brief']);
        $this->assertSame('the platform default', S::config(null)['brief']);
        // A programme that has written nothing still inherits.
        $this->assertSame('the platform default', S::config(4242)['brief']);
    }

    public function test_outcomes_save_and_an_emptied_one_is_retired_not_deleted(): void
    {
        $this->call('saveOutcomes', ['o' => [
            ['id' => 0, 'slug' => 'scale', 'label' => 'How far it reaches',
             'description' => 'A figure with a source behind it.',
             'criterion_id' => 9710, 'required' => '1'],
            ['id' => 0, 'slug' => 'story', 'label' => 'One person it changed'],
        ]]);
        S::forget();
        $this->assertCount(2, S::outcomes(self::PROG));

        $id = (int) DB::table('gates_questionnaire_outcomes')
            ->where('programme_id', self::PROG)->where('slug', 'story')->value('id');
        $this->call('saveOutcomes', ['o' => [['id' => $id, 'slug' => 'story', 'label' => '']]]);
        S::forget();

        $this->assertCount(1, S::outcomes(self::PROG));
        // Retired, not deleted: an operator who removes the wrong one can put it back.
        $this->assertSame(0, (int) DB::table('gates_questionnaire_outcomes')
            ->where('id', $id)->value('is_active'));
    }

    public function test_a_programme_overriding_one_outcome_keeps_the_platform_rest(): void
    {
        S::saveOutcome(null, null, ['slug' => 'scale', 'label' => 'Platform scale']);
        S::saveOutcome(null, null, ['slug' => 'story', 'label' => 'Platform story']);
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'Our scale']);
        S::forget();

        $by = [];
        foreach (S::outcomes(self::PROG) as $o) $by[$o['slug']] = $o['label'];
        $this->assertSame('Our scale', $by['scale']);
        $this->assertSame('Platform story', $by['story'],
            'overriding one outcome replaced the whole set');
    }

    public function test_seeding_outcomes_copies_the_derived_ones_in_once(): void
    {
        Q::seedDefaults(self::PROG);
        S::forget();

        $n = S::seedOutcomes(self::PROG);
        $this->assertGreaterThan(0, $n);
        S::forget();
        $this->assertFalse(S::outcomes(self::PROG)[0]['derived']);
        // Second press does nothing, rather than doubling the set.
        $this->assertSame(0, S::seedOutcomes(self::PROG));
    }

    // ══ 4. a refused row is named, never silently dropped ════════════════════

    public function test_two_outcomes_cannot_share_a_short_name_and_the_screen_says_so(): void
    {
        // The database has a UNIQUE on (programme_id, slug). That failure was caught, logged
        // and reported as success — the screen said "1 outcome saved" and the second row was
        // gone, with the operator's paragraph in it.
        $this->call('saveOutcomes', ['o' => [
            ['id' => 0, 'slug' => 'scale', 'label' => 'How far it reaches'],
            ['id' => 0, 'slug' => 'scale', 'label' => 'A second one by mistake'],
        ]]);
        S::forget();

        $this->assertCount(1, S::outcomes(self::PROG));
        $this->assertStringContainsString('A second one by mistake', $_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('scale', $_SESSION['flash_error'] ?? '');
    }

    public function test_an_outcome_whose_short_name_folds_to_nothing_is_named(): void
    {
        $this->call('saveOutcomes', ['o' => [
            ['id' => 0, 'slug' => '!!!', 'label' => 'No usable short name'],
        ]]);
        S::forget();

        $this->assertCount(0, DB::table('gates_questionnaire_outcomes')
            ->where('programme_id', self::PROG)->get()->all());
        $this->assertStringContainsString('No usable short name', $_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('short name', $_SESSION['flash_error'] ?? '');
    }

    public function test_two_questions_cannot_share_a_short_name_either(): void
    {
        // The questions table has no unique constraint, so both rows stored — and questions()
        // dedupes by slug at READ time, so one simply never reached a nominee. The operator
        // saw "2 questions saved" and the form asked one thing.
        $this->call('saveQuestions', ['q' => [
            ['id' => 0, 'slug' => 'reach', 'label' => 'Where has it spread?', 'kind' => 'textarea'],
            ['id' => 0, 'slug' => 'reach', 'label' => 'A duplicate short name', 'kind' => 'textarea'],
        ]]);

        $this->assertSame(1, DB::table('gates_programme_questions')
            ->where('programme_id', self::PROG)->where('slug', 'reach')->count());
        $this->assertStringContainsString('A duplicate short name', $_SESSION['flash_error'] ?? '');
    }

    public function test_a_clean_save_reports_no_refusals(): void
    {
        unset($_SESSION['flash_error']);
        $this->call('saveOutcomes', ['o' => [
            ['id' => 0, 'slug' => 'scale', 'label' => 'How far it reaches'],
            ['id' => 0, 'slug' => 'story', 'label' => 'One person it changed'],
        ]]);
        $this->assertArrayNotHasKey('flash_error', $_SESSION,
            'a clean save must not raise an error banner');
    }

    public function test_a_slug_is_folded_so_the_model_cannot_mistype_it(): void
    {
        // The model gets this vocabulary in its prompt and every call is checked against it.
        // A slug carrying a space or a capital is one that will be typed back subtly
        // differently and silently dropped.
        $this->assertSame('how_far_it_reaches', S::slug('  How Far it Reaches!  '));
        $this->assertSame('', S::slug('---'));
    }
}
