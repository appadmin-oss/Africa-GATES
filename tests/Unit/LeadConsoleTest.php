<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Admin\Controllers\RequestsController;
use AfricaGates\Services\EventPlan\LeadPages;
use AfricaGates\Services\EventPlan\Leads;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * THE EVENT LEAD CONSOLE, DRIVEN THROUGH ITS CONTROLLERS (consoles v7 §5; review slice L1).
 *
 * Each case renders the real page or posts the real form and reads the record, because the
 * promises are about what a lead SEES and what a lead CAN DO: only their own events, a
 * readiness that is counted, a risk level nobody types, spend they approve up to a limit and
 * ask finance for above it, and access that ends with the event.
 *
 * A lead is a MEMBER account (Plan::ACTOR) at /lead, never a staff admin (owner, 29 Oct 2026):
 * an organisation adopting the platform brings leads who are not our staff. Staff give the
 * role by email from the event's admin page; a staff grant opens nothing here.
 */
final class LeadConsoleTest extends TestCase
{
    private int $lead;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::resetRules();
        $this->lead = $this->member('Tunde Akin');
        $this->other = $this->member('Femi Ade');
        $this->as($this->lead);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['lead_toast'], $_SESSION['lead_error'],
              $_SESSION['rq_admin_ok'], $_SESSION['rq_admin_error']);
        parent::tearDown();
    }

    private function admin(string $name, string $role): int
    {
        return (int) DB::table('gates_admins')->insertGetId(['email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'name' => $name, 'role' => $role, 'is_active' => 1, 'password_hash' => 'x', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function member(string $name, int $verified = 1): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => $verified, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** Signed in as a member: how a lead arrives. */
    private function as(int $id): void
    {
        unset($_SESSION['admin_id']);
        $_SESSION['user_id'] = $id;
    }

    /** Signed in to the staff console: how finance decides. */
    private function staff(int $id, string $name, string $role): void
    {
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_name'] = $name;
        $_SESSION['admin_role'] = $role;
    }

    private function event(string $title = 'Alimosho ceremony', string $when = '+10 days', array $o = []): int
    {
        return (int) DB::table('gates_site_events')->insertGetId($o + ['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => $title,
            'event_date' => date('Y-m-d 18:00:00', strtotime($when)), 'venue' => 'Alimosho Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function lead(int $event, ?int $admin = null): void
    {
        $r = Grants::grant('member', $admin ?? $this->lead, 'event.lead', 'event', $event, date('Y-m-d', strtotime('+40 days')), 1);
        $this->assertTrue($r['ok']);
    }

    private function ctrl(string $class): object
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get($class);
    }

    /** GET a lead page; returns the HTML. */
    private function page(string $method, array $args = [], array $query = []): string
    {
        $_SERVER['REQUEST_URI'] = '/lead';   // what a real request carries; an earlier test's address must not leak in
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/lead')->withQueryParams($query);
        $res = $this->ctrl(LeadController::class)->{$method}($req, new Response(), $args);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    /** POST to /admin/lead/act; returns the sentence it answered with. */
    private function post(array $body): string
    {
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $this->ctrl(LeadController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')->withParsedBody($body + ['back' => '/lead']), new Response());
        return (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '');
    }

    public function test_a_member_who_leads_nothing_is_told_so_and_no_zero_is_drawn(): void
    {
        $html = $this->page('root');
        $this->assertStringContainsString('You don’t lead an event yet', $html);
        $this->assertDoesNotMatchRegularExpression('~sh7-badge[^>]*>0<~', $html, 'a 0 badge was drawn');
    }

    public function test_a_lead_sees_only_the_events_they_hold_a_grant_on(): void
    {
        $mine = $this->event('Alimosho ceremony');
        $theirs = $this->event('Lagos Education Honours Night', '+38 days');
        $this->lead($mine);
        $this->lead($theirs, $this->other);
        $html = $this->page('root');
        $this->assertStringContainsString('Alimosho ceremony', $html);
        $this->assertStringNotContainsString('Lagos Education Honours Night', $html);
        $this->expectException(HttpNotFoundException::class);
        $this->page('event', ['id' => $theirs]);
    }

    public function test_a_crafted_post_about_another_event_writes_nothing(): void
    {
        $theirs = $this->event('Lagos Education Honours Night');
        $this->lead($theirs, $this->other);
        $this->assertStringContainsString('Only a lead of this event', $this->post(['do' => 'work_add', 'event' => $theirs, 'title' => 'Sneaky']));
        $this->assertSame(0, DB::table('gates_event_work')->count());
        $this->assertSame(0, DB::table('gates_audit_log')->where('action', 'like', 'lead.%')->count(), 'a refusal is not audited as a change');
    }

    public function test_readiness_is_counted_from_the_work_and_a_late_blocker_tops_the_overview(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $this->assertStringContainsString('Start the plan', $this->page('root'), 'an empty plan shows its steps, not zeros');
        $this->assertStringContainsString('Added', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'Venue floor plan', 'owner' => 'member:' . $this->lead,
            'due_at' => date('Y-m-d', strtotime('-3 days')), 'est_hours' => '6', 'blocks_night' => '1', 'blocks_note' => 'the safety permit']));
        $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'Run-of-show v2', 'due_at' => date('Y-m-d', strtotime('+3 days'))]);
        $this->assertSame(['done' => 0, 'all' => 2, 'pct' => 0], Plan::readiness($ev));
        $html = $this->page('root');
        $this->assertStringContainsString('Blocking a night', $html);
        // Due by the end of a day three days ago: two whole days late.
        $this->assertStringContainsString('Venue floor plan is 2 days late', $html);
        $this->assertStringContainsString('the safety permit waits on it', $html);

        $w = (int) DB::table('gates_event_work')->where('title', 'Run-of-show v2')->value('id');
        $this->assertStringContainsString('Done', $this->post(['do' => 'work_status', 'work' => $w, 'status' => 'done']));
        $this->assertSame(50, Plan::readiness($ev)['pct']);
        $this->assertStringContainsString('50% ready', $this->page('root'));
        $this->assertSame(3, DB::table('gates_audit_log')->where('action', 'like', 'lead.%')->count(), 'one audit entry per change');
    }

    public function test_work_with_no_owner_lands_in_the_leads_inbox_and_a_due_date_is_the_end_of_that_day(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'Shortlist three caterers', 'due_at' => date('Y-m-d')]);
        $w = DB::table('gates_event_work')->first();
        $this->assertSame(date('Y-m-d') . ' 23:59:59', (string) $w->due_at);
        $this->assertFalse(Plan::isLate($w), 'due today is not late today');
        $html = $this->page('root', ['page' => 'inbox']);
        $this->assertStringContainsString('Shortlist three caterers', $html);
        $this->assertStringContainsString('nobody has it yet', $html);
    }

    public function test_an_owner_must_be_on_the_events_team(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $this->assertStringContainsString('Pick somebody on this event’s team', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'X', 'owner' => 'member:' . $this->other]));
        $this->lead($ev, $this->other);
        $this->assertStringContainsString('Added', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'X', 'owner' => 'member:' . $this->other]));
    }

    public function test_a_risk_level_is_computed_from_how_likely_and_how_bad(): void
    {
        $this->assertSame('high', Plan::level('likely', 'high'));
        $this->assertSame('high', Plan::level('possible', 'high'));
        $this->assertSame('medium', Plan::level('possible', 'medium'));
        $this->assertSame('medium', Plan::level('unlikely', 'high'));
        $this->assertSame('low', Plan::level('unlikely', 'low'));
        $ev = $this->event();
        $this->lead($ev);
        $this->post(['do' => 'risk_add', 'event' => $ev, 'title' => 'Floor plan late, permit at risk', 'likelihood' => 'likely', 'impact' => 'high']);
        $this->post(['do' => 'risk_add', 'event' => $ev, 'title' => 'Rain at the entrance', 'likelihood' => 'possible', 'impact' => 'medium']);
        $html = $this->page('root', ['page' => 'risks']);
        $this->assertLessThan(strpos($html, 'Rain at the entrance'), strpos($html, 'Floor plan late'), 'high first');
        $this->assertMatchesRegularExpression('~Risks.{0,400}sh7-badge--bad~s', $html, 'the rail count is red when a risk is high');
    }

    public function test_a_lead_approves_up_to_their_limit_and_asks_finance_above_it(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $limit = Plan::limitMinor();
        $this->post(['do' => 'line_add', 'event' => $ev, 'title' => 'Volunteer transport', 'estimate' => '45,000']);
        $this->post(['do' => 'line_add', 'event' => $ev, 'title' => 'MC fee', 'estimate' => (string) ($limit / 100 + 250000)]);
        $small = (int) DB::table('gates_event_budget')->where('title', 'Volunteer transport')->value('id');
        $big = (int) DB::table('gates_event_budget')->where('title', 'MC fee')->value('id');
        $this->assertStringContainsString('You can approve', $this->page('root', ['page' => 'approvals']));

        $this->assertStringContainsString('Approved', $this->post(['do' => 'line_approve', 'line' => $small]));
        $this->assertStringContainsString('over your limit', $this->post(['do' => 'line_approve', 'line' => $big]));
        $this->assertSame('proposed', DB::table('gates_event_budget')->where('id', $big)->value('status'));

        $this->assertStringContainsString('Add what you need and why', $this->post(['do' => 'line_ask', 'line' => $big, 'why' => '']));
        $this->assertStringContainsString('Sent to finance', $this->post(['do' => 'line_ask', 'line' => $big, 'why' => 'The MC holds the date until Friday']));
        $line = DB::table('gates_event_budget')->where('id', $big)->first();
        $this->assertSame('requested', $line->status);
        $req = DB::table('gates_requests')->where('id', $line->request_id)->first();
        $this->assertSame(['spend', 'finance', 'event', $ev], [(string) $req->type, (string) $req->decider_role, (string) $req->scope_kind, (int) $req->scope_id]);
        $this->assertSame($limit + 25000000, (int) $req->amount_minor);

        // Finance sees it, and deciding it moves the line.
        $boss = $this->admin('Sade Bello', 'admin');
        $this->staff($boss, 'Sade Bello', 'admin');
        $res = $this->ctrl(RequestsController::class)->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/requests'), new Response());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('MC fee', $html);
        $this->assertStringContainsString('If approved', $html);
        $post = fn (array $b) => $this->ctrl(RequestsController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/admin/requests')->withParsedBody($b), new Response());
        $post(['request' => $req->id, 'action' => 'decline', 'note' => '']);
        $this->assertStringContainsString('Say why', (string) $_SESSION['rq_admin_error']);
        $post(['request' => $req->id, 'action' => 'approve']);
        $this->assertSame('approved', DB::table('gates_event_budget')->where('id', $big)->value('status'));
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'requests.approve')->count());
    }

    public function test_an_editor_cannot_decide_finances_requests(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $this->post(['do' => 'request', 'type' => 'spend', 'title' => 'Canopy', 'amount' => '900000', 'why' => 'Rain', 'event' => $ev]);
        $id = (int) DB::table('gates_requests')->value('id');
        $this->staff($this->admin('Femi Ade', 'editor'), 'Femi Ade', 'editor');
        $this->ctrl(RequestsController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/admin/requests')->withParsedBody(['request' => $id, 'action' => 'approve']), new Response());
        $this->assertSame('waiting', DB::table('gates_requests')->where('id', $id)->value('status'));
    }

    public function test_a_lead_may_only_ask_about_their_own_events(): void
    {
        $theirs = $this->event('Lagos Education Honours Night');
        $this->lead($theirs, $this->other);
        $this->assertStringContainsString('Pick one of your events', $this->post(['do' => 'request', 'type' => 'venue', 'title' => 'Move it', 'why' => 'x', 'event' => $theirs]));
        $this->assertSame(0, DB::table('gates_requests')->count());
    }

    public function test_staff_give_it_by_email_until_the_day_after_and_it_ends_with_the_grant(): void
    {
        $ev = $this->event('Alimosho ceremony', '+10 days');
        $email = (string) DB::table('gates_users')->where('id', $this->lead)->value('email');
        /* Nobody with the address, or an unconfirmed one, is now INVITED rather than refused
           (owner, 3 Nov 2026) — and an unconfirmed address still never holds the role: the
           invitation waits, and RoleInvitesTest holds when it switches on. */
        $none = Leads::give($ev, 'nobody@example.test', '', 1);
        $this->assertTrue($none['ok'] && !empty($none['pending']) && empty($none['granted']), 'nobody with that address: invited, not given');
        $unverified = $this->member('Kemi Bello', 0);
        $un = Leads::give($ev, (string) DB::table('gates_users')->where('id', $unverified)->value('email'), '', 1);
        $this->assertTrue($un['ok'] && !empty($un['pending']), 'an unconfirmed address is invited');
        $this->assertFalse(\AfricaGates\Services\Grants::allows('member', $unverified, 'event.lead', 'event', $ev), 'an unconfirmed address is not proof of who holds it');
        $this->assertTrue(Leads::give($ev, strtoupper($email), '', 1)['ok'], 'the address as typed, in any case');
        $this->assertFalse(Leads::give($ev, $email, '', 1)['ok'], 'not twice');
        $g = DB::table('gates_grants')->where('capability', 'event.lead')->where('actor_kind', 'member')->where('actor_id', $this->lead)->first();
        $this->assertSame(date('Y-m-d', strtotime('+11 days')) . ' 23:59:59', (string) $g->expires_at);

        $this->assertStringContainsString('Alimosho ceremony', $this->page('root'));
        DB::table('gates_grants')->where('id', $g->id)->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertStringContainsString('You don’t lead an event yet', $this->page('root'), 'an ended grant removes the event on the next page');
    }

    public function test_a_staff_grant_opens_nothing_here_and_the_old_address_forwards(): void
    {
        $ev = $this->event();
        $admin = $this->admin('Ada Obi', 'superadmin');
        Grants::grant('admin', $admin, 'event.lead', 'event', $ev, date('Y-m-d', strtotime('+40 days')), 1);
        $this->staff($admin, 'Ada Obi', 'superadmin');
        $this->as($this->lead);
        $this->assertStringContainsString('You don’t lead an event yet', $this->page('root'), 'a lead is a member; a staff grant is not one');
        $this->assertStringContainsString('Only a lead of this event', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'X']));
        $this->lead($ev);
        $this->assertSame(['Tunde Akin'], array_map(static fn ($g) => $g->name, Leads::of($ev)), 'the event’s Leads page lists members, never a staff grant');

        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($b->build());
        $app = \Slim\Factory\AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $fwd = null;
        foreach ($app->getRouteCollector()->getRoutes() as $r) if ($r->getPattern() === '/admin/lead[/{rest:.*}]') $fwd = $r->getCallable();
        $this->assertNotNull($fwd, 'the old address is still answered');
        $res = $fwd((new ServerRequestFactory())->createServerRequest('GET', '/admin/lead/event/' . $ev . '/plan?tab=risks'), new Response(), ['rest' => 'event/' . $ev . '/plan']);
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/lead/event/' . $ev . '/plan?tab=risks', $res->getHeaderLine('Location'), 'page and view kept');
    }

    public function test_analytics_compares_with_the_last_edition_on_the_same_day_out(): void
    {
        $first = $this->event('Alimosho ceremony 2025', '-355 days', ['series_key' => 'alimosho-ceremony']);
        $now = $this->event('Alimosho ceremony 2026', '+10 days', ['series_key' => 'alimosho-ceremony']);
        $this->lead($now);
        // Ten days before last year's night: 4 tickets. Today, ten days before this one: 5.
        $reg = static function (int $ev, string $at): void {
            DB::table('gates_event_registrations')->insert(['event_id' => $ev, 'name' => 'Guest', 'email' => bin2hex(random_bytes(4)) . '@example.test',
                'status' => 'confirmed', 'quantity' => 1, 'created_at' => $at]);
        };
        for ($i = 0; $i < 4; $i++) $reg($first, date('Y-m-d H:i:s', strtotime('-366 days')));
        $reg($first, date('Y-m-d H:i:s', strtotime('-357 days')));   // after the same day out: not counted
        for ($i = 0; $i < 5; $i++) $reg($now, date('Y-m-d H:i:s', strtotime('-1 day')));
        $html = $this->page('root', ['page' => 'analytics']);
        $this->assertStringContainsString('against Alimosho ceremony 2025', $html);
        $this->assertStringContainsString('4 → 5', $html);
        $this->assertStringContainsString('+25%', $html);
    }

    public function test_every_page_renders_for_a_lead_with_a_plan(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'Venue floor plan', 'owner' => 'member:' . $this->lead, 'due_at' => date('Y-m-d', strtotime('+2 days')), 'est_hours' => '50']);
        $this->post(['do' => 'risk_add', 'event' => $ev, 'title' => 'Rain', 'likelihood' => 'possible', 'impact' => 'medium']);
        $this->post(['do' => 'line_add', 'event' => $ev, 'title' => 'Chairs', 'estimate' => '30000']);
        foreach (array_keys(LeadPages::ROOT) as $p) {
            $html = $this->page('root', ['page' => $p]);
            $this->assertStringContainsString('aria-current="page"', $html, $p);
            $this->assertDoesNotMatchRegularExpression('~sh7-badge[^>]*>0<~', $html, $p);
        }
        $this->assertStringContainsString('Over capacity', $this->page('root', ['page' => 'people']), '50h against a 40h week');
        foreach (['today', 'plan', 'money', 'people'] as $p) $this->assertStringContainsString('Alimosho ceremony', $this->page('event', ['id' => $ev, 'page' => $p]), $p);
        $html = $this->page('event', ['id' => $ev, 'page' => 'plan']);
        $this->assertStringContainsString('action="/lead/act"', $html, 'the shell posts to the lead console, not the volunteer workspace');
        $this->assertStringNotContainsString('/admin', $html, 'nothing on a lead page points into the staff console');
    }

    // ── Roles offered to an address (owner, 3 Nov 2026): told, invited, recruited, findable ──

    private function vol(int $user, string $status = 'active'): void
    {
        DB::table('gates_volunteers')->insert(['user_id' => $user, 'status' => $status, 'display_name' => 'V' . $user, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function emailOf(int $user): string
    {
        return (string) DB::table('gates_users')->where('id', $user)->value('email');
    }

    public function test_somebody_with_no_account_is_invited_and_it_switches_on_when_they_sign_in_with_the_address_confirmed(): void
    {
        $ev = $this->event('Alimosho ceremony', '+10 days');
        $r = Leads::give($ev, 'New.Organiser@Example.test', '', 1, 'Femi Ade');
        $this->assertTrue($r['ok'] && !empty($r['pending']), 'held against the address');
        $this->assertSame('pending', DB::table('gates_role_invites')->where('id', $r['id'])->value('status'));
        // They sign up with that address — unconfirmed: nothing yet.
        $u = (int) DB::table('gates_users')->insertGetId(['name' => 'Ngozi Eze', 'email' => 'new.organiser@example.test', 'password_hash' => 'x',
            'status' => 'active', 'email_verified' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(0, \AfricaGates\Services\RoleInvites::claimFor($u), 'an unconfirmed address is not proof');
        $this->assertFalse(Grants::allows('member', $u, 'event.lead', 'event', $ev));
        // Confirmed: the next request through the member gate makes it theirs.
        DB::table('gates_users')->where('id', $u)->update(['email_verified' => 1]);
        $_SESSION['user_id'] = $u; unset($_SESSION['role_invites_at']);
        (new \AfricaGates\Middleware\UserAuthMiddleware())->__invoke((new ServerRequestFactory())->createServerRequest('GET', '/lead'),
            new class implements \Psr\Http\Server\RequestHandlerInterface { public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return new Response(); } });
        $this->assertTrue(Grants::allows('member', $u, 'event.lead', 'event', $ev), 'nobody had to give it again');
        $i = DB::table('gates_role_invites')->where('id', $r['id'])->first();
        $this->assertSame('granted', $i->status);
        $this->assertSame(date('Y-m-d', strtotime('+11 days')) . ' 23:59:59', (string) DB::table('gates_grants')->where('id', $i->grant_id)->value('expires_at'), 'it ends with the event');
        $this->assertSame(0, \AfricaGates\Services\RoleInvites::claimFor($u), 'once');
    }

    public function test_an_invitation_withdrawn_or_past_its_end_never_switches_on(): void
    {
        $ev = $this->event();
        $a = Leads::give($ev, 'late@example.test', '', 1);
        $this->assertTrue(\AfricaGates\Services\RoleInvites::cancel((int) $a['id'], $ev));
        $this->assertFalse(\AfricaGates\Services\RoleInvites::cancel((int) $a['id'], $this->event('Another')), 'only at its own event');
        $b = Leads::give($ev, 'gone@example.test', '', 1);
        DB::table('gates_role_invites')->where('id', $b['id'])->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        foreach (['late@example.test', 'gone@example.test'] as $e) {
            $u = (int) DB::table('gates_users')->insertGetId(['name' => 'X', 'email' => $e, 'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
            \AfricaGates\Services\RoleInvites::claimFor($u);
            $this->assertFalse(Grants::allows('member', $u, 'event.lead', 'event', $ev), $e);
        }
        $this->assertSame('expired', DB::table('gates_role_invites')->where('id', $b['id'])->value('status'));
    }

    public function test_a_lead_recruits_by_email_and_vetting_is_never_skipped(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $approved = $this->member('Chika Nwosu'); $this->vol($approved);
        $this->assertStringContainsString('role is theirs now', $this->post(['do' => 'vol_invite', 'event' => $ev, 'email' => $this->emailOf($approved), 'capability' => 'vol.door']));
        $this->assertTrue(Grants::allows('member', $approved, 'vol.door', 'event', $ev), 'an approved volunteer holds it at once');
        // Somebody who is not a volunteer yet: invited, and it waits for the volunteer team's approval.
        $applicant = $this->member('Bola Ade');
        $this->assertStringContainsString('waiting for them to apply to volunteer', $this->post(['do' => 'vol_invite', 'event' => $ev, 'email' => $this->emailOf($applicant), 'capability' => 'vol.usher']));
        $this->vol($applicant, 'applicant');
        \AfricaGates\Services\RoleInvites::claimFor($applicant);
        $this->assertFalse(Grants::allows('member', $applicant, 'vol.usher', 'event', $ev), 'an applicant is not yet a volunteer');
        DB::table('gates_volunteers')->where('user_id', $applicant)->update(['status' => 'active']);
        \AfricaGates\Services\RoleInvites::claimFor($applicant);
        $this->assertTrue(Grants::allows('member', $applicant, 'vol.usher', 'event', $ev), 'switched on once approved');
        // A lead never makes a lead, and recruits only for their own events.
        $this->assertStringContainsString('the night’s roles', $this->post(['do' => 'vol_invite', 'event' => $ev, 'email' => 'x@example.test', 'capability' => 'event.lead']));
        $theirs = $this->event('Lagos Education Honours Night');
        $this->assertStringContainsString('isn’t one of your events', $this->post(['do' => 'vol_invite', 'event' => $theirs, 'email' => 'x@example.test', 'capability' => 'vol.door']));
        $this->assertSame(0, DB::table('gates_role_invites')->where('email', 'x@example.test')->count());
        // Ending: only a volunteer role at THIS event.
        $other = (int) Grants::grant('member', $approved, 'vol.door', 'event', $theirs, date('Y-m-d', strtotime('+20 days')), 1)['id'];
        $this->assertStringContainsString('already ended', $this->post(['do' => 'vol_end', 'event' => $ev, 'grant' => $other]));
        $this->assertTrue(Grants::allows('member', $approved, 'vol.door', 'event', $theirs), 'another event’s role is untouched');
        $mine = (int) DB::table('gates_grants')->where('actor_id', $approved)->where('scope_id', $ev)->value('id');
        $this->assertStringContainsString('Ended', $this->post(['do' => 'vol_end', 'event' => $ev, 'grant' => $mine]));
        $this->assertFalse(Grants::allows('member', $approved, 'vol.door', 'event', $ev));
        $this->assertGreaterThan(0, DB::table('gates_audit_log')->where('action', 'lead.vol_invite')->count(), 'every recruit is audited against the event');
    }

    /**
     * Owner, 5 Nov 2026: "it does not show to the lead that they've been added (it says no
     * event)". A lead's role waits for a confirmed address; somebody who signs in with a code
     * texted to their phone never confirms one, and the console said they led nothing.
     */
    public function test_a_lead_whose_address_is_unconfirmed_is_shown_the_event_and_how_to_switch_it_on(): void
    {
        $ev = $this->event('Alimosho ceremony');
        $u = $this->member('Ngozi Eze', 0);
        $this->assertTrue(Leads::give($ev, $this->emailOf($u), '', 1, 'Femi Ade')['pending'] ?? false);
        $this->as($u);
        $html = $this->page('root');
        $this->assertStringContainsString('Lead · Alimosho ceremony', $html, 'the event they were given is named');
        $this->assertStringContainsString('confirm your email address', $html);
        $this->assertStringContainsString('action="/account/verify/resend"', $html, 'with the one button that ends the wait');
        $this->assertStringNotContainsString('You don’t lead an event yet', $html);
        // Confirmed: it opens on the very next page, not five minutes later.
        DB::table('gates_users')->where('id', $u)->update(['email_verified' => 1]);
        $_SESSION['role_invites_at'] = time();
        $this->assertStringContainsString('Your event', $this->page('root'));
        $this->assertTrue(Plan::isLead($u, $ev));
    }

    /** An approved volunteer is vetted; an unconfirmed email must not hold their role for ever. */
    public function test_an_approved_volunteer_gets_the_role_even_if_they_sign_in_by_phone(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $byPhone = $this->member('Emeka Obi', 0); $this->vol($byPhone);
        $this->assertStringContainsString('role is theirs now', $this->post(['do' => 'vol_invite', 'event' => $ev, 'email' => $this->emailOf($byPhone), 'capability' => 'vol.usher']));
        $this->assertTrue(Grants::allows('member', $byPhone, 'vol.usher', 'event', $ev));
        // A lead still needs the confirmed address.
        $other = $this->member('Ada Lead', 0);
        $this->assertTrue(!empty(Leads::give($ev, $this->emailOf($other), '', 1)['pending']));
        $this->assertFalse(Plan::isLead($other, $ev));
    }

    public function test_the_volunteers_page_offers_recruiting_by_email_and_never_lists_the_platforms_roster(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $stranger = $this->member('Ada Obi'); $this->vol($stranger);
        DB::table('gates_volunteers')->where('user_id', $stranger)->update(['display_name' => 'Ada Obi']);
        $html = $this->page('event', ['id' => $ev, 'page' => 'people'], ['tab' => 'volunteers']);
        $this->assertStringContainsString('Add somebody to the team', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringNotContainsString('Ada Obi', $html, 'a volunteer not on this event is not shown to a lead');
    }

    public function test_staff_find_every_events_leads_from_the_events_list(): void
    {
        $ev = $this->event('Alimosho ceremony');
        $this->lead($ev);
        Leads::give($ev, 'waiting@example.test', '', 1);
        $this->staff($this->admin('Femi Ade', 'superadmin'), 'Femi Ade', 'superadmin');
        $c = $this->ctrl(\AfricaGates\Admin\Controllers\EventsController::class);
        $list = (string) $c->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/events'), new Response())->getBody();
        $this->assertStringContainsString('/admin/events/' . $ev . '/leads', $list, 'each event links to its leads');
        $this->assertStringContainsString('Tunde Akin', $list, 'who leads it, on the list');
        $this->assertStringContainsString('1 invited', $list);
        $this->assertStringContainsString('/admin/events/leads', $list);
        $all = (string) $c->allLeads((new ServerRequestFactory())->createServerRequest('GET', '/admin/events/leads'), new Response())->getBody();
        $this->assertStringContainsString('Tunde Akin', $all);
        $this->assertStringContainsString('waiting@example.test', $all);
        $this->assertStringContainsString('Alimosho ceremony', $all);
    }
}
