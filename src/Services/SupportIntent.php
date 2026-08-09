<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Is this person browsing, or are they stuck?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Gee sits on every page of the site and, until now, answered a payment problem
 * the way a signpost answers one: "for account, payment, appeal or moderation
 * issues, point to /support". That instruction is in Gee's own prompt, and it is
 * the worst possible answer to give somebody whose money has vanished — it asks
 * them to start again, in a different place, having already explained
 * themselves once.
 *
 * Meanwhile the support desk — which can re-check their payment against the
 * gateway and credit the votes inside ten seconds — sat behind a link most of
 * them never followed. The tools were built and the people who needed them were
 * being redirected away from them.
 *
 * So the guide now hands a stuck person to the support brain directly, in the
 * same conversation. This class decides when.
 *
 * ── PRECISION OVER RECALL, DELIBERATELY ──────────────────────────────────────
 *
 * The two mistakes are not symmetrical, so this is tuned hard toward one of them.
 *
 *   FALSE NEGATIVE (a real problem read as browsing) — Gee answers as a guide and
 *   points at /support, which is exactly what it did before this class existed.
 *   The person is no worse off than yesterday, and they usually rephrase, and the
 *   second phrasing is nearly always more explicit than the first.
 *
 *   FALSE POSITIVE (browsing read as a problem) — the support agent answers a
 *   question about the CPI. It is a narrower brain: it reasons about
 *   transactions, it opens tickets, and it can turn "how does voting work" into
 *   a support case nobody asked for. It also costs a tool loop and several model
 *   calls to give a worse answer than Gee would have given free.
 *
 * Hence: a support signal has to be POSITIVELY present. Nothing routes on the
 * bare word "vote", "payment" or "help" — those are the most common words on the
 * platform and they appear in ordinary curiosity far more often than in trouble.
 *
 * ── NO MODEL HERE, ON PURPOSE ────────────────────────────────────────────────
 *
 * Classifying with a model would read better and would also mean an extra call
 * before every single Gee message, a new failure mode in front of the widget on
 * every page, and a routing decision an attacker can talk to. String matching is
 * dull, free, instant, and cannot be argued with.
 */
final class SupportIntent
{
    /**
     * A payment reference. Nobody types one of these to make conversation — it is
     * the single strongest signal in the whole heuristic, and on its own enough.
     */
    private const REFERENCE = '/\bAFG-[A-Za-z0-9]{2,}[A-Za-z0-9-]{2,}/i';

    /**
     * Phrases that only occur when something has gone wrong.
     *
     * Every one is a complaint, not a topic. "refund" is here; "payment" is not.
     * "did not arrive" is here; "arrive" is not.
     */
    private const TROUBLE = [
        // money
        'refund', 'refunded', 'my money', 'money back', 'charged twice', 'double charge',
        'charged me', 'debited', 'deducted', 'took my money', 'paid but', 'already paid',
        'i have paid', "i've paid", 'payment failed', 'failed payment', 'not credited',
        'no receipt', 'receipt did not', 'chargeback', 'overcharged',
        // votes and nominations that did not happen
        'not showing', 'not show', 'did not show', 'not appear', 'did not appear',
        'have not appeared', 'not reflecting', 'not reflected', 'missing', 'disappeared',
        'not counted', 'did not count', 'not added', 'votes are gone', 'lost my vote',
        'not minted', 'no votes', 'zero votes',
        // codes and access
        'did not receive', 'not received', 'never received', 'did not get', "didn't get",
        'did not come', 'no code', 'code not', 'cannot log in', "can't log in",
        'cannot login', "can't login", 'cannot sign in', "can't sign in", 'locked out',
        'reset my password', 'forgot my password', 'account was', 'hacked',
        // the shape of a complaint
        'not working', 'does not work', "doesn't work", 'is broken', 'stuck', 'stopped working',
        'error', 'failed', 'wrong', 'complaint', 'complain', 'appeal', 'dispute',
        'speak to someone', 'talk to someone', 'talk to a human', 'speak to a human',
        'real person', 'customer care', 'customer service', 'raise a ticket', 'open a ticket',
        'my ticket', 'no one has replied', 'nobody replied', 'still waiting',
    ];

    /**
     * First-person possession of a thing that can go wrong.
     *
     * On its own this is not trouble — "where are my votes" is ambiguous and
     * "how do I see my votes" is browsing — so a hit here is only a signal when a
     * NEGATIVE word appears with it. That pairing is what separates "my payment
     * has not gone through" from "how do I make my payment".
     */
    private const MINE = [
        'my payment', 'my order', 'my vote', 'my votes', 'my receipt', 'my account',
        'my nomination', 'my profile', 'my ticket', 'my card', 'my transaction',
        'i paid', 'i bought', 'i purchased', 'i voted', 'i nominated', 'i donated',
        // 'otp' sits here rather than in TROUBLE so that "why do I need an OTP?" —
        // one of Gee's own suggested questions on the vote page — stays a guide
        // question, while "my OTP has not arrived" becomes a support one.
        'otp', 'verification code', 'confirmation code',
    ];

