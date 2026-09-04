<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{JudgeRubric, ResultRelease, VoteRecount};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A CATEGORY WHERE THE COMMUNITY HALF IS WORTH NOTHING, AND NOTHING SAID SO.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS ON THE SCREEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A live cycle, four nominees, votes of 1,536 · 1,955 · 126 · 398 — and an organic count of
 * ZERO on every one of them. So every community share was 0/1, every community half was
 * nought, and the heading still read "45% community · 55% judges" while the panel decided
 * the award on its own. The second-most-voted nominee placed second to the best-judged one.
 *
 * The screen printed, in the column where the explanation goes, `0% of ’s 0` — a broken
 * sentence, because with nobody holding an organic vote there is no scale-setter to name.
 * The operator found this by reading the numbers and called it cheating, which is the right
 * word for a page that states a weighting it is not applying.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT IS USUALLY NOT FRAUD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `organic_vote_count` is a cache of `gates_votes`. Every ordinary path maintains it. Votes
 * that arrive any other way — an import, a restore, a row written before the column existed
 * — leave the two disagreeing, and the column's own migration backfills only in the branch
 * that ADDS it, so on a database whose base schema already declared it the backfill has
 * never run.
 *
 * The repair for that already existed, privately, inside `BonusVoteService`, reachable only
 * as a side effect of clawing back a donation.
 */
