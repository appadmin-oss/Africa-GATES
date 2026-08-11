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
        /** Which tier's model and parameters this capability inherits. */
        public readonly string $tier,
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
     * THREE tiers, chosen by what the output is USED for rather than by how long the
     * prompt is:
     *
     *  • REASON — the answer feeds a decision a person will act on. Moderation scores,
     *    the reviewer's triage summary, the note sent to a nominator, duplicate-merge
     *    suggestions, the operator copilot, integrity briefs, search interpretation.
     *    Being wrong is the cost; latency is not.
     *
     *  • WRITE — the answer is PROSE a human reads and may publish: the public guide,
     *    thread summaries, rally copy on a share flier, admin drafting help. Needs a
     *    larger model than a classifier does, a longer output budget, and a warmer
     *    temperature, because the failure mode is not a wrong label but flat,
     *    generic, obviously-machine text that a nominee will not post.
     *
     *  • FAST — the answer is a suggestion the user immediately accepts or discards,
     *    on a synchronous request where latency IS the feature. Wording polish,
     *    category hints, admin filter parsing.
     */
    public const TIER_REASON = 'reason';
    public const TIER_WRITE  = 'write';
    public const TIER_FAST   = 'fast';

    /**
     * Primary model per tier. GROQ, whose relevant models are free-tier.
     *
     * This was briefly `openai:gpt-4o` / `openai:gpt-4o-mini`. That is wrong for this
     * deployment: the OpenAI key is not on a paid plan, so every pinned call would
     * 401 and fall through the ladder — turning the primary provider into a wasted
     * round trip on the hot path of every AI feature, most damagingly on
     * `moderation.classify`, which sits on the nomination submit and is capped at ONE
     * attempt. Pinning a provider that cannot answer there does not degrade the
     * feature gracefully; it disables it.
     *
     * `llama-3.3-70b-versatile` and `llama-3.1-8b-instant` are the two ids this
     * codebase has been running against, so they are known-good here rather than
     * guessed at. WRITE shares the 70b model with REASON and differs in its
     * parameters — see {@see PARAMS}. That is still delegation: the axis is
     * model-and-settings, and a classifier wanting a terse deterministic label and a
     * flier wanting warm publishable copy are not the same job even on one model.
     *
     * Every id is overridable per provider through admin Settings (`ai_groq_model`,
     * `ai_gemini_model`, …) or the environment, so moving to a newer Groq model — a
     * bigger free one, or one released after this was written — is a configuration
     * change, not a deploy.
     */
    public const PRIMARY = [
        self::TIER_REASON => 'groq:llama-3.3-70b-versatile',
        self::TIER_WRITE  => 'groq:llama-3.3-70b-versatile',
        self::TIER_FAST   => 'groq:llama-3.1-8b-instant',
    ];

    /**
     * Generation parameters per tier, so the delegation is real and not nominal.
     *
     * Temperature was previously a per-call literal at each of the twenty-one sites,
     * which is how a spam classifier and a piece of published copy ended up asking
     * for the same thing.
     *
     *  • REASON  — near-deterministic. A moderation score that moves between
     *              identical submissions is not a score.
     *  • WRITE   — warm enough to sound like a person, capped short of rambling.
     *  • FAST    — deterministic and tight; the user is waiting.
     *
     * A capability may still override `temperature` explicitly; this is the default
     * it inherits from its tier.
     */
    public const PARAMS = [
        self::TIER_REASON => ['temperature' => 0.15],
        self::TIER_WRITE  => ['temperature' => 0.7],
        self::TIER_FAST   => ['temperature' => 0.2],
    ];

    /**
     * Fallback ladder per tier, in order, used when a capability does not declare
     * its own.
     *
     * ORDERED BY WHAT THIS DEPLOYMENT CAN ACTUALLY REACH. Gemini has a free tier and
     * comes first. OpenAI is LAST, deliberately: an unpaid key fails with a 401 rather
     * than being absent, and `resolveRoute()` can only skip a provider with NO key —
     * it cannot know a key is unfunded. So a hop for an unpaid provider costs a real
     * timeout, and it belongs behind every provider that might answer.
     *
     * FAST does not climb to a heavier model: a 700ms suggestion that arrives after
     * the user has finished typing is worse than no suggestion.
     */
    private const FALLBACKS = [
        self::TIER_REASON => ['gemini:', 'groq:llama-3.1-8b-instant'],
        self::TIER_WRITE  => ['gemini:', 'groq:llama-3.1-8b-instant'],
        self::TIER_FAST   => ['gemini:', 'groq:llama-3.3-70b-versatile'],
    ];

    /**
     * TWO fallbacks, not three, because the default attempt cap is three hops total.
     *
     * A ladder longer than the ceiling has rungs nobody can stand on, and in review it
     * reads as coverage that exists. This was got wrong twice in a row: OpenAI was
     * listed fourth and unreachable, and moving it out left Anthropic fourth and
     * unreachable. `AiModelDelegationTest` now asserts declared-hops ≤ maxAttempts for
     * every capability, so the third instance of this fails the suite instead of
     * shipping.
     *
     * Anthropic and OpenAI are NOT absent, and that is the point rather than an omission.
     *
     * It was listed last, and last was unreachable: `route()` truncates to
     * `maxAttempts` (3 by default), so a four-entry ladder never got to its fourth
     * hop. A declared slot nothing can reach reads as coverage that does not exist.
     *
     * `AiService::resolveRoute()` appends every remaining CONFIGURED provider after the
     * declared hops, so a deployment holding an Anthropic or a funded OpenAI key still
     * reaches it whenever the cap allows — and a deployment whose ONLY key is one of
     * those gets every feature, because a pin decides preference, not eligibility.
     *
     * They simply do not occupy a declared slot ahead of a provider with a free tier.
     * An unpaid key fails with a 401 rather than being absent, and `resolveRoute()`
     * cannot tell those apart: it can skip a provider with NO key, not one whose key is
     * unfunded. So the hop costs a real timeout, and a capped capability must not spend
     * one of three attempts on it.
     *
     * The second Groq hop matters more than a third vendor here. Groq's free tier
     * rate-limits PER MODEL, so a 429 on the 70b model says nothing about the 8b one —
     * that is the most likely recoverable failure on this deployment's own primary
     * provider, and it now sits ahead of anything that needs a different key.
     */
    private const LADDER_NOTE = 'see FALLBACKS';

    /**
     * The tier ladder, minus any hop that repeats the pin EXACTLY.
     *
     * Provider-and-model, not provider alone. The earlier rule dropped every hop
     * sharing the pin's provider, on the reasoning that a provider which just failed
     * will fail again. That is right for an outage and wrong for the failure this
     * deployment will actually see: Groq's free tier rate-limits PER MODEL, so a 429
     * on `llama-3.1-8b-instant` says nothing about whether `llama-3.3-70b-versatile`
     * can answer. Dropping the second Groq hop would throw away the most likely
     * successful retry on the platform's own primary provider.
     *
     * An exact repeat is still removed, because retrying the identical
     * provider-and-model immediately is only a doubled timeout.
     *
     * @return list<string>
     */
    private static function defaultFallbacks(string $pinned, string $tier): array
    {
        return array_values(array_filter(
            self::FALLBACKS[$tier] ?? self::FALLBACKS[self::TIER_FAST],
            static fn (string $hop): bool => $hop !== $pinned
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
            tier:           $o['tier'] ?? self::TIER_FAST,
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
            // ── the judging interview ────────────────────────────────────────
            //
            // Both of these read a nominee's own file, and the second reads a transcript of
            // them speaking. That makes them the most personal text this platform sends
            // anywhere, so both are declared `public_content` and appear in the visitor-facing
            // AI notice — and the nominee is told, in the consent they give before the
            // interview happens, that a machine will write the transcript and help the panel
            // read it. Consent that omits the model is not consent to the model.
            //
            // FAIL_DEGRADE on both. The interview must be preparable and readable with no
            // provider at all: {@see InterviewBrief} builds a grounded question pack from the
            // rubric and the dossier, and {@see InterviewReview} still runs its figure and
            // coverage checks. A panel opening the console on the morning of a sitting must
            // never find an empty page because a free tier ran out overnight.
            'interview.brief' => $c('interview.brief', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                // Six questions with follow-ups and a line of rationale each.
                'max_tokens'      => 1200,
                // An interview per nominee per cycle, rebuildable a few times. Nowhere near
                // a hot path, so the budget is small on purpose: a runaway loop here would
                // be spending on a queue nobody is watching.
                'calls_per_day'   => 300,
                'tokens_per_day'  => 300_000,
                'timeout'         => 20,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'For a nominee being interviewed by the judging panel: their name, '
                    . 'organisation and country, the award criteria, and what their file says about them — '
                    . 'the nomination reason and any evidence added by the programme team. Contact details '
                    . 'are replaced with placeholders first. Vote counts, rankings and scores are never sent.',
                'data_purpose'    => 'To prepare the questions the panel will ask, so a nominee is asked about '
                    . 'their own work rather than from a standard list. A person asks the questions and a '
                    . 'person decides the score.',
            ]),
            // The nominee's own questionnaire, answered as a conversation. FAST tier and one
            // attempt: somebody is sitting looking at a chat box waiting for a reply, and a
            // follow-up that arrives after they have moved on is worse than none.
            //
            // The model NEVER authors an answer here — {@see QuestionnaireChat} stores the
            // nominee's own words verbatim and asks the model only whether one follow-up is
            // worth asking. That division is the point: the record says "supplied by the
            // nominee", and a model that tidied their sentence would make that a lie.
            'questionnaire.chat' => $c('questionnaire.chat', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::PRIMARY[self::TIER_FAST],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 90,
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 300_000,
                'timeout'         => 6,
                'max_attempts'    => 1,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The question you were just asked about your own work, and the '
                    . 'answer you typed. Contact details in your answer are replaced with '
                    . 'placeholders first. Nothing else from your file is sent, and your answers are '
                    . 'stored exactly as you wrote them.',
                'data_purpose'    => 'To decide whether one short follow-up question would help the '
                    . 'judges — for example asking for a number or who keeps a record. It never '
                    . 'writes or changes your answer, and it produces no score.',
            ]),
            // ONE short question, while a person is waiting to ask it. FAST tier because a
            // follow-up that arrives after the interviewer has moved on is worse than none —
            // and one attempt only, for the same reason moderation on the nomination submit
            // is capped: chaining providers here would spend twenty seconds of a live
            // conversation on a suggestion nobody can still use.
            'interview.followup' => $c('interview.followup', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::PRIMARY[self::TIER_FAST],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 90,
                // Roughly one per 40s per question, so a busy day of interviews cannot
                // exhaust it — but bounded, because this is the only capability on the
                // platform driven by a loop in somebody else's browser.
                'calls_per_day'   => 1200,
                'tokens_per_day'  => 200_000,
                'timeout'         => 6,
                'max_attempts'    => 1,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'During a judging interview, the last few sentences of what the '
                    . 'nominee has just said — taken from Google Meet\'s live captions — and the '
                    . 'question the panel had asked. Only while the nominee has given permission to '
                    . 'be recorded, and contact details are replaced with placeholders first.',
                'data_purpose'    => 'To suggest the next question for the panel to ask, so a nominee '
                    . 'is followed up on what they actually said. A person decides whether to ask it, '
                    . 'and it produces no score.',
            ]),
            'interview.review' => $c('interview.review', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                // A transcript sorted into four criteria with quotes is the longest output
                // any capability here produces.
                'max_tokens'      => 2000,
                'calls_per_day'   => 300,
                'tokens_per_day'  => 600_000,
                'timeout'         => 30,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The transcript of a judging interview — the nominee\'s own recorded '
                    . 'words — and the award criteria. Only ever after the nominee has given permission for '
                    . 'a transcript to be kept, and contact details are replaced with placeholders first.',
                'data_purpose'    => 'To sort what was said by which criterion it relates to, quoting the '
                    . 'nominee rather than summarising, so a judge reads the whole interview instead of '
                    . 'remembering part of it. It produces no score, rating or ranking of any kind.',
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
                'tier'            => self::TIER_WRITE,
                'model'           => self::PRIMARY[self::TIER_WRITE],
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
            // ── Support assistant ────────────────────────────────────────
            // Two capabilities, because they are two different jobs with
            // different failure modes, and collapsing them would mean one budget
            // and one kill switch for both.
            //
            // PLANNING picks the next lookup. It runs several times per answer,
            // must return JSON, and is pinned to the FAST tier — a routing
            // decision does not need a 70B model, and its latency is multiplied
            // by the number of rounds.
            'support.plan' => $c('support.plan', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::PRIMARY[self::TIER_FAST],
                // A planner that fails just means the agent answers with what it
                // already has. That is a worse answer, never a broken page.
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 220,
                'calls_per_day'   => 8000,
                'tokens_per_day'  => 1_500_000,
                'timeout'         => 15,
                'untrusted_input' => true,
                'public_content'  => false,
                'data_sent'       => 'Your support message and the names of the lookups available, so the '
                    . 'assistant can decide what to check. No account data is sent at this step.',
                'data_purpose'    => 'To decide which of your records to look up before answering.',
            ]),
            // COMPOSING writes the reply. It reads every lookup result at once,
            // so it wants the long-context provider and a larger budget — and it
            // is the step whose output a person actually reads.
            'support.answer' => $c('support.answer', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_WRITE,
                // Gemini first here, deliberately: this step is judged on prose
                // and on holding several lookup results in mind at once. Pinned to
                // a NAMED model rather than 'gemini:' — a pin that defers to
                // whatever the provider defaults to is not a pin, and the registry
                // test enforces that.
                'model'           => 'gemini:gemini-3.6-flash',
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 700,
                'calls_per_day'   => 4000,
                'tokens_per_day'  => 2_000_000,
                'timeout'         => 25,
                'untrusted_input' => true,
                'public_content'  => false,
                'data_sent'       => 'Your support message and the results of the lookups the assistant ran '
                    . 'on YOUR OWN records — payment status, amounts, dates. Never another person\'s data.',
                'data_purpose'    => 'To write an answer grounded in your actual records rather than a guess.',
            ]),
            // Reviewer-to-nominator decision note. Interpolates the nominator's
            // own text, and the output is sent to a real person, so a bad reply
            // must be discardable rather than merely clamped.
            'nomination.decision_note' => $c('nomination.decision_note', [
                'purpose'         => 'moderation',
                'tier'            => self::TIER_WRITE,
                'model'           => self::PRIMARY[self::TIER_WRITE],
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
                'tier'            => self::TIER_WRITE,
                'model'           => self::PRIMARY[self::TIER_WRITE],
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
                'tier'            => self::TIER_WRITE,
                'model'           => self::PRIMARY[self::TIER_WRITE],
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
                'tier'            => self::TIER_WRITE,
                'model'           => self::PRIMARY[self::TIER_WRITE],
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
            /**
             * Rally copy for a nominee's shareable flier.
             *
             * WRITE tier, and it is the clearest case for that tier existing: the
             * output is a line a real person posts under their own name to their own
             * community. A classifier's temperature produces "Vote for X in the
             * Africa GATES awards" every time, which nobody shares — the failure mode
             * is not inaccuracy but text that is obviously machine-written, and a
             * flier nobody posts is a feature that does nothing.
             *
             * Degrades silently to a written fallback line. A nominee pressing
             * "download" must never see an error where a graphic should be.
             */
            'nominee.rally_copy' => $c('nominee.rally_copy', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_WRITE,
                'model'           => self::PRIMARY[self::TIER_WRITE],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 160,
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 300_000,
                'timeout'         => 12,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => "The nominee's name, their award category, and their current "
                    . 'position in the public standings — all of which are already shown on the '
                    . 'nominee page. No contact details, and nothing from the nomination text.',
                'data_purpose'    => 'To draft one line of rally copy for a shareable flier. You see it '
                    . 'before you share it, and a written fallback is used if no model answers.',
            ]),
            /**
             * Interpreting a plain-English activity search.
             *
             * REASON, not WRITE: the output is a set of FILTERS the platform then acts
             * on, so it is a decision and needs a deterministic temperature. Every
             * field it returns is validated against a whitelist before use — the model
             * cannot introduce a filter, a table or a value the code did not already
             * allow, which is the same discipline admin.filter_parse already applies.
             */
            'search.interpret' => $c('search.interpret', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::PRIMARY[self::TIER_REASON],
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 220,
                'calls_per_day'   => 4000,
                'tokens_per_day'  => 400_000,
                // On a live search request, so it must not chain. A plain text search
                // is always available underneath and is the better answer than a wait.
                'timeout'         => 5,
                'max_attempts'    => 1,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'Only the words you typed into the search box, with contact details '
                    . 'replaced by placeholders. Nothing about you and nothing about anyone else.',
                'data_purpose'    => 'To work out what you meant — which kind of activity, which country, '
                    . 'what timeframe — so the search can filter as well as match text. Your words '
                    . 'are also searched literally either way.',
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
     * Default generation temperature for this capability's tier.
     *
     * A capability may override it per call; this is what it inherits. Read by
     * {@see AiGateway} so a classifier cannot accidentally be asked for creative
     * output, or a piece of published copy for a deterministic label.
     */
    public function temperature(): float
    {
        return (float) (self::PARAMS[$this->tier]['temperature']
            ?? self::PARAMS[self::TIER_FAST]['temperature']);
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
