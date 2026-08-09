<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The assistant working the ticket queue.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A TICKET DESERVES A SECOND ATTEMPT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Most tickets are opened by somebody who never talked to the assistant at all —
 * they came from the ballot, the checkout, an email, or straight to /support/
 * tickets. Nothing has looked at their problem when the row lands. And a large
 * share of what lands is one thing: a payment that went through and was never
 * credited, which this platform can now FIX in about two seconds without waking
 * anybody up.
 *
 * Leaving that in a queue overnight is the actual failure. Not rudeness — delay.
 * The person refreshes, sees nothing, assumes they were robbed, and tells their
 * friends. The fix existed the whole time.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULES IT WORKS UNDER — ALL ENFORCED HERE, NONE IN A PROMPT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. SAFE ACTIONS ONLY. {@see SAFE_TOOLS} is an allowlist, and the context it is
 *    given is built with no admin rights. The two things it can change —
 *    reclaiming a payment, resending a receipt — are both idempotent, both
 *    decided by the payment gateway rather than by the model, and neither can
 *    move money or send anything to an address the model chose. There is no path
 *    from here to a refund, a deletion, a tally, or an account.
 *
 * 2. NEVER ON AN URGENT TICKET. Fraud, a stolen card, a threat, a lawyer — a
 *    machine answering those first is worse than silence, however good the answer.
 *    {@see SupportTicketService::severity()} already classifies them and they are
 *    skipped outright.
 *
 * 3. NEVER TWICE IN A ROW. It replies only when the newest thing on the thread
 *    came from the member. If the assistant already answered and nobody has
 *    written back, there is nothing new to answer and a second attempt would just
 *    be the same paragraph with a different timestamp.
 *
 * 4. IT ONLY CLOSES WHAT IT ACTUALLY FIXED. Resolution requires a repair tool to
 *    have returned ok — not the model's opinion that it has been helpful. An
 *    answer with no repair behind it is posted and the ticket stays OPEN, because
 *    "here is an explanation" is not the same as "this is dealt with".
 *
 * 5. IT SAYS WHAT IT IS. Every reply is attributed to `Support assistant`, in the
 *    thread and in the email, and every reply ends by telling the person how to
 *    get a human. Support that hides which replies were automated is support that
 *    cannot be trusted about anything else.
 *
 * 6. SILENCE IS ALWAYS AVAILABLE. Every failure mode — no model, no reference,
 *    nothing looked up, an answer that fails grounding — ends in doing nothing and
 *    leaving the ticket for a person. Nothing here degrades into a guess.
 */
final class SupportAutoResolver
{
    /**
     * What the assistant may do on somebody else's behalf, unattended.
     *
     * Narrower than what it may do in a live conversation, and the difference is
     * supervision: in chat a person is reading every sentence and can say "no,
     * that is not my payment". Here nobody is. So the reads that DISCLOSE are
     * dropped — there is no audience to disclose to — and what remains is the
     * work: look up how the platform stands, and repair the thing that broke.
     *
     * `voting_deadlines` qualifies on both counts: it is the published schedule
     * plus two admin settings, with no payer, no member and no amount in it — and
     * it is the difference between "my payment confirmed four minutes after the
     * bell" being answered correctly and being guessed at. That question arrives
     * on tickets more than any other.
     */
    private const SAFE_TOOLS = ['fix_payment', 'resend_receipt', 'site_state',
                                'pricing', 'platform_health', 'help_search',
                                'voting_deadlines', 'help_article'];

    /** Tickets per sweep. A cron tick is not the place to answer two hundred. */
    private const PER_SWEEP = 12;

    /** Do not touch anything older than this — a stale queue needs a person, not a bot. */
    private const MAX_AGE_HOURS = 72;

    public function __construct(
        private readonly ?SupportAnswerer $agent = null,
        private readonly ?SupportTicketService $tickets = null,
    ) {}

