<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Two-stage moderation:
 *   1. Heuristic pass (fast, free) — catches obvious spam, link-spam, all-caps,
 *      profanity, gibberish, repetition. Returns score 0..1 (1 = pure spam).
 *   2. AI pass (only for borderline 0.20–0.60) — delegates to {@see AiService},
 *      which calls whichever provider key is configured (Groq → Gemini →
 *      Anthropic → OpenAI). Falls back gracefully when no provider is set.
 *
 * Decision thresholds (final score) — admin-configurable in Settings →
 * Moderation, defaults shown (see {@see thresholds()} for the clamps):
 *   < quarantine (0.30) → allow
 *   < reject     (0.65) → quarantine (admin review)
 *   ≥ reject            → reject (auto-block, never shown)
 */
class SpamService
{
    private const BLOCKLIST = [
        'viagra','casino','crypto giveaway','telegram dm','onlyfans','sex chat',
        'bitcoin mining','make money fast','click here','seo expert','buy followers',
    ];

    public function __construct(private readonly ?AiService $ai = null) {}

    /** Per-request cache of the admin-configured thresholds. */
    private static ?array $thresholds = null;

    /**
     * Decision thresholds, admin-configurable in Settings → Moderation
     * (mod_threshold_quarantine / mod_threshold_reject). Clamped so a typo can
     * never disable moderation: quarantine ∈ [0.05, 0.90], reject always at
     * least 0.05 above quarantine and ≤ 0.99. Defaults 0.30 / 0.65.
     *
     * @return array{quarantine: float, reject: float}
     */
    public static function thresholds(): array
    {
        if (self::$thresholds !== null) return self::$thresholds;
        $q = 0.30; $r = 0.65;
        try {
            $rows = DB::table('gates_settings')->whereIn('key_name', ['mod_threshold_quarantine', 'mod_threshold_reject'])->pluck('value', 'key_name');
            if (isset($rows['mod_threshold_quarantine']) && is_numeric($rows['mod_threshold_quarantine'])) $q = (float) $rows['mod_threshold_quarantine'];
            if (isset($rows['mod_threshold_reject']) && is_numeric($rows['mod_threshold_reject']))         $r = (float) $rows['mod_threshold_reject'];
        } catch (\Throwable) {}
        $q = max(0.05, min(0.90, $q));
        $r = max($q + 0.05, min(0.99, $r));
        return self::$thresholds = ['quarantine' => $q, 'reject' => $r];
    }

    /** Test/long-process hook: forget the cached thresholds. */
    public static function resetThresholdCache(): void
    {
        self::$thresholds = null;
    }

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
        $t = self::thresholds();
        $h = $this->heuristicScore($text);

        if ($h['score'] >= $t['reject']) {
            return ['decision' => 'reject', 'score' => $h['score'], 'reason' => $h['reason'], 'provider' => 'heuristic'];
        }
        if ($h['score'] < 0.20) {
            return ['decision' => 'allow', 'score' => $h['score'], 'reason' => 'Clean (heuristics)', 'provider' => 'heuristic'];
        }

        // ── Stage 2: AI (only borderline) ──────────────────────────
        if ($this->ai && $this->ai->configured()) {
            try {
                $ai = $this->ai->moderate($text, $context);
                if ($ai !== null) {
                    $score = max($h['score'], $ai['score']);
                    if ($score >= $t['reject'])     return ['decision' => 'reject', 'score' => $score, 'reason' => $ai['reason'], 'provider' => $ai['provider']];
                    if ($score >= $t['quarantine']) return ['decision' => 'quarantine', 'score' => $score, 'reason' => $ai['reason'], 'provider' => $ai['provider']];
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
        // Anything short of a clean allow is a flag operators may want to see
        // in real time (quarantine queue growth, reject spikes). Best-effort.
        if (($verdict['decision'] ?? 'allow') !== 'allow') {
            WebhookService::dispatch('moderation.flagged', [
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'decision'    => (string) $verdict['decision'],
                'score'       => (float) ($verdict['score'] ?? 0),
                'provider'    => (string) ($verdict['provider'] ?? 'heuristic'),
            ]);
        }
    }
}
