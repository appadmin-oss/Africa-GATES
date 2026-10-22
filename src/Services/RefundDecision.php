<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Is a refund owed on this reference? Asked of the GATEWAY, never of the claimant.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROBLEM THIS SOLVES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two failures were happening at once, and they pull in opposite directions:
 *
 *   PEOPLE WHO PAID were being told their payment "was never completed", because
 *   our row said pending and nothing re-asked the bank before the email went out.
 *
 *   PEOPLE WHO DID NOT PAY can start a checkout, abandon it, watch their bank
 *   place a pending authorisation that looks exactly like a charge, and ask for a
 *   refund in good faith. Paying that out is money leaving for nothing — and a
 *   platform that does it once will be asked to do it a thousand times.
 *
 * A system that trusts the claimant fails the second case. A system that trusts
 * its own row fails the first. So this trusts NEITHER: it asks the gateway at the
 * moment of the decision, and it writes down what the gateway said.
 *
 * ── "IT DID NOT GO THROUGH" MUST BE A POSITIVE FINDING ───────────────────────
 *
 * This is the whole design. `NEVER_PAID` is only ever returned when a gateway was
 * reached and answered that there is no successful charge on this reference. When
 * the gateway cannot be reached the answer is `UNVERIFIABLE`, which is a different
 * outcome with a different script, because a confident refusal manufactured out of
 * a network timeout is the worst thing this class could produce.
 *
 * That distinction is also what makes the refusal SAYABLE. Support can write "the
 * payment provider has no completed payment on this reference, checked at 09:14"
 * and stand behind it. They cannot stand behind "our system says it didn't work".
 *
 * ── AND IT IS NOT THE THING THAT MOVES MONEY ─────────────────────────────────
 *
 * This decides. {@see RefundService} issues, and its claim-before-act idempotency
 * is untouched. Keeping the judgement separate from the payout is what lets the
 * judgement be re-run freely — on a support request, on an admin screen, twice by
 * accident — with no risk of paying somebody twice.
 *
 * ── WHY THE PENDING-AUTHORISATION CASE GETS ITS OWN WORDING ──────────────────
 *
 * It is the commonest honest dispute on this platform and it is not a lie on
 * anybody's part: a failed Nigerian card payment routinely leaves a hold against
 * the available balance that looks identical to a settled charge, then reverses in
 * 3–10 working days. Both parties are describing something real. Support that
 * cannot explain this ends up either calling the buyer mistaken or refunding money
 * it never received, and both are avoidable.
 */
final class RefundDecision
{
    /** Every outcome. There is no "other" — an unhandled state is a bug, not a bucket. */
    public const OUTCOMES = [
        'NOT_FOUND',        // no such reference on record
        'NEVER_PAID',       // gateway reached, says no successful charge. NOTHING OWED.
        'UNVERIFIABLE',     // gateway could not be reached. NOT the same as never paid.
        'DELIVERED',        // paid and the votes are on the tally. Nothing owed.
        'DELIVERABLE',      // paid, votes missing, but they can still be minted. Mint instead.
        'OWED',             // paid, votes missing, cannot be minted. Refund justified.
        'IN_FLIGHT',        // a refund is already claimed or settling.
        'ALREADY_REFUNDED', // money already back.
    ];

    public function __construct(private readonly ?PaymentService $payments = null) {}

