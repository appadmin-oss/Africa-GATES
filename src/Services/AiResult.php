<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The outcome of an AI call. Never an exception — a model being unavailable,
 * over budget or switched off is an ordinary condition, not an error, and it must
 * never be able to fail a user's action.
 *
 * `advisory` is the load-bearing property. When true, the caller MAY NOT use this
 * result to block, reject, approve or rank anything; it may only inform a human.
 * Every capability declared today is advisory, and {@see denies()} exists so a
 * caller cannot accidentally treat a refusal as a verdict — which is precisely
 * what the nomination spam gate did when it threw on a 'reject' score and
 * destroyed the submission.
 */
final class AiResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly string $message,
        public readonly mixed $value,
        public readonly bool $advisory,
        public readonly ?string $onFailure,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly int $latencyMs,
    ) {}

    public static function ok(
        mixed $value, AiCapability $cap, ?string $provider, ?string $model,
        int $tokensIn = 0, int $tokensOut = 0, int $latencyMs = 0,
    ): self {
        return new self(true, 'OK', '', $value, $cap->advisory, $cap->onFailure,
            $provider, $model, $tokensIn, $tokensOut, $latencyMs);
    }

    /** The gateway declined to call: switched off, over budget, undeclared. */
    public static function refused(string $code, string $message, ?AiCapability $cap = null): self
    {
        return new self(false, $code, $message, null, $cap?->advisory ?? true,
            $cap?->onFailure, null, null, 0, 0, 0);
    }

    /** The call was made and did not produce a usable answer. */
    public static function failed(string $code, string $message, ?AiCapability $cap = null): self
    {
        return new self(false, $code, $message, null, $cap?->advisory ?? true,
            $cap?->onFailure, null, null, 0, 0, 0);
    }

    /**
     * Whether the ABSENCE of this result should be surfaced to the human who
     * would otherwise have relied on it.
     *
     * Declared per capability rather than assumed, because silent degradation is
     * right for optional writing help and wrong for moderation — where review
     * quality would otherwise vary with provider health and nobody would know.
     */
    public function shouldAnnounce(): bool
    {
        return !$this->ok && $this->onFailure === AiCapability::FAIL_ANNOUNCE;
    }

    /**
     * ALWAYS false. Kept as an explicit, greppable seam so no caller can quietly
     * turn an advisory score into a rejection: `if ($r->denies()) { throw ... }`
     * is dead code by construction, and a future non-advisory capability has to
     * change this method — and be reviewed — rather than slip through a call site.
     */
    public function denies(): bool
    {
        return false;
    }

    /** The value, or $fallback when the call did not succeed. */
    public function valueOr(mixed $fallback): mixed
    {
        return $this->ok ? $this->value : $fallback;
    }
}
