<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Services\EventPlan\EventPages;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\TeamLoop;
use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * THE EVENT TEAM'S LOOP (owner, 5 Nov 2026: "Events leads cannot add reports, they cannot
 * create tasks … the events and volunteer features are too basic"), against the acceptance
 * tests of the "Event and Project Management: Design Plan" this build takes from:
 *
 *   AT-2  a change routes to the right approver   → a team member's new work waits for a lead
 *   AT-3  a rejected suggestion, no new evidence   → not raised again
 *   AT-6  done without its proof                   → held as awaiting proof
 *   AT-8  any plan change is logged                → audited against the event
 *   rule 1 two blocker reports                     → in front of a lead
 *   rule 12 at most three decisions on Today
 *
 * plus the fault that began it: work given to a volunteer reached them nowhere.
 * Each case drives the real service or posts the real controller and reads the record.
 */
final class EventTeamLoopTest extends TestCase
{
    private int $lead;
    private int $vol;
    private int $ev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = $this->member('Tunde Akin');
        $this->vol = $this->member('Chika Nwosu');
        $this->ev = $this->event('Alimosho ceremony');
        Grants::grant('member', $this->lead, 'event.lead', 'event', $this->ev, date('Y-m-d', strtotime('+40 days')), 1);
        DB::table('gates_volunteers')->insert(['user_id' => $this->vol, 'status' => 'active', 'display_name' => 'Chika Nwosu', 'created_at' => date('Y-m-d H:i:s')]);
        Grants::grant('member', $this->vol, 'vol.door', 'event', $this->ev, date('Y-m-d', strtotime('+40 days')), 1);
        $_SESSION['user_id'] = $this->lead;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['lead_toast'], $_SESSION['lead_error']);
        parent::tearDown();
    }

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function event(string $title): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => $title,
            'event_date' => date('Y-m-d 18:00:00', strtotime('+12 days')), 'venue' => 'Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function work(string $proof = 'none', ?int $owner = null): int
    {
        $r = Plan::addWork($this->ev, ['title' => 'Venue contract', 'owner' => 'member:' . ($owner ?? $this->vol), 'proof_type' => $proof], $this->lead);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) $r['id'];
    }

    /** POST /lead/act as the signed-in member; returns the sentence it answered with. */
    private function post(array $body): string
    {
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $b->build()->get(LeadController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')->withParsedBody($body + ['back' => '/lead']), new Response());
        return (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '');
    }

    public function test_work_given_to_a_volunteer_reaches_them(): void
    {
        $id = $this->work();
        $this->assertSame([$id], array_map(static fn ($w) => (int) $w->id, TeamLoop::mine($this->vol, $this->ev)));
        $html = json_encode(EventPages::teamWork($this->ev, $this->vol));
        $this->assertStringContainsString('Venue contract', (string) $html, 'on the volunteer’s own page');
        $this->assertStringContainsString('work_done', (string) $html, 'with a way to finish it');
    }

    public function test_done_without_its_proof_is_held_and_does_not_count(): void
    {
        $id = $this->work('link');
        $r = TeamLoop::complete($id, $this->vol, '');
        $this->assertTrue($r['ok'] && !empty($r['held']));
        $this->assertSame('proof', DB::table('gates_event_work')->where('id', $id)->value('status'));
        $this->assertSame(0, Plan::readiness($this->ev)['done'], 'held work is not done');
        $this->assertFalse(TeamLoop::complete($id, $this->vol, 'not a link')['ok'], 'a link proof must be a link');
        $this->assertTrue(TeamLoop::complete($id, $this->vol, 'https://drive.example/contract.pdf')['ok']);
        $this->assertSame('done', DB::table('gates_event_work')->where('id', $id)->value('status'));
        $this->assertSame(1, Plan::readiness($this->ev)['done']);
        // A lead pressing Done on proof-needing work goes through the same check.
        $id2 = $this->work('note', $this->lead);
        $this->assertTrue(Plan::setStatus($id2, 'done', $this->lead)['held'] ?? false);
    }

    public function test_a_sign_off_is_a_leads(): void
    {
        $id = $this->work('signoff');
        TeamLoop::complete($id, $this->vol, 'All signed');
        $this->assertSame('proof', DB::table('gates_event_work')->where('id', $id)->value('status'), 'the owner’s done waits for a lead');
        $this->assertSame('proof', TeamLoop::decisions($this->ev)[0]['kind']);
        $this->assertFalse(TeamLoop::returnProof($id, $this->lead, '')['ok'], 'sending back needs a reason');
        $this->assertTrue(TeamLoop::complete($id, $this->lead, '')['ok']);
        $this->assertSame('done', DB::table('gates_event_work')->where('id', $id)->value('status'));
    }

    public function test_only_the_owner_or_a_lead_finishes_work_and_another_events_work_is_untouchable(): void
    {
        $id = $this->work();
        $stranger = $this->member('Ada Obi');
        $this->assertFalse(TeamLoop::complete($id, $stranger, '')['ok']);
        $this->assertFalse(TeamLoop::report($this->ev, $id, $stranger, 'Ada', 'ok', 2, '')['ok'], 'reports come from the team only');
        $other = $this->event('Somebody else’s night');
        $this->assertFalse(TeamLoop::report($other, $id, $this->vol, 'Chika', 'ok', 2, '')['ok'], 'a work id from another event');
        $this->assertSame(0, (int) DB::table('gates_event_reports')->count());
    }

    public function test_a_team_members_new_work_waits_for_a_lead_and_counts_nowhere_until_approved(): void
    {
        $r = TeamLoop::propose($this->ev, $this->vol, ['title' => 'Order lanyards', 'why' => 'Door stewards need ID', 'mine' => '1']);
        $this->assertTrue($r['ok']);
        $this->assertNull(Plan::readiness($this->ev), 'a suggestion is not on the plan');
        $this->assertSame([], Plan::work([$this->ev]));
        $this->assertFalse(TeamLoop::decide($r['id'], $this->vol, 'approve', '')['ok'], 'not their own, and not a lead');
        $this->assertFalse(TeamLoop::decide($r['id'], $this->lead, 'decline', '')['ok'], 'declining needs a reason');
        $this->assertTrue(TeamLoop::decide($r['id'], $this->lead, 'approve', '', 'note')['ok']);
        $row = DB::table('gates_event_work')->where('id', $r['id'])->first();
        $this->assertSame(['todo', 'note', $this->vol], [$row->status, $row->proof_type, (int) $row->owner_id]);
        $this->assertSame(1, Plan::readiness($this->ev)['all']);
    }

    public function test_a_declined_suggestion_is_not_raised_again_without_something_new(): void
    {
        $id = (int) TeamLoop::propose($this->ev, $this->vol, ['title' => 'Order lanyards'])['id'];
        TeamLoop::decide($id, $this->lead, 'decline', 'We have last year’s');
        $again = TeamLoop::propose($this->ev, $this->vol, ['title' => 'order LANYARDS']);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('We have last year’s', $again['error']);
        $this->assertTrue(TeamLoop::propose($this->ev, $this->vol, ['title' => 'Order lanyards', 'changed' => 'Last year’s were lost'])['ok']);
    }

    public function test_two_blocker_reports_put_it_in_front_of_a_lead_and_today_shows_at_most_three(): void
    {
        $id = $this->work();
        $this->assertFalse(TeamLoop::report($this->ev, $id, $this->vol, 'Chika', 'blocked', 2, '')['ok'], 'blocked needs a line');
        TeamLoop::report($this->ev, $id, $this->vol, 'Chika', 'blocked', 2, 'Venue not answering');
        $this->assertSame([], array_filter(TeamLoop::decisions($this->ev), static fn ($d) => $d['kind'] === 'blocked'), 'one report is not enough');
        TeamLoop::report($this->ev, $id, $this->vol, 'Chika', 'blocked', 1, 'Still nothing');
        $this->assertSame('blocked', TeamLoop::decisions($this->ev)[0]['kind'], 'two in a week are, and they rank first');
        // The event as a whole counts too.
        TeamLoop::report($this->ev, null, $this->vol, 'Chika', 'blocked', 2, 'Printer');
        TeamLoop::report($this->ev, null, $this->vol, 'Chika', 'blocked', 2, 'Printer');
        foreach (['A', 'B', 'C'] as $t) TeamLoop::propose($this->ev, $this->vol, ['title' => 'Suggestion ' . $t]);
        $this->assertGreaterThan(3, count(TeamLoop::decisions($this->ev)));
        $needs = array_values(array_filter(EventPages::loop(Plan::event($this->ev)), static fn ($s) => ($s['h'] ?? '') === 'Needs you'));
        $this->assertCount(TeamLoop::TOP, $needs[0]['items'], 'at most three on Today');
    }

    public function test_a_progress_report_on_your_own_work_starts_it(): void
    {
        $id = $this->work();
        TeamLoop::report($this->ev, $id, $this->vol, 'Chika', 'ok', 3, 'Spoke to the venue');
        $this->assertSame('doing', DB::table('gates_event_work')->where('id', $id)->value('status'));
    }

    public function test_the_lead_console_takes_the_loops_posts_and_audits_them(): void
    {
        $id = $this->work('link');
        $this->assertStringContainsString('Reported', $this->post(['do' => 'report_add', 'event' => $this->ev, 'work' => $id, 'state' => 'risk', 'confidence' => 2, 'note' => 'Slow']));
        $s = (int) TeamLoop::propose($this->ev, $this->vol, ['title' => 'Order lanyards'])['id'];
        $this->assertStringContainsString('Approved', $this->post(['do' => 'work_decide', 'work' => $s, 'verdict' => 'approve', 'proof' => 'none']));
        $this->assertStringContainsString('Held', $this->post(['do' => 'work_status', 'work' => $id, 'status' => 'done']));
        foreach (['lead.report_add', 'lead.work_decide'] as $a) $this->assertGreaterThan(0, DB::table('gates_audit_log')->where('action', $a)->count(), $a . ' is audited');
    }
}
