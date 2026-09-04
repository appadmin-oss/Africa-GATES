<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Console\Commands\CpiRecomputeCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The rollup that turns nominee scores into the number printed on a person's public page.
 *
 * Two properties, and the second one shipped broken.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE · THE COMMUNITY HALF IS NORMALISED PER CATEGORY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two nominees with IDENTICAL raw votes sit in different categories. Against a cohort
 * whose best is five votes, five votes is the whole community share; against one whose
 * best is fifty it is a tenth of it. Normalised globally they come out equal, and a
 * category nobody votes in becomes the easy category to win.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO · AN UNJUDGED NOMINATION IS NOT A PUBLIC STANDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Below quorum `NomineeScoringService` scores the judge half ZERO rather than
 * renormalising it away, so that popularity alone cannot top a board — and it hands out
 * a `provisional` flag whose stated purpose is that a community-only figure "sits in the
 * same column as a full CPI and reads as one".
 *
 * This rollup dropped that flag, and it is the caller where dropping it costs the most:
 * `cpi_score` and `cpi_tier` are printed on the vote page, the leaderboard, the registry
 * index and a person's own profile, with a gold star beside them. A nominee no judge had
 * opened was published at 450 of 1000 — which the tier ladder calls GOLD — off the votes
 * alone. The understatement the zero exists to be became a verdict the moment it was
 * averaged into somebody's standing.
 *
 * The version of this file that shipped before asserted exactly that: two profiles, no
 * judges anywhere, 450 and 45 written to public columns and checked to the digit.
 */
