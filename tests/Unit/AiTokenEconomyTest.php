<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiGateway;
use AfricaGates\Services\AiService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Every AI feature: correct on the current models, and paying for as little as it can.
 *
 * What is held here, each with the fault it replaced:
 *   · a repeat of an identical classify/analyse request is answered from cache — no call, no
 *     budget, a CACHED row — while drafting and conversation never are;
 *   · Claude's one-shot path sends no temperature to a model that refuses it and reads the
 *     answer after the thinking block (both broke every capability on a current Claude);
 *   · OpenAI's reasoning models get the parameters they accept;
 *   · prompt-cache reads are accounted separately, so a budget counts real spend.
 */
final class AiTokenEconomyTest extends TestCase
{
    /** @param list<?array> $replies one per HTTP call */
    private function ai(array $replies, array $keys = ['anthropic' => 'k'], ?string $anthropicModel = null,
                        ?string $openaiModel = null): AiService
    {
        return new class ($replies, $keys, $anthropicModel, $openaiModel) extends AiService {
            public array $sent = [];
            public function __construct(private array $replies, array $k, ?string $am, ?string $om)
            {
                parent::__construct($k['groq'] ?? null, $k['gemini'] ?? null, $k['anthropic'] ?? null, $k['openai'] ?? null,
                                    null, null, $am, $om);
            }
            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->sent[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
                return array_shift($this->replies);
            }
        };
    }

    private static function claude(string $text, array $usage = ['input_tokens' => 100, 'output_tokens' => 10]): array
    {
        return ['stop_reason' => 'end_turn', 'usage' => $usage,
                'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'text', 'text' => $text]]];
    }

    public function test_a_repeated_classification_is_answered_from_cache_for_nothing(): void
    {
        $ai = $this->ai([self::claude('{"score":0.1,"reason":"clean"}')]);
        $in = ['system' => 'classify', 'user' => 'the same comment', 'json' => true];

        $first  = (new AiGateway($ai))->run('moderation.classify', $in);
        $second = (new AiGateway($ai))->run('moderation.classify', $in);

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);
        $this->assertSame($first->value, $second->value);
        $this->assertCount(1, $ai->sent, 'the second is never sent to a provider');
        $this->assertSame(1, (int) DB::table('gates_ai_calls')->where('outcome', AiGateway::CACHED)->count());
        $this->assertSame(1, AiGateway::spentToday('moderation.classify')['calls'], 'and counts against no budget');
        $report = AiGateway::spendReport()[0];
        $this->assertSame(1, $report['cached']);
        $this->assertSame(0, $report['failures'], 'a cache hit is not a failure');

        // A different comment is a different request.
        (new AiGateway($this->ai([self::claude('{}')])))->run('moderation.classify', ['user' => 'another'] + $in);
        $this->assertSame(1, (int) DB::table('gates_ai_calls')->where('outcome', AiGateway::CACHED)->count());
    }

    public function test_drafting_and_conversation_are_never_cached_and_fresh_skips_it(): void
    {
        foreach (['admin.award_wording', 'challenge.draft', 'guide.chat', 'support.answer', 'admin.assistant'] as $name) {
            $this->assertSame(0, AiCapability::find($name)->cacheTtl, $name . ' — pressing it again means "another one"');
        }
        $ai = $this->ai([self::claude('{"a":1}'), self::claude('{"a":2}')]);
        $in = ['system' => 's', 'user' => 'u', 'json' => true];
        (new AiGateway($ai))->run('moderation.classify', $in);
        $r = (new AiGateway($ai))->run('moderation.classify', $in + ['fresh' => true]);
        $this->assertSame('{"a":2}', $r->value);
        $this->assertCount(2, $ai->sent);
    }

    public function test_a_cached_answer_still_passes_the_schema(): void
    {
        $ai = $this->ai([self::claude('{"ok":true}')]);
        $in = ['system' => 's', 'user' => 'u', 'json' => true];
        (new AiGateway($ai))->run('moderation.classify', $in);
        $r = (new AiGateway($ai))->run('moderation.classify', $in + ['schema' => static fn (string $raw) => null]);
        $this->assertFalse($r->ok, 'a cached value a stricter caller rejects is not served');
    }

    public function test_the_claude_one_shot_path_works_on_a_current_model(): void
    {
        $ai = $this->ai([self::claude('hello')], ['anthropic' => 'k'], 'claude-opus-5-5');
        $this->assertSame('hello', $ai->complete('sys', 'user', 64));
        $p = $ai->sent[0]['payload'];
        $this->assertArrayNotHasKey('temperature', $p);
        $this->assertSame(['effort' => 'low'], $p['output_config']);
        $this->assertGreaterThanOrEqual(2048, $p['max_tokens'], 'thinking counts against it');
        $this->assertSame(['type' => 'ephemeral'], $p['cache_control']);
        $this->assertSame('default', $p['fallbacks']);

        $refused = $this->ai([['stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber'], 'content' => []]],
                             ['anthropic' => 'k'], 'claude-opus-5-5');
        $this->assertNull($refused->complete('sys', 'user'));
        $this->assertStringContainsString('declined', (string) $refused->hopErrors()[0]['error']);
    }

    public function test_an_older_claude_keeps_its_temperature(): void
    {
        $ai = $this->ai([self::claude('x')], ['anthropic' => 'k'], 'claude-haiku-4-5-20251001');
        $ai->complete('sys', 'user', 64, false, 0.2);
        $this->assertSame(0.2, $ai->sent[0]['payload']['temperature']);
        $this->assertArrayNotHasKey('output_config', $ai->sent[0]['payload']);
    }

    public function test_openai_reasoning_models_get_the_parameters_they_accept(): void
    {
        $ai = $this->ai([['choices' => [['message' => ['content' => 'ok']]]]], ['openai' => 'k'], null, 'gpt-5-mini');
        $this->assertSame('ok', $ai->complete('sys', 'user', 64));
        $p = $ai->sent[0]['payload'];
        $this->assertArrayNotHasKey('temperature', $p);
        $this->assertArrayNotHasKey('max_tokens', $p);
        $this->assertSame(1024, $p['max_completion_tokens']);
        $this->assertSame('low', $p['reasoning_effort']);

        $old = $this->ai([['choices' => [['message' => ['content' => 'ok']]]]], ['openai' => 'k'], null, 'gpt-4o-mini');
        $old->complete('sys', 'user', 64);
        $this->assertSame(64, $old->sent[0]['payload']['max_tokens']);
    }

    public function test_prompt_cache_reads_are_accounted_apart_from_full_rate_input(): void
    {
        $ai = $this->ai([self::claude('x', ['input_tokens' => 40, 'cache_creation_input_tokens' => 0,
                                            'cache_read_input_tokens' => 3000, 'output_tokens' => 12])]);
        $ai->complete('sys', 'user');
        $this->assertSame(['in' => 40, 'out' => 12, 'cached' => 3000], $ai->lastUsage());

        $oa = $this->ai([['choices' => [['message' => ['content' => 'ok']]],
                          'usage' => ['prompt_tokens' => 1500, 'completion_tokens' => 5, 'prompt_tokens_details' => ['cached_tokens' => 1200]]]],
                        ['openai' => 'k']);
        $oa->complete('sys', 'user');
        $this->assertSame(['in' => 300, 'out' => 5, 'cached' => 1200], $oa->lastUsage());
    }
}