    /**
     * Can the queue be worked at all?
     *
     * This used to also require `$this->agent->available()` — an AI provider — and
     * so returned false on its first line for every site without an API key. The
     * effect was that the platform's commonest repairable ticket ("I paid, no
     * votes") waited for a person on exactly the deployments least likely to have
     * one watching, while the two-second fix sat there the whole time.
     *
     * The repair does not involve a model. `fix_payment` asks Paystack and credits
     * the votes; `resend_receipt` re-sends to the address on the order. A model
     * chooses which to call and phrases the outcome, and {@see SupportPlan} plus
     * the tools' own `say` strings do both when there is none.
     *
     * What still holds the line is unchanged and is checked per ticket, not here:
     * consider() will not act unless a plan can actually DO something (rule 1),
     * and worthSending() will not post an answer that repaired nothing and looked
     * nothing up (rule 4). Both are stricter without a model than with one.
     */
    public function available(): bool
    {
        return $this->agent !== null && $this->tickets !== null;
    }

    /**
     * Work the queue. Returns how many tickets were answered.
     *
     * Called from maintenance rather than from the request that opens the ticket.
     * That is deliberate: ticket creation must not sit waiting on two model calls
     * and a gateway round-trip, and the person who just clicked "Open ticket"
     * should get their reference immediately — the answer can follow a minute
     * later, the way it would from a human desk.
     */
    public function sweep(int $limit = self::PER_SWEEP): int
    {
        if (!$this->available()) return 0;

        $done = 0;
        foreach ($this->candidates($limit) as $id) {
            try {
                if ($this->consider((int) $id)) $done++;
            } catch (\Throwable $e) {
                // One bad ticket must not stop the sweep — and it must not stay
                // invisible either.
                error_log('[support] auto-resolve failed on ticket ' . $id . ': ' . $e->getMessage());
            }
        }
        return $done;
    }

