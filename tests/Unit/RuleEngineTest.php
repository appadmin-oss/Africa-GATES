<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\RuleEngine;
use AfricaGates\Services\NomineeScoringService;

/**
 * Phase 2 — RuleEngine: code defaults, scope precedence (global → cycle), and
 * proof that a per-cycle weight override actually changes the computed CPI.
 */
class RuleEngineTest extends TestCase
{
    public function test_defaults_when_no_override(): void
    {
        $eff = (new RuleEngine())->effective(1, 1);
        $this->assertSame(0.45, $eff['community_weight']);
        $this->assertSame(0.55, $eff['judge_weight']);
    }

    public function test_cycle_override_wins_over_global(): void
    {
        $r = new RuleEngine();
        $r->set('global', null, ['community_weight' => 0.5, 'judge_weight' => 0.5]);
        $r->set('cycle', 7, ['community_weight' => 0.8, 'judge_weight' => 0.2]);

        $w = $r->weights(null, 7);
        $this->assertEqualsWithDelta(0.8, $w['community'], 0.001);
        $this->assertEqualsWithDelta(0.2, $w['judge'], 0.001);

        // A cycle with no override falls back to the global layer.
        $this->assertEqualsWithDelta(0.5, $r->weights(null, 99)['community'], 0.001);
    }

    public function test_override_changes_computed_cpi(): void
    {
        // Small fixtures: the community half is scaled by how deep a category's support
        // was (CpiService::depth), so the full-credit mark is set to 1 here and depth
        // becomes 1.0. The discount has its own tests in CpiServiceTest.
        (new \AfricaGates\Services\RuleEngine())->set('global', null,
            ['community_full_credit_votes' => 1]);
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'C1']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'A', 'status' => 'approved', 'vote_count' => 10, 'organic_vote_count' => 10]);
        // Ten real voters behind the ten votes. This test is about WEIGHTS, and without
        // rows the fixture lands in the unmeasured path where the people term — 70% of the
        // community half — is not paid, so the figure below would be measuring that
        // instead. One person per vote is the case the `ideal` yardstick is named for.
        for ($v = 0; $v < 10; $v++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 1, 'category_id' => 1, 'vote_type' => 'standard', 'weight' => 1,
                'voter_email_hash' => \AfricaGates\Services\VoteService::voterHash('rw' . $v . '@x.test'),
            ]);
        }

        $scoring = new NomineeScoringService();
        // Default 45/55, no judge scores → 0.45 * 1000 = 450.
        $this->assertSame(450, $scoring->scoreCategory(1)[1]['cpi_score']);

        // Reconfigure THIS cycle to 100% community → 1.0 * 1000 = 1000.
        (new RuleEngine())->set('cycle', 1, ['community_weight' => 1.0, 'judge_weight' => 0.0]);
        $this->assertSame(1000, $scoring->scoreCategory(1)[1]['cpi_score']);
    }

    public function test_weights_normalize_non_normalized_inputs(): void
    {
        $r = new RuleEngine();
        $r->set('global', null, ['community_weight' => 3, 'judge_weight' => 1]); // sums to 4, not 1
        $w = $r->weights();
        $this->assertEqualsWithDelta(0.75, $w['community'], 0.001);
        $this->assertEqualsWithDelta(0.25, $w['judge'], 0.001);
    }

    public function test_programme_layer_sits_between_global_and_cycle(): void
    {
        $r = new RuleEngine();
        $r->set('global', null, ['community_weight' => 0.5, 'judge_weight' => 0.5]);
        $r->set('programme', 4, ['community_weight' => 0.7, 'judge_weight' => 0.3]);

        // Programme override applies when no cycle override exists…
        $this->assertEqualsWithDelta(0.7, $r->weights(4, 9)['community'], 0.001);

        // …and a cycle override outranks the programme layer.
        $r->set('cycle', 9, ['community_weight' => 0.9, 'judge_weight' => 0.1]);
        $this->assertEqualsWithDelta(0.9, $r->weights(4, 9)['community'], 0.001);
    }

    /**
     * `merge()` CHANGES SOME KEYS AND LEAVES THE REST ALONE.
     *
     * ── WHY THIS IS THE MOST EXPENSIVE MISTAKE AVAILABLE IN THIS CLASS ──────
     *
     * A scope holds ONE json document, so `set()` necessarily replaces it — and the global
     * document carries the weights, the fraud bands, the quorum, the community-return
     * accrual and `community_basis`, the rule that decides every published index. A writer
     * that calls `set()` with three keys erases the other nine with no error anywhere, and
     * the first symptom is every cycle on the platform scored by defaults nobody chose.
     *
     * Both settings-screen writers did the read-and-merge by hand, in seven identical
     * lines each. Correct, twice — which is the state a third writer gets wrong.
     */
    public function test_merge_keeps_the_keys_it_was_not_given(): void
    {
        // A VALUE THAT IS NOT THE DEFAULT. `community_return_bps` defaults to 5000, so a
        // fixture that stores 5000 and then asserts it survived passes whether the key
        // survived or fell back — which is what the first version of this test did, and a
        // mutation that removed the merge entirely did not fail it.
        $this->assertNotSame(3750, RuleEngine::DEFAULTS['community_return_bps'],
            'this fixture has to differ from the default or it proves nothing');

        $r = new RuleEngine();
        $r->set('global', null, [
            'community_return_bps' => 3750,
            'community_basis'      => 'reach',
        ]);

        $r->merge('global', null, ['community_basis' => 'ideal']);

        $eff = $r->effective(null, null);
        $this->assertSame('ideal', $eff['community_basis'], 'the change did not take');
        $this->assertSame(3750, $eff['community_return_bps'],
            'changing one rule erased another — and the global document holds the weights, '
            . 'the quorum and the basis that decides every published index');
    }

    /** And it works at a scope that has no row yet. */
    public function test_merge_onto_nothing_is_the_new_keys(): void
    {
        $r = new RuleEngine();
        $r->merge('cycle', 41, ['community_basis' => 'reach']);

        $this->assertSame('reach', $r->effective(null, 41)['community_basis']);
        // And it did not leak into a different scope.
        $this->assertSame(RuleEngine::DEFAULTS['community_basis'],
            $r->effective(null, 42)['community_basis']);
    }

    /**
     * `set()` STILL REPLACES, AND THAT IS WHAT IT IS FOR.
     *
     * Pinned so nobody "fixes" it into a second merge: a test fixture declaring a whole
     * ruleset needs the replacement, and two functions that both merge would leave no way
     * to clear a key at all.
     */
    public function test_set_still_replaces_the_whole_document(): void
    {
        $r = new RuleEngine();
        $r->set('global', null, ['community_return_bps' => 3750, 'community_basis' => 'reach']);
        $r->set('global', null, ['community_basis' => 'ideal']);

        // Back to the DEFAULT, not to 3750: the document was replaced, so the key is gone
        // and `effective()` falls through to the hardcoded value.
        $this->assertSame(RuleEngine::DEFAULTS['community_return_bps'],
            $r->effective(null, null)['community_return_bps'],
            'set() must replace — merge() is the one that preserves');
    }
}
