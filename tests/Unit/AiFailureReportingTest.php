<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use Tests\TestCase;

/**
 * When AI stops working, the report has to name every provider that failed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `complete()` walks a failover chain, and it recorded the reason like this:
 *
 *     $this->lastError = $provider . '/' . $model . ': ' . $why;   // per hop
 *     …
 *     error_log('all providers failed — ' . $this->lastError);     // once, at the end
 *
 * One variable, overwritten by each hop. On this deployment two keys are
 * configured, so a Groq failure followed by a Gemini failure left ONLY the Gemini
 * reason behind — in the error log and in the admin "Test AI now" button alike.
 *
 * That is not a cosmetic loss. The two hops fail for unrelated reasons and want
 * opposite responses:
 *
 *   • `HTTP 0` at Groq — the request never left the host. An egress firewall or
 *     DNS. No key change fixes it.
 *   • `HTTP 401` at Gemini — the key is genuinely rejected. Rotate it.
 *
 * Reporting only the second one invites somebody to rotate a Gemini key and
 * declare it fixed, while the hop that runs FIRST on every single request — and
 * therefore accounts for the whole latency budget and the whole failure — is never
 * even mentioned. Gee was reported from production as "never able to respond", and
 * the one artefact that could have explained why was showing a single cause for
 * two faults.
 *
 * ── WHY THIS IS THE ONLY WAY TO SEE IT ───────────────────────────────────────
 *
 * There is no SSH on this account. The provider's own refusal text reaches an
 * operator through exactly one path: `selfTest()` → the admin flash message. So the
 * assertions below are about that text, not about internal state.
 */
