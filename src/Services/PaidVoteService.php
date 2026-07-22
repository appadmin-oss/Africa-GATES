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
 * Pricing: the admin sets a per-vote price AND a votes-per-₦1,000 bundle
 * rate; the charge is always the CHEAPER of the two rules for the requested
 * quantity (bulk discount, computed server-side — never trust client totals).
 */
class PaidVoteService
{
    public const DEFAULT_PRICE_NAIRA = 100;
    public const DEFAULT_PER_1000    = 10;
    public const MAX_QTY             = 1000;

    private static function setting(string $key): ?string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return is_string($v) ? trim($v) : null;
        } catch (\Throwable) {
            return null;
        }
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

    /** Admin-set bundle rate: votes granted per ₦1,000. */
    public static function votesPer1000(): int
    {
        $v = (int) (self::setting('vote_votes_per_1000') ?? 0);
        return $v > 0 ? $v : self::DEFAULT_PER_1000;
    }

    /**
     * Server-side price for a quantity of votes. The per-vote price rules
     * small quantities; the ₦1,000-bundle rate applies to FULL bundles only
     * (it can never fractionally undercut the per-vote price), with any
     * remainder charged per vote — the buyer always gets the cheaper of the
     * two totals. Always ≥ ₦100 so gateway minimums are never violated.
     */
    public static function price(int $qty): int
    {
        $qty     = max(1, min(self::MAX_QTY, $qty));
        $p       = self::pricePerVote();
        $per1000 = self::votesPer1000();
        $perVote = $qty * $p;
        if ($qty < $per1000) return max(100, $perVote);
        $bundles = intdiv($qty, $per1000);
        $mixed   = $bundles * 1000 + ($qty - $bundles * $per1000) * $p;
        return max(100, min($perVote, $mixed));
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
