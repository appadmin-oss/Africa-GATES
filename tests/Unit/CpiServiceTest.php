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

    /**
     * ── THE NUMBERS HERE MOVED, AND WHY ──────────────────────────────────────
     *
     * Both halves are curved now. They were linear and the index did not discriminate: on
     * a real four-nominee category, last place — six per cent of the leader's votes and a
     * 7.6 panel mark — scored 414 of 1000, which is `gold` on the ladder below. A score
     * everybody clears measures nothing.
     *
     * Community: the share of the leader is raised to `COMMUNITY_CURVE` (2.0), so half the
     * leader's support is worth a quarter of the weight rather than half of it.
     *
     * Judge: the mark is re-based on the range a panel actually uses — nothing is awarded
     * below `JUDGE_FLOOR` (5.0) in practice, so the bottom half of the scale was dead and
     * handing out a third of the judge weight for free — then raised to `JUDGE_CURVE` (1.5).
     *
     * Every expectation below is derived from those, not chosen: this file is the one place
     * the arithmetic is stated as arithmetic.
     */
    public function test_nominee_score_and_rollup(): void
    {
        $s = new CpiService();
        // Full share → 1.0^2 = 1.0. Judge 8/10 → ((8−5)/5)^1.5 = 0.6^1.5 = 0.4648.
        // 0.45×1000 + 0.55×0.4648×1000 = 450 + 256 = 706.
        $this->assertSame(706, $s->nomineeScore(10, 10, 8.0));
        // no judge score -> only the 45% public component
        $this->assertSame(450, $s->nomineeScore(10, 10, null));
        // Was `500` — the MEAN, which punished a person for being nominated twice: a 900
        // and a 100 came out below a lone 900. It is the best result lifted by each
        // further one now, so 600 with a 400 beside it is 640. The properties that must
        // hold whatever the lift is set to live in CpiRollupTest.
        $this->assertSame(640, $s->profileRollup([400, 600]));
        $this->assertSame(640, $s->profileRollup([600, 400]), 'order changed the answer');
        $this->assertNull($s->profileRollup([]));
    }

    public function test_normalization_is_per_cohort_not_global(): void
    {
        $s = new CpiService();
        // Same raw votes (5), different cohort maxes -> different public share.
        $lowCohort  = $s->nomineeScore(5, 5,  null);   // 5/5  = full share
        $highCohort = $s->nomineeScore(5, 50, null);   // 5/50 = a tenth of the leader
        $this->assertGreaterThan($highCohort, $lowCohort);
        $this->assertSame(450, $lowCohort);
        // 0.1^2 = 0.01 → 4.5, rounds to 5. Under the old linear share this was 45 — a tenth
        // of the leader's support returning a tenth of the weight, which is the flattening
        // the curve exists to remove.
        $this->assertSame(5, $highCohort);
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
        // A mark AT the floor is worth nothing on the judge half — that is what the floor
        // means. It was 725 (half the judge weight) and a panel does not call 5/10 half
        // distinguished.
        $this->assertSame(450,  $s->nomineeScore(10, 10, 5.0));
        // And below it cannot go negative.
        $this->assertSame(450,  $s->nomineeScore(10, 10, 3.0));

        // ── THE CURVES ARE SETTINGS, AND A BAD ONE CANNOT INVERT THE MEASURE ─
        //
        // Passing 1.0 restores the old linear behaviour exactly, which is what makes this
        // a tuning knob rather than a rewrite. And an exponent of zero would make every
        // share return 1.0 — the whole community half collapsing to a constant, silently,
        // from one bad setting — so it is clamped.
        $this->assertSame(45,  $s->nomineeScore(5, 50, null, 0.45, 0.55, 1.0));
        $this->assertSame(725, $s->nomineeScore(10, 10, 5.0, 0.45, 0.55, 1.0, 0.0, 1.0));
        $this->assertGreaterThan(0, $s->nomineeScore(5, 50, null, 0.45, 0.55, 0.0),
            'an exponent of zero was accepted and flattened the community half to a constant');
    }
}
