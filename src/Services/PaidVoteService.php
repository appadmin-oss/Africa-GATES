<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use AfricaGates\Services\CacheService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Paid voting (admin-toggleable business model, OFF by default).
 *
 * When a superadmin enables it, supporters buy votes for a nominee: an order
 * rides the EXISTING audited payment rails (gates_donations row, tier
 * 'paid-vote', gateway init → idempotent confirm), and a confirmed payment
 * auto-MINTS the votes for the nominee the order was created for
 * (intent_nominee_id).
 *
 * Integrity model (unchanged): paid votes are vote_type='paid' weighted rows
 * that bump the PUBLIC tally (vote_count) only — organic_vote_count, the CPI
 * community signal, is never touched by money. Free OTP voting stays
 * available unless the admin also disables it.
 *
 * Pricing: the admin defines a LADDER of "buy this many, get this much off"
 * ({@see tiers()}). The charge is quantity × per-vote price less the deepest
 * discount the quantity reaches — computed server-side, never from the client.
 * The same ladder supplies the quick-amount chips the ballot renders, so the
 * button a buyer taps and the price they are charged cannot disagree.
 */
class PaidVoteService
{
    public const DEFAULT_PRICE_NAIRA = 100;
    public const DEFAULT_PER_1000    = 10;

    /**
     * Default cap on ONE order. Admin-overridable via {@see maxQty()}.
     *
     * Was a bare `const MAX_QTY = 1000` clamped in two places, which made "can the site
     * take an order of more than a thousand votes?" a code change rather than a setting —
     * and the answer was no, silently: `PaidVoteController` clamped the posted quantity
     * down, so a buyer who asked for 5,000 was charged for 1,000 and told nothing.
     */
    public const DEFAULT_MAX_QTY = 1000;

    /**
     * The ceiling no setting may exceed, because the DATABASE cannot hold more.
     *
     * A paid order mints one `gates_votes` row with `weight = quantity`, and that column
     * is `INT UNSIGNED` (widened from `SMALLINT UNSIGNED` — see
     * `database/migrations/2026_07_30_vote_weight_widen.php`, which is what actually made
     * orders above 65,535 possible at all).
     *
     * Set well below the column's 4,294,967,295 on purpose. This is not a storage limit,
     * it is a blast radius: the number is also what a single mistyped quantity or a single
     * compromised admin setting can add to a public tally in one transaction, and no real
     * order needs eight digits. Ten million is past any plausible campaign and still small
     * enough that the damage from one bad order is bounded and reversible by the existing
     * clawback path.
     */
    public const HARD_MAX_QTY = 10_000_000;

    /**
     * The largest naira total ONE order may come to.
     *
     * A SECOND ceiling, and it binds before the quantity one does. `gates_donations`
     * .amount_naira is `INT UNSIGNED` (4,294,967,295), and the price is quantity × rate —
     * so at an admin rate of ₦1,000 a vote, {@see HARD_MAX_QTY} alone would let `price()`
     * compute ₦10,000,000,000 and overflow the column. Two ceilings that each look
     * sufficient in isolation, multiplied together, is exactly how an "impossible" value
     * reaches a column.
     *
     * ₦100,000,000 also happens to be far above what any gateway will authorise on one
     * transaction, so the practical limit on a genuinely large order is a bank's, not
     * ours — {@see maxQtyForOrder()} is what the form and the checkout agree on.
     */
    public const MAX_ORDER_NAIRA = 100_000_000;

    /**
     * Hours after a cycle's close that a payment STARTED BEFORE THE CLOSE may
     * still mint. Six covers a stuck webhook, a retried 502 and a bank transfer
     * that settles overnight; it is far short of a results announcement.
     */
    public const DEFAULT_GRACE_HOURS = 6;

    /**
     * Minutes before a cycle's close that paid checkout stops taking new orders.
     * Ten is roughly three times the slowest realistic 3-D Secure round trip,
     * so an order accepted at the cutoff has ample room to confirm in time.
     */
    public const DEFAULT_CUTOFF_MINUTES = 10;

