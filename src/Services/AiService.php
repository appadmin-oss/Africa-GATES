<?php
declare(strict_types=1);

namespace AfricaGates\Services;

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
        $this->lastUsage = [
            'in'  => (int) ($u['prompt_tokens'] ?? $u['input_tokens']  ?? $u['promptTokenCount']     ?? 0),
            'out' => (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? $u['candidatesTokenCount'] ?? 0),
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
            // path works and each has its own correct handling.
            if (ProviderBreaker::isUnreachable($why)) {
                ProviderBreaker::open($provider);
            }
            // fall through to the next hop
        }
        if ($this->hopErrors !== []) {
            // Every hop, on one line. A log that named only the last one is why
            // "AI doesn't work" stayed unexplained through several attempts to fix it.
            error_log('[AiService] all providers failed — ' . self::describeHops($this->hopErrors));
        }
        return null;
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
    private function geminiChat(string $system, string $user, int $maxTokens, bool $json, float $temp, ?string $model = null): ?string
    {
        $model = $this->modelFor('gemini', $model);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . urlencode((string) $this->geminiKey);
        $cfg = ['temperature' => $temp, 'maxOutputTokens' => $maxTokens];
        if ($json) $cfg['responseMimeType'] = 'application/json';
        $payload = [
            'contents'          => [['parts' => [['text' => $user]]]],
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'generationConfig'  => $cfg,
        ];
        $j = $this->httpPost($url, [], $payload);
        $this->captureUsage($j);
        $c = $j['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return (is_string($c) && $c !== '') ? $c : null;
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
     * Protected, not private, so a test can intercept the ONE network call without
     * bypassing anything else. Overriding the four per-provider methods instead
     * would skip `modelFor()` and the payload assembly — i.e. skip the code that
     * decides which model is requested, which is the part worth testing.
     */
    protected function httpPost(string $url, array $headers, array $payload): ?array
    {
        $body = json_encode($payload);
        // Up to 2 attempts: retry once on a TRANSIENT failure (network/timeout,
        // 429 rate-limit, or 5xx), which are exactly the errors that made AI
        // "randomly not work". Permanent errors (4xx auth/bad-request) don't retry.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
                CURLOPT_POSTFIELDS     => $body,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($code === 200 && $resp) {
                $j = json_decode((string) $resp, true);
                return is_array($j) ? $j : null;
            }

            // `HTTP 0` is EXCLUDED from the retry, and that is the point.
            //
            // Code 0 means cURL got no response at all — the connection was refused,
            // the name did not resolve, or the timeout expired. None of those clear in
            // 300 milliseconds, so the retry only ever bought a second full timeout on
            // a certainty. With a 6s timeout that is 12.3s per call spent on a
            // provider that cannot answer, which on this deployment is what stopped
            // Gee responding: the request ran out of patience before the chain reached
            // a provider that could. The cross-request half is in ProviderBreaker.
            $transient = ($code === 429 || $code >= 500);
            $this->lastError = 'HTTP ' . $code . ($cerr !== '' ? ' (' . $cerr . ')' : '')
                . ($resp ? ' ' . substr((string) $resp, 0, 160) : '');
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