    /**
     * Which tickets are waiting on us?
     *
     * Derived from the messages rather than tracked in a column. A flag would
     * need to be set correctly by every path that ever touches a ticket — the
     * member reply, the staff reply, the admin console, this class — and the
     * first one to forget leaves a person waiting on a bot that thinks it has
     * already answered. The thread itself cannot be out of date with itself.
     *
     * @return list<int>
     */
    private function candidates(int $limit): array
    {
        try {
            $rows = DB::table('gates_support_tickets')
                ->where('status', 'open')
                ->where('severity', '!=', 'urgent')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - self::MAX_AGE_HOURS * 3600))
                ->orderByDesc('id')->limit($limit * 4)
                ->get(['id']);
        } catch (\Throwable $e) {
            error_log('[support] auto-resolve could not read the queue: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            if (count($out) >= $limit) break;
            if ($this->awaitingUs((int) $r->id)) $out[] = (int) $r->id;
        }
        return $out;
    }

    /**
     * True when the last word on this thread was the member's.
     *
     * The ticket body itself counts as a member message — a brand-new ticket with
     * no replies at all is the commonest case there is, and it is precisely the
     * one worth answering.
     */
    private function awaitingUs(int $ticketId): bool
    {
        try {
            $last = DB::table('gates_support_messages')->where('ticket_id', $ticketId)
                ->orderByDesc('id')->first(['author_type']);
        } catch (\Throwable) {
            return false;
        }
        return $last === null || (string) $last->author_type === 'member';
    }

    /**
     * Consider one ticket, and answer it if that is the right thing to do.
     *
     * @return bool True if a reply was posted.
     */
    public function consider(int $ticketId): bool
    {
        if (!$this->available()) return false;

        try {
            $t = DB::table('gates_support_tickets')->where('id', $ticketId)->first();
        } catch (\Throwable) { return false; }

        if (!$t || (string) $t->status !== 'open' || (string) $t->severity === 'urgent') return false;
        if (!$this->awaitingUs($ticketId)) return false;

        $question = $this->question($t, $ticketId);
        if (trim($question) === '') return false;

        // The scope is the TICKET's owner, taken from the stored row — never from
        // anything in the text. A ticket that carries no identity gets a guest's
        // scope, which is exactly right: the repair tools work from a reference,
        // and the reads that need an identity simply are not offered.
        $ctx = new SupportContext(
            viewerId:    ($t->user_id ?? null) !== null ? (int) $t->user_id : null,
            viewerEmail: ($t->email ?? null) !== null ? (string) $t->email : null,
            isAdmin:     false,
            search:      new ActivityFeedService(),
        );

        // ── WITHOUT A MODEL, ONLY ACT WHERE THERE IS SOMETHING TO DO ─────────
        //
        // Rule 1 in the class note, applied to the model-free path. With rules
        // doing the planning, a vague ticket plans one step — read a Help Centre
        // article — and that article would come back ok:true, satisfy
        // worthSending(), and be posted to somebody's inbox as though it were an
        // answer. It would also mark the ticket answered on the queue, burying it.
        //
        // So an unattended model-free pass requires a plan that can actually DO
        // something: repair a payment, resend a receipt. Anything vaguer waits for
        // a person, which is the correct outcome and was the outcome before.
        if (!$this->agent->available() && !SupportPlan::canAct($question, $ctx, self::SAFE_TOOLS)) {
            return false;
        }

        $r = $this->agent->ask($question, [], $ctx, self::SAFE_TOOLS, escalate: false);

        $repaired = self::repaired($r['used'] ?? [], $r['results'] ?? []);
        if (!$this->worthSending($r, $repaired)) return false;

        $body = trim((string) $r['reply']) . "\n\n"
              . ($repaired
                  ? 'That should be the whole of it. If anything is still wrong, reply here and a person will pick it up.'
                  : 'I have not been able to settle this myself, so it is with the team as well — '
                  . 'reply here if you have anything to add.');

        return $this->tickets->agentReply($ticketId, $body, $repaired);
    }

    /**
     * What to ask on the member's behalf.
     *
     * The ticket transcript, plus any replies since. Trimmed from the END, not
     * the start: the newest thing said is the thing being answered, and a payment
     * reference is far more often in the latest message than in the opening one.
     */
    private function question(object $t, int $ticketId): string
    {
        $parts = [trim((string) ($t->transcript ?? '')) ?: trim((string) ($t->subject ?? ''))];

        try {
            $since = DB::table('gates_support_messages')->where('ticket_id', $ticketId)
                ->where('author_type', 'member')->orderByDesc('id')->limit(3)
                ->get(['body'])->reverse();
            foreach ($since as $m) $parts[] = trim((string) $m->body);
        } catch (\Throwable) {}

        $all = trim(implode("\n\n", array_filter($parts)));
        return mb_strlen($all) > 4000 ? mb_substr($all, -4000) : $all;
    }

    /** Did a repair tool actually succeed? Not "did the model sound confident". */
    private static function repaired(array $used, array $results): bool
    {
        foreach ($results as $f) {
            if (!in_array($f['tool'] ?? '', ['fix_payment', 'resend_receipt'], true)) continue;
            $d = $f['data'] ?? null;
            if (is_array($d) && ($d['ok'] ?? false) === true) return true;
        }
        return false;
    }

    /**
     * Is this answer worth a member's inbox?
     *
     * The bar is higher than for chat, because chat is asked for and an email is
     * not. A reply that looked nothing up and repaired nothing is a paragraph of
     * sympathy — which a person can write better, with the authority to actually
     * do something about it. Sending it would also mark the ticket as answered on
     * the queue, which is the real harm: it buries the ones still waiting.
     */
    private function worthSending(array $r, bool $repaired): bool
    {
        $reply = trim((string) ($r['reply'] ?? ''));
        if ($reply === '' || mb_strlen($reply) < 40) return false;
        if ($repaired) return true;

        // Nothing repaired: only send when it genuinely looked something up. A
        // read that failed does not count — an answer built on a failed lookup is
        // an answer built on nothing.
        foreach ($r['results'] ?? [] as $f) {
            if (($f['ok'] ?? false) === true) return true;
        }
        return false;
    }
}
