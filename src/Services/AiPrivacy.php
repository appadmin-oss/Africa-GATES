<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * What leaves the platform when a model is called, and what does not.
 *
 * {@see AiGateway} was written with this class named in its docblock as the place
 * the redaction policy would land, and left empty on purpose: the questions of
 * lawful basis under the NDPA 2023, cross-border transfer, and each provider's
 * retention terms need primary sources, and a plausible-sounding guess about
 * Nigerian data protection law would be worse than an admitted gap.
 *
 * Two things here need no such sources, and both were missing:
 *
 *  1. DATA MINIMISATION AS ENGINEERING, NOT COMPLIANCE. A spam classifier does
 *     not need a nominee's phone number to decide whether text is spam. A triage
 *     scorer does not need an email address to judge whether a case is specific.
 *     Removing data the task cannot use is correct whatever the law turns out to
 *     require, so {@see minimise()} does it before the payload leaves the process.
 *
 *  2. A TRUTHFUL DISCLOSURE. The privacy page described hashed emails and
 *     cookies and said nothing at all about sending nomination text to a
 *     third-party model. {@see disclosure()} derives that notice from the
 *     capability registry, so the page cannot drift from what the code does —
 *     adding a capability changes the published notice automatically.
 *
 * SUBSTITUTE, DO NOT DELETE. Contact details are replaced with `[email]`,
 * `[phone]` and `[number]` rather than stripped. That is deliberate: the presence
 * of a contact detail is itself a spam signal ({@see SpamService} scores it), so
 * deleting it silently would remove the evidence for the very classification the
 * call is making. The placeholder keeps the signal and drops the value.
 *
 * WHAT IS DELIBERATELY NOT REDACTED, and why:
 *
 *  • NAMES. A triage capability that cannot see who was nominated, or a merge
 *    detector that cannot compare names, is not a degraded feature — it is a
 *    useless one. Names go out. The disclosure says so in plain words rather
 *    than implying otherwise.
 *  • URLS. The nomination form asks for up to three reference links and the
 *    triage prompt rewards verifiable impact; redacting the evidence would
 *    invert the platform's own instruction to nominators.
 *  • ADMIN-OPERATED CAPABILITIES. An admin searching for a phone number is
 *    acting deliberately on data they already control, and redacting their query
 *    would break the search. Minimisation is therefore DECLARED per capability
 *    ({@see AiCapability::$minimise}) rather than inferred, so the rule is
 *    readable instead of implicit.
 *
 * STILL OPEN, and still stated rather than guessed: the lawful basis for the
 * transfer, whether a nominator can meaningfully consent on a third party's
 * behalf, each provider's retention and training terms, and whether a data
 * processing agreement is required. Those need sources. This class narrows the
 * exposure and publishes the facts; it does not pretend to settle the law.
 */
final class AiPrivacy
{
    public const PLACEHOLDER_EMAIL  = '[email]';
    public const PLACEHOLDER_PHONE  = '[phone]';
    public const PLACEHOLDER_NUMBER = '[number]';