    /**
     * @deprecated Read {@see maxQty()} instead — this is only the default.
     *             Kept as an alias so nothing that referenced the old constant breaks.
     */
    public const MAX_QTY = self::DEFAULT_MAX_QTY;

    private static function setting(string $key): ?string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return is_string($v) ? trim($v) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when the category's cycle is inside its voting window, per the
     * shared COMPUTED-phase guard. One helper so checkout, minting and any
     * future paid-vote surface cannot drift apart.
     */
    public static function votingOpenFor(int $categoryId, ?Carbon $at = null): bool
    {
        return BallotGuard::isVotable($categoryId, $at);
    }

    /**
     * When the buyer actually placed this order.
     *
     * `created_at` is written by start(), which has already refused the order
     * unless voting was open — so this is not a guess about intent, it is a
     * timestamp the platform stamped on a check it performed itself.
     *
     * A row with no usable timestamp falls back to now, which reproduces the old
     * behaviour for that row rather than minting on an assumption.
     */
    private static function orderPlacedAt(object $don): Carbon
    {
        $raw = trim((string) ($don->created_at ?? ''));
        if ($raw === '') return Carbon::now();
        try { return Carbon::parse($raw); } catch (\Throwable) { return Carbon::now(); }
    }

    /**
     * How long after a cycle closes a payment TAKEN BEFORE THE CLOSE may still mint.
     *
     * Gateway confirmation is neither instant nor ours. A 3-D Secure round trip,
     * a bank transfer that settles on the bank's schedule, a webhook retried
     * after a 502 — each can put minutes or hours between somebody paying and us
     * hearing about it. That lag belongs to our infrastructure, not to the buyer,
     * and charging them for it is what made people furious.
     *
     * Bounded, though: a webhook arriving three weeks late must not move a tally
     * whose winner has been announced. Inside the window it mints; outside it is
     * refused and refunded, which RefundService already does by itself.
     */
    public static function lateMintGraceHours(): int
    {
        $v = (int) (self::setting('paid_vote_grace_hours') ?? 0);
        return $v > 0 ? min($v, 168) : self::DEFAULT_GRACE_HOURS;
    }

    /**
     * How long BEFORE a cycle closes paid checkout stops taking new orders.
     *
     * The prevention half. If nothing can START in the last few minutes, almost
     * nothing is ever in flight when the ballot shuts, and the grace window above
     * becomes a safety net rather than a routine code path.
     *
     * Free OTP voting is untouched — it mints inside the request, so it can run
     * to the bell. Only the path that has to wait on somebody else's server stops
     * early, and the ballot says so rather than silently refusing.
     */
    public static function checkoutCutoffMinutes(): int
    {
        $v = self::setting('paid_vote_cutoff_minutes');
        if ($v === null || $v === '') return self::DEFAULT_CUTOFF_MINUTES;
        return max(0, min((int) $v, 240));
    }

    /**
     * When paid checkout stops for this category, or null when it is not votable.
     *
     * Returned so the ballot can SAY the time rather than just refuse at it —
     * "card payment closes 23:50" is a plan; "closed" thirty seconds after you
     * tapped pay is a betrayal.
     */
    public static function checkoutClosesAt(int $categoryId): ?Carbon
    {
        $close = BallotGuard::votingCloseFor($categoryId);
        return $close?->copy()->subMinutes(self::checkoutCutoffMinutes());
    }

    /** True when paid checkout is inside its (earlier) window for this category. */
    public static function checkoutOpenFor(int $categoryId, ?Carbon $at = null): bool
    {
        $at = $at ?? Carbon::now();
        if (!self::votingOpenFor($categoryId, $at)) return false;

        $cutoff = self::checkoutClosesAt($categoryId);
        // No published close time means no cutoff to enforce — an open-ended
        // cycle must not have paid voting silently disabled by a null.
        return $cutoff === null || $at->lt($cutoff);
    }

