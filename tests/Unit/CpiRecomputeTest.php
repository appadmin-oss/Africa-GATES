<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Console\Commands\CpiRecomputeCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Command-level test driving the per-category normalization fix (Task C1).
 *
 * Two nominees with IDENTICAL raw votes (5) sit in different categories:
 *   - Category 1 cohort max = 5  -> full community share
 *   - Category 2 cohort max = 50 -> small community share
 * With correct per-category normalization the profile linked to the
 * small-cohort nominee must out-score the one in the large cohort. Under the
 * old global-max bug both normalize identically and the scores are equal.
 */
class CpiRecomputeTest extends TestCase
{
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

    private function runRecompute(): void
    {
        $tester = new CommandTester(new CpiRecomputeCommand());
        $tester->execute([]);
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

        $this->runRecompute();

        $p1 = (int) DB::table('gates_profiles')->where('id', 1)->value('cpi_score');
        $p2 = (int) DB::table('gates_profiles')->where('id', 2)->value('cpi_score');

        // Per-category: P1 = 0.45 * (5/5) * 1000 = 450 ; P2 = 0.45 * (5/50) * 1000 = 45
        $this->assertSame(450, $p1);
        $this->assertSame(45, $p2);
        $this->assertGreaterThan($p2, $p1);
    }
}
