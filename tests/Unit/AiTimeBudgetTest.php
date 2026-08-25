<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiGateway;
use AfricaGates\Services\AiService;
use AfricaGates\Support\ProviderBreaker;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * How long a call is allowed to take, and what it means when it takes longer.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PRODUCTION FAULT THESE TESTS PIN DOWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The public status page read "AI assistance · Slower than usual · 0% answering" for weeks.
 * Every writing and summary helper was failing, and none of the obvious causes was it: the
 * keys were valid, the models were current, the providers were up.
 *
 * `AiCapability` declares a timeout per capability — 4s for the classifier that sits on the
 * nomination submit, 20s for a thread summary, 30s for a judge's dossier map, 120s for the
 * document reader that uploads files before it reasons. **Nothing read the field.** Every
 * call ran on `AiService`'s 6s constructor default, so the fourteen capabilities declaring
 * more than six seconds were cut off mid-generation on every single request, then the chain
 * paid six more seconds a hop for two more hops. Slow, and never an answer — which is
 * precisely what the status page said, and precisely not what it was understood to mean.
 *
 * It was the same fault the model pin had before `route()` was passed to `complete()`:
 * declared as data, faithfully recorded in the audit log, absent from the wire. That is why
 * the first test here asserts the number reaches the ONE place that can prove it.
 *
 * The second half is the consequence nobody would have looked for. cURL reports an HTTP code
 * of 0 for a read timeout as well as for a connection that never opened, and
 * {@see ProviderBreaker} sidelines a provider for five minutes on the strength of that text.
 * So every one of those cut-off generations also filed a healthy, answering provider as
 * unreachable — the exact outcome the breaker's own docblock forbids.
 */
