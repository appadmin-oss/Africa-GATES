<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\LeadController;
use AfricaGates\Admin\Controllers\RequestsController;
use AfricaGates\Services\EventPlan\LeadPages;
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
 */
final class LeadConsoleTest extends TestCase
{
    private int $lead;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::resetRules();
        $this->lead = $this->admin('Tunde Akin', 'editor');
        $this->other = $this->admin('Femi Ade', 'editor');
        $this->as($this->lead, 'Tunde Akin', 'editor');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['lead_toast'], $_SESSION['lead_error'],
              $_SESSION['rq_admin_ok'], $_SESSION['rq_admin_error']);
        parent::tearDown();
    }

    private function admin(string $name, string $role): int
    {
        return (int) DB::table('gates_admins')->insertGetId(['email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'name' => $name, 'role' => $role, 'is_active' => 1, 'password_hash' => 'x', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function as(int $id, string $name, string $role): void
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
        $r = Grants::grant('admin', $admin ?? $this->lead, 'event.lead', 'event', $event, date('Y-m-d', strtotime('+40 days')), 1);
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
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/lead')->withQueryParams($query);
        $res = $this->ctrl(LeadController::class)->{$method}($req, new Response(), $args);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    /** POST to /admin/lead/act; returns the sentence it answered with. */
    private function post(array $body): string
    {
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $this->ctrl(LeadController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/admin/lead/act')->withParsedBody($body + ['back' => '/admin/lead']), new Response());
        return (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '');
    }

    public function test_an_admin_who_leads_nothing_is_told_so_and_no_zero_is_drawn(): void
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
        $this->assertStringContainsString('Added', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'Venue floor plan', 'owner' => 'admin:' . $this->lead,
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
        $this->assertStringContainsString('Pick somebody on this event’s team', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'X', 'owner' => 'admin:' . $this->other]));
        $this->lead($ev, $this->other);
        $this->assertStringContainsString('Added', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'X', 'owner' => 'admin:' . $this->other]));
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
        $this->as($boss, 'Sade Bello', 'admin');
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
        $this->as($this->other, 'Femi Ade', 'editor');
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

    public function test_access_ends_with_the_grant_and_an_administrator_gives_it_until_the_day_after(): void
    {
        $ev = $this->event('Alimosho ceremony', '+10 days');
        $this->as($this->admin('Ada Obi', 'superadmin'), 'Ada Obi', 'superadmin');
        $this->assertStringContainsString('They lead it now', $this->post(['do' => 'lead_grant', 'admin' => $this->lead, 'event' => $ev]));
        $g = DB::table('gates_grants')->where('capability', 'event.lead')->where('actor_id', $this->lead)->first();
        $this->assertSame(date('Y-m-d', strtotime('+11 days')) . ' 23:59:59', (string) $g->expires_at);
        $viewer = $this->admin('Kemi Bello', 'viewer');
        $this->assertStringContainsString('can only look', $this->post(['do' => 'lead_grant', 'admin' => $viewer, 'event' => $ev]));

        $this->as($this->lead, 'Tunde Akin', 'editor');
        $this->assertStringContainsString('Alimosho ceremony', $this->page('root'));
        DB::table('gates_grants')->where('id', $g->id)->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertStringContainsString('You don’t lead an event yet', $this->page('root'), 'an ended grant removes the event on the next page');
    }

    public function test_a_role_that_may_only_look_cannot_change_a_plan_even_with_a_grant(): void
    {
        $ev = $this->event();
        $this->lead($ev);
        $_SESSION['admin_role'] = 'viewer';
        $this->assertStringContainsString('can look here but not change', $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'X']));
        $this->assertSame(0, DB::table('gates_event_work')->count());
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
        $this->post(['do' => 'work_add', 'event' => $ev, 'title' => 'Venue floor plan', 'owner' => 'admin:' . $this->lead, 'due_at' => date('Y-m-d', strtotime('+2 days')), 'est_hours' => '50']);
        $this->post(['do' => 'risk_add', 'event' => $ev, 'title' => 'Rain', 'likelihood' => 'possible', 'impact' => 'medium']);
        $this->post(['do' => 'line_add', 'event' => $ev, 'title' => 'Chairs', 'estimate' => '30000']);
        foreach (array_keys(LeadPages::ROOT) as $p) {
            $html = $this->page('root', ['page' => $p]);
            $this->assertStringContainsString('aria-current="page"', $html, $p);
            $this->assertDoesNotMatchRegularExpression('~sh7-badge[^>]*>0<~', $html, $p);
        }
        $this->assertStringContainsString('Over capacity', $this->page('root', ['page' => 'people']), '50h against a 40h week');
        foreach (['today', 'plan', 'money', 'people'] as $p) $this->assertStringContainsString('Alimosho ceremony', $this->page('event', ['id' => $ev, 'page' => $p]), $p);
        $this->assertStringContainsString('/admin/lead/act', $this->page('event', ['id' => $ev, 'page' => 'plan']), 'the shell posts to the lead console, not the volunteer workspace');
    }
}