    /**
     * The verdict for one reference, with its evidence.
     *
     * @param bool $askGateway false to decide from the record alone — used by the
     *        queue view, which would otherwise make one HTTPS call per row and
     *        become a page nobody opens twice.
     *
     * @return array{outcome:string, owed:bool, say:string, ...}
     */
    public function for(string $reference, bool $askGateway = true): array
    {
        $ref = trim($reference);
        if ($ref === '' || mb_strlen($ref) > 120) {
            return $this->verdict('NOT_FOUND', false,
                'That does not look like one of our payment references. Ours begin with AFG-.');
        }

        // Somebody asking for their money back is quoting whatever number they have, and
        // that is usually the gateway's receipt. Resolve it to ours before deciding, so the
        // verdict is about their actual payment rather than about a failed string match.
        $ref = PaymentLookup::canonical($ref, $this->payments);

        try {
            $d = DB::table('gates_donations')->where('payment_ref', $ref)->first();
        } catch (\Throwable) {
            return $this->verdict('UNVERIFIABLE', false, 'The record could not be read just now.');
        }
        if (!$d) {
            return $this->verdict('NOT_FOUND', false,
                'No payment matching that is on record. The reference we sent (it begins with AFG-), the '
                . 'transaction number on their bank or Paystack receipt, or the email address they paid '
                . 'with — any one of those will find it.');
        }

        $base = [
            'reference' => (string) $d->payment_ref,
            'amount'    => (int) ($d->amount_naira ?? 0),
            'votes'     => (int) ($d->bonus_votes ?? 0),
            'status'    => (string) ($d->status ?? ''),
        ];

        // ── already settled one way or the other ─────────────────────────────
        if (($d->refunded_at ?? null) !== null) {
            return $this->verdict('ALREADY_REFUNDED', false,
                'That payment has already been refunded in full. Banks usually take 5–10 working days to '
                . 'show it. There is nothing further owed.', $base);
        }
        if (in_array((string) ($d->refund_state ?? ''), ['requested', 'pending'], true)) {
            return $this->verdict('IN_FLIGHT', false,
                'A refund for that payment is already under way. Nothing more needs to be started — doing it '
                . 'again is how somebody gets paid back twice.', $base);
        }

        // ── ALREADY DELIVERED? ASK THAT FIRST ────────────────────────────────
        //
        // The vote rows are on OUR tally. Nothing a gateway could say changes the
        // fact that this supporter has what they paid for, so requiring a gateway
        // answer first was simply wrong — and it failed in the most damaging
        // direction available. Reproduced on MySQL: a fully delivered order with
        // an unreachable provider came back UNVERIFIABLE, so the one screen a
        // person checks before answering a complaint said "I cannot say either
        // way" about an order that was demonstrably fine. That is the answer that
        // dents trust, because it reads as a platform that cannot account for its
        // own money.
        //
        // Safe in the direction that matters: this branch can only ever conclude
        // NOTHING IS OWED. It cannot cause a payout, so moving it ahead of the
        // gateway weakens no fraud guard — the guard exists to stop paying out on
        // an unverified CLAIM, and this is a verified finding of the opposite.
        // Deliberately `>= ordered`, so a partial delivery falls through to the
        // gateway and is treated as still open.
        $proof = VoteProof::forReference($ref);
        if (!empty($proof['found']) && (int) $proof['delivered'] > 0
            && (int) $proof['delivered'] >= (int) $proof['ordered']) {
            return $this->verdict('DELIVERED', false,
                'The votes are on the tally — ' . (int) $proof['delivered'] . ' of them. Nothing is owed. '
                . 'Send them /vote/verify?ref=' . rawurlencode($ref) . ' so they can see the individual '
                . 'records rather than taking our word for it.',
                $base + ['delivered' => (int) $proof['delivered']]);
        }

        // ── did money actually arrive? ───────────────────────────────────────
        //
        // Asked of the gateway even when our row already says confirmed, because
        // the expensive mistake runs in BOTH directions and our row is the thing
        // that was wrong last time.
        $ev = $askGateway ? $this->askGateway($d) : $this->lastKnownEvidence($d);
        $base['evidence'] = $ev;

        if ($ev['verdict'] === 'unreachable') {
            return $this->verdict('UNVERIFIABLE', false,
                'The payment provider could not be reached, so I cannot say either way yet. Do NOT tell them '
                . 'the payment failed — an unanswered check is not a finding. Try again shortly.', $base);
        }

        if ($ev['verdict'] !== 'success') {
            // THE POSITIVE FINDING. A gateway was reached and said no.
            return $this->verdict('NEVER_PAID', false, $this->neverPaidScript($ev), $base);
        }


        // Paid, votes missing. Can they still be minted? Minting beats refunding
        // every time — it is what the buyer actually paid for.
        if ($this->stillDeliverable($d)) {
            return $this->verdict('DELIVERABLE', false,
                'Paid, and the votes are missing, but they CAN still be delivered — voting in that category '
                . 'has not run out of road. Mint them rather than refunding: it is what they paid for. Run '
                . 'the repair, or use Vote Delivery in the admin.', $base);
        }

        return $this->verdict('OWED', true,
            'Paid, no votes on the tally, and they can no longer be delivered — the cycle has closed. A '
            . 'refund of ₦' . number_format((int) $d->amount_naira) . ' is genuinely owed. This is the one '
            . 'case the platform refunds by itself; it does not need anybody to ask.', $base);
    }

