<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The panel could not score, and neither side could tell.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE CHAIN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The rubric is seeded by an OPTIONAL migrate flag (`--with-seed-rubric`), so a
 * deployment that ran plain `db:migrate` has no criteria at all. Four things then went
 * wrong together, and every one of them was silent:
 *
 *   1. `count($n['scores']) === count($criteria)` is `0 === 0` — TRUE. Every nominee
 *      reported itself COMPLETE.
 *   2. `progress.scored` counted them all, so the ballot read "N of N scored".
 *   3. In the browser, `[].every()` is also true, so the client agreed.
 *   4. `saveScore()` ended `return ['ok' => true, 'saved' => $valid]` unconditionally.
 *      With no rubric every posted score was skipped as unrecognised, $valid stayed 0,
 *      and it answered ok:true — so the ballot showed its green "saved" state and stored
 *      nothing.
 *
 * A judge saw a finished ballot, saved successfully, and left no scores behind. Reporting
 * success for work that was discarded is the worst failure this file can have: nobody can
 * discover it, and the scores are simply absent when the round is counted.
 *
 * These tests hold all four, plus the reverse — that a real rubric still works — because
 * a fix that locked a working ballot would be its own outage.
 */
final class JudgeCannotScoreTest extends TestCase
{
    private JudgeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new JudgeService();

        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'Educators']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'nominations_open' => '2020-01-01 00:00:00', 'voting_open' => '2020-02-01 00:00:00',
            'voting_close' => '2020-03-01 00:00:00', 'results_date' => '2020-04-01 00:00:00',
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'Ada Nwosu', 'status' => 'approved',
            'vote_count' => 5, 'organic_vote_count' => 5,
        ]);
        // Assignments are a JSON column on the judge, not a join table.
        DB::table('gates_judges')->insert([
            'id' => 1, 'name' => 'J1', 'email' => 'j1@x.io', 'is_active' => 1,
            'programme_ids' => json_encode([1]),
        ]);
    }

    private function addRubric(): void
    {
        DB::table('gates_judge_criteria')->insert([
            'id' => 1, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 25, 'is_active' => 1,
        ]);
    }

    // ══ with no rubric ═══════════════════════════════════════════════════════

    /** THE ONE THAT MATTERS: a save that stores nothing must not report success. */
    public function test_saving_with_no_rubric_does_not_claim_success(): void
    {
        $r = $this->svc->saveScore(1, 1, [1 => 8], null);

        $this->assertFalse($r['ok'], 'a save that stored nothing reported ok:true');
        $this->assertSame(0, $r['saved']);
        $this->assertStringContainsString('rubric', strtolower($r['message']));
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->count());
    }

    /** And the ballot must not look finished. */
    public function test_a_nominee_is_not_complete_when_there_is_nothing_to_score(): void
    {
        $b = $this->svc->ballot(1, 1);

        $nominee = $b['categories'][0]['nominees'][0];
        $this->assertFalse($nominee['complete'], 'a nominee read as complete with an empty rubric');
        $this->assertSame(0, $b['progress']['scored'], 'the progress counter claimed scored nominees');
        $this->assertSame(1, $b['progress']['total']);
    }

    /** The ballot locks and says why, aimed at somebody who can act on it. */
    public function test_the_ballot_locks_and_names_the_cause(): void
    {
        $b = $this->svc->ballot(1, 1);

        $this->assertFalse($b['judging_open'], 'an unusable ballot was left open');
        $this->assertTrue($b['no_rubric']);
        $this->assertStringContainsString('rubric', strtolower($b['lock_reason']));
        // It must point at the organisers, because the judge reading it cannot fix it.
        $this->assertStringContainsString('organisers', strtolower($b['lock_reason']));
    }

    // ══ with a rubric — the ballot must still work ════════════════════════════

    public function test_a_real_rubric_opens_the_ballot(): void
    {
        $this->addRubric();
        $b = $this->svc->ballot(1, 1);

        $this->assertTrue($b['judging_open'], 'a working ballot was locked');
        $this->assertFalse($b['no_rubric']);
        $this->assertSame('', $b['lock_reason']);
        $this->assertCount(1, $b['criteria']);
    }

    public function test_a_score_saves_and_the_nominee_completes(): void
    {
        $this->addRubric();

        $r = $this->svc->saveScore(1, 1, [1 => 8], 'Strong evidence.');
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame(1, $r['saved']);

        $b = $this->svc->ballot(1, 1);
        $this->assertTrue($b['categories'][0]['nominees'][0]['complete']);
        $this->assertSame(1, $b['progress']['scored']);
        $this->assertSame(8, $b['categories'][0]['nominees'][0]['scores'][1]);
    }

    /** A stale page posting ids from an old rubric must be told, not thanked. */
    public function test_scores_that_match_no_criterion_are_reported_not_swallowed(): void
    {
        $this->addRubric();

        $r = $this->svc->saveScore(1, 1, [999 => 8], null);

        $this->assertFalse($r['ok'], 'unrecognised scores were reported as saved');
        $this->assertStringContainsString('rubric', strtolower($r['message']));
        $this->assertSame(0, DB::table('gates_judge_criteria_scores')->count());
    }

    /** A note written before any score is a legitimate save and must stay one. */
    public function test_a_notes_only_save_still_succeeds(): void
    {
        $this->addRubric();

        $r = $this->svc->saveScore(1, 1, [], 'Reading the dossier first.');

        $this->assertTrue($r['ok'], 'a judge could not save a note before scoring');
        $this->assertSame(0, $r['saved']);
        $this->assertSame(1, DB::table('gates_judge_notes')->count());
    }

    // ══ the other locks still hold ═══════════════════════════════════════════

    public function test_scoring_outside_the_judging_phase_is_still_refused(): void
    {
        $this->addRubric();
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'voting']);

        $r = $this->svc->saveScore(1, 1, [1 => 8], null);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('judging phase', $r['message']);

        $b = $this->svc->ballot(1, 1);
        $this->assertFalse($b['judging_open']);
        // The phase, not the rubric, is the reason here — the message must not misdiagnose.
        $this->assertFalse($b['no_rubric']);
    }
}
