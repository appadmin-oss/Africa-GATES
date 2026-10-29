<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\VolunteersController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * /admin/volunteers — the staff side that fills the workspace (review slice V1).
 *
 * Each case posts the form the page draws and reads the record, because the two translations
 * between the forms and the services are where this page could quietly do the wrong thing:
 * a place travels as one "kind:id" choice, and a training answer is numbered from 1 on the form
 * and stored from 0.
 */
final class VolunteersAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_name'] = 'Femi Ade';
        $_SESSION['admin_role'] = 'superadmin';
        Training::reset();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['vol_admin_ok'], $_SESSION['vol_admin_error']);
        parent::tearDown();
    }

    private function ctrl(): VolunteersController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteersController::class);
    }

    private function post(array $body): string
    {
        unset($_SESSION['vol_admin_ok'], $_SESSION['vol_admin_error']);
        $this->ctrl()->act((new ServerRequestFactory())->createServerRequest('POST', '/admin/volunteers')->withParsedBody($body), new Response());
        return (string) ($_SESSION['vol_admin_error'] ?? $_SESSION['vol_admin_ok'] ?? '');
    }

    private function applicant(): int
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chika Nwosu', 'email' => 'chika.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        return (int) $r['id'];
    }

    public function test_approve_then_give_a_role_that_ends_at_an_event_chosen_as_one_field(): void
    {
        $vid = $this->applicant();
        $this->assertStringContainsString('Approved', $this->post(['do' => 'approve', 'id' => $vid, 'tab' => 'applications']));
        $this->assertSame('active', DB::table('gates_volunteers')->where('id', $vid)->value('status'));
        $ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'c-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertStringContainsString('Every role needs an end date', $this->post(['do' => 'grant', 'id' => $vid, 'capability' => 'vol.door', 'place' => 'event:' . $ev, 'expires_at' => '', 'tab' => 'roster']));
        $this->assertStringContainsString('Given', $this->post(['do' => 'grant', 'id' => $vid, 'capability' => 'vol.door', 'place' => 'event:' . $ev, 'expires_at' => date('Y-m-d', strtotime('+12 days')), 'tab' => 'roster']));
        $uid = (int) DB::table('gates_volunteers')->where('id', $vid)->value('user_id');
        $this->assertTrue(Grants::allows('member', $uid, 'vol.door', 'event', $ev));
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'volunteers.grant')->count(), 'one audit entry per write');
    }

    public function test_a_shift_posted_with_a_place_lands_on_that_place(): void
    {
        $ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'c-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $msg = $this->post(['do' => 'shift', 'tab' => 'shifts', 'title' => 'Door, Gate B', 'scope' => 'event:' . $ev,
            'starts_at' => date('Y-m-d\T15:30', strtotime('+10 days')), 'ends_at' => date('Y-m-d\T21:00', strtotime('+10 days')), 'slots' => '4']);
        $this->assertStringContainsString('Posted', $msg);
        $s = DB::table('gates_vol_shifts')->where('title', 'Door, Gate B')->first();
        $this->assertSame(['event', $ev], [(string) $s->scope_kind, (int) $s->scope_id]);
    }

    public function test_a_training_answer_numbered_from_one_on_the_form_is_the_answer_stored(): void
    {
        $this->post(['do' => 'module', 'tab' => 'training', 'title' => 'Door steward', 'slug' => 'door-steward', 'minutes' => '10',
            'q' => ['A red pass goes to?'], 'opts' => ["Back to the queue\nThe gate lead\nThe car park"], 'answer' => ['2']]);
        $qs = json_decode((string) DB::table('gates_vol_modules')->where('slug', 'door-steward')->value('questions'), true);
        $this->assertSame('1', $qs[0]['answer'], 'answer 2 on the form is option index 1: "The gate lead"');
        $this->assertSame('The gate lead', $qs[0]['options'][(int) $qs[0]['answer']]);
    }

    public function test_a_role_that_may_only_look_cannot_change_anything(): void
    {
        $_SESSION['admin_role'] = 'viewer';
        $vid = $this->applicant();
        $this->assertStringContainsString('can look here but not change', $this->post(['do' => 'approve', 'id' => $vid, 'tab' => 'applications']));
        $this->assertSame('applicant', DB::table('gates_volunteers')->where('id', $vid)->value('status'));
    }

    public function test_the_page_renders_every_tab(): void
    {
        $this->applicant();
        foreach (array_keys(VolunteersController::TABS) as $tab) {
            $res = $this->ctrl()->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/volunteers')->withQueryParams(['tab' => $tab]), new Response());
            $this->assertSame(200, $res->getStatusCode(), $tab);
            $this->assertStringContainsString('aria-current="page"', (string) $res->getBody(), $tab);
        }
    }
}
