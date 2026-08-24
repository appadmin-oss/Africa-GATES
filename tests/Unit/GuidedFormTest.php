<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\QuestionnaireService as Q;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The guided form, after the fixes.
 *
 * ── WHY THESE ARE TESTED AGAINST THE TEMPLATE ────────────────────────────────
 *
 * Each of these is a defect that was invisible from the server: a fixed height, an emoji, an
 * instruction about our storage model. Nothing rendered wrong, no exception was thrown, and no
 * existing test could have noticed any of them. They are asserted here because the only place
 * they exist is in that file, and the only way they come back is by somebody editing it.
 */
final class GuidedFormTest extends TestCase
{
    private function tpl(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/my-work.twig');
    }

    private function body(): string
    {
        // Everything below the comment header, so a defect NAMED in a comment does not read as
        // the defect still being present.
        $t = $this->tpl();
        return (string) preg_replace('~\{#.*?#\}|/\*.*?\*/~s', '', $t);
    }

    public function test_the_conversation_is_no_longer_read_through_a_letterbox(): void
    {
        // 58vh is about four exchanges on a phone. A nominee ten answers in was scrolling
        // their own conversation inside a window while the page around it stayed still.
        $this->assertStringNotContainsString('max-height:58vh', $this->body());
    }

    public function test_the_wizard_has_a_map(): void
    {
        // "Question 4 of 11" with only Back and Next means somebody who remembers a detail for
        // question 9 has to press Next five times to reach it and five times to come back — so
        // they do not, and the detail is lost.
        $b = $this->body();
        $this->assertStringContainsString('mw__map', $b);
        $this->assertStringContainsString('Jump to a question', $b);
        $this->assertStringContainsString('answered(', $b);
    }

    public function test_the_map_has_a_reactive_dependency_or_it_would_never_move(): void
    {
        // answered() reads the DOM, and Alpine cannot know a plain <textarea> changed. Without
        // touching a reactive value the map would render once and then say "unanswered" beside
        // a question somebody had just finished — worse than no map at all.
        $b = $this->body();
        $this->assertStringContainsString('typed++', $b);
        $this->assertMatchesRegularExpression('~this\.typed;~', $b);
    }

    public function test_attaching_a_file_no_longer_asks_anybody_to_save_first(): void
    {
        // An instruction about our storage model, on the screen where somebody is already
        // least sure they are doing it right.
        $this->assertStringNotContainsString('Save this page, then attach', $this->body());
        $this->assertStringContainsString('attach(w,', $this->body());
    }

    public function test_an_oversize_file_is_offered_the_route_that_works(): void
    {
        // Never a bare refusal: the size that was sent, the limit that applies, and the link
        // route — which a judge can follow just as well.
        $this->assertStringContainsString('Paste a link to it above instead', $this->body());
    }

    public function test_there_are_no_emoji_left_on_the_page(): void
    {
        // Several rendered as an empty box on the Android builds this page is most read on,
        // and an emoji is not a label — a screen reader announces "paperclip", which is not
        // what the row means.
        $b = $this->body();
        foreach (['📝', '🎤', '🎙', '🔊', '🔈', '📎', '✓', '🛍'] as $glyph) {
            $this->assertStringNotContainsString($glyph, $b, "an emoji survived: {$glyph}");
        }
    }

