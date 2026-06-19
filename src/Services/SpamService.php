<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Two-stage moderation:
 *   1. Heuristic pass (fast, free) — catches obvious spam, link-spam, all-caps,
 *      profanity, gibberish, repetition. Returns score 0..1 (1 = pure spam).
 *   2. AI pass (only for borderline 0.20–0.60) — calls the configured AI
 *      moderation endpoint (Anthropic by default; OpenAI moderation if
 *      OPENAI_KEY set). Falls back gracefully when no API key.
 *
 * Decision thresholds (final score):
 *   < 0.30 → allow
 *   < 0.65 → quarantine (admin review)
 *   ≥ 0.65 → reject (auto-block, never shown)
 */
class SpamService
{
    private const BLOCKLIST = [
        'viagra','casino','crypto giveaway','telegram dm','onlyfans','sex chat',
        'bitcoin mining','make money fast','click here','seo expert','buy followers',
    ];

    public function __construct(
        private readonly ?string $groqKey = null,
        private readonly ?string $geminiKey = null,
        private readonly ?string $anthropicKey = null,
        private readonly ?string $openaiKey = null
    ) {}

    /**
     * @return array{decision:'allow'|'quarantine'|'reject', score:float, reason:string, provider:string}
     */
    public function evaluate(string $text, array $context = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['decision' => 'reject', 'score' => 1.0, 'reason' => 'Empty content', 'provider' => 'heuristic'];
        }

        // ── Stage 1: heuristics ────────────────────────────────────
        $h = $this->heuristicScore($text);

        if ($h['score'] >= 0.65) {
            return ['decision' => 'reject', 'score' => $h['score'], 'reason' => $h['reason'], 'provider' => 'heuristic'];
        }
        if ($h['score'] < 0.20) {
            return ['decision' => 'allow', 'score' => $h['score'], 'reason' => 'Clean (heuristics)', 'provider' => 'heuristic'];
        }

        // ── Stage 2: AI (only borderline) ──────────────────────────
        if ($this->groqKey || $this->geminiKey || $this->anthropicKey || $this->openaiKey) {
            try {
                $ai = $this->aiScore($text, $context);
                if ($ai !== null) {
                    $score = max($h['score'], $ai['score']);
                    if ($score >= 0.65) return ['decision' => 'reject', 'score' => $score, 'reason' => $ai['reason'], 'provider' => $ai['provider']];
                    if ($score >= 0.30) return ['decision' => 'quarantine', 'score' => $score, 'reason' => $ai['reason'], 'provider' => $ai['provider']];
                    return ['decision' => 'allow', 'score' => $score, 'reason' => $ai['reason'], 'provider' => $ai['provider']];
                }
            } catch (\Throwable $e) { /* fall through to heuristic */ }
        }

