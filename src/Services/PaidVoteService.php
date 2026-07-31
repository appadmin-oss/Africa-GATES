<?php
declare(strict_types=1);

namespace AfricaGates\Services;

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
    public static function votingOpenFor(int $categoryId): bool
    {
        return BallotGuard::isVotable($categoryId);
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

        // PHASE GATE. `start()` checked that voting was open before taking the
        // money, but mint() — reachable from BOTH the browser callback and the
        // gateway webhook, either of which can land arbitrarily late — checked
        // nothing. A payment initiated while voting was open but CONFIRMED
        // after it closed still minted weighted votes and bumped the public
        // tally. Refuse instead, and mark the order so it can be refunded.
        // Deliberately leaves the order at votes_used = 0. A CONFIRMED
        // 'paid-vote' row with votes_used = 0 is therefore the queryable
        // "paid but never minted — refund owed" signal for ops, and the
        // existing clawback path can reverse it. No new column needed, and the
        // idempotency gate below is never armed, so a later retry inside a
        // reopened window would still mint correctly.
        if (!self::votingOpenFor((int) $nominee->category_id)) {
            return ['ok' => false, 'code' => 'VOTING_CLOSED',
                    'message' => 'Voting closed before this payment confirmed. No votes were added — this order is refundable.'];
        }

        return DB::connection()->transaction(function () use ($don, $nominee, $qty, $nomineeId) {
            // Idempotency gate: only the first caller flips votes_used.
            $claimed = DB::table('gates_donations')
                ->where('id', $don->id)->where('votes_used', 0)
                ->update(['votes_used' => $qty]);
            if ($claimed === 0) return ['ok' => true, 'minted' => 0, 'message' => 'Already minted.'];

            DB::table('gates_votes')->insert([
                'nominee_id'       => $nomineeId,
                'category_id'      => (int) $nominee->category_id,
                // Synthetic, order-scoped hash — never an email, never collides
                // with the one-vote-per-category unique key for real voters.
                'voter_email_hash' => 'paidvote:' . $don->id . ':' . bin2hex(random_bytes(6)),
                'voter_name'       => mb_substr((string) $don->donor_name, 0, 120),
                // The buyer's consent, carried from the order onto the vote so the
                // public supporters list never has to read a payments table. An order
                // that predates the checkbox has show_name = 0 by column default, and
                // a database without the migration yields null → 0. Both mean private,
                // which is the only safe direction for a default.
                'show_name'        => (int) ($don->show_name ?? 0) === 1 ? 1 : 0,
                'vote_type'        => 'paid',
                'weight'           => $qty,
                'donation_id'      => (int) $don->id,
                'voted_at'         => Carbon::now()->toDateTimeString(),
            ]);
            // Public tally only — organic_vote_count (the CPI community signal)
            // is NEVER moved by money.
            DB::table('gates_nominees')->where('id', $nomineeId)->increment('vote_count', $qty);

            WebhookService::dispatch('vote.paid', [
                'nominee_id' => $nomineeId,
                'nominee'    => (string) $nominee->name,
                'votes'      => $qty,
                'order_ref'  => (string) ($don->payment_ref ?? ''),
            ]);
            return ['ok' => true, 'minted' => $qty];
        });
    }
}
