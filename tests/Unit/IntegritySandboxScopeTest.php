<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\JudgeAnomalyService;
use AfricaGates\Services\JudgeBiasService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A rehearsal is not evidence about a judge.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * HOW THE SANDBOX GOT INTO THE INTEGRITY SCREENS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see DemoSeeder} contains the sandbox in its own programme, and that works because
 * every tally, rank and cut on this platform is computed PER CATEGORY — a demo category
 * simply cannot reach a real one.
 *
 * The integrity scans are the exception, and they are the exception by necessity: "which
 * panels are being judged right now" is a question about CYCLES, so they start at
 * `gates_award_cycles` and walk down. Containment says nothing about that direction.
 *
 * Then the practice run gave real judges a practice ballot, in a cycle deliberately
 * carrying `status = 'judging'` — the newest such cycle on a fresh install. So the marks a
 * real judge left on a ballot captioned NOTHING HERE COUNTS became outlier flags with
 * their name on them, and bias findings measured across nominees that do not exist. The
 * integrity page, whose picker sorts judging-first and newest-first, opened on it by
 * default and presented the rehearsal as the platform's panel.
 */
final class IntegritySandboxScopeTest extends TestCase
{
    private int $sandboxCycle = 0;
    private int $realCycle    = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_judge_criteria')->delete();
        DB::table('gates_judge_criteria')->insert([['id' => 1, 'slug' => 'a', 'label' => 'A', 'weight' => 100, 'is_active' => 1]]);

        DB::table('gates_judges')->insert([
            ['id' => 1, 'name' => 'Real One',   'email' => 'j1@e.com', 'is_active' => 1],
            ['id' => 2, 'name' => 'Real Two',   'email' => 'j2@e.com', 'is_active' => 1],
            ['id' => 3, 'name' => 'Real Three', 'email' => 'j3@e.com', 'is_active' => 1],
        ]);

        $this->sandboxCycle = $this->cycleUnder(DemoSeeder::PROGRAMME_SLUG, 0, 2025, 90);
        $this->realCycle    = $this->cycleUnder('real-awards', 1, 2026, 190);
    }

    /**
     * A programme with one judging cycle, one category and one nominee, scored 9/9/3 by
     * the three judges — the shape that produces exactly one anomaly flag.
     */
    private function cycleUnder(string $slug, int $active, int $year, int $idBase): int
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => $slug, 'is_active' => $active,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => $year, 'status' => 'judging',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cid, 'slug' => 'c' . $idBase, 'title' => 'C', 'sort_order' => 1,
        ]);
        $nom = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => 'N' . $idBase, 'status' => 'approved',
            'country_code' => 'NG', 'vote_count' => 0, 'organic_vote_count' => 0,
        ]);

        foreach ([1 => 9, 2 => 9, 3 => 3] as $judge => $score) {
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id' => $judge, 'nominee_id' => $nom, 'category_id' => $cat,
                'criterion_id' => 1, 'score' => $score,
            ]);
        }

        return $cid;
    }

    // ════════════════════════════════════════════════════════════════════════

    /** The scan that walks every judging cycle does not walk into the sandbox. */
    public function test_the_active_scan_skips_the_sandbox(): void
    {
        $r = (new JudgeAnomalyService())->scanActive();

        $this->assertCount(1, $r['flags'],
            'the practice ballot produced a flag against a real judge');
        $this->assertSame(1, $r['nominees_scanned'],
            'a demo nominee was scanned');
    }

    /** And a direct call with the sandbox cycle finds nothing either. */
    public function test_the_sandbox_cycle_scans_to_nothing(): void
    {
        $r = (new JudgeAnomalyService())->forCycle($this->sandboxCycle);

        $this->assertSame([], $r['flags']);
        $this->assertSame(0, $r['nominees_scanned']);
    }

    /** The real cycle is still scanned — the filter excludes the sandbox, not the work. */
    public function test_a_real_cycle_is_still_scanned(): void
    {
        $r = (new JudgeAnomalyService())->forCycle($this->realCycle);

        $this->assertCount(1, $r['flags']);
        $this->assertSame('Real Three', $r['flags'][0]['judge']);
    }

    /** The bias scan reads no sandbox scores, so it has nothing to difference. */
    public function test_the_bias_scan_reads_no_sandbox_scores(): void
    {
        $this->assertSame(0, JudgeBiasService::forCycle($this->sandboxCycle)['scores']);
        $this->assertSame(3, JudgeBiasService::forCycle($this->realCycle)['scores'],
            'the real cycle stopped being measured');
    }

    /**
     * And the filter is inert when there is no sandbox — a `!=` against a programme id of
     * zero would silently drop every cycle on an installation that never built one.
     */
    public function test_nothing_is_excluded_when_no_sandbox_exists(): void
    {
        DB::table('gates_award_programmes')->where('slug', DemoSeeder::PROGRAMME_SLUG)->delete();
        DB::table('gates_award_cycles')->where('id', $this->sandboxCycle)->delete();

        $this->assertSame(0, DemoSeeder::programmeId());
        $this->assertCount(1, (new JudgeAnomalyService())->scanActive()['flags']);
        $this->assertSame(3, JudgeBiasService::forCycle($this->realCycle)['scores']);
    }
}