    /**
     * Replace contact identifiers in $text with placeholders.
     *
     * The three rules, in the order they must run:
     *
     *  1. EMAIL first, because an address's local part can contain digit runs a
     *     later rule would otherwise chew into (`ada12345678@example.com`).
     *  2. PHONE — something that looks like a dialable number: a leading `+`,
     *     `00` or `0`, or digits broken by spaces, dots, dashes or brackets.
     *     Requires 9+ digits so a year, a price or an impact figure survives.
     *  3. BARE 10–15 DIGIT RUNS with no phone shape at all — a bank account
     *     (10), BVN (11) or NIN (11) pasted plain. Kept as a SEPARATE rule with
     *     its own placeholder rather than folded into the phone rule, because
     *     the phone pattern is deliberately broad and would otherwise consume
     *     these and tell the model `[phone]` about a bank account.
     *
     * The one known collision is an unformatted figure of a billion or more,
     * which becomes `[number]`. That is rare, it errs toward privacy, and the
     * local heuristics still score the ORIGINAL text — only the payload leaving
     * the process is reduced — so no moderation signal is lost either way.
     *
     * @return array{text:string, removed:array<string,int>} counts per placeholder,
     *         so a caller can record WHAT was minimised without recording the values
     */
    public static function minimise(string $text): array
    {
        $removed = [];

        $apply = static function (string $pattern, string $with, string $label) use (&$text, &$removed): void {
            $n = 0;
            $out = preg_replace_callback($pattern, static function () use ($with, &$n) {
                $n++;
                return $with;
            }, $text);
            // A failed regex (backtrack limit on pathological input) must leave
            // the text UNCHANGED rather than null it into an empty prompt.
            if (is_string($out)) {
                $text = $out;
                if ($n > 0) $removed[$label] = ($removed[$label] ?? 0) + $n;
            }
        };

        $apply('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', self::PLACEHOLDER_EMAIL, 'email');

        // +234 803 123 4567 · 0803-123-4567 · (080) 3123 4567 · 234.803.123.4567
        // Three shapes, all needing 9+ digits: an international prefix, a leading
        // trunk 0, or any run broken by a separator. A bare unseparated run with
        // none of those falls through to the next rule on purpose.
        $apply(
            '/(?<![\w.])(?:'
            . '(?:\+|00)\d[\d\s().\-]{6,18}\d'      // +234…, 00234…
            . '|0\d[\d\s().\-]{6,18}\d'             // 0803…
            . '|\d[\d]*[\s().\-][\d\s().\-]{5,18}\d' // 803 123 4567
            // Trailing guard: no more digits, and not a decimal/version
            // continuation. It must NOT reject a following '.' outright — a
            // number at the end of a sentence has a full stop after it, and an
            // earlier version of this rule silently matched nothing at all
            // because of exactly that.
            . ')(?!\d)(?!\.\d)/',
            self::PLACEHOLDER_PHONE, 'phone'
        );

        // Plain 10–15 digit runs the phone rule deliberately left alone: bank
        // account, BVN, NIN pasted without formatting.
        $apply('/(?<![\d.])\d{10,15}(?!\d)(?!\.\d)/', self::PLACEHOLDER_NUMBER, 'number');

        return ['text' => $text, 'removed' => $removed];
    }

    /**
     * Whether $text still contains anything {@see minimise()} would replace.
     *
     * Exists so a test can assert the property directly — "nothing matching a
     * contact identifier is present in the assembled prompt" — rather than
     * re-implementing the patterns and drifting from them.
     */
    public static function containsContactDetail(string $text): bool
    {
        return self::minimise($text)['removed'] !== [];
    }

    /**
     * The published notice, derived from the capability registry.
     *
     * Grouped by destination provider, because that is the question a privacy
     * notice actually has to answer: who receives this, and what do they get.
     * Only capabilities that process content submitted by the PUBLIC appear —
     * an admin using a drafting assistant on their own copy is not a disclosure
     * about the visitor's data, and listing it would bury the part that is.
     *
     * @return list<array{provider:string, capabilities:list<array{name:string, purpose:string, sends:string, minimised:bool, advisory:bool}>}>
     */
    public static function disclosure(): array
    {
        $byProvider = [];
        foreach (AiCapability::all() as $cap) {
            if (!$cap->publicContent) continue;
            $byProvider[$cap->provider()][] = [
                'name'      => $cap->name,
                'purpose'   => $cap->dataPurpose,
                'sends'     => $cap->dataSent,
                'minimised' => $cap->minimise,
                'advisory'  => $cap->advisory,
            ];
        }
        ksort($byProvider);

        $out = [];
        foreach ($byProvider as $provider => $caps) {
            $out[] = ['provider' => $provider, 'capabilities' => $caps];
        }
        return $out;
    }

    /**
     * True when no public-content capability could actually run right now — the
     * platform-wide switch is off, or nothing is configured.
     *
     * The disclosure is rendered either way. A notice that appears and disappears
     * with a settings toggle is worse than a stable one, because a visitor
     * reading it on a quiet day would be told something untrue about the next.
     * This only lets the page say which state it is currently in.
     */
    public static function currentlyActive(): bool
    {
        foreach (AiCapability::all() as $cap) {
            if ($cap->publicContent && AiGateway::available($cap->name)) return true;
        }
        return false;
    }
}
