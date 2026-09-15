<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\SettingsController;
use AfricaGates\Admin\Services\{SettingsService, AuditService};
use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
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

    /**
     * Providers this deployment is not guaranteed to be able to reach.
     *
     * NOT "cannot pay for" — the earlier rule here banned OpenAI outright because the
     * key on this deployment was not on a paid plan, and that has stopped being true.
     * The hazard it was really guarding against survives the funding: `resolveRoute()`
     * can skip a provider with NO key, and it cannot tell a key that is unfunded,
     * expired, quota-exhausted or region-blocked from one that works, because all of
     * those fail with a 401 or a 429 at request time rather than being absent. So a hop
     * here costs a real timeout, and it must never be the last thing a capability can
     * try.
     *
     * Gemini and Groq are on the other list because both have a free tier: a deployment
     * that has done nothing but paste a key can still reach them.
     */
    private const NEEDS_A_FUNDED_KEY = ['openai', 'anthropic'];

    public function test_a_provider_that_can_401_is_never_a_capability_s_only_hop(): void
    {
        // The pins were briefly openai:gpt-4o / openai:gpt-4o-mini, on a deployment
        // whose OpenAI key was not funded — so every pinned call 401'd and fell through
        // the ladder, a wasted round trip on the hot path of every AI feature. Most
        // damagingly on moderation.classify, which sits on the nomination submit and is
        // capped at ONE attempt: pinning a provider that cannot answer there does not
        // degrade the feature, it disables it.
        //
        // That deployment now has a funded OpenAI key, and door.name_pronounce names it
        // deliberately. So the rule is no longer "never" — it is that a 401 must always
        // have somewhere to land. A capability that cannot climb (one attempt, or an
        // empty ladder) must pin a provider that answers without a billing relationship.
        foreach (AiCapability::all() as $cap) {
            $route = $cap->route();
            $free  = array_values(array_filter(
                $route,
                fn (string $hop): bool => !in_array(explode(':', $hop, 2)[0], self::NEEDS_A_FUNDED_KEY, true)
            ));

            $this->assertNotSame([], $free,
                "{$cap->name} can only ever reach a provider that might 401: "
                . implode(', ', $route));
        }
    }

    public function test_a_funded_provider_is_never_reached_by_inheritance(): void
    {
        // The tier ladder every un-pinned capability inherits is free-tier only, and
        // that is the load-bearing half of the rule above. A capability reaches OpenAI
        // because somebody decided it should — door.name_pronounce does, and says why
        // beside the pin — never because it did not declare a ladder and the shared one
        // happened to contain it.
        //
        // OpenAI was briefly listed LAST in each shared ladder, which was worse than
        // useless: route() truncates to maxAttempts (3), so the fourth hop was
        // unreachable and the slot read as coverage that did not exist. It is now
        // reached deliberately, or through resolveRoute()'s trailing "every remaining
        // configured provider" append, and by no other path.
        foreach (AiCapability::all() as $cap) {
            if (in_array($cap->provider(), self::NEEDS_A_FUNDED_KEY, true)) {
                continue; // named its own provider; the test above bounds the risk
            }

            foreach ($cap->fallbacks as $hop) {
                $this->assertNotContains(explode(':', $hop, 2)[0], self::NEEDS_A_FUNDED_KEY,
                    "{$cap->name} inherits a {$hop} hop it never asked for");
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
        // WHERE THE DELEGATION LIVES, now that one provider leads all three tiers.
        //
        // This used to assert two distinct pinned models, on a registry where REASON and
        // WRITE shared Groq's 70b and FAST took the 8b. The primary has since moved to a
        // provider that publishes one model for all three, so counting distinct pins
        // would now measure the vendor's catalogue rather than this platform's choices —
        // and would fail the day an operator points the platform at a provider with one
        // model, which is a configuration, not a regression.
        //
        // The three axes that ARE this platform's are asserted instead: the parameters
        // each tier generates with, the ORDER of the ladder each tier falls through, and
        // the fact that a capability may still name its own provider outright.

        // 1. The pin is concrete for every tier — a half-written pin reads as a choice
        //    while being an absence.
        foreach ([AiCapability::TIER_REASON, AiCapability::TIER_WRITE, AiCapability::TIER_FAST] as $t) {
            $pin = AiCapability::primary($t);
            [$provider, $model] = array_pad(explode(':', $pin, 2), 2, '');
            $this->assertNotSame('', $provider, $t);
            $this->assertNotSame('', $model, "the {$t} tier's pin names no model");
        }

        // 2. FAST falls through its ladder in a different ORDER: the small model before
        //    the big one, because a suggestion that lands after the user has finished
        //    typing is worse than no suggestion. REASON climbs the other way.
        $fast   = AiCapability::find('nomination.polish')->fallbacks;
        $reason = AiCapability::find('nomination.triage')->fallbacks;
        $this->assertNotSame($reason, $fast,
            'the tiers must not fall through an identical ladder in an identical order');

        // 3. And a capability that knows better than the platform preference can say so.
        $named = [];
        foreach (AiCapability::all() as $cap) {
            if ($cap->provider() !== AiCapability::primaryProvider()) $named[] = $cap->name;
        }
        $this->assertNotSame([], $named,
            'no capability names its own provider, so the registry is one preference '
            . 'copied thirty times');

        // WRITE shares its model with REASON and differs in its PARAMETERS. That is
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
            // primary(), not PRIMARY: the constant is the shipped default and carries no
            // model half, while a capability's pin is the operator's provider resolved to
            // a concrete model. Comparing against the constant would assert the fallback
            // for a deployment nobody has configured.
            $this->assertSame(AiCapability::primary(AiCapability::TIER_FAST),
                AiCapability::find($name)->model, $name);
            $this->assertSame(AiCapability::TIER_FAST, AiCapability::find($name)->tier, $name);
        }
    }

    public function test_gemini_is_pinned_to_the_requested_flash_model(): void
    {
        $this->assertSame('gemini-3.6-flash', AiService::DEFAULT_MODELS['gemini']);

        // And every capability can REACH it — as its pin or as a hop.
        //
        // This used to say "it is the first fallback everywhere", which was true while
        // the platform led with Groq and is now false by construction: Gemini is the
        // pin, so `ladder()` filters it out of the ladder behind itself. The property
        // that mattered survives the move and is the one asserted here — a Groq outage,
        // or an OpenAI 401 on the one capability that names OpenAI, still lands on a
        // provider with a free tier that this deployment can reach.
        //
        // With ONE exception, and it is a safety property rather than an oversight.
        // `evidence.analyse` reads an attached document and Groq is text-only: a fallback
        // would hand the file to a model that cannot see it and get back a fluent,
        // well-formed, entirely invented description, stored next to the real ones. So an
        // empty ladder is allowed, on a Gemini pin, capped at one attempt.
        foreach (AiCapability::all() as $cap) {
            if ($cap->fallbacks === []) {
                $this->assertStringStartsWith('gemini:', $cap->model,
                    "{$cap->name} declares no fallback, which is only allowed for a Gemini pin");
                $this->assertSame(1, $cap->maxAttempts,
                    "{$cap->name} has no ladder to climb, so it must not claim more than one attempt");
                continue;
            }

            $reaches = array_filter(
                $cap->route(),
                static fn (string $hop): bool => str_starts_with($hop, 'gemini:')
            );
            $this->assertNotSame([], $reaches,
                "{$cap->name} can never reach Gemini: " . implode(', ', $cap->route()));
        }
    }

    public function test_a_file_reading_capability_can_never_route_to_a_text_only_model(): void
    {
        // The failure this guards against is not an outage, it is a HALLUCINATION with an
        // audit trail: the log would record a successful call and a stored analysis, and
        // nothing on the screen would distinguish it from a document actually read.
        $cap = AiCapability::find('evidence.analyse');
        $this->assertNotNull($cap);

        foreach ($cap->route() as $hop) {
            $this->assertStringStartsWith('gemini:', $hop,
                'evidence.analyse may only ever reach a provider that can receive a file');
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
        // free model is rate-limited and the large free model is not. It used to be
        // asserted on the PIN, when FAST pinned Groq's 8b; the primary has moved and the
        // property now lives in the ladder, which is where it has to be checked.
        $cap = AiCapability::find('nomination.polish');
        $route = $cap->route();

        $small = array_search('groq:llama-3.1-8b-instant', $route, true);
        $big   = array_search('groq:llama-3.3-70b-versatile', $route, true);

        $this->assertNotFalse($small, 'a per-model 429 must be recoverable without leaving the provider');
        $this->assertNotFalse($big, 'and the recovery is the other model on the same provider');
        $this->assertLessThan($big, $small,
            'the small model first: on the FAST tier a slower better answer that lands '
            . 'after the user has stopped typing is a worse answer');

        // With only a Groq key configured, resolveRoute() skips the Gemini pin outright
        // rather than spending an attempt on a provider it has no key for — so the first
        // thing on the wire is the small model.
        $ai = $this->recorder(['groq' => 'k'], failing: []);
        $this->assertSame('ok', $ai->complete('sys', 'user', 80, false, 0.2, $route, $cap->maxAttempts));
        $this->assertSame('groq:llama-3.1-8b-instant', $ai->attempted[0], 'the small model first');
    }

    // ── The route, end to end ────────────────────────────────────────────────

    public function test_the_pinned_model_is_the_one_actually_requested(): void
    {
        // The defect that made the pin decorative. With only a Groq key configured
        // the old code called Groq regardless of the pin; with an OpenAI key it
        // still called Groq first if a Groq key existed.
        //
        // Every key present, deliberately: a deployment holding three keys is the case
        // where "whichever key was configured first" and "the declared pin" come apart,
        // and it is the only one that can tell them apart. Omit the pinned provider's own
        // key and resolveRoute() correctly SKIPS the pin, which would make this pass for
        // the wrong reason.
        $ai  = $this->recorder(['groq' => 'k-groq', 'gemini' => 'k-gemini', 'openai' => 'k-openai']);
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
        // The PIN's provider is the one made to fail: failing anything else would leave
        // the pin answering and assert nothing about the ladder.
        $cap = AiCapability::find('nomination.triage');
        $ai  = $this->recorder(
            ['groq' => 'k', 'gemini' => 'k', 'openai' => 'k'],
            failing: [$cap->provider()],
        );

        $out = $ai->complete('sys', 'user', 80, false, 0.2, $cap->route(), $cap->maxAttempts);

        $this->assertSame('ok', $out);
        $this->assertSame($cap->model, $ai->attempted[0], 'the pin is tried first');
        $this->assertSame($cap->fallbacks[0], $ai->attempted[1],
            'then the declared fallback, and the route it declares is the route it takes');
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

    // ── Whose choice the primary is ──────────────────────────────────────────

    /**
     * Point the platform at a provider, the way the Settings screen does.
     *
     * The registry memoises its pins — a request must not change provider halfway
     * through — so the setting alone is not enough to see the effect. `forget()` is
     * what the memo exists to be cleared BY, and this is its only caller.
     */
    private function leadWith(string $provider): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => AiCapability::PRIMARY_SETTING],
            ['value' => $provider]
        );
        AiCapability::forget();
    }

    protected function tearDown(): void
    {
        DB::table('gates_settings')
            ->where('key_name', AiCapability::PRIMARY_SETTING)->delete();
        unset($_ENV['AI_PRIMARY'], $_SERVER['AI_PRIMARY']);
        AiCapability::forget();

        parent::tearDown();
    }

    public function test_an_operator_can_move_the_primary_provider(): void
    {
        // This was a constant, and moving it was a deploy — on a platform with no shell,
        // where every other operational value is settable from /admin/settings for exactly
        // that reason. An operator who has just pasted an Anthropic key and wants their
        // features to use it should not need a developer.
        $this->assertSame(AiCapability::DEFAULT_PRIMARY, AiCapability::primaryProvider(),
            'the shipped default');

        $this->leadWith('anthropic');

        $this->assertSame('anthropic', AiCapability::primaryProvider());
        foreach (['nomination.triage', 'guide.chat', 'nomination.polish'] as $name) {
            $this->assertSame('anthropic', AiCapability::find($name)->provider(),
                "{$name} did not follow the operator's choice");
        }
    }

    public function test_the_moved_primary_still_pins_a_concrete_model(): void
    {
        // A pin that defers to "whatever the provider defaults to" is not a pin, and the
        // registry test enforces that for the SHIPPED default. It has to survive the move
        // as well, or an operator changing providers quietly empties every model half.
        foreach (array_keys(AiCapability::PROVIDERS) as $provider) {
            $this->leadWith($provider);

            foreach (AiCapability::all() as $cap) {
                $this->assertNotSame('', $cap->modelId(), "{$cap->name} on {$provider}");
                $this->assertNotSame($cap->provider(), $cap->modelId(),
                    "{$cap->name} lost its model half on {$provider}");
            }
        }
    }

    public function test_moving_the_primary_never_leaves_the_pin_in_its_own_ladder(): void
    {
        // THE REGRESSION THIS FILE EXISTS FOR, in its newest form.
        //
        // The shared ladder writes a hop as `gemini:` — "this provider's configured
        // model" — while primary() writes its pin out in full as `gemini:gemini-3.6-flash`.
        // The filter that removes the pin from the ladder behind it compared the two as
        // STRINGS, so it saw two different hops and kept both: the pin, tried twice,
        // spending one of only three attempts on the provider that had just failed.
        //
        // Silent, too. The route reads as a three-hop ladder in the audit log and is
        // really two, and it only appears at all once the primary moves onto a provider
        // the ladder already contains — which is what this change did.
        foreach (array_keys(AiCapability::PROVIDERS) as $provider) {
            $this->leadWith($provider);

            foreach (AiCapability::all() as $cap) {
                $route = array_map(
                    static fn (string $hop): string => AiCapability::concrete($hop),
                    $cap->route()
                );

                $this->assertSame(count($route), count(array_unique($route)),
                    "{$cap->name} on {$provider} retries a hop it has already made: "
                    . implode(', ', $route));
            }
        }
    }

    public function test_a_capability_that_names_its_own_provider_ignores_the_primary(): void
    {
        // door.name_pronounce names OpenAI because working out that Ngozi is Igbo, and
        // where the stress falls, is a knowledge question rather than a reasoning one —
        // and the answer is KEPT and then read aloud to somebody at their own door. An
        // operator moving the platform preference is expressing a preference about the
        // platform, and it must not silently move a decision made about one job.
        foreach (array_keys(AiCapability::PROVIDERS) as $provider) {
            $this->leadWith($provider);

            $this->assertSame('openai', AiCapability::find('door.name_pronounce')->provider(),
                "the primary moved to {$provider} and took the name pronunciation with it");

            // Same shape, different reason: evidence.analyse is pinned to the only
            // provider configured here that can receive a FILE at all.
            $this->assertSame('gemini', AiCapability::find('evidence.analyse')->provider(),
                "evidence.analyse followed the primary to {$provider}, where a text-only "
                . 'model would invent a description of a document it never received');
        }
    }

    public function test_a_capability_that_can_climb_still_lands_free_whatever_the_primary_is(): void
    {
        // An operator may lead with a provider that needs a funded key — that is their
        // call, and a capability capped at one attempt then rides on it. What must not
        // happen is a capability that HAS a ladder spending every rung of it on providers
        // that can all 401 together.
        foreach (array_keys(AiCapability::PROVIDERS) as $provider) {
            $this->leadWith($provider);

            foreach (AiCapability::all() as $cap) {
                if ($cap->maxAttempts < 2 || $cap->fallbacks === []) continue;

                $free = array_filter(
                    $cap->route(),
                    fn (string $hop): bool
                        => !in_array(explode(':', $hop, 2)[0], self::NEEDS_A_FUNDED_KEY, true)
                );

                $this->assertNotSame([], $free,
                    "{$cap->name} on {$provider} climbs a ladder that can 401 all the way down: "
                    . implode(', ', $cap->route()));
            }
        }
    }

    public function test_a_primary_nobody_recognises_falls_back_to_the_shipped_default(): void
    {
        // The value arrives from a form. A typo, a stale row, or a provider that was
        // removed from PROVIDERS must not produce a pin like `chatgpt:gpt-4o` that every
        // capability then carries to a router which has never heard of it — the platform
        // would go dark on a setting nobody could see was wrong.
        foreach (['chatgpt', '', '   ', 'gemini; drop table', 'OPENAI '] as $junk) {
            $this->leadWith($junk);

            $p = AiCapability::primaryProvider();
            $this->assertArrayHasKey($p, AiCapability::PROVIDERS,
                "'{$junk}' resolved to '{$p}', which is not a provider this platform can call");
        }

        // …except the one that is not junk. Case and surrounding space are an operator
        // typing, not an operator meaning something else.
        $this->leadWith('OPENAI ');
        $this->assertSame('openai', AiCapability::primaryProvider());
    }

    public function test_the_env_fallback_applies_only_when_nothing_is_set(): void
    {
        // Same precedence as every other operational value here: the setting an operator
        // can reach beats the file they cannot. `.env` exists so a fresh deployment can
        // arrive already pointed somewhere, not so it can override the screen.
        $_ENV['AI_PRIMARY'] = 'groq';
        AiCapability::forget();
        $this->assertSame('groq', AiCapability::primaryProvider());

        $this->leadWith('gemini');
        $this->assertSame('gemini', AiCapability::primaryProvider(),
            'the settings row must win over the environment');
    }

    // ── And the route from the browser to that choice ────────────────────────

    /**
     * The form an operator actually uses, invoked the way a browser invokes it.
     *
     * Not a direct settings write: the thing that has failed on this platform before is
     * the PATH — a value the service reads correctly and no screen can set, on a host
     * with no shell. `save()`'s allowlist is where that path is cut, silently, by an
     * omission that looks like nothing.
     */
    private function post(array $body): void
    {
        $_SESSION['admin_id'] = 1;

        (new SettingsController(
            $this->createStub(\Slim\Views\Twig::class),
            new SettingsService(),
            new AuditService(),
        ))->save(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/admin/settings')
                ->withParsedBody($body),
            new Response()
        );

        AiCapability::forget();
    }

    public function test_an_admin_can_move_the_primary_from_the_browser(): void
    {
        $this->assertSame(AiCapability::DEFAULT_PRIMARY, AiCapability::primaryProvider(),
            'the shipped default');

        $this->post(['ai_settings' => '1', 'ai_primary' => 'groq']);

        $this->assertSame('groq', AiCapability::primaryProvider(),
            'the value the registry reads is the value the form wrote');
        $this->assertSame('groq', AiCapability::find('guide.chat')->provider());
    }

    public function test_the_form_has_the_field_that_sets_it(): void
    {
        // The other half, and the half that goes wrong quietly. A resolver nothing can
        // reach is the same bug as a field nothing reads, and the field NAME is the whole
        // contract between them: rename one and the form keeps rendering, keeps saving,
        // and stops doing anything.
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        $this->assertStringContainsString('name="' . AiCapability::PRIMARY_SETTING . '"', $form,
            'the primary is resolvable but there is no field that sets it');
    }

    public function test_the_screen_names_the_primary_from_the_setting_and_not_from_prose(): void
    {
        // THE REASON THIS IS A TEST. The AI card used to name the leading provider in a
        // sentence, and that sentence was wrong three times: it said OpenAI while the code
        // pinned Groq, then Groq after the pin had moved, then Groq again here. A sentence
        // restating a value that lives elsewhere is falsified by every move of that value,
        // and nothing fails when it is.
        //
        // So the card must interpolate, not assert. Checked on the RENDERED text rather
        // than the source, because a Twig comment recording what the sentence used to say
        // is the explanation, not the bug.
        $body = '';
        foreach (preg_split('/(\{#.*?#\})/s', (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/settings.twig'
        ), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $chunk) {
            if (!str_starts_with($chunk, '{#')) $body .= $chunk;
        }

        // The blurb paragraph exactly — from the card's own marker to the end of the
        // first <p>. A fixed byte window would drift the day somebody adds a field, and
        // would start quietly checking a region the test is not about.
        $start = strpos($body, 'name="ai_settings"');
        $this->assertNotFalse($start, 'the AI providers card has moved or been renamed');
        $end = strpos($body, '</p>', $start);
        $this->assertNotFalse($end, 'the card no longer opens with the paragraph under test');
        $card = substr($body, $start, $end - $start);

        // Collected rather than asserted one by one: a failure here should name the
        // provider, not print two thousand characters of card at whoever broke it.
        $named = [];
        foreach (AiCapability::PROVIDERS as $label) {
            if (str_contains($card, '<strong>' . $label . '</strong>')) $named[] = $label;
        }
        $this->assertSame([], $named,
            'the card names a leading provider in prose; interpolate '
            . 'ai_primary_choices[ai_primary] instead, so the screen cannot go stale the '
            . 'next time the primary moves');
        $this->assertStringContainsString('ai_primary_choices[ai_primary]', $card,
            'the card must read the provider it names from the same value the picker sets');
    }

    public function test_the_model_field_beside_the_provider_picker_is_not_decorative(): void
    {
        // Two controls, one decision, and they have to agree about who wins. An operator
        // who picks a provider and then types a model id into the field next to it has
        // said something specific; TIER_MODELS winning would make that field save,
        // redisplay its value and change nothing at all.
        $this->leadWith('groq');
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'ai_groq_model'], ['value' => 'llama-4-scout-17b']
        );
        AiCapability::forget();

        foreach (['nomination.triage', 'guide.chat', 'nomination.polish'] as $name) {
            $this->assertSame('groq:llama-4-scout-17b', AiCapability::find($name)->model, $name);
        }

        DB::table('gates_settings')->where('key_name', 'ai_groq_model')->delete();
    }

    public function test_a_provider_with_two_sizes_keeps_its_tier_split_by_default(): void
    {
        // The other side of the same rule. With nothing chosen, Groq is the one provider
        // here whose tiers want genuinely different ids rather than one model at two
        // temperatures — a throwaway suggestion on the small one, a moderation score on
        // the large. That split is a shipped default, and it must survive the primary
        // becoming a setting.
        $this->leadWith('groq');

        $this->assertSame('groq:llama-3.3-70b-versatile',
            AiCapability::find('nomination.triage')->model, 'the reasoning tier');
        $this->assertSame('groq:llama-3.1-8b-instant',
            AiCapability::find('nomination.polish')->model, 'the latency-sensitive one');
    }

    public function test_the_shipped_default_primary_costs_a_deployment_nothing(): void
    {
        // The whole reason the default is what it is. A platform where nobody has chosen
        // anything must still be able to think, so the fallback provider has to be one a
        // deployment can reach by pasting a key rather than by opening an account with a
        // card. Choosing otherwise would make every AI feature dark by default and look,
        // from the admin screen, exactly like a misconfiguration.
        $this->assertArrayHasKey(AiCapability::DEFAULT_PRIMARY, AiCapability::PROVIDERS);
        $this->assertNotContains(AiCapability::DEFAULT_PRIMARY, self::NEEDS_A_FUNDED_KEY,
            'the shipped default needs a billing relationship, so an unconfigured '
            . 'deployment gets nothing');
    }
}
