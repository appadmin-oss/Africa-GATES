<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\AiReply;
use AfricaGates\Support\Env;
use AfricaGates\Support\ProviderBreaker;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Pluggable AI gateway for Africa GATES.
 *
 * Resolves provider API keys from admin settings (gates_settings) with .env
 * fallback, then offers two capabilities used across the platform:
 *
 *   • moderate()  — score user content 0..1 for spam/abuse (SpamService stage 2)
 *   • complete()  — general-purpose text/JSON completion (auto-filter presets,
 *                   summaries, suggestions — "AI integrations and much more")
 *
 * Provider priority (first configured wins): Groq (free, fast) → Gemini
 * (free tier) → Anthropic → OpenAI. With NO key configured the service is
 * inert — moderate()/complete() return null and every caller falls back to
 * its own heuristics — so the platform runs fully without any AI key, and the
 * moment an admin pastes a key in Settings it auto-upgrades.
 */
class AiService
{
    private const MOD_SYSTEM = 'You are a content-moderation classifier for Africa GATES, a continental cultural awards platform. Reply ONLY with a JSON object {"score": 0.NN, "reason": "short"}. 0.0 = clean and on-topic; 0.5 = irrelevant or low-effort; 1.0 = spam, scam, hate, doxxing, or harassment.';

    public function __construct(
        private readonly ?string $groqKey = null,
        private readonly ?string $geminiKey = null,
        private readonly ?string $anthropicKey = null,
        private readonly ?string $openaiKey = null,
        private readonly ?string $groqModel = null,
        private readonly ?string $geminiModel = null,
        private readonly ?string $anthropicModel = null,
        private readonly ?string $openaiModel = null,
        private readonly int $timeout = 6,
    ) {}

    /** Default Groq model for moderation — the strongest widely-available one. */
    public const MODERATION_MODEL = 'llama-3.3-70b-versatile';

    /**
     * Per-provider default model, used when neither a capability pin nor an admin
     * Setting nor an env var names one.
     *
     * These were four literals buried in the four chat methods, each duplicated in
     * `activeModel()` — so the model the status panel reported and the model the
     * request actually sent were two independent copies that could disagree.
     * {@see modelFor()} is now the only place a default is chosen.
     */
    public const DEFAULT_MODELS = [
        'openai'    => 'gpt-4o-mini',
        'gemini'    => 'gemini-3.6-flash',
        'anthropic' => 'claude-haiku-4-5-20251001',
        'groq'      => 'llama-3.1-8b-instant',
    ];

    /**
     * Build from admin settings (gates_settings) with .env fallback. Usable
     * from any code path (controllers, services, cron) without DI.
     *
     * TWO Groq keys, by purpose:
     *   • 'general'    (default) — public + admin features (Gee, story polish,
     *     summaries, form/terms drafting). Uses ai_groq_key + its cheap/fast
     *     model — high volume, keeps moderation's quota clean.
     *   • 'moderation' — the spam/abuse classifier. Uses a DEDICATED Groq key
     *     (ai_groq_key_mod) on the BEST model, so safety decisions get the
     *     strongest reasoning and their own rate budget. FREE BACKUP: if the
     *     moderation key is unset it falls back to the general Groq key (still
     *     free) — and if no Groq at all, to the rest of the chain — so
     *     moderation is never left without an AI when one is configured.
     */
    public static function boot(string $purpose = 'general'): self
    {
        $resolve = static function (string $settingKey, string $envKey): ?string {
            $v = null;
            try { $v = DB::table('gates_settings')->where('key_name', $settingKey)->value('value'); }
            catch (\Throwable) {}
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '') return $v;
            $env = Env::get($envKey);
            return ($env !== null && $env !== '') ? (string) $env : null;
        };

        // Resolve BOTH Groq keys up front so either slot can power the other —
        // an admin who pasted only ONE Groq key (in either field) gets working
        // AI everywhere, not just half the platform.
        $generalKey = $resolve('ai_groq_key', 'GROQ_API_KEY');
        $modKey     = $resolve('ai_groq_key_mod', 'GROQ_MODERATION_KEY');
        $groqModel  = $resolve('ai_groq_model', 'GROQ_MODEL');

        if ($purpose === 'moderation') {
            // Dedicated moderation key when present; otherwise the general Groq
            // key as a free backup. Either way, moderation runs the best model.
            $groqKey   = $modKey ?: $generalKey;
            $groqModel = $resolve('ai_groq_model_mod', 'GROQ_MODERATION_MODEL') ?: self::MODERATION_MODEL;
        } else {
            // General features prefer the general key, but fall back to the
            // moderation key so a single configured Groq key runs everything.
            $groqKey = $generalKey ?: $modKey;
        }

