<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

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
     * The providers this platform can be pointed at, and what to call them on a screen.
     *
     * @var array<string,string>
     */
    public const PROVIDERS = [
        'gemini'    => 'Google Gemini',
        'groq'      => 'Groq',
        'openai'    => 'OpenAI',
        'anthropic' => 'Anthropic',
    ];

    /** Where an operator's choice is kept. `.env` AI_PRIMARY is the fallback. */
    public const PRIMARY_SETTING = 'ai_primary';

    /**
     * The SHIPPED per-tier default id for a provider whose tiers want different models.
     *
     * Only Groq is listed, because Groq is the one provider configured here that offers
     * two ids worth distinguishing: a big model for a judgement and a small one for a
     * suggestion somebody is waiting behind. Everywhere else the tiers differ in their
     * parameters and in the order of their ladders, on one model.
     *
     * A DEFAULT, and behind the operator. {@see primary()} consults `ai_groq_model` first —
     * a settings field the registry overruled would be a control that saves and does
     * nothing — so this decides only for a deployment that has not chosen.
     *
     * @var array<string, array<string,string>>
     */
    private const TIER_MODELS = [
        'groq' => [
            self::TIER_REASON => 'llama-3.3-70b-versatile',
            self::TIER_WRITE  => 'llama-3.3-70b-versatile',
            self::TIER_FAST   => 'llama-3.1-8b-instant',
        ],
    ];

    /**
     * What a deployment leads with when nobody has said otherwise.
     *
     * ── WHY THIS MOVED OFF GROQ ──────────────────────────────────────────────
     *
     * It was Groq, for a reason that was true and has stopped being the whole story:
     * Groq's relevant models are free-tier, and the alternative at the time was an OpenAI
     * key that was not on a paid plan — so every pinned call 401'd and fell through the
     * ladder, turning the primary into a wasted round trip on the hot path of every AI
     * feature. Most damagingly on `moderation.classify`, capped at ONE attempt, where
     * pinning a provider that cannot answer does not degrade the feature but disables it.
     *
     * Gemini also has a free tier, is already first in every fallback ladder here, and is
     * the provider this deployment now leads with. Groq stays directly behind it, so the
     * failure being guarded against — a primary that cannot answer — is one hop from
     * recovery.
     *
     * ── AND IT IS ONE VALUE, NOT THREE ───────────────────────────────────────
     *
     * This was `PRIMARY`, a public per-tier array. Two things went wrong with that shape
     * the moment the primary became a setting. It had no reader left — {@see primary()}
     * resolves the operator's choice, and nothing consulted the constant — and a declared
     * value nothing reads is the most expensive bug available in this codebase. Worse, it
     * was a value somebody WOULD read: a constant called PRIMARY, naming a provider,
     * while the provider was somewhere else entirely.
     *
     * Per-tier is also the wrong shape now. The three tiers differ in their parameters and
     * in the order of their ladders, not in who they lead with; three identical entries
     * invited a fourth to diverge silently. The one place tiers really do want different
     * model ids is {@see TIER_MODELS}, which is about a provider's catalogue rather than
     * about preference.
     *
     * It must stay a provider with a FREE TIER: an unconfigured platform still has to be
     * able to think.
     */
    public const DEFAULT_PRIMARY = 'gemini';

    /** The provider every un-pinned capability leads with, from settings or `.env`. */
    private static ?string $primaryMemo = null;

    public static function primaryProvider(): string
    {
        if (self::$primaryMemo !== null) return self::$primaryMemo;

        $v = '';
        try {
            $v = (string) (DB::table('gates_settings')
                ->where('key_name', self::PRIMARY_SETTING)->value('value') ?? '');
        } catch (\Throwable) {
            // No settings table yet. The shipped default still answers.
        }
        if (trim($v) === '') $v = (string) Env::get('AI_PRIMARY', '');

        // Lower-cased and trimmed because this arrives from a form, and validated against
        // PROVIDERS because anything else would become a pin like `chatgpt:gpt-4o` that
        // every capability then carries to a router which has never heard of it — the
        // platform going dark on a setting nobody can see is wrong.
        $v = strtolower(trim($v));

        return self::$primaryMemo = isset(self::PROVIDERS[$v]) ? $v : self::DEFAULT_PRIMARY;
    }

    /**
     * The pin for a tier: the operator's provider, on a CONCRETE model.
     *
     * ── WHY THE MODEL HALF IS RESOLVED HERE AND NOT LEFT EMPTY ───────────────
     *
     * `gemini:` with no model would work — `AiService::modelFor()` falls through to the
     * configured id and then to the shipped default — and it would be a pin that made no
     * decision. The registry's whole job is that every capability's route is DATA somebody
     * can read, and a half-written pin reads as a choice while being an absence.
     *
     * So the id is resolved HERE instead, from the same three places every other model id
     * on this platform comes from: what the operator typed into the field beside the
     * picker, then {@see TIER_MODELS} for a provider whose tiers want genuinely different
     * ids rather than one model at different temperatures, then what this platform ships
     * pointing at. That keeps the settings field meaningful and the pin concrete at once.
     *
     * A capability may still name a provider outright — {@see all()}'s
     * `door.name_pronounce` does — and that beats this, because it is a choice about that
     * one job rather than a preference about the platform.
     */
    public static function primary(string $tier): string
    {
        $p = self::primaryProvider();

        // The operator's own id first, and the ORDER is the point. `ai_groq_model` is a
        // field on the settings screen; if TIER_MODELS won, that field would save, redisplay
        // its value, and change nothing — a control that does nothing is the exact shape of
        // half the defects recorded in this file. TIER_MODELS is the shipped default for a
        // provider whose tiers want different ids, not an override of a person.
        $chosen = self::chosenModel($p);

        return $p . ':' . ($chosen !== ''
            ? $chosen
            : (self::TIER_MODELS[$p][$tier] ?? self::shippedModel($p)));
    }

    /** @var array<string,string> provider => the operator's id, '' when they set none */
    private static array $chosenMemo = [];

    /**
     * The model id a provider is currently set to: admin settings, `.env`, then the
     * shipped default. One resolver, so the pin and the router cannot disagree about
     * which model "Gemini" means.
     */
    public static function modelIdFor(string $provider): string
    {
        $chosen = self::chosenModel($provider);

        return $chosen !== '' ? $chosen : self::shippedModel($provider);
    }

    /**
     * The id an operator has actually CHOSEN for a provider, or '' — settings, then
     * `.env`, and no shipped default.
     *
     * Split out from {@see modelIdFor()} because the two callers need different things
     * and collapsing them loses one: a pin has a per-tier default to fall to, a bare
     * ladder hop has only the shipped one, and both need to know whether the value they
     * are looking at came from a person.
     *
     * Memoised per process because building the registry asks about the same handful of
     * providers roughly eighty times — once per pin and once per ladder hop — and each
     * miss is a settings query. {@see forget()} clears it.
     */
    private static function chosenModel(string $provider): string
    {
        if (isset(self::$chosenMemo[$provider])) return self::$chosenMemo[$provider];

        $v = '';
        try {
            $v = (string) (DB::table('gates_settings')
                ->where('key_name', 'ai_' . $provider . '_model')->value('value') ?? '');
        } catch (\Throwable) {
            // No settings table yet — the shipped default still names a model.
        }
        if (trim($v) === '') $v = (string) Env::get(strtoupper($provider) . '_MODEL', '');

        return self::$chosenMemo[$provider] = trim($v);
    }

    /** What this platform ships pointing at, when nobody has said otherwise. */
    private static function shippedModel(string $provider): string
    {
        return AiService::DEFAULT_MODELS[$provider] ?? AiService::DEFAULT_MODELS['openai'];
    }

    /**
     * A hop written out in full, so two spellings of one request compare equal.
     *
     * `gemini:` means "this provider's configured model" and is the same call as
     * `gemini:gemini-3.6-flash`. Nothing may compare two hops as strings without coming
     * through here first — see {@see ladder()} for what that cost the last time — which
     * is why this is public: the test that asserts a ladder never repeats its own pin is
     * exactly the caller that must not do it by hand.
     */
    public static function concrete(string $hop): string
    {
        [$provider, $model] = array_pad(explode(':', $hop, 2), 2, '');
        $model = trim($model);

        return $provider . ':' . ($model !== '' ? $model : self::modelIdFor($provider));
    }

    /**
     * Drop the memoised registry and the memoised provider.
     *
     * `all()` is built once per process and the pin is resolved while building it, so a
     * change to the setting is invisible until the next request. That is right in
     * production — a request must not change providers halfway — and wrong for a test that
     * needs to see both, which is the only caller.
     */
    public static function forget(): void
    {
        self::$memo = null;
        self::$primaryMemo = null;
        self::$chosenMemo = [];
    }

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
     * Fallback ladder per tier, in order, used when a capability does not declare its own.
     *
     * ── FREE-TIER ONLY, AND THAT IS THE RULE RATHER THAN THE ORDER ───────────
     *
     * `resolveRoute()` can skip a provider with NO key. It cannot tell a key that is
     * unfunded, expired, quota-exhausted or region-blocked from one that works, because
     * all of those fail at request time with a 401 or a 429 rather than being absent. So
     * a hop for a provider that needs a billing relationship costs a real timeout, and a
     * capability with three attempts must not spend one on a maybe.
     *
     * Anthropic and OpenAI are therefore not here, and that is deliberate rather than an
     * omission. They are still REACHABLE two ways: a capability may name one outright —
     * `door.name_pronounce` does, and says why beside its pin — and `resolveRoute()`
     * appends every remaining CONFIGURED provider after the declared hops, so a
     * deployment whose only key is Anthropic gets every feature. A pin decides
     * preference, not eligibility.
     *
     * ── THREE ENTRIES, TWO SLOTS ─────────────────────────────────────────────
     *
     * The default cap is three attempts INCLUDING the pin, so a ladder may declare two.
     * Three are listed because {@see ladder()} removes whichever one the pin has taken —
     * the primary is a setting now, and it lands on one of these — leaving two either
     * way. A rung nobody can stand on reads in review as coverage that exists, and this
     * codebase has shipped that twice: OpenAI listed fourth and unreachable, then
     * Anthropic fourth and unreachable after OpenAI moved out. `AiModelDelegationTest`
     * asserts declared-hops <= maxAttempts for every capability, so the third instance
     * fails the suite instead of shipping.
     *
     * Groq occupies two of the three slots rather than a third vendor taking one. Its
     * free tier rate-limits PER MODEL, so a 429 on the 70b says nothing about whether the
     * 8b can answer — that is the most likely recoverable failure on a provider this
     * deployment can definitely reach, and it belongs ahead of anything needing a
     * different key.
     */
    private const FALLBACKS = [
        self::TIER_REASON => ['gemini:', 'groq:llama-3.3-70b-versatile', 'groq:llama-3.1-8b-instant'],
        self::TIER_WRITE  => ['gemini:', 'groq:llama-3.3-70b-versatile', 'groq:llama-3.1-8b-instant'],
        // FAST climbs rather than descends, and only as a FALLBACK: a 700ms suggestion
        // that arrives after the user has finished typing is worse than no suggestion, so
        // the small model is tried before the big one.
        self::TIER_FAST   => ['gemini:', 'groq:llama-3.1-8b-instant', 'groq:llama-3.3-70b-versatile'],
    ];

    /**
     * A capability's fallback ladder, minus any hop that repeats the pin.
     *
     * Provider-and-model, not provider alone. The earlier rule dropped every hop
     * sharing the pin's provider, on the reasoning that a provider which just failed
     * will fail again. That is right for an outage and wrong for the failure this
     * deployment will actually see: Groq's free tier rate-limits PER MODEL, so a 429
     * on `llama-3.1-8b-instant` says nothing about whether `llama-3.3-70b-versatile`
     * can answer. Dropping the second Groq hop would throw away the most likely
     * successful retry on the platform's own primary provider.
     *
     * ── AND WHY THE COMPARISON IS NOT A STRING COMPARISON ────────────────────
     *
     * This registry spells one hop two ways. A ladder entry is written `gemini:` —
     * "whatever model this provider is configured to" — while {@see primary()} writes
     * its pin out in full. So `$hop !== $pinned` saw `gemini:` and
     * `gemini:gemini-3.6-flash` as two different hops and kept both, which is not a
     * fallback: it is the pin, tried twice, spending one of only three attempts on the
     * provider that has just failed. Silent, too — the route reads as a three-hop
     * ladder in the audit log and is really two.
     *
     * Both sides go through {@see concrete()} first, so an exact repeat is removed
     * however it happens to be written, and Groq's two ids still count as two hops.
     *
     * A DECLARED ladder gets the same filter — the hole is not the inherited list, it is
     * the string comparison, and a capability that names its own hops can write the pin
     * two ways just as easily. What it does NOT get is the trim: a declared ladder is a
     * decision about that one capability, made beside its own attempt cap.
     *
     * @param list<string>|null $declared null to inherit the tier's ladder
     * @return list<string>
     */
    private static function ladder(?array $declared, string $pinned, string $tier): array
    {
        $pin  = self::concrete($pinned);
        $hops = $declared ?? (self::FALLBACKS[$tier] ?? self::FALLBACKS[self::TIER_FAST]);

        $out = array_values(array_filter(
            $hops,
            static fn (string $hop): bool => self::concrete($hop) !== $pin
        ));

        // TWO, because the default attempt cap is three hops INCLUDING the pin. A rung
        // nobody can stand on reads in review as coverage that exists, and this codebase
        // has shipped that twice — once with OpenAI fourth, once with Anthropic fourth.
        // `AiModelDelegationTest` asserts declared-hops <= maxAttempts for every
        // capability; this is the line that keeps it true when the pin moves.
        return $declared !== null ? $out : array_slice($out, 0, 2);
    }

    /**
     * The built registry. A `static $all` inside {@see all()} once, which no test could
     * reach — and the pins are resolved from settings while it is built, so a suite that
     * cannot clear it can only ever see one provider's worth of the registry.
     */
    private static ?array $memo = null;

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
        if (self::$memo !== null) return self::$memo;

        $c = static fn (string $name, array $o): self => new self(
            name:           $name,
            purpose:        $o['purpose'],
            tier:           $o['tier'] ?? self::TIER_FAST,
            model:          $o['model'],
            maxAttempts:    $o['max_attempts'] ?? 3,
            fallbacks:      self::ladder($o['fallbacks'] ?? null, $o['model'], $o['tier'] ?? self::TIER_FAST),
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

        return self::$memo = [
            // Reviewer-facing score + summary for a nomination. Interpolates the
            // nominator's free text, so it is the single most injection-exposed
            // capability on the platform.
            'nomination.triage' => $c('nomination.triage', [
                'purpose'         => 'moderation',
                'tier'            => self::TIER_REASON,
                'model'           => self::primary(self::TIER_REASON),
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
            // ── THE COUNTDOWN LETTERS, DRAFTED FOR ONE CEREMONY ─────────────
            //
            // Writes the five letters an organiser will send to their nominees in the
            // final week. TIER_WRITE, because this is composition rather than judgement,
            // and a large budget per call because five letters is genuinely long output —
            // a truncated day four is a letter that stops mid-argument.
            //
            // NOT untrusted input. Everything it is given is a ceremony an ADMIN entered:
            // the title, the theme, the venue, the values. No nominee's text reaches it,
            // which is why the fence and the minimiser are off — and why that has to be
            // reconsidered the day anybody thinks of feeding it a nomination.
            //
            // Advisory, always. Nothing it returns is sent to anybody: an operator reads
            // five drafts and chooses. A model that could post its own letters to a
            // shortlist would be the single most consequential unattended writer on this
            // platform, and it is not one.
            'invite.sequence_draft' => $c('invite.sequence_draft', [
                'purpose'         => 'general',
                'tier'            => self::TIER_WRITE,
                'model'           => self::primary(self::TIER_WRITE),
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 3000,
                // Small on purpose. This is an operator pressing a button a handful of
                // times while they settle the wording for one ceremony, not a hot path —
                // and each call is expensive enough that a runaway would be noticed as a
                // bill rather than as a slowdown.
                'calls_per_day'   => 40,
                'tokens_per_day'  => 200_000,
                // Five long letters do not come back in six seconds.
                'timeout'         => 45,
                'data_sent'       => 'The ceremony\'s name, theme, date and venue, and the wording an '
                    . 'administrator set for it. Nothing a nominee or member of the public wrote.',
                'data_purpose'    => 'To draft the countdown letters for an organiser to read, edit and '
                    . 'choose. Nothing it writes is sent to anybody without a person saving it first.',
            ]),
            // Spam/abuse classifier. Must never be the thing that decides.
            'moderation.classify' => $c('moderation.classify', [
                'purpose'         => 'moderation',
                'tier'            => self::TIER_REASON,
                'model'           => self::primary(self::TIER_REASON),
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
                'model'           => self::primary(self::TIER_FAST),
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
                'model'           => self::primary(self::TIER_REASON),
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
                'model'           => self::primary(self::TIER_FAST),
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
            // Help WHILE an answer is being written, rather than a verdict after it is
            // finished. FAST and one attempt, for the same reason as the follow-up above: a
            // suggestion that arrives after somebody has moved to the next question is worse
            // than none, and chaining providers would spend ten seconds of their attention on
            // it. Every check that matters is mechanical and runs with no key at all — this
            // only phrases the one specific thing a rule cannot see.
            'questionnaire.coach' => $c('questionnaire.coach', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::primary(self::TIER_FAST),
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 70,
                'calls_per_day'   => 3000,
                'tokens_per_day'  => 300_000,
                'timeout'         => 5,
                'max_attempts'    => 1,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The question you were asked and the answer you have typed so '
                    . 'far. Contact details are replaced with placeholders first. Nothing else from '
                    . 'your file is sent, and your answer is stored exactly as you wrote it.',
                'data_purpose'    => 'To point out one fact that is missing — a number, a date, a '
                    . 'place, or who could confirm it — while you are still writing. It never '
                    . 'rewrites your answer, never comments on the work itself, and produces no '
                    . 'score of any kind.',
            ]),
            // The live interview: a whole conversation, not one classification. Different from
            // every other capability here on three axes, and each one is a deliberate choice.
            //
            // REASON tier, not FAST. The other questionnaire capabilities are advisory — a
            // follow-up that arrives late is merely unhelpful. This one IS the questionnaire:
            // it decides what to ask, reads whether an answer landed, and calls the tools that
            // build the record a judging panel reads. A cheap model that quotes approximately
            // rather than exactly produces a ledger of rejected calls and an interview that
            // never converges.
            //
            // ADVISORY, like everything else here, and worth stating why given how central it
            // looks. `advisory` does not mean "unimportant" — it means the result may not
            // block, reject, approve or rank. This one cannot: it asks questions and quotes
            // answers. It cannot refuse a submission, cannot score anybody, and cannot submit
            // — the nominee does that by typing their own name, and propose_complete only
            // opens the screen where they do it.
            //
            // What it does NOT degrade to is nothing. When it cannot run, the nominee is moved
            // to the guided form with everything already said carried across — see
            // QuestionnaireInterview::switchToForm() — and told why. A dead end on a deadline
            // is the one failure this feature is not allowed to have.
            //
            // The daily ceilings are per PLATFORM and sit above the per-submission ceiling in
            // gates_questionnaire_config. Two limits because they stop different things: the
            // per-submission one stops one person's conversation running away, and this one
            // stops a bad day across every conversation at once.
            'questionnaire.interview' => $c('questionnaire.interview', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::primary(self::TIER_REASON),
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 900,
                'calls_per_day'   => 4000,
                'tokens_per_day'  => 8_000_000,
                'timeout'         => 45,
                'max_attempts'    => 2,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'What you type in the conversation, and what the interviewer '
                    . 'has already asked. Contact details in your messages are replaced with '
                    . 'placeholders first. The award\'s own published guidance is sent as '
                    . 'background. Nothing else from your file is sent, no score of any kind is '
                    . 'sent, and your words are stored exactly as you wrote them.',
                'data_purpose'    => 'To hold the interview: to decide what to ask you next, and to '
                    . 'mark which of the things the panel needs you have now described — always '
                    . 'by quoting your own words, never by writing an answer for you. It cannot '
                    . 'send anything to the judges; only you can do that, by typing your name.',
            ]),
            // ONE short question, while a person is waiting to ask it. FAST tier because a
            // follow-up that arrives after the interviewer has moved on is worse than none —
            // and one attempt only, for the same reason moderation on the nomination submit
            // is capped: chaining providers here would spend twenty seconds of a live
            // conversation on a suggestion nobody can still use.
            'interview.followup' => $c('interview.followup', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::primary(self::TIER_FAST),
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
                'model'           => self::primary(self::TIER_REASON),
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
                'model'           => self::primary(self::TIER_FAST),
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
                'model'          => self::primary(self::TIER_FAST),
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
                'model'           => self::primary(self::TIER_REASON),
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
                'model'           => self::primary(self::TIER_WRITE),
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
                'model'           => self::primary(self::TIER_FAST),
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
                'model'           => self::primary(self::TIER_WRITE),
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
                'model'           => self::primary(self::TIER_WRITE),
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
                'model'           => self::primary(self::TIER_FAST),
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
                'model'           => self::primary(self::TIER_WRITE),
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
                'model'           => self::primary(self::TIER_WRITE),
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
                'model'           => self::primary(self::TIER_REASON),
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
                'model'           => self::primary(self::TIER_WRITE),
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
             * Matching what a vendor SELLS to the organiser's own trade categories.
             *
             * ── WHY A MODEL AND NOT A KEYWORD TABLE ─────────────────────────
             *
             * The category list is the organiser's, set per event — a book fair adds
             * "publishing", a food festival drops "beauty" — so there is no fixed
             * vocabulary to write rules against. A keyword table would have to be
             * rewritten every time somebody edits the list, and the first event whose
             * list it had not been rewritten for would silently match nothing.
             *
             * ── AND WHY IT ONLY EVER SUGGESTS ───────────────────────────────
             *
             * The category is what a QUOTA is set against, and the quota is the entire
             * fairness mechanism for stands — twelve jewellery stalls and no food is
             * the failure §10.1 exists to prevent. A model that silently filed an
             * applicant under the wrong trade would move somebody between queues
             * without either of them knowing. So this returns a suggestion the vendor
             * accepts or ignores, on a control they can already operate by hand, and
             * the form works identically with no model configured at all.
             *
             * FAST tier: the answer is one slug off a list the prompt carries.
             */
            'vendor.category_match' => $c('vendor.category_match', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_FAST,
                'model'           => self::primary(self::TIER_FAST),
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 120,
                'calls_per_day'   => 3000,
                'tokens_per_day'  => 200_000,
                // On a button somebody is waiting behind, not a background job.
                'timeout'         => 8,
                'max_attempts'    => 1,
                'untrusted_input' => true,
                'public_content'  => false,
                'data_sent'       => 'What you typed in "Describe your goods", and the list of trade '
                    . 'categories this event publishes. Nothing about you, your business name or '
                    . 'your contact details is sent.',
                'data_purpose'    => 'To suggest which trade category your description fits, so you do '
                    . 'not have to read a list of seven. You choose the category yourself — the '
                    . 'suggestion only moves the control, and the form works without it.',
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
                'model'           => self::primary(self::TIER_REASON),
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
            // A plain summary of what a nominee has said, for them to confirm before sending
            // and for the panel to read after. Both styles, one summary — see
            // QuestionnaireSummary for why it is not two.
            'questionnaire.summary' => $c('questionnaire.summary', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_WRITE,
                'model'           => self::primary(self::TIER_WRITE),
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 600,
                // One per submission, cached on the answers' hash — so this is roughly one
                // call per nominee per edit, not per page view.
                'calls_per_day'   => 2000,
                'tokens_per_day'  => 2_000_000,
                'timeout'         => 25,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'The answers you wrote in your questionnaire, with contact '
                    . 'details replaced by placeholders. Never your email or phone number.',
                'data_purpose'    => 'To show you a short summary of what you have said before you '
                    . 'send it, so you can check we have understood you — and to give the panel '
                    . 'the same summary at the top of your entry. It is a summary, never a score, '
                    . 'and your own words are what the judges read.',
            ]),
            // Orients a judge in ONE nominee's dossier before they read it. Never sees the
            // rest of the field, so it cannot rank — see JudgeAssist for why that is a
            // property of the call and not a rule in the prompt.
            'judge.orientation' => $c('judge.orientation', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => self::primary(self::TIER_REASON),
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                'max_tokens'      => 700,
                // A panel of ten judges opening forty ballots each is 400 in a sitting, and
                // the result is cached per nominee — so this is generous rather than tight,
                // and the cache is what keeps it affordable.
                'calls_per_day'   => 1500,
                'tokens_per_day'  => 3_000_000,
                'timeout'         => 30,
                'untrusted_input' => true,
                'public_content'  => true,
                'data_sent'       => 'What you and the person who nominated you wrote about your '
                    . 'work, plus the titles and descriptions of anything you attached, with '
                    . 'contact details replaced by placeholders. Never your email or phone number.',
                'data_purpose'    => 'To give a judge a map of your entry before they read it — '
                    . 'what your case rests on, what is evidenced, and what to look at. It '
                    . 'produces notes, never a score, and it never sees another nominee.',
            ]),
            // Reads the documents a nominee attached as evidence and describes them for a
            // reviewer. Pinned to Gemini by NECESSITY rather than preference: it is the
            // only configured provider that can see a file at all. The default ladder
            // would fall back to Groq, which is text-only and would cheerfully describe a
            // document it never received, so the fallback list is deliberately EMPTY.
            'evidence.analyse' => $c('evidence.analyse', [
                'purpose'         => 'assist',
                'tier'            => self::TIER_REASON,
                'model'           => 'gemini:gemini-3.6-flash',
                // One attempt, no alternates. Not a limitation to be fixed later: a
                // fallback here would be worse than a failure, because a text-only model
                // asked about an attachment it cannot see returns a confident,
                // well-formed, entirely invented description — and it would be stored
                // beside real ones with no way for a reviewer to tell them apart.
                'max_attempts'    => 1,
                'fallbacks'       => [],
                'on_failure'      => self::FAIL_ANNOUNCE,
                'advisory'        => true,
                // A batch of six documents, each answered with a summary, claims, dates
                // and names. The batch size and this ceiling are set together.
                'max_tokens'      => 1600,
                // Deliberately small. Each call carries up to six documents at ~258
                // tokens a page, so a hundred calls is already a large amount of input,
                // and results are cached on content hash — a second look at the same file
                // costs nothing. A runaway loop should stop long before the bill does.
                'calls_per_day'   => 300,
                'tokens_per_day'  => 3_000_000,
                // Long: this uploads files, waits for the provider to finish processing
                // them, and then reasons over several at once.
                'timeout'         => 120,
                'untrusted_input' => true,
                'minimise'        => true,
                'public_content'  => true,
                'data_sent'       => 'The documents you attached to your nomination — certificates, letters, '
                    . 'photographs, reports — and the titles you gave them, with contact details in those '
                    . 'titles replaced by placeholders. The files are held by the provider for up to 48 '
                    . 'hours and then deleted.',
                'data_purpose'    => 'To describe each document for the reviewer reading your entry: what it '
                    . 'is, what it claims, and whether it is legible. It produces notes for a person, never '
                    . 'a score, and it cannot approve or reject anything.',
            ]),
            'integrity.brief' => $c('integrity.brief', [
                'purpose'        => 'assist',
                'tier'           => self::TIER_REASON,
                'model'          => self::primary(self::TIER_REASON),
                'on_failure'     => self::FAIL_ANNOUNCE,
                'advisory'       => true,
                'max_tokens'     => 800,
                'calls_per_day'  => 200,
                'tokens_per_day' => 200_000,
            ]),

            // ── HOW A NAME IS SAID, WORKED OUT ONCE ─────────────────────────
            //
            // The door greets people by name in a Nigerian voice, and Azure reads Yoruba,
            // Igbo and Hausa names by ENGLISH rules: silent finals, long and short vowels,
            // schwa where there should be a pure vowel. An operator could correct them one
            // at a time in Settings and nobody ever did — three hundred names is not an
            // afternoon anybody has. This is the platform doing that work instead.
            //
            // UNTRUSTED, because every name came off a public booking form. The fence is
            // the point: a guest who names themselves with an instruction gets their
            // instruction treated as data, and the worst case is one silly respelling
            // rather than a model doing as it was told by an attendee.
            //
            // MINIMISE OFF, and deliberately. Only first names are sent — no email, no
            // phone, nothing the minimiser exists to strip — and it rewrites things that
            // look like contact details, which is a good way to corrupt a name.
            //
            // TIER_REASON, though this is a small job: it is asked once per name ever, for
            // a few dozen names an evening, and the whole value is knowing that Ngozi is
            // Igbo. A fast model that guesses is worse than no model, because the answer is
            // KEPT and then read aloud to somebody at their own door.
            //
            // ADVISORY, and the fallbacks matter more than usual: with no provider, no
            // budget or no answer, DoorWelcome::suggest() still respells the name offline.
            // Nothing here may become a reason the greeting stops working.
            'door.name_pronounce' => $c('door.name_pronounce', [
                'purpose'         => 'general',
                'tier'            => self::TIER_REASON,
                // ── THE ONE CAPABILITY THAT NAMES ITS OWN PROVIDER ──────────
                //
                // OpenAI, outright, and not the platform's primary. This is a knowledge
                // question — is Ngozi Igbo, and where does the stress fall — rather than a
                // reasoning one, and the answer is KEPT and then read aloud to somebody at
                // their own door. A model that is merely fluent will produce a confident
                // respelling of a name it does not know, which is worse than the offline
                // rule because it is believed.
                //
                // Naming a provider here is a choice about THIS JOB. `primary()` is a
                // preference about the platform, and an operator moving that should not
                // silently move this — which is exactly what reading the setting here
                // would do.
                //
                // Concrete, but resolved from `ai_openai_model` in Settings — so the
                // provider is this capability's decision and the model stays the
                // operator's, without leaving a half-written pin in the registry. Which
                // also means the argument above is only half-bought by this line: the
                // shipped default is the small model, and an operator who wants a bigger
                // one for this reason has the field to say so. The offline rule staying
                // underneath is what makes that safe either way.
                'model'           => 'openai:' . self::modelIdFor('openai'),
                // Behind it, the free-tier providers in the usual order — so a deployment
                // with no OpenAI key still gets names worked out rather than nothing.
                'fallbacks'       => ['gemini:', 'groq:llama-3.3-70b-versatile'],
                // DEGRADE and not ANNOUNCE: the rule answers instead, so there is nothing
                // for an operator to be told about and nothing for them to do.
                'on_failure'      => self::FAIL_DEGRADE,
                'advisory'        => true,
                'max_tokens'      => 700,
                // A batch covers an evening, and an evening happens rarely. This is a
                // ceiling against a loop, not a working budget.
                'calls_per_day'   => 60,
                'tokens_per_day'  => 120_000,
                'timeout'         => 25,
                'untrusted_input' => true,
                'minimise'        => false,
                'public_content'  => false,
                'data_sent'       => 'The FIRST NAMES of guests expected in the next few days, and '
                    . 'nothing else. No surname, no email address, no phone number, no ticket '
                    . 'reference, and nothing that says which event anybody is coming to.',
                'data_purpose'    => 'To work out how each name is pronounced, so the door can greet '
                    . 'people by name in a Nigerian voice instead of reading their name by English '
                    . 'rules. The answer is kept so a name is only ever asked about once.',
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