    private const NEGATIVE = [
        ' not ', "n't", ' no ', 'never', 'yet', 'still', 'cannot', "can't", 'unable',
        'where is', 'where are', 'why is', 'why has', 'why did', 'nothing', 'without',
        'problem', 'issue', 'help me',
    ];

    /**
     * Words that mean somebody is looking around, not stuck.
     *
     * Used only to break the stickiness below. They are NOT consulted when a
     * trouble phrase is present: "the shop charged me twice" contains "shop" and
     * is unambiguously a support problem.
     */
    private const BROWSING = [
        'cpi', 'how does', 'how do i nominate', 'what is', 'who is', 'tell me about',
        'leaderboard', 'shop', 'merch', 'partner', 'sponsor', 'events', 'gala',
        'community', 'jury', 'judges', 'criteria', 'donate', 'programme', 'program',
        'nominate someone', 'register',
    ];

    /**
     * Should the support brain answer this?
     *
     * @param list<array{role?:string,text?:string,content?:string}> $history prior turns
     */
    public static function looksLikeSupport(string $message, array $history = []): bool
    {
        $m = self::norm($message);
        if ($m === '') return false;

        if (preg_match(self::REFERENCE, $message) === 1) return true;
        if (self::isTrouble($message)) return true;

        // ── STICKINESS ───────────────────────────────────────────────────────
        //
        // A support conversation is not one message. Once somebody has said "I
        // paid and got nothing", their next turn is "AFG-4c1…", then "yes", then
        // "how long will that take?" — and not one of those, read alone, looks
        // like a support message at all.
        //
        // Bouncing those back to the guide loses the thread mid-repair and makes
        // the assistant look like it has forgotten what it was doing thirty
        // seconds ago, which is the specific failure people describe as "the bot
        // is useless". So once the conversation has turned into support, it stays
        // there until the person visibly changes the subject.
        if (self::wasSupport($history) && !self::any($m, self::BROWSING)) return true;

        return false;
    }

    /**
     * Is something wrong, in this person's own words?
     *
     * The trouble half of {@see looksLikeSupport()}, without the stickiness — "has
     * this message got a complaint in it", asked of one message on its own.
     *
     * Extracted because {@see looksLikeSupport()} and {@see wasSupport()} both had
     * their own copy of the same three checks, and a vocabulary tuned this hard
     * toward precision is worth nothing if half of it can drift out of the other
     * half. Public so the same judgement is available to anything that needs it
     * without a second list being written.
     */
    public static function isTrouble(string $message): bool
    {
        $m = self::norm($message);
        if ($m === ' ' || $m === '') return false;
        if (self::any($m, self::TROUBLE)) return true;
        return self::any($m, self::MINE) && self::any($m, self::NEGATIVE);
    }

    /**
     * Did an earlier USER turn in this conversation look like support?
     *
     * Only user turns count. The assistant's own words are full of trouble
     * vocabulary — it says "refund" and "not credited" while explaining a
     * process — and letting its replies vote would make one mention of the word
     * "refund" pin the rest of the conversation to the support agent.
     *
     * @param list<array<string,mixed>> $history
     */
    private static function wasSupport(array $history): bool
    {
        foreach (array_slice($history, -6) as $h) {
            if (!is_array($h)) continue;
            if (($h['role'] ?? 'user') !== 'user') continue;
            $text = (string) ($h['content'] ?? $h['text'] ?? '');
            if ($text === '') continue;
            if (preg_match(self::REFERENCE, $text) === 1 || self::isTrouble($text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lower-cased, punctuation flattened to spaces, and PADDED with a space at
     * each end.
     *
     * The padding is what lets a needle be written as ' no ' and still match a
     * message that begins "no code came" — without it, the leading-space needles
     * silently never fire on the first word, which is where people put them.
     */
    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // Apostrophes are kept: "didn't" and "can't" are needles above, and the
        // curly apostrophe is what a phone keyboard actually produces.
        $s = str_replace(['’', '‘', '`'], "'", $s);
        $s = (string) preg_replace('/[^a-z0-9\']+/u', ' ', $s);
        return ' ' . trim((string) preg_replace('/\s+/', ' ', $s)) . ' ';
    }

    /** @param list<string> $needles */
    private static function any(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) return true;
        }
        return false;
    }
}
