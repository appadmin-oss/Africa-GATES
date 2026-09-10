<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CpiService, NomineeScoringService, RuleEngine, VoteService};
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
 * ── WHERE THE PUBLISHED WORDING AND THE CODE DIVERGE ───────────────────────
 *
 * In TWO places, and both are decisions rather than drift. They are pinned here with
 * literal numbers so that anybody holding the announcement next to the platform finds
 * the answer in one file instead of deriving it.
 *
 * 1 · THE DENOMINATOR IS THE EDITION'S, NOT THE CATEGORY'S. The published text says
 *     "in Category"; both maxima are the largest held by any nominee in the CYCLE.
 *     Confirmed by the operator. Per category, every category's leader takes the full
 *     450 however small their field, and `ResultRelease::overall()` then ranks those
 *     figures against each other. See {@see NomineeScoringService::editionScale()}.
 *
 * 2 · THE TWO TERMS SHARE ONE DENOMINATOR UNDER THE CURRENT DEFAULT. The published text
 *     names two — "Highest Unique Votes" for the 315 and "Highest Total Votes" for the
 *     135 — which is `CpiService::BASIS_REACH`, asserted below. The default is
 *     `BASIS_IDEAL`, where BOTH terms are divided by the largest TALLY in the edition,
 *     read as though every one of those votes had come from a separate person. The two
 *     agree exactly where every vote is one person one vote, and diverge to the extent
 *     that they are not.
 *
 *     It is not a small difference and it is not hypothetical. The same field, scored
 *     both ways, on the fixture below:
 *
 *                     unique / votes / panel      reach        ideal
 *         Leader          400 / 1000 / 10      1000 (450+550)  811 (261+550)
 *         Follower        100 /  500 /  6       476 (146+330)  429  (99+330)
 *
 *     Under `ideal` a full 450 requires a nominee's whole tally to be one vote each from
 *     as many people as the biggest tally in the edition — so "Community Points (Max
 *     450)" is a ceiling almost nobody reaches, where under `reach` the edition's reach
 *     leader collects it. WHICH ONE IS PUBLISHED IS THE OPERATOR'S CALL, not a matter of
 *     arithmetic. Both are asserted; `test_the_default_basis_is_pinned` is the single
 *     line that changes when that call is made.
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


    /** Score under one named basis, so a test says which rule it is asserting. */
    private function useBasis(string $basis): void
    {
        (new RuleEngine())->set('global', null, ['community_basis' => $basis]);
    }

    /**
     * The two-nominee field both bases are measured on. Chosen so no term is 0 or 1 and
     * so a swapped numerator or a shared denominator moves the answer:
     *
     *   Leader    400 people, 1,000 votes, panel 10
     *   Follower  100 people,   500 votes, panel  6
     *
     * The follower is the load-bearing row — a quarter of the reach and half the tally,
     * so the two community terms pull different amounts and cannot be exchanged.
     */
    private function field(): array
    {
        $this->nominee(7001, self::CAT_A, 'Leader',   1000);
        $this->backers(7001, self::CAT_A, 400, 'lead');
        $this->marks(7001, self::CAT_A, [1, 2], 10);

        $this->nominee(7002, self::CAT_A, 'Follower', 500);
        $this->backers(7002, self::CAT_A, 100, 'foll');
        $this->marks(7002, self::CAT_A, [1, 2], 6);

        return (new NomineeScoringService())->scoreCategory(self::CAT_A);
    }

    // ══ the formula as published ═════════════════════════════════════════════

    /**
     * THE PUBLISHED WORDING, TERM FOR TERM, UNDER THE BASIS THAT IMPLEMENTS IT.
     *
     *   Leader   315·(400/400) + 135·(1000/1000) + 550·(10/10) = 315 + 135 + 550 = 1000
     *   Follower 315·(100/400) + 135·( 500/1000) + 550·( 6/10) = 78.75 + 67.5 + 330
     *                                                          = 476.25 → 476
     *
     * This is `reach`, and it is NOT the current default — see the class docblock and
     * `test_the_default_basis_is_pinned`. Asserted regardless, because it is the rule the
     * platform publishes and a nominee who checks their own score against the
     * announcement is doing this arithmetic.
     */
    public function test_the_published_formula_is_the_arithmetic_of_the_reach_basis(): void
    {
        $this->useBasis(CpiService::BASIS_REACH);
        $out = $this->field();

        // The denominators, named on the row so a nominee can check their own score.
        $this->assertSame(400,  $out[7002]['cohort_max_unique'], 'highest unique voters');
        $this->assertSame(1000, $out[7002]['cohort_max'],        'highest total votes');
        $this->assertSame(100,  $out[7002]['unique_voters']);

        // A perfect card is exactly the stated maximum, and not a point more.
        $this->assertSame(450,  $out[7001]['community_points'], '315 + 135');
        $this->assertSame(550,  $out[7001]['judge_points'],     '550 × 10/10');
        $this->assertSame(1000, $out[7001]['cpi_score'],        'Total Possible = 1,000 Points');

        // And the row where every term is a genuine fraction. 78.75 + 67.5 is summed and
        // rounded ONCE, as one community component: 146, not 79 + 68.
        $this->assertSame(146, $out[7002]['community_points'], '78.75 + 67.5, rounded once');
        $this->assertSame(330, $out[7002]['judge_points'],     '550 × 6/10');
        $this->assertSame(476, $out[7002]['cpi_score'],        '146.25 + 330');
    }

    /**
     * UNDER `reach` THE TWO COMMUNITY TERMS HAVE THEIR OWN DENOMINATORS.
     *
     * "Highest Unique Votes" and "Highest Total Votes" are two different figures and may
     * be held by two different nominees.
     *
     *   Reach leader : 300 people on 100 votes → 315·(300/300) + 135·(100/900) = 330
     *   Tally leader :  10 people on 900 votes → 315·( 10/300) + 135·(900/900) = 145.5 → 146
     *
     * Which is the point of the 70/30 split: 900 votes from ten people loses to 100 votes
     * from three hundred.
     */
    public function test_under_reach_each_term_has_its_own_maximum(): void
    {
        $this->useBasis(CpiService::BASIS_REACH);

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

    // ══ and the basis that actually runs ═════════════════════════════════════

    /**
     * THE DEFAULT SCORES THE SAME FIELD LOWER, AND THIS IS BY HOW MUCH.
     *
     * `ideal` divides BOTH terms by the largest tally in the edition — 1,000 here — so
     * the people term is measured against a number of votes rather than a number of
     * people:
     *
     *   Leader   0.70·(400/1000) + 0.30·(1000/1000) = 0.58   → 261 + 550 = 811
     *   Follower 0.70·(100/1000) + 0.30·( 500/1000) = 0.22   →  99 + 330 = 429
     *
     * The leader holds every maximum in the edition and still takes 261 of 450, because
     * six hundred of their thousand votes were not separate people. That is the intended
     * behaviour of this basis and it is the whole difference from the published wording.
     */
    public function test_the_default_basis_measures_both_terms_against_the_tally(): void
    {
        $out = $this->field();      // no useBasis(): whatever the platform defaults to

        $this->assertSame(261, $out[7001]['community_points'],
            'the edition leader does not reach the full community half under `ideal`');
        $this->assertSame(811, $out[7001]['cpi_score']);
        $this->assertSame(99,  $out[7002]['community_points']);
        $this->assertSame(429, $out[7002]['cpi_score']);
    }

    /**
     * WHICH BASIS IS IN FORCE, PINNED ON ONE LINE.
     *
     * The published formula describes `reach`; the platform runs `ideal`. Both are
     * asserted above, so whichever the operator settles on, the arithmetic for it is
     * already covered — this is the only assertion that moves, and it must move
     * deliberately rather than because somebody edited a default.
     */
    public function test_the_default_basis_is_pinned(): void
    {
        $this->assertSame(CpiService::BASIS_IDEAL, RuleEngine::DEFAULTS['community_basis'],
            'the community basis in force; the published formula describes BASIS_REACH');
        $this->assertSame(CpiService::SCALE_LINEAR, RuleEngine::DEFAULTS['judge_scale'],
            '550 × average/10, straight — the only form a nominee can check');
        $this->assertSame(CpiService::SCOPE_EDITION, RuleEngine::DEFAULTS['community_scope'],
            'the denominator is the cycle, not the category');
    }

    // ══ the parts both bases agree on ════════════════════════════════════════

    /**
     * THE PANEL HALF IS THE MARK, STRAIGHT, UNDER EITHER BASIS.
     *
     * 550 × (average ÷ 10), with no floor and no exponent. The community basis does not
     * touch it, which is worth an assertion because the two halves are summed by one
     * call and a regression in either surfaces as "the total is wrong".
     */
    public function test_the_panel_half_is_550_times_the_mark_over_ten(): void
    {
        foreach ([CpiService::BASIS_REACH, CpiService::BASIS_IDEAL] as $basis) {
            $this->useBasis($basis);
            $out = $this->field();

            $this->assertSame(550, $out[7001]['judge_points'], "550 × 10/10 under {$basis}");
            $this->assertSame(330, $out[7002]['judge_points'], "550 × 6/10 under {$basis}");

            DB::table('gates_judge_criteria_scores')->delete();
            DB::table('gates_votes')->delete();
            DB::table('gates_nominees')->whereIn('id', [7001, 7002])->delete();
        }
    }

    /**
     * THE DENOMINATOR IS THE EDITION'S, WHICH IS THE FIRST DIVERGENCE FROM THE WORDING.
     *
     * Beta's leader has more support than anybody in Alpha, and Alpha's only nominee is
     * scored against Beta's figures. Per category Alpha's nominee would lead a field of
     * one and take the whole 450; per edition, under the default `ideal` basis with a
     * ceiling of 2,000, they take 0.70·(50/2000) + 0.30·(200/2000) = 0.0475 → 21.
     *
     * If this ever fails because somebody set `community_scope = category`, that setting
     * exists only to reproduce an already-announced cycle to the digit. It is not an
     * alternative rule and it must not be the default.
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
        $this->assertSame(21, $out[7021]['community_points'],
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
     * honest reading of "550 × (Average Mark ÷ 10)" with no average is zero — not
     * "renormalise the community half up to 1,000", which would put an unjudged popular
     * nominee top of the board on turnout alone. `provisional` is what stops the
     * understatement being read as a verdict.
     *
     * Under the default basis the community half here is 0.70·(100/1000) + 0.30·1 = 0.37
     * → 167, and the total is that and nothing else.
     */
    public function test_an_unfinished_panel_scores_zero_and_says_so(): void
    {
        $this->nominee(7031, self::CAT_A, 'Unjudged', 1000);
        $this->backers(7031, self::CAT_A, 100, 'unj');

        $row = (new NomineeScoringService())->scoreCategory(self::CAT_A)[7031];

        $this->assertSame(167, $row['community_points'], 'the community half, alone');
        $this->assertSame(0,   $row['judge_points']);
        $this->assertSame(167, $row['cpi_score'], 'and not renormalised upward');
        $this->assertTrue($row['provisional'], 'a community-only figure is not a CPI');
    }
}
