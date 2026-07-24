<?php
declare(strict_types=1);

namespace AfricaGates\Services;

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
            $env = $_ENV[$envKey] ?? null;
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
        return match ($this->activeProvider()) {
            'groq'      => $this->groqModel ?: 'llama-3.1-8b-instant',
            'gemini'    => $this->geminiModel ?: 'gemini-2.0-flash',
            'anthropic' => $this->anthropicModel ?: 'claude-haiku-4-5-20251001',
            'openai'    => $this->openaiModel ?: ($_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini'),
            default     => null,
        };
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

    /** Last provider error seen during a call, for diagnostics ({@see selfTest()}). */
    private ?string $lastError = null;

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
    public function complete(string $system, string $user, int $maxTokens = 512, bool $json = false, float $temperature = 0.2): ?string
    {
        foreach ($this->providerChain() as $provider) {
            try {
                $out = match ($provider) {
                    'groq'      => $this->groqChat($system, $user, $maxTokens, $json, $temperature),
                    'gemini'    => $this->geminiChat($system, $user, $maxTokens, $json, $temperature),
                    'anthropic' => $this->anthropicChat($system, $user, $maxTokens, $json, $temperature),
                    'openai'    => $this->openaiChat($system, $user, $maxTokens, $json, $temperature),
                    default     => null,
                };
                if (is_string($out) && $out !== '') {
                    return $json ? self::stripJsonFence($out) : $out;
                }
                $this->lastError = $provider . ': empty/failed response';
            } catch (\Throwable $e) {
                $this->lastError = $provider . ': ' . $e->getMessage();
            }
            // fall through to the next configured provider
        }
        if ($this->lastError !== null) error_log('[AiService] all providers failed — ' . $this->lastError);
        return null;
    }

    /**
     * On-demand health check: make one tiny real completion and report which
     * provider answered (or the error). Powers the admin Settings "test AI"
     * button so "AI doesn't work" is diagnosable instead of silent.
     *
     * @return array{ok:bool,provider:?string,model:?string,error:?string}
     */
    public function selfTest(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'provider' => null, 'model' => null, 'error' => 'No provider key configured.'];
        }
        $this->lastError = null;
        $out = $this->complete('You are a health check. Reply with the single word OK.', 'ping', 8, false, 0.0);
        return [
            'ok'       => is_string($out) && $out !== '',
            'provider' => $this->activeProvider(),
            'model'    => $this->activeModel(),
            'error'    => (is_string($out) && $out !== '') ? null : ($this->lastError ?? 'No response from any provider.'),
        ];
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
    private function groqChat(string $system, string $user, int $maxTokens, bool $json, float $temp): ?string
    {
        $payload = [
            'model'       => $this->groqModel ?: 'llama-3.1-8b-instant',
            'temperature' => $temp,
            'max_tokens'  => $maxTokens,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ];
        if ($json) $payload['response_format'] = ['type' => 'json_object'];
        $j = $this->httpPost('https://api.groq.com/openai/v1/chat/completions', ['Authorization: Bearer ' . $this->groqKey], $payload);
        $c = $j['choices'][0]['message']['content'] ?? null;
        return (is_string($c) && $c !== '') ? $c : null;
    }

    // ── Provider: Google Gemini (free tier) ────────────────────────────────
    private function geminiChat(string $system, string $user, int $maxTokens, bool $json, float $temp): ?string
    {
        $model = $this->geminiModel ?: 'gemini-2.0-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . urlencode((string) $this->geminiKey);
        $cfg = ['temperature' => $temp, 'maxOutputTokens' => $maxTokens];
        if ($json) $cfg['responseMimeType'] = 'application/json';
        $payload = [
            'contents'          => [['parts' => [['text' => $user]]]],
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'generationConfig'  => $cfg,
        ];
        $j = $this->httpPost($url, [], $payload);
        $c = $j['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return (is_string($c) && $c !== '') ? $c : null;
    }

    // ── Provider: Anthropic (Claude Haiku) ─────────────────────────────────
    private function anthropicChat(string $system, string $user, int $maxTokens, bool $json, float $temp): ?string
    {
        $payload = [
            'model'      => $this->anthropicModel ?: 'claude-haiku-4-5-20251001',
            'max_tokens' => $maxTokens,
            'temperature'=> $temp,
            'system'     => $system . ($json ? ' Reply with ONLY a valid JSON object.' : ''),
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];
        $j = $this->httpPost('https://api.anthropic.com/v1/messages', ['x-api-key: ' . $this->anthropicKey, 'anthropic-version: 2023-06-01'], $payload);
        $c = $j['content'][0]['text'] ?? null;
        return (is_string($c) && $c !== '') ? $c : null;
    }

    // ── Provider: OpenAI (chat completions) ────────────────────────────────
    private function openaiChat(string $system, string $user, int $maxTokens, bool $json, float $temp): ?string
    {
        $payload = [
            'model'       => $this->openaiModel ?: ($_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini'),
            'max_tokens'  => $maxTokens,
            'temperature' => $temp,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ];
        if ($json) $payload['response_format'] = ['type' => 'json_object'];
        $j = $this->httpPost('https://api.openai.com/v1/chat/completions', ['Authorization: Bearer ' . $this->openaiKey], $payload);
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
    private function httpPost(string $url, array $headers, array $payload): ?array
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

            $transient = ($code === 0 || $code === 429 || $code >= 500);
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