    /**
     * When THIS order's checkout dies — the earlier of our patience and the ballot.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A PER-ORDER DEADLINE AND NOT A GLOBAL WINDOW
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A checkout used to be live for a flat {@see PaymentService::IN_FLIGHT_MINUTES}
     * from creation, with no knowledge of the ballot. So an order started twenty
     * minutes before the bell stayed "in flight" for an hour and forty minutes AFTER
     * voting had closed. Everything downstream believed it: the reconciler kept
     * re-asking the gateway about it, the abandoned-cart mailer treated it as a live
     * cart worth nudging, and a payment that landed in that stretch was confirmed —
     * money taken for votes that could no longer be delivered.
     *
     * That is the shape of the whole incident. Every refund the platform has had to
     * issue for "voting closed before the payment confirmed" began as a checkout the
     * ballot had already finished with but nothing had told.
     *
     * So the deadline is a property of the ORDER, computed once from two facts, and
     * `min()` is the entire policy:
     *
     *   created_at + IN_FLIGHT_MINUTES   how patient we are with a slow bank
     *   voting close                     when the votes stop being deliverable
     *
     * ── WHY THE CLOSE AND NOT checkoutClosesAt() ─────────────────────────────
     *
     * {@see checkoutClosesAt()} is several minutes EARLIER, and it governs whether a
     * checkout may START. Once somebody is already at the gateway with their card
     * out, the honest deadline is the ballot's own — using the earlier one would kill
     * a payment that was still perfectly deliverable and tell a buyer who did nothing
     * wrong that they were too late. Refusing to sell and refusing to honour are
     * different decisions and they are allowed different clocks.
     *
     * ── AND WHY THIS DOES NOT SHORTEN THE LATE-MINT GRACE ────────────────────
     *
     * {@see lateMintGraceHours()} still applies, and deliberately outlives this. This
     * is about when a checkout stops being PAYABLE; the grace is about honouring a
     * payment that was made in time and confirmed slowly. Clipping the grace to this
     * would refuse the very people the grace exists to protect — somebody who paid at
     * 23:58 whose webhook landed at 00:02.
     *
     * @return Carbon|null null when the cycle publishes no close time, i.e. there is
     *                     nothing to clip against — an open-ended cycle must not have
     *                     its checkouts silently killed by a missing date.
     */
    public static function checkoutDeadline(int $categoryId, Carbon $placedAt): ?Carbon
    {
        $ours  = $placedAt->copy()->addMinutes(PaymentService::IN_FLIGHT_MINUTES);
        $close = BallotGuard::votingCloseFor($categoryId);

        if ($close === null) return $ours;
        return $close->lt($ours) ? $close->copy() : $ours;
    }

    /**
     * Restrict a query to pending orders whose checkout is no longer payable.
     *
     * ONE predicate, because three places were each deriving this from the flat
     * global window and they are supposed to agree: the abandoned-cart nudge ("you
     * did not finish paying"), the reconciler's tombstone, and anything else that has
     * to decide whether a buyer is still at the gateway or has gone.
     *
     * Reads the recorded deadline where there is one and falls back to the old global
     * window where there is not. The fallback is load-bearing rather than tidy: rows
     * predating the column, and open-ended cycles with no published close, both carry
     * NULL — and treating NULL as "expired" would void every in-flight checkout on a
     * database the migration had only just touched.
     */
    public static function whereCheckoutDead(mixed $q, ?Carbon $now = null): mixed
    {
        $now      = $now ?? Carbon::now();
        $fallback = $now->copy()->subMinutes(PaymentService::IN_FLIGHT_MINUTES)->toDateTimeString();

        if (!OptionalColumn::on('gates_donations', 'checkout_expires_at')) {
            return $q->where('created_at', '<=', $fallback);
        }

        $stamp = $now->toDateTimeString();
        return $q->where(function ($w) use ($stamp, $fallback) {
            $w->where('checkout_expires_at', '<', $stamp)
              ->orWhere(function ($o) use ($fallback) {
                  $o->whereNull('checkout_expires_at')->where('created_at', '<=', $fallback);
              });
        });
    }

