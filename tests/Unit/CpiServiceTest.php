<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase; // pure math — no DB needed
use AfricaGates\Services\CpiService;

class CpiServiceTest extends TestCase
{
    public function test_tier_thresholds(): void
    {
        $s = new CpiService();
        $this->assertSame('diamond', $s->tierFor(900));
        $this->assertSame('diamond', $s->tierFor(850));
        $this->assertSame('gold', $s->tierFor(450));
        $this->assertSame('bronze', $s->tierFor(100));
        $this->assertSame('unranked', $s->tierFor(0));
    }

    public function test_nominee_score_and_rollup(): void
    {
        $s = new CpiService();
        // 0.45*1.0 (full public) + 0.55*0.8 (judge 8/10) = 0.89 -> 890
        $this->assertSame(890, $s->nomineeScore(10, 10, 8.0));
        // no judge score -> only the 45% public component
        $this->assertSame(450, $s->nomineeScore(10, 10, null));
        $this->assertSame(500, $s->profileRollup([400, 600]));
        $this->assertNull($s->profileRollup([]));
    }

    public function test_normalization_is_per_cohort_not_global(): void
    {
        $s = new CpiService();
        // Same raw votes (5), different cohort maxes -> different public share.
        $lowCohort  = $s->nomineeScore(5, 5,  null);   // 5/5  = full share
        $highCohort = $s->nomineeScore(5, 50, null);   // 5/50 = small share
        $this->assertGreaterThan($highCohort, $lowCohort);
        $this->assertSame(450, $lowCohort);
        $this->assertSame(45, $highCohort);
    }

    public function test_baseline_score(): void
    {
        $s = new CpiService();
        // premium(100) verify only: 0.50*1.0*1000 = 500
        $this->assertSame(500, $s->baselineScore('premium', 0.0, 0));
        // none verify, full completeness: 0.30*1.0*1000 = 300
        $this->assertSame(300, $s->baselineScore('none', 100.0, 0));
    }

    public function test_nominee_score_boundary_cases(): void
    {
        $s = new CpiService();
        $this->assertSame(0,    $s->nomineeScore(0, 0, null));    // zero cohort + zero votes → no divide-by-zero
        $this->assertSame(450,  $s->nomineeScore(5, 0, null));    // cohortMax 0 guarded to 1 → full public share
        $this->assertSame(450,  $s->nomineeScore(20, 10, null));  // votes > cohort max clamps at 1.0 (not 900)
        $this->assertSame(450,  $s->nomineeScore(10, 10, 0.0));   // judge 0.0 is a real low score
        $this->assertSame(1000, $s->nomineeScore(10, 10, 10.0));  // full public + full judge
        $this->assertSame(725,  $s->nomineeScore(10, 10, 5.0));   // 0.45*1000 + 0.55*0.5*1000
    }
}
