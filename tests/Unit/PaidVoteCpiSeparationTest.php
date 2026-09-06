<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\NomineeScoringService;

/**
 * EVERY VOTE COUNTS, WHATEVER IT COST — AND WHY THIS FILE NO LONGER SAYS THE OPPOSITE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE USED TO ASSERT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Integrity contract: purchased votes must NOT move the Cultural Power Index or the
 * cohort normalisation." The community half was normalised over `organic_vote_count`
 * alone, `vote_count` was a display total, and this file existed to hold that line.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT CHANGED, BECAUSE OTHERWISE IT READS AS A SLIP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A deployment may switch free voting off entirely — `paid_voting_disable_free`, read by
 * {@see \AfricaGates\Services\PaidVoteService::freeVotingDisabled()}, which makes
 * `castVote` answer 403. And `VoteService::castVote()` is the ONLY code path in this
 * platform that increments `organic_vote_count`.
 *
 * So on such a deployment that column is permanently zero, for every nominee, forever.
 * A community half normalised over it is therefore permanently zero too — and the panel
 * silently decides 100% of every award while every page states 45/55. The old rule did
 * not protect a community vote there. It deleted one, and it did so invisibly: a live
 * cycle ran with nominees on 1,536, 1,955, 126 and 398 votes, organic zero on all four,
 * and the operator found it by reading the numbers off the screen and calling it cheating.
 *
 * The operator was shown the alternatives — count them capped against organic support,
 * count them at reduced weight, or count them as a separate published component — and
 * chose to count them in full with no ceiling.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE CONSEQUENCE, STATED RATHER THAN INFERRED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Purchases are not bounded against a nominee's genuine support: `PaidVoteService` caps
 * the size of ONE order, not how many orders a campaign places. A sufficiently funded
 * nominee can therefore take a category on spending alone, and the tests below assert
 * exactly that rather than leaving it to be discovered. Every public surface — the
 * integrity centre, the philosophy page, the help centre, the ballot and the result page
 * — says so in the same words.
 *
 * `organic_vote_count` is still written, still returned and still shown beside the total
 * everywhere the total appears, so a reader can always see how much of a tally was bought.
 * It simply decides nothing.
 */