final class CommunityHalfDarkTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'dark-' . bin2hex(random_bytes(3)), 'title' => 'Academic Excellence',
            'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'judging',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'academic', 'title' => 'Academic Excellence',
            'sort_order' => 1,
        ]);
    }

    /** `vote_count` set, `organic_vote_count` left at zero — the live shape exactly. */
    private function nominee(string $name, int $votes, int $organic = 0): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
            'vote_count' => $votes, 'organic_vote_count' => $organic,
        ]);
    }

    private function panel(int $nominee, float $mark): void
    {
        static $n = 0;
        for ($k = 0; $k < 2; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Judge ' . (++$n), 'is_active' => 1,
                'email' => 'j' . $n . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $this->categoryId,
                    'criterion_id' => (int) $c->id, 'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    /** The live cycle, reproduced. */
    private function theLiveCycle(): void
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 1536);
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 1955);
        $c = $this->nominee('Mrs Makinde Adejumoke', 126);
        $d = $this->nominee('Lawal Sade Olukemi', 398);
        $this->panel($a, 8.0);
        $this->panel($b, 7.9);
        $this->panel($c, 7.6);
        $this->panel($d, 7.6);
    }

    // ══ the finding ══════════════════════════════════════════════════════════

    /**
     * THE THING THE SCREEN HAD TO SAY AND DID NOT.
     *
     * Not "cohort_max is zero", which a template could work out and which says nothing about
     * the consequence. The consequence is that the weighting printed at the top of the
     * category is not the weighting being applied to it.
     */
    public function test_a_category_with_no_organic_votes_is_reported_as_such(): void
    {
        $this->theLiveCycle();

        $c = ResultRelease::category($this->categoryId);

        $this->assertTrue($c['community_dark'],
            'the community half is zero for the whole field and the release says nothing');

        // And the consequence is real: every community half is nought while the rules still
        // say the community is worth 45% of the index.
        foreach ($c['rows'] as $r) {
            $this->assertSame(0, $r['community_points'],
                $r['name'] . ' has community points from nowhere');
        }
        $this->assertGreaterThan(0, $c['weights']['community'],
            'the fixture no longer shows a weighting being stated and not applied');
    }

    /**
     * THE ORDER IS THE JUDGES' ORDER, WHICH IS THE PART THAT LOOKS LIKE CHEATING.
     *
     * The nominee with 1,955 votes places second to the one with 1,536, purely because the
     * community half contributed nothing to either. Held so nobody "fixes" the symptom by
     * quietly reintroducing vote_count into the ranking — purchased support must never move
     * a rank, and that rule is not what went wrong here.
     */
    public function test_the_most_voted_nominee_loses_and_it_is_the_panel_that_did_it(): void
    {
        $this->theLiveCycle();

        $c = ResultRelease::category($this->categoryId);

        $this->assertSame('Dr. Adegboyega Aborode', $c['winner']['name']);
        $this->assertSame('Ajayi Temitope Oluwarotimi', $c['runner_up']['name']);
        $this->assertGreaterThan($c['winner']['organic'], $c['runner_up']['organic'] + 1955,
            'the fixture no longer has the more-supported nominee placing second');
        $this->assertTrue($c['community_dark']);
    }

    /** With organic votes present it is an ordinary category and says nothing. */
    public function test_a_category_with_organic_votes_is_not_flagged(): void
    {
        $a = $this->nominee('Dr. Adegboyega Aborode', 1536, 1536);
        $b = $this->nominee('Ajayi Temitope Oluwarotimi', 1955, 1955);
        $this->panel($a, 8.0);
        $this->panel($b, 7.9);

        $c = ResultRelease::category($this->categoryId);

        $this->assertFalse($c['community_dark']);
        // And with the community half working, the more-supported nominee takes it.
        $this->assertSame('Ajayi Temitope Oluwarotimi', $c['winner']['name'],
            'the community half is on and still changed nothing');
    }

    // ══ the repair ═══════════════════════════════════════════════════════════

    /**
     * The counters are a CACHE of `gates_votes`, and the recount makes them agree.
     *
     * It cannot invent support: a nominee with no ballots comes out with no votes, however
     * large the stored number was. That is the difference between a repair and a thumb on
     * the scale, and it is why this writes the ledger's answer rather than reconciling
     * towards the stored one.
     */
    public function test_the_recount_rebuilds_the_counters_from_the_ballots(): void
    {
        $n = $this->nominee('Dr. Adegboyega Aborode', 1536, 0);

        // Three real organic ballots and one purchased pack of five.
        foreach ([1, 2, 3] as $i) {
            DB::table('gates_votes')->insert([
                'nominee_id' => $n, 'category_id' => $this->categoryId,
                'voter_email_hash' => 'h' . $i, 'vote_type' => 'standard', 'weight' => 1,
                'voted_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
        DB::table('gates_votes')->insert([
            'nominee_id' => $n, 'category_id' => $this->categoryId,
            'voter_email_hash' => 'paid', 'vote_type' => 'bonus', 'weight' => 5,
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->assertTrue(VoteRecount::categoryDrifts($this->categoryId),
            'a stored 1,536 against four ballots is not being reported as a discrepancy');

        $r = VoteRecount::category($this->categoryId);
        $row = DB::table('gates_nominees')->find($n);

        $this->assertCount(1, $r['changed']);
        // WEIGHT, not rows: the purchased pack is one row carrying five votes.
        $this->assertSame(8, (int) $row->vote_count);
        $this->assertSame(3, (int) $row->organic_vote_count,
            'purchased votes were counted as community support');
        $this->assertFalse(VoteRecount::categoryDrifts($this->categoryId));
    }

    /**
     * AND IT CANNOT MANUFACTURE VOTES.
     *
     * The honest outcome of recounting a category whose ballots really are all non-organic
     * is that nothing changes — and the operator needs to be told that, because it means the
     * missing community half is a fact about the votes rather than a drifted counter.
     */
    public function test_a_recount_with_no_ballots_takes_the_counters_to_nothing(): void
    {
        $n = $this->nominee('Dr. Adegboyega Aborode', 1536, 0);

        VoteRecount::category($this->categoryId);
        $row = DB::table('gates_nominees')->find($n);

        $this->assertSame(0, (int) $row->vote_count,
            'a stored total with no ballots behind it survived a recount');
        $this->assertSame(0, (int) $row->organic_vote_count);
    }

    /** A category whose counters already agree reports no change, and writes nothing. */
    public function test_a_category_that_agrees_reports_no_change(): void
    {
        $this->nominee('Dr. Adegboyega Aborode', 0, 0);

        $this->assertFalse(VoteRecount::categoryDrifts($this->categoryId));
        $this->assertSame([], VoteRecount::category($this->categoryId)['changed']);
    }
}
