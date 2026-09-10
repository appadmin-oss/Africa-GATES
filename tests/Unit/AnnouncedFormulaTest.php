<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CpiService, NomineeScoringService, RuleEngine, VoteService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE PUBLISHED FORMULA, ARITHMETIC FOR ARITHMETIC.
 *
 * This is the formula Africa GATES publishes, in the operator's own words:
 *
 *     Total Possible = 1,000 Points
 *
 *     1. COMMUNITY VOTE (450 points)
 *        Unique Voters (315 pts): 315 × (Your Unique Voters ÷ Highest Total Votes in edition)
 *        Total Votes   (135 pts): 135 × (Your Total Votes   ÷ Highest Total Votes in edition)
 *
 *     2. JUDGING PANEL (550 points)
 *        Panel Mark (550 pts): 550 × (Average Mark ÷ 10)
 *
 *     Final Score = Community Points (max 450) + Panel Points (max 550)
 *
 * ONE yardstick for both community terms — the highest TOTAL VOTES in the award programme
 * edition — and the edition, not the category. That is `CpiService::BASIS_IDEAL` with
 * `SCOPE_EDITION`, which is what the platform defaults to, so the published formula and the
 * running arithmetic agree term for term. Asserted here against literal point totals worked
 * out by hand, not `assertSame($a, $b)` where both sides come from the same service.
 *
 * ── WHY THIS FILE EXISTS SEPARATELY FROM THE OTHER SCORING TESTS ────────────
 *
 * Everything else in the scoring suite tests a MECHANISM — the edition scale, the reach
 * count, a basis kept for reproducing an announced cycle. Each is right about its own part
 * and none of them states the whole sum the way it is published, so "is the published
 * formula what runs?" could not be answered by reading one file. It was asked twice, and
 * answering it the first time took a day.
 *
 * ── AND THE ONE PLACE THE LITERAL FORMULA IS NOT FOLLOWED ───────────────────
 *
 * Where NOT ONE nominee anywhere in the edition has a countable vote row, the 315 term
 * would be `315 × (0 ÷ max)` for everybody and the whole field would be capped at 135 —
 * silently, with the order intact, which is what makes that shape of fault survive. So the
 * tally takes the whole 450 instead: it is what could actually be measured, stated as the
 * whole of it. That is an all-or-nothing fallback for the edition, not a per-nominee
 * softening, and `test_an_edition_with_no_countable_rows_falls_back_to_the_tally` pins it
 * below. It is the only deviation, and it is deliberate — see
 * {@see \AfricaGates\Services\CpiService::idealPart()}.
 *
 * `reach` — the older basis, where the 315 has its own denominator (the most PEOPLE any
 * nominee has) — is kept so a cycle announced under it stays reproducible to the digit, and
 * is asserted here too. It is NOT the published rule; on the fixture below it pays a leader
 * 450 where the published rule pays 261.
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

    // ══ the older basis, kept for reproducing an announced cycle ════════════

    /**
     * `reach` GIVES THE 315 ITS OWN DENOMINATOR, AND IS NOT THE PUBLISHED RULE.
     *
     *   Leader   315·(400/400) + 135·(1000/1000) + 550·(10/10) = 315 + 135 + 550 = 1000
     *   Follower 315·(100/400) + 135·( 500/1000) + 550·( 6/10) = 78.75 + 67.5 + 330
     *                                                          = 476.25 → 476
     *
     * Kept as a setting so a cycle announced under it stays reproducible to the digit, and
     * asserted so that reproduction is checked rather than assumed. Note what it pays the
     * leader — the full 450 — where the published rule pays 261: leading on people is worth
     * the whole half here, because the denominator is the most PEOPLE anybody has.
     */
    public function test_the_older_reach_basis_stays_reproducible(): void
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

    // ══ the published formula ════════════════════════════════════════════════

    /**
     * THE PUBLISHED FORMULA, TERM FOR TERM, ON THE DEFAULT BASIS.
     *
     * `ideal` divides BOTH terms by the largest tally in the edition — 1,000 here — so
     * the people term is measured against a number of votes rather than a number of
     * people:
     *
     *   Leader   0.70·(400/1000) + 0.30·(1000/1000) = 0.58   → 261 + 550 = 811
     *   Follower 0.70·(100/1000) + 0.30·( 500/1000) = 0.22   →  99 + 330 = 429
     *
     * The leader holds every maximum in the edition and still takes 261 of 450, because six
     * hundred of their thousand votes were not separate people. That is the point of one
     * yardstick: a full 450 means as many separate supporters as the biggest tally anybody
     * managed, and nothing softer.
     */
    public function test_the_published_formula_is_the_arithmetic_that_runs(): void
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
     * These three ARE the published formula: one yardstick for both community terms, that
     * yardstick the edition's highest total votes, and the panel mark straight out of ten.
     * A default edited without the announcement being reissued is the §19 fault on the
     * arithmetic that decides an award, so it must move deliberately or not at all.
     */
    public function test_the_default_basis_is_pinned(): void
    {
        $this->assertSame(CpiService::BASIS_IDEAL, RuleEngine::DEFAULTS['community_basis'],
            'both community terms against one yardstick, as published');
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

    /**
     * THE ONE DEVIATION: AN EDITION WITH TALLIES AND NO BALLOT ROWS.
     *
     * Read literally, `315 × (unique ÷ highest total votes)` pays every nominee in such an
     * edition zero on the 315 — an imported tally, a restored backup, a purged cycle — and
     * caps the whole field at 135 of 450. The ORDER survives, which is precisely what makes
     * that shape of fault last: nothing looks wrong, no screen says anything, and a cycle
     * scored out of 135 still reads like one scored out of 450.
     *
     * So where reach cannot be measured ANYWHERE in the edition, the tally takes the whole
     * community half. Two nominees on 400 and 100 of a 400-vote maximum, with no vote rows
     * at all: 450 and 113, not 135 and 34.
     *
     * All-or-nothing for the edition, deliberately — one countable row anywhere switches the
     * people term on for every nominee at once. A single category missing its rows does NOT
     * reach this, and is flagged per nominee as `reach_unmeasured` instead.
     */
    public function test_an_edition_with_no_countable_rows_falls_back_to_the_tally(): void
    {
        // Tallies but no `gates_votes` rows — nothing for VoterReach to count.
        $this->nominee(7041, self::CAT_A, 'Imported leader', 400);
        $this->nominee(7042, self::CAT_A, 'Imported second', 100);

        $out = (new NomineeScoringService())->scoreCategory(self::CAT_A);

        $this->assertSame(0, $out[7041]['cohort_max_unique'],
            'precondition: the question cannot be asked in this edition');
        $this->assertSame(450, $out[7041]['community_points'],
            'the tally takes the whole half, rather than the field being capped at 135');
        $this->assertSame(113, $out[7042]['community_points'], '450 × 100/400');

        // And NOT flagged, which is the half of this that is easy to get backwards.
        // `reach_unmeasured` needs `cohort_max_unique > 0`: it exists for the nominee whose
        // rows are missing while the REST of the edition has them, because that one loses
        // 315 points silently and the fallback above never fires for them. Here the
        // fallback did fire, the whole half was paid on the tally, and there is nothing
        // understated to warn about.
        $this->assertFalse($out[7041]['reach_unmeasured'],
            'the fallback already paid the half; a warning here would name a loss nobody took');
    }
}