    /** Master toggle — OFF by default. */
    public static function enabled(): bool
    {
        return self::setting('paid_voting_enabled') === '1';
    }

    /** When paid voting is on, the admin may also switch the free OTP path off. */
    public static function freeVotingDisabled(): bool
    {
        return self::enabled() && self::setting('paid_voting_disable_free') === '1';
    }

    /** Admin-set price for a single vote (₦). */
    public static function pricePerVote(): int
    {
        $v = (int) (self::setting('vote_price_naira') ?? 0);
        return $v > 0 ? $v : self::DEFAULT_PRICE_NAIRA;
    }

    /**
     * The largest quantity ONE order may carry, from the admin's settings.
     *
     * Clamped to {@see HARD_MAX_QTY} rather than trusted, because this reads a value an
     * admin typed into a form and the number goes straight into a public vote tally. A
     * setting of 0 or a blank means "use the default", so clearing the field cannot
     * accidentally disable paid voting by capping every order at zero votes.
     */
    public static function maxQty(): int
    {
        $v = (int) (self::setting('vote_max_qty') ?? 0);
        if ($v < 1) $v = self::DEFAULT_MAX_QTY;
        return min(self::HARD_MAX_QTY, $v);
    }

    /**
     * The real maximum for one order: the smaller of the admin's quantity cap and the
     * quantity whose price still fits {@see MAX_ORDER_NAIRA}.
     *
     * ONE function, consulted by the ballot form (as the input's `max`), by the checkout
     * (as the rejection threshold) and by the settings page (as the figure it shows the
     * admin). Three surfaces computing this separately is how a form offers a quantity the
     * checkout then refuses.
     */
    public static function maxQtyForOrder(): int
    {
        // A large order pays the DEEPEST tier discount, so that is the rate which
        // decides how many votes fit under the cash ceiling. Computed from the same
        // ladder price() uses — deriving it from the retired ₦1,000 bundle rate would
        // cap orders against a price the buyer is no longer charged.
        $deepest  = 0;
        foreach (self::tiers() as $t) { $deepest = max($deepest, $t['off']); }
        $cheapest = max(0.01, (float) self::pricePerVote() * (100 - $deepest) / 100);

        return max(1, min(self::maxQty(), (int) floor(self::MAX_ORDER_NAIRA / $cheapest)));
    }

    /**
     * The retired bundle rate: votes granted per ₦1,000.
     *
     * @deprecated Superseded by {@see tiers()}. Nothing prices with this any more — it
     *             survives only so `2026_07_31_vote_tiers_from_bundle.php` can read what
     *             a live site had configured and translate it into an equivalent tier.
     *             Once every deployment has run that migration this can go.
     */
    public static function votesPer1000(): int
    {
        $v = (int) (self::setting('vote_votes_per_1000') ?? 0);
        return $v > 0 ? $v : self::DEFAULT_PER_1000;
    }

    /** Fallback ladder when none is configured — the chips the ballot already showed. */
    public const DEFAULT_TIERS = [
        ['qty' => 1,  'off' => 0],
        ['qty' => 5,  'off' => 0],
        ['qty' => 10, 'off' => 5],
        ['qty' => 30, 'off' => 10],
    ];

