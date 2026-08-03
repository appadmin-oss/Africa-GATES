<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use AfricaGates\Support\ProviderBreaker;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * An unreachable provider must be learned once, not rediscovered every call.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PRODUCTION FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Groq is pinned first for every AI feature. On this deployment the host cannot reach
 * api.groq.com at all — the admin health check reports `HTTP 0`, the request never
 * leaving the server. And `HTTP 0` was classified as transient, so every call ran:
 *
 *     Groq attempt 1 → 6s timeout · backoff 0.3s · Groq attempt 2 → 6s timeout
 *     …only then is Gemini contacted
 *
 * Twelve and a half seconds spent on a certainty before the provider that might
 * answer is tried, and a Gee turn makes more than one model call. The request ran out
 * of patience before any answer could exist. The failover chain was correct and never
 * got the time to run — which is why "Gee is never able to respond" was true even
 * where a second key was configured.
 *
 * Two halves, and this file pins both:
 *
 *   WITHIN a call   `HTTP 0` is no longer retried. A refused connection or an
 *                   unresolved name does not clear in 300ms.
 *   ACROSS calls    the provider is skipped for a few minutes.
 *
 * ── THE PROPERTY THAT MATTERS MOST ───────────────────────────────────────────
 *
 * The breaker may only ever REORDER attempts. If every provider is open-circuit, all
 * of them are tried anyway. A cache row must never be the thing that makes the
 * platform unable to think — that would be a worse outage than the one it prevents,
 * and harder to diagnose because nothing would be failing, only skipped.
 */