    /**
     * The sentence support has to be able to stand behind.
     *
     * The pending-authorisation explanation is included EVERY time rather than
     * only when somebody argues, because they will argue, and because the
     * explanation is the difference between "you are mistaken about your own bank"
     * and "both of these things are true at once".
     */
    private function neverPaidScript(array $ev): string
    {
        $when = $ev['checked_at'] ?? 'just now';
        return 'The payment provider has NO completed payment on this reference — asked and answered at '
             . $when . ($ev['gateway_status'] !== '' ? ' (it reports "' . $ev['gateway_status'] . '")' : '')
             . '. Nothing settled to us, so there is nothing to refund; sending money here would be sending '
             . 'money we were never paid.'
             . "\n\n"
             . 'SAY THIS NEXT, without being asked. A failed card payment routinely leaves a PENDING '
             . 'AUTHORISATION with Nigerian banks — the money leaves their available balance and looks '
             . 'exactly like a charge, then reverses itself in 3–10 working days. They are not wrong about '
             . 'what they saw. Ask them to check their AVAILABLE balance rather than the statement, and to '
             . 'send a screenshot with the date and amount if it is still showing after 5 working days, and '
             . 'commit to taking it up with the provider directly at that point. Never imply they are lying.';
    }

    /**
     * Ask the gateway, and write down what it said.
     *
     * The provider recorded on the order is asked first; only an order taken before
     * that column existed causes every gateway to be tried. A miss at the wrong
     * gateway is not evidence of anything, which is why `unreachable` is
     * distinguished from `failed` all the way through.
     *
     * @return array{verdict:string, gateway_status:string, amount:int, currency:string,
     *                provider:string, checked_at:string}
     */
    private function askGateway(object $d): array
    {
        $svc = $this->payments;
        if ($svc === null) return $this->lastKnownEvidence($d);

        $enabled  = $svc->enabledProviderIds();
        $recorded = strtolower(trim((string) ($d->provider ?? '')));
        $order    = ($recorded !== '' && in_array($recorded, $enabled, true))
            ? array_merge([$recorded], array_values(array_diff($enabled, [$recorded])))
            : $enabled;

        $anyAnswered = false;
        $best = ['verdict' => 'unreachable', 'gateway_status' => '', 'amount' => 0,
                 'currency' => '', 'provider' => ''];

        foreach ($order as $p) {
            $v = $svc->verify($p, (string) $d->payment_ref);
            if (!($v['ok'] ?? false)) continue;   // could not get an answer from this one
            $anyAnswered = true;

            $status = (string) ($v['status'] ?? '');
            if ($status === 'success') {
                $best = ['verdict' => 'success', 'gateway_status' => 'success',
                         'amount' => (int) ($v['amount'] ?? 0), 'currency' => (string) ($v['currency'] ?? ''),
                         'provider' => $p];
                break;   // a success anywhere is the answer; stop asking
            }
            // Keep the most informative non-success answer. `failed` is a real
            // finding; `pending` means it may yet complete and must not be
            // reported as a failure.
            if ($best['verdict'] === 'unreachable' || $status === 'failed') {
                $best = ['verdict' => $status !== '' ? $status : 'failed', 'gateway_status' => $status,
                         'amount' => (int) ($v['amount'] ?? 0), 'currency' => (string) ($v['currency'] ?? ''),
                         'provider' => $p];
            }
        }

        // Nobody answered at all. NOT a failure — an unknown.
        if (!$anyAnswered) $best['verdict'] = 'unreachable';

        $best['checked_at'] = Carbon::now()->format('Y-m-d H:i');
        $this->recordEvidence((int) $d->id, $best);
        return $best;
    }

    /**
     * The last recorded answer, for views that must not make network calls.
     *
     * A stale verdict is labelled as stale rather than presented as current: the
     * queue can say "as of yesterday 14:00" honestly, and the person about to
     * refuse somebody's refund re-checks before they write the message.
     */
    private function lastKnownEvidence(object $d): array
    {
        $raw = (string) ($d->gateway_evidence ?? '');
        $ev  = $raw !== '' ? (json_decode($raw, true) ?: []) : [];

        return [
            'verdict'        => (string) ($d->gateway_verdict ?? 'unreachable'),
            'gateway_status' => (string) ($ev['gateway_status'] ?? ''),
            'amount'         => (int) ($ev['amount'] ?? 0),
            'currency'       => (string) ($ev['currency'] ?? ''),
            'provider'       => (string) ($ev['provider'] ?? ''),
            'checked_at'     => (string) ($d->gateway_checked_at ?? '') !== ''
                ? Carbon::parse((string) $d->gateway_checked_at)->format('Y-m-d H:i')
                : 'never',
            'stale'          => true,
        ];
    }

