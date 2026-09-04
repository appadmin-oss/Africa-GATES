<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase; // pure math — no DB needed
use AfricaGates\Services\CpiService;

class CpiServiceTest extends TestCase
{
    /**
     * Pure math, so the RuleEngine plays no part: every call here passes the full-credit
     * mark explicitly. The community half is scaled by how DEEP a category's support was
     * as well as by a nominee's share of its leader — see CpiService::depth(), and the
     * dedicated tests at the bottom of this file. Passing 1 makes that factor 1.0 so these
     * expectations are about the curve alone.
     */
    private const FULL = 1;

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
        $this->assertSame(706, $s->nomineeScore(10, 10, 8.0, 0.45, 0.55, null, null, null, self::FULL));
        // no judge score -> only the 45% public component
        $this->assertSame(450, $s->nomineeScore(10, 10, null, 0.45, 0.55, null, null, null, self::FULL));
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
        $lowCohort  = $s->nomineeScore(5, 5,  null, 0.45, 0.55, null, null, null, self::FULL);   // 5/5  = full share
        $highCohort = $s->nomineeScore(5, 50, null, 0.45, 0.55, null, null, null, self::FULL);   // 5/50 = a tenth of the leader
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
        $this->assertSame(0,    $s->nomineeScore(0, 0, null, 0.45, 0.55, null, null, null, self::FULL));    // zero cohort + zero votes → no divide-by-zero
        $this->assertSame(450,  $s->nomineeScore(5, 0, null, 0.45, 0.55, null, null, null, self::FULL));    // cohortMax 0 guarded to 1 → full public share
        $this->assertSame(450,  $s->nomineeScore(20, 10, null, 0.45, 0.55, null, null, null, self::FULL));  // votes > cohort max clamps at 1.0 (not 900)
        $this->assertSame(450,  $s->nomineeScore(10, 10, 0.0, 0.45, 0.55, null, null, null, self::FULL));   // judge 0.0 is a real low score
        $this->assertSame(1000, $s->nomineeScore(10, 10, 10.0, 0.45, 0.55, null, null, null, self::FULL));  // full public + full judge
        // A mark AT the floor is worth nothing on the judge half — that is what the floor
        // means. It was 725 (half the judge weight) and a panel does not call 5/10 half
        // distinguished.
        $this->assertSame(450,  $s->nomineeScore(10, 10, 5.0, 0.45, 0.55, null, null, null, self::FULL));
        // And below it cannot go negative.
        $this->assertSame(450,  $s->nomineeScore(10, 10, 3.0, 0.45, 0.55, null, null, null, self::FULL));

        // ── THE CURVES ARE SETTINGS, AND A BAD ONE CANNOT INVERT THE MEASURE ─
        //
        // Passing 1.0 restores the old linear behaviour exactly, which is what makes this
        // a tuning knob rather than a rewrite. And an exponent of zero would make every
        // share return 1.0 — the whole community half collapsing to a constant, silently,
        // from one bad setting — so it is clamped.
        $this->assertSame(45,  $s->nomineeScore(5, 50, null, 0.45, 0.55, 1.0, null, null, self::FULL));
        $this->assertSame(725, $s->nomineeScore(10, 10, 5.0, 0.45, 0.55, 1.0, 0.0, 1.0, self::FULL));
        $this->assertGreaterThan(0, $s->nomineeScore(5, 50, null, 0.45, 0.55, 0.0, null, null, self::FULL),
            'an exponent of zero was accepted and flattened the community half to a constant');
    }

    /**
     * EIGHTY-NINE VOTES IS NOT NINETEEN HUNDRED, AND IT USED TO SCORE THE SAME.
     *
     * The community half was purely relative — a share of the leader of your own category
     * — so the leader of ANY category collected the whole weight whatever their support
     * was. Two released categories, side by side on the operator's screen:
     *
     *     Ajayi Temitope Oluwarotimi   1,955 votes   community 450
     *     Idowu Olayemi Olubukunola       89 votes   community 450
     *
     * The operator's word was "rigged", and it is the right word: a figure that does not
     * move when the thing it measures changes twentyfold is not measuring it.
     */
    public function test_a_thin_category_does_not_pay_its_leader_what_a_deep_one_does(): void
    {
        $s = new CpiService();

        $deep = $s->nomineeScore(1955, 1955, null);   // leads a category with real backing
        $thin = $s->nomineeScore(89, 89, null);       // leads a category with almost none

        $this->assertSame(450, $deep, 'a leader past the full-credit mark is paid in full');
        $this->assertSame(134, $thin);
        $this->assertGreaterThan($thin, $deep,
            'eighty-nine votes still pays what nineteen hundred pays');
    }

    /**
     * AND IT IS A DISCOUNT, NOT A CLIFF.
     *
     * Square-rooted on purpose. A category at half the full-credit mark keeps seventy per
     * cent of the weight rather than fifty, because a field with five hundred people
     * behind it is a real field — the shape has to punish emptiness without punishing
     * being smaller.
     */
    public function test_the_discount_is_gentle_and_monotonic(): void
    {
        $s = new CpiService();

        $this->assertSame(318, $s->nomineeScore(500, 500, null));   // 0.71 of the weight
        $this->assertSame(450, $s->nomineeScore(5000, 5000, null)); // capped, never above

        // Monotonic: more support behind a category is never worth less.
        $last = -1;
        foreach ([10, 50, 100, 400, 900, 1000, 4000] as $votes) {
            $now = $s->nomineeScore($votes, $votes, null);
            $this->assertGreaterThanOrEqual($last, $now, "depth went backwards at {$votes}");
            $last = $now;
        }
    }

    /**
     * SETTING IT TO ONE RESTORES THE OLD BEHAVIOUR EXACTLY.
     *
     * Which is what makes it a judgement an operator owns rather than a rule imposed here.
     * A continental prize and a school prize do not have the same "full mandate" number.
     */
    public function test_the_full_credit_mark_is_a_setting_that_can_be_turned_off(): void
    {
        $s = new CpiService();

        $this->assertSame(450, $s->nomineeScore(89, 89, null, 0.45, 0.55, null, null, null, 1),
            'the discount cannot be switched off, so it is a rule rather than a setting');
    }
}