class CpiRecomputeTest extends TestCase
{
    /** @var int[] */
    private array $crit = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The suite's own criteria, so "complete scorecard" is a fact this file controls.
        // Anything already active would otherwise decide whether a judge here counts.
        DB::table('gates_judge_criteria')->update(['is_active' => 0]);
        foreach (['impact' => 60, 'rigour' => 40] as $slug => $w) {
            $this->crit[] = (int) DB::table('gates_judge_criteria')->insertGetId([
                'programme_id' => null, 'slug' => $slug, 'label' => ucfirst($slug),
                'weight' => $w, 'is_active' => 1, 'sort_order' => count($this->crit),
            ]);
        }
    }

    private function seedProfile(int $id): void
    {
        DB::table('gates_profiles')->insert([
            'id' => $id, 'slug' => "p{$id}", 'display_name' => "P{$id}", 'email' => "p{$id}@x.io",
            'country_code' => 'NG', 'status' => 'approved',
            'verification_tier' => 'none', 'completeness_pct' => 0, 'view_count' => 0,
        ]);
    }

    private function seedNominee(int $id, int $cat, int $votes, ?int $profileId): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => $cat, 'profile_id' => $profileId,
            'name' => "N{$id}", 'country_code' => 'NG', 'status' => 'approved',
            'vote_count' => $votes, 'organic_vote_count' => $votes,
        ]);
    }

    private function judge(string $name): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $name, 'is_active' => 1,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    /** A COMPLETE scorecard — every active criterion — which is what quorum counts. */
    private function score(int $judge, int $nominee, int $cat, float $mark): void
    {
        foreach ($this->crit as $cid) {
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judge, 'nominee_id' => $nominee, 'category_id' => $cat,
                'criterion_id' => $cid, 'score' => $mark,
                'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
            ]);
        }
    }

    /** Quorum is two complete scorecards; both judges mark the same, so the average is $mark. */
    private function panel(int $nominee, int $cat, float $mark): void
    {
        static $n = 0;
        $this->score($this->judge('Judge ' . (++$n)), $nominee, $cat, $mark);
        $this->score($this->judge('Judge ' . (++$n)), $nominee, $cat, $mark);
    }

    private function runRecompute(): void
    {
        (new CommandTester(new CpiRecomputeCommand()))->execute([]);
    }

    public function test_scores_are_normalized_per_category(): void
    {
        $this->seedProfile(1);
        $this->seedProfile(2);
        // Category 1: P1's nominee (5 votes) + a peer (5 votes)  -> cohort max 5
        $this->seedNominee(1, 1, 5, 1);
        $this->seedNominee(2, 1, 5, null);
        // Category 2: P2's nominee (5 votes) + a runaway peer (50) -> cohort max 50
        $this->seedNominee(3, 2, 5, 2);
        $this->seedNominee(4, 2, 50, null);

        // Both judged, identically, so the ONLY thing separating them is the cohort they
        // are normalised against — which is the whole property under test.
        $this->panel(1, 1, 6.0);
        $this->panel(3, 2, 6.0);

        $this->runRecompute();

        $p1 = (int) DB::table('gates_profiles')->where('id', 1)->value('cpi_score');
        $p2 = (int) DB::table('gates_profiles')->where('id', 2)->value('cpi_score');

        // 0.45 * (5/5) + 0.55 * 0.6 = 0.78  ;  0.45 * (5/50) + 0.55 * 0.6 = 0.375
        $this->assertSame(780, $p1);
        $this->assertSame(375, $p2);
        $this->assertGreaterThan($p2, $p1);
    }

    /**
     * THE DEFECT. A nominee nobody has judged does not get a public score off their votes.
     *
     * 5 votes against a cohort max of 5 is the full community share — 450 of 1000, which
     * `CpiService::tierFor()` calls GOLD. Published beside a gold star on the vote page,
     * for somebody no judge has opened.
     */
    public function test_an_unjudged_nomination_does_not_become_a_public_standing(): void
    {
        $this->seedProfile(1);
        $this->seedNominee(1, 1, 5, 1);
        $this->seedNominee(2, 1, 5, null);

        $this->runRecompute();

        $row = DB::table('gates_profiles')->where('id', 1)->first();

        $this->assertSame(0, (int) $row->cpi_score,
            'an unjudged nominee was published with a community-only score as their standing');
        $this->assertSame('unranked', (string) $row->cpi_tier,
            'the tier ladder put a gold star on a nominee no judge has opened');
    }

    /**
     * The history table records MOVEMENTS, and there is no movement in running it again.
     *
     * It used to write a row per approved profile per run — every six hours, forever, two
     * indexes maintained on each insert — of which essentially all repeated the row before.
     * `cpi_last_computed` on the profile already answers "did the recompute run"; the only
     * question this table's own profile_id/computed_at indexes were cut for is "did their
     * standing move, and when", and a wall of duplicates is where that answer went.
     */
    public function test_the_history_records_a_movement_and_not_every_tick(): void
    {
        $this->seedProfile(1);
        $this->seedNominee(1, 1, 5, 1);
        $this->panel(1, 1, 6.0);

        $this->runRecompute();
        $this->runRecompute();
        $this->runRecompute();

        $rows = DB::table('gates_cpi_history')->where('profile_id', 1)->get();
        $this->assertCount(1, $rows, 'the history grew a row per run for a score that never moved');
        $this->assertSame(780, (int) $rows[0]->cpi_score);

        // And a real movement IS recorded — otherwise the assertion above is satisfied by
        // a table nothing writes to at all.
        //
        // The movement is somebody ELSE's votes, which is worth seeing: the community half
        // is a share of the cohort's best, so a rival arriving with fifty votes moves this
        // profile's published standing without them doing anything. Nothing here decides
        // whether that is right — it is the denominator the whole index rests on.
        $this->seedNominee(2, 1, 50, null);
        $this->runRecompute();

        $this->assertCount(2, DB::table('gates_cpi_history')->where('profile_id', 1)->get(),
            'a standing changed and the history did not record it');
    }

    /**
     * And ONE judge is not a panel: quorum is two complete scorecards, so a single
     * enthusiastic marker cannot mint a standing either.
     */
    public function test_one_judge_is_not_enough_to_publish_a_standing(): void
    {
        $this->seedProfile(1);
        $this->seedNominee(1, 1, 5, 1);
        $this->score($this->judge('Solo'), 1, 1, 10.0);

        $this->runRecompute();

        $this->assertSame(0, (int) DB::table('gates_profiles')->where('id', 1)->value('cpi_score'),
            'one judge marking full marks published a standing on their own');
    }

    /**
     * A judged nomination still counts when a sibling nomination is not yet judged.
     *
     * The exclusion is of the unjudged SCORE, never of the profile: a person with one
     * decided award and one still being judged has a standing, and it is the decided one.
     * Averaging the pending nomination in at zero would punish them for being nominated
     * twice.
     */
    public function test_a_pending_second_nomination_does_not_drag_a_decided_one_down(): void
    {
        $this->seedProfile(1);
        // Judged, in a cohort where they lead.
        $this->seedNominee(1, 1, 5, 1);
        $this->panel(1, 1, 6.0);
        // Nominated in a second category, nobody has marked it yet.
        $this->seedNominee(2, 2, 5, 1);
        $this->seedNominee(3, 2, 50, null);

        $this->runRecompute();

        $this->assertSame(780, (int) DB::table('gates_profiles')->where('id', 1)->value('cpi_score'),
            'a nomination still waiting on its panel pulled down an award already decided');
    }
}