    /** Persist the answer so the decision is explainable months later. Never throws. */
    private function recordEvidence(int $id, array $ev): void
    {
        try {
            DB::table('gates_donations')->where('id', $id)->update(
                OptionalColumn::filter('gates_donations', [
                    'gateway_checked_at' => Carbon::now()->toDateTimeString(),
                    'gateway_verdict'    => mb_substr((string) $ev['verdict'], 0, 24),
                    'gateway_evidence'   => json_encode($ev, JSON_UNESCAPED_SLASHES),
                ], ['gateway_checked_at', 'gateway_verdict', 'gateway_evidence'])
            );
        } catch (\Throwable $e) {
            // Losing the evidence must not lose the decision — the caller still
            // gets a correct verdict, it is simply less defensible later.
            error_log('[refund-decision] could not record evidence for ' . $id . ': ' . $e->getMessage());
        }
    }

    /**
     * Could the votes still be minted?
     *
     * Deliberately the same two questions {@see RefundService::terminallyUnminted()}
     * asks, in the same order, because a refund screen that disagreed with the
     * refund sweep about what is deliverable would be worse than having neither.
     */
    private function stillDeliverable(object $d): bool
    {
        $nomineeId = (int) ($d->intent_nominee_id ?? 0);
        if ($nomineeId < 1) return false;

        try {
            $catId = (int) (DB::table('gates_nominees')->where('id', $nomineeId)->value('category_id') ?? 0);
            if ($catId < 1) return false;
            if (PaidVoteService::votingOpenFor($catId)) return true;

            $close = BallotGuard::votingCloseFor($catId);
            if ($close !== null) {
                $ends = $close->copy()->addHours(PaidVoteService::lateMintGraceHours());
                if (Carbon::now()->lt($ends)
                    && Carbon::parse((string) $d->created_at)->lt($close)) return true;
            }
            return false;
        } catch (\Throwable) {
            // Unknown is never a reason to refund. It is a reason to ask a person.
            return false;
        }
    }

    /** @return array{outcome:string, owed:bool, say:string} */
    private function verdict(string $outcome, bool $owed, string $say, array $extra = []): array
    {
        return ['outcome' => $outcome, 'owed' => $owed, 'say' => $say] + $extra;
    }

    // ── the queue ────────────────────────────────────────────────────────────

    /**
     * Everything a person may need to act on, in one list.
     *
     * ── WHY THIS IS NOT JUST "WHAT THE SWEEP WILL TAKE" ──────────────────────
     *
     * {@see RefundService} handles one narrow case automatically and deliberately
     * refuses the rest: over the per-order ceiling, out of retries, or a refusal it
     * classified as permanent. Every one of those was then left in a state that
     * appeared nowhere a person looks — which is how "left for a human" became
     * "left for a human who was never told".
     *
     * This is that list. It reads the RECORD rather than the gateway, so opening the
     * page costs nothing; the per-row verdict is re-checked against the gateway
     * only when somebody is about to act on it.
     *
     * @return list<array<string,mixed>>
     */
    public static function queue(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $out   = [];

        try {
            $rows = DB::table('gates_donations')
                ->where('tier', 'paid-vote')
                ->where('status', 'confirmed')
                ->whereNull('refunded_at')
                ->where(function ($w) {
                    // Owed but untouched, OR parked by the sweep for a person.
                    $w->where(function ($x) {
                        $x->where('votes_used', 0)->whereNull('refund_requested_at');
                    })->orWhereIn('refund_state', ['manual', 'exhausted', 'failed']);
                })
                ->orderByDesc('id')->limit($limit)->get();
        } catch (\Throwable $e) {
            error_log('[refund-decision] queue read failed: ' . $e->getMessage());
            return [];
        }

        foreach ($rows as $d) {
            $out[] = [
                'reference'  => (string) $d->payment_ref,
                'amount'     => (int) ($d->amount_naira ?? 0),
                'votes'      => (int) ($d->bonus_votes ?? 0),
                'created_at' => (string) ($d->created_at ?? ''),
                'state'      => (string) ($d->refund_state ?? ''),
                'attempts'   => (int) ($d->refund_attempts ?? 0),
                'reason'     => (string) ($d->refund_reason ?? ''),
                // Last recorded gateway answer, labelled with its age. Never
                // presented as current — the act-on-it path re-checks.
                'gateway'    => (string) ($d->gateway_verdict ?? ''),
                'checked_at' => (string) ($d->gateway_checked_at ?? ''),
                // Why the automatic path is not handling it, in words.
                'why_manual' => match ((string) ($d->refund_state ?? '')) {
                    'manual'    => 'over the per-order ceiling',
                    'exhausted' => 'the gateway refused it until we stopped trying',
                    'failed'    => 'refused once; a retry is scheduled',
                    default     => 'waiting for the automatic sweep',
                },
            ];
        }
        return $out;
    }
}
