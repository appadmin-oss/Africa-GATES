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
        /**
         * Hard ceiling on provider attempts for ONE call.
         *
         * Declared because the right answer is per capability, not global. A
         * synchronous form POST must not chain: `moderation.classify` has a 4s
         * timeout and sits on the nomination submit, so four hops is a sixteen-second
         * wait for a signal that is advisory anyway — better to skip the model and
         * use the local heuristics. A background integrity brief has no user waiting
         * and should exhaust the ladder.
         *
         * This is the constraint the original code stated in a comment ("Sits on a
         * synchronous form POST, so one attempt only") and had no mechanism to
         * enforce.
         */
        public readonly int $maxAttempts,
        /**
         * Ordered alternates, `provider:model`, tried when the pin cannot answer.
         *
         * Declared per capability rather than shared, because the right substitute
         * depends on the job: a moderation classifier should fall back to another
         * strong reasoning model, while a wording-polish helper is better served by
         * anything cheap and fast than by nothing.
         *
         * @var list<string>
         */
        public readonly array $fallbacks,
        public readonly string $onFailure,
        public readonly bool $advisory,
        public readonly int $maxTokens,
        public readonly int $callsPerDay,
        public readonly int $tokensPerDay,
        /** Timeout in seconds for ONE provider attempt. */
        public readonly int $timeout,
        /** True when the prompt carries untrusted user text that must be fenced. */
        public readonly bool $untrustedInput,
        /**
         * Strip contact identifiers from the payload before it leaves.
         *
         * Declared rather than inferred from `untrustedInput`, because the right
         * answer differs: public-submitted prose should never carry a phone
         * number to a classifier that cannot use one, while an admin searching
         * for a phone number is acting deliberately on data they control and
         * redacting their query would simply break the feature.
         * {@see AiPrivacy::minimise()}
         */
        public readonly bool $minimise,
        /**
         * True when this capability processes content submitted by the PUBLIC,
         * and so belongs in the published privacy disclosure. Admin drafting
         * help does not: listing it would bury the part that concerns visitors.
         */
        public readonly bool $publicContent,
        /**
         * Plain-language description of what is sent, for the privacy notice.
         *
         * Written to be read by a nominator, not a developer, and kept beside the
         * code that does the sending so the published notice cannot drift from
         * the payload. {@see AiPrivacy::disclosure()}
         */
        public readonly string $dataSent,
        /** Plain-language description of what it is used for, same audience. */
        public readonly string $dataPurpose,
    ) {}

    /**
     * Reasoning weight a capability needs. This is the delegation axis.
     *
     * Every one of the fifteen capabilities used to pin a Groq llama model — eleven
     * on `llama-3.3-70b-versatile`, four on `llama-3.1-8b-instant` — which is not
     * delegation, it is one vendor with two sizes. Worse, the pin was never honoured:
     * {@see AiGateway} read it into the audit log and then let `AiService` pick
     * whichever key happened to be configured first, so the recorded model and the
     * called model were unrelated.
     *
     * Two tiers, chosen by what the output is USED for rather than by how long the
     * prompt is:
     *
     *  • REASON — the answer feeds a decision a person will act on, or is shown to
     *    the public as prose. Moderation scores, the reviewer's triage summary, the
     *    note sent to a nominator, duplicate-merge suggestions, the operator
     *    copilot, the public guide, integrity briefs.
     *
     *  • FAST — the answer is a suggestion the user immediately accepts or discards,
     *    on a synchronous request where latency IS the feature. Wording polish,
     *    category hints, admin filter parsing.
     */
    public const TIER_REASON = 'reason';
    public const TIER_FAST   = 'fast';

    /**
     * Primary model per tier. OpenAI, because a single vendor for the pins keeps
     * behaviour comparable across features and every other provider stays available
     * as a fallback.
     *
     * Every id here is overridable per provider through admin Settings
     * (`ai_openai_model`, `ai_gemini_model`, …) or the environment, so moving to a
     * newer model is a configuration change, not a deploy.
     */
    public const PRIMARY = [
        self::TIER_REASON => 'openai:gpt-4o',
        self::TIER_FAST   => 'openai:gpt-4o-mini',
    ];

    /**
     * Fallback ladder per tier, in order, used when a capability does not declare
     * its own.
     *
     * REASON keeps reasoning quality: Gemini Flash, then Claude, then the strongest
     * Groq model. FAST keeps latency: the flash/instant models first, and it does
     * NOT climb to a heavier model — a 700ms suggestion that arrives after the user
     * has finished typing is worse than no suggestion.
     */
    private const FALLBACKS = [
        self::TIER_REASON => ['gemini:gemini-3.6-flash', 'anthropic:', 'groq:llama-3.3-70b-versatile'],
        self::TIER_FAST   => ['gemini:gemini-3.6-flash', 'groq:llama-3.1-8b-instant'],
    ];

    /**
     * The tier ladder, minus any hop that repeats the pin's provider.
     *
     * Retrying the same provider that just failed is not a fallback — if the pin was
     * `gemini:…` then Gemini being down means the Gemini hop is dead too, and
     * keeping it only spends the timeout twice.
     *
     * @return list<string>
     */
    private static function defaultFallbacks(string $pinned, string $tier): array
    {
        $pinnedProvider = explode(':', $pinned, 2)[0];
        return array_values(array_filter(
            self::FALLBACKS[$tier] ?? self::FALLBACKS[self::TIER_FAST],
            static fn (string $hop): bool => explode(':', $hop, 2)[0] !== $pinnedProvider
        ));
    }

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
            maxAttempts:    $o['max_attempts'] ?? 3,
            fallbacks:      $o['fallbacks'] ?? self::defaultFallbacks($o['model'], $o['tier'] ?? self::TIER_FAST),
            onFailure:      $o['on_failure'],
            advisory:       $o['advisory'] ?? true,
            maxTokens:      $o['max_tokens'] ?? 512,
            callsPerDay:    $o['calls_per_day'] ?? 1000,
            tokensPerDay:   $o['tokens_per_day'] ?? 500_000,
            timeout:        $o['timeout'] ?? 6,
            untrustedInput: $o['untrusted_input'] ?? false,
            // Default to minimising whatever is fenced as untrusted: the safe
            // default is to send less, and the exceptions are stated explicitly.
            minimise:       $o['minimise'] ?? ($o['untrusted_input'] ?? false),
            publicContent:  $o['public_content'] ?? false,
            dataSent:       $o['data_sent'] ?? 'Nothing submitted by the public.',
            dataPurpose:    $o['data_purpose'] ?? $o['purpose'],
        );

        return $all = [
            // Reviewer-facing score + summary for a nomination. Interpolates the
            // nominator's free text, so it is the single most injection-exposed
            // capability on the platform.
            'nomination.triage' => $c('nomination.triage', [
                'purpose'         => 'moderation',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 250,
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 400_000,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => "The nominee's name, organisation and country, the number of reference "
                    . 'links you provided, and your reason text. Contact details inside the text are '
                    . 'replaced with placeholders first. The nominee\'s email address and phone number '
                    . 'are never sent.',
                'data_purpose'    => 'To score how complete and specific the nomination reads, and summarise it '
                    . 'for the reviewer. A person always makes the decision.',
            ]),
            // Spam/abuse classifier. Must never be the thing that decides.
            'moderation.classify' => $c('moderation.classify', [
                'purpose'         => 'moderation',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 80,
                'calls_per_day'   => 5000,
                'tokens_per_day'  => 300_000,
                // Sits on a synchronous form POST, so one attempt only — and now
                // ENFORCED rather than described. The old path could chain four
                // providers × two attempts × 6s; declaring the route without a cap
                // would have quietly reintroduced that.
                'timeout'         => 4,
                'max_attempts'    => 1,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The text you submitted — a nomination reason, a comment or a post — with '
                    . 'contact details replaced by placeholders. Only borderline text is sent at all: '
                    . 'clearly clean and clearly abusive content is decided on this platform without '
                    . 'any model being called.',
                'data_purpose'    => 'To help judge whether the text is spam or abuse. The score is one signal '
                    . 'among several and never decides alone.',
            ]),
            // Optional writing help. The one feature whose silent-degradation
            // design was already right.
            'nomination.polish' => $c('nomination.polish', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::PRIMARY[self::TIER_FAST],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 700,
                'calls_per_day'   => 3000,
                'tokens_per_day'  => 600_000,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'Only the draft text you asked us to improve, with contact details '
                    . 'replaced by placeholders. Nothing is sent unless you press the button.',
                'data_purpose'    => 'To suggest clearer wording. You choose whether to keep it.',
            ]),
            'nomination.suggest_category' => $c('nomination.suggest_category', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::PRIMARY[self::TIER_FAST],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 200,
                'calls_per_day'   => 3000,
                'tokens_per_day'  => 300_000,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The nominee name and description you have typed so far, plus the list of '
                    . 'available categories. Contact details are replaced by placeholders.',
                'data_purpose'    => 'To suggest which award category fits. You can pick any category regardless.',
            ]),
            // Admin plain-English filter parsing. Already whitelist-validates
            // its output — the reference pattern for every other capability.
            'admin.filter_parse' => $c('admin.filter_parse', [
                'purpose'        => 'assist',
                'tier'           => self::TIER_FAST,
                'model'          => self::PRIMARY[self::TIER_FAST],
                'on_failure'     => self::FAIL_DEGRADE,
                'advisory'       => true,
                'max_tokens'     => 200,
                'calls_per_day'  => 1000,
                'tokens_per_day' => 100_000,
                // An admin searching for a phone number is acting deliberately on
                // data they already control; redacting the query breaks the search.
                'minimise'       => false,
            ]),
            // Operator copilot. Failures here are LOUD by design: the console
            // must never pretend AI is working.
            'admin.assistant' => $c('admin.assistant', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 1024,
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 1_000_000,
                'timeout'         => 20,
                'untrusted_input' => true,
                // Same reasoning as the filter parser: the operator's own query,
                // about data they administer.
                'minimise'        => false,
            ]),
            // Public guide. Degrades to scripted answers, which is correct — a
            // visitor should never see an error from a help widget.
            'guide.chat' => $c('guide.chat', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 1024,
                'calls_per_day'   => 4000,
                'tokens_per_day'  => 2_000_000,
                'timeout'         => 20,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The question you type into the help widget, with contact details replaced '
                    . 'by placeholders, plus a summary of the current award cycles. Your identity is '
                    . 'not sent.',
                'data_purpose'    => 'To answer questions about how the awards work. Falls back to scripted '
                    . 'answers when unavailable.',
            ]),
            // Reviewer-to-nominator decision note. Interpolates the nominator's
            // own text, and the output is sent to a real person, so a bad reply
            // must be discardable rather than merely clamped.
            'nomination.decision_note' => $c('nomination.decision_note', [
                'purpose'         => 'moderation',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 200,
                'calls_per_day'   => 1000,
                'tokens_per_day'  => 200_000,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => "The nominee's name and your reason text, with contact details replaced by "
                    . "placeholders, plus the reviewer's decision and their note.",
                'data_purpose'    => 'To draft the explanation sent to you when a nomination is decided. A '
                    . 'reviewer reads and can rewrite it before it is sent.',
            ]),
            // Community thread summary shown to readers.
            'community.thread_summary' => $c('community.thread_summary', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 320,
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 800_000,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The public posts in the thread being summarised, with contact details '
                    . 'replaced by placeholders. Author names are not sent.',
                'data_purpose'    => 'To produce the short summary shown at the top of a discussion.',
            ]),
            'community.polish' => $c('community.polish', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::PRIMARY[self::TIER_FAST],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 600,
                'calls_per_day'   => 3000,
                'tokens_per_day'  => 600_000,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'Only the draft post or comment you asked us to improve, with contact '
                    . 'details replaced by placeholders.',
                'data_purpose'    => 'To suggest clearer wording. You choose whether to keep it.',
            ]),
            // Admin drafting help for legal/programme copy. Operator-authored
            // prompt, so untrusted only in the sense that it is free text.
            'admin.content_assist' => $c('admin.content_assist', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 1400,
                'calls_per_day'   => 1000,
                'tokens_per_day'  => 1_000_000,
                'timeout'         => 20,
                'untrusted_input' => true,
            ]),
            'admin.form_design' => $c('admin.form_design', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 900,
                'calls_per_day'   => 300,
                'tokens_per_day'  => 300_000,
                'timeout'         => 20,
                'untrusted_input' => true,
            ]),
            'nominee.merge_suggest' => $c('nominee.merge_suggest', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 400,
                'calls_per_day'   => 500,
                'tokens_per_day'  => 200_000,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'A list of nominee names and their internal ids — nothing else. No contact '
                    . 'details, no nomination text.',
                'data_purpose'    => 'To spot the same person entered twice under different spellings. An '
                    . 'administrator confirms every merge, and merges can be undone.',
            ]),
            'integrity.brief' => $c('integrity.brief', [
                'purpose'        => 'assist',
                'tier'           => self::TIER_REASON,
                'model'          => self::PRIMARY[self::TIER_REASON],
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

    /** The model half of the pinned model ("gpt-4o"). */
    public function modelId(): string
    {
        $parts = explode(':', $this->model, 2);
        return $parts[1] ?? $parts[0];
    }

    /**
     * The ordered route to hand {@see AiService::complete()}: pin, then fallbacks.
     *
     * This is the value that makes the pin real. It was previously read only into
     * the audit log while the request itself was built from whichever provider key
     * was configured first.
     *
     * @return list<string>
     */
    public function route(): array
    {
        return array_slice(
            array_values(array_merge([$this->model], $this->fallbacks)),
            0,
            max(1, $this->maxAttempts)
        );
    }
}
