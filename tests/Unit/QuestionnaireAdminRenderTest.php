<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\QuestionnairesController;
use AfricaGates\Services\QuestionnaireLedger as L;
use AfricaGates\Services\QuestionnaireRehearsal as R;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The three admin screens, rendered.
 *
 * ── WHY A RENDER TEST, WHEN THE LOGIC IS TESTED ELSEWHERE ────────────────────
 *
 * A Twig template is compiled at request time. An undefined variable, a filter that does not
 * exist, a `for` over something that is not iterable — none of these are visible to PHP's
 * linter, to the service tests, or to anything else in this suite. They surface as a 500 on an
 * operator's screen, and on this deployment the operator finds out from a blank page.
 *
 * These are new screens with no other coverage, so they get the cheapest test that would have
 * caught the whole class: render them with real data and assert the things a reader must find.
 */
final class QuestionnaireAdminRenderTest extends TestCase
{
    private const PROG = 9950;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'Seed Banks', 'slug' => 'seed-banks-9950']);
        DB::table('gates_judge_criteria')->insertOrIgnore([
            'id' => 9960, 'programme_id' => null, 'slug' => 'impact', 'label' => 'Impact',
            'description' => 'x', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1]);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'],
                                                    ['value' => 'sk-test-not-a-real-key']);
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'test-token';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token']);
        parent::tearDown();
    }

    private function ctrl(): QuestionnairesController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(QuestionnairesController::class);
    }

    /**
     * Render one action and hand back its HTML as a READER sees it.
     *
     * Entities decoded and whitespace collapsed, because neither is content. Twig's
     * `html_attr` escaper turns every space into `&#x20;` — correct, and it means a JSON seed
     * inside an attribute contains no literal spaces at all. Asserting against the raw bytes
     * would be asserting against the escaper.
     */
    private function render(string $method, array $args, string $verb = 'GET'): string
    {
        $req = (new ServerRequestFactory())->createServerRequest($verb, '/x');
        $res = $this->ctrl()->{$method}($req, (new ResponseFactory())->createResponse(), $args);
        $this->assertSame(200, $res->getStatusCode(),
            "{$method} redirected instead of rendering — it refused before reaching the template");
        $html = html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    // ══ the builder ══════════════════════════════════════════════════════════

    public function test_the_builder_renders_with_nothing_configured(): void
    {
        // The state every programme is in the first time somebody opens it. A screen that only
        // worked once rows existed would be a screen nobody could ever get past.
        $html = $this->render('questions', ['id' => self::PROG]);

        $this->assertStringContainsString('Guided form', $html);
        $this->assertStringContainsString('Live interview', $html);
        $this->assertStringContainsString('The brief', $html);
        // The outcomes are derived from the questions, and the screen says so rather than
        // showing an empty list that looks broken.
        $this->assertStringContainsString('derived from the questions', $html);
    }

    public function test_the_builder_renders_a_fully_configured_programme(): void
    {
        S::saveConfig(self::PROG, ['style' => S::INTERVIEW, 'brief' => 'Ask about the seed bank.']);
        S::saveKnowledge(self::PROG, null, 'What counts as evidence', 'A register, a ledger, a photograph.');
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'How far it reaches',
            'description' => 'A figure with a source.', 'criterion_id' => 9960, 'required' => true]);
        S::addRule(self::PROG, 'Do not ask for a figure more than once.', 'rehearsal', 'a turn', 1);
        S::forget();

        $html = $this->render('questions', ['id' => self::PROG]);
        $this->assertStringContainsString('Ask about the seed bank.', $html);
        $this->assertStringContainsString('What counts as evidence', $html);
        $this->assertStringContainsString('How far it reaches', $html);
        $this->assertStringContainsString('Do not ask for a figure more than once.', $html);
    }

    public function test_the_builder_says_so_when_the_interview_cannot_run(): void
    {
        // An operator configuring an interview that will never open needs to be told on the
        // screen where they are configuring it, not by a nominee.
        S::saveConfig(self::PROG, ['style' => S::INTERVIEW]);
        S::forget();
        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->update(['value' => '']);

        $html = $this->render('questions', ['id' => self::PROG]);
        $this->assertStringContainsString('cannot run', $html);
        $this->assertStringContainsString('ai_openai_key', $html);
    }

    public function test_the_branching_controls_are_actually_on_the_screen(): void
    {
        // The four columns that were readable and not writable. If the editor loses them
        // again, it will be by somebody deleting these fields.
        Q::seedDefaults(self::PROG);
        $html = $this->render('questions', ['id' => self::PROG]);

        foreach (['show_if_slug', 'show_if', 'min_words', 'wants_number'] as $field) {
            $this->assertStringContainsString("[' + i + '][{$field}]", $html,
                "the editor lost the {$field} control");
        }
    }

    /**
     * Two template-level assertions for defects that only exist in the browser.
     *
     * Both were found by driving the page in Chromium and neither is reachable from PHP: the
     * markup renders, nothing throws server-side, and the screen is simply broken in a way
     * that looks like nothing happened.
     */
    public function test_the_repeater_gives_every_row_a_fresh_key(): void
    {
        // Duplicating a question produced two rows with the same :key. Alpine's x-for tracks
        // its inserted nodes by key, two identical keys make that ambiguous, and the next
        // splice threw. The symptoms were that Copy did nothing and that reordering afterwards
        // DELETED a question — silently, from a list an operator was mid-way through editing.
        $t = (string) file_get_contents(dirname(__DIR__, 2)
            . '/templates/admin/questionnaires/questions.twig');
        $this->assertStringContainsString("Object.assign({ id: 0 }, r, { uid: 'r' + (++n) })", $t,
            'the fresh uid must be assigned LAST or a duplicated row inherits its source key');
    }

    public function test_no_alpine_loop_sits_inside_a_select_or_a_datalist(): void
    {
        // `<template>` is not permitted in either; the parser hoists it out before Alpine sees
        // it, x-for then inserts after a marker that has moved, and every card throws on load.
        // Comments stripped first. This file EXPLAINS the defect, so its prose contains the
        // literal words `<select>` and `<datalist>` — and a check that reads them as markup
        // fails on the note describing the bug it is guarding against.
        $t = (string) preg_replace('~\{#.*?#\}|<!--.*?-->~s', '',
            (string) file_get_contents(dirname(__DIR__, 2)
                . '/templates/admin/questionnaires/questions.twig'));

        foreach (['select', 'datalist'] as $el) {
            // Bounded to the element's OWN content. An unbounded `.*?` runs from any <select>
            // to any later <template> anywhere in the file, which matches a page that is
            // perfectly fine — the first version of this test failed on its own explanatory
            // comment.
            $this->assertDoesNotMatchRegularExpression(
                '~<' . $el . '\b[^>]*>(?:(?!</' . $el . '>).)*?<template\s+x-for~s', $t,
                "an x-for inside <{$el}> throws on every render");
        }
    }

    // ══ the rehearse pane ════════════════════════════════════════════════════

    public function test_the_rehearse_pane_renders_and_opens_a_real_test_submission(): void
    {
        $html = $this->render('rehearse', ['id' => self::PROG]);

        $this->assertStringContainsString('Answer as a nominee would', $html);
        $this->assertStringContainsString('Be somebody difficult', $html);
        // It posts to the NOMINEE'S endpoint. If this ever becomes an admin-only URL, the
        // rehearsal has stopped rehearsing the thing it stands for.
        $this->assertStringContainsString("'/my-work/' + TOKEN + '/interview'", $html);
        $this->assertSame(1, DB::table('gates_nominee_submissions')
            ->where('is_test', 1)->where('programme_id', self::PROG)->count());
    }

    public function test_the_rehearse_pane_carries_all_four_difficult_nominees(): void
    {
        $html = $this->render('rehearse', ['id' => self::PROG]);
        foreach (R::personas() as $p) {
            $this->assertStringContainsString($p['label'], $html);
        }
    }

    public function test_a_saved_case_and_its_last_result_are_shown(): void
    {
        DB::table('gates_questionnaire_cases')->insert([
            'programme_id' => self::PROG, 'title' => 'Three-word answers',
            'persona' => 'short', 'transcript_json' => '["Farming."]',
            'expect_json' => '["scale"]', 'last_result' => 'LOST: scale.',
            'last_run_at' => '2026-08-19 10:00:00']);

        $html = $this->render('rehearse', ['id' => self::PROG]);
        $this->assertStringContainsString('Three-word answers', $html);
        // A regression that lost ground is the only reading of this screen that matters.
        $this->assertStringContainsString('LOST: scale.', $html);
    }

    // ══ the submission view ══════════════════════════════════════════════════

    public function test_a_submitted_interview_shows_its_ledger_and_its_transcript(): void
    {
        S::saveConfig(self::PROG, ['style' => S::INTERVIEW]);
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'How far it reaches',
            'criterion_id' => 9960, 'required' => true]);
        S::forget();

        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 9951, 'category_id' => null, 'name' => 'Ada', 'status' => 'approved']);
        $id = (int) DB::table('gates_nominee_submissions')->insertGetId([
            'nominee_id' => 9951, 'programme_id' => self::PROG, 'cycle_id' => null,
            'invite_token' => str_repeat('a', 32), 'status' => 'draft',
            'style' => 'interview', 'interview_phase' => 'talk',
            'ai_tokens_in' => 4000, 'ai_tokens_out' => 900,
            'transcript_json' => (string) json_encode(['turns' => [
                ['role' => 'interviewer', 'text' => 'What is the work?'],
                ['role' => 'nominee', 'text' => 'We reach 4,000 farmers across eight states.'],
            ], 'notes' => [['text' => 'They mentioned a safeguarding concern.']], 'focus' => null]),
        ]);
        L::record((object) ['id' => $id, 'programme_id' => self::PROG], 'scale', 'met',
            'Eight states', 'We reach 4,000 farmers across eight states',
            [['i' => 1, 'role' => 'nominee', 'text' => 'We reach 4,000 farmers across eight states.']]);

        $html = $this->render('show', ['id' => $id]);

        $this->assertStringContainsString('What the interview recorded', $html);
        $this->assertStringContainsString('We reach 4,000 farmers across eight states', $html);
        // Machine-derived values are labelled wherever they appear, including here.
        $this->assertStringContainsString('Machine-derived heading', $html);
        $this->assertStringContainsString('from turn 2', $html);
        // Notes are for staff and are never presented as the nominee's own words.
        $this->assertStringContainsString('safeguarding concern', $html);
        $this->assertStringContainsString('4,900 tokens', $html);
        $this->assertStringContainsString('The conversation, in full', $html);
    }

    public function test_a_form_submission_shows_none_of_the_interview_panels(): void
    {
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 9952, 'category_id' => null, 'name' => 'Bola', 'status' => 'approved']);
        $id = (int) DB::table('gates_nominee_submissions')->insertGetId([
            'nominee_id' => 9952, 'programme_id' => self::PROG, 'cycle_id' => null,
            'invite_token' => str_repeat('b', 32), 'status' => 'draft', 'style' => 'form',
        ]);

        $html = $this->render('show', ['id' => $id]);
        $this->assertStringNotContainsString('What the interview recorded', $html);
        $this->assertStringNotContainsString('The conversation, in full', $html);
    }
}
