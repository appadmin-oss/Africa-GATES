<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Tasks;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * AN EVENT'S LEAD SETS ITS VOLUNTEER TASKS (owner, 5 Nov 2026: "the event lead should be the
 * one who does this"). Tasks were posted only by staff from Admin › Volunteers.
 *
 * What these try to break: a member who leads nothing posting work; a lead closing or accepting
 * another event's task through a crafted post; an event's task offered to, or taken by, a
 * volunteer who is not working that event; and the accepted hours not landing on the record.
 */
final class LeadVolunteerTasksTest extends TestCase
{
    private int $lead;
    private int $event;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::resetRules();
        $this->lead = $this->member('Tunde Akin');
        $this->event = $this->event('Alimosho ceremony');
        $this->other = $this->event('Ikeja gala');
        $this->assertTrue(Grants::grant('member', $this->lead, 'event.lead', 'event', $this->event, date('Y-m-d', strtotime('+40 days')), 1)['ok']);
        $_SESSION['user_id'] = $this->lead;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['lead_toast'], $_SESSION['lead_error']);
        parent::tearDown();
    }

    public function test_a_lead_posts_a_task_for_their_event_and_its_team_sees_it_at_once(): void
    {
        $msg = $this->post(['do' => 'vtask_add', 'event' => $this->event, 'title' => 'Fold 200 programmes', 'checked_by' => 'lead', 'est_minutes' => '90']);
        $this->assertStringStartsWith('Posted', $msg);
        $t = DB::table('gates_vol_tasks')->where('title', 'Fold 200 programmes')->first();
        $this->assertSame(['event', $this->event, 'open'], [(string) $t->scope_kind, (int) $t->scope_id, (string) $t->status]);
        $this->assertSame($this->lead, (int) $t->posted_by_member);
        $this->assertSame('Tunde Akin', (string) $t->posted_by_name);
        $this->assertNull($t->created_by, 'a lead is not staff: no admin id is written');

        $onTeam = $this->volunteer('Ada Okafor', $this->event);
        $elsewhere = $this->volunteer('Bola Ade', $this->other);
        $this->assertContains((int) $t->id, array_map(static fn ($r) => (int) $r->id, Tasks::library($onTeam)), 'offered to the night’s team');
        $this->assertNotContains((int) $t->id, array_map(static fn ($r) => (int) $r->id, Tasks::library($elsewhere)), 'not to somebody at another event');
        $this->assertFalse(Tasks::take($elsewhere, (int) $t->id)['ok'], 'and not takeable by a crafted post either');
        $this->assertTrue(Tasks::take($onTeam, (int) $t->id)['ok']);

        // The lead's page lists it and the take waiting to be accepted.
        $html = $this->page();
        $this->assertStringContainsString('Fold 200 programmes', $html);
        $this->assertStringContainsString('Taken, waiting to be accepted', $html);
        $this->assertStringContainsString('name="take"', $html);
    }

    public function test_accepting_puts_verified_hours_on_the_volunteers_record(): void
    {
        $this->post(['do' => 'vtask_add', 'event' => $this->event, 'title' => 'Label the gift bags', 'checked_by' => 'lead']);
        $t = DB::table('gates_vol_tasks')->where('title', 'Label the gift bags')->first();
        $v = $this->volunteer('Ada Okafor', $this->event);
        Tasks::take($v, (int) $t->id);
        $take = (int) DB::table('gates_vol_task_takes')->where('task_id', $t->id)->value('id');

        $this->assertStringContainsString('between a quarter', $this->post(['do' => 'vtask_accept', 'event' => $this->event, 'take' => $take, 'hours' => '0']));
        $this->assertStringStartsWith('Accepted', $this->post(['do' => 'vtask_accept', 'event' => $this->event, 'take' => $take, 'hours' => '1.5']));
        $h = DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('source', 'task')->first();
        $this->assertNotNull($h);
        $this->assertEqualsWithDelta(1.5, (float) $h->hours, 0.001);
        $this->assertSame('verified', (string) $h->status, 'accepting is the check');
        $this->assertStringContainsString('already been accepted', $this->post(['do' => 'vtask_accept', 'event' => $this->event, 'take' => $take, 'hours' => '1']));
    }

    public function test_nobody_else_and_no_other_event(): void
    {
        // A member who leads nothing.
        $_SESSION['user_id'] = $this->member('Femi Ade');
        $this->assertStringContainsString('Only a lead of this event', $this->post(['do' => 'vtask_add', 'event' => $this->event, 'title' => 'Sneaky', 'checked_by' => 'lead']));
        $this->assertFalse(DB::table('gates_vol_tasks')->where('title', 'Sneaky')->exists());
        $_SESSION['user_id'] = $this->lead;

        // A lead of THIS event cannot post for, close or accept on ANOTHER.
        $this->assertStringContainsString('Only a lead of this event', $this->post(['do' => 'vtask_add', 'event' => $this->other, 'title' => 'Not mine', 'checked_by' => 'lead']));
        $theirs = Tasks::postForEvent($this->other, ['title' => 'Their task', 'checked_by' => 'lead'], 999, 'Someone')['id'];
        $this->assertStringContainsString('not open on this event', $this->post(['do' => 'vtask_close', 'event' => $this->event, 'task' => $theirs]));
        $this->assertSame('open', (string) DB::table('gates_vol_tasks')->where('id', $theirs)->value('status'));

        $v = $this->volunteer('Bola Ade', $this->other);
        Tasks::take($v, $theirs);
        $take = (int) DB::table('gates_vol_task_takes')->where('task_id', $theirs)->value('id');
        $this->assertStringContainsString('not on this event', $this->post(['do' => 'vtask_accept', 'event' => $this->event, 'take' => $take, 'hours' => '2']));
        $this->assertFalse(DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->exists());
    }

    public function test_closing_stops_new_takers_and_an_unknown_module_is_refused(): void
    {
        $this->post(['do' => 'vtask_add', 'event' => $this->event, 'title' => 'Usher briefing pack', 'checked_by' => 'lead']);
        $id = (int) DB::table('gates_vol_tasks')->where('title', 'Usher briefing pack')->value('id');
        $this->assertStringStartsWith('Closed', $this->post(['do' => 'vtask_close', 'event' => $this->event, 'task' => $id]));
        $v = $this->volunteer('Ada Okafor', $this->event);
        $this->assertFalse(Tasks::take($v, $id)['ok']);
        $this->assertStringContainsString('does not exist', $this->post(['do' => 'vtask_add', 'event' => $this->event, 'title' => 'X', 'checked_by' => 'lead', 'module_key' => 'no-such-module']));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function event(string $title): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => $title,
            'event_date' => date('Y-m-d 18:00:00', strtotime('+10 days')), 'venue' => 'Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** An approved volunteer holding a door role at one event. */
    private function volunteer(string $name, int $event): object
    {
        $uid = $this->member($name);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Kin', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun',
            'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        Volunteers::approve((int) $r['id'], 1);
        $this->assertTrue(Grants::grant('member', $uid, 'vol.door', 'event', $event, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        return Volunteers::of($uid);
    }

    private function ctrl(): LeadController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(LeadController::class);
    }

    private function post(array $body): string
    {
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $this->ctrl()->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')->withParsedBody($body + ['back' => '/lead']), new Response());
        return (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '');
    }

    private function page(): string
    {
        $_SERVER['REQUEST_URI'] = '/lead/event/' . $this->event . '/people';
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/lead/event/' . $this->event . '/people')->withQueryParams(['tab' => 'tasks']);
        $res = $this->ctrl()->event($req, new Response(), ['id' => $this->event, 'page' => 'people']);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }
}
