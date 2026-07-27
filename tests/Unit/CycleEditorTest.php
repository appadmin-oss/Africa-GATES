<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The admin cycle editor. This is where the date windows that drive the whole
 * lifecycle are actually set, and it previously showed the operator none of the
 * consequences: no computed phase, no validation, no history, no timezone, and a
 * status dropdown offering values the transition guard always refuses.
 *
 * Worst of all, it resolved the cycle by the SUBMITTED year, so changing the year
 * field silently inserted a brand-new empty cycle instead of editing.
 */
class CycleEditorTest extends TestCase
{
    private int $programmeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'tok';
        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'creative', 'title' => 'Creative Awards', 'is_active' => 1, 'sort_order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token'],
              $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function seedCycle(array $over = []): int
    {
        return (int) DB::table('gates_award_cycles')->insertGetId(array_merge([
            'programme_id' => $this->programmeId, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ], $over));
    }

    private function controller(): \AfricaGates\Admin\Controllers\ProgrammesController
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $builder->build()->get(\AfricaGates\Admin\Controllers\ProgrammesController::class);
    }

    private function view(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/programmes/' . $this->programmeId . '/cycle');
        return (string) $this->controller()
            ->cycleEdit($req, new Response(), ['id' => (string) $this->programmeId])->getBody();
    }

    private function save(array $body): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/programmes/' . $this->programmeId . '/cycle')
            ->withParsedBody($body);
        $this->controller()->cycleSave($req, new Response(), ['id' => (string) $this->programmeId]);
    }

    public function test_the_editor_shows_the_computed_phase_not_just_the_column(): void
    {
        $this->seedCycle();

        $body = $this->view();

        $this->assertStringContainsString('Current phase', $body);
        $this->assertStringContainsString('Voting open', $body);
        $this->assertStringContainsString('Votes accepted:', $body, 'state the consequence, not just the name');
    }

    public function test_the_editor_flags_a_column_that_disagrees_with_the_dates(): void
    {
        // Column says voting; the close date passed three days ago.
        $this->seedCycle([
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-3 days')),
        ]);

        $body = $this->view();

        $this->assertStringContainsString('stored status column says', $body);
        $this->assertStringContainsString('With the jury', $body, 'and name the phase the dates compute');
    }

    public function test_the_editor_states_the_timezone(): void
    {
        $this->seedCycle();

        $body = $this->view();

        $this->assertStringContainsString('All times are UTC', $body);
        $this->assertStringContainsString("your browser's timezone is not applied", $body,
            'datetime-local gives no offset, so the convention must be explicit');
    }

    public function test_the_status_dropdown_offers_only_accepted_transitions(): void
    {
        $this->seedCycle(['status' => 'judging']);

        $body = $this->view();

        // 'results' is refused unconditionally by the transition guard.
        $this->assertStringNotContainsString('<option value="results"', $body,
            'an option that can never be chosen must not be offered');
        $this->assertStringContainsString('<option value="judging"', $body, 'the current value stays selectable');
    }

    public function test_changing_the_year_edits_the_cycle_instead_of_creating_one(): void
    {
        $id = $this->seedCycle(['year' => 2026]);

        $this->save([
            'cycle_id' => (string) $id,
            'year'     => '2031',
            'status'   => 'voting',
            'voting_open'  => date('Y-m-d H:i', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i', strtotime('+10 days')),
            'nominations_open' => '', 'nominations_close' => '', 'results_date' => '',
        ]);

        $this->assertSame(1, DB::table('gates_award_cycles')->where('programme_id', $this->programmeId)->count(),
            'editing the year must not silently insert a second cycle');
        $this->assertSame(2031, (int) DB::table('gates_award_cycles')->where('id', $id)->value('year'));
    }

    public function test_incoherent_windows_are_refused_with_a_reason(): void
    {
        $id = $this->seedCycle();

        $this->save([
            'cycle_id' => (string) $id, 'year' => (string) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i', strtotime('+10 days')),
            'voting_close' => date('Y-m-d H:i', strtotime('+1 day')),   // before it opens
            'nominations_open' => '', 'nominations_close' => '', 'results_date' => '',
        ]);

        $this->assertNotEmpty($_SESSION['flash_error'] ?? '', 'the save must be refused');
        $this->assertStringContainsString('close AFTER it opens', (string) $_SESSION['flash_error']);
    }

    public function test_a_close_date_with_no_open_date_saves_but_warns(): void
    {
        // Legal, but it is the one configuration that reaches the branch where a
        // stale status column can affect authorization.
        $id = $this->seedCycle();

        $this->save([
            'cycle_id' => (string) $id, 'year' => (string) date('Y'), 'status' => 'voting',
            'voting_open'  => '',
            'voting_close' => date('Y-m-d H:i', strtotime('+10 days')),
            'nominations_open' => '', 'nominations_close' => '', 'results_date' => '',
        ]);

        $this->assertEmpty($_SESSION['flash_error'] ?? '', 'it is legal, so it must not be blocked');
        $this->assertStringContainsString('no OPEN date', (string) ($_SESSION['flash_ok'] ?? ''));
    }

    public function test_saving_refreshes_the_indexed_boundary(): void
    {
        $id = $this->seedCycle();

        $this->save([
            'cycle_id' => (string) $id, 'year' => (string) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i', strtotime('+10 days')),
            'results_date' => date('Y-m-d H:i', strtotime('+20 days')),
            'nominations_open' => '', 'nominations_close' => '',
        ]);

        $at = (string) DB::table('gates_award_cycles')->where('id', $id)->value('next_boundary_at');
        $this->assertStringStartsWith(date('Y-m-d', strtotime('+10 days')), $at,
            'the divergence sweep must agree with the dates just saved');
    }

    public function test_the_editor_shows_the_phase_history(): void
    {
        $id = $this->seedCycle();
        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => $id, 'from_status' => 'nominations', 'to_status' => 'voting',
            'reason' => 'auto: date window', 'actor' => 'cron',
            'boundary_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'observed_at' => date('Y-m-d H:i:s'),
            'notify' => 0,
        ]);

        $body = $this->view();

        $this->assertStringContainsString('Phase history', $body);
        $this->assertStringContainsString('nominations → voting', $body);
        $this->assertStringContainsString('suppressed', $body, 'a withheld announcement must be visible');
    }

    public function test_the_editor_targets_the_cycle_the_public_site_is_running(): void
    {
        // A future cycle used to win by year, so the admin edited one cycle while
        // the site ran another and status changes appeared to do nothing.
        $live = $this->seedCycle(['year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_cycles')->insert([
            'programme_id' => $this->programmeId, 'year' => 2031, 'status' => 'upcoming',
        ]);

        $body = $this->view();

        $this->assertStringContainsString('Other cycles', $body, 'the ambiguity must be surfaced');
        $this->assertStringContainsString('2026 · voting (editing)', $body,
            'the in-flight cycle is the one being edited');
        $this->assertStringContainsString('2031 · upcoming', $body, 'and the other one is listed, not hidden');
        $this->assertStringContainsString('value="' . $live . '"', $body,
            'the hidden cycle_id must target the live cycle, not the newest year');
    }
}
