<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireInterview as I;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The nominee's interview screen, served.
 *
 * ── WHAT A RENDER TEST IS FOR HERE ───────────────────────────────────────────
 *
 * Not the layout. The promises. This page carries four sentences that are not decoration —
 * nothing costs money, nothing is sent until you send it, the AI does not write your answers,
 * and the form is always one tap away — and each one is there because leaving it out has a
 * named cost: a fee-demand impersonation that succeeds, a nominee who never presses submit
 * because they think they already did, a panel reading a machine's prose as a person's, and
 * somebody stuck in a conversation on the evening of a deadline.
 *
 * A template refactor can delete any of them silently. These tests are what notices.
 */
final class InterviewPageTest extends TestCase
{
    private const PROG = 98;
    private const CAT  = 9800;
    private const NOM  = 9801;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'P', 'slug' => 'p-9800']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9800, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9800, 'title' => 'C', 'slug' => 'c-9800']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada Nwosu',
            'status' => 'approved', 'vote_count' => 3]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9800, 'category_id' => self::CAT,
            'nominee_name' => 'Ada Nwosu', 'nominee_email' => 'ada@example.org',
            'country_code' => 'NG', 'reason' => 'Runs a seed bank.',
            'nominator_name' => 'K', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9801']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'],
                                                    ['value' => 'sk-test-not-a-real-key']);

        S::saveConfig(self::PROG, ['style' => S::INTERVIEW, 'brief' => 'Ask about the seed bank.']);
        S::saveOutcome(self::PROG, null, ['slug' => 'scale', 'label' => 'How far it reaches',
            'description' => 'A figure with a source.', 'required' => true]);
        S::forget();
    }

    private function get(string $path): string
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        $res = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));

        // Whitespace collapsed, because these tests assert SENTENCES and a template wraps them
        // across lines. Matching the source's line breaks would mean a reflow — a change with
        // no effect on anybody — failing a test about a promise to a nominee.
        return (string) preg_replace('/\s+/', ' ', (string) $res->getBody());
    }

    private function open(): string
    {
        return (string) Q::open(self::NOM)['token'];
    }

    public function test_the_interview_screen_is_served_and_not_the_form(): void
    {
        $html = $this->get('/my-work/' . $this->open());
        $this->assertStringContainsString('ivApp()', $html);
        $this->assertStringContainsString('Start the conversation', $html);
        // The form's own scope must not also be on the page — two Alpine roots over one
        // submission is how the two styles start disagreeing about what is saved.
        $this->assertStringNotContainsString('mw__chat', $html);
    }

    public function test_the_four_promises_are_on_the_page(): void
    {
        $html = $this->get('/my-work/' . $this->open());

        // A fee demand is the commonest way an awards scheme is impersonated, and this link
        // lands with exactly the people that is aimed at.
        $this->assertStringContainsString('Nothing about this costs money', $html);
        // Somebody who believes the conversation submitted for them never presses the button.
        $this->assertStringContainsString('Nothing reaches the judges', $html);
        // The provenance the whole record rests on.
        $this->assertStringContainsString('It never writes your answers for you', $html);
        // And the way out, as a real form post that survives the script failing.
        $this->assertStringContainsString('/interview/switch', $html);
        $this->assertStringContainsString('Fill in the form instead', $html);
    }

    public function test_the_form_escape_hatch_needs_no_javascript(): void
    {
        $html = $this->get('/my-work/' . $this->open());
        // A <form method="post"> with the token in it, not an @click. An escape hatch that
        // needs working JavaScript is not an escape hatch.
        $this->assertMatchesRegularExpression(
            '~<form[^>]+action="/my-work/[a-f0-9]{32}/interview/switch"~', $html);
    }

    public function test_the_model_cannot_reach_the_submit(): void
    {
        $token = $this->open();
        $s = Q::byToken($token);
        \AfricaGates\Services\QuestionnaireLedger::record($s, 'scale', 'met', 'Eight states',
            'we reach 4,000 farmers across eight states',
            [['role' => 'nominee', 'text' => 'we reach 4,000 farmers across eight states']]);
        I::setPhase($token, 'review');

        $html = $this->get('/my-work/' . $token);
        // The submit is a plain POST to the questionnaire's own submit path with a typed name.
        // There is no endpoint the interview can call that reaches it.
        $this->assertStringContainsString('Type your full name to send it', $html);
        $this->assertStringContainsString('name="declared_name"', $html);
        $this->assertStringContainsString('This is your act, not the interviewer', $html);
    }

    public function test_a_deployment_without_the_key_gets_the_form_instead(): void
    {
        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->update(['value' => '']);
        $html = $this->get('/my-work/' . $this->open());

        // Never stamped as an interview at all, so this nominee simply meets the form — no
        // dead end, no explanation needed, no half-feature.
        $this->assertStringNotContainsString('ivApp()', $html);
        $this->assertStringContainsString('mw__', $html);
    }

    public function test_an_interview_already_started_shows_the_degraded_screen_not_a_dead_end(): void
    {
        $token = $this->open();
        I::open($token);
        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->update(['value' => '']);

        $html = $this->get('/my-work/' . $token);
        $this->assertStringContainsString('ivApp()', $html);
        $this->assertStringContainsString('Nothing you said is lost', $html);
        $this->assertStringContainsString('Carry on in the form', $html);
    }

    public function test_a_submitted_interview_says_it_has_been_sent(): void
    {
        // Without this screen the page showed "Where we stopped" and a resume button into a
        // conversation that then refused every turn — which reads as the submission having
        // been lost, to somebody who has just finished describing their life's work.
        $token = $this->open();
        I::open($token);
        $s = Q::byToken($token);
        \AfricaGates\Services\QuestionnaireLedger::record($s, 'scale', 'met', 'x',
            'we reach 4,000 farmers across eight states',
            [['i' => 0, 'role' => 'nominee', 'text' => 'we reach 4,000 farmers across eight states']]);
        Q::submit($token, 'Ada Nwosu');

        $html = $this->get('/my-work/' . $token);
        $this->assertStringContainsString('This has been sent.', $html);
        $this->assertStringContainsString('Read what was sent', $html);
        // And the reassurance that matters most to somebody who has just handed something over.
        $this->assertStringContainsString('Nobody will ask you for money', $html);
    }

    public function test_the_composer_stops_a_paste_the_server_would_truncate(): void
    {
        // The server truncates at MAX_SAY_CHARS silently. Without a maxlength a nominee
        // pasting three thousand words loses the tail with nothing to tell them, and the
        // answer a panel reads stops mid-sentence.
        $html = $this->get('/my-work/' . $this->open());
        $this->assertStringContainsString(
            'maxlength="' . \AfricaGates\Services\QuestionnaireInterview::MAX_SAY_CHARS . '"',
            $html);
    }

    public function test_every_machine_derived_value_is_labelled_as_such(): void
    {
        $html = $this->get('/my-work/' . $this->open());
        $this->assertStringContainsString('machine-derived', $html);
        $this->assertStringContainsString('See it in the conversation', $html);
    }

    public function test_the_progress_rail_carries_no_percentage(): void
    {
        $html = $this->get('/my-work/' . $this->open());
        // The rail comes from the outcome ledger and can jump by three in one turn. A
        // percentage would be a promise about a finish line the conversation does not have.
        $this->assertStringNotContainsString("pct()", $html);
        $this->assertStringContainsString('A guide, not a score', $html);
    }
}
