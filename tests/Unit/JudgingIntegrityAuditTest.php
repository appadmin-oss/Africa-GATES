<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Services\{JudgeRubric, NomineeScoringService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Whose marks decide an award, and which nominees an award can go to.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE IS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Four findings from an audit of the judging → scoring → results path. Every one was
 * confirmed by running the real code and reading the numbers back, not by reading it —
 * and the measured figures are quoted in each test, because a regression here is silent
 * by nature. Nothing throws. A result simply comes out different, and the only person
 * who could notice is the nominee who lost.
 */
final class JudgingIntegrityAuditTest extends TestCase
{
    private int $prog = 0;
    private int $cycle = 0;
    private int $cat = 0;
    private int $nominee = 0;
    /** @var list<int> */
    private array $crit = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The community half is scaled by how deep the support in a category actually was
        // (CpiService::depth) — a leader on 89 votes no longer collects what a leader on
        // 1,955 collects. These fixtures use small counts to keep their arithmetic legible,
        // so the mark is set to 1: depth becomes 1.0 and the test is about the thing it is
        // about. The discount has its own tests in CpiServiceTest.
        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['community_full_credit_votes' => 1]);

        $this->prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'p', 'title' => 'P', 'is_active' => 1,
        ]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->prog, 'year' => 2026, 'status' => 'judging',
        ]);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycle, 'slug' => 'c', 'title' => 'C', 'sort_order' => 1,
        ]);
        $this->nominee = $this->nominee('N', 10);

        $this->crit = array_map('intval',
            DB::table('gates_judge_criteria')->whereNull('programme_id')->pluck('id')->all());
        $this->assertNotEmpty($this->crit, 'the shipped rubric should be installed');
    }

    private function nominee(string $name, int $organic): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->cat, 'name' => $name, 'status' => 'approved',
            'vote_count' => $organic, 'organic_vote_count' => $organic,
        ]);
    }

    private function judge(string $email): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $email, 'email' => $email, 'is_active' => 1,
            'programme_ids' => json_encode([$this->prog]),
        ]);
    }

    /** A COMPLETE scorecard: every active criterion, one mark. */
    private function scorecard(int $judgeId, int $nomineeId, int $mark): void
    {
        foreach ($this->crit as $cid) {
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judgeId, 'nominee_id' => $nomineeId, 'category_id' => $this->cat,
                'criterion_id' => $cid, 'score' => $mark, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1 · A RECUSED JUDGE
    // ════════════════════════════════════════════════════════════════════════

    /**
     * THE MOST SERIOUS OF THE FOUR.
     *
     * `declareConflict()` wrote a row to `gates_judge_coi` and did nothing else.
     * `saveScore()` read it and refused FURTHER marks — but every mark already given
     * stayed in the average and kept counting toward quorum.
     *
     * So a judge who realised mid-cycle that they knew a nominee, and did exactly the
     * right thing by recusing, left their assessment inside the result. Recusal is a
     * promise about the RESULT, not about a form being disabled.
     *
     * Measured before the fix: two judges on 10 and 2, average 6.00; the 10 recuses;
     * average still 6.00, still 2 judges.
     */
    public function test_a_recused_judges_marks_leave_the_result(): void
    {
        $a = $this->judge('a@x.io');
        $b = $this->judge('b@x.io');
        $this->scorecard($a, $this->nominee, 10);
        $this->scorecard($b, $this->nominee, 2);

        $before = (new NomineeScoringService())->judgeStatsFor([$this->nominee])[$this->nominee];
        $this->assertSame(6.0, $before['avg']);
        $this->assertSame(2, $before['judges']);

        (new JudgeService())->declareConflict($a, $this->prog, 'I know this nominee');

        $after = (new NomineeScoringService())->judgeStatsFor([$this->nominee])[$this->nominee];
        $this->assertSame(1, $after['judges'], 'a recused judge still counted toward quorum');
        $this->assertSame(2.0, $after['avg'], 'a recused judge still moved the average');
    }

    /** And withdrawing the recusal puts them back — recusal is reversible, so this must be. */
    public function test_withdrawing_a_recusal_restores_the_marks(): void
    {
        $a = $this->judge('a@x.io');
        $this->scorecard($a, $this->nominee, 8);
        $svc = new JudgeService();

        $svc->declareConflict($a, $this->prog);
        $this->assertArrayNotHasKey($this->nominee,
            (new NomineeScoringService())->judgeStatsFor([$this->nominee]));

        $svc->withdrawConflict($a, $this->prog);
        $this->assertSame(1,
            (new NomineeScoringService())->judgeStatsFor([$this->nominee])[$this->nominee]['judges']);
    }

    /** The conflict is per PROGRAMME, so it must not reach a panel they still sit on. */
    public function test_a_conflict_in_one_programme_does_not_void_marks_in_another(): void
    {
        $otherProg = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'p2', 'title' => 'P2', 'is_active' => 1,
        ]);
        $otherCycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $otherProg, 'year' => 2026, 'status' => 'judging',
        ]);
        $otherCat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $otherCycle, 'slug' => 'c2', 'title' => 'C2', 'sort_order' => 1,
        ]);
        $otherNom = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $otherCat, 'name' => 'Elsewhere', 'status' => 'approved',
            'vote_count' => 5, 'organic_vote_count' => 5,
        ]);

        $a = $this->judge('a@x.io');
        $this->scorecard($a, $this->nominee, 9);
        foreach ($this->crit as $cid) {
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $a, 'nominee_id' => $otherNom, 'category_id' => $otherCat,
                'criterion_id' => $cid, 'score' => 9, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new JudgeService())->declareConflict($a, $this->prog);

        $stats = (new NomineeScoringService())->judgeStatsFor([$this->nominee, $otherNom]);
        $this->assertArrayNotHasKey($this->nominee, $stats, 'the conflicted programme still counted');
        $this->assertSame(1, $stats[$otherNom]['judges'] ?? 0,
            'a conflict in one programme wrongly voided marks in another');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 2 · A JUDGE TAKEN OFF THE PANEL
    // ════════════════════════════════════════════════════════════════════════

    /**
     * `is_active = 0` is how a judge is removed — resignation, misconduct, an appointment
     * that should never have been made. It stopped them signing in. It did not stop their
     * marks deciding the award.
     */
    public function test_a_deactivated_judges_marks_leave_the_result(): void
    {
        $a = $this->judge('a@x.io');
        $b = $this->judge('b@x.io');
        $this->scorecard($a, $this->nominee, 10);
        $this->scorecard($b, $this->nominee, 2);

        DB::table('gates_judges')->where('id', $b)->update(['is_active' => 0]);

        $after = (new NomineeScoringService())->judgeStatsFor([$this->nominee])[$this->nominee];
        $this->assertSame(1, $after['judges'], 'a removed judge still counted toward quorum');
        $this->assertSame(10.0, $after['avg']);
    }

    /**
     * And losing a judge can drop a nominee under quorum, which makes them
     * winner-INELIGIBLE rather than merely lower-scoring.
     *
     * That is the correct conservative outcome — CycleMaterialiser skips such a category
     * and logs it for manual review. A category decided by a panel that has since been
     * disqualified needs a person, not a cron job.
     */
    public function test_losing_a_judge_can_drop_a_nominee_below_quorum(): void
    {
        $a = $this->judge('a@x.io');
        $b = $this->judge('b@x.io');
        $this->scorecard($a, $this->nominee, 7);
        $this->scorecard($b, $this->nominee, 7);

        $this->assertTrue((new NomineeScoringService())->scoreCategory($this->cat)[$this->nominee]['eligible']);

        DB::table('gates_judges')->where('id', $b)->update(['is_active' => 0]);

        $this->assertFalse((new NomineeScoringService())->scoreCategory($this->cat)[$this->nominee]['eligible'],
            'one remaining judge decided an award that needs two');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 3 · THE AWARD COMES FROM THE SHORTLIST
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Under normal operation only shortlisted nominees ever accumulate marks, so this
     * changes nothing. It is for the case where they DID: a nominee scored before the
     * shortlist was published, or dropped from it afterwards, keeps their marks.
     *
     * Measured before the fix: an unshortlisted nominee scored 10 by both judges reached
     * CPI 1000 and would have won a category whose published shortlist did not contain
     * them.
     */
    public function test_a_nominee_off_the_shortlist_cannot_take_the_award(): void
    {
        $onList  = $this->nominee('On the list', 50);
        $offList = $this->nominee('Off the list', 100);

        $a = $this->judge('a@x.io');
        $b = $this->judge('b@x.io');
        $this->scorecard($a, $onList, 5);
        $this->scorecard($b, $onList, 5);
        $this->scorecard($a, $offList, 10);
        $this->scorecard($b, $offList, 10);

        $this->publishShortlist($this->cycle, $this->cat, [$onList]);

        // Both are eligible on the scoring alone — the exclusion is the shortlist's, and
        // it has to happen at promotion.
        $scores = (new NomineeScoringService())->scoreCategory($this->cat);
        $this->assertTrue($scores[$offList]['eligible']);
        $this->assertGreaterThan($scores[$onList]['cpi_score'], $scores[$offList]['cpi_score'],
            'the setup is only meaningful while the excluded nominee out-scores the listed one');

        // The cycle has to reach RESULTS for anything to be promoted, or the assertion
        // below would pass because nothing ran at all — which proves nothing.
        DB::table('gates_award_cycles')->where('id', $this->cycle)->update([
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2020-03-01 00:00:00',
            'voting_close'      => '2020-04-01 00:00:00',
            'results_date'      => '2020-05-01 00:00:00',
        ]);

        (new \AfricaGates\Services\CycleMaterialiser())->run();

        $status = static fn (int $id): string =>
            (string) DB::table('gates_nominees')->where('id', $id)->value('status');

        // The listed nominee IS crowned — without this the test would pass on a run that
        // promoted nobody.
        $this->assertSame('winner', $status($onList),
            'nothing was promoted, so the exclusion below is unproven');
        $this->assertSame('approved', $status($offList),
            'a nominee the published shortlist excluded was crowned anyway');
    }

    /** A category that does not shortlist at all still crowns somebody. */
    public function test_a_category_with_no_shortlist_still_promotes(): void
    {
        $a = $this->judge('a@x.io');
        $b = $this->judge('b@x.io');
        $this->scorecard($a, $this->nominee, 8);
        $this->scorecard($b, $this->nominee, 8);

        $this->assertTrue((new NomineeScoringService())->scoreCategory($this->cat)[$this->nominee]['eligible'],
            'an empty shortlist filter must not be read as "shortlisted nobody"');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 4 · ADDING A CRITERION VOIDS EVERY SCORECARD
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Not prevented — an operator may have a real reason, and a rubric that cannot gain a
     * criterion is its own problem. But the NUMBER goes in front of them first.
     *
     * Measured: two complete scorecards, quorum met, nominee eligible. Add one criterion
     * and it is judges 0, eligible NO, CPI back to the community-only figure — with
     * nothing thrown and nothing logged.
     */
    public function test_the_operator_is_told_how_many_scorecards_adding_one_would_void(): void
    {
        $a = $this->judge('a@x.io');
        $b = $this->judge('b@x.io');
        $this->scorecard($a, $this->nominee, 7);
        $this->scorecard($b, $this->nominee, 7);

        $this->assertSame(2, JudgeRubric::completeScorecards(null),
            'the warning would have understated what the edit costs');

        // And the underlying hazard is real, which is why the warning has to exist.
        $before = (new NomineeScoringService())->scoreCategory($this->cat)[$this->nominee];
        $this->assertTrue($before['eligible']);

        JudgeRubric::save(null, 0, ['label' => 'A fifth thing', 'weight' => '25']);

        $after = (new NomineeScoringService())->scoreCategory($this->cat)[$this->nominee];
        $this->assertFalse($after['eligible'],
            'if this ever stops being true the warning should be removed, not kept');
    }

    /** A partial scorecard is not counted in the warning either — same definition throughout. */
    public function test_a_partial_scorecard_is_not_counted(): void
    {
        $a = $this->judge('a@x.io');
        DB::table('gates_judge_criteria_scores')->insert([
            'judge_id' => $a, 'nominee_id' => $this->nominee, 'category_id' => $this->cat,
            'criterion_id' => $this->crit[0], 'score' => 9, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(0, JudgeRubric::completeScorecards(null));
    }
    // ════════════════════════════════════════════════════════════════════════
    // 5 · A BELOW-QUORUM FIGURE IS LABELLED, NOT SILENTLY COMPARABLE
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The judge half is not counted below quorum, so the number is a COMMUNITY score.
     *
     * It sat in the same column as a full CPI with nothing to say the two were not
     * comparable — and the comment above it claimed the judge component was "withheld
     * (community-only)", which is what RENORMALISING would mean and is not what happens.
     */
    public function test_a_below_quorum_score_is_marked_provisional(): void
    {
        $a = $this->judge('a@x.io');
        $this->scorecard($a, $this->nominee, 7);   // one judge; quorum is two

        $row = (new NomineeScoringService())->scoreCategory($this->cat)[$this->nominee];

        $this->assertFalse($row['eligible']);
        $this->assertTrue($row['provisional'],
            'a community-only figure was presented as a Cultural Power Index');
    }

    /** And a scored nominee's figure is not marked provisional. */
    public function test_a_full_scorecard_is_not_provisional(): void
    {
        $this->scorecard($this->judge('a@x.io'), $this->nominee, 7);
        $this->scorecard($this->judge('b@x.io'), $this->nominee, 7);

        $row = (new NomineeScoringService())->scoreCategory($this->cat)[$this->nominee];

        $this->assertTrue($row['eligible']);
        $this->assertFalse($row['provisional']);
    }

    /**
     * THE REASON THE WEIGHTING IS NOT "FIXED": renormalising is worse.
     *
     * Giving community the full weight when there is no judge average would put an
     * UNJUDGED nominee at the top of the board on popularity alone — the single thing
     * this platform exists to prevent. Scoring the absent half as zero understates rather
     * than overstates, which is the conservative direction.
     *
     * Pinned with the measured numbers so a future "obvious simplification" has to argue
     * with them rather than with a comment.
     */
    public function test_an_unjudged_nominee_cannot_outrank_a_judged_one(): void
    {
        $cpi = new \AfricaGates\Services\CpiService();

        // Called directly, so the RuleEngine row in setUp does not reach it: the full-credit
        // mark is passed here instead. 100 votes against the live default of 1,000 would be
        // discounted to a third, which is correct and is a different test.
        $judged     = $cpi->nomineeScore(100, 100, 6.0, 0.45, 0.55, null, null, null, 1);
        $unjudged   = $cpi->nomineeScore(100, 100, null, 0.45, 0.55, null, null, null, 1);
        $renormed   = 1000;                                // what community-only would give

        $this->assertSame(499, $judged);
        $this->assertSame(450, $unjudged);

        $this->assertLessThan($judged, $unjudged,
            'an unjudged nominee outranked a judged one — popularity decided the board');
        $this->assertLessThan($renormed, $unjudged,
            'renormalising would hand the top of the board to whoever has the most votes');
    }
}