    /**
     * The quantity ladder: each entry is BOTH a chip on the ballot and the bulk
     * discount that applies from that quantity upward.
     *
     * ── WHY ONE LIST AND NOT THREE SETTINGS ──────────────────────────────────
     *
     * Editable chips, percentage discounts and "make pricing flexible" arrived as
     * three requests and are one thing: a table of "buy this many, get this much
     * off". Modelling them separately guarantees they disagree — a chip for 6 votes
     * with a discount defined at 5, and a buyer shown one number and charged another.
     *
     * ── WHY PERCENTAGES REPLACED THE ₦1,000 BUNDLE ───────────────────────────
     *
     * The old rate was "votes per ₦1,000", a discount only by implication: the admin
     * had to work backwards from the per-vote price to discover what discount they
     * had just set, and its meaning changed silently whenever that price moved. A
     * percentage is the thing being decided, so it is the thing stored — and it stays
     * correct when the price changes. `votesPer1000()` is left for the migration and
     * is no longer consulted by `price()`.
     *
     * Stored as JSON so the ladder can be any length. Invalid rows are DROPPED rather
     * than rejected: this is read on the public ballot, and a malformed setting must
     * degrade to sane pricing, never to a fatal on the page that takes money.
     *
     * @return list<array{qty:int, off:int}> ascending by qty, deduplicated
     */
    public static function tiers(): array
    {
        $raw  = trim((string) (self::setting('vote_tiers') ?? ''));
        $rows = $raw !== '' ? json_decode($raw, true) : null;
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $qty = (int) ($r['qty'] ?? 0);
                if ($qty < 1) continue;
                // Capped at 90%: a 100% discount is a free vote sold as a paid one,
                // and the resulting ₦0 order cannot be charged — it would strand the
                // buyer at the gateway with a pending order behind them.
                $out[$qty] = ['qty' => $qty, 'off' => max(0, min(90, (int) ($r['off'] ?? 0)))];
            }
        }
        if (!$out) return self::DEFAULT_TIERS;
        ksort($out);
        return array_values($out);
    }

    /** The quantities the ballot offers as one-tap chips. */
    public static function chips(): array
    {
        return array_map(static fn (array $t): int => $t['qty'], self::tiers());
    }

    /** The discount at this quantity — the deepest tier the quantity has reached. */
    public static function discountPctFor(int $qty): int
    {
        $off = 0;
        foreach (self::tiers() as $t) {
            if ($qty >= $t['qty']) $off = $t['off'];
        }
        return $off;
    }

    /**
     * Server-side price for a quantity of votes — the ONLY place a total is decided.
     *
     * qty × per-vote price, less the tier discount the quantity has reached. Rounded
     * UP to the naira so a percentage can never shave a fraction in the buyer's
     * favour and disagree with what the gateway is asked to charge, and floored at
     * ₦100 because every gateway rejects less — a low enough price with a deep enough
     * discount would otherwise produce an order that cannot be paid at all.
     */
    public static function price(int $qty): int
    {
        $qty   = max(1, min(self::maxQty(), $qty));
        $gross = $qty * self::pricePerVote();
        $net   = (int) ceil($gross * (100 - self::discountPctFor($qty)) / 100);
        return max(100, $net);
    }

    /** What a quantity saves against the undiscounted price — for the ballot copy. */
    public static function savingFor(int $qty): int
    {
        $qty = max(1, min(self::maxQty(), $qty));
        return max(0, $qty * self::pricePerVote() - self::price($qty));
    }

    /**
     * Mint the paid votes for a CONFIRMED paid-vote order. Idempotent: the
     * order's votes_used flips 0 → bonus_votes exactly once (guarded UPDATE),
     * so gateway webhook + browser callback can both call this safely.
     *
     * @return array{ok:bool, minted?:int, message?:string}
     */
    public static function mint(int $donationId): array
    {
        $don = DB::table('gates_donations')->where('id', $donationId)->first();
        if (!$don)                                        return ['ok' => false, 'message' => 'Order not found.'];
        if (($don->tier ?? '') !== 'paid-vote')           return ['ok' => false, 'message' => 'Not a paid-vote order.'];
        if (($don->status ?? '') !== 'confirmed')         return ['ok' => false, 'message' => 'Order is not confirmed.'];
        $qty       = (int) $don->bonus_votes;
        $nomineeId = (int) ($don->intent_nominee_id ?? 0);
        if ($qty < 1 || $nomineeId < 1)                   return ['ok' => false, 'message' => 'Order carries no votes.'];

        $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->first();
        if (!$nominee)                                    return ['ok' => false, 'message' => 'Nominee not found.'];

        // ── STILL A NOMINEE WHEN THE MONEY LANDED? ───────────────────────────
        //
        // The checkout enforces this allowlist; this did not, and the two are separated
        // by however long the gateway takes — minutes usually, and the webhook can arrive
        // later still. In that gap a moderator can DISQUALIFY somebody: fraud, a
        // withdrawn nomination, a rule breach found after the fact.
        //
        // Without this the votes were credited anyway. A disqualified nominee's public
        // tally moved, the site-wide "votes cast" total counted it, and the buyer was
        // told their votes had landed on a page the platform had already decided did not
        // belong on the ballot. Nothing failed loudly, which is why it survived the
        // careful work done on every OTHER way this mint can go wrong.
        //
        // Deliberately NOT the same answer as a late confirmation. That case reasons "we
        // sold them a vote; we owe them the vote", and it is right, because the ballot was
        // open when we took the money and the lag is our infrastructure's. Disqualification
        // is a different fact: there is no longer anything to credit, and crediting it
        // anyway would put the platform's own integrity decision behind a payment. So this
        // refuses and leaves votes_used = 0 — the queryable "paid but delivered nothing"
        // signal RefundService already sweeps — which sends the money back rather than
        // keeping it for votes nobody can honour.
        //
        // Merged nominees are covered too: a merge repoints intent_nominee_id to the
        // survivor, so reaching this with a tombstone means the repoint did not happen,
        // and minting into a row no page reads is never the right answer.
        $eligible = in_array((string) ($nominee->status ?? ''), ['approved', 'winner', 'runner_up'], true)
                 && empty($nominee->merged_into ?? null);
        if (!$eligible) {
            return ['ok' => false, 'code' => 'NOMINEE_NOT_ELIGIBLE',
                    'message' => 'This nominee is no longer on the ballot, so the votes could not be '
                               . 'added. No votes were counted and this order is refundable.'];
        }

        // STORAGE GUARD, and it is not redundant with the checkout's cap.
        //
        // The quantity was validated when the order was created; this runs when the
        // gateway confirms, which can be much later and can be reached from the webhook
        // as well as the browser. In between, an admin may have LOWERED the cap, or the
        // order may predate a cap change entirely. What must never happen is this INSERT
        // reaching the database with a weight the column cannot hold: under strict mode
        // that aborts the transaction (a paid order that cannot mint), and on a host that
        // has relaxed sql_mode MySQL CLAMPS it and reports success — crediting 65,535
        // votes for an order of 100,000 with no error anywhere.
        //
        // Refused the same way a closed cycle is refused: votes_used stays 0, which is the
        // queryable "paid but never minted — refund owed" signal `cycles:audit` already
        // reports and the clawback path can already reverse. No new state to learn.
        if ($qty > self::HARD_MAX_QTY) {
            return ['ok' => false, 'code' => 'QTY_TOO_LARGE',
                    'message' => 'Order quantity (' . number_format($qty) . ') exceeds the maximum a single '
                        . 'vote record can hold (' . number_format(self::HARD_MAX_QTY) . '). No votes were added — '
                        . 'this order is refundable, or can be re-entered as several smaller orders.'];
        }

        // ── PHASE GATE, ON THE BUYER'S CLOCK ─────────────────────────────────
        //
        // This gate used to ask "is voting open RIGHT NOW", where "now" was
        // whenever the gateway's webhook happened to land. It was added for a
        // real reason — a late confirmation must not bump a closed tally — but it
        // read the wrong clock, and the people it punished were the ones who had
        // done nothing wrong.
        //
        // Somebody pays at 23:58 while the ballot is plainly open. 3-D Secure
        // takes a minute. The webhook is retried once after a 502. It lands at
        // 00:02, this gate says "closed", the money has already left their
        // account and no votes appear. They watch a nominee they paid to support
        // finish without their votes and are told days later that a refund is
        // coming. That is not an edge case; it is every deadline, and it is the
        // single most enraging thing this platform can do.
        //
        // THE ORDER'S OWN TIMESTAMP IS THE ANSWER. `start()` already refuses to
        // create a paid-vote order unless voting is open, so `created_at` is a
        // verified record that the ballot WAS open when we took the money. We
        // sold them a vote; we owe them the vote. The lag is our
        // infrastructure's, not theirs.
        //
        // BallotGuard::isVotable() has always accepted a `$now` and threaded it
        // down to CyclePolicy::stateFor(). The parameter existed; the caller was
        // not using it.
        //
        // Still bounded on the late side, because the original concern is valid:
        // a webhook three weeks late must not move a tally whose winner has been
        // announced. Past the grace window this refuses exactly as before,
        // leaving votes_used = 0 — the queryable "paid but never minted" signal
        // that cycles:audit reports and RefundService already acts on by itself.
        // ── MONEY GOING BACK BEATS VOTES GOING OUT ───────────────────────────
        //
        // New, and required BY the widened window below. RefundService refunds an
        // order that could not mint, and it used to be impossible for a refunded
        // order to mint afterwards because the phase gate stayed shut forever.
        // Now the gate can reopen for hours, so a reconciler retry could credit
        // votes for money that has already been sent back — free votes, paid for
        // by us.
        //
        // `refund_requested_at` is the CLAIM stamp RefundService writes BEFORE it
        // calls the gateway, so this refuses while a refund is merely in flight,
        // not only once it has landed. Checked through OptionalColumn because
        // these columns arrive with 2026_08_03_auto_refunds.
        if (OptionalColumn::on('gates_donations', 'refunded_at')) {
            $refunded = ($don->refunded_at ?? null) !== null
                || (($don->refund_requested_at ?? null) !== null);
            if ($refunded) {
                return ['ok' => false, 'code' => 'ALREADY_REFUNDED',
                        'message' => 'This order has been refunded, so no votes were added.'];
            }
        }

        $placedAt = self::orderPlacedAt($don);
        $catId    = (int) $nominee->category_id;

        if (!self::votingOpenFor($catId, $placedAt)) {
            return ['ok' => false, 'code' => 'VOTING_CLOSED',
                    'message' => 'Voting was already closed when this payment was started. '
                               . 'No votes were added — this order is refundable.'];
        }

        // Open when they paid. Is the confirmation within reach of the close?
        $grace = self::lateMintGraceHours();
        $close = BallotGuard::votingCloseFor($catId);
        if ($close !== null && Carbon::now()->gt($close->copy()->addHours($grace))) {
            return ['ok' => false, 'code' => 'CONFIRMED_TOO_LATE',
                    'message' => 'This payment confirmed more than ' . $grace . ' hours after voting closed, '
                               . 'so the votes could not be counted. The order is refundable.'];
        }

        return DB::connection()->transaction(function () use ($don, $nominee, $qty, $nomineeId) {
            // Idempotency gate: only the first caller flips votes_used.
            $claimed = DB::table('gates_donations')
                ->where('id', $don->id)->where('votes_used', 0)
                ->update(['votes_used' => $qty]);
            if ($claimed === 0) return ['ok' => true, 'minted' => 0, 'message' => 'Already minted.'];

            DB::table('gates_votes')->insert(OptionalColumn::filter('gates_votes', [
                'nominee_id'       => $nomineeId,
                'category_id'      => (int) $nominee->category_id,
                // Synthetic, order-scoped hash — never an email, never collides
                // with the one-vote-per-category unique key for real voters.
                'voter_email_hash' => 'paidvote:' . $don->id . ':' . bin2hex(random_bytes(6)),
                'voter_name'       => mb_substr((string) $don->donor_name, 0, 120),
                // The buyer's consent, carried from the order onto the vote so the
                // public supporters list never has to read a payments table. An order
                // that predates the field has show_name = 0 by column default, and
                // reading it off an unmigrated database yields null → 0. Both mean
                // private, the only safe direction for a default.
                //
                // FILTERED, NOT WRITTEN BLIND. Reading the column degrades on its own;
                // writing it does not, and that asymmetry is what broke paid voting. On
                // an unmigrated database this INSERT threw INSIDE the claim transaction
                // — after `votes_used` had already been set — so the money was taken,
                // the order looked minted, and no vote existed. A supporter who has paid
                // must get their votes whether or not anyone has run the migration.
                'show_name'        => (int) ($don->show_name ?? 0) === 1 ? 1 : 0,
                'vote_type'        => 'paid',
                'weight'           => $qty,
                'donation_id'      => (int) $don->id,
                'voted_at'         => Carbon::now()->toDateTimeString(),
            ], ['show_name']));
            // Public tally only — organic_vote_count (the CPI community signal)
            // is NEVER moved by money.
            DB::table('gates_nominees')->where('id', $nomineeId)->increment('vote_count', $qty);

            // The free OTP path busts this tag (ApiController) and the paid path did not,
            // so a paid vote left the front page's "Votes cast" and the leaderboard
            // caches stale for up to an hour. That is the worst possible window: a paid
            // pack is exactly what a nominee buys during a rally and then goes to look at.
            try { (new CacheService())->forgetByTag('leaderboard'); } catch (\Throwable) {}

            WebhookService::dispatch('vote.paid', [
                'nominee_id' => $nomineeId,
                'nominee'    => (string) $nominee->name,
                'votes'      => $qty,
                'order_ref'  => (string) ($don->payment_ref ?? ''),
            ]);
            // ── THE NOMINEE'S SHARE OF WHAT WAS RAISED IN THEIR NAME ─────
            //
            // Provisioned HERE, at the moment the contribution becomes real, rather
            // than totalled up at the end of the cycle. If the share were computed
            // later, the money would have spent the whole cycle inside the operating
            // budget looking spendable, and the bill would arrive after it was gone.
            // Setting it aside on arrival means the programme only ever sees its own
            // portion.
            //
            // Inside the transaction on purpose: a vote that exists without its
            // accrual, or an accrual without its vote, are both states somebody would
            // later have to reconcile by hand. Silent when the rate is zero, when the
            // nominee has not qualified, or when this contribution already accrued —
            // all three are ordinary rather than failures, and none of them may
            // interrupt the delivery of votes somebody has paid for.
            try {
                CommunityReturnService::accrue((int) $don->id);
            } catch (\Throwable $e) {
                error_log('[paid-vote] community return not accrued on order ' . (int) $don->id . ': ' . $e->getMessage());
            }

            // ── AND TELL THEM WHAT IT DID ────────────────────────────────────
            //
            // Not a receipt — CheckoutMailer already sends one, and their bank has
            // already told them the amount. This says where the nominee now stands
            // and how many people are behind them, which is the thing they actually
            // wanted to know and cannot see anywhere else.
            //
            // OUTSIDE the accrual's concerns but inside the same transaction, and
            // claimed against gates_supporter_honours before it composes anything —
            // a retried mint or a replayed webhook must not thank the same person
            // twice. Best-effort to the point of silence: a mail failure has no
            // business rolling back votes somebody has paid for.
            try {
                SupporterHonours::thank((int) $don->id);
            } catch (\Throwable $e) {
                error_log('[paid-vote] supporter not thanked on order ' . (int) $don->id . ': ' . $e->getMessage());
            }

            return ['ok' => true, 'minted' => $qty];
        });
    }
}
