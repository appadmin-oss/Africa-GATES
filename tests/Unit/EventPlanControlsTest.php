<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Services\EventPlan\Changes;
use AfricaGates\Services\EventPlan\Goals;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\Scope;
use AfricaGates\Services\Grants;
use AfricaGates\Services\RoleInvites;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The event plan's controls, as the 13 Nov 2026 audit found them missing (AUDIT-AND-PLAN
 * §9.22): who asks for money and who approves it stay two people on every road to a grant
 * (EV-5); a lead cannot undo an owner's or staff's invitation alone (EV-6); the budget is an
 * owner's decision (EV-8); the event's audit tab holds money and staff writes (EV-9); an
 * approved change that changes nothing says so (EV-10); and two goal counts that moved the
 * wrong way (EV-19). Each test here failed on the code before its fix.
 */
final class EventPlanControlsTest extends TestCase
{
    private int $lead;
    private int $ev;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::resetRules();
        $this->lead = $this->member('Tunde Akin');
        $this->ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho Honours',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+40 days')), 'venue' => 'Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue(Grants::grant('member', $this->lead, 'event.lead', 'event', $this->ev, $this->until(), 1)['ok']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function until(): string
    {
        return date('Y-m-d', strtotime('+60 days'));
    }

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array{0:bool,1:string} refused?, the sentence shown */
    private function leadPost(array $body): array
    {
        $_SESSION['user_id'] = $this->lead;
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $b->build()->get(LeadController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')
            ->withParsedBody($body + ['back' => '/lead']), new Response());
        return [isset($_SESSION['lead_error']), (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '')];
    }

    public function test_one_person_never_holds_the_lead_or_owner_role_and_the_finance_role_at_one_event(): void
    {
        // Staff giving the lead the finance role directly: refused where every grant is made.
        $r = Grants::grant('member', $this->lead, 'event.finance', 'event', $this->ev, $this->until(), 1);
        $this->assertFalse($r['ok'], 'a lead was made the finance admin of their own event');

        // And the other order: a finance admin cannot later be made a lead or an owner.
        $fin = $this->member('Funmi Bello');
        $this->assertTrue(Grants::grant('member', $fin, 'event.finance', 'event', $this->ev, $this->until(), 1)['ok']);
        $this->assertFalse(Grants::grant('member', $fin, 'event.lead', 'event', $this->ev, $this->until(), 1)['ok']);
        $this->assertFalse(Grants::grant('member', $fin, 'event.own', 'event', $this->ev, $this->until(), 1)['ok']);

        // At a DIFFERENT event the same person may lead: the rule is per event.
        $other = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'o-' . bin2hex(random_bytes(3)), 'title' => 'Other', 'event_date' => date('Y-m-d', strtotime('+50 days')),
            'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue(Grants::grant('member', $fin, 'event.lead', 'event', $other, $this->until(), 1)['ok']);
    }

    public function test_a_lead_cannot_withdraw_an_owners_or_finance_invitation(): void
    {
        $own = RoleInvites::offer('newowner@example.test', 'event.own', $this->ev, $this->until(), ['kind' => 'admin', 'id' => 1, 'name' => 'Staff']);
        $this->assertTrue($own['ok']);
        $inviteId = (int) DB::table('gates_role_invites')->where('email', 'newowner@example.test')->value('id');
        [$refused] = $this->leadPost(['do' => 'vol_invite_cancel', 'event' => $this->ev, 'invite' => $inviteId]);
        $this->assertTrue($refused, 'a lead withdrew an owner invitation staff had sent');
        $this->assertSame('pending', (string) DB::table('gates_role_invites')->where('id', $inviteId)->value('status'));

        RoleInvites::offer('helper@example.test', 'vol.door', $this->ev, $this->until(), ['kind' => 'member', 'id' => $this->lead, 'name' => 'Tunde']);
        $vol = (int) DB::table('gates_role_invites')->where('email', 'helper@example.test')->value('id');
        [$refused] = $this->leadPost(['do' => 'vol_invite_cancel', 'event' => $this->ev, 'invite' => $vol]);
        $this->assertFalse($refused, 'a volunteer invitation is still the lead’s to withdraw');
    }

    public function test_the_budget_waits_for_an_owner_and_the_series_does_not(): void
    {
        [$refused, $msg] = $this->leadPost(['do' => 'event_settings', 'event' => $this->ev, 'budget' => '5,000,000', 'series' => 'Alimosho Honours']);
        $this->assertFalse($refused, $msg);
        $e = DB::table('gates_site_events')->where('id', $this->ev)->first();
        $this->assertNull($e->budget_minor, 'the budget changed with nobody deciding');
        $this->assertSame('alimosho-honours', (string) $e->series_key, 'a label is not a decision');
        $c = DB::table('gates_event_changes')->where('kind', 'budget')->first();
        $this->assertSame('large', (string) $c->tier);
        $this->assertTrue(Changes::isMoney($c));
        $this->assertFalse(Changes::decide((int) $c->id, $this->lead, 'approve', '')['ok']);
        $this->assertTrue(Changes::decide((int) $c->id, 0, 'approve', '', true)['ok']);
        $this->assertSame(500000000, (int) DB::table('gates_site_events')->where('id', $this->ev)->value('budget_minor'));
    }

    public function test_the_audit_tab_holds_money_writes_and_staffs_writes(): void
    {
        $this->leadPost(['do' => 'line_add', 'event' => $this->ev, 'title' => 'Chairs', 'estimate' => '40000']);
        $line = (int) DB::table('gates_event_budget')->value('id');
        $this->leadPost(['do' => 'line_ask', 'line' => $line, 'why' => 'Needed for the night']);
        DB::table('gates_audit_log')->insert(['admin_id' => null, 'action' => 'event.owner.give', 'target_type' => 'event', 'target_id' => $this->ev,
            'meta' => json_encode(['email' => 'o@example.test']), 'created_at' => date('Y-m-d H:i:s')]);
        $actions = array_map(static fn ($a) => (string) $a->action, Scope::audit($this->ev));
        $this->assertContains('lead.line_ask', $actions, 'a spend request was not on the event’s record');
        $this->assertContains('event.owner.give', $actions, 'staff giving an owner was not on the event’s record');
    }

    public function test_an_approved_change_whose_subject_is_gone_says_nothing_changed(): void
    {
        $g = Goals::create($this->ev, ['metric' => 'tickets', 'target' => '300', 'deadline' => date('Y-m-d', strtotime('+30 days'))], $this->lead);
        $this->assertTrue($g['ok'], (string) ($g['error'] ?? ''));
        $gate = (int) DB::table('gates_event_gates')->where('goal_id', $g['id'])->value('id');
        if ($gate === 0) {
            $gate = (int) DB::table('gates_event_gates')->insertGetId(['goal_id' => $g['id'], 'event_id' => $this->ev, 'title' => 'Half sold', 'target' => 150,
                'due_at' => date('Y-m-d H:i:s', strtotime('+20 days')), 'status' => 'open', 'approver' => 'lead', 'created_at' => date('Y-m-d H:i:s')]);
        }
        $p = Changes::propose('gate_date', $gate, date('Y-m-d', strtotime('+25 days')), 'More time to sell', $this->lead);
        $this->assertTrue($p['ok'], (string) ($p['error'] ?? ''));
        DB::table('gates_event_gates')->where('id', $gate)->delete();   // what draftGates() does to open gates
        $r = Changes::decide((int) $p['id'], 0, 'approve', '', true);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Nothing was changed', (string) $r['msg'], 'it said "Approved and applied" about nothing');
        $this->assertSame('rejected', (string) DB::table('gates_event_changes')->where('id', $p['id'])->value('status'));
    }

    public function test_confirmed_nominees_do_not_fall_at_results_and_drafts_are_not_submissions(): void
    {
        $now = date('Y-m-d H:i:s');
        $prog = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'p-' . bin2hex(random_bytes(3)), 'title' => 'Teaching', 'is_active' => 1, 'created_at' => $now]);
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $prog, 'year' => 2026, 'status' => 'results', 'created_at' => $now]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cycle, 'slug' => 'primary', 'title' => 'Primary', 'sort_order' => 0]);
        DB::table('gates_event_programmes')->insert(['event_id' => $this->ev, 'programme_id' => $prog]);
        foreach (['approved', 'winner', 'runner_up'] as $i => $st) {
            DB::table('gates_nominees')->insert(['category_id' => $cat, 'name' => 'N' . $i, 'status' => $st]);
        }
        foreach (['pending', 'draft'] as $st) {
            DB::table('gates_nominations')->insert(['cycle_id' => $cycle, 'category_id' => $cat, 'nominee_name' => 'X', 'nominator_name' => 'Y',
                'nominator_email' => 'y@example.test', 'status' => $st, 'created_at' => $now]);
        }
        $count = fn (string $metric) => Goals::value($this->ev, $metric);
        $this->assertSame(3, $count('nominees'), 'the winner and runner-up stopped counting on results night');
        $this->assertSame(1, $count('nominations'), 'a draft counted as a submitted nomination');
    }
}
