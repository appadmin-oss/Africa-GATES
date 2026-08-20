<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use AfricaGates\Services\QuestionnaireInterview as I;
use AfricaGates\Services\QuestionnaireLedger as L;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireStyle as S;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The live interview.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THING THAT MATTERS MOST HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A QUOTE MUST BE SOMETHING THE NOMINEE ACTUALLY SAID.
 *
 * Everything else in this feature is a convenience. That one check is what stops
 * `record_outcome` being a way to have a language model write somebody's award entry in their
 * name, and it is why a judging panel can read the result at all. So it is tested against
 * paraphrase, against invention, against the model quoting its own question back, and against
 * the two kinds of difference — whitespace and curly quotes — that must be forgiven or correct
 * quotes get rejected all day.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE REST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. THE NOMINEE'S WORDS ARE STORED BEFORE THE MODEL IS CALLED, so a provider that times
 *      out costs the reply and never the answer.
 *   2. THE MODEL CANNOT SUBMIT. propose_complete opens a screen and does nothing else, and it
 *      is refused outright while a required outcome is unmet.
 *   3. THE STYLE IS STAMPED AT OPEN TIME, so a mid-cycle switch cannot rewrite the rules
 *      under somebody halfway through.
 *   4. EVERY WAY THIS CAN STOP ENDS IN THE FORM, with what was already said carried across.
 */
final class QuestionnaireInterviewTest extends TestCase
{
    private const PROG = 9600;
    private const CAT  = 9600;
    private const NOM  = 9601;

