<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Paid / bonus votes — wires the previously-dead gates_donations.bonus_votes.
 *
 * A CONFIRMED donation grants N bonus votes. Redeeming them mints ONE weighted
 * row in gates_votes (vote_type='bonus', weight=N, donation_id=…) and bumps the
 * nominee's vote_count by N. Because the Cultural Power Index community component
 * is cohort-normalised over vote_count, bonus votes flow through the EXISTING CPI
 * math unchanged — and they never touch the judge component, so expert scoring
 * stays clean. A bonus vote is auditable (its donation_id) and reversible at the
 * data layer.
 *
 * Integrity guards mirror the organic path: confirmed donation only, cannot
 * redeem more than remain, nominee must be approved, cycle must be in 'voting'.
 *
 * POLICY NOTE: bonus weight currently counts in full. A per-cycle cap on paid
 * influence (e.g. RuleEngine 'max_paid_weight_pct') is a deliberate follow-up —
 * the seam is the increment below, not the CPI formula.
 */
class BonusVoteService
{
    /** Floor for the paid-vote cap so early donations aren't blocked at 0 organic. */
    private const MIN_BONUS_CAP = 10;

    public function __construct(private readonly ?LoggerInterface $log = null) {}

    /**
     * Redeem $count bonus votes from a confirmed donation onto a nominee.
     *
     * @return array{ok:bool, message?:string, vote_id?:int, weight?:int}
     */
    public function redeem(int $donationId, int $nomineeId, int $count = 1): array
    {
        if ($count < 1) {
            return ['ok' => false, 'message' => 'Vote count must be at least 1.'];
        }

        $result = DB::transaction(function () use ($donationId, $nomineeId, $count) {
            // Lock the donation row so two concurrent redemptions can't both spend
            // the same remaining balance (classic double-spend).
            $donation = DB::table('gates_donations')->where('id', $donationId)->lockForUpdate()->first();
            if (!$donation) {
                return ['ok' => false, 'message' => 'Donation not found.'];
            }
            if ($donation->status !== 'confirmed') {
                return ['ok' => false, 'message' => 'Donation is not confirmed.'];
            }
            $remaining = (int) $donation->bonus_votes - (int) $donation->votes_used;
            if ($count > $remaining) {
                return ['ok' => false, 'message' => "Only {$remaining} bonus vote(s) remain on this donation."];
            }

            $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->where('status', 'approved')->first();
            if (!$nominee) {
                return ['ok' => false, 'message' => 'Nominee is not open for voting.'];
            }

            // Same cycle gate as organic votes: an approved nominee is only votable
            // while its cycle is in 'voting'.
            $cycle = DB::table('gates_award_cycles AS cy')
                ->join('gates_award_categories AS c', 'c.cycle_id', '=', 'cy.id')
                ->where('c.id', $nominee->category_id)
                ->select('cy.status', 'cy.id', 'cy.programme_id')->first();
            if (!$cycle || $cycle->status !== 'voting') {
                return ['ok' => false, 'message' => 'Voting is not open for this category right now.'];
            }

            // Cap paid influence: total bonus weight on a nominee may not exceed a
            // configurable % of its ORGANIC votes (RuleEngine 'max_paid_weight_pct'),
            // so money cannot swamp the community signal. A small floor lets early
            // donations through before a nominee has built organic support.
            $pct = (int) ((new RuleEngine())->effective((int) $cycle->programme_id, (int) $cycle->id)['max_paid_weight_pct'] ?? 50);
            $bonusSoFar = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)->where('vote_type', 'bonus')->sum('weight');
            $organic = max(0, (int) $nominee->vote_count - $bonusSoFar);
            $cap = max(self::MIN_BONUS_CAP, (int) floor($organic * $pct / 100));
            if ($bonusSoFar + $count > $cap) {
                return ['ok' => false, 'message' => "Bonus votes for this nominee are capped at {$cap} ({$pct}% of organic support)."];
            }

            // ONE weighted row represents the whole redemption. The synthetic,
            // namespaced voter hash deliberately bypasses the per-human
            // UNIQUE(voter_email_hash, category_id) rule that governs OTP votes —
            // bonus votes are a separate, purchasable mechanism, so a donation may
            // back several rows in one category.
            $voteId = DB::table('gates_votes')->insertGetId([
                'nominee_id'       => $nomineeId,
                'category_id'      => (int) $nominee->category_id,
                'voter_email_hash' => 'bonus:' . $donationId . ':' . bin2hex(random_bytes(6)),
                'nominee_country'  => $nominee->country_code ?? null,
                'vote_type'        => 'bonus',
                'weight'           => $count,
                'donation_id'      => $donationId,
                'voted_at'         => Carbon::now()->toDateTimeString(),
            ]);

            // The weight (not 1) flows into vote_count, so the existing
            // cohort-normalised community CPI absorbs it with no formula change.
            DB::table('gates_nominees')->where('id', $nomineeId)->increment('vote_count', $count);
            DB::table('gates_donations')->where('id', $donationId)->increment('votes_used', $count);

            return ['ok' => true, 'vote_id' => (int) $voteId, 'weight' => $count];
        });

        if ($result['ok']) {
            $this->log?->info('[bonus-vote] redeemed', ['donation' => $donationId, 'nominee' => $nomineeId, 'weight' => $count]);
        } else {
            $this->log?->info('[bonus-vote] rejected', ['donation' => $donationId, 'reason' => $result['message'] ?? '?']);
        }
        return $result;
    }

    /** Total bonus weight already redeemed onto a nominee (for transparent display). */
    public function bonusWeightFor(int $nomineeId): int
    {
        return (int) DB::table('gates_votes')
            ->where('nominee_id', $nomineeId)
            ->where('vote_type', 'bonus')
            ->sum('weight');
    }
}
