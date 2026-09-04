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
     * AND IT CANNOT MANUFACTURE VOTES — NOR DESTROY THE EVIDENCE OF THEM.
     *
     * A nominee carrying a stored total with not one ballot row behind it is not a drifted
     * counter. It is an ABSENT LEDGER — an import, a restore, a migration from whatever ran
     * before this platform — and that stored number is then the only surviving record that
     * the support existed at all.
     *
     * Writing the ledger's answer there repairs nothing and erases that record, on a button
     * labelled "recount", pressed by somebody trying to fix a scoring fault. The first
     * version of this file asserted exactly that erasure, which is how close it came to
     * shipping: 1,536 votes to zero, on a live cycle, irreversibly.
     *
     * So the counters survive, and the refusal is REPORTED — an operator who presses recount
     * and sees nothing move has learned something specific and actionable.
     */
    public function test_a_recount_refuses_a_nominee_whose_ballots_are_missing_entirely(): void
    {
        $n = $this->nominee('Dr. Adegboyega Aborode', 1536, 0);

        $r   = VoteRecount::category($this->categoryId);
        $row = DB::table('gates_nominees')->find($n);

        $this->assertSame(1536, (int) $row->vote_count,
            'a stored total with no ballots behind it was destroyed by a recount');
        $this->assertSame(0, (int) $row->organic_vote_count);

        $this->assertCount(1, $r['changed'],
            'the recount refused silently — nothing tells the operator why nothing moved');
        $this->assertSame('no ballots on record', $r['changed'][0]['refused'] ?? null);
        $this->assertSame($r['changed'][0]['was'], $r['changed'][0]['now'],
            'a refusal reported a movement');
    }

    /**
     * THE REFUSAL IS ABOUT AN ABSENT LEDGER, NOT ABOUT ZERO.
     *
     * A nominee whose ballots are all non-organic really should come out with no community
     * support, and the guard above must not be so broad that it protects that stored total
     * too — that would leave the very drift this repair exists for permanently unfixable.
     * The ledger EXISTS here; it just says something the operator will not enjoy.
     */
    public function test_a_ledger_that_exists_and_says_zero_organic_is_still_written(): void
    {
        $n = $this->nominee('Dr. Adegboyega Aborode', 1536, 1536);

        DB::table('gates_votes')->insert([
            'nominee_id' => $n, 'category_id' => $this->categoryId,
            'voter_email_hash' => 'paid', 'vote_type' => 'paid', 'weight' => 40,
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);

        $r   = VoteRecount::category($this->categoryId);
        $row = DB::table('gates_nominees')->find($n);

        $this->assertSame(40, (int) $row->vote_count);
        $this->assertSame(0, (int) $row->organic_vote_count,
            'purchased votes were left standing as community support');
        $this->assertArrayNotHasKey('refused', $r['changed'][0],
            'a nominee with a real ledger was refused as if it were missing');
    }

    /** A category whose counters already agree reports no change, and writes nothing. */
    public function test_a_category_that_agrees_reports_no_change(): void
    {
        $this->nominee('Dr. Adegboyega Aborode', 0, 0);

        $this->assertFalse(VoteRecount::categoryDrifts($this->categoryId));
        $this->assertSame([], VoteRecount::category($this->categoryId)['changed']);
    }

    /**
     * AND THE OPERATOR IS TOLD, IN WORDS, ON THE SCREEN.
     *
     * From the operator's chair a refusal and a category that already agreed look identical:
     * they press the button and nothing moves. They call for opposite next steps — one is
     * "the community half really is zero, publish it", the other is "your ballot table has
     * been lost, do not publish anything yet" — so the flash has to distinguish them. A
     * refusal the service reports and the screen swallows is a refusal that never happened.
     */
    public function test_the_screen_says_which_nominees_were_left_alone_and_why(): void
    {
        $this->nominee('Dr. Adegboyega Aborode', 1536, 0);

        $_SESSION['admin_id'] = 1;
        unset($_SESSION['flash_ok']);

        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/result-release/recount')
            ->withParsedBody(['category' => (string) $this->categoryId]);

        $view = \Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates');
        (new \AfricaGates\Admin\Controllers\ResultReleaseController($view))
            ->recount($req, new \Slim\Psr7\Response());

        $said = (string) ($_SESSION['flash_ok'] ?? '');
        unset($_SESSION['flash_ok'], $_SESSION['admin_id']);

        $this->assertStringContainsString('Dr. Adegboyega Aborode', $said,
            'the recount left a nominee alone without naming them');
        $this->assertStringContainsString('1536', $said,
            'the stored total the refusal protected is not shown');
        $this->assertStringContainsString('not one ballot row', $said,
            'the screen does not say WHY nothing moved, so it reads as "already correct"');
        $this->assertStringNotContainsString('every stored total already agreed', $said,
            'a refusal was reported as a category whose counters were already right');
    }
}