        // Borderline + no AI available → quarantine
        return ['decision' => 'quarantine', 'score' => $h['score'], 'reason' => $h['reason'], 'provider' => 'heuristic'];
    }

    /** Heuristic features → score [0..1]. */
    private function heuristicScore(string $text): array
    {
        $reasons = [];
        $score = 0.0;
        $len = mb_strlen($text);
        $lower = mb_strtolower($text);

        // Very short
        if ($len < 4) { $score += 0.6; $reasons[] = 'too short'; }
        // Very long with no spaces (gibberish)
        if ($len > 80 && substr_count($text, ' ') < 3) { $score += 0.4; $reasons[] = 'no spaces'; }
        // All caps (>50% caps in a 20+ char string)
        if ($len > 20) {
            $caps = preg_match_all('/[A-Z]/', $text);
            $alphas = preg_match_all('/[A-Za-z]/', $text) ?: 1;
            if (($caps / $alphas) > 0.6) { $score += 0.3; $reasons[] = 'all caps'; }
        }
        // Excessive punctuation
        if (preg_match_all('/[!?]{2,}/', $text) > 1) { $score += 0.2; $reasons[] = 'excessive punctuation'; }
        // URLs
        $urls = preg_match_all('/https?:\/\/|www\./i', $text);
        if ($urls >= 1) { $score += 0.15 * $urls; $reasons[] = 'contains links'; }
        // Blocklist
        foreach (self::BLOCKLIST as $w) {
            if (str_contains($lower, $w)) { $score += 0.5; $reasons[] = "matches '$w'"; break; }
        }
        // Repeated chars (aaaaa, ......)
        if (preg_match('/(.)\1{5,}/u', $text)) { $score += 0.25; $reasons[] = 'repeated chars'; }
        // Phone numbers / WhatsApp / Telegram lures
        if (preg_match('/\+?\d{8,}/', $text) || preg_match('/whatsapp|telegram|signal me/i', $text)) { $score += 0.35; $reasons[] = 'contact lure'; }

        return ['score' => min(1.0, $score), 'reason' => empty($reasons) ? 'clean' : implode(', ', $reasons)];
    }

    /** Call provider in priority order. Groq is free + fast (Llama 3.1/3.3). */
    private function aiScore(string $text, array $context): ?array
    {
        if ($this->groqKey)      return $this->groqScore($text, $context);
        if ($this->geminiKey)    return $this->geminiScore($text, $context);
        if ($this->anthropicKey) return $this->anthropicScore($text, $context);
        if ($this->openaiKey)    return $this->openaiScore($text);
        return null;
    }

    /** Groq (free, OpenAI-compatible) — uses llama-3.1-8b-instant by default. */
    private function groqScore(string $text, array $context): ?array
    {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->groqKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $_ENV['GROQ_MODEL'] ?? 'llama-3.1-8b-instant',
                'temperature' => 0.0,
                'max_tokens' => 80,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a content-moderation classifier for Africa GATES, a continental cultural awards platform. Reply ONLY with {"score": 0.NN, "reason": "..."}. 0.0=clean & on-topic; 0.5=irrelevant/low-effort; 1.0=spam, scam, hate, doxxing, harassment.'],
                    ['role' => 'user',   'content' => substr($text, 0, 2000)],
                ],
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return null;
        $j = json_decode((string)$resp, true);
        $content = $j['choices'][0]['message']['content'] ?? '';
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['score'])) {
                return ['score' => (float)$parsed['score'], 'reason' => (string)($parsed['reason'] ?? 'ai-classified'), 'provider' => 'groq'];
            }
        }
        return null;
    }

    /** Google Gemini (free tier, very smart). */
    private function geminiScore(string $text, array $context): ?array
    {
        $model = $_ENV['GEMINI_MODEL'] ?? 'gemini-2.0-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . urlencode($this->geminiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [['parts' => [['text' =>
                    'Classify this user-submitted text for an African cultural-awards platform. Reply ONLY with a JSON object: {"score": 0.NN, "reason": "short"}. 0.0=clean on-topic; 0.5=low-effort; 1.0=spam/scam/hate.\n\n' . substr($text, 0, 2000)
                ]]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 80, 'responseMimeType' => 'application/json'],
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return null;
        $j = json_decode((string)$resp, true);
        $content = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['score'])) {
                return ['score' => (float)$parsed['score'], 'reason' => (string)($parsed['reason'] ?? 'ai-classified'), 'provider' => 'gemini'];
            }
        }
        return null;
    }

    private function anthropicScore(string $text, array $context): ?array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->anthropicKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 80,
                'messages' => [[
                    'role' => 'user',
                    'content' => "Score this user-submitted text on Africa GATES (a continental cultural awards platform) for spam/abuse on a 0-1 scale.\n\n"
                        . "0.0 = clean and on-topic\n"
                        . "0.5 = irrelevant, low-effort\n"
                        . "1.0 = spam, scam, hate, doxxing\n\n"
                        . "Reply with ONLY a JSON object: {\"score\": 0.NN, \"reason\": \"short explanation\"}.\n\n"
                        . "Text: " . substr($text, 0, 2000)
                ]],
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return null;
        $j = json_decode((string)$resp, true);
        $content = $j['content'][0]['text'] ?? '';
        if (preg_match('/\{[^}]*\}/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed) && isset($parsed['score'])) {
                return ['score' => (float)$parsed['score'], 'reason' => (string)($parsed['reason'] ?? 'ai-classified'), 'provider' => 'anthropic'];
            }
        }
        return null;
    }

    private function openaiScore(string $text): ?array
    {
        $ch = curl_init('https://api.openai.com/v1/moderations');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode(['model' => 'omni-moderation-latest', 'input' => substr($text, 0, 2000)]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return null;
        $j = json_decode((string)$resp, true);
        $r = $j['results'][0] ?? null;
        if (!$r) return null;
        $score = (float)($r['category_scores']['harassment'] ?? 0);
        $score = max($score, (float)($r['category_scores']['hate'] ?? 0));
        $score = max($score, (float)($r['category_scores']['sexual'] ?? 0));
        $score = max($score, (float)($r['category_scores']['violence'] ?? 0));
        if (!empty($r['flagged'])) $score = max($score, 0.7);
        return ['score' => $score, 'reason' => $r['flagged'] ? 'flagged by OpenAI' : 'cleared by OpenAI', 'provider' => 'openai'];
    }

    public function logDecision(string $targetType, int $targetId, array $verdict): void
    {
        try {
            DB::table('gates_moderation_log')->insert([
                'target_type' => $targetType,
                'target_id' => $targetId,
                'provider' => $verdict['provider'] ?? 'heuristic',
                'decision' => $verdict['decision'],
                'score' => $verdict['score'],
                'reason' => $verdict['reason'],
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {}
    }
}
