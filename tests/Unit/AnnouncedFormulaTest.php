<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{NomineeScoringService, VoteService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE PUBLISHED FORMULA, ARITHMETIC FOR ARITHMETIC.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS SEPARATELY FROM THE OTHER SCORING TESTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is the formula Africa GATES publishes, in the operator's own words:
 *
 *     Total Possible = 1,000 Points
 *
 *     1. COMMUNITY VOTE (450 points)
 *        Unique Voters Component (315 pts): 315 × (Your Unique Voters ÷ Highest Unique Votes)
 *        Total Votes Component   (135 pts): 135 × (Your Total Votes   ÷ Highest Total Votes)
 *
 *     2. JUDGING PANEL (550 points)
 *        Panel Mark Component (550 pts): 550 × (Average Mark ÷ 10)
 *
 *     Final Score = Community Points (max 450) + Panel Points (max 550)
 *
 * Everything else in the scoring suite tests a MECHANISM — the edition scale, the reach
 * count, a basis kept for reproducing an announced cycle. Each is right about its own
 * part and none of them states the whole sum the way it is published, so "is the published
 * formula what runs?" was a question nobody could answer by reading one file. It was asked,
 * and answering it took a day of reading.
 *
 * So this asserts the arithmetic in the published shape, against literal expected point
 * totals worked out by hand from the numbers seeded below. Not `assertSame($a, $b)` where
 * both sides come from the same service — that passes whatever the service does.
 *
 * ── THE ONE PLACE THE PUBLISHED WORDING AND THE CODE DIVERGE ────────────────
 *
 * The published text says "in Category" for both denominators. THE PLATFORM DOES NOT DO
 * THAT, deliberately: both maxima are the largest held by any nominee in the CYCLE.
 * Confirmed by the operator, and the reasoning is in
 * {@see NomineeScoringService::editionScale()} — per category, every category's leader
 * takes the full 450 however small their field, and `ResultRelease::overall()` then ranks
 * those figures against each other. `test_the_denominator_is_the_edition_not_the_category`
 * below pins the difference so the divergence stays a decision rather than a drift, and so
 * anybody comparing the announcement against the code finds the answer here.
 */
final class AnnouncedFormulaTest extends TestCase
{
    private const CYCLE = 70;
    private const CAT_A = 700;
    private const CAT_B = 701;   // a second category, so "edition" and "category" differ

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => self::CYCLE, 'programme_id' => 0, 'year' => 2026, 'status' => 'judging',
        ]);
        foreach ([self::CAT_A => 'Alpha', self::CAT_B => 'Beta'] as $id => $t) {
            DB::table('gates_award_categories')->insertOrIgnore([
                'id' => $id, 'cycle_id' => self::CYCLE, 'slug' => strtolower($t), 'title' => $t,
            ]);
        }
        foreach ([1, 2] as $j) {
            DB::table('gates_judges')->insertOrIgnore([
                'id' => $j, 'name' => 'Judge ' . $j, 'email' => 'af' . $j . '@x.test', 'is_active' => 1,
            ]);
        }
    }

    private function nominee(int $id, int $cat, string $name, int $votes): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => $cat, 'name' => $name, 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => $votes, 'organic_vote_count' => $votes,
        ]);
    }

    /**
     * $n distinct verified people behind a nominee — real rows, because reach is counted
     * from `gates_votes` and never from a stored counter ({@see \AfricaGates\Services\VoterReach}).
     */
    private function backers(int $nominee, int $cat, int $n, string $tag): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => $nominee, 'category_id' => $cat, 'vote_type' => 'standard',
                'weight' => 1, 'voter_email_hash' => VoteService::voterHash($tag . $i . '@x.test'),
            ]);
        }
    }

    /**
     * A COMPLETE scorecard from each judge at a whole mark.
     *
     * Whole marks only, and that is not tidiness: `gates_judge_criteria_scores.score` is a
     * TINYINT, so MySQL rounds a fractional mark and SQLite stores it verbatim — a fixture
     * built on 7.9 asserts a difference the production database cannot hold.
     */
    private function marks(int $nominee, int $cat, array $judges, int $score): void
    {
        $crit = array_map('intval',
            DB::table('gates_judge_criteria')->where('is_active', 1)->pluck('id')->all());
        self::assertNotEmpty($crit, 'the rubric must have active criteria or no mark counts');
        foreach ($judges as $j) {
            foreach ($crit as $cid) {
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $cat,
                    'criterion_id' => $cid, 'score' => $score,
                ]);
            }
        }
    }

    // ══ the published sum ════════════════════════════════════════════════════

    /**
     * EVERY TERM, WITH THE ARITHMETIC DONE BY HAND.
     *
     * Two nominees, chosen so no term is 0 or 1 and so a swapped numerator or a shared
     * denominator would change the answer:
     *
     *   Leader   400 people, 1,000 votes, panel 10 → 315·(400/400) + 135·(1000/1000) + 550·(10/10)
     *                                              = 315 + 135 + 550 = 1000
     *   Follower 100 people,   500 votes, panel  6 → 315·(100/400)  + 135·(500/1000)  + 550·(6/10)
     *                                              = 78.75 + 67.5 + 330 = 476.25 → 476
     *
     * The follower is the load-bearing row: a quarter of the reach and half the tally, so
     * the two community terms are pulling different amounts and cannot be swapped without
     * the total moving.
     */
    public function test_the_published_formula_is_the_arithmetic_that_runs(): void
    {
        $this->nominee(7001, self::CAT_A, 'Leader',   1000);
        $this->backers(7001, self::CAT_A, 400, 'lead');
        $this->marks(7001, self::CAT_A, [1, 2], 10);

        $this->nominee(7002, self::CAT_A, 'Follower', 500);
        $this->backers(7002, self::CAT_A, 100, 'foll');
        $this->marks(7002, self::CAT_A, [1, 2], 6);

        $out = (new NomineeScoringService())->scoreCategory(self::CAT_A);

        // ── the denominators, named on the row so a nominee can check their own score ──
        $this->assertSame(400,  $out[7002]['cohort_max_unique'], 'highest unique voters');
        $this->assertSame(1000, $out[7002]['cohort_max'],        'highest total votes');
        $this->assertSame(100,  $out[7002]['unique_voters']);

        // ── a perfect card is exactly the stated maximum, and not a point more ──
        $this->assertSame(450,  $out[7001]['community_points'], '315 + 135');
        $this->assertSame(550,  $out[7001]['judge_points'],     '550 × 10/10');
        $this->assertSame(1000, $out[7001]['cpi_score'],        'Total Possible = 1,000 Points');

        // ── and the row where every term is a genuine fraction ──
        // 315·(100/400) = 78.75 and 135·(500/1000) = 67.5; the two are summed and rounded
        // ONCE, as one community component, which is 146 and not 79 + 68.
        $this->assertSame(146, $out[7002]['community_points'], '78.75 + 67.5, rounded once');
        $this->assertSame(330, $out[7002]['judge_points'],     '550 × 6/10');
        $this->assertSame(476, $out[7002]['cpi_score'],        '146.25 + 330');
    }

    /**
     * THE TWO COMMUNITY TERMS HAVE THEIR OWN DENOMINATORS.
     *
     * "Highest Unique Votes" and "Highest Total Votes" are two different figures and may be
     * held by two different nominees. Scoring both terms against one maximum is the kind of
     * simplification that reads as tidier and is wrong: here the reach leader is not the
     * tally leader, so a single denominator would move both rows.
     *
     * Reach leader  : 300 people on 100 votes  (many people, one vote each)
     * Tally leader  :  10 people on 900 votes  (few people, bought or bonused)
     *
     *   Reach leader → 315·(300/300) + 135·(100/900) + 0 = 315 + 15 = 330
     *   Tally leader → 315·(10/300)  + 135·(900/900) + 0 = 10.5 + 135 = 145.5 → 146
     *
     * Which is the whole point of the 70/30 split: 3,000 votes from ten people loses to 100
     * votes from three hundred.
     */
    public function test_reach_and_tally_are_measured_against_their_own_maxima(): void
    {
        $this->nominee(7011, self::CAT_A, 'Broad',  100);
        $this->backers(7011, self::CAT_A, 300, 'broad');
        $this->nominee(7012, self::CAT_A, 'Bought', 900);
        $this->backers(7012, self::CAT_A, 10, 'bought');

        $out = (new NomineeScoringService())->scoreCategory(self::CAT_A);

        $this->assertSame(300, $out[7011]['cohort_max_unique'], 'the reach leader sets the 315');
        $this->assertSame(900, $out[7011]['cohort_max'],        'the tally leader sets the 135');

        $this->assertSame(330, $out[7011]['community_points'],
            'three hundred supporters on a hundred votes');
        $this->assertSame(146, $out[7012]['community_points'],
            'nine hundred votes from ten people is worth less than a hundred from three hundred');
    }

    /**
     * THE DENOMINATOR IS THE EDITION'S, WHICH IS WHERE THE PUBLISHED WORDING DIVERGES.
     *
     * The announcement says "in Category". It is the cycle. This is the assertion that
     * proves the difference is real and not a reading of the prose: Beta's leader has more
     * support than anybody in Alpha, and Alpha's nominee is scored against Beta's figures.
     *
     * Per category, Alpha's leader would lead a field of one and take the whole 450. Per
     * edition they take 315·(50/500) + 135·(200/2000) = 31.5 + 13.5 = 45.
     *
     * If this test ever fails because somebody set `community_scope = category`, that is a
     * setting kept only to reproduce an already-announced cycle to the digit — it is not
     * an alternative rule, and it must not be the default. See RuleEngine::DEFAULTS.
     */
    public function test_the_denominator_is_the_edition_not_the_category(): void
    {
        $this->nominee(7021, self::CAT_A, 'Alpha sole', 200);
        $this->backers(7021, self::CAT_A, 50, 'alpha');

        $this->nominee(7022, self::CAT_B, 'Beta giant', 2000);
        $this->backers(7022, self::CAT_B, 500, 'beta');

        $out = (new NomineeScoringService())->scoreCategory(self::CAT_A);

        $this->assertSame(500,  $out[7021]['cohort_max_unique'],
            "Beta's reach, read while drawing Alpha");
        $this->assertSame(2000, $out[7021]['cohort_max'], "Beta's tally");
        $this->assertSame('edition', $out[7021]['cohort_scope']);
        $this->assertSame(45, $out[7021]['community_points'],
            'leading a category of one is not worth the community half');

        // And the scale-setter is NAMED, because they are on another page entirely and a
        // screen that scanned its own rows for the denominator would report it as nobody's.
        $this->assertSame(7022, (int) $out[7021]['cohort_max_by']['id']);
        $this->assertSame(7022, (int) $out[7021]['cohort_max_unique_by']['id']);
    }

    /**
     * AN UNJUDGED NOMINEE SCORES THE COMMUNITY HALF AND NOTHING ELSE.
     *
     * The published formula has no clause for a panel that has not finished, and the
     * honest reading of "550 × (Average Mark ÷ 10)" when there is no average is zero —
     * not "renormalise the community half up to 1,000", which would put an unjudged
     * popular nominee top of the board on turnout alone. `provisional` is what stops the
     * understatement being read as a verdict.
     */
    public function test_an_unfinished_panel_scores_zero_and_says_so(): void
    {
        $this->nominee(7031, self::CAT_A, 'Unjudged', 1000);
        $this->backers(7031, self::CAT_A, 100, 'unj');

        $row = (new NomineeScoringService())->scoreCategory(self::CAT_A)[7031];

        $this->assertSame(450, $row['community_points'], 'the whole community half, alone');
        $this->assertSame(0,   $row['judge_points']);
        $this->assertSame(450, $row['cpi_score'], 'and not renormalised to 1,000');
        $this->assertTrue($row['provisional'], 'a community-only figure is not a CPI');
    }
}
