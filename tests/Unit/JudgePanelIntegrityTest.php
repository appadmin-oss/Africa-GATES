<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\JudgesController;
use AfricaGates\Admin\Controllers\ProgrammesController;
use AfricaGates\Admin\Services\{AuditService, UploadService};
use AfricaGates\Judge\Middleware\JudgeAuthMiddleware;
use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Services\JudgeRubric;
use AfricaGates\Services\JudgingAudit;
use AfricaGates\Services\NomineeScoringService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * WHO IS ON THE PANEL, AND WHOSE MARKS COUNT — asked the same way everywhere.
 *
 * Each case below is a place where two parts of the judging portal answered one question
 * differently: the admin "remove" button and the database's cascade, a withdrawn recusal
 * and the audit built to report it, the portal's assignment rule and the scorer's, a
 * deactivated judge and their live session, the ballot's phase and the save's, the rubric
 * form's ceiling and the column's. Every assertion was watched failing against the code it
 * replaced (see the report that came with this file) before it was trusted passing.
 */
final class JudgePanelIntegrityTest extends TestCase
{
    private const PROG = 61;
    private const CYCLE = 61;
    private const CAT = 61;
    private const NOM = 61;

    private JudgeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        \AfricaGates\Support\SchemaHas::forget();
        $this->svc = new JudgeService();

