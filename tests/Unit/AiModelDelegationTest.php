<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiService;
use Tests\TestCase;

/**
 * Which model actually runs each feature.
 *
 * TWO DEFECTS, and the second is why the first mattered so little.
 *
 * 1. No delegation. All fourteen capabilities pinned a Groq llama model — ten on
 *    `llama-3.3-70b-versatile`, four on `llama-3.1-8b-instant`. That is one vendor
 *    in two sizes, chosen once and copied thirteen times.
 *
 * 2. The pin was never honoured. `AiCapability::$model` is documented "pinned.
 *    Never whatever key happens to be first" — and `AiGateway` read it into the
 *    audit log while `AiService::complete()` iterated `providerChain()`, an order
 *    determined solely by which API key existed. So the recorded model and the
 *    called model were independent values, and on any failover the log named a
 *    provider that had just failed.
 *
 * The tests below pin the route end to end: a declared pin becomes the first hop,
 * a missing key is skipped rather than attempted, a pin cannot make a feature
 * unavailable on a deployment that has other keys, and what answered is what gets
 * recorded.
 */
class AiModelDelegationTest extends TestCase
{
    /**
     * An AiService that records the route it was handed and reports which hop
     * "answered", without making a network call.
     *
     * A subclass rather than a mock: the resolution logic under test —
     * `resolveRoute()`, `modelFor()`, key filtering — is the real thing. Only the
     * HTTP call is replaced.
     */
    private function recorder(array $keys, array $failing = []): AiService
    {
        return new class ($keys, $failing) extends AiService {
            public array $attempted = [];
            public function __construct(array $keys, private readonly array $failing)
            {
                parent::__construct(
                    groqKey:      $keys['groq']      ?? null,
                    geminiKey:    $keys['gemini']    ?? null,
                    anthropicKey: $keys['anthropic'] ?? null,
                    openaiKey:    $keys['openai']    ?? null,
                );
            }
            // The four provider calls all funnel through httpPost; intercepting the
            // per-provider methods instead would bypass modelFor() and test nothing.
            protected function httpPost(string $url, array $headers, array $payload): ?array
            {
                $provider = match (true) {
                    str_contains($url, 'api.groq.com')             => 'groq',
                    str_contains($url, 'generativelanguage')       => 'gemini',
                    str_contains($url, 'api.anthropic.com')        => 'anthropic',
                    str_contains($url, 'api.openai.com')           => 'openai',
                    default                                        => 'unknown',
                };
                // Gemini carries the model in the URL, everyone else in the body.
                $model = $payload['model'] ?? (preg_match('~/models/([^:]+):~', $url, $m) ? $m[1] : '?');
                $this->attempted[] = $provider . ':' . $model;

                if (in_array($provider, $this->failing, true)) {
                    return null;
                }
                return match ($provider) {
                    'gemini'    => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]],
                    'anthropic' => ['content' => [['text' => 'ok']]],
                    default     => ['choices' => [['message' => ['content' => 'ok']]]],
                };
            }
        };
    }

    // ── The registry ─────────────────────────────────────────────────────────

    public function test_nothing_is_pinned_to_a_provider_this_deployment_cannot_pay_for(): void
    {
        // The pins were briefly openai:gpt-4o / openai:gpt-4o-mini. That is wrong here:
        // the OpenAI key is not on a paid plan, so every pinned call 401s and falls
        // through the ladder — a wasted round trip on the hot path of every AI feature.
        // Most damagingly on moderation.classify, which sits on the nomination submit
        // and is capped at ONE attempt: pinning a provider that cannot answer there
        // does not degrade the feature, it disables it.
        $offenders = [];
        foreach (AiCapability::all() as $cap) {
            if (str_starts_with($cap->model, 'openai:')) {
                $offenders[] = $cap->name . ' => ' . $cap->model;
            }
        }
        $this->assertSame([], $offenders,
            'the pins are free-tier Groq; OpenAI is a last-resort fallback only');

        // And every pin must be a provider with a free tier.
        foreach (AiCapability::all() as $cap) {
            $this->assertContains($cap->provider(), ['groq', 'gemini'],
                "{$cap->name} pins {$cap->provider()}, which has no free tier");
        }
    }

    public function test_no_declared_hop_needs_a_key_this_deployment_cannot_fund(): void
    {
        // resolveRoute() can skip a provider with NO key; it cannot know a key is
        // unfunded, because an unpaid key fails with a 401 rather than being absent. So
        // an OpenAI hop costs a real timeout, and a capability capped at three attempts
        // must not spend one of them there.
        //
        // OpenAI was briefly listed LAST in each ladder, which was worse than useless:
        // route() truncates to maxAttempts (3), so the fourth hop was unreachable and
        // the slot read as coverage that did not exist. It is now reached only through
        // resolveRoute()'s trailing "every remaining configured provider" append.
        foreach (AiCapability::all() as $cap) {
            foreach ($cap->route() as $hop) {
                $this->assertNotSame('openai', explode(':', $hop, 2)[0],
                    "{$cap->name} declares an OpenAI hop; free-tier providers must fill the ladder");
                $this->assertContains(explode(':', $hop, 2)[0], ['groq', 'gemini', 'anthropic'], $cap->name);
            }
        }
    }

    public function test_openai_is_still_reachable_when_its_key_is_funded(): void
    {
        // Not listed is not the same as not available. A deployment whose ONLY key is
        // OpenAI must still get every feature — the trailing append in resolveRoute()
        // is what guarantees a pin decides preference, not eligibility.
        $ai  = $this->recorder(['openai' => 'k']);
        $cap = AiCapability::find('guide.chat');

        $this->assertSame('ok', $ai->complete('sys', 'u', 80, false, 0.2, $cap->route(), $cap->maxAttempts));
        $this->assertCount(1, $ai->attempted);
        $this->assertStringStartsWith('openai:', $ai->attempted[0]);
    }

    public function test_the_default_ladder_fits_the_default_attempt_cap(): void
    {
        // A ladder longer than the ceiling has rungs nobody can stand on, and in review
        // it reads as coverage that exists. Got wrong twice consecutively here: OpenAI
        // was listed fourth and unreachable, and moving it out left Anthropic fourth and
        // unreachable.
        //
        // Measured against a capability that takes the DEFAULT cap. A capability that
        // deliberately lowers its own — moderation.classify caps at 1, because it sits
        // on the nomination submit — is making a choice, not declaring dead rungs, and
        // route() truncates it correctly. That is the next test.
        $cap = AiCapability::find('nomination.triage');
        $this->assertSame(3, $cap->maxAttempts, 'the default');
        $this->assertSame(1 + count($cap->fallbacks), $cap->maxAttempts,
            'the default ladder must be exactly as long as the default ceiling');
        $this->assertCount($cap->maxAttempts, $cap->route());
    }

    public function test_a_capability_that_lowers_its_cap_gets_a_truncated_route(): void
    {
        // The other side. The cap wins over the inherited ladder, and route() reflects
        // that rather than handing AiService hops it will never reach.
        $cap = AiCapability::find('moderation.classify');
        $this->assertSame(1, $cap->maxAttempts);
        $this->assertGreaterThan(1, 1 + count($cap->fallbacks), 'it does inherit a ladder');
        $this->assertCount(1, $cap->route(), 'and route() truncates to the ceiling');

        // Every capability: the route is exactly the ladder capped, never longer.
        foreach (AiCapability::all() as $c) {
            $this->assertCount(
                min(1 + count($c->fallbacks), $c->maxAttempts),
                $c->route(),
                $c->name
            );
        }
    }

    public function test_every_capability_pins_a_concrete_provider_and_model(): void
    {
        // A pin of "openai:" with no model would silently fall through to the
        // shipped default, which is a different thing from a declared decision.
        foreach (AiCapability::all() as $cap) {
            $this->assertStringContainsString(':', $cap->model, "{$cap->name} must be provider:model");
            $this->assertNotSame('', $cap->provider(), "{$cap->name} names no provider");
            $this->assertNotSame('', $cap->modelId(), "{$cap->name} names no model");
            $this->assertNotSame($cap->provider(), $cap->modelId(), "{$cap->name} is missing its model half");
        }
    }

    public function test_the_delegation_actually_differentiates_by_tier(): void
    {
        // The whole point. If every capability resolved to one model, this file
        // would be documenting a rename rather than a delegation.
        $models = [];
        foreach (AiCapability::all() as $cap) $models[$cap->model] = true;

        $this->assertGreaterThanOrEqual(2, count($models),
            'reasoning work and latency-sensitive suggestions must not share one model');
        $this->assertArrayHasKey(AiCapability::PRIMARY[AiCapability::TIER_REASON], $models);
        $this->assertArrayHasKey(AiCapability::PRIMARY[AiCapability::TIER_FAST], $models);

        // WRITE shares the 70b model with REASON and differs in its PARAMETERS. That is
        // still delegation — a classifier wanting a terse deterministic label and a
        // flier wanting warm publishable copy are not the same job on one model.
        $temps = [];
        foreach ([AiCapability::TIER_REASON, AiCapability::TIER_WRITE, AiCapability::TIER_FAST] as $t) {
            $temps[$t] = AiCapability::PARAMS[$t]['temperature'];
        }
        $this->assertGreaterThan($temps[AiCapability::TIER_REASON], $temps[AiCapability::TIER_WRITE],
            'published prose needs a warmer temperature than a moderation score');
        $this->assertLessThanOrEqual(0.2, $temps[AiCapability::TIER_REASON],
            'a score that moves between identical submissions is not a score');
    }

    public function test_decisions_get_the_reasoning_tier(): void
    {
        // These drive a decision a person acts on. Latency is not the constraint;
        // being wrong is.
        foreach (['moderation.classify', 'nomination.triage', 'nominee.merge_suggest',
                  'admin.assistant', 'integrity.brief'] as $name) {
            $cap = AiCapability::find($name);
            $this->assertNotNull($cap, $name);
            $this->assertSame(AiCapability::TIER_REASON, $cap->tier, $name);
            $this->assertSame(0.15, $cap->temperature(), $name);
        }
    }

    public function test_published_prose_gets_the_writing_tier(): void
    {
        // The failure mode here is not a wrong label but flat, generic,
        // obviously-machine text — which for rally copy means a nominee will not post
        // it, and for the public guide means a visitor stops trusting the answers.
        foreach (['guide.chat', 'community.thread_summary', 'nomination.decision_note',
                  'admin.content_assist', 'admin.form_design'] as $name) {
            $cap = AiCapability::find($name);
            $this->assertNotNull($cap, $name);
            $this->assertSame(AiCapability::TIER_WRITE, $cap->tier, $name);
            $this->assertGreaterThan(0.5, $cap->temperature(), $name);
        }
    }

    public function test_throwaway_suggestions_get_the_fast_tier(): void
    {
        // A wording suggestion the user accepts or discards, on a synchronous
        // request. A slower better answer that lands after they stop typing is a
        // worse answer.
        foreach (['nomination.polish', 'community.polish', 'nomination.suggest_category',
                  'admin.filter_parse'] as $name) {
            $this->assertSame(AiCapability::PRIMARY[AiCapability::TIER_FAST],
                AiCapability::find($name)->model, $name);
            $this->assertSame(AiCapability::TIER_FAST, AiCapability::find($name)->tier, $name);
        }
    }

    public function test_gemini_is_pinned_to_the_requested_flash_model(): void
    {
        $this->assertSame('gemini-3.6-flash', AiService::DEFAULT_MODELS['gemini']);

        // And it is the first fallback everywhere, so a GPT outage lands there.
        foreach (AiCapability::all() as $cap) {
            $this->assertNotSame([], $cap->fallbacks, "{$cap->name} declares no fallback");
            $this->assertStringStartsWith('gemini:', $cap->fallbacks[0], $cap->name);
        }
    }

    public function test_a_fallback_never_repeats_the_pin_exactly(): void
    {
        // Provider-and-model, not provider alone. An earlier version of this rule
        // dropped every hop sharing the pin's provider, reasoning that a provider
        // which just failed will fail again. That is right for an outage and wrong for
        // the failure this deployment will actually see: Groq's free tier rate-limits
        // PER MODEL, so a 429 on llama-3.1-8b-instant says nothing about whether
        // llama-3.3-70b-versatile can answer. Dropping the second Groq hop would throw
        // away the most likely successful retry on the platform's own primary provider.
        //
        // An exact repeat is still forbidden — that is only a doubled timeout.
        foreach (AiCapability::all() as $cap) {
            foreach ($cap->fallbacks as $hop) {
                $this->assertNotSame($cap->model, $hop,
                    "{$cap->name} repeats its own pin as a fallback");
            }
            $this->assertSame(count($cap->route()), count(array_unique($cap->route())),
                "{$cap->name} has a duplicate hop in its route");
        }
    }

    public function test_the_fast_tier_can_fall_back_to_a_bigger_model_on_the_same_provider(): void
    {
        // The case the old rule forbade, and the most likely one to matter: the small
        // free model is rate-limited and the large free model is not.
        $cap = AiCapability::find('nomination.polish');
        $this->assertSame('groq:llama-3.1-8b-instant', $cap->model);
        $this->assertContains('groq:llama-3.3-70b-versatile', $cap->route(),
            'a per-model 429 must be recoverable without leaving the provider');

        $ai = $this->recorder(['groq' => 'k'], failing: []);
        $this->assertSame('ok', $ai->complete('sys', 'user', 80, false, 0.2, $cap->route(), $cap->maxAttempts));
        $this->assertSame('groq:llama-3.1-8b-instant', $ai->attempted[0], 'the small model first');
    }

    // ── The route, end to end ────────────────────────────────────────────────

    public function test_the_pinned_model_is_the_one_actually_requested(): void
    {
        // The defect that made the pin decorative. With only a Groq key configured
        // the old code called Groq regardless of the pin; with an OpenAI key it
        // still called Groq first if a Groq key existed.
        $ai  = $this->recorder(['groq' => 'k-groq', 'openai' => 'k-openai']);
        $cap = AiCapability::find('nomination.triage');

        $out = $ai->complete('sys', 'user', 80, false, 0.2, $cap->route(), $cap->maxAttempts);

        $this->assertSame('ok', $out);
        $this->assertSame([$cap->model], $ai->attempted,
            'the pin must be the FIRST and, when it answers, the ONLY attempt');
    }

    public function test_the_declared_fallback_runs_when_the_pin_fails(): void
    {
        // nomination.triage, not moderation.classify: triage is reviewed
        // asynchronously by a person, so it is allowed to fall back. The one that
        // sits on a form POST is capped to a single attempt — see below.
        $ai  = $this->recorder(
            ['groq' => 'k', 'gemini' => 'k', 'openai' => 'k'],
            failing: ['groq'],
        );
        $cap = AiCapability::find('nomination.triage');

        $out = $ai->complete('sys', 'user', 80, false, 0.2, $cap->route(), $cap->maxAttempts);

        $this->assertSame('ok', $out);
        $this->assertSame($cap->model, $ai->attempted[0], 'the pin is tried first');
        $this->assertSame('gemini:gemini-3.6-flash', $ai->attempted[1],
            'then the declared fallback, on the provider default model');
    }

    public function test_a_synchronous_capability_makes_exactly_one_attempt(): void
    {
        // moderation.classify has a 4s timeout and sits on the nomination submit.
        // Its own comment said "one attempt only" and nothing enforced it, so
        // declaring a fallback ladder would have quietly turned a 4s worst case into
        // a 16s one on a form POST. The signal is advisory: skipping the model and
        // using the local heuristics beats making the user wait.
        $cap = AiCapability::find('moderation.classify');
        $this->assertSame(1, $cap->maxAttempts);
        $this->assertCount(1, $cap->route(), 'the declared route is truncated to the ceiling');

        $ai = $this->recorder(
            ['openai' => 'k', 'gemini' => 'k', 'anthropic' => 'k', 'groq' => 'k'],
            failing: ['openai', 'gemini', 'anthropic', 'groq'],
        );
        $this->assertNull($ai->complete('sys', 'user', 80, false, 0.2, $cap->route(), $cap->maxAttempts));
        $this->assertCount(1, $ai->attempted,
            'four keys must not become four timeouts: ' . implode(', ', $ai->attempted));
    }

    public function test_the_ceiling_never_makes_a_feature_unavailable(): void
    {
        // The cap is applied AFTER unconfigured providers are dropped. Capping the
        // declared route first would mean a deployment whose only key is Anthropic
        // gets a single hop of `openai:` — which it has no key for — and therefore
        // no moderation at all.
        $cap = AiCapability::find('moderation.classify');
        $ai  = $this->recorder(['anthropic' => 'k']);

        $this->assertSame('ok', $ai->complete('sys', 'user', 80, false, 0.2, $cap->route(), $cap->maxAttempts));
        $this->assertCount(1, $ai->attempted);
        $this->assertStringStartsWith('anthropic:', $ai->attempted[0]);
    }

    public function test_a_background_capability_is_allowed_to_exhaust_the_ladder(): void
    {
        // Nobody is waiting on an integrity brief, so quality beats latency there.
        // If every capability had the same ceiling this would be a global setting
        // dressed up as a per-capability one.
        $this->assertGreaterThan(1, AiCapability::find('integrity.brief')->maxAttempts);
        $this->assertGreaterThan(1, count(AiCapability::find('integrity.brief')->route()));
    }

    public function test_a_hop_with_no_key_is_skipped_rather_than_attempted(): void
    {
        // Spending a timeout on a provider that cannot possibly answer is the
        // difference between a 4s moderation call and a 16s one, on a synchronous
        // form POST.
        $ai  = $this->recorder(['groq' => 'k-groq']);
        $cap = AiCapability::find('moderation.classify');

        $ai->complete('sys', 'user', 80, false, 0.2, $cap->route());

        foreach ($ai->attempted as $hop) {
            $this->assertStringStartsWith('groq:', $hop, 'only the configured provider may be called');
        }
        $this->assertNotSame([], $ai->attempted, 'and it must still run — a pin is a preference, not a gate');
    }

    public function test_a_pin_cannot_make_a_feature_unavailable(): void
    {
        // A deployment with only an Anthropic key must still get moderation, even
        // though nothing pins or falls back to Anthropic at the fast tier. The pin
        // decides preference; the configured keys decide eligibility.
        $ai  = $this->recorder(['anthropic' => 'k']);
        $cap = AiCapability::find('nomination.polish');

        $this->assertSame('ok', $ai->complete('sys', 'user', 80, false, 0.2, $cap->route()));
        $this->assertCount(1, $ai->attempted);
        $this->assertStringStartsWith('anthropic:', $ai->attempted[0]);
    }

    public function test_no_hop_is_attempted_twice(): void
    {
        // The route ends with "everything else configured", which overlaps the
        // declared ladder. A duplicate hop is a doubled timeout for nothing.
        $ai  = $this->recorder(['openai' => 'k', 'gemini' => 'k', 'anthropic' => 'k', 'groq' => 'k'],
                               failing: ['openai', 'gemini', 'anthropic', 'groq']);
        $cap = AiCapability::find('moderation.classify');

        $ai->complete('sys', 'user', 80, false, 0.2, $cap->route());

        $this->assertSame(array_values(array_unique($ai->attempted)), $ai->attempted,
            'a provider/model pair must appear at most once: ' . implode(', ', $ai->attempted));
    }

    public function test_an_empty_route_reproduces_the_old_key_priority_order(): void
    {
        // Every legacy caller passes no route, and must keep working unchanged.
        $ai = $this->recorder(['gemini' => 'k', 'openai' => 'k'], failing: ['gemini']);

        $this->assertSame('ok', $ai->complete('sys', 'user'));
        $this->assertStringStartsWith('gemini:', $ai->attempted[0], 'gemini outranks openai by key priority');
        $this->assertStringStartsWith('openai:', $ai->attempted[1]);
    }

    // ── The audit trail ──────────────────────────────────────────────────────

    public function test_what_answered_is_recorded_not_what_was_preferred(): void
    {
        // The lie that mattered. activeProvider() reports the first configured key,
        // so after a failover the log named a provider that had just failed and a
        // model that was never called — wrong precisely when something went wrong.
        $ai = $this->recorder(['groq' => 'k', 'gemini' => 'k'], failing: ['groq']);

        $ai->complete('sys', 'user', 80, false, 0.2, ['groq:llama-3.3-70b-versatile', 'gemini:gemini-3.6-flash']);

        $this->assertSame('groq', $ai->activeProvider(), 'key priority still prefers groq');
        $this->assertSame('gemini', $ai->lastProvider(), 'but gemini is what answered');
        $this->assertSame('gemini-3.6-flash', $ai->lastModel());
    }

    public function test_nothing_is_recorded_as_having_answered_when_nothing_did(): void
    {
        $ai = $this->recorder(['openai' => 'k'], failing: ['openai']);

        $this->assertNull($ai->complete('sys', 'user', 80, false, 0.2, ['openai:gpt-4o']));
        $this->assertNull($ai->lastProvider());
        $this->assertNull($ai->lastModel());
    }

    public function test_a_stale_success_is_cleared_by_the_next_failed_call(): void
    {
        // lastProvider() is read after the fact by the gateway's logger. If a
        // failure left the previous call's value in place, the log would attribute
        // one call's answer to another.
        $ai = $this->recorder(['openai' => 'k']);
        $ai->complete('sys', 'user', 80, false, 0.2, ['openai:gpt-4o']);
        $this->assertSame('openai', $ai->lastProvider());

        $broken = $this->recorder(['openai' => 'k'], failing: ['openai']);
        $broken->complete('sys', 'user', 80, false, 0.2, ['openai:gpt-4o']);
        $this->assertNull($broken->lastProvider());
    }

    // ── Model resolution ─────────────────────────────────────────────────────

    public function test_an_explicit_model_beats_the_configured_one(): void
    {
        // How a capability pin reaches the wire while an admin Setting still wins
        // for everything that does not declare one.
        $ai = new AiService(openaiKey: 'k', openaiModel: 'gpt-from-settings');

        $this->assertSame('gpt-4o', $ai->modelFor('openai', 'gpt-4o'));
        $this->assertSame('gpt-from-settings', $ai->modelFor('openai'));
        $this->assertSame('gpt-from-settings', $ai->modelFor('openai', '  '),
            'a blank override is not an override');
    }

    public function test_the_shipped_default_applies_when_nothing_is_configured(): void
    {
        $ai = new AiService(openaiKey: 'k');

        foreach (AiService::DEFAULT_MODELS as $provider => $expected) {
            $this->assertSame($expected, $ai->modelFor($provider));
        }
    }

    public function test_the_status_panel_and_the_request_cannot_disagree(): void
    {
        // activeModel() duplicated the four default literals, so the model the admin
        // panel displayed and the model the request sent were two copies that could
        // drift. Both now read modelFor().
        $ai = new AiService(geminiKey: 'k', geminiModel: 'gemini-pinned-by-admin');

        $this->assertSame('gemini', $ai->activeProvider());
        $this->assertSame('gemini-pinned-by-admin', $ai->activeModel());
        $this->assertSame($ai->modelFor('gemini'), $ai->activeModel());
    }
}
