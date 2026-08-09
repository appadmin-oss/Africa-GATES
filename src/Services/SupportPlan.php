<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Which tools to run, decided without a model.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS: THE ASSISTANT WAS ONE API KEY AWAY FROM DOING NOTHING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The support assistant has twenty-four working tools. It can re-check a payment
 * against Paystack and credit the votes in about two seconds, resend a receipt,
 * prove which vote rows exist, read the live deadlines, and tell somebody whether
 * the gateway is up. All of that is deterministic code with no model in it.
 *
 * And every single one of them sat behind `SupportAgentService::available()`,
 * which is true only when an AI provider is configured and reachable. So:
 *
 *   • With no AI key, a live chat answered a Help-Centre article or an apology,
 *     and — worse — {@see SupportAgentService::plain()} returns
 *     `escalated: false, ticket: null`, so somebody who wrote "I paid and got
 *     nothing, let me speak to a human" got an apology and NO TICKET. Their
 *     message was read by nobody, ever.
 *
 *   • The unattended queue in {@see SupportAutoResolver} returned 0 from its
 *     first line. Every repairable payment ticket waited for a person.
 *
 *   • And the case that actually bites in production is not "no key" but a key
 *     that is configured and failing — an expired token, a spent free quota, a
 *     network fault. The planner is itself a model call, so when the provider is
 *     down there is no plan, therefore no tools, therefore no facts, and the
 *     whole apparatus produces "I could not put an answer together."
 *
 * The model was never the part that knew anything. It chooses which tool to run
 * and then phrases the result. Choosing is a decision a table of rules makes
 * perfectly well for the questions people actually ask, and the phrasing already
 * exists: every tool writes a `say` string in plain English precisely so it can
 * be relayed. {@see SupportAgentService::fromFactsAlone()} joins those.
 *
 * So this class is the planner half of a complete, model-free support turn.
 *
 * ── PRECISION, NOT COVERAGE ──────────────────────────────────────────────────
 *
 * It does not try to route everything; it routes what it can name with
 * confidence and returns [] otherwise, which leaves the Help-Centre floor to
 * answer. A wrong tool is worse than no tool: `fix_payment` on a reference
 * lifted out of an unrelated sentence spends the repair rate limit and answers a
 * question nobody asked.
 *
 * ── ENTITLEMENT IS NOT DECIDED HERE ──────────────────────────────────────────
 *
 * Every step is filtered against the caller's own tool list, and
 * {@see SupportContext::run()} re-checks it regardless. That double check is what
 * lets {@see SupportAutoResolver} pass a narrow allowlist and rely on it.
 */
final class SupportPlan
{
    /** Tool calls per turn. Enough for repair-then-prove; short enough to stay fast. */
    private const MAX_STEPS = 3;

    /**
     * One of our references, loose enough to catch a real one inside a sentence.
     *
     * Deliberately looser than {@see SupportContext::shapeOf()}, which validates.
     * This only has to FIND the candidate; the tools decide whether it resolves.
     */
    private const OURS = '/\bAFG-[A-Za-z0-9]{2,}(?:-[A-Za-z0-9]{4,})?/i';

    /**
     * A gateway's own number, which is what most people actually have.
     *
     * A bare run of 8+ digits, or a provider-style token. Both are now findable —
     * {@see PaymentLookup} resolves them to our reference — so quoting one is no
     * longer a dead end, and a planner that ignored them would be throwing away
     * the identifier the supporter is most likely to be holding.
     *
     * Three details, each of which was wrong first time and caught by a test:
     *
     *   · The token branch comes FIRST. With the digit branch first, scanning
     *     left-to-right pulled `6413965117` out of the middle of
     *     `paystack_6413965117_hw8rf` and threw the rest away.
     *   · MULTIPLE separators. Real gateway tokens have two or three
     *     (`paystack_6413965117_hw8rf`), and a pattern allowing one matched none
     *     of them.
     *   · A DIGIT is required somewhere in the token, or `double-charged` and
     *     `pre-order` are read as payment references.
     */
    private const THEIRS = '/\b((?=[a-z0-9_-]*\d)[a-z]{3,12}(?:[_-][A-Za-z0-9]+){1,4})\b'
                         . '|(?<![\w.])(\d{8,20})(?![\w.])/i';

    private const EMAIL = '/\b[\w.+-]+@[\w-]+\.[\w.-]{2,}\b/';

    /**
     * Build a plan for one message.
     *
     * @param list<string> $only restrict to these tools; [] means whatever the context allows
     * @return list<array{tool:string, args:array<string,mixed>, why:string}>
     */
    public static function steps(string $message, SupportContext $ctx, array $only = []): array
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') return [];

        $ref   = self::reference($message);
        $email = self::email($message);
        $steps = [];

        $add = static function (string $tool, array $args, string $why) use (&$steps): void {
            foreach ($steps as $s) { if ($s['tool'] === $tool) return; }   // once each
            $steps[] = ['tool' => $tool, 'args' => $args, 'why' => $why];
        };

