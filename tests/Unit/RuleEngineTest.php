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
}