    public function test_the_drawn_glyphs_replaced_them(): void
    {
        $t = $this->tpl();
        foreach (['mw__clip', 'mw__spk', 'mw__micglyph'] as $cls) {
            $this->assertStringContainsString('.' . $cls . '{', $t, "no style for {$cls}");
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE THIRD DOOR IS CLOSED
    // ════════════════════════════════════════════════════════════════════════
    //
    // "Answer by chatting" was a guided, question-by-question chat over the same draft —
    // neither the live interview nor the form. Three doors onto one submission is not three
    // times the help: it is a choice nobody has the information to make, on the screen where
    // somebody is describing their life work. It is gone, and the form is that conversation
    // now.

    public function test_the_page_offers_no_chat_mode(): void
    {
        $b = $this->body();

        foreach (['Answer by chatting', "mode === 'chat'", 'mw__modes', 'offer_chat'] as $gone) {
            $this->assertStringNotContainsString($gone, $b,
                'the guided chat is still reachable from the page: ' . $gone);
        }
    }

    public function test_the_page_no_longer_posts_to_the_chat_endpoint(): void
    {
        $this->assertStringNotContainsString('/chat"', $this->body());
    }

    /** The interview is NOT the chat, and must survive its removal. */
    public function test_the_way_back_to_the_live_interview_is_still_there(): void
    {
        $b = $this->body();
        $this->assertStringContainsString('/interview/resume', $b);
        $this->assertStringContainsString('Go back to the conversation', $b);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE FORM IS SHAPED LIKE THE THING PEOPLE FINISH
    // ════════════════════════════════════════════════════════════════════════

    public function test_a_question_is_asked_rather_than_listed(): void
    {
        $b = $this->body();

        // The mark, the bubble and the composer: the interview screen, over the form fields.
        foreach (['mw__asked', 'mw__mark', 'mw__bubble', 'mw__answer'] as $part) {
            $this->assertStringContainsString($part, $b, 'missing ' . $part);
        }
    }

    /**
     * The fields must stay in the DOM at every step.
     *
     * The stepper hides them with x-show. A stepper that RENDERED only the current step
     * would make the no-script path — and any browser that failed to load Alpine — submit
     * one answer and silently drop ten.
     */
    public function test_every_field_stays_in_the_dom(): void
    {
        $b = $this->body();
        $this->assertStringContainsString('x-show="step ===', $b);
        $this->assertStringNotContainsString('x-if="step ===', $b,
            'x-if removes the field from the DOM, so the answer would never be posted');
    }

    /**
     * Voice had to survive the chat, because the person it exists for is now on the form.
     *
     * The microphone and the read-aloud lived inside the chat pane. Deleting the pane
     * without moving them would have taken spoken answers away from a nominee on a phone,
     * one who reads slowly, or one working in a third language — which is who they are for.
     */
    public function test_voice_moved_onto_the_form_rather_than_leaving_with_the_chat(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('mic(', $b, 'the microphone left with the chat');
        $this->assertStringContainsString('play(', $b, 'the read-aloud left with the chat');

        // Addressed by SLUG now, not by a turn index into a conversation that no longer
        // exists. Still resolved server-side against this submission own questions, so the
        // page still cannot ask the platform to speak arbitrary text.
        $this->assertStringContainsString('slug: slug', $b);
        $this->assertStringNotContainsString('turn: i', $b);
    }

    /** A dictated answer goes into the field it was dictated for, not into a global box. */
    public function test_dictation_targets_the_question_it_was_started_from(): void
    {
        $b = $this->body();
        $this->assertStringContainsString('this.dictating', $b);
        $this->assertStringContainsString('q-" + this.dictating', $b);
    }

    /**
     * The speaker button was 0x0 pixels.
     *
     * Two `.mw__spk` rules existed: a button, then a decorative CSS triangle with
     * width:0;height:0 declared afterwards. The second won the cascade, so every read-aloud
     * button on the page had no size at all.
     */
    public function test_the_speaker_button_is_not_collapsed_by_a_second_rule(): void
    {
        $css = $this->tpl();
        preg_match_all('~\.mw__spk\{~', $css, $m);
        $this->assertCount(1, $m[0],
            'two .mw__spk rules again — the later one decides the size of every speaker button');
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND IT ACTUALLY RENDERS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Everything above reads the template as text. This one renders it.
     *
     * The chat pane was removed by deleting a hundred lines out of the middle of a page that
     * shares one Alpine scope across every panel. A stray reference to a variable the
     * controller no longer passes would leave a Twig error, and nothing that reads the file
     * as a string can see one.
     */
    public function test_the_page_renders_through_the_real_controller(): void
    {
        $prog = 9700; $cycle = 9700; $cat = 9700; $nom = 9701;

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => $prog, 'title' => 'P', 'slug' => 'p-9700']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => $cycle, 'programme_id' => $prog, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => $cat, 'cycle_id' => $cycle, 'title' => 'C', 'slug' => 'c-9700']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => $nom, 'category_id' => $cat, 'name' => 'Grace Mensah',
            'status' => 'approved', 'vote_count' => 800]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => $nom, 'cycle_id' => $cycle, 'category_id' => $cat,
            'nominee_name' => 'Grace Mensah', 'nominee_email' => 'grace@example.org',
            'country_code' => 'NG', 'reason' => 'Runs a girls coding club.',
            'nominator_name' => 'Kofi', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9701']);
        foreach ([['impact', 'Impact'], ['reach', 'Reach']] as $i => [$slug, $label]) {
            DB::table('gates_judge_criteria')->insertOrIgnore([
                'id' => 9710 + $i, 'programme_id' => null, 'slug' => $slug, 'label' => $label,
                'description' => 'x', 'weight' => 50, 'sort_order' => $i + 1, 'is_active' => 1]);
        }

        $token = (string) Q::open($nom)['token'];

        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(\AfricaGates\Controllers\MyWorkController::class);

        $res = $ctrl->page(
            (new ServerRequestFactory())->createServerRequest('GET', '/my-work/' . $token),
            new Response(), ['token' => $token]);

        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();

        $this->assertStringContainsString('mw__asked', $html, 'the question is not being asked');
        $this->assertStringContainsString('mw__bubble', $html);
        $this->assertStringNotContainsString('Answer by chatting', $html);
        $this->assertStringNotContainsString('/chat"', $html);
    }

    public function test_the_alpine_scope_carries_no_apostrophe(): void
    {
        // The whole object is a single-quoted HTML attribute. ONE apostrophe truncates it and
        // every binding on the page silently stops existing — the page renders, nothing
        // throws, and the questionnaire is simply inert. It happened while these fixes were
        // being written, which is why this assertion exists rather than a comment.
        $t = $this->tpl();
        $at = strpos($t, "x-data='{");
        $this->assertNotFalse($at);
        $end = strpos($t, "'", $at + 9);
        $this->assertNotFalse($end);
        $body = substr($t, $at + 9, $end - $at - 9);
        $this->assertGreaterThan(5000, strlen($body),
            'the x-data attribute is truncated — something inside it closed the quote');
        $this->assertStringContainsString('declared_name', $t);
    }
}