        // ── 1 · an outage answers a hundred people at once ───────────────────
        //
        // First, and before asking anybody for a reference. During a provider
        // outage every buyer arrives with the same sentence, and "what is your
        // reference" is the wrong answer given a hundred times.
        if (self::any($m, ['payment failed', 'failed payment', 'could not pay', "couldn't pay",
                           'cannot pay', "can't pay", 'checkout', 'declined', 'card was declined',
                           'gateway', 'paystack', 'flutterwave', 'page would not load',
                           'threw me out', 'timed out', 'transaction failed'])) {
            $add('gateway_status', [], 'they say paying itself failed');
        }

        // ── 2 · a reference in hand is the strongest signal there is ─────────
        //
        // Nobody types a payment reference to make conversation. If one is present
        // together with any complaint, the repair is the answer — and it is
        // idempotent, so running it when the payment was fine costs nothing but a
        // confirmation that it was fine.
        if ($ref !== null) {
            if (self::any($m, ['receipt', 'invoice', 'no email', 'email never', 'proof of payment'])
                && !self::any($m, ['no votes', 'not showing', 'not appear', 'not reflect', 'missing'])) {
                // Votes are there, the email is not. Nothing to repair.
                $add('resend_receipt', ['reference' => $ref], 'they have the votes but not the receipt');
            }
            if (self::any($m, ['refund', 'money back', 'my money', 'reverse', 'chargeback'])) {
                $add('refund_status', ['reference' => $ref], 'they asked about a refund');
            }
            // ── A REFERENCE IS ITS OWN SIGNAL ────────────────────────────────
            //
            // This was gated on the trouble vocabulary as well, and the diagnostic
            // page caught it on the first real sentence: "my debit alert says
            // 4738291042 and nothing came" planned a Help Centre article, because
            // "nothing came" is not one of SupportIntent's phrases. Extending that
            // list is the wrong repair — it is tuned for a different job, deciding
            // whether Gee should hand a conversation over at all, and every phrase
            // added there loosens that too.
            //
            // SupportIntent's own note has the right rule: a payment reference is
            // "the single strongest signal in the whole heuristic, and on its own
            // enough". Nobody types one to make conversation. And the repair is
            // idempotent — run against a payment that was fine, it replies that the
            // payment was fine, which is a useful answer to the person who asked.
            $add('fix_payment', ['reference' => $ref], 'they quoted a payment reference');
            // Proof, not reassurance. vote_proof reads the live vote ROWS, so it
            // can contradict us, and it returns a URL they can open themselves.
            $add('vote_proof', ['reference' => $ref], 'so they can check it themselves');
        }

        // ── 3 · a code or email that never arrived ───────────────────────────
        //
        // Far more often a dead domain or a typo (gmial.com) than a spam folder,
        // and "check your spam folder" is useless advice for either.
        if (self::any($m, ['code', 'otp', 'verification', 'link', 'email'])
            && self::any($m, ['did not receive', 'not received', 'never received', 'did not get',
                              "didn't get", 'never came', 'not arrive', 'did not arrive',
                              'no email', 'never arrived'])) {
            if ($email !== null) {
                $add('check_email_domain', ['email' => $email], 'a named address that received nothing');
            } else {
                $add('platform_health', [], 'mail is reported not arriving');
            }
        }

        // ── 4 · a FREE vote, which has no payment and no reference at all ────
        //
        // Most votes on this platform are free. Answering one of those with a
        // payment tool asks somebody for a reference that never existed.
        if ($ref === null
            && self::any($m, ['not showing', 'not show', 'did not show', 'not appear', 'not reflect',
                              'not reflecting', 'not counted', 'did not count', 'not added'])
            && !self::any($m, ['paid', 'payment', 'bought', 'purchase', 'charged', 'debited', 'card'])) {
            $add('free_vote_help', [], 'a vote not showing, with no payment mentioned');
            if ($email !== null) {
                $add('when_did_i_vote', ['email' => $email], 'to see whether the vote exists at all');
            }
        }

        // ── 5 · the three clocks ─────────────────────────────────────────────
        //
        // "Why can't I pay when voting is still open" has a real answer: card
        // payment stops EARLIER than voting does. Guessing at it is what makes a
        // supporter feel cheated.
        if (self::any($m, ['deadline', 'closing', 'closes', 'close', 'still open', 'too late',
                           'in time', 'cut off', 'cut-off', 'last day', 'when does', 'until when',
                           'expired', 'ended'])) {
            $add('voting_deadlines', [], 'a question about the clock');
        }

        // ── 6 · what it costs ────────────────────────────────────────────────
        if (self::any($m, ['how much', 'price', 'cost', 'naira', 'per vote', 'bundle', 'cheap',
                           'expensive', 'fee'])) {
            $add('pricing', [], 'a question about price');
        }

        // ── 7 · a nominee by name ────────────────────────────────────────────
        //
        // Only from an explicit "vote for X" shape. Guessing a name out of free
        // prose finds the wrong nominee, and sending somebody to the wrong ballot
        // is worse than sending them to search.
        if (($name = self::nominee($message)) !== null) {
            $add('find_nominee', ['name' => $name], 'they named who they want to vote for');
        }