final class ProviderBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProviderBreaker::clearAll();
    }

    protected function tearDown(): void
    {
        ProviderBreaker::clearAll();
        parent::tearDown();
    }

    /**
     * A gateway that records every attempt and fails each with a scripted body.
     *
     * @param array<string,string> $failures provider => the text httpPost would set
     */
    private function ai(array $failures): AiService
    {
        return new class ($failures) extends AiService {
            /** @var list<string> every provider actually contacted, in order */
            public array $attempted = [];

            public function __construct(private array $failures)
            {
                parent::__construct(groqKey: 'k1', geminiKey: 'k2');
            }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $p = match (true) {
                    str_contains($url, 'api.groq.com')       => 'groq',
                    str_contains($url, 'generativelanguage') => 'gemini',
                    default                                  => 'other',
                };
                $this->attempted[] = $p;

                if (isset($this->failures[$p])) {
                    $this->lastError = $this->failures[$p];
                    return null;
                }
                return match ($p) {
                    'gemini' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]],
                    default  => ['choices' => [['message' => ['content' => 'ok']]]],
                };
            }
        };
    }

    // ══ the breaker itself ═══════════════════════════════════════════════════

    public function test_an_unreachable_provider_is_remembered(): void
    {
        $this->assertFalse(ProviderBreaker::isOpen('groq'));

        ProviderBreaker::open('groq');

        $this->assertTrue(ProviderBreaker::isOpen('groq'));
        $this->assertFalse(ProviderBreaker::isOpen('gemini'), 'One provider, not all of them.');
    }

    /** It lapses on its own, so a host that fixes its firewall recovers unattended. */
    public function test_the_breaker_expires(): void
    {
        ProviderBreaker::open('groq', 1);
        $this->assertTrue(ProviderBreaker::isOpen('groq'));

        // Age the stored row rather than sleeping — a test that waits is a test
        // somebody eventually deletes.
        DB::table('gates_cache')->where('cache_key', 'ai_breaker:groq')
            ->update(['payload' => (string) (time() - 5)]);
        (new \ReflectionClass(ProviderBreaker::class))->setStaticPropertyValue('memo', []);

        $this->assertFalse(ProviderBreaker::isOpen('groq'));
    }

    /** Only `HTTP 0` trips it. A provider that ANSWERS proves the path works. */
    public function test_only_a_connection_failure_counts_as_unreachable(): void
    {
        $this->assertTrue(ProviderBreaker::isUnreachable('HTTP 0 (Could not resolve host)'));
        $this->assertTrue(ProviderBreaker::isUnreachable('HTTP 0 (Connection timed out)'));

        foreach ([
            'HTTP 401 {"error":"Invalid API Key"}',
            'HTTP 429 rate_limit_exceeded',
            'HTTP 503 backend overloaded',
            'HTTP 404 model does not exist',
        ] as $answered) {
            $this->assertFalse(ProviderBreaker::isUnreachable($answered),
                "{$answered}: the provider replied, so the network path is fine — tripping "
                . 'here would take a feature down for minutes to save milliseconds.');
        }
    }

    /** A 500-with-a-zero-in-it must not be read as HTTP 0. */
    public function test_a_zero_inside_a_message_is_not_a_connection_failure(): void
    {
        $this->assertFalse(ProviderBreaker::isUnreachable('HTTP 500 {"error":"code 0 internal"}'));
    }

    // ══ wired into the chain ═════════════════════════════════════════════════

    /** THE FIX. A dead first hop is contacted once, then skipped. */
    public function test_a_dead_provider_is_not_contacted_again_on_the_next_call(): void
    {
        $ai = $this->ai(['groq' => 'HTTP 0 (Could not resolve host: api.groq.com)']);

        $this->assertSame('ok', $ai->complete('s', 'u'));
        $this->assertSame(['groq', 'gemini'], $ai->attempted, 'First call learns it the hard way.');

        $ai->attempted = [];
        $this->assertSame('ok', $ai->complete('s', 'u'));
        $this->assertSame(['gemini'], $ai->attempted,
            'Groq was tried again, so every call still pays a full timeout for a '
            . 'provider that cannot answer — which is what stopped Gee responding.');
    }

    /** A provider that merely ERRORS is still tried again — it is reachable. */
    public function test_a_provider_that_answers_badly_is_still_tried_again(): void
    {
        $ai = $this->ai(['groq' => 'HTTP 401 {"error":"Invalid API Key"}']);

        $ai->complete('s', 'u');
        $ai->attempted = [];
        $ai->complete('s', 'u');

        $this->assertSame(['groq', 'gemini'], $ai->attempted,
            'A 401 is the provider replying. Skipping it for five minutes would hide a '
            . 'key that an admin might fix in between.');
    }

    /**
     * THE SAFETY PROPERTY. Every provider open must not mean nothing is tried.
     *
     * The breaker is allowed to reorder attempts and nothing else. If a stale cache
     * could empty the chain, a transient network fault would leave AI switched off
     * with no error anywhere — worse than the delay it exists to prevent, and much
     * harder to find.
     */
    public function test_with_every_provider_open_the_chain_is_used_anyway(): void
    {
        ProviderBreaker::open('groq');
        ProviderBreaker::open('gemini');

        $ai = $this->ai([]);   // both would answer if asked
        $this->assertSame('ok', $ai->complete('s', 'u'));
        $this->assertNotSame([], $ai->attempted,
            'A cache row must never be the reason the platform cannot think.');
    }

    /** The health check ignores the breaker — it reports what happens NOW. */
    public function test_the_health_check_always_makes_real_attempts(): void
    {
        ProviderBreaker::open('groq');

        $ai = $this->ai(['groq' => 'HTTP 0 (Connection timed out)']);
        $r  = $ai->selfTest();

        $this->assertContains('groq', $ai->attempted,
            'Answering from a stale breaker would report a provider as failing seconds '
            . 'after the host fixed the firewall.');
        $this->assertTrue($r['ok'], 'Gemini still answered.');
    }

    // ══ and the retry that doubled the cost ═════════════════════════════════

    /**
     * `HTTP 0` must not be retried inside one call.
     *
     * ── WHY A BLACKHOLED ADDRESS AND NOT A CLOSED PORT ───────────────────────
     *
     * The first version of this test pointed at 127.0.0.1:1, and it PASSED with the
     * bug reintroduced. A closed port refuses instantly, so two attempts still finish
     * in milliseconds and there is nothing to measure. The cost being guarded here is
     * a TIMEOUT, so the test has to produce one: 10.255.255.1 is non-routable, packets
     * are dropped, and the attempt runs the clock out exactly as an egress firewall
     * does.
     *
     * Measured against the real thing: one attempt 3.05s, two attempts 6.33s at a 3s
     * timeout. In production that is 6s versus 12.3s, per call, on a provider that
     * cannot answer.
     */
    public function test_a_timed_out_connection_is_not_retried_within_one_call(): void
    {
        $svc = new class extends AiService {
            public function __construct() { parent::__construct(groqKey: 'k', timeout: 2); }
            public function probe(): ?array
            {
                return $this->httpPost('http://10.255.255.1:81/v1/chat', [], ['a' => 1]);
            }
            public function seenError(): string { return (string) $this->lastError; }
        };

        $started = microtime(true);
        $this->assertNull($svc->probe());
        $elapsed = microtime(true) - $started;

        $this->assertStringContainsString('HTTP 0', $svc->seenError());

        // If this environment refuses rather than drops, there is no timeout to
        // measure and the test would pass for the wrong reason. Say so instead.
        if ($elapsed < 1.5) {
            $this->markTestSkipped('10.255.255.1 did not blackhole here (' . round($elapsed, 2)
                . 's), so a retry would be invisible — nothing to assert.');
        }

        $this->assertLessThan(3.2, $elapsed,
            'Roughly double the timeout means the connection failure was retried. A '
            . 'refused connection or an unresolved name does not clear in 300ms, so the '
            . 'retry only ever buys a second full timeout on a certainty.');
    }
}
