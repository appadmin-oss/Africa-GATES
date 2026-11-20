<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Services\EventPlan\EventPages;
use AfricaGates\Services\EventPlan\EventPermissions;
use AfricaGates\Services\EventPlan\Goals;
use AfricaGates\Services\EventPlan\Leads;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * AN EVENT'S FINANCE ADMIN (owner, 12 Nov 2026: "Add a new financial admin role for an event").
 *
 *   · given by staff, by address, like every role; it ends
 *   · the event's spend requests go to them instead of the platform's finance team
 *   · they approve spend and sign off money gates, and NEVER plan spend: who asks for money is
 *     never who approves it
 *   · they see the money, the goals and the reports, and nothing about who may do what
 */
final class EventFinanceAdminTest extends TestCase
{
    private int $lead;
    private int $fin;
    private int $ev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = $this->member('Tunde Akin');
        $this->fin = $this->member('Funmi Bello');
        $this->ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho Honours',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+40 days')), 'venue' => 'Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        Grants::grant('member', $this->lead, 'event.lead', 'event', $this->ev, date('Y-m-d', strtotime('+60 days')), 1);
    }

    /** The lead tests sign somebody in; a session left behind changes what later tests see. */
    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function makeFinance(): void
    {
        $email = (string) DB::table('gates_users')->where('id', $this->fin)->value('email');
        $r = Leads::giveFinance($this->ev, $email, '', 1, 'Staff');
        $this->assertTrue($r['ok'] && !empty($r['granted']), json_encode($r));
    }

    /** A line over the lead's limit, asked of finance. @return array{0:int,1:int} line id, request id */
    private function asked(): array
    {
        $line = Plan::addLine($this->ev, ['title' => 'Stage and sound', 'estimate' => (string) (intdiv(Plan::limitMinor(), 100) + 50000)], $this->lead);
        $this->assertTrue($line['ok'], (string) ($line['error'] ?? ''));
        $id = (int) ($line['id'] ?? DB::table('gates_event_budget')->max('id'));
        $r = Plan::askLine($id, $this->lead, 'Tunde Akin', 'The quote came in high');
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return [$id, (int) $r['request']];
    }

    public function test_the_role_is_given_by_staff_and_opens_the_money_never_the_planning_of_it(): void
    {
        $this->assertFalse(EventPermissions::standing($this->fin, $this->ev)['any']);
        $this->makeFinance();
        $st = EventPermissions::standing($this->fin, $this->ev);
        $this->assertTrue($st['finance'] && $st['any']);
        $this->assertSame('Finance admin', EventPermissions::label($st));
        foreach (['money.view', 'money.approve', 'goals.view', 'reports.view'] as $p) $this->assertContains($p, $st['perms']);
        $this->assertNotContains('money.plan', $st['perms'], 'who asks for money is never who approves it');
        $this->assertNotContains('plan.edit', $st['perms']);
        $this->assertTrue(EventPermissions::allowsView($st, 'money', 'approvals'));
        $this->assertFalse(EventPermissions::allowsView($st, 'roles'), 'nothing about who may do what');
        $this->assertFalse(Plan::addLine($this->ev, ['title' => 'x', 'estimate' => '10'], $this->fin)['ok']);
        $this->assertContains($this->ev, array_map(static fn ($e) => (int) $e->id, EventPermissions::events($this->fin)), 'the event is in their list');
    }

    public function test_spend_over_the_leads_limit_goes_to_the_events_finance_admin_and_they_decide_it(): void
    {
        [, $req] = $this->asked();
        $r = Requests::find($req);
        $this->assertSame('role', $r->decider_kind, 'with no finance admin: the platform’s finance team');
        $this->makeFinance();
        [$line, $req] = $this->asked();
        $r = Requests::find($req);
        $this->assertSame(['member', $this->fin], [$r->decider_kind, (int) $r->decider_id]);
        $this->assertFalse(Requests::canDecide($r, 'member', $this->lead), 'the lead who asked cannot');
        $this->assertTrue(Requests::act($req, 'approve', 'member', $this->fin, 'Funmi Bello')['ok']);
        Plan::syncRequest($req);
        $this->assertSame('approved', DB::table('gates_event_budget')->where('id', $line)->value('status'));
    }

    public function test_a_money_gate_is_the_finance_admins_to_sign(): void
    {
        $g = Goals::create($this->ev, ['metric' => 'tickets', 'target' => '1', 'deadline' => date('Y-m-d', strtotime('+20 days'))], $this->lead);
        $this->assertTrue($g['ok']);
        $gate = (int) DB::table('gates_event_gates')->where('goal_id', $g['id'])->value('id');
        DB::table('gates_event_gates')->where('id', $gate)->update(['approver' => 'owner']);   // a money gate
        $this->assertFalse(Goals::signGate($gate, $this->fin, 'missed', 'Not reached')['ok']);
        $this->makeFinance();
        $this->assertTrue(Goals::signGate($gate, $this->fin, 'missed', 'Not reached')['ok']);
    }

    public function test_ending_it_sends_spend_back_to_the_platforms_finance_team(): void
    {
        $this->makeFinance();
        $grant = (int) DB::table('gates_grants')->where('actor_id', $this->fin)->where('capability', 'event.finance')->value('id');
        $this->assertFalse(Leads::end($grant, $this->ev + 1, 1, 'event.finance'), 'only at its own event');
        $this->assertTrue(Leads::end($grant, $this->ev, 1, 'event.finance'));
        $this->assertFalse(EventPermissions::standing($this->fin, $this->ev)['finance']);
        [, $req] = $this->asked();
        $this->assertSame('role', Requests::find($req)->decider_kind);
    }

    public function test_they_see_the_money_earned_from_votes_and_tickets_with_refunds_kept_out(): void
    {
        $now = date('Y-m-d H:i:s');
        $prog = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'p-' . bin2hex(random_bytes(3)), 'title' => 'Teaching Award', 'is_active' => 1, 'created_at' => $now]);
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $prog, 'year' => 2026, 'status' => 'voting', 'created_at' => $now]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cycle, 'slug' => 'primary', 'title' => 'Primary', 'sort_order' => 0]);
        DB::table('gates_event_programmes')->insert(['event_id' => $this->ev, 'programme_id' => $prog]);
        $nom = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => 'Ngozi Eze', 'status' => 'approved', 'vote_count' => 0]);
        $cols = array_flip(DB::schema()->getColumnListing('gates_donations'));
        $pay = fn (int $naira, ?string $refunded = null) => DB::table('gates_donations')->insert(array_intersect_key([
            'amount_naira' => $naira, 'status' => 'confirmed', 'tier' => 'paid-vote', 'intent_nominee_id' => $nom, 'reference' => 'R' . bin2hex(random_bytes(4)),
            'email' => 'buyer@example.test', 'donor_email' => 'buyer@example.test', 'donor_name' => 'A buyer', 'payment_ref' => 'P' . bin2hex(random_bytes(4)), 'currency' => 'NGN', 'refunded_at' => $refunded, 'created_at' => $now], $cols));
        $pay(20000);
        $pay(5000);
        $pay(8000, $now);
        $v = \AfricaGates\Services\EventPlan\Goals::voteIncome($this->ev);
        $this->assertSame([25000, 2, 8000, 1], [$v['total'], $v['orders'], $v['refunded'], $v['refunds']], 'a refunded payment is shown apart, never counted');
        $this->makeFinance();
        $page = json_encode(\AfricaGates\Services\EventPlan\EventPages::page(['id' => $this->fin, 'name' => 'Funmi'], Plan::event($this->ev), 'money', 'income', []));
        $this->assertStringContainsString('Earned from votes and tickets', (string) $page);
        $this->assertStringContainsString('25,000', (string) $page);
        $this->assertStringContainsString('Teaching Award', (string) $page, 'by award and category');
    }

    /** POST to the lead portal as somebody; returns [refused?, the sentence shown]. */
    private function leadPost(int $as, array $body): array
    {
        $_SESSION['user_id'] = $as;
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $b->build()->get(LeadController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')
            ->withParsedBody($body + ['back' => '/lead']), new Response());
        return [isset($_SESSION['lead_error']), (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '')];
    }

    /** Owner, 13 Nov 2026: the LEAD asks for the finance admin, and an OWNER approves it. */
    public function test_the_lead_asks_and_only_an_owner_approves(): void
    {
        $owner = $this->member('Olu Owner');
        Grants::grant('member', $owner, 'event.own', 'event', $this->ev, date('Y-m-d', strtotime('+60 days')), 1);
        $email = (string) DB::table('gates_users')->where('id', $this->fin)->value('email');

        [$refused] = $this->leadPost($this->lead, ['do' => 'finance_give', 'event' => $this->ev, 'email' => $email]);
        $this->assertTrue($refused, 'a reason is needed: the owner reads it first');
        [$refused, $msg] = $this->leadPost($this->lead, ['do' => 'finance_give', 'event' => $this->ev, 'email' => $email, 'reason' => 'Keeps our books']);
        $this->assertFalse($refused, $msg);
        $this->assertFalse(Grants::allows('member', $this->fin, 'event.finance', 'event', $this->ev), 'nothing until an owner approves');

        // The lead sees it waiting, on their own team page.
        $page = (string) json_encode(EventPages::page(['id' => $this->lead, 'name' => 'Tunde Akin'], Plan::event($this->ev), 'people', 'volunteers', []));
        $this->assertStringContainsString('Waiting for an owner', $page);

        $change = (int) DB::table('gates_event_changes')->where('event_id', $this->ev)->where('kind', 'finance')->value('id');
        [$refused] = $this->leadPost($this->lead, ['do' => 'change_decide', 'change' => $change, 'verdict' => 'approve']);
        $this->assertTrue($refused, 'the lead cannot approve their own ask');
        $this->assertFalse(Grants::allows('member', $this->fin, 'event.finance', 'event', $this->ev));

        // The owner — not a lead — sees it with its button on Rules › Changes.
        $ownerView = (string) json_encode(EventPages::page(['id' => $owner, 'name' => 'Olu Owner'], Plan::event($this->ev), 'rules', 'changes', []));
        $this->assertStringContainsString('change_decide', $ownerView);
        [$refused, $msg] = $this->leadPost($owner, ['do' => 'change_decide', 'change' => $change, 'verdict' => 'approve']);
        $this->assertFalse($refused, $msg);
        $this->assertTrue(Grants::allows('member', $this->fin, 'event.finance', 'event', $this->ev), 'the owner\'s approval gives it');

        $grant = (int) Leads::finance($this->ev)[0]->id;
        [$refused, $msg] = $this->leadPost($this->lead, ['do' => 'finance_end', 'event' => $this->ev, 'grant' => $grant]);
        $this->assertFalse($refused, $msg);
        $this->assertFalse(Grants::allows('member', $this->fin, 'event.finance', 'event', $this->ev));
    }

    /** With no owner, staff decide it — and a lead's ask cannot lapse into a grant. */
    public function test_with_no_owner_staff_decide_and_nothing_lapses(): void
    {
        $email = (string) DB::table('gates_users')->where('id', $this->fin)->value('email');
        $r = \AfricaGates\Services\EventPlan\Changes::proposeFinance($this->ev, $email, '', 'Keeps our books', $this->lead);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('staff', $r['msg']);
        DB::table('gates_event_changes')->where('id', $r['id'])->update(['lapse_at' => date('Y-m-d H:i:s', time() - 60)]);
        \AfricaGates\Services\EventPlan\Changes::lapse($this->ev, true);
        $this->assertFalse(Grants::allows('member', $this->fin, 'event.finance', 'event', $this->ev), 'a large change never lapses');
        $d = \AfricaGates\Services\EventPlan\Changes::decide((int) $r['id'], 0, 'approve', '', true);
        $this->assertTrue($d['ok'], (string) ($d['error'] ?? ''));
        $this->assertTrue(Grants::allows('member', $this->fin, 'event.finance', 'event', $this->ev));
    }

    public function test_the_lead_cannot_approve_their_own_money(): void
    {
        $mine = (string) DB::table('gates_users')->where('id', $this->lead)->value('email');
        [$refused] = $this->leadPost($this->lead, ['do' => 'finance_give', 'event' => $this->ev, 'email' => strtoupper($mine), 'reason' => 'x']);
        $this->assertTrue($refused, 'not to themselves');

        $other = $this->member('Second Lead');
        Grants::grant('member', $other, 'event.lead', 'event', $this->ev, date('Y-m-d', strtotime('+60 days')), 1);
        [$refused] = $this->leadPost($this->lead, ['do' => 'finance_give', 'event' => $this->ev, 'reason' => 'x',
            'email' => (string) DB::table('gates_users')->where('id', $other)->value('email')]);
        $this->assertTrue($refused, 'not to another lead of the same event');
        $this->assertSame([], Leads::finance($this->ev));
        $this->assertSame(0, DB::table('gates_event_changes')->where('kind', 'finance')->count(), 'and nothing was even asked');

        // And a volunteer at the event, or anybody else, cannot hand it out at all.
        [$refused] = $this->leadPost($this->fin, ['do' => 'finance_give', 'event' => $this->ev, 'email' => 'someone@example.test', 'reason' => 'x']);
        $this->assertTrue($refused, 'only a lead of this event');
    }

    /**
     * The decider of an event's spend was fixed when the request was made and never asked
     * again, so a finance admin whose role had ended could still approve, and nothing held
     * the finance limit on this path (AUDIT-AND-PLAN EV-4). Both are asked at decision time
     * now, and a lapsed request falls to the platform's finance team, which can see it.
     */
    public function test_a_finance_admin_whose_role_ended_cannot_decide_and_finance_can(): void
    {
        $this->makeFinance();
        [, $rid] = $this->asked();
        $r = DB::table('gates_requests')->where('id', $rid)->first();
        $this->assertTrue(Requests::canDecide($r, 'member', $this->fin), 'the live finance admin decides');

        foreach (DB::table('gates_grants')->where('capability', 'event.finance')->pluck('id') as $g) Grants::revoke((int) $g, 1);
        $this->assertFalse(Requests::canDecide($r, 'member', $this->fin), 'a finance admin whose role ended still decided');
        $this->assertTrue(Requests::canDecide($r, 'admin', 1, 'superadmin'), 'and it falls to the platform finance team');
        $this->assertContains($rid, array_map(static fn ($x) => (int) $x->id, Requests::waitingOn('admin', 1, 'superadmin')), 'which can see it');
    }

    public function test_a_spend_above_the_finance_limit_goes_to_platform_finance(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'event_finance_limit_naira', 'value' => '1000']);
        Plan::resetRules();
        $this->makeFinance();
        [, $rid] = $this->asked();
        $r = DB::table('gates_requests')->where('id', $rid)->first();
        $this->assertFalse(Requests::canDecide($r, 'member', $this->fin), 'over the finance admin’s limit');
        $this->assertTrue(Requests::canDecide($r, 'admin', 1, 'superadmin'));
    }
}
