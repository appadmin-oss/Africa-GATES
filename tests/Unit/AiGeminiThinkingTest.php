<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use Tests\TestCase;

/**
 * The free tier, and the reason it did not work at all.
 *
 * ── THE FAULT ────────────────────────────────────────────────────────────────
 *
 * `maxOutputTokens` on a Gemini flash model is the budget for THINKING PLUS ANSWER.
 * A reasoning model handed 70 of them spends all 70 reasoning and returns HTTP 200
 * carrying `finishReason: MAX_TOKENS` and no `parts` array — a success with nothing in
 * it. The provider method read `candidates[0].content.parts[0].text`, got null, and the
 * ladder reported "empty/failed response", which is also what it says when a socket
 * never opened.
 *
 * Six capabilities declare a budget under a hundred tokens because their answer is a
 * word or a small JSON object — `moderation.classify` at 80, which is every public
 * submission, and the judges' `questionnaire.chat` (90), `questionnaire.coach` (70) and
 * `interview.followup` (90). Against a thinking model those could not succeed, ever. So
 * "Gemini does not work" and "none of the AI works" were one fault, and it was this one.
 *
 * Held here on the WIRE — what the provider is sent and what it does with each reply —
 * because every symptom of this bug was visible only in a real inbox of a real free-tier
 * account, and none of it in the code.
 */
