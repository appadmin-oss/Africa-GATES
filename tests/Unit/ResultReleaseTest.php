<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CycleMaterialiser, JudgeScorecard, ResultRelease};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The scores that decide an award, and the screen that shows them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS INVISIBLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `NomineeScoringService::scoreCategory()` crowns every winner on this platform. It had
 * three callers — the promotion, a snapshot writer, and a console command on a host with
 * no shell — and no screen. An operator could see who had won and not one figure behind
 * it: not the index, not the community/judge split, not who was excluded for missing the
 * quorum or for being off the shortlist, and not whether first and second were separated
 * at all.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTY THIS FILE EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The screen must not be a SECOND opinion about who wins. If it ranked with its own copy
 * of the comparator, the two could drift — and the drift would be between what an
 * operator was shown before a release and what the release then did, which is worse than
 * showing them nothing.
 *
 * So {@see ResultRelease::order()} is the ranking and the promotion calls it. The test
 * below runs the real promotion and asserts it crowned whoever the screen put first.
 */
final class ResultReleaseTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;
    /** @var array<string,int> */
    private array $crit = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals-' . bin2hex(random_bytes(3)),
            'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'judging',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'primary', 'title' => 'Primary',
        ]);
        foreach (['impact' => 'Impact', 'rigour' => 'Rigour'] as $slug => $label) {
            $this->crit[$slug] = (int) DB::table('gates_judge_criteria')->insertGetId([
                'programme_id' => $this->programmeId, 'slug' => $slug,
                'label' => $label, 'weight' => 50, 'is_active' => 1,
            ]);
        }
    }

    private function nominee(string $name, int $organic = 0, int $paid = 0): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $organic, 'vote_count' => $organic + $paid,
        ]);
    }

    private function judge(string $name): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $name, 'is_active' => 1,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'programme_ids' => json_encode([$this->programmeId]),
        ]);
    }

    /**
     * A COMPLETE scorecard with a different mark per criterion.
     *
     * Complete matters and is easy to get wrong: `judgeAveragesFor()` counts only
     * scorecards covering every EFFECTIVE criterion, which is the programme's own plus the
     * global rubric it inherits. Marking the two the programme declared leaves the
     * scorecard short, the nominee below quorum, and the judge half scored as absent — so
     * a test meaning to exercise the judge half exercises a community-only figure instead
     * and passes for the wrong reason.
     *
     * A different mark per criterion is the other half of the point: {@see scoreAll()}
     * gives them all the same one, which can only ever produce a whole-number average, and
     * a whole-number average is exactly the case where the two halves of the index round
     * without ever disagreeing.
     *
     * @param list<int> $marks one per effective criterion, in rubric order; cycled if short
     */
    private function scoreEach(int $judge, int $nominee, array $marks): void
    {
        $i = 0;
        foreach (\AfricaGates\Services\JudgeRubric::effective($this->programmeId) as $c) {
            if ((int) $c->is_active !== 1) continue;
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judge, 'nominee_id' => $nominee,
                'category_id' => $this->categoryId, 'criterion_id' => (int) $c->id,
                'score' => $marks[$i++ % count($marks)],
                'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
            ]);
        }
    }

    /** A COMPLETE scorecard: every effective criterion, which is what the quorum counts. */
    private function scoreAll(int $judge, int $nominee, int $score): void
    {
        foreach (\AfricaGates\Services\JudgeRubric::effective($this->programmeId) as $c) {
            if ((int) $c->is_active !== 1) continue;
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judge, 'nominee_id' => $nominee,
                'category_id' => $this->categoryId, 'criterion_id' => (int) $c->id,
                'score' => $score,
                'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
            ]);
        }
    }

    // ══ the scores are visible at all ════════════════════════════════════════

    /**
     * Every scored nominee, with the figures that decided it. None of this reached a
     * screen before: the scorer's only readers were the promotion, a snapshot writer and
     * a console command on a host with no shell.
     */
    public function test_every_scored_nominee_is_listed_with_what_decided_them(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $strong = $this->nominee('Strong', 100);
        $weak   = $this->nominee('Weak', 20);
        $this->scoreAll($j1, $strong, 9); $this->scoreAll($j2, $strong, 9);
        $this->scoreAll($j1, $weak, 4);   $this->scoreAll($j2, $weak, 4);

        $c = ResultRelease::category($this->categoryId);

        $this->assertCount(2, $c['rows']);
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertGreaterThan($by['Weak']['cpi'], $by['Strong']['cpi']);
        $this->assertSame(1, $by['Strong']['rank']);
        $this->assertSame(2, $by['Weak']['rank']);
        $this->assertSame(2, $by['Strong']['judges']);
        $this->assertNotNull($by['Strong']['judge_score']);
        $this->assertSame(100, $by['Strong']['organic']);

        // The margin, which is how much of a result this is. Never visible anywhere.
        $this->assertSame($by['Strong']['cpi'] - $by['Weak']['cpi'], $c['margin']);
        $this->assertSame('Strong', $c['winner']['name']);
        $this->assertSame('Weak', $c['runner_up']['name']);
    }

    // ══ the property the whole design rests on ═══════════════════════════════

    /**
     * THE ONE THAT MATTERS. The screen is not a second opinion about who wins.
     *
     * The real promotion is run and asked what it crowned. If the two ever disagreed, the
     * disagreement would be between what an operator was shown before a release and what
     * the release then did.
     */
    public function test_the_promotion_crowns_whoever_the_screen_put_first(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        foreach ([['A', 80, 7], ['B', 95, 6], ['C', 40, 9]] as [$name, $organic, $mark]) {
            $n = $this->nominee($name, $organic);
            $this->scoreAll($j1, $n, $mark);
            $this->scoreAll($j2, $n, $mark);
        }

        $shown = ResultRelease::category($this->categoryId);
        $this->assertNotNull($shown['winner'], 'the screen showed no winner to compare');

        // ── THE REAL PROMOTION, VIA A REAL TRANSITION ────────────────────────
        //
        // Setting `status = 'results'` by hand promotes nobody: the engine crowns on the
        // MOVE into results, not on already being there. So the dates are set and the
        // engine is asked to advance the cycle itself, which is what happens on the night.
        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update([
            'status'       => 'judging',
            'voting_open'  => Carbon::now()->subDays(30)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDays(3)->toDateTimeString(),
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        (new CycleMaterialiser(false))->run();

        $crowned = DB::table('gates_nominees')->where('category_id', $this->categoryId)
            ->where('status', 'winner')->value('name');

        $this->assertSame($shown['winner']['name'], (string) $crowned,
            'the release crowned somebody other than the nominee the screen ranked first');

        $second = DB::table('gates_nominees')->where('category_id', $this->categoryId)
            ->where('status', 'runner_up')->value('name');
        $this->assertSame($shown['runner_up']['name'], (string) $second);
    }

    /** And the comparator is literally shared, not merely equivalent today. */
    public function test_the_promotion_calls_the_screens_comparator(): void
    {
        $m = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Services/CycleMaterialiser.php');

        $this->assertStringContainsString('ResultRelease::order(', $m,
            'the promotion sorts with its own copy — the two can drift');
        $this->assertStringContainsString('ResultRelease::shortlistedIn(', $m);
    }

    // ══ who is out, and why ══════════════════════════════════════════════════

    /**
     * A nominee below the judge quorum is listed with the reason, not omitted.
     *
     * An exclusion nobody can see is the part of a result that is hardest to defend
     * later — and this one is invisible in every other number, because the nominee is
     * simply absent from the ranking.
     */
    public function test_a_nominee_below_quorum_is_shown_with_the_reason(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $full = $this->nominee('Full', 50);
        $this->scoreAll($j1, $full, 8); $this->scoreAll($j2, $full, 8);

        $thin = $this->nominee('One judge only', 90);
        $this->scoreAll($j1, $thin, 10);   // a single judge

        $c  = ResultRelease::category($this->categoryId);
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertFalse($by['One judge only']['in_running']);
        $this->assertSame(ResultRelease::OUT_QUORUM, $by['One judge only']['out_reason']);
        $this->assertNull($by['One judge only']['rank'], 'an excluded nominee was given a placing');
        $this->assertTrue($by['One judge only']['provisional']);

        $this->assertSame('Full', $c['winner']['name']);
    }

    /**
     * A nominee off the published shortlist is excluded, and says so.
     *
     * Measured on exactly this setup once: an unshortlisted nominee scored 10 by both
     * judges reached the top of the index and would have taken a category whose published
     * shortlist did not contain them.
     */
    public function test_a_nominee_off_the_shortlist_is_shown_as_excluded(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $on  = $this->nominee('Shortlisted', 40);
        $off = $this->nominee('Not shortlisted', 100);
        $this->scoreAll($j1, $on, 6);  $this->scoreAll($j2, $on, 6);
        $this->scoreAll($j1, $off, 10); $this->scoreAll($j2, $off, 10);

        $sid = (int) DB::table('gates_shortlists')->insertGetId([
            'cycle_id' => $this->cycleId, 'category_id' => $this->categoryId,
            'status' => 'published', 'entry_count' => 1, 'considered' => 2,
            'published_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('gates_shortlist_entries')->insert([
            'shortlist_id' => $sid, 'nominee_id' => $on, 'rank_no' => 1,
        ]);

        $c  = ResultRelease::category($this->categoryId);
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertSame(ResultRelease::OUT_SHORTLIST, $by['Not shortlisted']['out_reason']);
        $this->assertFalse($by['Not shortlisted']['on_shortlist']);
        $this->assertSame('Shortlisted', $c['winner']['name'],
            'a nominee the shortlist excluded took the award');
    }

    /** No shortlist at all is not the same as an empty one, and must crown normally. */
    public function test_a_programme_that_does_not_shortlist_still_crowns(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $n  = $this->nominee('Only', 30);
        $this->scoreAll($j1, $n, 7); $this->scoreAll($j2, $n, 7);

        $c = ResultRelease::category($this->categoryId);

        $this->assertNull($c['shortlisted'], 'no shortlist was reported as an empty one');
        $this->assertSame('Only', $c['winner']['name']);
    }

    // ══ the two facts that only ever reached a log ═══════════════════════════

    /**
     * A dead heat is on the screen, not in a maintenance log on a host with no shell.
     *
     * The promotion writes "DEAD HEAT ... This one needs a human" into its run log. There
     * is no shell here, so no human has ever read that sentence.
     */
    public function test_a_dead_heat_is_reported_on_the_screen(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        // Identical support, identical marks: the methodology cannot separate them.
        foreach (['Tie A', 'Tie B'] as $name) {
            $n = $this->nominee($name, 60);
            $this->scoreAll($j1, $n, 8);
            $this->scoreAll($j2, $n, 8);
        }

        $c = ResultRelease::category($this->categoryId);

        $this->assertTrue($c['dead_heat'], 'a dead heat was resolved silently by id');
        $this->assertSame(0, $c['margin']);
    }

    /** And a category the quorum blocks says so, instead of showing an empty table. */
    public function test_a_category_that_crowns_nobody_says_why(): void
    {
        $j = $this->judge('Ada Obi');
        $n = $this->nominee('Half judged', 50);
        $this->scoreAll($j, $n, 9);   // one judge, below quorum

        $c = ResultRelease::category($this->categoryId);

        $this->assertNull($c['winner']);
        $this->assertNotNull($c['blocked']);
        $this->assertStringContainsString('quorum', $c['blocked']);
    }

    // ══ money must not move a rank ═══════════════════════════════════════════

    /**
     * The tiebreak is ORGANIC, and the screen shows both numbers so it can be checked.
     *
     * This tie broke on `vote_count` once. At that single moment two nominees on equal
     * index were separated by who had BOUGHT votes, and every guard upstream became
     * decoration.
     */
    public function test_purchased_votes_cannot_separate_two_equal_nominees(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        // ── THE FIXTURE HAS TO DISCRIMINATE ──────────────────────────────────
        //
        // `Plain` is created FIRST so it holds the lower id. Same organic support, same
        // marks, same index — so the only legitimate separator left is the deterministic
        // id fallback, and `Plain` must win.
        //
        // A tiebreak on `vote_count` would put `Bought` first instead. The first version
        // of this test asserted only `dead_heat`, which is computed from cpi and organic
        // and is true either way: it claimed to prove money cannot decide an award and
        // proved nothing. Restoring the old comparator left it green.
        $plain  = $this->nominee('Plain', 60, 0);
        $bought = $this->nominee('Bought', 60, 900);
        $this->scoreAll($j1, $plain, 8);  $this->scoreAll($j2, $plain, 8);
        $this->scoreAll($j1, $bought, 8); $this->scoreAll($j2, $bought, 8);

        $c = ResultRelease::category($this->categoryId);

        $this->assertSame('Plain', $c['winner']['name'],
            'purchased votes lifted a nominee above one the methodology cannot separate '
            . 'them from — every guard upstream is decoration if this one gives way');
        $this->assertTrue($c['dead_heat'],
            'purchased votes separated two nominees the methodology cannot separate');

        // Both figures are on the screen, because they are different claims: one decided
        // this and the other is what the public page shows.
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;
        $this->assertSame(60,  $by['Bought']['organic']);
        $this->assertSame(960, $by['Bought']['vote_count']);
    }

    // ══ it must not decide anything ══════════════════════════════════════════

    /** Looking at the screen must not crown anybody. */

    // ══ the working ══════════════════════════════════════════════════════════

    /**
     * A CPI NOBODY COULD CHECK.
     *
     * The screen showed organic votes, a judge mark out of ten, the two weights and an
     * index out of a thousand, and no way to get from any of them to the last one. That is
     * the wrong shape for the one page somebody has to defend in public: an operator could
     * read every input to the decision and still not say why the number was the number.
     *
     * It is not derivable by eye either, because the community half is scaled against the
     * COHORT MAXIMUM rather than against the votes cast — the same 2,650 votes are worth
     * 247 points behind a leader on 4,820 and 450 on their own. The denominator is the
     * whole explanation and it appeared on no screen.
     */
    public function test_the_two_halves_of_the_index_add_up_to_the_index(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $lead = $this->nominee('Grace Abiodun', 4820);
        $mid  = $this->nominee('Fatima Bello', 2650);
        $this->scoreAll($j1, $lead, 9); $this->scoreAll($j2, $lead, 9);
        $this->scoreAll($j1, $mid, 8);  $this->scoreAll($j2, $mid, 8);

        $c  = ResultRelease::category($this->categoryId);
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        // EXACTLY, on every row. `cpi_score` rounds the sum once, so two independently
        // rounded halves can differ from it by a point — and a sum printed beside a figure
        // it does not equal is worse than printing no working at all. ResultRelease gives
        // the judge half the single rounding step for that reason.
        foreach ($by as $name => $r) {
            $this->assertSame($r['cpi'], $r['community_points'] + $r['judge_points'],
                "the working printed under {$name}'s index does not add up to it");
        }

        // And the halves are the halves: 45% of a full community share is 450 of 1000.
        $this->assertSame(450, $by['Grace Abiodun']['community_points']);
        $this->assertSame(100, $by['Grace Abiodun']['community_share']);
        $this->assertSame(247, $by['Fatima Bello']['community_points'],
            '2,650 votes behind a leader on 4,820 is 55% of the community weight');
        $this->assertSame(55, $by['Fatima Bello']['community_share']);
    }

    /**
     * And it holds across the space, not at the one point a fixture happens to pick.
     *
     * `cpi_score` rounds the SUM once. Two independently rounded halves agree with that
     * most of the time and not always — a community half of x.5 beside a judge half of
     * y.5 is off by a point — so an example-based test passes on whichever numbers were
     * chosen and says nothing about the next ones. Swept instead: forty nominees across
     * the whole range of vote shares and every half-mark the panel can produce.
     */
    public function test_the_halves_add_up_across_the_whole_range(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        // One nominee per vote share, each on a different run of marks, so the community
        // half sweeps 0–100% of the leader and the judge half lands all over the weighted
        // average the rubric can produce.
        for ($i = 1; $i <= 40; $i++) {
            $n = $this->nominee('N' . $i, $i);
            $marks = [$i % 11, ($i + 3) % 11, ($i + 7) % 11];
            $this->scoreEach($j1, $n, $marks);
            $this->scoreEach($j2, $n, $marks);
        }

        $c = ResultRelease::category($this->categoryId);
        $this->assertCount(40, $c['rows']);

        $judged = 0;
        foreach ($c['rows'] as $r) {
            $this->assertSame($r['cpi'], $r['community_points'] + $r['judge_points'],
                "{$r['name']}: {$r['community_points']} + {$r['judge_points']} does not "
                . "equal the {$r['cpi']} printed beside it");
            if (!$r['provisional']) $judged++;
        }

        // Or the sweep is only ever exercising community-only figures, where the judge
        // half is zero and cannot disagree with anything.
        $this->assertSame(40, $judged, 'every scorecard here should be complete');
    }

    /**
     * THE SCALE IS THE FIELD, NOT THE ENTRY LIST.
     *
     * The cohort maximum used to be taken before the shortlist was applied, so a nominee
     * who could not win decided what everybody else's support was worth — and the more
     * popular they were, the less the finalists' votes counted.
     *
     * These are the numbers from the version that shipped. Yetunde is the only person
     * shortlisted and holds 1,900 organic votes; Samuel, who is not on the list, holds
     * 5,100. Her community share came out at 37% and her community half at 168 of a
     * possible 450 — so two thirds of the community weight was removed from a final by
     * somebody who had already been taken out of it, and the panel decided alone. Nothing
     * on any screen said the denominator was a person who could not win.
     *
     * Against the field she is actually in, her share is 100% and her community half is
     * the full 450. Samuel's votes are still counted, still shown, and still his — they
     * are simply no longer the yardstick for a contest he is not in.
     */
    public function test_somebody_off_the_shortlist_no_longer_sets_the_scale(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $on  = $this->nominee('Yetunde Adeyemi', 1900);
        $off = $this->nominee('Samuel Oyelaran', 5100);
        foreach ([$on, $off] as $n) { $this->scoreAll($j1, $n, 7); $this->scoreAll($j2, $n, 7); }
        $this->publishShortlist($this->cycleId, $this->categoryId, [$on]);

        $c = ResultRelease::category($this->categoryId);

        $this->assertSame(1900, $c['cohort_max'],
            'the denominator is still a nominee who is not in the running');
        $this->assertSame('Yetunde Adeyemi', $c['scale_set_by']);
        $this->assertFalse($c['scale_is_out'],
            'the scale is set from inside the field now; the warning is about nobody');

        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertSame(100, $by['Yetunde Adeyemi']['community_share'],
            'the shortlisted leader is still measured against somebody outside the final');
        $this->assertSame(450, $by['Yetunde Adeyemi']['community_points'],
            'the community half is still being suppressed — 450 is its full weight');

        // Nothing is hidden. He is still scored, still listed, still holds every vote he
        // was given; the change is what the OTHERS are divided by, not what he is worth.
        $this->assertSame(5100, $by['Samuel Oyelaran']['organic']);
    }

    /**
     * A PUBLISHED LIST NAMING NOBODY WHO SCORES MUST NOT EMPTY THE COHORT.
     *
     * Every entry withdrawn, rejected or merged away after publication is an ordinary end
     * to a bad year in a category, and it leaves the shortlist pointing at nobody the
     * scorer can see. An empty collection's `max()` is null, so the floor that stops a
     * division by nought would make the denominator ONE — and every nominee in the
     * category would take the FULL community half.
     *
     * That is the dangerous direction. A category scored to zero is noticed within the
     * hour; a whole field scored identically at the top of the range reads like a close
     * contest, and the release screen has nothing to say about it.
     */
    public function test_a_shortlist_naming_nobody_who_scores_does_not_flatten_the_field(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $a = $this->nominee('Yetunde Adeyemi', 1000);
        $b = $this->nominee('Ngozi Eze', 250);
        foreach ([$a, $b] as $n) { $this->scoreAll($j1, $n, 7); $this->scoreAll($j2, $n, 7); }

        // Shortlisted, then pulled back for review — so the published list names somebody
        // the scorer no longer returns. `pending` and not `rejected`: the status column
        // permits only pending/approved/winner/runner_up, and a fixture that invents a
        // value passes on SQLite and is `Data truncated` on the database this runs on.
        $gone = $this->nominee('Samuel Oyelaran', 4000);
        DB::table('gates_nominees')->where('id', $gone)->update(['status' => 'pending']);
        $this->publishShortlist($this->cycleId, $this->categoryId, [$gone]);

        $c = ResultRelease::category($this->categoryId);

        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertSame(1000, $c['cohort_max'],
            'the cohort emptied out and the denominator fell back to the floor of one');
        $this->assertSame(450, $by['Yetunde Adeyemi']['community_points']);
        $this->assertSame(113, $by['Ngozi Eze']['community_points'],
            'a nominee on a quarter of the votes was handed the same community half as '
            . 'the leader — the field was flattened, not scored');
    }

    /**
     * THE SCREEN DIVIDES BY THE NUMBER THE SCORER DIVIDED BY.
     *
     * This page's whole purpose is showing how a CPI was reached, and the denominator is
     * the half of that which cannot be guessed from anything else on it. It used to be
     * computed twice — once inside `NomineeScoringService`, and again here as a `max()`
     * over the rows on the page. The two agreed only for as long as both happened to mean
     * "everybody who scored"; the moment the scorer narrowed its cohort to the published
     * field, this screen carried on naming a nominee who was not in it, and every "37% of
     * Samuel's 5,100" on the page explained the arithmetic with a number that had not
     * produced it.
     *
     * Held as an identity rather than as a value, so it survives any later change to what
     * the cohort MEANS: whatever the scorer divided by is what the screen must say, and
     * every row's share must come back out of it.
     */
    public function test_the_screen_and_the_scorer_use_one_denominator(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $on  = $this->nominee('Yetunde Adeyemi', 1900);
        $mid = $this->nominee('Ngozi Eze', 950);
        $off = $this->nominee('Samuel Oyelaran', 5100);
        foreach ([$on, $mid, $off] as $n) { $this->scoreAll($j1, $n, 7); $this->scoreAll($j2, $n, 7); }
        $this->publishShortlist($this->cycleId, $this->categoryId, [$on, $mid]);

        $scored = (new \AfricaGates\Services\NomineeScoringService())
            ->scoreCategory($this->categoryId);
        $c = ResultRelease::category($this->categoryId);

        $fromScorer = (int) $scored[$on]['cohort_max'];
        $this->assertSame($fromScorer, $c['cohort_max'],
            'the release screen is explaining the arithmetic with a denominator the '
            . 'scorer did not use');

        // And the per-row share the screen prints is that denominator, applied. Checked on
        // a row that is NOT the scale-setter, because "100% of their own votes" is true
        // whatever the denominator is and would pass a broken one.
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertSame((int) round(950 / $fromScorer * 100), $by['Ngozi Eze']['community_share']);
        $this->assertSame(50, $by['Ngozi Eze']['community_share'],
            '950 of 1,900 is half the field\'s best, and the page said otherwise');
    }

    /**
     * AND THE QUORUM DELIBERATELY DOES NOT NARROW IT, WHICH IS WHY THE WARNING SURVIVES.
     *
     * Below quorum is PENDING, not out — a panel may still finish. Narrowing the
     * denominator for it would move every published score in the category each time a
     * scorecard was completed, and it runs perversely: drop the unjudged leader, inflate
     * everybody's share, then hand it all back when they are judged.
     *
     * So a shortlisted nominee nobody has finished judging can still set the scale, and
     * that is the case `scale_is_out` exists to name. It is a smaller and more honest
     * warning than the one it replaces: not "somebody who cannot win", but "somebody the
     * panel has not got to yet".
     */
    public function test_a_shortlisted_nominee_below_quorum_still_sets_the_scale(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $lead    = $this->nominee('Chidinma Okeke', 4000);   // shortlisted, ONE judge
        $decided = $this->nominee('Yetunde Adeyemi', 1000);  // shortlisted, at quorum
        $this->scoreAll($j1, $lead, 8);
        $this->scoreAll($j1, $decided, 7);
        $this->scoreAll($j2, $decided, 7);
        $this->publishShortlist($this->cycleId, $this->categoryId, [$lead, $decided]);

        $c = ResultRelease::category($this->categoryId);

        $this->assertSame(4000, $c['cohort_max'],
            'an unjudged nominee was dropped from the scale, which moves every other '
            . 'published score the moment their panel finishes');
        $this->assertSame('Chidinma Okeke', $c['scale_set_by']);
        $this->assertTrue($c['scale_is_out'],
            'the field is being measured against somebody the panel has not finished, '
            . 'and the release screen did not say so');
    }

    /**
     * And the warning is silent when it would be a warning about nobody.
     *
     * A category whose only nominee is below the quorum has a scale-setter who is
     * technically "out", and there is no field for them to be holding down. Firing there
     * teaches an operator to skip the box on the categories where it means something,
     * which is how a real finding stops being read.
     */
    public function test_the_scale_warning_is_silent_when_it_would_warn_about_nobody(): void
    {
        $j = $this->judge('Ada Obi');
        $n = $this->nominee('Prof. Olusegun Ade', 900);
        $this->scoreAll($j, $n, 10);                     // one judge, below quorum

        $c = ResultRelease::category($this->categoryId);

        $this->assertNotNull($c['blocked'], 'this category crowns nobody');
        $this->assertFalse($c['scale_is_out'],
            'a category with nobody in the running warned that somebody out of the '
            . 'running was holding the field down');
    }


    // ══ when the index cannot separate two people ════════════════════════════

    /**
     * A DEAD HEAT IS NOT TWO IDENTICAL NOMINEES. It is two identical AVERAGES.
     *
     * Everything the release can show about these two is the same figure — the index, both
     * halves of it, the panel average, the vote count. That is what "the methodology
     * cannot separate them" means, and it is why the page asks for a person.
     *
     * What the page could not show is that the panels said completely different things: a
     * board that agreed flatly on 8, against two judges who disagreed on every criterion
     * in opposite directions and cancelled out. The person being asked to decide had the
     * tie explained to them and no evidence to decide it on.
     */
    public function test_a_dead_heat_can_hide_two_completely_different_panels(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $flat  = $this->nominee('Chidi Okafor', 3300);
        $split = $this->nominee('Amara Nwosu', 3300);

        // Unanimous.
        $this->scoreEach($j1, $flat, [8, 8]);
        $this->scoreEach($j2, $flat, [8, 8]);
        // Opposed on both criteria, cancelling to the same weighted average.
        $this->scoreEach($j1, $split, [9, 7]);
        $this->scoreEach($j2, $split, [7, 9]);

        $c  = ResultRelease::category($this->categoryId);
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertTrue($c['dead_heat']);
        $this->assertSame(0, $c['margin']);

        // Every figure the release can print is identical.
        foreach (['cpi', 'community_points', 'judge_points', 'judge_score', 'organic'] as $f) {
            $this->assertSame($by['Chidi Okafor'][$f], $by['Amara Nwosu'][$f],
                "the two are not actually tied on {$f}, so this is not the case under test");
        }

        // And the panels are not remotely the same. THIS is what the person deciding
        // needs, and it exists only in the marks.
        $flatCard  = JudgeScorecard::forNominee($flat);
        $splitCard = JudgeScorecard::forNominee($split);

        $this->assertSame($flatCard['panel']['average'], $splitCard['panel']['average']);
        $this->assertSame([8.0, 8.0], array_column($flatCard['judges'], 'average'),
            'the unanimous panel');
        $this->assertNotSame(
            array_column($flatCard['judges'], 'average'),
            array_column($splitCard['judges'], 'average'),
            'the two panels agreed identically, so the fixture is not exercising the point'
        );
    }

    /**
     * And the screen points at that evidence from the sentence that asks for a decision.
     *
     * §18 in its sharpest form: the mechanism exists, the route exists, and a page that
     * says "this one needs a person to decide" without linking to what they decide ON is
     * still asking somebody to go and look for it.
     */
    public function test_the_dead_heat_notice_links_to_both_scorecards(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/result-release.twig');

        // The Twig comment above it quotes the reasoning, so strip comments first: this
        // codebase has shipped a scanner fooled by its own explanation four times.
        $body = (string) preg_replace('~\{#.*?#\}~s', '', $tpl);

        $this->assertMatchesRegularExpression(
            '~needs a person to decide.*?scorecard/\{\{ c\.winner\.nominee_id~s', $body,
            'the notice asks for a decision and does not link to the winner\'s marks');
        $this->assertStringContainsString('scorecard/{{ c.runner_up.nominee_id }}', $body,
            'and not to the runner-up\'s either — a dead heat is decided between TWO cards');
    }

    /**
     * AN EXACT TIE THE VOTES BROKE IS NOT A DEAD HEAT, AND MUST NOT READ AS "0 APART".
     *
     * Equal CPI with different organic support is separated by the comparator, on votes.
     * The screen reported "first and second are 0 apart on a 0–1000 index" beside a WINS
     * badge and said nothing about what broke a tie it had just called exact — so the one
     * moment the organic tiebreak decides an award was the one moment it was invisible.
     *
     * The tiebreak is the platform's whole argument: paid votes move `vote_count` and
     * never `organic_vote_count`, so a nominee with more TOTAL votes does not necessarily
     * win a tie. That is exactly the thing somebody challenges, and it now says so.
     */
    public function test_an_index_tie_broken_by_organic_votes_says_so(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        // Same index by different routes: more votes and a lower mark, against fewer
        // votes and a higher one. 1000 sets the cohort scale.
        $loud  = $this->nominee('More votes, lower mark', 1000);
        $quiet = $this->nominee('Fewer votes, higher mark', 756);
        $this->scoreAll($j1, $loud, 6);  $this->scoreAll($j2, $loud, 6);
        $this->scoreAll($j1, $quiet, 8); $this->scoreAll($j2, $quiet, 8);

        $c  = ResultRelease::category($this->categoryId);
        $by = [];
        foreach ($c['rows'] as $r) $by[$r['name']] = $r;

        $this->assertSame($by['More votes, lower mark']['cpi'],
                          $by['Fewer votes, higher mark']['cpi'], 'the index must be level');
        $this->assertSame(0, $c['margin']);
        $this->assertFalse($c['dead_heat'], 'different organic support is not a dead heat');
        $this->assertTrue($c['tie_broken_by_votes'],
            'the index tied, something separated them, and the screen cannot say what');
        $this->assertSame('More votes, lower mark', $c['winner']['name']);
    }

    /** And it is not claimed on a category that is merely close. */
    public function test_a_close_result_is_not_reported_as_a_tie(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $a = $this->nominee('Ibrahim Sule', 2101);
        $b = $this->nominee('Blessing Eke', 2088);
        foreach ([$a, $b] as $n) { $this->scoreAll($j1, $n, 7); $this->scoreAll($j2, $n, 7); }

        $c = ResultRelease::category($this->categoryId);

        $this->assertGreaterThan(0, $c['margin']);
        $this->assertFalse($c['tie_broken_by_votes']);
        $this->assertFalse($c['dead_heat']);
    }

    // ══ the summary strip ════════════════════════════════════════════════════

    /**
     * A DEAD HEAT IS NOT ALSO A THIN MARGIN.
     *
     * It counted as both, because a dead heat has a margin of zero and zero is inside ten.
     * So one category appeared under two headings and an operator adding the tiles up got
     * more things to look at than the page beneath them showed. A summary whose numbers
     * cannot be reconciled with the evidence under it is worse than no summary.
     */
    public function test_a_dead_heat_is_not_counted_again_as_a_thin_margin(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        foreach (['Chidi Okafor', 'Amara Nwosu'] as $name) {
            $n = $this->nominee($name, 3300);
            $this->scoreAll($j1, $n, 8); $this->scoreAll($j2, $n, 8);
        }

        $a = ResultRelease::attention(ResultRelease::forCycle($this->cycleId));

        $this->assertSame(1, $a['dead_heats']);
        $this->assertSame(0, $a['thin_margins'], 'the dead heat was counted twice');
        $this->assertSame(1, $a['needs_person'],
            'one category needs a person, however many ways it is described');
    }

    /** And a genuinely thin margin still counts, once. */
    public function test_a_thin_margin_is_counted_and_a_wide_one_is_not(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $a = $this->nominee('Ibrahim Sule', 2101);
        $b = $this->nominee('Blessing Eke', 2088);
        foreach ([$a, $b] as $n) { $this->scoreAll($j1, $n, 7); $this->scoreAll($j2, $n, 7); }

        $at = ResultRelease::attention(ResultRelease::forCycle($this->cycleId));

        $this->assertSame(0, $at['dead_heats']);
        $this->assertSame(1, $at['thin_margins']);
        $this->assertSame(1, $at['needs_person']);
    }

    /**
     * The headline counts CATEGORIES, once, however many reasons a category has.
     *
     * Three tiles asked an operator to do an addition that was not the right addition: a
     * category can be blocked and nothing else, or blocked with a dead heat behind the
     * block, and adding the tiles gives a number that is in neither case the number of
     * things to look at.
     */
    public function test_a_category_with_two_reasons_needs_one_person(): void
    {
        $j = $this->judge('Ada Obi');
        foreach (['A', 'B'] as $name) $this->scoreAll($j, $this->nominee($name, 500), 8);

        $a = ResultRelease::attention(ResultRelease::forCycle($this->cycleId));

        $this->assertSame(1, $a['blocked'], 'nobody meets the quorum');
        $this->assertSame(1, $a['needs_person']);
        $this->assertSame(2, $a['provisional'], 'and the nominee-level count is separate');
    }

    public function test_reading_the_release_changes_nothing(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');
        $n  = $this->nominee('Would win', 70);
        $this->scoreAll($j1, $n, 9); $this->scoreAll($j2, $n, 9);

        ResultRelease::forCycle($this->cycleId);

        $this->assertSame('approved',
            (string) DB::table('gates_nominees')->where('id', $n)->value('status'),
            'the release screen crowned somebody by being looked at');
    }

    /** §18 · a screen nothing routes to is a screen nobody can open. */
    public function test_the_release_screen_is_reachable(): void
    {
        $r = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $n = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Support/AdminNav.php');

        $this->assertStringContainsString("'/result-release'", $r, 'no route reaches it');
        $this->assertStringContainsString('result-release', $n, 'no menu entry reaches it');
        $this->assertFileExists(dirname(__DIR__, 2) . '/templates/admin/result-release.twig');
    }
}
