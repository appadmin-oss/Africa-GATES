<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{CycleMaterialiser, ResultRelease};
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
