<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Services\EventPlan\EventReport;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\TeamLoop;
use AfricaGates\Services\EventPlan\TeamPages;
use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * An event's written report and its sign-off (EventReport), through the lead console's one
 * dispatcher — the same path, permission filter and audit a real post takes.
 *
 * Each rule here is one a shortcut would break: a report signed off by its own author, one
 * submitted before the night it reports on, figures that move under a signature, a "sent back"
 * with nothing to fix, and a final report that can still be edited.
 */
final class EventReportTest extends TestCase
{
    private int $lead;
    private int $owner;
    private int $ev;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::resetRules();
        $this->lead = $this->member('Tunde Akin');
        $this->owner = $this->member('Ngozi Eze');
        $this->ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'rep-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho award ceremony',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+10 days')), 'venue' => 'Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $this->give('event.lead', $this->lead);
        $this->give('event.own', $this->owner);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['lead_toast'], $_SESSION['lead_error'], $_SESSION[TeamPages::SESSION]);
        parent::tearDown();
    }

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function give(string $cap, int $who): void
    {
        $this->assertTrue(Grants::grant('member', $who, $cap, 'event', $this->ev, date('Y-m-d', strtotime('+40 days')), 1)['ok']);
    }

    private function ctrl(): LeadController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(LeadController::class);
    }

    /** @return array{0:bool,1:string} [refused, message] */
    private function post(int $as, array $body): array
    {
        $_SESSION['user_id'] = $as;
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $this->ctrl()->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')->withParsedBody($body + ['event' => $this->ev, 'back' => '/lead']), new Response());
        return [isset($_SESSION['lead_error']), (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '')];
    }

    private function page(int $as): string
    {
        $_SESSION['user_id'] = $as;
        $_SERVER['REQUEST_URI'] = '/lead/event/' . $this->ev . '/reports';
        $res = $this->ctrl()->event((new ServerRequestFactory())->createServerRequest('GET', '/lead')->withQueryParams(['tab' => 'post']), new Response(),
            ['id' => $this->ev, 'page' => 'reports']);
        return (string) $res->getBody();
    }

    private function night(): void
    {
        DB::table('gates_site_events')->where('id', $this->ev)->update(['event_date' => date('Y-m-d 18:00:00', strtotime('-2 days'))]);
    }

    private function state(): string
    {
        return (string) DB::table('gates_event_writeups')->where('event_id', $this->ev)->value('status');
    }

    public function test_a_lead_writes_submits_after_the_night_and_an_owner_signs_it_off(): void
    {
        [$no, $msg] = $this->post($this->lead, ['do' => 'report_save', 'summary' => 'Doors opened at 18:20; 640 came.', 'lessons' => 'Book the sound check a day earlier.']);
        $this->assertFalse($no, $msg);
        $this->assertSame('draft', $this->state());

        [$no, $msg] = $this->post($this->lead, ['do' => 'report_submit']);
        $this->assertTrue($no, 'a report on a night that has not happened is a plan');
        $this->assertStringContainsString('after the night', $msg);

        $this->night();
        [$no, $msg] = $this->post($this->lead, ['do' => 'report_submit']);
        $this->assertFalse($no, $msg);
        $this->assertSame('submitted', $this->state());
        $this->assertNotNull(DB::table('gates_event_writeups')->where('event_id', $this->ev)->value('figures_json'), 'the figures are frozen at submission');

        // With the owner now, it is not the leads' to change.
        [$no] = $this->post($this->lead, ['do' => 'report_save', 'summary' => 'Quietly rewritten.']);
        $this->assertTrue($no);

        $owner = $this->page($this->owner);
        $this->assertStringContainsString('Doors opened at 18:20; 640 came.', $owner);
        $this->assertStringContainsString('value="report_decide"', $owner, 'the owner is offered the decision');
        $this->assertStringNotContainsString('value="report_decide"', $this->page($this->lead), 'a lead who is not an owner is not');

        [$no, $msg] = $this->post($this->owner, ['do' => 'report_decide', 'verdict' => 'approve']);
        $this->assertFalse($no, $msg);
        $this->assertSame('approved', $this->state());
        [$no] = $this->post($this->lead, ['do' => 'report_save', 'summary' => 'Changed after signing.']);
        $this->assertTrue($no, 'a signed-off report is final');
        $this->assertStringContainsString('Signed off by Ngozi Eze', $this->page($this->lead));
    }

    public function test_nobody_signs_off_a_report_they_wrote(): void
    {
        $this->give('event.own', $this->lead);   // the lead is also an owner
        $this->night();
        $this->post($this->lead, ['do' => 'report_save', 'summary' => 'Written by somebody who also owns the event.']);
        $this->post($this->lead, ['do' => 'report_submit']);
        [$no, $msg] = $this->post($this->lead, ['do' => 'report_decide', 'verdict' => 'approve']);
        $this->assertTrue($no);
        $this->assertStringContainsString('another owner', $msg);
        $this->assertSame('submitted', $this->state());
        $this->assertStringNotContainsString('value="report_decide"', $this->page($this->lead));
    }

    public function test_sent_back_needs_a_reason_and_the_leads_can_change_and_resubmit(): void
    {
        $this->night();
        $this->post($this->lead, ['do' => 'report_save', 'summary' => 'A first draft.']);
        $this->post($this->lead, ['do' => 'report_submit']);
        [$no] = $this->post($this->owner, ['do' => 'report_decide', 'verdict' => 'return', 'note' => '']);
        $this->assertTrue($no, 'sent back with nothing to fix');
        [$no, $msg] = $this->post($this->owner, ['do' => 'report_decide', 'verdict' => 'return', 'note' => 'Say what the late start cost.']);
        $this->assertFalse($no, $msg);
        $this->assertSame('returned', $this->state());
        $this->assertStringContainsString('Say what the late start cost.', $this->page($this->lead));

        [$no, $msg] = $this->post($this->lead, ['do' => 'report_save', 'summary' => 'A second draft, with the cost.']);
        $this->assertFalse($no, $msg);
        [$no, $msg] = $this->post($this->lead, ['do' => 'report_submit']);
        $this->assertFalse($no, $msg);
        $this->assertSame('submitted', $this->state());
    }

    public function test_a_report_needs_a_summary_and_only_a_lead_writes_it(): void
    {
        $this->night();
        $this->post($this->lead, ['do' => 'report_save', 'lessons' => 'Only lessons.']);
        [$no, $msg] = $this->post($this->lead, ['do' => 'report_submit']);
        $this->assertTrue($no);
        $this->assertStringContainsString('summary', $msg);

        $stranger = $this->member('Bola Ade');
        [$no] = $this->post($stranger, ['do' => 'report_save', 'summary' => 'Not mine to write.']);
        $this->assertTrue($no);
        [$no] = $this->post($this->owner, ['do' => 'report_save', 'summary' => 'An owner who does not lead.']);
        $this->assertTrue($no, 'an owner signs; a lead writes');
    }

    /** The team's quick reports share the word "report" and must keep working beside this. */
    public function test_the_teams_quick_reports_are_untouched(): void
    {
        $this->night();
        $this->post($this->lead, ['do' => 'report_save', 'summary' => 'Written.']);
        $this->assertTrue(TeamLoop::report($this->ev, null, $this->lead, 'Tunde Akin', 'ok', 2, 'First')['ok']);
        $this->assertTrue(TeamLoop::report($this->ev, null, $this->lead, 'Tunde Akin', 'ok', 2, 'Second')['ok'], 'two quick reports for one event');
    }

    public function test_the_steps_are_on_the_events_audit_trail(): void
    {
        $this->night();
        $this->post($this->lead, ['do' => 'report_save', 'summary' => 'For the record.']);
        $this->post($this->lead, ['do' => 'report_submit']);
        $this->post($this->owner, ['do' => 'report_decide', 'verdict' => 'approve']);
        $actions = DB::table('gates_audit_log')->where('target_type', 'event')->where('target_id', $this->ev)->pluck('action')->all();
        foreach (['lead.report_save', 'lead.report_submit', 'lead.report_decide'] as $a) $this->assertContains($a, $actions);
        $this->assertSame(EventReport::STATUSES['approved'], 'Signed off');
    }
}