class PaidVoteCpiSeparationTest extends TestCase
{
    private function seedCohort(): void
    {
        // Small fixtures: the community half is scaled by how deep a category's support
        // was (CpiService::depth), so the full-credit mark is set to 1 here and depth
        // becomes 1.0. The discount has its own tests in CpiServiceTest.
        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['community_full_credit_votes' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'),
            'status' => 'voting', 'voting_close' => Carbon::now()->addDays(7)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 10, 'cycle_id' => 1, 'slug' => 'cat-10', 'title' => 'Category',
        ]);
        // A: 10 free votes and nothing bought.  B: 2 free and 100 bought (tally 102).
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 10, 'name' => 'A', 'country_code' => 'NG',
             'status' => 'approved', 'vote_count' => 10,  'organic_vote_count' => 10],
            ['id' => 2, 'category_id' => 10, 'name' => 'B', 'country_code' => 'NG',
             'status' => 'approved', 'vote_count' => 102, 'organic_vote_count' => 2],
        ]);

        // ── AND THE ROWS BEHIND THOSE TALLIES ────────────────────────────────
        //
        // The tally columns alone are not the input any more: seventy per cent of the
        // community half is counted from `gates_votes`, one person at a time. A: ten
        // separate people. B: two people, plus ONE buyer whose single order carries a
        // hundred votes — which is what "2 free and 100 bought" actually looks like on
        // disk, and the shape the whole basis exists to tell apart.
        for ($i = 0; $i < 10; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 1, 'category_id' => 10, 'vote_type' => 'standard', 'weight' => 1,
                'voter_email_hash' => hash('sha256', "a{$i}@example.test"),
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 2, 'category_id' => 10, 'vote_type' => 'standard', 'weight' => 1,
                'voter_email_hash' => hash('sha256', "b{$i}@example.test"),
            ]);
        }
        DB::table('gates_donations')->insert([
            'id' => 900, 'donor_name' => 'One Backer', 'donor_email' => 'backer@example.test',
            'amount_naira' => 100000, 'status' => 'confirmed',
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 2, 'category_id' => 10, 'vote_type' => 'paid', 'weight' => 100,
            'donation_id' => 900,
            'voter_email_hash' => 'paidvote:900:' . bin2hex(random_bytes(6)),
        ]);
    }

    public function test_the_index_normalises_over_every_vote_not_the_free_ones(): void
    {
        $this->seedCohort();

        $scores = (new NomineeScoringService())->scoreCategory(10);

        // No judges → community-only. Two denominators now, both drawn from the field:
        // the largest TALLY (102, B's) and the largest number of PEOPLE (10, A's).
        //
        //   A:  0.7 × 10/10 + 0.3 × 10/102  = 0.7294 → 328
        //   B:  0.7 ×  3/10 + 0.3 × 102/102 = 0.5100 → 230
        //
        // B's hundred bought votes still count in full toward the thirty per cent — they
        // are real votes and this platform does not pretend otherwise. What they cannot
        // do any more is carry the other seventy, because one buyer is one person however
        // large the cheque. Under the old tally-only rule these came out 4 and 450: the
        // nominee with ten supporters scored four points, and the one with three scored
        // the maximum.
        $this->assertSame(328, $scores[1]['cpi_score']);
        $this->assertSame(230, $scores[2]['cpi_score']);
        $this->assertGreaterThan($scores[2]['cpi_score'], $scores[1]['cpi_score'],
            'a hundred votes from one buyer still outrank ten separate supporters');

        // The denominator moved with the numerator. Scaling a total against an organic
        // maximum would let a nominee exceed 100% of the cohort and take more than the
        // whole community weight — the two have to be the same measure or a share is not
        // a share.
        $this->assertSame(102, $scores[1]['cohort_max']);
        $this->assertSame(102, $scores[2]['cohort_max']);
    }

    /**
     * SPENDING STILL COUNTS IN FULL — AND IT BUYS THIRTY PER CENT, NOT ALL OF IT.
     *
     * This file used to assert the reverse of its own reverse: first that bought votes
     * could not win, then — correctly, after an operator decision — that they could, with
     * no ceiling at which money stopped mattering. Under the reach basis the honest
     * statement is neither, and it is worth being exact about because both halves of it
     * are load-bearing:
     *
     *   MONEY IS NOT NEUTERED.  Every bought vote counts, at full weight, toward the
     *                           thirty per cent that is the tally — and toward the
     *                           tiebreak and the eligibility filter, which are unchanged.
     *                           A hundred bought votes are worth 135 of B's 230 here.
     *                           Pretending otherwise would be a lie the receipts contradict.
     *   MONEY CANNOT BUY REACH. One buyer is one person however large the cheque, so the
     *                           other seventy per cent is beyond it. That is the whole
     *                           point, and it is why B loses to A above.
     *
     * So: with reach held equal, spending decides. That is the case this pins.
     */
    public function test_with_reach_held_equal_the_larger_tally_still_wins(): void
    {
        $this->seedCohort();

        // A third nominee with A's ten supporters exactly, and one bought order on top.
        // Same people, more votes — so the seventy per cent ties and the thirty decides.
        DB::table('gates_nominees')->insert([
            'id' => 3, 'category_id' => 10, 'name' => 'C', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 210, 'organic_vote_count' => 10,
        ]);
        for ($i = 0; $i < 10; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 3, 'category_id' => 10, 'vote_type' => 'standard', 'weight' => 1,
                'voter_email_hash' => hash('sha256', "c{$i}@example.test"),
            ]);
        }
        DB::table('gates_donations')->insert([
            'id' => 901, 'donor_name' => 'Backer Two', 'donor_email' => 'two@example.test',
            'amount_naira' => 200000, 'status' => 'confirmed',
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 3, 'category_id' => 10, 'vote_type' => 'paid', 'weight' => 200,
            'donation_id' => 901,
            'voter_email_hash' => 'paidvote:901:' . bin2hex(random_bytes(6)),
        ]);

        $scores = (new NomineeScoringService())->scoreCategory(10);

        $this->assertGreaterThan($scores[1]['cpi_score'], $scores[3]['cpi_score'],
            'C matched A on people and outspent them on the tally, and the thirty per cent '
            . 'did not move — money has been neutered rather than bounded');

        // And the size of the win is the thirty per cent, not more: C leads the tally
        // outright (135 of 135) where A holds 10/210 of it.
        $this->assertSame(11, $scores[3]['unique_voters'], 'C\'s buyer counted as more than one person');
        $this->assertSame(11, $scores[1]['cohort_max_unique'], 'the people denominator is not the field\'s best');
    }

    /**
     * BOTH FIGURES SURVIVE, BECAUSE THE DISCLOSURE DEPENDS ON THEM.
     *
     * Every public surface now prints the tally AND how much of it was organic. If the
     * scorer stopped returning the total, or the counter stopped being maintained, that
     * disclosure would quietly become "N votes cast, N of them organic" for a nominee who
     * had bought most of them — a truthful-looking sentence that is false.
     */
    public function test_the_organic_figure_is_still_kept_and_still_returned(): void
    {
        $this->seedCohort();

        $scores = (new NomineeScoringService())->scoreCategory(10);

        $this->assertSame(102, $scores[2]['vote_count']);
        $this->assertSame(2, (int) DB::table('gates_nominees')->where('id', 2)
            ->value('organic_vote_count'),
            'the organic counter is no longer maintained, so no page can say how much of '
            . 'a tally was bought');
    }
}
