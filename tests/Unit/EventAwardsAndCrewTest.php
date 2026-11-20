<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\EventsController;
use AfricaGates\Services\EventsFront;
use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Owner, 1 Nov 2026: "events do not display their awards and volunteers cannot be added to an
 * event". An event names the awards it presents wherever it is shown, and its own admin page
 * adds and removes volunteers — with the Volunteers page's permission, never the Events page's.
 */
final class EventAwardsAndCrewTest extends TestCase
{
    private int $event = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = (int) DB::table('gates_site_events')->insertGetId(['title' => 'Gala night', 'slug' => 'gala-' . bin2hex(random_bytes(3)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+10 days')), 'status' => 'published']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function programme(string $title, int $active = 1): int
    {
        return (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'p-' . bin2hex(random_bytes(3)), 'title' => $title, 'is_active' => $active]);
    }

    private function ctrl(): EventsController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(EventsController::class);
    }

    private function page(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/events/' . $this->event . '/leads');
        return (string) $this->ctrl()->leads($req, new Response(), ['id' => (string) $this->event])->getBody();
    }

    private function post(array $body): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/events/' . $this->event . '/leads')->withParsedBody($body);
        $this->ctrl()->leadsAct($req, new Response(), ['id' => (string) $this->event]);
    }

    public function test_an_event_names_its_awards_and_never_an_inactive_one(): void
    {
        $a = $this->programme('Teachers Prize');
        $sandbox = $this->programme('Rehearsal award', 0);
        foreach ([$a, $sandbox] as $p) DB::table('gates_event_programmes')->insert(['event_id' => $this->event, 'programme_id' => $p]);
        $list = EventsFront::awards($this->event)['list'];
        $this->assertSame(['Teachers Prize'], array_column($list, 'title'));
        $this->assertStringStartsWith('/awards/p-', $list[0]['url']);
        $this->assertSame([], EventsFront::awards($this->event + 1)['list']);

        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';
        $this->assertStringContainsString('Teachers Prize', $this->page());
    }

    public function test_staff_add_and_remove_a_volunteer_on_the_event(): void
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Bola Ade', 'email' => 'bola.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $vid = (int) DB::table('gates_volunteers')->insertGetId(['user_id' => $uid, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $until = date('Y-m-d', strtotime('+11 days'));

        // A role that can open Events but not Volunteers sees the crew, not the form, and cannot post it.
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'events_manager_that_does_not_exist';
        $this->post(['do' => 'vol_give', 'volunteer' => $vid, 'capability' => 'vol.door', 'until' => $until]);
        $this->assertSame(0, (int) DB::table('gates_grants')->count());

        $_SESSION['admin_role'] = 'superadmin';
        $this->assertStringContainsString('Add to this event', $this->page());
        $this->post(['do' => 'vol_give', 'volunteer' => $vid, 'capability' => 'award.moderate', 'until' => $until]);
        $this->assertSame(0, (int) DB::table('gates_grants')->count(), 'only the roles a night is staffed with');
        $this->post(['do' => 'vol_give', 'volunteer' => $vid, 'capability' => 'vol.door', 'until' => $until]);
        $this->assertTrue(Grants::allows('member', $uid, 'vol.door', 'event', $this->event));
        $html = $this->page();
        $this->assertStringContainsString('Bola Ade', $html);
        $this->assertStringContainsString('Door steward', $html);

        // A grant on another event cannot be ended from this page.
        $other = Grants::grant('member', $uid, 'vol.usher', 'event', $this->event + 1, $until, 1);
        $this->post(['do' => 'vol_end', 'grant' => $other['id']]);
        $this->assertTrue(Grants::allows('member', $uid, 'vol.usher', 'event', $this->event + 1));

        $mine = (int) DB::table('gates_grants')->where('scope_id', $this->event)->value('id');
        $this->post(['do' => 'vol_end', 'grant' => $mine]);
        $this->assertFalse(Grants::allows('member', $uid, 'vol.door', 'event', $this->event));
    }
}