final class AiGeminiThinkingTest extends TestCase
{
    /**
     * A stub standing in for a REASONING model: it spends whatever ceiling it is given
     * and only answers once the ceiling leaves room to think.
     *
     * @param int $thinks how many tokens this model burns before it will answer
     */
    private function thinkingGemini(int $thinks): AiService
    {
        return new class ($thinks) extends AiService {
            /** @var list<int> the ceiling each attempt carried */
            public array $budgets = [];

            public function __construct(private int $thinks)
            {
                parent::__construct(geminiKey: 'AIza' . str_repeat('k', 35));
            }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $budget = (int) ($payload['generationConfig']['maxOutputTokens'] ?? 0);
                $this->budgets[] = $budget;

                if ($budget <= $this->thinks) {
                    // Exactly the shape the API returns: a candidate, a finish reason,
                    // and no parts at all.
                    return [
                        'candidates'    => [['finishReason' => 'MAX_TOKENS', 'content' => []]],
                        'usageMetadata' => ['promptTokenCount' => 40, 'thoughtsTokenCount' => $budget],
                    ];
                }

                return [
                    'candidates'    => [['finishReason' => 'STOP',
                                         'content' => ['parts' => [['text' => 'yes']]]]],
                    'usageMetadata' => ['promptTokenCount' => 40,
                                        'thoughtsTokenCount' => $this->thinks,
                                        'candidatesTokenCount' => 3],
                ];
            }
        };
    }

    // ══ the regression itself ════════════════════════════════════════════════

    /**
     * A capability whose answer is one word still gets room to think.
     *
     * 80 is `moderation.classify`, which sits on every public submission. Sent as 80 it
     * cannot come back with anything.
     */
    public function test_a_small_capability_budget_does_not_starve_the_model(): void
    {
        $ai = $this->thinkingGemini(thinks: 400);

        $out = $ai->complete('classify this', 'a message', 80);

        $this->assertSame('yes', $out,
            'an 80-token capability got 80 tokens of thinking room and therefore no answer — '
            . 'this is moderation.classify, on every public submission');
        $this->assertGreaterThanOrEqual(768, $ai->budgets[0],
            'the declared capability budget reached the wire unchanged');
    }

    /** And a model that wants more than the floor gets exactly one more go. */
    public function test_a_model_that_outgrows_the_floor_is_retried_once_and_only_once(): void
    {
        $ai = $this->thinkingGemini(thinks: 1200);

        $out = $ai->complete('classify this', 'a message', 90);

        $this->assertSame('yes', $out);
        $this->assertCount(2, $ai->budgets, 'expected one retry, no more');
        $this->assertGreaterThan($ai->budgets[0], $ai->budgets[1],
            'the retry carried the same ceiling, so it could only fail the same way');
    }

    /**
     * A 200 that carried nothing must say WHY.
     *
     * "empty/failed response" is what the ladder says when the socket never opened. An
     * operator reading that on the status page has no way to tell a starved budget from
     * an unreachable host, and the two have nothing in common to do about them.
     */
    public function test_an_empty_two_hundred_is_reported_as_what_it_actually_was(): void
    {
        $ai = new class extends AiService {
            public function __construct() { parent::__construct(geminiKey: 'AIza' . str_repeat('k', 35)); }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                return ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => []]]];
            }
        };

        $this->assertNull($ai->complete('s', 'u', 90));

        $err = implode(' | ', array_column($ai->hopErrors(), 'error'));
        $this->assertStringContainsString('MAX_TOKENS', $err,
            'the reason Gemini gave for the empty reply was thrown away');
        $this->assertStringNotContainsString('empty/failed response', $err,
            'a 200 with a stated finish reason is still being reported as a dead socket');
    }

    /** A blocked PROMPT is a different fault from a starved budget, and says so. */
    public function test_a_blocked_prompt_is_named_rather_than_called_empty(): void
    {
        $ai = new class extends AiService {
            public function __construct() { parent::__construct(geminiKey: 'AIza' . str_repeat('k', 35)); }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                return ['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []];
            }
        };

        $this->assertNull($ai->complete('s', 'u', 90));

        $err = implode(' | ', array_column($ai->hopErrors(), 'error'));
        $this->assertStringContainsString('SAFETY', $err);
        $this->assertStringContainsString('PROMPT', $err,
            'a blocked prompt and a withheld answer are different problems for the operator');
    }

    /**
     * A safety block is not retried.
     *
     * The retry exists for the one fault a retry can fix. A capability sitting on a
     * synchronous form POST cannot afford a second round trip to be told the same thing.
     */
    public function test_only_a_starved_budget_is_retried(): void
    {
        $ai = new class extends AiService {
            public int $calls = 0;
            public function __construct() { parent::__construct(geminiKey: 'AIza' . str_repeat('k', 35)); }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->calls++;
                return ['candidates' => [['finishReason' => 'SAFETY', 'content' => []]]];
            }
        };

        $ai->complete('s', 'u', 90);

        $this->assertSame(1, $ai->calls, 'a withheld answer was asked for twice');
    }

    // ══ the thinking control, and why it is gated ════════════════════════════

    /** @return array<string,mixed> the generationConfig one call put on the wire */
    private function configFor(?string $model): array
    {
        $ai = new class ($model) extends AiService {
            public array $cfg = [];
            public function __construct(?string $m)
            {
                parent::__construct(geminiKey: 'AIza' . str_repeat('k', 35), geminiModel: $m);
            }
            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->cfg = (array) ($payload['generationConfig'] ?? []);
                return ['candidates' => [['finishReason' => 'STOP',
                                          'content' => ['parts' => [['text' => 'ok']]]]]];
            }
        };
        $ai->complete('s', 'u', 90);

        return $ai->cfg;
    }

    /**
     * A Gemini 3 model is asked to think BRIEFLY.
     *
     * The floor stops a short capability starving; this stops it paying for reasoning it
     * does not need. On a free tier the reasoning IS the bill — a classifier returning one
     * JSON object does not benefit from four hundred tokens of deliberation.
     */
    public function test_a_gemini_three_model_is_asked_to_think_briefly(): void
    {
        $cfg = $this->configFor('gemini-3.6-flash');

        $this->assertSame('low', $cfg['thinkingConfig']['thinkingLevel'] ?? null);
        $this->assertArrayNotHasKey('thinkingBudget', (array) ($cfg['thinkingConfig'] ?? []),
            'thinkingBudget is the legacy control and may not be sent alongside thinkingLevel');
    }

    /**
     * And an older model is not, because an unknown field is a 400.
     *
     * An operator may name a 2.x model in Settings. Sending a parameter it does not know
     * would turn a working provider off with the setting meant to tune it — so those keep
     * the budget floor and the retry, which is what they had.
     */
    public function test_an_older_model_is_not_sent_a_field_it_would_reject(): void
    {
        $cfg = $this->configFor('gemini-2.5-flash');

        $this->assertArrayNotHasKey('thinkingConfig', $cfg);
        $this->assertGreaterThanOrEqual(768, (int) ($cfg['maxOutputTokens'] ?? 0),
            'an older model lost the floor as well as the control, so it has neither');
    }

    // ══ and the spend that was not being counted ═════════════════════════════

    /**
     * Reasoning tokens are billed and were not metered.
     *
     * `thoughtsTokenCount` is reported separately and is NOT part of
     * `candidatesTokenCount`. Counting only the latter meters a classifier that thought
     * for four hundred tokens and answered in three as three — so `tokens_per_day`, which
     * exists to protect exactly this free tier, was measuring the wrong number by an
     * order of magnitude on the capabilities whose answers are shortest.
     */
    public function test_reasoning_tokens_are_counted_as_the_spend_they_are(): void
    {
        $ai = $this->thinkingGemini(thinks: 400);

        $ai->complete('classify this', 'a message', 80);

        $u = $ai->lastUsage();
        $this->assertSame(403, $u['out'],
            'thinking tokens are missing from the meter — the daily cap cannot hold');
        $this->assertSame(40, $u['in']);
    }
}
