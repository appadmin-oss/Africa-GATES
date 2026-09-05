<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use Tests\TestCase;

/**
 * The turn shape the live interview sits on.
 *
 * ── WHAT IS ACTUALLY AT RISK HERE ────────────────────────────────────────────
 *
 * The interview converges because the model can call `record_outcome` with a quote. If the
 * tool never reaches the provider, or the call never comes back out, the failure is SILENT and
 * looks like a well-behaved chatbot: the nominee has a pleasant conversation, the outcome
 * ledger stays empty, the progress rail never moves, and nothing in the log says why.
 *
 * So these tests assert the two translations in both directions, per provider dialect, and
 * they assert the failure modes are loud rather than empty.
 */
class AiChatToolsTest extends TestCase
{
    /** The one tool the interview cannot work without, in the neutral declaration shape. */
    private function tools(): array
    {
        return [[
            'name' => 'record_outcome',
            'description' => 'Record that an outcome has been evidenced.',
            'parameters' => ['type' => 'object', 'properties' => [
                'slug'  => ['type' => 'string'],
                'quote' => ['type' => 'string'],
            ], 'required' => ['slug', 'quote']],
        ]];
    }

    /**
     * An AiService that captures the payload and replies with whatever the test wants,
     * without a network call. Subclass, not a mock: `resolveRoute()`, `modelFor()` and the
     * whole payload assembly — the parts that can be wrong — stay real.
     */
    private function wire(array $keys, array $replies): AiService
    {
        return new class ($keys, $replies) extends AiService {
            /** @var list<array{url:string,payload:array}> */
            public array $sent = [];
            public function __construct(array $keys, private readonly array $replies)
            {
                parent::__construct(
                    groqKey:      $keys['groq']      ?? null,
                    geminiKey:    $keys['gemini']    ?? null,
                    anthropicKey: $keys['anthropic'] ?? null,
                    openaiKey:    $keys['openai']    ?? null,
                );
            }
            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->sent[] = ['url' => $url, 'payload' => $payload];
                $which = match (true) {
                    str_contains($url, 'api.groq.com')      => 'groq',
                    str_contains($url, 'api.anthropic.com') => 'anthropic',
                    str_contains($url, 'generativelanguage')=> 'gemini',
                    default                                 => 'openai',
                };
                return $this->replies[$which] ?? null;
            }
        };
    }

    // ── OpenAI dialect ───────────────────────────────────────────────────────

    public function test_a_tool_call_comes_back_as_structure_not_prose(): void
    {
        $ai = $this->wire(['openai' => 'k'], ['openai' => [
            'choices' => [['finish_reason' => 'tool_calls', 'message' => [
                'content' => 'Thank you — that is exactly what the panel needs.',
                'tool_calls' => [[
                    'id' => 'call_1', 'type' => 'function',
                    'function' => ['name' => 'record_outcome',
                                   'arguments' => '{"slug":"scale","quote":"We reached 4,000 farmers."}'],
                ]],
            ]]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 90],
        ]]);

        $r = $ai->chat([
            ['role' => 'system', 'content' => 'You are conducting an interview.'],
            ['role' => 'user',   'content' => 'We reached 4,000 farmers.'],
        ], ['tools' => $this->tools()]);

        $this->assertNotNull($r);
        $this->assertSame('Thank you — that is exactly what the panel needs.', $r->text);
        $this->assertTrue($r->hasTools());
        $this->assertSame('tools', $r->stopReason);

        $calls = $r->callsTo('record_outcome');
        $this->assertCount(1, $calls);
        // The arguments arrive as a JSON STRING on the wire and must be an array here —
        // a caller handed the raw string would validate the quote against nothing.
        $this->assertSame('scale', $calls[0]['arguments']['slug']);
        $this->assertSame('We reached 4,000 farmers.', $calls[0]['arguments']['quote']);
    }

    public function test_the_tools_reach_the_provider_in_its_own_wrapper(): void
    {
        $ai = $this->wire(['openai' => 'k'],
            ['openai' => ['choices' => [['message' => ['content' => 'ok']]]]]);
        $ai->chat([['role' => 'user', 'content' => 'hello']], ['tools' => $this->tools()]);

        $sent = $ai->sent[0]['payload'];
        // Unwrapped tools are the silent failure: the model never sees them and answers
        // in prose forever.
        $this->assertSame('function', $sent['tools'][0]['type']);
        $this->assertSame('record_outcome', $sent['tools'][0]['function']['name']);
        $this->assertSame('object', $sent['tools'][0]['function']['parameters']['type']);
    }

    public function test_usage_is_per_turn_because_the_ceiling_is_per_submission(): void
    {
        $ai = $this->wire(['openai' => 'k'], ['openai' => [
            'choices' => [['message' => ['content' => 'ok']]],
            'usage'   => ['prompt_tokens' => 2400, 'completion_tokens' => 140],
        ]]);
        $r = $ai->chat([['role' => 'user', 'content' => 'hello']]);
        $this->assertSame(2400, $r->usage['in']);
        $this->assertSame(140, $r->usage['out']);
        $this->assertSame(2540, $r->tokens());
    }

    public function test_an_assistant_turn_carrying_tool_calls_round_trips(): void
    {
        // The second turn of any tool conversation replays the first back to the provider.
        // If the replay is malformed the API rejects the whole request, so the interview
        // dies on turn two — after appearing to work perfectly on turn one.
        $ai = $this->wire(['openai' => 'k'],
            ['openai' => ['choices' => [['message' => ['content' => 'next question']]]]]);

        $ai->chat([
            ['role' => 'user', 'content' => 'We reached 4,000 farmers.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call_1', 'name' => 'record_outcome',
                 'arguments' => ['slug' => 'scale', 'quote' => 'We reached 4,000 farmers.']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'record_outcome',
             'content' => '{"ok":true}'],
        ], ['tools' => $this->tools()]);

        $msgs = $ai->sent[0]['payload']['messages'];
        $this->assertSame('assistant', $msgs[1]['role']);
        $this->assertSame('', $msgs[1]['content']);            // never null — OpenAI refuses null
        $this->assertSame('call_1', $msgs[1]['tool_calls'][0]['id']);
        // Arguments go back as a STRING, which is the asymmetry that makes this worth a test.
        $this->assertSame('{"slug":"scale","quote":"We reached 4,000 farmers."}',
                          $msgs[1]['tool_calls'][0]['function']['arguments']);
        $this->assertSame('tool', $msgs[2]['role']);
        $this->assertSame('call_1', $msgs[2]['tool_call_id']);
    }

    public function test_malformed_arguments_do_not_throw(): void
    {
        $ai = $this->wire(['openai' => 'k'], ['openai' => [
            'choices' => [['message' => ['content' => 'ok', 'tool_calls' => [[
                'id' => 'c1', 'function' => ['name' => 'record_outcome',
                                             'arguments' => '{"slug":"scale", oops'],
            ]]]]],
        ]]);
        $r = $ai->chat([['role' => 'user', 'content' => 'x']], ['tools' => $this->tools()]);
        $this->assertNotNull($r);
        // Empty rather than absent: the validation layer refuses it on its own terms, and
        // the prose is still worth showing.
        $this->assertSame([], $r->callsTo('record_outcome')[0]['arguments']);
    }

    public function test_a_fenced_arguments_payload_still_parses(): void
    {
        $ai = $this->wire(['openai' => 'k'], ['openai' => [
            'choices' => [['message' => ['content' => '', 'tool_calls' => [[
                'id' => 'c1', 'function' => ['name' => 'set_focus',
                    'arguments' => "```json\n{\"slug\":\"scale\"}\n```"],
            ]]]]],
        ]]);
        $r = $ai->chat([['role' => 'user', 'content' => 'x']], ['tools' => $this->tools()]);
        $this->assertSame('scale', $r->callsTo('set_focus')[0]['arguments']['slug']);
    }

    // ── Anthropic dialect ────────────────────────────────────────────────────

    public function test_anthropic_gets_its_system_prompt_hoisted_and_concatenated(): void
    {
        $ai = $this->wire(['anthropic' => 'k'],
            ['anthropic' => ['content' => [['type' => 'text', 'text' => 'ok']]]]);

        $ai->chat([
            ['role' => 'system', 'content' => 'THE BRIEF'],
            ['role' => 'system', 'content' => 'THE KNOWLEDGE BASE'],
            ['role' => 'system', 'content' => 'THE OUTCOMES'],
            ['role' => 'user',   'content' => 'hello'],
        ], ['tools' => $this->tools()]);

        $sent = $ai->sent[0]['payload'];
        // All three, not the last one: the interview's system side is authored in three
        // separate places and dropping any of them changes what the model is trying to do
        // with nothing to notice.
        $this->assertStringContainsString('THE BRIEF', $sent['system']);
        $this->assertStringContainsString('THE KNOWLEDGE BASE', $sent['system']);
        $this->assertStringContainsString('THE OUTCOMES', $sent['system']);
        $this->assertCount(1, $sent['messages']);
        $this->assertSame('input_schema', array_key_first(
            array_diff_key($sent['tools'][0], ['name' => 1, 'description' => 1])));
    }

    public function test_anthropic_tool_use_blocks_come_back_as_calls(): void
    {
        $ai = $this->wire(['anthropic' => 'k'], ['anthropic' => [
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => 'Noted.'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'record_outcome',
                 'input' => ['slug' => 'scale', 'quote' => 'four thousand farmers']],
            ],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 40],
        ]]);
        $r = $ai->chat([['role' => 'user', 'content' => 'x']], ['tools' => $this->tools()]);

        $this->assertSame('Noted.', $r->text);
        $this->assertSame('tools', $r->stopReason);
        $this->assertSame('scale', $r->callsTo('record_outcome')[0]['arguments']['slug']);
        $this->assertSame(940, $r->tokens());
    }

    public function test_consecutive_tool_results_merge_into_one_anthropic_turn(): void
    {
        // One good paragraph often settles three outcomes, so three results in a row is the
        // ordinary case. Anthropic refuses two user turns back to back, so an unmerged
        // replay 400s and the interview dies on the turn AFTER a productive one.
        $ai = $this->wire(['anthropic' => 'k'],
            ['anthropic' => ['content' => [['type' => 'text', 'text' => 'ok']]]]);

        $ai->chat([
            ['role' => 'user', 'content' => 'a long answer'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'a', 'name' => 'record_outcome', 'arguments' => ['slug' => 'one']],
                ['id' => 'b', 'name' => 'record_outcome', 'arguments' => ['slug' => 'two']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '{"ok":true}'],
            ['role' => 'tool', 'tool_call_id' => 'b', 'content' => '{"ok":true}'],
        ], ['tools' => $this->tools()]);

        $msgs = $ai->sent[0]['payload']['messages'];
        $this->assertCount(3, $msgs);
        $this->assertSame('user', $msgs[2]['role']);
        $this->assertCount(2, $msgs[2]['content']);
        $this->assertSame('tool_result', $msgs[2]['content'][0]['type']);
        $this->assertSame('b', $msgs[2]['content'][1]['tool_use_id']);
    }

    // ── Routing and failure ──────────────────────────────────────────────────

    public function test_a_provider_that_cannot_carry_tools_is_skipped_not_sent_a_toolless_request(): void
    {
        // Gemini sits ahead of OpenAI in the chain. Sending it a tool request without tools
        // would produce a friendly, useless conversation and an empty ledger.
        $ai = $this->wire(['gemini' => 'g', 'openai' => 'o'],
            ['openai' => ['choices' => [['message' => ['content' => 'ok']]]]]);

        $r = $ai->chat([['role' => 'user', 'content' => 'x']], ['tools' => $this->tools()]);

        $this->assertNotNull($r);
        $this->assertSame('openai', $r->provider);
        $this->assertCount(1, $ai->sent);
        $this->assertStringContainsString('api.openai.com', $ai->sent[0]['url']);
    }

    public function test_no_tool_capable_key_says_so_instead_of_returning_a_bare_null(): void
    {
        $ai = $this->wire(['gemini' => 'g'], []);
        $this->assertNull($ai->chat([['role' => 'user', 'content' => 'x']],
                                    ['tools' => $this->tools()]));
        $hops = $ai->hopErrors();
        $this->assertNotEmpty($hops);
        $this->assertStringContainsString('tool-capable', $hops[0]['error']);
    }

    public function test_a_turn_with_neither_prose_nor_a_tool_call_is_a_failure(): void
    {
        // An empty bubble that moves the conversation on is worse than an error: the nominee
        // has no idea whether they were heard.
        $ai = $this->wire(['openai' => 'k', 'anthropic' => 'a'], [
            'openai'    => ['choices' => [['message' => ['content' => '']]]],
            'anthropic' => ['content' => [['type' => 'text', 'text' => 'the fallback answered']]],
        ]);
        $r = $ai->chat([['role' => 'user', 'content' => 'x']], ['tools' => $this->tools()]);
        $this->assertSame('the fallback answered', $r->text);
        $this->assertSame('anthropic', $r->provider);
    }

    public function test_the_reply_names_the_provider_that_answered_not_the_preferred_one(): void
    {
        $ai = $this->wire(['groq' => 'g', 'openai' => 'o'],
            ['openai' => ['choices' => [['message' => ['content' => 'ok']]]]]);
        $r = $ai->chat([['role' => 'user', 'content' => 'x']], ['tools' => $this->tools()]);
        $this->assertSame('openai', $r->provider);
        $this->assertSame('groq', $ai->activeProvider());   // still the preferred one
    }
}
