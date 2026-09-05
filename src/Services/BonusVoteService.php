<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Bonus votes — the votes granted against a confirmed contribution.
 *
 * A CONFIRMED donation grants N bonus votes. Redeeming them mints ONE weighted
 * row in gates_votes (vote_type='bonus', weight=N, donation_id=…) and bumps the
 * nominee's vote_count by N. Each is auditable (donation_id) and reversible.
 *
 * ── WHAT THIS DOCBLOCK USED TO CLAIM ─────────────────────────────────────────
 *
 * "Bonus votes are EXCLUDED from the Cultural Power Index. CPI's community
 * component is cohort-normalised over organic_vote_count only … so purchased votes
 * are visible as supporter backing but cannot move rank or winner selection."
 *
 * That was true and is not. The community half normalises over `vote_count`, the
 * full tally, so a bonus vote counts exactly as a free one does — because the old
 * rule deleted the community half entirely wherever free voting was switched off,
 * and the panel decided every award alone while every page said 45/55.
 *
 * What money still cannot reach is the JUDGE half: judges are never shown a vote
 * count. That is the separation this platform actually maintains, and it is the
 * one worth stating.
 *
 * ── THE CAP, AND WHY IT NO LONGER MEASURES WHAT IT USED TO ───────────────────
 *
 * A per-nominee ceiling (RuleEngine `max_paid_weight_pct`) bounds how much of a
 * nominee's support may be granted rather than cast. It was a percentage of
 * `organic_vote_count`, which made it a dead rule twice over:
 *
 *   · Where `paid_voting_disable_free` is set that column is permanently zero, so
 *     the cap collapsed to its floor — TEN bonus votes per nominee, forever,
 *     whatever anybody contributed — and the refusal read "capped at 10 (50% of
 *     organic support)" to an operator whose site has no organic votes by design.
 *   · And it was measuring against a column the index had stopped reading, so the
 *     thing it was protecting no longer existed.
 *
 * It is a percentage of NON-BONUS support now — everything cast or bought, minus
 * the grants themselves. Excluding the grants is load-bearing: bonus weight
 * increments `vote_count`, so a cap read straight off the tally would raise its own
 * ceiling with every grant and stop being a cap at all.
 *
 * {@see capFor()} — ONE resolver. PointsService carried its own copy of this
 * formula and was broken in exactly the same way, which is what two copies of a
 * rule are for.
 *
 * Integrity guards mirror the organic path: confirmed donation only, cannot
 * redeem more than remain, nominee must be approved, cycle must be in 'voting'.
 */
class BonusVoteService
{
    /** Floor for the bonus cap, so the first contributions are not blocked at 0 support. */
    public const MIN_BONUS_CAP = 10;

    /**
     * How much bonus weight this nominee may hold, and what that was measured against.
     *
     * Returned as parts rather than one integer because the refusal has to be able to
     * SAY the arithmetic. "Capped at 10 (50% of organic support)" was the old message on
     * a site with no organic votes — a true sentence about a rule, and useless to the
     * person reading it, who cannot tell a ceiling they have reached from a ceiling that
     * was never going to move.
     *
     * @param  int $tally  the nominee's `vote_count`; passed in because both callers have
     *                     the row loaded and re-reading it inside a transaction that is
     *                     about to increment it invites two answers to one question.
     * @return array{cap:int, used:int, base:int, pct:int}
     */
    public static function capFor(int $nomineeId, int $tally,
                                  ?int $programmeId, ?int $cycleId): array
    {
        $pct = (int) ((new RuleEngine())->effective($programmeId, $cycleId)['max_paid_weight_pct'] ?? 50);

        $used = (int) DB::table('gates_votes')->where('nominee_id', $nomineeId)
            ->where('vote_type', 'bonus')->sum('weight');

        // Non-bonus support: everything cast or bought, less what has been granted. See
        // the class docblock — measuring against the raw tally makes each grant raise its
        // own ceiling, and the cap silently stops being one.
        $base = max(0, $tally - $used);

        return [
            'cap'  => max(self::MIN_BONUS_CAP, (int) floor($base * $pct / 100)),
            'used' => $used,
            'base' => $base,
            'pct'  => $pct,
        ];
    }

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

            // How much of a nominee's support may be GRANTED rather than cast. One
            // resolver, shared with PointsService — see capFor().
            $c = self::capFor($nomineeId, (int) $nominee->vote_count,
                              (int) $cycle->programme_id, (int) $cycle->id);
            if ($c['used'] + $count > $c['cap']) {
                // The base is named, so an operator can tell "this nominee has reached
                // their ceiling" from "this nominee has almost no support yet".
                return ['ok' => false, 'message' => "Bonus votes for this nominee are capped at "
                    . "{$c['cap']} — {$c['pct']}% of their {$c['base']} votes cast or bought, "
                    . "and {$c['used']} have already been granted."];
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

            // vote_count only. organic_vote_count stays untouched because it means one
            // thing — a free vote from a code-verified person — and every page that
            // prints "N of those were contributed" is reading the difference. It is a
            // DISCLOSURE now, not a second ranking figure: the index reads the tally.
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

        // REFUSED UP FRONT when the refund stamp has nowhere to go.
        //
        // `refunded_at` arrived in a migration, so an unmigrated database does not have
        // it — the same gap that broke paid voting. The fix here is the OPPOSITE of the
        // one applied there. Filtering the column out would let this method delete the
        // supporter's votes and then quietly skip the stamp, leaving a donation that
        // reads as live and can be redeemed a second time. Dropping a naming preference
        // is survivable; dropping the mark that says money was returned is not.
        //
        // Checked BEFORE the transaction, so nothing is half-done, and reported as a
        // migration to run rather than as "Clawback failed." — which is all an operator
        // saw once the raw SQLSTATE had been swallowed by the catch below.
        $missing = OptionalColumn::missing('gates_donations', ['refunded_at']);
        if ($missing !== []) {
            return ['ok' => false, 'error' => OptionalColumn::explain('gates_donations', $missing),
                    'cleared' => 0, 'weight' => 0, 'nominees' => []];
        }

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

    /**
     * Rebuild a nominee's vote counters from surviving rows.
     *
     * The arithmetic lives in {@see VoteRecount} now, and this delegates rather than
     * keeping a second copy — it was private here and reachable only as a side effect of
     * clawing back a donation, which meant the platform had a repair for drifted vote
     * counters and no way to run it. A live cycle then released with the community half
     * reading zero for an entire category.
     */
    private static function recountNominee(int $nomineeId): void
    {
        VoteRecount::applyNominee($nomineeId);
    }
}