        // ── 8 · "is it actually fixed?" ──────────────────────────────────────
        //
        // Counts real vote rows rather than our own counter, so it can and does
        // contradict us. Claiming resolution while it says otherwise is the
        // fastest way to lose somebody for good.
        if (self::any($m, ['is it fixed', 'still broken', 'you said it was fixed', 'was told',
                           'resolved', 'incident', 'outage', 'everyone', 'other people'])) {
            $add('delivery_health', [], 'they are asking whether the problem itself is over');
        }

        // ── 9 · the written answer, which is vetted and kept correct ─────────
        //
        // Always worth one call for anything shaped like a question, and last so
        // it never displaces a repair. help_article carries a URL the reader keeps.
        if ($steps === [] || self::any($m, ['how do', 'how does', 'how can', 'what is', 'what are',
                                            'why does', 'why did', 'why is', 'can i', 'do i',
                                            'where do', 'where is', '?'])) {
            $add('help_article', ['query' => mb_substr(trim($message), 0, 200)], 'a written answer may exist');
        }

        // ── entitlement and scope ────────────────────────────────────────────
        //
        // Not a courtesy: `only` is how the unattended resolver is confined to safe
        // tools, and a plan that ignored it would hand SupportAutoResolver a step
        // it is not allowed to take.
        $allowed = array_column($ctx->tools(), 'name');
        $steps = array_values(array_filter($steps, static fn(array $s): bool =>
            in_array($s['tool'], $allowed, true)
            && ($only === [] || in_array($s['tool'], $only, true))));

        return array_slice($steps, 0, self::MAX_STEPS);
    }

    /**
     * Is there anything here this can act on beyond reading an article?
     *
     * The unattended resolver uses this to decide whether a model-free pass is
     * worth making at all: posting "here is a Help Centre link" onto somebody's
     * ticket unprompted is noise, whereas repairing their payment is the job.
     */
    public static function canAct(string $message, SupportContext $ctx, array $only = []): bool
    {
        foreach (self::steps($message, $ctx, $only) as $s) {
            if ($s['tool'] !== 'help_article') return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pulling the identifiers out of a sentence
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The payment reference in this message, ours or the gateway's.
     *
     * Ours wins when both are present, because somebody who has both is quoting
     * ours deliberately and theirs incidentally ("AFG-PVOTE-… , debit alert said
     * 4738291042"). Trailing punctuation is trimmed: a reference at the end of a
     * sentence arrives with a full stop attached far more often than not.
     */
    public static function reference(string $message): ?string
    {
        if (preg_match(self::OURS, $message, $m)) {
            return rtrim($m[0], '.,;:)');
        }
        // A bare long number is only a reference if the sentence is about money.
        // Otherwise it is a phone number, an amount, a date or a vote count, and
        // spending the repair rate limit on it helps nobody.
        if (self::aboutMoney(mb_strtolower($message)) && preg_match(self::THEIRS, $message, $m)) {
            $hit = trim(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
            // 11 digits starting 0 is a Nigerian mobile number, not a transaction id.
            if (preg_match('/^0\d{10}$/', $hit)) return null;
            return $hit !== '' ? $hit : null;
        }
        return null;
    }

    public static function email(string $message): ?string
    {
        return preg_match(self::EMAIL, $message, $m) ? mb_strtolower($m[0]) : null;
    }

    /**
     * A nominee named in an explicit "vote for X" shape, or null.
     *
     * Capitalisation is not required — plenty of people type in lower case — but
     * the PHRASE is, and the tail is cut at the first word that starts a new
     * clause, so "vote for Amara but the page is broken" does not search for
     * "Amara but the page is broken".
     */
    public static function nominee(string $message): ?string
    {
        if (!preg_match('/\b(?:vote for|voting for|support|find|nominee)\s+([^?.,!\n]{2,60})/i',
                        $message, $m)) {
            return null;
        }
        $tail = trim($m[1]);
        // Cut at a conjunction or a verb that begins a complaint.
        $tail = preg_split('/\s+\b(?:but|and|because|however|so|then|is|was|has|have|did|does|will|cannot|can|in|on|at|the)\b/i',
                           $tail)[0] ?? $tail;
        $tail = trim($tail, " \t\"'");
        // Two-plus characters and not a pronoun or a category word standing alone.
        if (mb_strlen($tail) < 2 || mb_strlen($tail) > 60) return null;
        if (in_array(mb_strtolower($tail), ['me', 'him', 'her', 'them', 'someone', 'somebody',
                                            'anyone', 'my nominee', 'a nominee', 'this'], true)) {
            return null;
        }
        return $tail;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vocabulary — borrowed, not re-listed
    // ─────────────────────────────────────────────────────────────────────────

    private static function aboutMoney(string $lower): bool
    {
        return self::any($lower, ['paid', 'pay', 'payment', 'charge', 'charged', 'debit', 'debited',
                                  'money', 'transaction', 'receipt', 'refund', 'bought', 'purchase',
                                  'reference', 'ref', 'naira', 'card', 'bank', 'transfer', 'alert']);
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