final class AiFailureReportingTest extends TestCase
{
    /**
     * An AiService whose single network call fails with a scripted HTTP status
     * per provider, exactly as {@see AiService::httpPost()} would record it.
     *
     * @param array<string,string> $failures provider => body text httpPost would set
     */
    private function failing(array $failures, array $keys = ['groq' => 'k1', 'gemini' => 'k2']): AiService
    {
        return new class ($failures, $keys) extends AiService {
            /** @var list<string> */
            public array $attempted = [];

            public function __construct(private array $failures, array $keys)
            {
                parent::__construct(
                    groqKey:   $keys['groq']   ?? null,
                    geminiKey: $keys['gemini'] ?? null,
                );
            }

            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $provider = match (true) {
                    str_contains($url, 'api.groq.com')       => 'groq',
                    str_contains($url, 'generativelanguage') => 'gemini',
                    str_contains($url, 'api.anthropic.com')  => 'anthropic',
                    str_contains($url, 'api.openai.com')     => 'openai',
                    default                                  => 'unknown',
                };
                $this->attempted[] = $provider;

                if (isset($this->failures[$provider])) {
                    // Precisely what the real httpPost() writes on a non-200.
                    $this->lastError = $this->failures[$provider];
                    return null;
                }
                return match ($provider) {
                    'gemini' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]],
                    default  => ['choices' => [['message' => ['content' => 'ok']]]],
                };
            }
        };
    }

    // ══ the regression itself ════════════════════════════════════════════════

    /**
     * THE BUG. Both hops failed; both must be in the report.
     *
     * Asserted on the operator-facing string rather than on `hopErrors()`, because
     * an array that holds both while the message shows one is the bug intact.
     */
    public function test_every_failed_provider_is_named_not_just_the_last(): void
    {
        $ai = $this->failing([
            'groq'   => 'HTTP 0 (Could not resolve host: api.groq.com)',
            'gemini' => 'HTTP 401 {"error":{"message":"API key not valid"}}',
        ]);

        $r = $ai->selfTest();

        $this->assertFalse($r['ok']);
        $this->assertCount(2, $r['hops'], 'Both hops were tried, so both are accountable.');

        $this->assertStringContainsString('groq', $r['error']);
        $this->assertStringContainsString('gemini', $r['error'],
            'The last hop must not be the only one reported.');

        // And the PROVIDERS' OWN WORDS, which is the only evidence there is.
        $this->assertStringContainsString('Could not resolve host', $r['error'],
            'Losing the first hop\'s text is what sent somebody to rotate the wrong key.');
        $this->assertStringContainsString('API key not valid', $r['error']);
    }

    /**
     * A hop that fails must not inherit the previous hop's reason.
     *
     * `lastError` is cleared before each attempt. Without that, a provider whose
     * failure sets nothing would be reported carrying the text of the one before
     * it — a false attribution, which is worse than a vague one because it reads
     * as evidence.
     */
    public function test_a_hop_never_inherits_the_previous_hops_reason(): void
    {
        // Groq fails with a real HTTP error; Gemini "succeeds" at HTTP level but
        // returns a shape yielding no text, so its own reason is the generic one.
        $ai = new class extends AiService {
            public function __construct() { parent::__construct(groqKey: 'k1', geminiKey: 'k2'); }
            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                if (str_contains($url, 'api.groq.com')) {
                    $this->lastError = 'HTTP 429 {"error":"rate limit"}';
                    return null;
                }
                return ['candidates' => []];   // 200, nothing usable, no lastError
            }
        };

        $r = $ai->selfTest();
        $hops = $r['hops'];

        $this->assertSame('groq', $hops[0]['provider']);
        $this->assertStringContainsString('429', $hops[0]['error']);

        $this->assertSame('gemini', $hops[1]['provider']);
        $this->assertStringNotContainsString('429', $hops[1]['error'],
            'Gemini did not rate-limit anything — that text belongs to the hop before it.');
    }

    /** A provider that answers is still reported as the one that answered. */
    public function test_a_working_fallback_is_reported_as_the_answer(): void
    {
        $r = $this->failing(['groq' => 'HTTP 500 upstream'])->selfTest();

        $this->assertTrue($r['ok']);
        $this->assertSame('gemini', $r['provider'], 'Name what worked, not what was preferred.');
        $this->assertNull($r['error']);
        $this->assertNull($r['cause']);
    }

    // ══ turning a status code into something actionable ══════════════════════

    /**
     * HTTP 0 must NOT be described as a key problem.
     *
     * The most consequential mapping in the file. `HTTP 0` is the request never
     * arriving — on shared hosting, the account's own outbound firewall. It is the
     * failure most easily misread as an expired key, and the misreading costs a
     * key rotation, a redeploy, and no improvement.
     */
    public function test_an_unreachable_provider_is_not_blamed_on_the_key(): void
    {
        $r = $this->failing(['groq' => 'HTTP 0 (Connection timed out)',
                             'gemini' => 'HTTP 0 (Connection timed out)'])->selfTest();

        $this->assertNotNull($r['cause']);
        $this->assertStringContainsString('never reached the provider', $r['cause']);
        $this->assertStringContainsString('Rotating the key will not help', $r['cause']);
    }

    public function test_a_rejected_key_says_to_replace_the_key(): void
    {
        $cause = (string) AiService::likelyCause([
            ['provider' => 'groq', 'model' => 'm', 'error' => 'HTTP 401 {"error":"Invalid API Key"}'],
        ]);

        $this->assertStringContainsString('rejected', $cause);
        $this->assertStringContainsString('Issue a new one', $cause);
    }

    public function test_a_spent_quota_is_not_confused_with_a_bad_key(): void
    {
        $cause = (string) AiService::likelyCause([
            ['provider' => 'groq', 'model' => 'm', 'error' => 'HTTP 429 rate_limit_exceeded'],
        ]);

        $this->assertStringContainsString('quota', $cause);
        $this->assertStringNotContainsString('Issue a new one', $cause,
            'Being out of quota is not a reason to rotate a working key.');
    }

    /**
     * A decommissioned model has to be named, because the fix is to change THAT.
     *
     * Providers retire model ids on their own schedule; a pinned model that was
     * correct at deploy time becomes a 404 later with no action on our side. Naming
     * the model string in the message is the difference between a one-field edit and
     * an afternoon.
     */
    public function test_a_retired_model_is_named_in_the_advice(): void
    {
        $cause = (string) AiService::likelyCause([
            ['provider' => 'groq', 'model' => 'llama-3.1-8b-instant',
             'error' => 'HTTP 404 {"error":{"message":"model does not exist"}}'],
        ]);

        $this->assertStringContainsString('llama-3.1-8b-instant', $cause);
        $this->assertStringContainsString('decommissioned', $cause);
    }

    /** Provider-side 5xx tells the operator to change nothing. */
    public function test_a_provider_outage_advises_no_change(): void
    {
        $cause = (string) AiService::likelyCause([
            ['provider' => 'gemini', 'model' => 'm', 'error' => 'HTTP 503 backend overloaded'],
        ]);

        $this->assertStringContainsString('Nothing to change', $cause);
    }

    /** No keys at all is a distinct answer, and blank must count as absent. */
    public function test_no_key_configured_says_so_plainly(): void
    {
        $r = (new AiService())->selfTest();

        $this->assertFalse($r['ok']);
        $this->assertSame([], $r['hops'], 'Nothing was tried, so no hop is to blame.');
        $this->assertStringContainsString('No provider key configured', $r['error']);
        $this->assertStringContainsString('blank counts as unset', (string) $r['cause'],
            'A key present but empty in .env reads as unconfigured, and that trips people up.');
    }

    /** Two providers failing the same way is said once, not twice. */
    public function test_identical_causes_are_not_repeated(): void
    {
        $cause = (string) AiService::likelyCause([
            ['provider' => 'groq', 'model' => 'a', 'error' => 'HTTP 500'],
            ['provider' => 'groq', 'model' => 'b', 'error' => 'HTTP 500'],
        ]);

        $this->assertSame(1, substr_count($cause, 'Nothing to change'));
    }

    /** An unrecognised failure yields no invented cause — the raw text still shows. */
    public function test_an_unrecognised_failure_invents_nothing(): void
    {
        $this->assertNull(AiService::likelyCause([
            ['provider' => 'groq', 'model' => 'm', 'error' => 'something nobody has seen before'],
        ]));
    }

    /** One description, shared by the log and the admin screen so they cannot drift. */
    public function test_the_log_and_the_admin_screen_read_the_same_line(): void
    {
        $hops = [
            ['provider' => 'groq',   'model' => 'llama', 'error' => 'HTTP 0'],
            ['provider' => 'gemini', 'model' => 'flash', 'error' => 'HTTP 401'],
        ];

        $line = AiService::describeHops($hops);

        $this->assertStringContainsString('groq/llama → HTTP 0', $line);
        $this->assertStringContainsString('gemini/flash → HTTP 401', $line);
    }
    // ══ and the same test, one provider at a time ════════════════════════════

    /**
     * THE SECOND BUG, and the reason `probeAll()` exists.
     *
     * `selfTest()` walks the ladder and stops at the first provider that answers — which is
     * what the platform genuinely does, and exactly the wrong instrument for "is Gemini
     * working?". With a healthy Groq at the top, Gemini is NEVER CALLED and the console
     * reports a green tick over a fallback that has been dead for months.
     *
     * Reported as "Gemini is not operational" with nothing in the admin console able to
     * confirm or deny it. That is the shape of the fault: it has no symptom until the day
     * the primary goes down, which is the day the fallback was the whole point.
     */
    public function test_the_ladder_test_cannot_see_a_broken_fallback(): void
    {
        $ai = $this->failing(['gemini' => 'HTTP 404 {"error":{"message":"model not found"}}']);

        $ladder = $ai->selfTest();
        $this->assertTrue($ladder['ok'], 'groq answered, so the ladder is satisfied');
        $this->assertSame([], $ladder['hops'], 'gemini was never even tried');

        // The per-provider probe stands on every rung.
        $probe = array_column($ai->probeAll(), null, 'provider');

        $this->assertTrue($probe['groq']['ok']);
        $this->assertFalse($probe['gemini']['ok'], 'the broken fallback is still invisible');
        $this->assertStringContainsString('model not found', (string) $probe['gemini']['error'],
            "the provider's own words are the only evidence an operator has");
        $this->assertStringContainsString('decommissioned', (string) $probe['gemini']['cause']);
    }

    /**
     * Each row is that provider's OWN verdict, not the ladder's winner wearing its name.
     *
     * `resolveRoute()` appends every other configured provider after the declared hop and
     * then reorders to drop open-circuit ones. Without `maxAttempts = 1` and a breaker reset
     * per iteration, probing a failing Gemini would fall through to Groq and report Groq's
     * success in the Gemini row — a green tick on the exact thing being investigated.
     */
    public function test_a_probe_row_never_reports_another_providers_answer(): void
    {
        $ai = $this->failing([
            // HTTP 0 is the one that trips the breaker, which is what makes this the
            // ordering trap rather than a hypothetical one.
            'gemini' => 'HTTP 0 (Could not resolve host: generativelanguage.googleapis.com)',
        ]);

        $probe = array_column($ai->probeAll(), null, 'provider');

        $this->assertFalse($probe['gemini']['ok']);
        $this->assertStringContainsString('Could not resolve host', (string) $probe['gemini']['error']);
        $this->assertStringContainsString('egress firewall', (string) $probe['gemini']['cause'],
            'rotating the key will not fix a request that never left the host');

        // And the breaker gemini just tripped must not steal groq's row either.
        $this->assertTrue($probe['groq']['ok']);
    }

    /**
     * A provider with no key is reported as such, not omitted.
     *
     * "Not configured" and "configured and broken" are different problems with different
     * fixes, and an operator cannot tell them apart from an absence.
     */
    public function test_a_provider_with_no_key_is_reported_rather_than_left_out(): void
    {
        $probe = array_column($this->failing([])->probeAll(), null, 'provider');

        $this->assertCount(4, $probe, 'every provider is accounted for');
        $this->assertFalse($probe['anthropic']['configured']);
        $this->assertFalse($probe['anthropic']['ok']);
        $this->assertNull($probe['anthropic']['error'], 'there is no failure to quote');
        $this->assertStringContainsString('No key', (string) $probe['anthropic']['cause']);
    }
}
