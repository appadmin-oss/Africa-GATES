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

    /** Score a criterion by id, for tests that work against the EFFECTIVE rubric. */
    private function scoreById(int $judge, int $nominee, int $criterionId, int $score,
                               string $at = '2026-11-01 09:00:00'): void
    {
        DB::table('gates_judge_criteria_scores')->insert([
            'judge_id' => $judge, 'nominee_id' => $nominee, 'category_id' => $this->categoryId,
            'criterion_id' => $criterionId, 'score' => $score,
            'created_at' => $at, 'updated_at' => $at,
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

        $this->assertSame([], JudgingAudit::forProgramme($this->programmeId)['changes']['rows'],
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

        $this->assertCount(1, $changes['rows']);
        $this->assertSame(1, $changes['total']);
        $this->assertSame(4, $changes['rows'][0]['old']);
        $this->assertSame(9, $changes['rows'][0]['new']);
        $this->assertTrue($changes['rows'][0]['after_results'],
            'a mark raised by five points a week after the results date read as routine');
        $this->assertSame('Impact', $changes['rows'][0]['criterion'], 'the criterion was not named');
    }

    /**
     * A busy judge on ANOTHER programme cannot push this one's changes off the list.
     *
     * The first cut narrowed the log by judge and fetched four times the limit to leave
     * room for discarding foreign rows. A judge who also sits on two other panels fills
     * that window with their changes elsewhere and this programme's fall off the end —
     * an audit silently showing fewer changes than exist, which is the worst thing an
     * audit can do. Filtering by NOMINEE is exact, because a nominee belongs to one
     * cycle and one programme.
     */
    public function test_changes_elsewhere_cannot_crowd_out_this_programmes(): void
    {
        $mine = $this->nominee('Ours');
        $j    = $this->judge('Busy Ada');
        $this->score($j, $mine, 'impact', 5);

        // Another programme entirely, same judge, and far more recent activity.
        $otherProg = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'other', 'title' => 'Another Award', 'is_active' => 1,
        ]);
        $otherCycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $otherProg, 'year' => 2026, 'status' => 'judging',
        ]);
        $otherCat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $otherCycle, 'slug' => 'x', 'title' => 'X',
        ]);
        $theirs = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $otherCat, 'name' => 'Theirs', 'status' => 'approved',
        ]);

        // One old change here, fifty newer ones there.
        DB::table('gates_judge_score_log')->insert([
            'judge_id' => $j, 'nominee_id' => $mine, 'criterion_id' => $this->crit['impact'],
            'old_score' => 2, 'new_score' => 5, 'changed_at' => '2026-11-01 09:00:00',
        ]);
        for ($i = 0; $i < 50; $i++) {
            DB::table('gates_judge_score_log')->insert([
                'judge_id' => $j, 'nominee_id' => $theirs, 'criterion_id' => $this->crit['impact'],
                'old_score' => 1, 'new_score' => 9,
                // The space is load-bearing. Without it this built '2026-12-0110:00:00',
                // which SQLite stores as the string it is and MySQL refuses outright —
                // fifty rows of it, in a test about crowding out, none of which arrived.
                'changed_at' => '2026-12-0' . (($i % 9) + 1) . ' 1' . ($i % 10) . ':00:00',
            ]);
        }

        $changes = JudgingAudit::forProgramme($this->programmeId, 5)['changes'];

        $this->assertCount(1, $changes['rows'],
            "another programme's changes crowded this one's out of the window");
        $this->assertSame('Ours', $changes['rows'][0]['nominee']);
        $this->assertSame(1, $changes['total'], 'the total counted a foreign programme');
    }

    /**
     * A mark changed and then DELETED still appears.
     *
     * It is the row somebody auditing a result would most want to see, and the first cut
     * dropped it: context came from the surviving scores, so a change with no surviving
     * score had nothing to match against and vanished.
     */
    public function test_a_change_survives_the_score_being_deleted(): void
    {
        $n = $this->nominee('Score Later Removed');
        $j = $this->judge('Ada One');

        DB::table('gates_judge_score_log')->insert([
            'judge_id' => $j, 'nominee_id' => $n, 'criterion_id' => $this->crit['impact'],
            'old_score' => 3, 'new_score' => 10, 'changed_at' => '2026-11-02 09:00:00',
        ]);
        // …and no row in gates_judge_criteria_scores at all.

        $changes = JudgingAudit::forProgramme($this->programmeId)['changes'];

        $this->assertCount(1, $changes['rows'],
            'a mark changed and then deleted disappeared from the audit entirely');
        $this->assertSame('Ada One', $changes['rows'][0]['judge']);
        $this->assertSame('Score Later Removed', $changes['rows'][0]['nominee']);
    }

    /** And a truncated list says so, rather than reading as the whole record. */
    public function test_a_truncated_list_reports_the_real_total(): void
    {
        $n = $this->nominee('Much Revised');
        $j = $this->judge('Ada One');
        $this->score($j, $n, 'impact', 7);

        for ($i = 0; $i < 12; $i++) {
            DB::table('gates_judge_score_log')->insert([
                'judge_id' => $j, 'nominee_id' => $n, 'criterion_id' => $this->crit['impact'],
                'old_score' => 1, 'new_score' => 7,
                'changed_at' => '2026-11-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 09:00:00',
            ]);
        }

        $changes = JudgingAudit::forProgramme($this->programmeId, 5)['changes'];

        $this->assertCount(5, $changes['rows']);
        $this->assertSame(12, $changes['total'],
            'a truncated list presented as the whole record reassures with the wrong number');
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

    // ══ does the rubric decide anything ══════════════════════════════════════

    /**
     * A criterion every judge marked the same decided nothing — and kept its weight.
     *
     * Weights say what an award CLAIMS to value. This says what separated the field. On a
     * two-criterion rubric where one is inert, the result was really decided by one
     * criterion, and the screen said 50/50.
     */
    public function test_a_criterion_nobody_varied_is_named_as_deciding_nothing(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        foreach (['A', 'B', 'C'] as $i => $name) {
            $n = $this->nominee($name);
            // Rigour: every mark identical. Impact: it actually separates them.
            $this->score($j1, $n, 'rigour', 8);
            $this->score($j2, $n, 'rigour', 8);
            $this->score($j1, $n, 'impact', 3 + $i);
            $this->score($j2, $n, 'impact', 4 + $i);
        }

        $crit = JudgingAudit::forProgramme($this->programmeId)['criteria'];
        $by   = [];
        foreach ($crit as $c) $by[$c['label']] = $c;

        $this->assertTrue($by['Rigour']['inert'], 'a criterion with one value decided nothing');
        $this->assertSame(1, $by['Rigour']['distinct']);
        $this->assertSame(0.0, $by['Rigour']['spread']);

        $this->assertFalse($by['Impact']['inert']);
        $this->assertGreaterThan(1, $by['Impact']['distinct']);

        // The weight travels WITH the finding: "decided nothing" and "was worth half the
        // mark" only mean something together.
        $this->assertNotNull($by['Rigour']['share']);

        // Worst first, so the inert one is not buried under a working rubric.
        $this->assertSame('Rigour', $crit[0]['label']);
    }

    // ══ scorecards left part-marked ══════════════════════════════════════════

    /**
     * A partial scorecard is NOT discarded, which is why it belongs on an audit.
     *
     * The weighted average divides by the weight actually marked, so a judge who marked
     * two criteria of two on one nominee and one of two on another has silently reweighted
     * the second nominee's mark onto whichever criterion they finished. Coverage above
     * counts nominees NOBODY marked; this is the quieter one.
     */
    public function test_a_part_marked_scorecard_is_reported_with_how_much_it_covered(): void
    {
        // ── THE REQUIRED SET IS THE RUBRIC'S, NOT THIS TEST'S ────────────────
        //
        // JudgeRubric::effective() is the SHIPPED criteria plus this programme's own,
        // deduped by slug — the same list the scorer presents and the same one
        // completeScorecards() counts against. Writing "2" here would have been a second
        // opinion about what complete means, and it would have been wrong: this
        // programme's effective rubric is five criteria, not the two it declares.
        $ids = array_map(
            static fn (object $r): int => (int) $r->id,
            array_filter(\AfricaGates\Services\JudgeRubric::effective($this->programmeId),
                         static fn (object $r): bool => (int) $r->is_active === 1)
        );
        $required = count($ids);
        $this->assertGreaterThan(1, $required, 'no rubric to be incomplete against');

        $j = $this->judge('Ada Obi');

        $whole = $this->nominee('Whole');
        foreach ($ids as $cid) $this->scoreById($j, $whole, $cid, 7);

        $half = $this->nominee('Half');
        foreach (array_slice($ids, 0, $required - 1) as $cid) $this->scoreById($j, $half, $cid, 9);

        $inc = JudgingAudit::forProgramme($this->programmeId)['incomplete'];

        $this->assertSame($required, $inc['required']);
        $this->assertSame(1, $inc['pairs'], 'a complete scorecard was reported as partial');
        $this->assertSame('Half', $inc['rows'][0]['nominee']);
        $this->assertSame($required - 1, $inc['rows'][0]['marked']);
        $this->assertSame((int) round(($required - 1) * 100 / $required), $inc['rows'][0]['covered']);
    }

    // ══ where the panel disagreed ════════════════════════════════════════════

    /**
     * The widest disagreement, not the lowest score — which is the row somebody appeals.
     *
     * Each judge's own WEIGHTED average, the same arithmetic the judge portal shows them
     * about their own marking. A rubric where one criterion is worth more than another
     * would otherwise report agreement that does not exist.
     */
    public function test_the_nominee_the_panel_could_not_agree_about_is_first(): void
    {
        $j1 = $this->judge('Ada Obi');
        $j2 = $this->judge('Tunde Cole');

        $agreed = $this->nominee('Agreed');
        $this->score($j1, $agreed, 'impact', 7); $this->score($j1, $agreed, 'rigour', 7);
        $this->score($j2, $agreed, 'impact', 7); $this->score($j2, $agreed, 'rigour', 7);

        $split = $this->nominee('Split');
        $this->score($j1, $split, 'impact', 9); $this->score($j1, $split, 'rigour', 9);
        $this->score($j2, $split, 'impact', 3); $this->score($j2, $split, 'rigour', 3);

        $d = JudgingAudit::forProgramme($this->programmeId)['disagreement'];

        $this->assertSame('Split', $d[0]['nominee'], 'the widest disagreement is not first');
        $this->assertSame(6.0, $d[0]['spread']);
        $this->assertSame(3.0, $d[0]['low']);
        $this->assertSame(9.0, $d[0]['high']);
        // Named, so a challenge about one nominee is answered with the marks rather than
        // with an average neither judge held.
        $this->assertSame(['Ada Obi' => 9.0, 'Tunde Cole' => 3.0], $d[0]['marks']);
    }

    /**
     * One judge cannot disagree with anybody, and must not be reported as agreeing.
     *
     * A spread of zero over a panel of one reads as "the panel was unanimous". It means
     * nobody else looked, which coverage above already names as a thin panel.
     */
    public function test_a_nominee_with_one_judge_is_not_reported_as_unanimous(): void
    {
        $j = $this->judge('Ada Obi');
        $n = $this->nominee('Alone');
        $this->score($j, $n, 'impact', 8);
        $this->score($j, $n, 'rigour', 8);

        $this->assertSame([], JudgingAudit::forProgramme($this->programmeId)['disagreement'],
            'a panel of one was reported as agreeing with itself');
    }

    // ══ how long they spent ══════════════════════════════════════════════════

    /**
     * The MEDIAN gap between marks, because judges score in sittings across days.
     *
     * A mean is destroyed by one overnight gap, and the span between first and last says
     * nothing about the reading. Four seconds a mark across a hundred nominees is a fact
     * an operator can weigh, and it is a different objection from any of the arithmetic.
     */
    public function test_the_typical_gap_between_marks_survives_an_overnight_break(): void
    {
        $j = $this->judge('Ada Obi');
        $n1 = $this->nominee('One');
        $n2 = $this->nominee('Two');

        // Four marks five seconds apart, then a fourteen-hour break, then two more.
        $this->score($j, $n1, 'impact', 5, '2026-11-01 09:00:00');
        $this->score($j, $n1, 'rigour', 5, '2026-11-01 09:00:05');
        $this->score($j, $n2, 'impact', 6, '2026-11-01 09:00:10');
        $this->score($j, $n2, 'rigour', 6, '2026-11-01 23:00:10');

        $judges = JudgingAudit::forProgramme($this->programmeId)['judges'];
        $mine   = $judges[0];

        $this->assertSame('Ada Obi', $mine['judge']);
        // Gaps are 5, 5, 50400 — the median is 5, the mean would be 16,803.
        $this->assertSame(5, $mine['median_gap'],
            'an overnight break was allowed to describe how long they spent per mark');
    }

    /** A single mark has no gap, and reporting zero would read as instant. */
    public function test_one_mark_reports_no_pace_rather_than_zero(): void
    {
        $j = $this->judge('Ada Obi');
        $this->score($j, $this->nominee('One'), 'impact', 5);

        $this->assertNull(JudgingAudit::forProgramme($this->programmeId)['judges'][0]['median_gap']);
    }

    /** §17 is only closed when something renders it. */
    public function test_the_new_findings_reach_the_screen(): void
    {
        $t = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/judging-audit.twig');

        foreach (['audit.criteria', 'audit.incomplete', 'audit.disagreement', 'j.median_gap'] as $k) {
            $this->assertStringContainsString($k, $t, $k . ' is computed and rendered nowhere');
        }
    }
}