        return new self(
            $groqKey,
            $resolve('ai_gemini_key', 'GEMINI_API_KEY'),
            $resolve('ai_anthropic_key', 'ANTHROPIC_API_KEY'),
            $resolve('ai_openai_key', 'OPENAI_API_KEY'),
            $groqModel,
            $resolve('ai_gemini_model', 'GEMINI_MODEL'),
            $resolve('ai_anthropic_model', 'ANTHROPIC_MODEL'),
            $resolve('ai_openai_model', 'OPENAI_MODEL'),
        );
    }

    /** True when at least one provider key is configured. */
    public function configured(): bool
    {
        return (bool) ($this->groqKey || $this->geminiKey || $this->anthropicKey || $this->openaiKey);
    }

    /** First configured provider, in priority order, or null. */
    public function activeProvider(): ?string
    {
        if ($this->groqKey)      return 'groq';
        if ($this->geminiKey)    return 'gemini';
        if ($this->anthropicKey) return 'anthropic';
        if ($this->openaiKey)    return 'openai';
        return null;
    }

    /** The model string the active provider would use (null when inert). */
    public function activeModel(): ?string
    {
        $p = $this->activeProvider();
        return $p === null ? null : $this->modelFor($p);
    }

    /** Per-provider configured flags + active provider/model, for the admin status panel. */
    public function status(): array
    {
        return [
            'groq'      => (bool) $this->groqKey,
            'gemini'    => (bool) $this->geminiKey,
            'anthropic' => (bool) $this->anthropicKey,
            'openai'    => (bool) $this->openaiKey,
            'active'    => $this->activeProvider(),
            'model'     => $this->activeModel(),
        ];
    }

    /**
     * Moderate user content. Returns {score:0..1, reason, provider} or null
     * when no provider is configured / the call fails (caller falls back).
     *
     * @return array{score:float,reason:string,provider:string}|null
     */
    public function moderate(string $text, array $context = []): ?array
    {
        $text = substr(trim($text), 0, 2000);
        if ($text === '') return null;
        $provider = $this->activeProvider();
        if ($provider === null) return null;

        try {
            // OpenAI exposes a purpose-built moderation endpoint; the rest use chat.
            if ($provider === 'openai') return $this->openaiModerate($text);

            $content = $this->complete(self::MOD_SYSTEM, $text, 80, true, 0.0);
            if ($content === null) return null;
            $score = $this->parseScore($content);
            return $score ? $score + ['provider' => $provider] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Last provider error seen during a call, for diagnostics ({@see selfTest()}).
     *
     * Protected for the same reason {@see httpPost()} is: a test double replaces the
     * one network call, and the thing worth asserting is that the PROVIDER'S OWN
     * words — its HTTP code and refusal text — survive into the per-hop record. A
     * double that could only return null would prove the plumbing while leaving the
     * part operators actually read untested.
     */
    protected ?string $lastError = null;

    /**
     * EVERY hop's failure, not just the last one.
     *
     * `$lastError` is overwritten by each hop in the chain, so on a deployment with
     * two keys — which is this one — a Groq failure followed by a Gemini failure
     * left only the Gemini reason behind. Both the error log and the admin "Test AI
     * now" button then reported one cause for two unrelated faults, and the first
     * one — the hop that fires first on every single request — was invisible.
     *
     * That is the difference between "this host cannot reach api.groq.com AND the
     * Gemini key is rejected", which is two things to fix, and "gemini said 401",
     * which invites you to replace a key that may well be fine.
     *
     * Recorded per hop as provider/model → reason, because the same HTTP code means
     * different things at different hops: 404 is a decommissioned model name on one
     * provider and a wrong endpoint path on another.
     *
     * @var list<array{provider:string,model:string,error:string}>
     */
    private array $hopErrors = [];

    /**
     * Per-hop failures from the most recent call, oldest first.
     *
     * @return list<array{provider:string,model:string,error:string}>
     */
    public function hopErrors(): array
    {
        return $this->hopErrors;
    }

    /**
     * Token usage from the most recent successful call.
     *
     * Nothing captured this before, so AI spend was unknowable even after the
     * fact — there was no way to answer "what did this cost?" for any feature.
     * {@see AiGateway} records it per call and enforces per-capability budgets
     * against it.
     *
     * @var array{in:int, out:int}
     */
    private array $lastUsage = ['in' => 0, 'out' => 0];

    /**
     * Which provider and model ACTUALLY answered the most recent call.
     *
     * Not the same question as `activeProvider()`, which reports whichever key
     * happens to be first in the priority order. The audit log recorded
     * `activeProvider()`/`activeModel()`, so after any failover — the whole point
     * of the chain — it named a provider that had just failed and a model that was
     * never called. An audit trail that is wrong precisely when something went
     * wrong is worse than no audit trail.
     */
    private ?string $lastProvider = null;
    private ?string $lastModel = null;

    /** @return array{in:int, out:int} */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    /** The provider that answered the last successful call, or null. */
    public function lastProvider(): ?string
    {
        return $this->lastProvider;
    }

    /** The model id that answered the last successful call, or null. */
    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    /**
     * Model id for $provider: an explicit override, else the configured one, else
     * the shipped default.
     *
     * The override is how a capability's pin reaches the wire. Without it,
     * `AiCapability::$model` — documented as "pinned, never whatever key happens
     * to be first" — was metadata only: the gateway read it into the log and the
     * request was still built from whichever key was configured.
     */
    public function modelFor(string $provider, ?string $override = null): string
    {
        if ($override !== null && trim($override) !== '') {
            return trim($override);
        }
        $configured = match ($provider) {
            'openai'    => $this->openaiModel,
            'gemini'    => $this->geminiModel,
            'anthropic' => $this->anthropicModel,
            'groq'      => $this->groqModel,
            default     => null,
        };
        return ($configured !== null && trim($configured) !== '')
            ? trim($configured)
            : (self::DEFAULT_MODELS[$provider] ?? self::DEFAULT_MODELS['openai']);
    }

    /** Is a key configured for $provider? */
    public function hasProvider(string $provider): bool
    {
        return match ($provider) {
            'openai'    => (bool) $this->openaiKey,
            'gemini'    => (bool) $this->geminiKey,
            'anthropic' => (bool) $this->anthropicKey,
            'groq'      => (bool) $this->groqKey,
            default     => false,
        };
    }

    /** Read whichever usage shape the provider returned. */
    private function captureUsage(?array $j): void
    {
        if (!$j) return;
        // Groq + OpenAI: usage.prompt_tokens / completion_tokens
        // Anthropic:     usage.input_tokens / output_tokens
        // Gemini:        usageMetadata.promptTokenCount / candidatesTokenCount
        $u = $j['usage'] ?? $j['usageMetadata'] ?? null;
        if (!is_array($u)) return;

        // ── THINKING IS SPEND, AND IT IS MOST OF IT ──────────────────────────
        //
        // Gemini reports reasoning tokens separately, in `thoughtsTokenCount`, and they
        // are NOT included in `candidatesTokenCount`. Counting only the latter meters a
        // classifier that thought for four hundred tokens and answered in thirty as
        // thirty — so `tokens_per_day` was measuring the wrong number by an order of
        // magnitude on exactly the capabilities whose answers are shortest, and a daily
        // cap set to protect a free tier could not do it.
        //
        // Added rather than maximised: they are disjoint counts of one bill.
        $out = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? $u['candidatesTokenCount'] ?? 0);
        $out += (int) ($u['thoughtsTokenCount'] ?? 0);

        $this->lastUsage = [
            'in'  => (int) ($u['prompt_tokens'] ?? $u['input_tokens']  ?? $u['promptTokenCount'] ?? 0),
            'out' => $out,
        ];
    }

    /** Configured providers in priority order. */
    private function providerChain(): array
    {
        $chain = [];
        if ($this->groqKey)      $chain[] = 'groq';
        if ($this->geminiKey)    $chain[] = 'gemini';
        if ($this->anthropicKey) $chain[] = 'anthropic';
        if ($this->openaiKey)    $chain[] = 'openai';
        return $chain;
    }

    /**
     * General-purpose completion. Returns the model's text (or a JSON string
     * when $json=true) or null when no provider is available / all fail.
     *
     * REAL FAILOVER: tries each configured provider in priority order and moves
     * on to the next when one errors or returns empty — previously it stopped at
     * the first configured provider, so a single Groq hiccup silently killed the
     * feature even with Gemini/Anthropic/OpenAI keys also set. When $json=true
     * the reply is unwrapped from any ```json fence before being returned.
     */
    public function complete(
        string $system,
        string $user,
        int $maxTokens = 512,
        bool $json = false,
        float $temperature = 0.2,
        array $route = [],
        int $maxAttempts = 0,
    ): ?string {
        $this->lastUsage    = ['in' => 0, 'out' => 0];
        $this->lastProvider = null;
        $this->lastModel    = null;
        $this->hopErrors    = [];

        // Consumed here and cleared in the `finally` below, so a caller that sets a timeout
        // for a long job cannot leave it set for the next, unrelated call on the same
        // instance — `AiService::boot()` results are reused within a request.
        $budget = $this->timeoutOverride;
        try {
            foreach ($this->resolveRoute($route, $maxAttempts) as [$provider, $model]) {
                // Cleared per hop so `httpPost()`'s HTTP code and the provider's own
                // body — the only text that says WHY — is attributed to this hop and
                // cannot be inherited from the previous one.
                $this->lastError = null;
                try {
                    $out = match ($provider) {
                        'groq'      => $this->groqChat($system, $user, $maxTokens, $json, $temperature, $model),
                        'gemini'    => $this->geminiChat($system, $user, $maxTokens, $json, $temperature, $model),
                        'anthropic' => $this->anthropicChat($system, $user, $maxTokens, $json, $temperature, $model),
                        'openai'    => $this->openaiChat($system, $user, $maxTokens, $json, $temperature, $model),
                        default     => null,
                    };
                    if (is_string($out) && $out !== '') {
                        // Recorded on SUCCESS only, so the audit log names what answered
                        // rather than what was tried first.
                        $this->lastProvider = $provider;
                        $this->lastModel    = $model;
                        return $json ? self::stripJsonFence($out) : $out;
                    }
                    // `lastError` already holds the HTTP code and the provider's own
                    // words when the failure was an HTTP one; the generic phrase is
                    // only for a 200 that carried nothing usable.
                    $why = $this->lastError ?? 'empty/failed response';
                } catch (\Throwable $e) {
                    $why = $e->getMessage();
                }
                $this->hopErrors[] = ['provider' => $provider, 'model' => $model, 'error' => $why];
                $this->lastError   = $provider . '/' . $model . ': ' . $why;

                // The request never reached this provider — DNS, or an egress firewall.
                // That stays true for minutes, so learn it once instead of paying a full
                // timeout for it on every subsequent call. Deliberately NOT tripped by
                // 401/429/5xx: those are the provider answering, which proves the network
                // path works and each has its own correct handling. Nor by a read timeout,
                // which also proves it — `httpPost()` tells the two apart.
                if (ProviderBreaker::isUnreachable($why)) {
                    ProviderBreaker::open($provider);
                }
                // fall through to the next hop
            }
        } finally {
            $this->timeoutOverride = null;
        }
        if ($this->hopErrors !== []) {
            // Every hop, on one line. A log that named only the last one is why
            // "AI doesn't work" stayed unexplained through several attempts to fix it.
            error_log('[AiService] all providers failed after ' . ($budget ?? $this->timeout)
                . 's per attempt — ' . self::describeHops($this->hopErrors));
        }
        return null;
    }

    /**
     * Tools cannot be carried by every provider's API in the same shape, and Gemini's is
     * different enough that a translation layer for it would be a third code path serving one
     * key nobody has configured for this feature. So the tool-capable set is named, and a route
     * asking for tools skips anything outside it rather than silently dropping them — a model
     * that never sees the tools would answer in prose forever and the ledger would stay empty
     * with nothing in the log to say why.
     */
    public const TOOL_PROVIDERS = ['openai', 'groq', 'anthropic'];

    /**
     * A conversational turn is not a 6-second job. Nor is a summary.
     *
     * `$timeout` — 6s — is right for the calls this class was built for: classify a comment,
     * draft a line of copy. It is wrong by a factor of five for a turn that reads a brief, a
     * knowledge base and twenty messages before it writes anything, and wrong by a factor of
     * twenty for a document reader that uploads files first. Rather than raise the constructor
     * default and slow every failure path across the platform, the callers that need longer
     * lift it for their own call and put it back.
     *
     * Not a parameter on {@see httpPost()}: four test doubles override that method by
     * signature, and widening it would break them all to express something only some callers
     * need. Not a parameter on {@see complete()} either, for the same reason one rung up — the
     * gateway's own test double overrides `complete()` by signature, and a subclass with fewer
     * parameters than its parent is a fatal error rather than a failing test.
     *
     * Protected for the same reason {@see httpPost()} is: the thing worth asserting is that
     * a capability's declared budget reaches the wire, and a test double intercepting the one
     * network call is the only place that can be seen. A private field would leave the fix
     * for the six-second ceiling provable only by timing a real request.
     */
    protected ?int $timeoutOverride = null;

    /**
     * Seconds allowed for the NEXT call, overriding the constructor's 6.
     *
     * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────
     *
     * {@see AiCapability} declares a timeout per capability — 4s for the classifier on the
     * nomination submit, 30s for a judge's dossier map, 120s for the document reader — and
     * for months NOTHING READ IT. Every call ran on the 6s default, so the summary and
     * drafting capabilities (12s to 120s declared) were cut off mid-generation on every
     * request: cURL reported no response, the chain walked all three hops paying six seconds
     * each, and the status page showed "0% answering" for features that were working and
     * simply not being given time to finish. It was the same class of fault the model pin had
     * — declared as data, read into the audit log, never put on the wire.
     *
     * Returns $this so a call site reads as one statement. The value is consumed and RESTORED
     * by {@see complete()}, so a stale override cannot leak from one call into the next on an
     * instance that gets reused — which `AiService::boot()` results are, inside one request.
     */
    public function withTimeout(?int $seconds): static
    {
        $this->timeoutOverride = ($seconds !== null && $seconds > 0) ? $seconds : null;
        return $this;
    }

    /** Seconds allowed for one conversational turn, tools and all, when nobody says otherwise. */
    public const CHAT_TIMEOUT = 45;

    /**
     * Seconds allowed to get CONNECTED, separate from the time allowed to answer.
     *
     * Bounded tightly and independently because reaching a provider and being answered by
     * one fail for unrelated reasons: the first is DNS or an egress firewall — the fault this
     * deployment actually has — and the second is a long generation. Without this split, a
     * capability that legitimately needs 120s to answer would also wait 120s to discover a
     * blocked outbound port, on every call.
     */
    public const CONNECT_TIMEOUT = 5;

    /**
     * A multi-turn exchange, with tools, returning the reply as a structure.
     *
     * ── WHY THIS SITS BESIDE complete() RATHER THAN REPLACING IT ──────────────
     *
     * `complete(system, user)` has around thirty callers and every one of them wants exactly
     * what it does: a string back from a single question. Rewriting them onto a message array
     * would be churn with no beneficiary, and rewriting `complete()` to delegate here would put
     * the whole platform's AI on a code path built for one feature on the day it shipped.
     *
     * So this is additive, and it reuses the parts worth reusing: the same provider chain, the
     * same {@see resolveRoute()} ordering, the same {@see ProviderBreaker} learning, the same
     * per-hop error record, the same {@see httpPost()} the tests already intercept.
     *
     * ── THE MESSAGE SHAPE IS PROVIDER-NEUTRAL ────────────────────────────────
     *
     * Callers speak one dialect and each provider adapter translates it, because the
     * alternative — callers building OpenAI payloads directly — would make the fallback chain
     * decorative. A route that falls through to Anthropic has to send Anthropic's shape or the
     * fallback is not a fallback.
     *
     *   ['role' => 'system',    'content' => string]
     *   ['role' => 'user',      'content' => string]
     *   ['role' => 'assistant', 'content' => string,
     *                           'tool_calls' => [['id'=>, 'name'=>, 'arguments'=>array]]]
     *   ['role' => 'tool',      'tool_call_id' => string, 'name' => string, 'content' => string]
     *
     * Tools are declared neutrally too — `['name'=>, 'description'=>, 'parameters'=>schema]` —
     * and each adapter wraps them the way its provider expects.
     *
     * @param list<array<string,mixed>> $messages
     * @param array{tools?:list<array<string,mixed>>, max_tokens?:int, temperature?:float,
     *              route?:list<string>, max_attempts?:int, tool_choice?:string} $opts
     */
    public function chat(array $messages, array $opts = []): ?AiReply
    {
        $this->lastUsage    = ['in' => 0, 'out' => 0];
        $this->lastProvider = null;
        $this->lastModel    = null;
        $this->hopErrors    = [];

        $tools     = array_values((array) ($opts['tools'] ?? []));
        $maxTokens = max(16, (int) ($opts['max_tokens'] ?? 900));
        $temp      = (float) ($opts['temperature'] ?? 0.4);
        $choice    = (string) ($opts['tool_choice'] ?? 'auto');

        $hops = $this->resolveRoute((array) ($opts['route'] ?? []),
                                    (int) ($opts['max_attempts'] ?? 0));
        if ($tools !== []) {
            $hops = array_values(array_filter(
                $hops, static fn(array $h): bool => in_array($h[0], self::TOOL_PROVIDERS, true)));
        }
        if ($hops === []) {
            // Said out loud rather than returned as a bare null, because "no tool-capable
            // provider is configured" and "every provider failed" need different fixes and the
            // caller cannot tell them apart from a null.
            $this->hopErrors[] = ['provider' => '-', 'model' => '-',
                'error' => $tools !== []
                    ? 'no tool-capable provider configured (need one of: '
                      . implode(', ', self::TOOL_PROVIDERS) . ')'
                    : 'no provider configured'];
            error_log('[AiService] chat() could not run — ' . self::describeHops($this->hopErrors));
            return null;
        }

        // A capability that declared its own budget keeps it; 45s is the default for a
        // conversational turn, not a ceiling imposed on one that asked for more.
        $this->timeoutOverride = max(self::CHAT_TIMEOUT, (int) $this->timeoutOverride);
        try {
            foreach ($hops as [$provider, $model]) {
                $this->lastError = null;
                try {
                    $reply = match ($provider) {
                        'openai', 'groq' => $this->openAiStyleChat($provider, $messages, $tools,
                                                                   $maxTokens, $temp, $choice, $model),
                        'anthropic'      => $this->anthropicToolChat($messages, $tools,
                                                                     $maxTokens, $temp, $choice, $model),
                        default          => null,
                    };
                    if ($reply !== null) {
                        $this->lastProvider = $provider;
                        $this->lastModel    = $model;
                        return $reply;
                    }
                    $why = $this->lastError ?? 'empty/failed response';
                } catch (\Throwable $e) {
                    $why = $e->getMessage();
                }
                $this->hopErrors[] = ['provider' => $provider, 'model' => $model, 'error' => $why];
                $this->lastError   = $provider . '/' . $model . ': ' . $why;
                if (ProviderBreaker::isUnreachable($why)) ProviderBreaker::open($provider);
            }
        } finally {
            $this->timeoutOverride = null;
        }

        error_log('[AiService] chat() failed — ' . self::describeHops($this->hopErrors));
        return null;
    }

    /**
     * OpenAI and Groq, which speak the same dialect.
     *
     * One method rather than two because the payload, the tool wrapper and the reply shape are
     * byte-identical between them — Groq built its API as a drop-in — and two copies would be
     * the drift this codebase keeps finding. Only the URL and the key differ.
     */
    private function openAiStyleChat(string $provider, array $messages, array $tools,
                                     int $maxTokens, float $temp, string $choice,
                                     ?string $model = null): ?AiReply
    {
        $payload = [
            'model'       => $this->modelFor($provider, $model),
            'max_tokens'  => $maxTokens,
            'temperature' => $temp,
            'messages'    => self::toOpenAiMessages($messages),
        ];
        if ($tools !== []) {
            $payload['tools'] = array_map(static fn(array $t): array => [
                'type'     => 'function',
                'function' => [
                    'name'        => (string) $t['name'],
                    'description' => (string) ($t['description'] ?? ''),
                    'parameters'  => (array) ($t['parameters'] ?? ['type' => 'object', 'properties' => []]),
                ],
            ], $tools);
            $payload['tool_choice'] = $choice === 'required' ? 'required' : 'auto';
        }

        [$url, $auth] = $provider === 'groq'
            ? ['https://api.groq.com/openai/v1/chat/completions', 'Bearer ' . $this->groqKey]
            : ['https://api.openai.com/v1/chat/completions',      'Bearer ' . $this->openaiKey];

        $j = $this->httpPost($url, ['Authorization: ' . $auth], $payload);
        if ($j === null) return null;
        $this->captureUsage($j);

        $m = $j['choices'][0]['message'] ?? null;
        if (!is_array($m)) return null;

        $calls = [];
        foreach ((array) ($m['tool_calls'] ?? []) as $c) {
            $name = (string) ($c['function']['name'] ?? '');
            if ($name === '') continue;
            $calls[] = [
                'id'   => (string) ($c['id'] ?? ''),
                'name' => $name,
                // Arguments arrive as a JSON STRING, and a model can emit one that does not
                // parse. A malformed call becomes an empty argument set rather than an
                // exception: the turn is still worth showing, and the validation layer refuses
                // an empty call for its own reasons anyway.
                'arguments' => self::decodeArguments((string) ($c['function']['arguments'] ?? '')),
            ];
        }

        $text = trim((string) ($m['content'] ?? ''));
        // A turn with neither prose nor a tool call is a failed turn, and treating it as
        // success would show the nominee an empty bubble and move the conversation on.
        if ($text === '' && $calls === []) return null;

        return new AiReply($text, $calls, $this->lastUsage, $provider,
                           $this->modelFor($provider, $model),
                           self::stopReasonFrom((string) ($j['choices'][0]['finish_reason'] ?? '')));
    }

    /**
     * Anthropic, whose messages are content BLOCKS and whose system prompt is its own field.
     *
     * Kept as a real adapter rather than a "close enough" one because the difference is not
     * cosmetic: a tool result posted in OpenAI's shape is rejected outright, so a fallback that
     * only looked like it worked would fail on exactly the turn the primary provider went down.
     */
    private function anthropicToolChat(array $messages, array $tools, int $maxTokens,
                                       float $temp, string $choice, ?string $model = null): ?AiReply
    {
        [$system, $turns] = self::toAnthropicMessages($messages);

        $payload = [
            'model'       => $this->modelFor('anthropic', $model),
            'max_tokens'  => $maxTokens,
            'temperature' => $temp,
            'messages'    => $turns,
        ];
        if ($system !== '') $payload['system'] = $system;
        if ($tools !== []) {
            $payload['tools'] = array_map(static fn(array $t): array => [
                'name'         => (string) $t['name'],
                'description'  => (string) ($t['description'] ?? ''),
                'input_schema' => (array) ($t['parameters'] ?? ['type' => 'object', 'properties' => []]),
            ], $tools);
            if ($choice === 'required') $payload['tool_choice'] = ['type' => 'any'];
        }

        $j = $this->httpPost('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $this->anthropicKey,
            'anthropic-version: 2023-06-01',
        ], $payload);
        if ($j === null) return null;
        $this->captureUsage($j);

        $text = '';
        $calls = [];
        foreach ((array) ($j['content'] ?? []) as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $name = (string) ($block['name'] ?? '');
                if ($name === '') continue;
                $calls[] = ['id' => (string) ($block['id'] ?? ''), 'name' => $name,
                            'arguments' => (array) ($block['input'] ?? [])];
            }
        }

        $text = trim($text);
        if ($text === '' && $calls === []) return null;

        return new AiReply($text, $calls, $this->lastUsage, 'anthropic',
                           $this->modelFor('anthropic', $model),
                           self::stopReasonFrom((string) ($j['stop_reason'] ?? '')));
    }

    /**
     * The neutral message list as OpenAI wants it.
     *
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private static function toOpenAiMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            if ($role === 'tool') {
                $out[] = ['role' => 'tool',
                          'tool_call_id' => (string) ($m['tool_call_id'] ?? ''),
                          'content' => (string) ($m['content'] ?? '')];
                continue;
            }
            $row = ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $row['tool_calls'] = array_map(static fn(array $c): array => [
                    'id'       => (string) ($c['id'] ?? ''),
                    'type'     => 'function',
                    'function' => ['name' => (string) ($c['name'] ?? ''),
                                   'arguments' => (string) json_encode((array) ($c['arguments'] ?? []))],
                ], (array) $m['tool_calls']);
                // OpenAI rejects a null content on an assistant turn that carries tool_calls,
                // and accepts an empty string. A model that called a tool without saying
                // anything is the ordinary case, not an edge one.
                $row['content'] = (string) ($m['content'] ?? '');
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The neutral message list as Anthropic wants it: system hoisted out, everything else in
     * content blocks.
     *
     * System messages are CONCATENATED rather than the last one winning, because the interview
     * assembles its system side from three separately-authored parts — the admin's brief, the
     * knowledge base, and the outcome list — and dropping any of them would change what the
     * model is trying to achieve without any error to notice.
     *
     * @param list<array<string,mixed>> $messages
     * @return array{0:string, 1:list<array<string,mixed>>}
     */
    private static function toAnthropicMessages(array $messages): array
    {
        $system = [];
        $turns  = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $body = (string) ($m['content'] ?? '');

            if ($role === 'system') {
                if (trim($body) !== '') $system[] = $body;
                continue;
            }

            if ($role === 'tool') {
                // A tool RESULT is a user-role block in Anthropic's model. Consecutive results
                // are merged into one user turn, because the API refuses two user turns in a
                // row and a turn that recorded three outcomes produces three results.
                $block = ['type' => 'tool_result',
                          'tool_use_id' => (string) ($m['tool_call_id'] ?? ''),
                          'content' => $body];
                $last = array_key_last($turns);
                if ($last !== null && $turns[$last]['role'] === 'user'
                    && is_array($turns[$last]['content'] ?? null)
                    && ($turns[$last]['content'][0]['type'] ?? '') === 'tool_result') {
                    $turns[$last]['content'][] = $block;
                } else {
                    $turns[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }

            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $blocks = [];
                if (trim($body) !== '') $blocks[] = ['type' => 'text', 'text' => $body];
                foreach ((array) $m['tool_calls'] as $c) {
                    $blocks[] = ['type' => 'tool_use', 'id' => (string) ($c['id'] ?? ''),
                                 'name' => (string) ($c['name'] ?? ''),
                                 'input' => (array) ($c['arguments'] ?? [])];
                }
                $turns[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }

            $turns[] = ['role' => $role, 'content' => [['type' => 'text', 'text' => $body]]];
        }
        return [implode("\n\n", $system), $turns];
    }

    /**
     * Tool arguments, which arrive as a JSON string from the OpenAI-style providers.
     *
     * Tolerant of a markdown fence for the same reason {@see stripJsonFence()} exists: some
     * models wrap even an arguments payload, and a refused call over three backticks would look
     * to the nominee like the interview simply not hearing them.
     *
     * @return array<string,mixed>
     */
    private static function decodeArguments(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $j = json_decode(self::stripJsonFence($raw), true);
        return is_array($j) ? $j : [];
    }

    /** Each provider's own word for why it stopped, folded to four we handle. */
    private static function stopReasonFrom(string $raw): string
    {
        return match ($raw) {
            'tool_calls', 'tool_use'    => 'tools',
            'length', 'max_tokens'      => 'length',
            'stop', 'stop_sequence',
            'end_turn'                  => 'stop',
            default                     => $raw === '' ? 'stop' : 'other',
        };
    }

    /**
     * Per-hop failures as one readable line.
     *
     * Shared by the error log and the admin health check so an operator reading
     * either one is looking at the same text, rather than two summaries that have
     * to be reconciled before they can be acted on.
     *
     * @param list<array{provider:string,model:string,error:string}> $hops
     */
    public static function describeHops(array $hops): string
    {
        if ($hops === []) return 'no provider was tried — no key is configured.';

        $parts = [];
        foreach ($hops as $h) {
            $parts[] = $h['provider'] . '/' . $h['model'] . ' → ' . $h['error'];
        }
        return implode(' | ', $parts);
    }

    /**
     * Turn a declared route into the ordered list of (provider, model) hops to try.
     *
     * A route is a list of `provider:model` strings — a capability's pin followed by
     * its declared fallbacks. Hops whose provider has no key are dropped rather than
     * attempted, and every remaining CONFIGURED provider is appended at the end so a
     * pin can never make a feature unavailable on a deployment that has keys: the
     * pin decides preference, not eligibility.
     *
     * An empty route means "no preference", which reproduces the previous
     * key-priority behaviour exactly — that is what every legacy caller gets.
     *
     * $maxAttempts caps the FINAL list, after ineligible hops are dropped — so a
     * cap of 1 still yields one usable hop on a deployment whose only key is a
     * provider the capability never mentions. Capping the declared route before
     * filtering would have turned the ceiling into an availability switch.
     *
     * @param  list<string> $route
     * @return list<array{0:string,1:string}>
     */
    private function resolveRoute(array $route, int $maxAttempts = 0): array
    {
        $hops = [];
        $seen = [];
        foreach ($route as $spec) {
            if (!is_string($spec) || trim($spec) === '') continue;
            $parts    = explode(':', trim($spec), 2);
            $provider = $parts[0];
            $model    = $this->modelFor($provider, $parts[1] ?? null);
            if (!$this->hasProvider($provider)) continue;
            $key = $provider . '/' . $model;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $hops[] = [$provider, $model];
        }
        // Then anything else configured, on its own default/settings model.
        foreach ($this->providerChain() as $provider) {
            $model = $this->modelFor($provider);
            $key   = $provider . '/' . $model;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $hops[] = [$provider, $model];
        }

        // ── SKIP PROVIDERS THAT ARE NOT REACHABLE AT ALL ─────────────────────
        //
        // Only ever a REORDERING, never a removal: if every hop is open-circuit the
        // full list is used unchanged. A cache row must not be the thing that makes
        // the platform unable to think, so the worst this can do is change what is
        // tried first. See ProviderBreaker.
        $live = array_values(array_filter($hops,
            static fn(array $h): bool => !ProviderBreaker::isOpen($h[0])));
        if ($live !== []) $hops = $live;

        return $maxAttempts > 0 ? array_slice($hops, 0, $maxAttempts) : $hops;
    }

    /**
     * On-demand health check: make one tiny real completion and report which
     * provider answered (or the error). Powers the admin Settings "test AI"
     * button so "AI doesn't work" is diagnosable instead of silent.
     *
     * @return array{ok:bool,provider:?string,model:?string,error:?string,
     *               hops:list<array{provider:string,model:string,error:string}>,cause:?string}
     */
    public function selfTest(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'provider' => null, 'model' => null, 'hops' => [],
                    'error' => 'No provider key configured.',
                    'cause' => 'Set GROQ_API_KEY or GEMINI_API_KEY, in the .env or in admin Settings. '
                             . 'Note that a key present but blank counts as unset.'];
        }
        // Somebody pressing "Test AI now" is asking what happens if we try RIGHT NOW.
        // Answering from a five-minute-old breaker would report a provider as failing
        // seconds after the host unblocked the firewall, and send them chasing a fault
        // that no longer exists. So the health check always makes real attempts.
        ProviderBreaker::clearAll();

        $this->lastError = null;
        $out = $this->complete('You are a health check. Reply with the single word OK.', 'ping', 8, false, 0.0);
        $ok = is_string($out) && $out !== '';
        $hops = $this->hopErrors();

        return [
            'ok' => $ok,
            // What ANSWERED, falling back to what would be tried first only when
            // nothing answered. A health check that names the provider it prefers
            // rather than the one that worked is the same lie as the audit log's.
            'provider' => $ok ? $this->lastProvider() : $this->activeProvider(),
            'model'    => $ok ? $this->lastModel()    : $this->activeModel(),
            'hops'     => $hops,
            'error'    => $ok ? null : self::describeHops($hops),
            'cause'    => $ok ? null : self::likelyCause($hops),
        ];
    }

    /** The four providers this class can call, in the order `activeProvider()` prefers them. */
    public const PROVIDERS = ['groq', 'gemini', 'anthropic', 'openai'];

    /**
     * Probe EVERY provider on its own, and report each one's verdict separately.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS EXISTS ALONGSIDE selfTest()
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `selfTest()` answers "can the platform think", and it answers it the way the platform
     * actually works: walk the ladder, stop at the first provider that answers. That is the
     * right question for "AI doesn't work" — and it is precisely the wrong instrument for
     * "Gemini doesn't work", because a healthy Groq at the top of the ladder means Gemini is
     * NEVER TRIED and the console reports a green tick.
     *
     * So a provider can be misconfigured, unfunded, blocked by the host's egress firewall or
     * pinned to a decommissioned model for months, and the only symptom is that fallback
     * quietly does nothing on the day the primary goes down — which is the day you needed it.
     * A ladder whose lower rungs have never been stood on is not a ladder.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE BREAKER IS CLEARED ON EVERY ITERATION, NOT ONCE AT THE TOP
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see resolveRoute()} appends every other configured provider after the declared hop,
     * and then REORDERS to drop open-circuit providers. So if this loop probes Gemini,
     * Gemini fails as unreachable and trips its breaker, then probes Anthropic — the Gemini
     * row would be fine, but a LATER probe of a provider whose breaker had opened would find
     * its own hop filtered out and `array_slice(…, 0, 1)` would hand back a DIFFERENT
     * provider's result under its name. Clearing per iteration makes each row mean what its
     * label says.
     *
     * Every provider is reported, including ones with no key, because "not configured" and
     * "configured and broken" are different problems with different fixes and an operator
     * cannot tell them apart from an absence.
     *
     * @return list<array{provider:string, configured:bool, model:?string, ok:bool,
     *                    ms:int, error:?string, cause:?string}>
     */
    public function probeAll(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $provider) {
            if (!$this->hasProvider($provider)) {
                $out[] = ['provider' => $provider, 'configured' => false, 'model' => null,
                          'ok' => false, 'ms' => 0, 'error' => null,
                          'cause' => 'No key for ' . $provider . '. A key that is present but blank '
                                   . 'counts as unset.'];
                continue;
            }

            ProviderBreaker::clearAll();   // see the note above — per iteration, deliberately

            $model = $this->modelFor($provider);
            $t0    = microtime(true);
            // One hop, this provider, nothing else: maxAttempts = 1 truncates the ladder
            // resolveRoute() would otherwise append.
            $reply = $this->complete('You are a health check. Reply with the single word OK.',
                                     'ping', 8, false, 0.0, [$provider . ':'], 1);
            $ms    = (int) round((microtime(true) - $t0) * 1000);
            $hops  = $this->hopErrors();
            $ok    = is_string($reply) && $reply !== '';

            $out[] = [
                'provider'   => $provider,
                'configured' => true,
                'model'      => $model,
                'ok'         => $ok,
                'ms'         => $ms,
                'error'      => $ok ? null : ($hops[0]['error'] ?? 'empty/failed response'),
                'cause'      => $ok ? null : self::likelyCause($hops),
            ];
        }

        // Left cleared rather than restored. The breaker exists to stop the hot path paying a
        // full timeout for a provider that is down; a probe that has just measured the truth
        // is better evidence than a cache row written before it, in either direction.
        ProviderBreaker::clearAll();

        return $out;
    }

    /**
     * Turn the providers' HTTP codes into the thing an operator has to go and change.
     *
     * The raw text is kept as well — this never replaces it — because a guess that
     * displaces the provider's own words is how you end up fixing the wrong thing.
     * But "HTTP 401 {\"error\":{\"message\":\"Invalid API Key\"...}}" in a flash
     * message is not an instruction, and the person reading it has no shell to go
     * digging with. Each mapping below is the ONE action that clears that code.
     *
     * `HTTP 0` deserves its mention: it is not a provider answer at all, it is the
     * request never arriving. On shared hosting that is usually the account's own
     * outbound firewall, which no amount of key-rotation will fix, and it is the
     * failure most likely to be misread as "the key expired".
     *
     * @param list<array{provider:string,model:string,error:string}> $hops
     */
    public static function likelyCause(array $hops): ?string
    {
        if ($hops === []) return null;

        $causes = [];
        foreach ($hops as $h) {
            $e = $h['error'];
            $at = $h['provider'];

            if (preg_match('~\bHTTP 0\b~', $e)) {
                $causes[] = "{$at}: the request never reached the provider — DNS, egress firewall or a "
                          . "blocked outbound port on this host. Rotating the key will not help. Ask the "
                          . "host whether outbound HTTPS to the provider's API domain is permitted.";
            } elseif (str_starts_with($e, 'TIMEOUT')) {
                // A DIFFERENT fault from the one above, and it took months to see because
                // both reported HTTP 0. This provider is reachable and was answering — it
                // simply had less time than the answer needed. The fix is the capability's
                // own budget, not the key, the model or the host.
                $causes[] = "{$at}: connected, but did not finish answering inside the time this feature "
                          . "allows. Nothing is wrong with the key or the network — the job needs longer "
                          . "than its declared timeout, or a shorter output. Raise the capability's "
                          . "`timeout` in AiCapability.";
            } elseif (preg_match('~\bHTTP 40[13]\b~', $e)) {
                $causes[] = "{$at}: the key was rejected — expired, revoked, or from a different project. "
                          . "Issue a new one and put it in admin Settings.";
            } elseif (preg_match('~\bHTTP 429\b~', $e)) {
                $causes[] = "{$at}: rate-limited or out of quota. Free tiers reset — check the provider's "
                          . "usage page before changing anything.";
            } elseif (preg_match('~\bHTTP 404\b~', $e)) {
                $causes[] = "{$at}: the model name is not served — '{$h['model']}' has most likely been "
                          . "decommissioned. Set a current model for {$at} in admin Settings.";
            } elseif (preg_match('~\bHTTP 5\d\d\b~', $e)) {
                $causes[] = "{$at}: the provider itself is erroring. Nothing to change here; retry later.";
            } elseif (str_contains($e, 'MAX_TOKENS')) {
                // Reached only after the retry at GEMINI_RETRY_OUTPUT already failed, so
                // this is a model that wants more room than any short capability declares
                // — not the starved-budget case, which now fixes itself.
                $causes[] = "{$at}: answered 200 but spent its whole output budget reasoning before "
                          . "writing anything, twice. '{$h['model']}' needs more room than this feature "
                          . "allows — raise the capability's `max_tokens` in AiCapability, or set a "
                          . "non-reasoning model for {$at} in admin Settings.";
            } elseif (str_contains($e, 'blockReason') || str_contains($e, 'withheld')) {
                // Deliberately NOT actionable as configuration: the provider made a content
                // decision. An operator who reads this as a fault will change keys and models
                // for a week without moving it.
                $causes[] = "{$at}: the provider refused this particular text on its own safety rules. "
                          . "Nothing is wrong with the key, the model or the host, and changing them "
                          . "will not move it — the same input will be refused again.";
            } elseif (str_contains($e, 'no candidates')) {
                $causes[] = "{$at}: answered 200 with an empty envelope. Usually a request shape the "
                          . "model does not accept rather than a key problem.";
            } elseif (str_contains($e, 'empty/failed response')) {
                $causes[] = "{$at}: answered 200 with nothing usable — usually a model that rejected the "
                          . "request shape rather than a key problem.";
            }
        }

        return $causes === [] ? null : implode(' ', array_unique($causes));
    }

    /** Strip a leading/trailing markdown ```json … ``` fence some models wrap JSON in. */
    private static function stripJsonFence(string $s): string
    {
        $t = trim($s);
        if (str_starts_with($t, '```')) {
            $t = (string) preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $t);
            $t = (string) preg_replace('/\s*```$/', '', $t);
        }
        return trim($t);
    }

    // ── Provider: Groq (free, fast, OpenAI-compatible) ─────────────────────
    private function groqChat(string $system, string $user, int $maxTokens, bool $json, float $temp, ?string $model = null): ?string
    {
        $payload = [
            'model'       => $this->modelFor('groq', $model),
            'temperature' => $temp,
            'max_tokens'  => $maxTokens,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ];
        if ($json) $payload['response_format'] = ['type' => 'json_object'];
        $j = $this->httpPost('https://api.groq.com/openai/v1/chat/completions', ['Authorization: Bearer ' . $this->groqKey], $payload);
        $this->captureUsage($j);
        $c = $j['choices'][0]['message']['content'] ?? null;
        return (is_string($c) && $c !== '') ? $c : null;
    }

    // ── Provider: Google Gemini (free tier) ────────────────────────────────

    /**
     * The floor under Gemini's output budget, and the reason this provider needs one.
     *
     * `maxOutputTokens` on a Gemini flash model is the budget for THINKING PLUS ANSWER,
     * not for the answer. A reasoning model handed 70 spends all 70 thinking and returns
     * HTTP 200 carrying `finishReason: MAX_TOKENS` and no `parts` array at all — a
     * success, with nothing in it.
     *
     * Six capabilities on this platform declare a budget under a hundred tokens because
     * their answer is a word or a small JSON object: `moderation.classify` at 80, which
     * is every public submission; `questionnaire.chat` at 90, `questionnaire.coach` at 70
     * and `interview.followup` at 90, which are the judges' three. Against a thinking
     * model those do not fail sometimes. They cannot succeed. That is the whole of
     * "Gemini does not work", and of "none of the AI works" on a deployment whose only
     * key is a Gemini one.
     *
     * A ceiling is not a target: raising it cannot make a model answer at greater length,
     * and a classifier still emits its thirty tokens. What it buys is room to think first.
     * Real spend is metered from `usageMetadata` after the fact, so the daily budget stays
     * honest either way — see {@see captureUsage()}.
     */
    private const GEMINI_MIN_OUTPUT = 768;

    /** The one retry's ceiling, for a model that wanted more room than the floor gave it. */
    private const GEMINI_RETRY_OUTPUT = 2048;

    /**
     * Ask a Gemini 3 model to think BRIEFLY, which is the other half of the fix.
     *
     * The budget floor below stops a short capability starving; this stops it paying for
     * reasoning it does not need. A spam classifier returning one JSON object does not
     * benefit from four hundred tokens of deliberation, and on a free tier that reasoning
     * is the whole bill.
     *
     * `thinkingLevel` is the Gemini 3 control. `thinkingBudget` is the legacy one and
     * Google warns against it on 3.x, and the two may not both be sent. Gated on the model
     * name rather than sent blind, because an operator may set a 2.x model in Settings and
     * an unknown field is a 400 — a working provider turned off by a parameter meant to
     * tune it. Those models keep the floor and the retry, which is what they had.
     *
     * @return array<string,mixed> merged into generationConfig, or nothing
     */
    private static function geminiThinking(string $model): array
    {
        return str_starts_with($model, 'gemini-3')
            ? ['thinkingConfig' => ['thinkingLevel' => 'low']]
            : [];
    }

    private function geminiChat(string $system, string $user, int $maxTokens, bool $json, float $temp, ?string $model = null): ?string
    {
        $model  = $this->modelFor('gemini', $model);
        $url    = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . urlencode((string) $this->geminiKey);
        $budget = max($maxTokens, self::GEMINI_MIN_OUTPUT);
        $think  = self::geminiThinking($model);

        $stop = '';
        $text = $this->geminiAttempt($url, $system, $user, $budget, $json, $temp, $stop, $think);
        if ($text !== null) return $text;

        // Only ever retried for the one fault a retry can fix. A safety block, a bad key
        // or a missing model returns the same answer the second time and the capability
        // is waiting on a synchronous request.
        if ($stop === 'MAX_TOKENS' && $budget < self::GEMINI_RETRY_OUTPUT) {
            $text = $this->geminiAttempt($url, $system, $user, self::GEMINI_RETRY_OUTPUT, $json, $temp, $stop, $think);
            if ($text !== null) return $text;
        }

        return null;
    }

    /**
     * One call, and a REASON when it comes back empty.
     *
     * A 200 that carries no text used to reach the ladder as "empty/failed response" —
     * the same words as a socket that never opened. Gemini says exactly what happened in
     * `finishReason` and `promptFeedback.blockReason`, and nothing read either, so the
     * admin status page and the error log both described a working integration returning
     * nothing. The platform has shipped six of these; §17 of the codebase index is the
     * list.
     *
     * @param string $stop out: the finish reason, so the caller can tell the one
     *                     recoverable failure from the five that are not
     */
    private function geminiAttempt(string $url, string $system, string $user, int $budget,
                                   bool $json, float $temp, string &$stop, array $think = []): ?string
    {
        $cfg = ['temperature' => $temp, 'maxOutputTokens' => $budget] + $think;
        if ($json) $cfg['responseMimeType'] = 'application/json';

        $j = $this->httpPost($url, [], [
            'contents'          => [['parts' => [['text' => $user]]]],
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'generationConfig'  => $cfg,
        ]);
        $this->captureUsage($j);

        // An HTTP failure has already written its code and the provider's own words into
        // lastError; saying anything else here would overwrite the better message.
        if ($j === null) { $stop = ''; return null; }

        $cand = $j['candidates'][0] ?? [];
        $stop = (string) ($cand['finishReason'] ?? '');

        // The parts array is a LIST and the text is not always in the first entry — a
        // model that returns a thought part alongside its answer puts the answer second.
        $text = '';
        foreach ((array) ($cand['content']['parts'] ?? []) as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }
        if ($text !== '') return $text;

        $this->lastError = self::geminiWhyEmpty($stop, $j, $budget);

        return null;
    }

    /** Why a 200 carried no text, in words an operator can act on. */
    private static function geminiWhyEmpty(string $stop, array $j, int $budget): string
    {
        $blocked = (string) ($j['promptFeedback']['blockReason'] ?? '');
        if ($blocked !== '') {
            return 'HTTP 200 but the PROMPT was blocked before the model saw it (blockReason '
                 . $blocked . ')';
        }

        return match ($stop) {
            'MAX_TOKENS' => 'HTTP 200 but no text: the whole ' . $budget . '-token output budget '
                          . 'went on reasoning before the model answered (finishReason MAX_TOKENS)',
            'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST' =>
                'HTTP 200 but the answer was withheld (finishReason ' . $stop . ')',
            'RECITATION' => 'HTTP 200 but the answer was withheld as a recitation of training data '
                          . '(finishReason RECITATION)',
            ''  => 'HTTP 200 with no candidates at all',
            default => 'HTTP 200 but no text (finishReason ' . $stop . ')',
        };
    }

    // ── Provider: Anthropic (Claude Haiku) ─────────────────────────────────
    private function anthropicChat(string $system, string $user, int $maxTokens, bool $json, float $temp, ?string $model = null): ?string
    {
        $payload = [
            'model'      => $this->modelFor('anthropic', $model),
            'max_tokens' => $maxTokens,
            'temperature'=> $temp,
            'system'     => $system . ($json ? ' Reply with ONLY a valid JSON object.' : ''),
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];
        $j = $this->httpPost('https://api.anthropic.com/v1/messages', ['x-api-key: ' . $this->anthropicKey, 'anthropic-version: 2023-06-01'], $payload);
        $this->captureUsage($j);
        $c = $j['content'][0]['text'] ?? null;
        return (is_string($c) && $c !== '') ? $c : null;
    }

    // ── Provider: OpenAI (chat completions) ────────────────────────────────
    private function openaiChat(string $system, string $user, int $maxTokens, bool $json, float $temp, ?string $model = null): ?string
    {
        $payload = [
            'model'       => $this->modelFor('openai', $model),
            'max_tokens'  => $maxTokens,
            'temperature' => $temp,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ];
        if ($json) $payload['response_format'] = ['type' => 'json_object'];
        $j = $this->httpPost('https://api.openai.com/v1/chat/completions', ['Authorization: Bearer ' . $this->openaiKey], $payload);
        $this->captureUsage($j);
        $c = $j['choices'][0]['message']['content'] ?? null;
        return (is_string($c) && $c !== '') ? $c : null;
    }

    /** OpenAI's purpose-built moderation endpoint → {score, reason}. */
    private function openaiModerate(string $text): ?array
    {
        $j = $this->httpPost('https://api.openai.com/v1/moderations', ['Authorization: Bearer ' . $this->openaiKey], ['model' => 'omni-moderation-latest', 'input' => $text]);
        $r = $j['results'][0] ?? null;
        if (!$r) return null;
        // Take the strongest signal across EVERY returned category (incl. omni
        // sub-categories like sexual/minors, illicit), not a hardcoded subset.
        $score = 0.0;
        foreach ((array) ($r['category_scores'] ?? []) as $v) {
            $score = max($score, (float) $v);
        }
        if (!empty($r['flagged'])) $score = max($score, 0.7);
        return ['score' => $score, 'reason' => !empty($r['flagged']) ? 'flagged by OpenAI' : 'cleared by OpenAI', 'provider' => 'openai'];
    }

    // ── Shared HTTP + parsing helpers ──────────────────────────────────────

    /**
     * Is the bearer token on this request something no provider could ever accept?
     *
     * TWO RULES, both chosen so they cannot catch a real key.
     *
     *   · Too short. The shortest credential any of these four providers issues is a
     *     Gemini key at `AIza` plus thirty-five characters; OpenAI is `sk-` plus
     *     forty-eight, Groq `gsk_` plus fifty-two. Twenty is far below all of them and
     *     comfortably above the `sk-test` that a fixture uses.
     *   · A marker no issued key contains. Same shape as the placeholder list in
     *     {@see OtpService::smtpConfigured()}, and for the same reason: the values people
     *     leave in a config file are a known, small set.
     *
     * Deliberately NOT a format check on the prefix. Providers change those — `sk-proj-`
     * did not exist when `sk-` was the whole convention — and a validator that rejects a
     * real key is a far worse failure than one that dials a fake one.
     *
     * The URL is inspected as well as the headers, and that is not belt-and-braces: Gemini
     * takes its key as a `?key=` query parameter and calls httpPost with NO headers at all,
     * so a header-only check would have left exactly one of the four providers still
     * dialling on a placeholder.
     *
     * @param list<string> $headers
     */
    private static function unusableCredential(string $url, array $headers): bool
    {
        $candidates = [];

        $q = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($q !== '') {
            parse_str($q, $params);
            foreach (['key', 'api_key', 'apikey'] as $name) {
                if (isset($params[$name]) && is_string($params[$name])) $candidates[] = $params[$name];
            }
        }

        foreach ($candidates as $key) {
            if (self::looksUnusable($key)) return true;
        }

        foreach ($headers as $h) {
            if (stripos((string) $h, 'authorization:') !== 0
                && stripos((string) $h, 'x-api-key:') !== 0) continue;

            $key = trim((string) preg_replace('~^[^:]+:\s*(Bearer\s+)?~i', '', (string) $h));
            if (self::looksUnusable($key)) return true;
        }

        return false;
    }

    /** The two rules above, applied to one credential. */
    private static function looksUnusable(string $key): bool
    {
        $key = trim($key);
        if ($key === '' || strlen($key) < 20) return true;

        foreach (['not-a-real-key', 'placeholder', 'changeme', 'change-me',
                  'your_', 'your-key', 'yourkey', 'example-key', 'dummy'] as $marker) {
            if (stripos($key, $marker) !== false) return true;
        }

        return false;
    }

    /**
     * Protected, not private, so a test can intercept the ONE network call without
     * bypassing anything else. Overriding the four per-provider methods instead
     * would skip `modelFor()` and the payload assembly — i.e. skip the code that
     * decides which model is requested, which is the part worth testing.
     */
    protected function httpPost(string $url, array $headers, array $payload): ?array
    {
        // ── A CREDENTIAL THAT CANNOT WORK IS NOT DIALLED ─────────────────────
        //
        // Refused here rather than at boot() on purpose: a placeholder must still count as
        // CONFIGURED, because the screens that show "AI is set up" are driven by that and
        // several tests seed a key precisely to render that state. What must not happen is
        // the round trip.
        //
        // It was happening. Three tests seed `sk-test-not-a-real-key` into gates_settings
        // and the suite dialled api.openai.com for real on every run — OpenAI's own 401 is
        // in the log, so the request left the machine. That made the suite depend on
        // outbound reachability, pay a handshake per call, and send traffic to a third
        // party on every CI build, all to reach a failure path that is reached identically
        // without leaving the process.
        //
        // Production gets the same benefit: an unedited placeholder can only ever return
        // 401, so dialling it is pure latency on a request somebody is waiting for.
        if (self::unusableCredential($url, $headers)) {
            error_log('[AiService] refusing to send a placeholder credential to ' . $url);
            return null;
        }

        $body  = json_encode($payload);
        $limit = max(1, (int) ($this->timeoutOverride ?? $this->timeout));

        // Up to 2 attempts: retry once on a TRANSIENT failure (429 rate-limit or 5xx),
        // which are exactly the errors that made AI "randomly not work". Permanent errors
        // (4xx auth/bad-request) don't retry, and nor does anything that consumed the whole
        // time budget — see below.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $limit,
                // ── A SEPARATE, SHORT CEILING ON GETTING CONNECTED AT ALL ─────────
                //
                // Now that a capability's own budget reaches this method, the total
                // timeout runs from 4s to 120s — and without this, a host that cannot
                // reach the provider at all would spend the WHOLE of that budget
                // discovering it. The document reader would sit for two minutes on a
                // blocked outbound port before trying anything else.
                //
                // Connecting is not the slow part of a model call: TCP and TLS to a
                // provider's edge is well under a second from anywhere that can reach it
                // at all, so a few seconds is generous while still failing fast. Never
                // longer than the total, or a small budget would be spent entirely on
                // the handshake.
                CURLOPT_CONNECTTIMEOUT => min($limit, self::CONNECT_TIMEOUT),
                CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
                CURLOPT_POSTFIELDS     => $body,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // Non-zero the moment the TCP+TLS handshake completes, and it is the whole
            // discriminator below.
            $connected = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0.0;
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($code === 200 && $resp) {
                $j = json_decode((string) $resp, true);
                return is_array($j) ? $j : null;
            }

            // ── "NEVER ARRIVED" AND "RAN OUT OF TIME" ARE DIFFERENT FAULTS ───────
            //
            // Both give an HTTP code of 0, and treating them as one thing was wrong in
            // both directions. `ProviderBreaker` skips a provider for five minutes on the
            // strength of this text, and its whole justification is that unreachability is
            // a FACT THAT STAYS TRUE — DNS, or the account's outbound firewall. A provider
            // that connected fine and then took longer than its budget is not that: it is
            // reachable, working, and possibly just slow, and sidelining it for five
            // minutes takes a working feature down to save a few hundred milliseconds.
            //
            // `CONNECT_TIME` settles it without guessing at cURL's wording: it is only
            // non-zero once the handshake completed, which proves the network path.
            //
            // So `HTTP 0` now means exactly what ProviderBreaker's docblock has always
            // claimed it means, and a read timeout says so in its own words instead.
            $timedOut = ($code === 0 && $connected);
            $this->lastError = ($timedOut
                    ? 'TIMEOUT after ' . $limit . 's (connected, but no reply in time)'
                    : 'HTTP ' . $code)
                . ($cerr !== '' ? ' (' . $cerr . ')' : '')
                . ($resp ? ' ' . substr((string) $resp, 0, 160) : '');

            // A retry is only ever worth 0.3s of backoff plus one more attempt, and both
            // `HTTP 0` and a timeout have already spent the budget: the first is a
            // certainty that will not clear in 300ms, and the second would DOUBLE the
            // wait a capability declared as its limit. Retrying a 120s document read into
            // a 240s one is how a request outlives the front end waiting on it.
            $transient = ($code === 429 || $code >= 500);
            if (!$transient || $attempt === 2) return null;
            usleep(300000); // 0.3s backoff before the single retry
        }
        return null;
    }

    /** Pull the first {"score":..,"reason":..} object out of a model reply. */
    private function parseScore(string $content): ?array
    {
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $p = json_decode($m[0], true);
            if (is_array($p) && isset($p['score'])) {
                return [
                    'score'  => max(0.0, min(1.0, (float) $p['score'])),
                    'reason' => (string) ($p['reason'] ?? 'ai-classified'),
                ];
            }
        }
        return null;
    }
}
