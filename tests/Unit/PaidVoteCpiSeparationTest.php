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
    }

    public function test_the_index_normalises_over_every_vote_not_the_free_ones(): void
    {
        $this->seedCohort();

        $scores = (new NomineeScoringService())->scoreCategory(10);

        // No judges → community-only. cohortMax = 102 (the largest TALLY), weight 0.45.
        //   A: 0.45 × (10/102) × 1000 =  44
        //   B: 0.45 × (102/102) × 1000 = 450
        $this->assertSame(44,  $scores[1]['cpi_score']);
        $this->assertSame(450, $scores[2]['cpi_score']);

        // The denominator moved with the numerator. Scaling a total against an organic
        // maximum would let a nominee exceed 100% of the cohort and take more than the
        // whole community weight — the two have to be the same measure or a share is not
        // a share.
        $this->assertSame(102, $scores[1]['cohort_max']);
        $this->assertSame(102, $scores[2]['cohort_max']);
    }

    /**
     * SPENDING CAN OVERTAKE FREE SUPPORT, AND NOTHING STOPS IT.
     *
     * This is the assertion the file previously made in reverse, and it is stated as a
     * property rather than left as a side effect: purchases are capped per ORDER, not
     * against a nominee's organic base, so there is no ceiling at which money stops
     * mattering. Anybody changing that later should have to change this line, and read
     * the header above before they do.
     */
    public function test_purchased_votes_can_overtake_a_nominee_with_more_free_support(): void
    {
        $this->seedCohort();

        $scores = (new NomineeScoringService())->scoreCategory(10);

        $this->assertGreaterThan($scores[1]['cpi_score'], $scores[2]['cpi_score'],
            'B outspent A five to one on the tally and did not out-rank them — the index '
            . 'is reading a subset of the votes again');
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
