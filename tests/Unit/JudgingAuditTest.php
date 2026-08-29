<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\JudgingAudit;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Auditing how one award programme judged, and who judged it.
 *
 * ── WHAT THIS IS FOR ─────────────────────────────────────────────────────────
 *
 * `gates_judge_score_log` has recorded every change to every mark since the day it was
 * added, and nothing read it. An organiser asked "did anybody change a score after the
 * panel met" had to answer that they could not tell — on the platform whose whole
 * argument is that its results can be checked.
 *
 * The properties held here are the ones that decide whether the answer can be trusted:
 * that a first mark is not reported as a change, that a rehearsal panel is not reported
 * as evidence about real judges, that a judge with nobody to compare against is not
 * reported as perfectly average, and that a declared conflict followed by scoring is
 * surfaced as the fact it is.
 */
final class JudgingAuditTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;
    /** @var array<string,int> */
    private array $crit = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026,
            'status' => 'judging', 'results_date' => '2026-12-01 00:00:00',
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'primary', 'title' => 'Primary',
        ]);
        foreach (['impact' => 'Impact', 'rigour' => 'Rigour'] as $slug => $label) {
            $this->crit[$slug] = (int) DB::table('gates_judge_criteria')->insertGetId([
                'programme_id' => $this->programmeId, 'slug' => $slug,
                'label' => $label, 'weight' => 50, 'is_active' => 1,
            ]);
        }
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');

        return $b->build();
    }

    private function nominee(string $name): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
        ]);
    }

    private function judge(string $name, string $email = ''): int
    {
        return (int) DB::table('gates_judges')->insertGetId([
            'name' => $name, 'is_active' => 1,
            'email' => $email !== '' ? $email : strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'programme_ids' => json_encode([$this->programmeId]),
        ]);
    }

    private function score(int $judge, int $nominee, string $crit, int $score, string $at = '2026-11-01 09:00:00'): void
    {
        DB::table('gates_judge_criteria_scores')->insert([
            'judge_id' => $judge, 'nominee_id' => $nominee, 'category_id' => $this->categoryId,
            'criterion_id' => $this->crit[$crit], 'score' => $score,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    // ══ the process ══════════════════════════════════════════════════════════

    /**
     * The smallest panel, not the average one.
     *
     * A mean would hide the nominee judged by one person inside an average of four — and
     * that nominee is the entire question somebody defending a result is being asked.
     */
    public function test_coverage_reports_the_thinnest_panel_any_nominee_faced(): void
    {
        $a = $this->nominee('Well Judged');
        $b = $this->nominee('Barely Judged');
        $c = $this->nominee('Not Judged At All');

        $j1 = $this->judge('Ada One');
        $j2 = $this->judge('Bola Two');
        $j3 = $this->judge('Chidi Three');

        foreach ([$j1, $j2, $j3] as $j) $this->score($j, $a, 'impact', 7);
        $this->score($j1, $b, 'impact', 6);

        $r = JudgingAudit::forProgramme($this->programmeId);

        $this->assertSame(3, $r['totals']['nominees']);
        $this->assertSame(2, $r['totals']['judged']);
        $this->assertSame(1, $r['totals']['unjudged'], 'a nominee nobody scored is the headline fact');

        $cycle = $r['cycles'][0];
        $this->assertSame(0, $cycle['min_panel'], 'the unjudged nominee must drag the minimum to zero');
        $this->assertSame(3, $cycle['max_panel']);
        $this->assertSame(1, $cycle['thin'], 'the nominee seen by one judge');
        $this->assertSame(1, $cycle['unjudged']);
    }

    /**
     * A first mark is not a change.
     *
     * `old_score` NULL means this was the first mark for that pair — not a change, and
     * not a score of zero. The column was built with that distinction and reporting first
     * marks as edits would make every judged nominee look tampered with.
     */
    public function test_a_first_mark_is_not_reported_as_a_change(): void
    {
        $n = $this->nominee('Scored Once');
        $j = $this->judge('Ada One');
        $this->score($j, $n, 'impact', 7);

        DB::table('gates_judge_score_log')->insert([
            'judge_id' => $j, 'nominee_id' => $n, 'criterion_id' => $this->crit['impact'],
            'old_score' => null, 'new_score' => 7, 'changed_at' => '2026-11-01 09:00:00',
        ]);

        $this->assertSame([], JudgingAudit::forProgramme($this->programmeId)['changes'],
            'a first mark was reported as an edit');
    }

    /** A mark moved after the results date is the fact an audit exists to surface. */
    public function test_a_mark_changed_after_the_results_date_is_flagged_as_such(): void
    {
        $n = $this->nominee('Late Edit');
        $j = $this->judge('Ada One');
        $this->score($j, $n, 'impact', 9, '2026-11-01 09:00:00');

        DB::table('gates_judge_score_log')->insert([
            'judge_id' => $j, 'nominee_id' => $n, 'criterion_id' => $this->crit['impact'],
            'old_score' => 4, 'new_score' => 9, 'changed_at' => '2026-12-09 22:14:00',
        ]);

        $changes = JudgingAudit::forProgramme($this->programmeId)['changes'];

        $this->assertCount(1, $changes);
        $this->assertSame(4, $changes[0]['old']);
        $this->assertSame(9, $changes[0]['new']);
        $this->assertTrue($changes[0]['after_results'],
            'a mark raised by five points a week after the results date read as routine');
        $this->assertTrue($changes[0]['late']);
        $this->assertSame('Impact', $changes[0]['criterion'], 'the criterion was not named');
    }

    // ══ the judges ═══════════════════════════════════════════════════════════

    /**
     * A judge with nobody to compare against has no lean — not a lean of zero.
     *
     * Counting their own mark as the panel reports a gap of exactly 0.0, which reads as
     * "perfectly average" and means "nobody else looked". That is the opposite of what
     * happened, on the screen where it matters most.
     */
    public function test_a_sole_scorer_has_no_lean_rather_than_a_lean_of_zero(): void
    {
        $n = $this->nominee('Seen By One');
        $j = $this->judge('Ada Alone');
        $this->score($j, $n, 'impact', 10);

        $judges = JudgingAudit::forProgramme($this->programmeId)['judges'];

        $this->assertCount(1, $judges);
        $this->assertNull($judges[0]['lean'],
            'a judge nobody could be compared against was reported as perfectly average');
        $this->assertSame(0, $judges[0]['compared']);
        $this->assertSame(1, $judges[0]['scores']);
    }

    /** And where there IS a panel, the lean is against the others, never against itself. */
    public function test_the_lean_is_measured_against_the_rest_of_the_panel(): void
    {
        $n  = $this->nominee('Panel Of Three');
        $j1 = $this->judge('Generous Ada');
        $j2 = $this->judge('Bola Two');
        $j3 = $this->judge('Chidi Three');

        $this->score($j1, $n, 'impact', 10);
        $this->score($j2, $n, 'impact', 4);
        $this->score($j3, $n, 'impact', 4);

        $by = [];
        foreach (JudgingAudit::forProgramme($this->programmeId)['judges'] as $j) {
            $by[$j['judge']] = $j;
        }

        // Ada marked 10 against a peer mean of 4 — a lean of +6, not +4 (which is what
        // comparing against a mean that includes her own 10 would give).
        $this->assertSame(6.0, $by['Generous Ada']['lean']);
        $this->assertSame(1, $by['Generous Ada']['compared']);
        $this->assertSame(-3.0, $by['Bola Two']['lean']);
    }

    /**
     * The sandbox is not evidence about anybody.
     *
     * DemoSeeder creates a real judge row with real marks so an operator can walk the
     * portal. An audit that counted them would report rehearsal marks as findings about a
     * real panel — and the sandbox reaching a public or evidential surface is one of the
     * things this codebase says must never happen.
     */
    public function test_the_rehearsal_panellist_is_not_audited_as_a_real_judge(): void
    {
        $n    = $this->nominee('Judged By Both');
        $real = $this->judge('Ada Real');
        $demo = $this->judge('DEMO Test Judge',
                             'demo.judge@' . \AfricaGates\Services\DemoSeeder::MAIL_DOMAIN);

        $this->score($real, $n, 'impact', 7);
        $this->score($demo, $n, 'impact', 1);

        $r = JudgingAudit::forProgramme($this->programmeId);

        $this->assertSame(1, $r['totals']['judges'], 'the sandbox judge was counted as a panellist');
        $this->assertSame('Ada Real', $r['judges'][0]['judge']);
        $this->assertNull($r['judges'][0]['lean'],
            'the rehearsal mark became the panel the real judge was compared against');
    }

    // ══ the one finding that is a fact ═══════════════════════════════════════

    /**
     * A judge who declared a conflict on this programme and scored in it anyway.
     *
     * The declaration was collected and stored and never compared against what the judge
     * then did. It needs no threshold and carries no caveat: two things happened, and
     * both are recorded.
     */
    public function test_a_declared_conflict_followed_by_scoring_is_surfaced(): void
    {
        $n = $this->nominee('Scored By A Conflicted Judge');
        $j = $this->judge('Conflicted Ada');

        DB::table('gates_judge_coi')->insert([
            'judge_id' => $j, 'programme_id' => $this->programmeId,
            'reason' => 'Sits on the board of a nominated school.',
            'created_at' => '2026-10-01 09:00:00',
        ]);
        $this->score($j, $n, 'impact', 9, '2026-11-01 09:00:00');

        $conflicts = JudgingAudit::forProgramme($this->programmeId)['conflicts'];

        $this->assertCount(1, $conflicts);
        $this->assertSame('Conflicted Ada', $conflicts[0]['judge']);
        $this->assertSame(1, $conflicts[0]['scores']);
        $this->assertTrue($conflicts[0]['declared_first'],
            'declaring and then scoring is a control that did not hold, and reads differently '
            . 'from scoring and then recusing');
    }

    /** A conflict declared on a programme they never scored is not a finding. */
    public function test_a_conflict_with_no_scoring_is_not_reported(): void
    {
        $this->nominee('Nobody Scored This');
        $j = $this->judge('Recused Ada');

        DB::table('gates_judge_coi')->insert([
            'judge_id' => $j, 'programme_id' => $this->programmeId,
            'reason' => 'Former colleague of a nominee.', 'created_at' => '2026-10-01 09:00:00',
        ]);

        $this->assertSame([], JudgingAudit::forProgramme($this->programmeId)['conflicts'],
            'a judge who declared and then stayed out was reported as a breach');
    }

    // ══ and the screen itself ════════════════════════════════════════════════

    /**
     * The audit renders, and the trail is on it.
     *
     * Asserted on the RENDERED page rather than on the array, because the whole point of
     * this work is that the data already existed and had no screen. A service returning
     * a correct array that no template reads is the bug, not the fix.
     */
    public function test_the_screen_shows_the_trail_and_the_breach(): void
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        $n = $this->nominee('Audited Nominee');
        $j = $this->judge('Conflicted Ada');

        DB::table('gates_judge_coi')->insert([
            'judge_id' => $j, 'programme_id' => $this->programmeId,
            'reason' => 'Sits on the board of a nominated school.',
            'created_at' => '2026-10-01 09:00:00',
        ]);
        $this->score($j, $n, 'impact', 9, '2026-11-01 09:00:00');
        DB::table('gates_judge_score_log')->insert([
            'judge_id' => $j, 'nominee_id' => $n, 'criterion_id' => $this->crit['impact'],
            'old_score' => 3, 'new_score' => 9, 'changed_at' => '2026-12-09 22:14:00',
        ]);

        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/admin/judging-audit')
            ->withQueryParams(['programme' => (string) $this->programmeId]);

        $html = (string) $this->container()
            ->get(\AfricaGates\Admin\Controllers\JudgingAuditController::class)
            ->index($req, new \Slim\Psr7\Response())
            ->getBody();

        $this->assertStringContainsString('Incredible Principal Awards', $html);
        $this->assertStringContainsString('Conflicted Ada', $html);
        $this->assertStringContainsString('3 &rarr; 9', $html, 'the change itself is not on the page');
        $this->assertStringContainsString('After results', $html,
            'a mark raised six points after the results date rendered as routine');
    }

    /** An empty programme is an empty audit, not an error. */
    public function test_a_programme_that_has_never_run_is_not_an_error(): void
    {
        $empty = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'brand-new', 'title' => 'Brand New Award', 'is_active' => 1,
        ]);

        $r = JudgingAudit::forProgramme($empty);

        $this->assertSame([], $r['cycles']);
        $this->assertSame([], $r['judges']);
        $this->assertSame(0, $r['totals']['nominees']);
        $this->assertNotNull($r['programme']);
    }
}