final class AiTimeBudgetTest extends TestCase
{
    /**
     * An AiService that answers, and records the time budget cURL would have been given.
     *
     * The budget is read off `$timeoutOverride` inside the intercepted call, because that is
     * where `httpPost()` reads it: asserting on anything earlier would prove the gateway
     * intended a timeout rather than that a request carried one.
     */
    private function recording(): AiService
    {
        return new class extends AiService {
            /** @var list<?int> the budget in force at each intercepted call */
            public array $budgets = [];

            public function __construct()
            {
                parent::__construct(groqKey: 'k');
            }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->budgets[] = $this->timeoutOverride;
                return ['choices' => [['message' => ['content' => '{"ok":true}']]]];
            }
        };
    }

    // ══ the regression itself ════════════════════════════════════════════════

    /**
     * THE BUG. A capability declaring 30 seconds must get 30 seconds.
     *
     * `judge.orientation` is the one to assert on: it is a judge waiting on a 700-token map
     * of a 14,000-character dossier, which is not a six-second job, and it is the capability
     * whose failure a judge reads as "the platform cannot summarise my panel's entries".
     */
    public function test_a_capabilitys_declared_timeout_reaches_the_request(): void
    {
        $ai  = $this->recording();
        $cap = AiCapability::find('judge.orientation');

        $this->assertNotNull($cap);
        $this->assertSame(30, $cap->timeout, 'the declaration this test is about');

        (new AiGateway($ai))->run('judge.orientation', ['system' => 's', 'user' => 'u']);

        $this->assertNotEmpty($ai->budgets, 'no provider call was made at all');
        $this->assertSame(30, $ai->budgets[0],
            'the capability declared 30s and the request was given the 6s default — the bug '
            . 'that read as "0% answering" on the status page');
    }

    /**
     * And a capability declaring LESS keeps less.
     *
     * `moderation.classify` sits on the synchronous nomination submit. Its 4s is a promise to
     * the person pressing the button, not a suggestion, and a fix that only ever raised the
     * ceiling would have broken that promise in the other direction.
     */
    public function test_a_short_declared_timeout_is_not_rounded_up(): void
    {
        $ai = $this->recording();

        (new AiGateway($ai))->run('moderation.classify', ['system' => 's', 'user' => 'u']);

        $this->assertSame(4, $ai->budgets[0] ?? null,
            'a capability on a synchronous form POST must not inherit a longer wait');
    }

    /**
     * A budget belongs to one call and must not outlive it.
     *
     * `AiService::boot()` results are reused within a request — the questionnaire makes
     * several calls behind one page load. A 120-second budget left set by the document reader
     * would then be inherited by whatever ran next, and the next thing might be a classifier
     * on a form POST.
     */
    public function test_a_time_budget_does_not_leak_into_the_next_call(): void
    {
        $ai = $this->recording();

        (new AiGateway($ai))->run('judge.orientation', ['system' => 's', 'user' => 'u']);
        // Straight to a raw completion, the way a legacy caller reaches AiService.
        $ai->complete('s', 'u');

        $this->assertSame([30, null], $ai->budgets,
            'the second call inherited the first one\'s budget');
    }

    // ══ reachable-but-slow is not unreachable ════════════════════════════════

    /**
     * A provider that connected and then ran out of time is NOT unreachable.
     *
     * The breaker exists for a fact that stays true for minutes — DNS, or this host's egress
     * firewall. A slow answer is not that fact, and skipping a working provider for five
     * minutes to save a few hundred milliseconds is the outcome the breaker was written to
     * avoid. Both cases used to produce the same "HTTP 0" text, so both tripped it.
     */
    public function test_a_read_timeout_is_not_treated_as_unreachable(): void
    {
        $this->assertFalse(
            ProviderBreaker::isUnreachable('TIMEOUT after 30s (connected, but no reply in time) '
                . '(Operation timed out after 30001 milliseconds)'),
            'a provider that answered the handshake has proved the network path'
        );
    }

    /** The fault the breaker is actually for still trips it. */
    public function test_a_connection_that_never_opened_is_still_unreachable(): void
    {
        $this->assertTrue(
            ProviderBreaker::isUnreachable('HTTP 0 (Could not resolve host: api.groq.com)'));
    }

    /**
     * And the breaker is not opened by a hop that timed out.
     *
     * Asserted through `complete()` rather than on `isUnreachable()` alone, because the bug
     * was the two of them together: the classifier was right about the text and the text was
     * wrong about the fault.
     */
    public function test_a_timed_out_hop_does_not_sideline_the_provider(): void
    {
        ProviderBreaker::clearAll();

        $ai = new class extends AiService {
            public function __construct() { parent::__construct(groqKey: 'k'); }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $this->lastError = 'TIMEOUT after 6s (connected, but no reply in time)';
                return null;
            }
        };

        $this->assertNull($ai->complete('s', 'u'));
        $this->assertFalse(ProviderBreaker::isOpen('groq'),
            'the next request must still try the provider that was merely slow');
    }

    /**
     * The operator is told to change the right thing.
     *
     * There is no shell on this account, so `likelyCause()` in a flash message is the whole
     * of the diagnosis somebody gets. Telling them a slow generation is a firewall problem
     * sends them to their host with a question the host cannot answer.
     */
    public function test_the_cause_for_a_timeout_names_the_timeout_and_not_the_firewall(): void
    {
        $cause = (string) AiService::likelyCause([
            ['provider' => 'groq', 'model' => 'llama-3.3-70b-versatile',
             'error' => 'TIMEOUT after 6s (connected, but no reply in time)'],
        ]);

        $this->assertStringContainsString('timeout', strtolower($cause));
        $this->assertStringNotContainsString('firewall', strtolower($cause));
        $this->assertStringNotContainsString('rotating the key', strtolower($cause));
    }

    // ══ the audit log's vocabulary ═══════════════════════════════════════════

    /**
     * `record()` writes the same word `run()` does, whatever case the caller used.
     *
     * The live interview calls `AiService::chat()` directly and accounts for itself through
     * `record()`, and it wrote `'ok'`. Both readers of this table ask `outcome = 'OK'`.
     * MySQL's collation matched it and SQLite's `=` did not, so every successful interview
     * turn counted as a success on production and a failure in every test and dev database —
     * a divergence that hides a real fault behind a green tick in exactly one environment.
     */
    public function test_a_recorded_outcome_is_normalised_to_the_logged_vocabulary(): void
    {
        DB::table('gates_ai_calls')->delete();

        AiGateway::record('questionnaire.interview', 'ok', ['tokens_in' => 5, 'tokens_out' => 5]);

        $this->assertSame('OK', (string) DB::table('gates_ai_calls')->value('outcome'));
    }

    /** The spend panel counts a lower-cased success as a success. */
    public function test_a_recorded_success_is_not_counted_as_a_failure(): void
    {
        DB::table('gates_ai_calls')->delete();

        AiGateway::record('questionnaire.interview', 'ok');

        $report = AiGateway::spendReport();
        $this->assertSame(1, $report[0]['calls'] ?? 0);
        $this->assertSame(0, $report[0]['failures'] ?? -1,
            'a working interview turn was being reported as a failed one');
    }
}