        DB::table('gates_award_programmes')->insert(['id' => self::PROG, 'slug' => 'pi', 'title' => 'Panel Integrity', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => self::PROG, 'year' => (int) date('Y'), 'status' => 'judging',
            'voting_open'  => Carbon::now()->subDays(30)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDays(3)->toDateTimeString(),
            'results_date' => Carbon::now()->addDays(20)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => self::CAT, 'cycle_id' => self::CYCLE, 'slug' => 'pic', 'title' => 'C']);
        DB::table('gates_nominees')->insert([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada', 'status' => 'approved', 'vote_count' => 0,
        ]);
        DB::table('gates_judge_criteria')->delete();
        DB::table('gates_judge_criteria')->insert(['id' => 1, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 25, 'is_active' => 1]);
        $this->publishShortlist(self::CYCLE, self::CAT, [self::NOM]);
    }

    private function judge(int $id, array $progs = [self::PROG], int $active = 1): int
    {
        DB::table('gates_judges')->insert([
            'id' => $id, 'name' => 'J' . $id, 'email' => 'pi' . $id . '@x.test',
            'programme_ids' => json_encode($progs), 'is_active' => $active,
        ]);
        return $id;
    }

    private function mark(int $judge, int $score = 8): void
    {
        DB::table('gates_judge_criteria_scores')->insert([
            'judge_id' => $judge, 'nominee_id' => self::NOM, 'category_id' => self::CAT,
            'criterion_id' => 1, 'score' => $score, 'created_at' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function judges(): array
    {
        return (new NomineeScoringService())->panelDetailFor([self::NOM])[self::NOM]['judges'] ?? [];
    }

    // ══ 1 · removing a judge ═════════════════════════════════════════════════

    private function judgesController(): JudgesController
    {
        return new JudgesController(\Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'),
            new AuditService(), new UploadService());
    }

    private function post(string $path, array $body = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body);
    }

    public function test_removing_a_judge_who_has_marked_retires_them_and_keeps_the_marks(): void
    {
        $_SESSION['admin_id'] = 1;
        $j = $this->judge(1);
        $this->mark($j);

        $this->judgesController()->delete($this->post('/admin/judges/1/delete'), new Response(), ['id' => '1']);

        $row = DB::table('gates_judges')->where('id', $j)->first();
        $this->assertNotNull($row, 'a judge with marks was hard-deleted — on MySQL that cascades every mark away');
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame(1, DB::table('gates_judge_criteria_scores')->where('judge_id', $j)->count());
        $this->assertSame(NomineeScoringService::NOT_COUNTED_REMOVED, $this->judges()[$j]['why'],
            'a removed judge\'s marks still count');
    }

    public function test_a_judge_with_no_record_at_all_is_actually_deleted(): void
    {
        $_SESSION['admin_id'] = 1;
        $this->judge(2);
        $this->judgesController()->delete($this->post('/admin/judges/2/delete'), new Response(), ['id' => '2']);
        $this->assertNull(DB::table('gates_judges')->where('id', 2)->first());
    }

    public function test_the_confirm_no_longer_promises_the_scores_stay(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/judges/index.twig');
        $this->assertStringNotContainsString('Scores they have already submitted stay on the nominees', $tpl);
    }

    // ══ 2 · withdrawing a recusal ════════════════════════════════════════════

    public function test_a_withdrawn_recusal_is_kept_audited_and_reported(): void
    {
        $j = $this->judge(3);
        $this->mark($j);

        $this->assertTrue($this->svc->declareConflict($j, self::PROG, 'knows her')['ok']);
        DB::table('gates_judge_coi')->update(['created_at' => '2020-01-01 00:00:00']);
        $this->assertSame(NomineeScoringService::NOT_COUNTED_RECUSED, $this->judges()[$j]['why']);

        // A second declaration does not move the first one's date.
        $this->svc->declareConflict($j, self::PROG);
        $this->assertSame('2020-01-01 00:00:00', (string) DB::table('gates_judge_coi')->value('created_at'),
            'redeclaring rewrote when the conflict was first declared');

        $this->assertTrue($this->svc->withdrawConflict($j, self::PROG)['ok']);

        $row = DB::table('gates_judge_coi')->where('judge_id', $j)->first();
        $this->assertNotNull($row, 'withdrawing deleted the declaration');
        $this->assertNotNull($row->withdrawn_at);
        $this->assertFalse($this->svc->hasConflict($j, self::PROG));
        $this->assertTrue($this->judges()[$j]['counts'], 'a withdrawn recusal should restore the marks');

        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'judge.conflict_withdraw')
            ->where('target_id', $j)->count(), 'the withdrawal left nothing in the audit log');

        $audit = JudgingAudit::forProgramme(self::PROG);
        $this->assertCount(1, $audit['conflicts'], 'the audit lost the declaration on withdrawal');
        $this->assertNotNull($audit['conflicts'][0]['withdrawn_at']);
    }

    public function test_a_conflict_cannot_be_declared_on_a_programme_that_is_not_theirs(): void
    {
        $j = $this->judge(4);
        $this->assertFalse($this->svc->declareConflict($j, 99999)['ok']);
        $this->assertSame(0, DB::table('gates_judge_coi')->count());
    }

    public function test_a_recusal_cannot_be_withdrawn_once_judging_has_closed(): void
    {
        $j = $this->judge(5);
        $this->svc->declareConflict($j, self::PROG);
        DB::table('gates_award_cycles')->where('id', self::CYCLE)->update([
            'status' => 'results', 'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $this->assertFalse($this->svc->withdrawConflict($j, self::PROG)['ok']);
        $this->assertTrue($this->svc->hasConflict($j, self::PROG));
    }

    // ══ 3 · a judge taken off the programme ══════════════════════════════════

    public function test_marks_from_a_judge_no_longer_assigned_do_not_count(): void
    {
        $in  = $this->judge(6);
        $out = $this->judge(7, [12345]);
        $this->mark($in);
        $this->mark($out);

        $j = $this->judges();
        $this->assertTrue($j[$in]['counts']);
        $this->assertFalse($j[$out]['counts'], 'an unassigned judge\'s card still counts');
        $this->assertSame(NomineeScoringService::NOT_COUNTED_UNASSIGNED, $j[$out]['why']);
        $this->assertSame(1, (new NomineeScoringService())->judgeStatsFor([self::NOM])[self::NOM]['judges']);
    }

    // ══ 6 · a deactivated judge's session ════════════════════════════════════

    public function test_a_deactivated_judge_is_signed_out_on_the_next_request(): void
    {
        $j = $this->judge(8);
        $_SESSION['judge_id'] = $j;
        $handler = new class implements RequestHandlerInterface {
            public bool $reached = false;
            public function handle(ServerRequestInterface $r): ResponseInterface { $this->reached = true; return new Response(); }
        };
        $mw  = new JudgeAuthMiddleware();
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/judge/ballot');

        $mw($req, $handler);
        $this->assertTrue($handler->reached, 'an active judge was turned away');

        DB::table('gates_judges')->where('id', $j)->update(['is_active' => 0]);
        $handler->reached = false;
        $res = $mw($req, $handler);
        $this->assertFalse($handler->reached, 'a deactivated judge kept their session');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertArrayNotHasKey('judge_id', $_SESSION);
        $this->assertSame([], $this->svc->programmes($j));
    }

    // ══ 7 · the dossier behind the gate ══════════════════════════════════════

    public function test_evidence_and_the_map_go_through_the_one_gate(): void
    {
        $j = $this->judge(9);
        $off = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Off the list', 'status' => 'approved', 'vote_count' => 0,
        ]);
        foreach ([[701, self::NOM], [702, $off]] as [$eid, $nid]) {
            DB::table('gates_nominee_evidence')->insert([
                'id' => $eid, 'nominee_id' => $nid, 'kind' => 'document', 'title' => 'Report',
                'source_url' => 'https://example.test/r.pdf', 'provenance' => 'nominee_supplied',
                'visible_to_judges' => 1,
            ]);
        }

        $this->assertNotNull($this->svc->evidenceFor($j, 701));
        $this->assertNull($this->svc->evidenceFor($j, 702),
            'evidence of a nominee off the shortlist was readable by id');

        $this->svc->declareConflict($j, self::PROG);
        $this->assertFalse($this->svc->mayJudgeNominee($j, self::NOM),
            'a recused judge can still pull the dossier map');
        $this->assertNull($this->svc->evidenceFor($j, 701));
        $this->assertStringContainsString('conflict', (string) $this->svc->saveScore($j, self::NOM, [1 => 7])['message'],
            'the save names the shortlist rather than the conflict');
    }

    // ══ 8 · one phase for the ballot and the save ════════════════════════════

    public function test_the_ballot_and_the_save_read_the_same_computed_phase(): void
    {
        $j = $this->judge(10);

        // Stored column lags behind the windows: computed phase is Judging.
        DB::table('gates_award_cycles')->where('id', self::CYCLE)->update(['status' => 'voting']);
        $this->assertTrue($this->svc->ballot($j, self::PROG)['judging_open'],
            'a cycle whose windows say judging was locked by a stale status column');
        $this->assertTrue($this->svc->saveScore($j, self::NOM, [1 => 7])['ok']);

        // And the other way: the results date has passed, the column still says judging.
        DB::table('gates_award_cycles')->where('id', self::CYCLE)->update([
            'status' => 'judging', 'results_date' => Carbon::now()->subHour()->toDateTimeString(),
        ]);
        $b = $this->svc->ballot($j, self::PROG);
        $this->assertFalse($b['judging_open']);
        $this->assertStringContainsString('finished', $b['lock_reason']);
        $this->assertFalse($this->svc->saveScore($j, self::NOM, [1 => 9])['ok'],
            'a ballot locked after judging still accepted a mark');
    }

    // ══ 9 · 10 · the TINYINT ceiling ═════════════════════════════════════════

    public function test_rubric_sort_order_is_clamped_to_the_column(): void
    {
        $r = JudgeRubric::save(null, 0, ['label' => 'Reach', 'weight' => 10, 'sort_order' => 900]);
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame(255, (int) DB::table('gates_judge_criteria')->where('label', 'Reach')->value('sort_order'));
        $this->assertStringContainsString('max="{{ max_sort }}"',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/rubric/index.twig'));
    }

    public function test_programme_and_category_sort_order_are_clamped_to_the_column(): void
    {
        $_SESSION['admin_id'] = 1;
        $c = new ProgrammesController(\Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'), new AuditService());

        $c->categorySave($this->post('/admin/programmes/61/category',
            ['slug' => 'big', 'title' => 'Big', 'sort_order' => '4000']), new Response(), ['id' => (string) self::PROG]);
        $this->assertSame(255, (int) DB::table('gates_award_categories')->where('slug', 'big')->value('sort_order'));

        // A refused write (the UNIQUE cycle+slug) is a flash, not an exception reaching the
        // operator — categorySave() had no catch at all.
        unset($_SESSION['flash_error']);
        $res = $c->categorySave($this->post('/admin/programmes/61/category',
            ['slug' => 'big', 'title' => 'Again', 'sort_order' => '1']), new Response(), ['id' => (string) self::PROG]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? null);

        $c->save($this->post('/admin/programmes/61', ['slug' => 'pi', 'title' => 'Panel Integrity',
            'sort_order' => '-4', 'is_active' => '1']), new Response(), ['id' => (string) self::PROG]);
        $this->assertSame(0, (int) DB::table('gates_award_programmes')->where('id', self::PROG)->value('sort_order'));
    }
}
