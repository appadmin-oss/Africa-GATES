<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The registry of things this platform is allowed to ask a model to do.
 *
 * Every AI feature is declared here as DATA — its purpose, its pinned model, its
 * budget, what happens when the provider fails, and whether it is advisory. That
 * replaces prompts and policy scattered across 21 call sites in 14 files, where
 * each one invented its own failure behaviour and none of them had a budget.
 *
 * Two fields carry the governance:
 *
 *  • `advisory` — true means the result MAY NOT block, reject, approve or rank
 *    anything. {@see AiGateway} enforces it; it is not a comment. The nomination
 *    spam gate used to be documented as advisory while actually throwing, which
 *    destroyed submissions at the boundary.
 *
 *  • `onFailure` — declared per capability instead of assumed. Silent
 *    degradation is right for an optional nicety like story polish and wrong for
 *    moderation, where quality would otherwise vary with provider health and
 *    nobody would be told.
 */
final class AiCapability
{
    /** Degrade silently — the caller carries on as if the feature were off. */
    public const FAIL_DEGRADE = 'degrade';
    /** Tell the operator/reviewer the AI was unavailable. Never blocks a user. */
    public const FAIL_ANNOUNCE = 'announce';

    private function __construct(
        public readonly string $name,
        public readonly string $purpose,
        /** Provider:model, pinned. Never "whatever key happens to be first". */
        public readonly string $model,
        public readonly string $onFailure,
        public readonly bool $advisory,
        public readonly int $maxTokens,
        public readonly int $callsPerDay,
        public readonly int $tokensPerDay,
        /** Timeout in seconds for ONE provider attempt. */
        public readonly int $timeout,
        /** True when the prompt carries untrusted user text that must be fenced. */
        public readonly bool $untrustedInput,
    ) {}

    /**
     * Every declared capability.
     *
     * Budgets are deliberately finite. The only global control before this was a
     * single rate-limit counter covering three endpoints; triage, moderation,
     * merge suggestions and the assistant were entirely unbounded, and no call
     * recorded its token usage, so spend was unknowable even after the fact.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        static $all = null;
        if ($all !== null) return $all;

        $c = static fn (string $name, array $o): self => new self(
            name:           $name,
            purpose:        $o['purpose'],
            model:          $o['model'],
            onFailure:      $o['on_failure'],
            advisory:       $o['advisory'] ?? true,
            maxTokens:      $o['max_tokens'] ?? 512,
            callsPerDay:    $o['calls_per_day'] ?? 1000,
            tokensPerDay:   $o['tokens_per_day'] ?? 500_000,
            timeout:        $o['timeout'] ?? 6,
            untrustedInput: $o['untrusted_input'] ?? false,
        );

        return $all = [
            // Reviewer-facing score + summary for a nomination. Interpolates the
            // nominator's free text, so it is the single most injection-exposed
            // capability on the platform.
            'nomination.triage' => $c('nomination.triage', [
                'purpose'         => 'moderation',
                'model'           => 'groq:llama-3.3-70b-versatile',
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 250,
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 400_000,
                'untrusted_input' => true,
            ]),
            // Spam/abuse classifier. Must never be the thing that decides.
            'moderation.classify' => $c('moderation.classify', [
                'purpose'         => 'moderation',
                'model'           => 'groq:llama-3.3-70b-versatile',
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 80,
                'calls_per_day'   => 5000,
                'tokens_per_day'  => 300_000,
                // Sits on a synchronous form POST, so one attempt only. The old
                // path could chain four providers × two attempts × 6s.
                'timeout'         => 4,
                'untrusted_input' => true,
            ]),
            // Optional writing help. The one feature whose silent-degradation
            // design was already right.
            'nomination.polish' => $c('nomination.polish', [
                'purpose'         => 'assist',
                'model'           => 'groq:llama-3.1-8b-instant',
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 700,
                'calls_per_day'   => 3000,
                'tokens_per_day'  => 600_000,
                'untrusted_input' => true,
            ]),
            'nomination.suggest_category' => $c('nomination.suggest_category', [
                'purpose'         => 'assist',
                'model'           => 'groq:llama-3.1-8b-instant',
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 200,
                'calls_per_day'   => 3000,
                'tokens_per_day'  => 300_000,
                'untrusted_input' => true,
            ]),
            // Admin plain-English filter parsing. Already whitelist-validates
            // its output — the reference pattern for every other capability.
            'admin.filter_parse' => $c('admin.filter_parse', [
                'purpose'        => 'assist',
                'model'          => 'groq:llama-3.1-8b-instant',
                'on_failure'     => self::FAIL_DEGRADE,
                'advisory'       => true,
                'max_tokens'     => 200,
                'calls_per_day'  => 1000,
                'tokens_per_day' => 100_000,
            ]),
            // Operator copilot. Failures here are LOUD by design: the console
            // must never pretend AI is working.
            'admin.assistant' => $c('admin.assistant', [
                'purpose'         => 'assist',
                'model'           => 'groq:llama-3.3-70b-versatile',
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 1024,
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 1_000_000,
                'timeout'         => 20,
                'untrusted_input' => true,
            ]),
            // Public guide. Degrades to scripted answers, which is correct — a
            // visitor should never see an error from a help widget.
            'guide.chat' => $c('guide.chat', [
                'purpose'         => 'assist',
                'model'           => 'groq:llama-3.3-70b-versatile',
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 1024,
                'calls_per_day'   => 4000,
                'tokens_per_day'  => 2_000_000,
                'timeout'         => 20,
                'untrusted_input' => true,
            ]),
            'nominee.merge_suggest' => $c('nominee.merge_suggest', [
                'purpose'         => 'assist',
                'model'           => 'groq:llama-3.3-70b-versatile',
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 400,
                'calls_per_day'   => 500,
                'tokens_per_day'  => 200_000,
                'untrusted_input' => true,
            ]),
            'integrity.brief' => $c('integrity.brief', [
                'purpose'        => 'assist',
                'model'          => 'groq:llama-3.3-70b-versatile',
                'on_failure'     => self::FAIL_ANNOUNCE,
                'advisory'       => true,
                'max_tokens'     => 800,
                'calls_per_day'  => 200,
                'tokens_per_day' => 200_000,
            ]),
        ];
    }

    /** Look one up, or null when the name is not declared. */
    public static function find(string $name): ?self
    {
        return self::all()[$name] ?? null;
    }

    /** The provider half of the pinned model ("groq"). */
    public function provider(): string
    {
        return explode(':', $this->model, 2)[0];
    }

    /** The model half of the pinned model ("llama-3.3-70b-versatile"). */
    public function modelId(): string
    {
        $parts = explode(':', $this->model, 2);
        return $parts[1] ?? $parts[0];
    }
}
