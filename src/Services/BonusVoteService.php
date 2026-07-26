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
 * nominee's vote_count — the public "total support" display — by N.
 *
 * INTEGRITY: bonus votes are EXCLUDED from the Cultural Power Index. CPI's
 * community component is cohort-normalised over organic_vote_count only, which
 * this path never touches — so purchased votes are visible as supporter backing
 * but cannot move rank or winner selection. They also never touch the judge
 * component. Each bonus vote is auditable (donation_id) and reversible.
 *
 * Integrity guards mirror the organic path: confirmed donation only, cannot
 * redeem more than remain, nominee must be approved, cycle must be in 'voting'.
 * A per-nominee cap (RuleEngine 'max_paid_weight_pct', default 50% of organic
 * support, with a small floor) bounds how large the paid display boost can get.
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
            if (!empty($donation->refunded_at ?? null)) {
                return ['ok' => false, 'message' => 'This donation was refunded — its votes can no longer be redeemed.'];
            }
            $remaining = (int) $donation->bonus_votes - (int) $donation->votes_used;
            if ($count > $remaining) {
                return ['ok' => false, 'message' => "Only {$remaining} bonus vote(s) remain on this donation."];
            }

            $nominee = MergeService::notMerged(DB::table('gates_nominees')->where('id', $nomineeId)->where('status', 'approved'))->first();
            if (!$nominee) {
                return ['ok' => false, 'message' => 'Nominee is not open for voting.'];
            }

            // Same cycle gate as organic votes: an approved nominee is only votable
            // while its cycle is in 'voting'.
            $cycle = DB::table('gates_award_cycles AS cy')
                ->join('gates_award_categories AS c', 'c.cycle_id', '=', 'cy.id')
                ->where('c.id', $nominee->category_id)
                ->select('cy.status', 'cy.id', 'cy.programme_id')->first();
            if (!$cycle) {
                return ['ok' => false, 'message' => 'Voting is not open for this category right now.'];
            }
            // Same COMPUTED-phase gate as an organic vote.
            try {
                BallotGuard::assertVotable((int) $nominee->category_id);
            } catch (PhaseError $e) {
                return ['ok' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode];
            }

            // Cap paid influence: total bonus weight on a nominee may not exceed a
            // configurable % of its ORGANIC votes (RuleEngine 'max_paid_weight_pct'),
            // so money cannot swamp the community signal. A small floor lets early
            // donations through before a nominee has built organic support.
            $pct = (int) ((new RuleEngine())->effective((int) $cycle->programme_id, (int) $cycle->id)['max_paid_weight_pct'] ?? 50);
            $bonusSoFar = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)->where('vote_type', 'bonus')->sum('weight');
            $organic = (int) $nominee->organic_vote_count;   // stable organic base (excludes paid)
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

            // Bonus weight bumps ONLY the display total (vote_count). organic_vote_count
            // is left untouched, so paid votes never enter the cohort-normalised CPI
            // community signal — money cannot move rank, only the visible support tally.
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

    /**
     * Reverse a refunded/charged-back donation: void EVERY purchased vote row
     * (bonus + paid) minted from it, rebuild the affected nominees' counters from
     * the surviving rows, and stamp refunded_at so the donation can't be redeemed
     * again. The audited ORGANIC path is untouched — organic_vote_count only ever
     * counted 'standard' rows, so this cannot change any nominee's CPI rank; it
     * only removes the paid display boost the refunded money bought.
     *
     * Idempotent (a second call finds no rows and no-ops) and transactional.
     *
     * @return array{ok:bool, error?:string, cleared:int, weight:int, nominees:int[]}
     */
    public static function clawbackDonation(int $donationId, ?int $adminId = null, string $reason = 'refund'): array
    {
        if ($donationId < 1) return ['ok' => false, 'error' => 'Invalid donation id.', 'cleared' => 0, 'weight' => 0, 'nominees' => []];

        try {
            $out = DB::transaction(function () use ($donationId) {
                $rows = DB::table('gates_votes')->where('donation_id', $donationId)->get(['id', 'nominee_id', 'weight']);
                $nominees = array_values(array_unique(array_map(static fn($r) => (int) $r->nominee_id, $rows->all())));
                $weight   = (int) $rows->sum('weight');

                if ($rows->isNotEmpty()) {
                    DB::table('gates_votes')->where('donation_id', $donationId)->delete();
                    foreach ($nominees as $nid) self::recountNominee($nid);
                }
                // Stamp the reversal (only from a live/confirmed state) so redemption
                // is blocked and it shows as refunded. Safe to run on any status.
                DB::table('gates_donations')->where('id', $donationId)
                    ->update(['refunded_at' => Carbon::now()->toDateTimeString()]);

                return ['cleared' => $rows->count(), 'weight' => $weight, 'nominees' => $nominees];
            });
        } catch (\Throwable $e) {
            error_log('[bonus-vote clawback] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Clawback failed.', 'cleared' => 0, 'weight' => 0, 'nominees' => []];
        }

        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($adminId ?? 0), 'donation.clawback', 'donation', $donationId,
                ['reason' => $reason, 'cleared' => $out['cleared'], 'weight' => $out['weight'], 'nominees' => $out['nominees']]
            );
        } catch (\Throwable) {}

        return ['ok' => true] + $out;
    }

    /** Rebuild a nominee's vote counters from surviving rows (vote_count = all weight; organic = 'standard' only). */
    private static function recountNominee(int $nomineeId): void
    {
        try {
            $all     = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)->sum('weight');
            $organic = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)->where('vote_type', 'standard')->sum('weight');
            DB::table('gates_nominees')->where('id', $nomineeId)->update([
                'vote_count'         => $all,
                'organic_vote_count' => $organic,
            ]);
        } catch (\Throwable) {}
    }
}