    protected function setUp(): void
    {
        parent::setUp();
        S::forget();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'P', 'slug' => 'p-9600',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9600, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9600, 'title' => 'C', 'slug' => 'c-9600',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada Nwosu',
            'status' => 'approved', 'vote_count' => 10,
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9600, 'category_id' => self::CAT,
            'nominee_name' => 'Ada Nwosu', 'nominee_email' => 'ada@example.org',
            'country_code' => 'NG', 'reason' => 'Runs a seed bank.',
            'nominator_name' => 'Kofi', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9601',
        ]);
        DB::table('gates_judge_criteria')->insertOrIgnore([
            'id' => 9610, 'programme_id' => null, 'slug' => 'impact', 'label' => 'Impact',
            'description' => 'x', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1,
        ]);

        // A configured key, because QuestionnaireStyle::interviewPossible() is a question about
        // the DEPLOYMENT and answering it from an injected test double would test nothing. A
        // deployment with no tool-capable provider must degrade, and that is asserted below.
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_openai_key'],
                                                    ['value' => 'sk-test-not-a-real-key']);

        S::saveConfig(self::PROG, ['style' => S::INTERVIEW, 'brief' => 'Ask about the seed bank.']);
        foreach ([['scale', 'How far it reaches', 1], ['story', 'One person it changed', 0]] as $i => [$slug, $label, $req]) {
            S::saveOutcome(self::PROG, null, ['slug' => $slug, 'label' => $label,
                'description' => 'x', 'criterion_id' => 9610, 'required' => $req,
                'sort_order' => $i + 1]);
        }
        S::forget();
    }

    private function open(): array
    {
        $r = Q::open(self::NOM);
        return [(int) $r['id'], (string) $r['token']];
    }

    private function sub(string $token): object
    {
        return Q::byToken($token);
    }

    /**
     * An AiService that says exactly what a test wants, without a network call.
     *
     * Replies are a queue: one per model round, so a test can drive the two-round path (a
     * silent tool call followed by prose) as well as the ordinary one.
     */
    private function ai(array $replies): AiService
    {
        return new class ($replies) extends AiService {
            public int $calls = 0;
            public array $lastMessages = [];
            public function __construct(private array $queue)
            {
                parent::__construct(openaiKey: 'test-key');
            }
            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->calls++;
                $this->lastMessages = $payload['messages'] ?? [];
                $next = array_shift($this->queue);
                if ($next === null) return null;
                return ['choices' => [['finish_reason' => $next['calls'] ?? null ? 'tool_calls' : 'stop',
                    'message' => [
                        'content' => (string) ($next['text'] ?? ''),
                        'tool_calls' => array_map(static fn(array $c, int $i): array => [
                            'id' => 'c' . $i, 'type' => 'function',
                            'function' => ['name' => $c[0], 'arguments' => (string) json_encode($c[1])],
                        ], $next['calls'] ?? [], array_keys($next['calls'] ?? [])),
                    ]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
            }
        };
    }

    // ══ 1. the quote check ═══════════════════════════════════════════════════

    private function turns(): array
    {
        return [
            ['role' => 'interviewer', 'text' => 'We reached four thousand farmers, did we not?'],
            ['role' => 'nominee', 'text' => 'We started in 2019 and we now reach 4,000 farmers '
                                          . 'across eight states in the north.'],
        ];
    }

    public function test_a_real_quote_is_accepted_and_located(): void
    {
        $found = L::quoteFrom('we now reach 4,000 farmers across eight states', $this->turns());
        $this->assertNotNull($found);
        $this->assertSame(1, $found[1], 'the turn index must be the one the words are in');
    }

    public function test_a_paraphrase_is_refused(): void
    {
        // The single most important negative in this feature: "roughly what they said" is
        // exactly what a model produces when it is helping, and it is not evidence.
        $this->assertNull(L::quoteFrom('they reach around four thousand farmers', $this->turns()));
    }

    public function test_an_invented_quote_is_refused(): void
    {
        $this->assertNull(L::quoteFrom('We have won three national awards for this work.',
                                       $this->turns()));
    }

    public function test_the_model_cannot_quote_its_own_question_back(): void
    {
        // Filing the interviewer's words as the nominee's evidence would let the model put a
        // number in a nominee's mouth by asking about it.
        $this->assertNull(L::quoteFrom('We reached four thousand farmers, did we not?',
                                       $this->turns()));
    }

    public function test_curly_quotes_and_extra_spaces_are_forgiven(): void
    {
        $turns = [['role' => 'nominee',
                   'text' => "It's the women's cooperative  that keeps the register."]];
        // A model reproducing a straight apostrophe as a curly one is typography, not content.
        $this->assertNotNull(L::quoteFrom("It\u{2019}s the women\u{2019}s cooperative that keeps the register.",
                                          $turns));
    }

    public function test_a_lowered_first_letter_is_forgiven(): void
    {
        // A model quoting from mid-sentence routinely lowercases the opening letter. Refusing
        // that is an outcome silently not recorded and a conversation that appears not to be
        // listening — for a difference that cannot change what was said.
        $found = L::quoteFrom('we started in 2019 and we now reach 4,000 farmers', $this->turns());
        $this->assertNotNull($found);
        // And what is STORED is still the transcript's own capitalisation.
        $this->assertStringStartsWith('We started in 2019', $found[0]);
    }

    public function test_forgiving_case_still_refuses_a_changed_word(): void
    {
        $this->assertNull(L::quoteFrom('we now reach 5,000 farmers across eight states',
                                       $this->turns()));
    }

    public function test_a_two_word_quote_proves_nothing(): void
    {
        // "yes" appears in every transcript, so a short quote would let any outcome be
        // recorded from any conversation.
        $this->assertNull(L::quoteFrom('4,000', $this->turns()));
    }

    // ══ 2. recording ═════════════════════════════════════════════════════════

    public function test_recording_an_outcome_files_it_against_the_criterion(): void
    {
        [, $token] = $this->open();
        $s = $this->sub($token);

        $r = L::record($s, 'scale', 'met', 'Reaches 4,000 farmers in eight states',
                       'we now reach 4,000 farmers across eight states', $this->turns());
        $this->assertTrue($r['ok'], $r['reason']);

        $row = DB::table('gates_submission_outcomes')->where('submission_id', (int) $s->id)
            ->where('slug', 'scale')->first();
        $this->assertSame('met', (string) $row->status);
        // Without the criterion the interview produces a good transcript and nothing a
        // judge's rubric can score.
        $this->assertSame(9610, (int) $row->criterion_id);
        $this->assertSame(1, (int) $row->turn_index);
    }

    public function test_an_undeclared_slug_is_dropped_not_created(): void
    {
        [, $token] = $this->open();
        $s = $this->sub($token);

        $r = L::record($s, 'invented_outcome', 'met', 'x',
                       'we now reach 4,000 farmers across eight states', $this->turns());
        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_submission_outcomes')
            ->where('submission_id', (int) $s->id)->count());
    }

    public function test_a_nominees_correction_is_never_overwritten_by_the_model(): void
    {
        [, $token] = $this->open();
        $s = $this->sub($token);

        L::correct($s, 'scale', 'It is 3,200 farmers, not 4,000 — I misspoke.');
        $r = L::record($s, 'scale', 'met', 'x',
                       'we now reach 4,000 farmers across eight states', $this->turns());

        $this->assertFalse($r['ok'], 'the machine restored its own version over a correction');
        $row = DB::table('gates_submission_outcomes')->where('submission_id', (int) $s->id)
            ->where('slug', 'scale')->first();
        $this->assertStringContainsString('3,200', (string) $row->quote);
        $this->assertSame(1, (int) $row->edited_by_nominee);
    }

    public function test_progress_counts_outcomes_and_never_turns(): void
    {
        [, $token] = $this->open();
        $s = $this->sub($token);

        $this->assertFalse(L::progress($s)['ready']);
        L::record($s, 'scale', 'met', 'x',
                  'we now reach 4,000 farmers across eight states', $this->turns());

        $p = L::progress($this->sub($token));
        $this->assertSame(1, $p['met']);
        $this->assertSame(2, $p['total']);
        // 'story' is optional, so one required outcome met is enough to finish.
        $this->assertTrue($p['ready']);
    }

    // ══ 3. the turn ══════════════════════════════════════════════════════════

    public function test_the_answer_is_stored_even_when_every_provider_fails(): void
    {
        [, $token] = $this->open();
        I::open($token);

        $r = I::say($token, 'We reach 4,000 farmers across eight states.', $this->ai([]));

        $this->assertFalse($r['ok']);
        $this->assertSame('turn_failed', $r['degraded']);
        $texts = array_column($r['turns'], 'text');
        $this->assertContains('We reach 4,000 farmers across eight states.', $texts,
            'a dropped connection must cost the reply, never the answer');
    }

    public function test_one_answer_can_settle_several_outcomes_in_a_single_turn(): void
    {
        [, $token] = $this->open();
        I::open($token);

        $said = 'We reach 4,000 farmers across eight states, and one of them, Hauwa, '
              . 'sent her daughter to school on the seed money.';
        $ai = $this->ai([[
            'text'  => 'Thank you. Tell me about the register you keep.',
            'calls' => [
                ['record_outcome', ['slug' => 'scale', 'status' => 'met', 'summary' => 'Eight states',
                                    'quote' => 'We reach 4,000 farmers across eight states']],
                ['record_outcome', ['slug' => 'story', 'status' => 'met', 'summary' => 'Hauwa',
                                    'quote' => 'sent her daughter to school on the seed money']],
            ],
        ]]);

        $r = I::say($token, $said, $ai);
        $this->assertTrue($r['ok']);
        // The rail must be able to jump by two. A progress bar that could only move one step
        // per message is a progress bar measuring typing.
        $this->assertSame(2, $r['progress']['met']);
        $this->assertSame(1, $ai->calls, 'a turn that spoke needs no second call');
    }

    public function test_a_silent_tool_call_gets_one_more_round_rather_than_an_empty_bubble(): void
    {
        [, $token] = $this->open();
        I::open($token);

        $ai = $this->ai([
            ['text' => '', 'calls' => [['record_outcome', ['slug' => 'scale', 'status' => 'met',
                'summary' => 'x', 'quote' => 'We reach 4,000 farmers across eight states']]]],
            ['text' => 'Noted. Who keeps that register?'],
        ]);

        $r = I::say($token, 'We reach 4,000 farmers across eight states.', $ai);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $ai->calls);
        $this->assertSame('Noted. Who keeps that register?', end($r['turns'])['text']);
    }

    public function test_a_refused_call_is_told_back_on_the_next_turn(): void
    {
        [, $token] = $this->open();
        I::open($token);

        $ai = $this->ai([
            ['text' => 'Good.', 'calls' => [['record_outcome', ['slug' => 'scale', 'status' => 'met',
                'summary' => 'x', 'quote' => 'they help about four thousand people']]]],
            ['text' => 'Let me ask that differently.'],
        ]);

        I::say($token, 'We reach 4,000 farmers across eight states.', $ai);
        $this->assertSame(0, DB::table('gates_submission_outcomes')->count(),
            'a paraphrase must not reach the ledger');

        I::say($token, 'The register is kept by the cooperative secretary.', $ai);
        $system = implode("\n", array_column(array_filter($ai->lastMessages,
            static fn(array $m): bool => $m['role'] === 'system'), 'content'));
        $this->assertStringContainsString('rejected tool calls', $system,
            'the model was never told why its record did not land');
    }

    // ══ 4. it cannot submit ══════════════════════════════════════════════════

    public function test_propose_complete_is_refused_while_a_required_outcome_is_unmet(): void
    {
        [, $token] = $this->open();
        I::open($token);

        $ai = $this->ai([
            ['text' => 'I think we have everything.', 'calls' => [['propose_complete', []]]],
        ]);
        $r = I::say($token, 'That is all I have to say.', $ai);

        $this->assertFalse($r['proposed'], 'review opened with a required outcome still unmet');
        $this->assertSame('talk', $r['phase']);
    }

    public function test_propose_complete_opens_review_and_submits_nothing(): void
    {
        [$id, $token] = $this->open();
        I::open($token);

        $ai = $this->ai([
            ['text' => 'That is everything the panel needs.', 'calls' => [
                ['record_outcome', ['slug' => 'scale', 'status' => 'met', 'summary' => 'x',
                                    'quote' => 'We reach 4,000 farmers across eight states']],
                ['propose_complete', ['reason' => 'all required outcomes met']],
            ]],
        ]);
        $r = I::say($token, 'We reach 4,000 farmers across eight states.', $ai);

        $this->assertTrue($r['proposed']);
        // Into the files phase, not review: words with no evidence behind them is the
        // submission this step exists to prevent.
        $this->assertSame('show', $r['phase']);
        // And the thing that matters most: it is still a draft.
        $this->assertSame('draft', (string) DB::table('gates_nominee_submissions')
            ->where('id', $id)->value('status'));
    }

    // ══ 5. style, and every way out ══════════════════════════════════════════

    public function test_the_style_is_stamped_when_the_submission_opens(): void
    {
        [$id, $token] = $this->open();
        I::open($token);
        $this->assertSame('interview', (string) DB::table('gates_nominee_submissions')
            ->where('id', $id)->value('style'));

        // The administrator changes their mind mid-cycle.
        S::saveConfig(self::PROG, ['style' => S::FORM]);
        S::forget();

        $this->assertSame('interview', I::state($token)['style'],
            'a mid-cycle switch rewrote the rules under somebody already answering');
    }

    public function test_switching_to_the_form_carries_the_answers_across(): void
    {
        [$id, $token] = $this->open();
        I::open($token);

        $ai = $this->ai([['text' => 'Thank you.', 'calls' => [
            ['record_outcome', ['slug' => 'scale', 'status' => 'met', 'summary' => 'x',
                                'quote' => 'We reach 4,000 farmers across eight states']],
        ]]]);
        I::say($token, 'We reach 4,000 farmers across eight states.', $ai);

        $r = I::switchToForm($token);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['carried']);

        $answers = json_decode((string) DB::table('gates_nominee_submissions')
            ->where('id', $id)->value('answers_json'), true);
        // The nominee's own words, not the model's heading.
        $this->assertSame('We reach 4,000 farmers across eight states', $answers['scale']);
        $this->assertSame('form', (string) DB::table('gates_nominee_submissions')
            ->where('id', $id)->value('style'));
    }

    public function test_switching_never_overwrites_something_typed_into_the_form(): void
    {
        [$id, $token] = $this->open();
        I::open($token);
        DB::table('gates_nominee_submissions')->where('id', $id)
            ->update(['answers_json' => (string) json_encode(['scale' => 'I typed this myself.'])]);

        $s = $this->sub($token);
        L::record($s, 'scale', 'met', 'x',
                  'we now reach 4,000 farmers across eight states', $this->turns());
        I::switchToForm($token);

        $answers = json_decode((string) DB::table('gates_nominee_submissions')
            ->where('id', $id)->value('answers_json'), true);
        $this->assertSame('I typed this myself.', $answers['scale'],
            'the switch undid work the nominee had done in the form');
    }

    public function test_the_token_ceiling_stops_the_turn_and_names_the_way_out(): void
    {
        [$id, $token] = $this->open();
        I::open($token);
        S::saveConfig(self::PROG, ['token_ceiling' => 10_000]);
        S::forget();
        DB::table('gates_nominee_submissions')->where('id', $id)
            ->update(['ai_tokens_in' => 9_000, 'ai_tokens_out' => 2_000]);

        $r = I::say($token, 'Anything else?', $this->ai([['text' => 'should never be called']]));
        $this->assertFalse($r['ok']);
        $this->assertSame('ceiling', $r['degraded']);
        $this->assertStringContainsString('form', $r['message'],
            'a nominee must never reach a dead end without being told where to go');
    }

    public function test_without_the_openai_key_the_programme_falls_back_to_the_form(): void
    {
        // The interview is pinned to OpenAI because the quote check needs a model that copies
        // exactly. A deployment without that key must land in the guided form rather than in a
        // pleasant conversation that records nothing.
        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->update(['value' => '']);

        $this->assertFalse(S::interviewPossible(self::PROG));
        $this->assertSame('form', S::styleFor(self::PROG));
        // The programme's INTENT is unchanged — this is a degradation, not a setting the
        // outage silently rewrote.
        $this->assertSame('interview', S::styleFor(self::PROG, live: false));
    }

    public function test_a_key_for_some_other_provider_does_not_count(): void
    {
        // Gemini looks configured to every other AI feature here and cannot carry tool calls.
        // Opening the interview on it would waste a nominee's evening before a deadline.
        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->update(['value' => '']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_gemini_key'],
                                                    ['value' => 'gk-test']);
        $this->assertFalse(S::interviewPossible(self::PROG));
    }

    public function test_an_interview_already_under_way_says_why_rather_than_going_quiet(): void
    {
        [, $token] = $this->open();
        I::open($token);
        DB::table('gates_settings')->where('key_name', 'ai_openai_key')->update(['value' => '']);

        $st = I::state($token);
        $this->assertSame('interview', $st['style'], 'the stamp must survive the outage');
        $this->assertSame('no_ai', $st['degraded']);
        $this->assertStringContainsString('form', I::degradedMessage('no_ai'));
    }

    // ══ 6. into the judges' dossier ══════════════════════════════════════════

    private function evidence(): array
    {
        return DB::table('gates_nominee_evidence')->where('nominee_id', self::NOM)
            ->orderBy('sort_order')->get()->all();
    }

    private function recordScale(string $token): void
    {
        $s = $this->sub($token);
        L::record($s, 'scale', 'met', 'Reaches 4,000 farmers in eight states',
                  'we now reach 4,000 farmers across eight states', $this->turns());
    }

    public function test_an_interview_can_actually_be_submitted(): void
    {
        // The whole feature is worthless if this fails. submit() validates against the
        // QUESTION list, and a programme with its own outcome slugs has no question carrying
        // them — so before this branch existed, every interview submission was refused with a
        // list of things the nominee had never been asked.
        [, $token] = $this->open();
        I::open($token);
        $this->recordScale($token);

        $r = Q::submit($token, 'Ada Nwosu');
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame('submitted', (string) $this->sub($token)->status);
    }

    public function test_a_required_outcome_still_missing_blocks_the_send(): void
    {
        [, $token] = $this->open();
        I::open($token);

        $r = Q::submit($token, 'Ada Nwosu');
        $this->assertFalse($r['ok']);
        // Named by its label, so the nominee is told what is missing rather than a slug.
        $this->assertSame(['How far it reaches'], $r['missing']);
    }

    public function test_the_quotes_reach_a_judge_as_evidence_under_the_criterion(): void
    {
        [, $token] = $this->open();
        I::open($token);
        $this->recordScale($token);
        Q::submit($token, 'Ada Nwosu');

        $rows = $this->evidence();
        $this->assertCount(1, $rows);
        // The heading is the ADMINISTRATOR'S outcome label, never the model's summary — so
        // nothing a machine composed reaches a panel as though a person wrote it.
        $this->assertSame('How far it reaches', (string) $rows[0]->title);
        $this->assertSame('we now reach 4,000 farmers across eight states', (string) $rows[0]->body);
        $this->assertStringContainsString('own interview', (string) $rows[0]->source_label);
        // Never verified: a self-supplied claim is a claim, and the dossier shows a judge the
        // difference.
        $this->assertSame(0, (int) $rows[0]->verified);
        $this->assertSame('nominee_supplied', (string) $rows[0]->provenance);
    }

    public function test_a_correction_by_the_nominee_is_visible_to_the_panel(): void
    {
        [, $token] = $this->open();
        I::open($token);
        $this->recordScale($token);
        L::correct($this->sub($token), 'scale', 'It is 3,200 farmers — I misspoke.');
        Q::submit($token, 'Ada Nwosu');

        $row = $this->evidence()[0];
        $this->assertSame('It is 3,200 farmers — I misspoke.', (string) $row->body);
        $this->assertStringContainsString('corrected by the nominee', (string) $row->source_label);
    }

    public function test_the_answers_map_is_filled_so_every_existing_reader_still_works(): void
    {
        [$id, $token] = $this->open();
        I::open($token);
        $this->recordScale($token);
        Q::submit($token, 'Ada Nwosu');

        $answers = json_decode((string) DB::table('gates_nominee_submissions')
            ->where('id', $id)->value('answers_json'), true);
        // The nominee's words, not the model's heading.
        $this->assertSame('we now reach 4,000 farmers across eight states', $answers['scale']);
    }

    public function test_a_guided_form_submission_is_untouched_by_any_of_this(): void
    {
        S::saveConfig(self::PROG, ['style' => S::FORM]);
        S::forget();

        [$id, $token] = $this->open();
        I::open($token);
        $this->assertSame('form', (string) $this->sub($token)->style);

        $answers = [];
        foreach (Q::questionsFor($this->sub($token)) as $q) {
            if ((int) ($q['is_required'] ?? 0) === 1) {
                $answers[(string) $q['slug']] = 'A real answer with 4,000 in it, written out.';
            }
        }
        Q::saveDraft($token, $answers, []);
        $r = Q::submit($token, 'Ada Nwosu');

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $rows = $this->evidence();
        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('own questionnaire', (string) $rows[0]->source_label);
    }

    // ══ 7. the door closes when it is sent ═══════════════════════════════════

    public function test_the_ledger_refuses_every_change_once_it_has_been_sent(): void
    {
        // The token stays valid after submission on purpose, so a re-opened submission can be
        // finished with the original link. That made the ledger writable after the panel had
        // it: the quotes a judge would read could be rewritten, and the change landed the
        // moment an operator pressed "Rewrite these rows" — with nothing recording that it
        // had changed. saveDraft() and attachFile() have always refused; these did not.
        [, $token] = $this->open();
        I::open($token);
        $this->recordScale($token);
        Q::submit($token, 'Ada Nwosu');

        $sent = $this->sub($token);
        $this->assertSame('submitted', (string) $sent->status);

        $this->assertFalse(L::correct($sent, 'scale', 'Actually it was 40,000.')['ok']);
        $this->assertFalse(L::drop($sent, 'scale'));
        $this->assertFalse(L::record($sent, 'scale', 'met', 'x',
            'we now reach 4,000 farmers across eight states', $this->turns())['ok']);

        // And the words the panel is reading are the ones that were sent.
        $row = DB::table('gates_submission_outcomes')->where('submission_id', (int) $sent->id)
            ->where('slug', 'scale')->first();
        $this->assertSame('we now reach 4,000 farmers across eight states', (string) $row->quote);
    }

    public function test_reopening_makes_it_editable_again(): void
    {
        // Re-opening is the supported way to let somebody correct their own submission, and it
        // has to actually work — otherwise the fix above turns a recoverable mistake into a
        // support ticket.
        [$id, $token] = $this->open();
        I::open($token);
        $this->recordScale($token);
        Q::submit($token, 'Ada Nwosu');
        Q::reopen($id, 'Please add a source for the figure.');

        $this->assertTrue(L::correct($this->sub($token), 'scale', 'It is 3,200 — I misspoke.')['ok']);
    }

    public function test_an_interview_with_nothing_authored_still_has_outcomes(): void
    {
        // Switching a programme on before anybody opens the builder must produce a working
        // conversation, aimed at exactly the criteria the form was aimed at.
        DB::table('gates_questionnaire_outcomes')->delete();
        S::forget();

        $outcomes = S::outcomes(self::PROG);
        $this->assertNotEmpty($outcomes);
        $this->assertTrue($outcomes[0]['derived']);
        $this->assertContains('summary', array_column($outcomes, 'slug'));
    }
}
