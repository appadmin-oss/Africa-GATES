<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Level;
use AfricaGates\Services\Volunteer\Outreach;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Faults found by walking the volunteer workspace and the events console as each role would
 * (2 Nov 2026), one test each. What they hold:
 *   · a team lead's contact report links a nominee only where the lead can open that page —
 *     without the outreach role every row of "Latest contacts" answered 404;
 *   · an applicant is not offered a break they cannot take, and is refused in words that say why
 *     ("Pick 2, 4 or 8 weeks" after pressing 2 weeks), and stepping back reads as withdrawing;
 *   · "To Level 2" says what is still needed — "10h verified" read as a fact about somebody with none;
 *   · an event's stand page draws no strip of zeros before anybody has applied.
 */
final class VolunteersEventsAuditTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        Outreach::reset();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'au-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'c' . bin2hex(random_bytes(2)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['vol_old'], $_SESSION['admin_id'], $_SESSION['admin_role']);
        parent::tearDown();
    }

    private function ctrl(): VolunteerController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    /** A volunteer, approved unless told otherwise, holding these grants on the award, signed in. */
    private function volunteer(array $caps, bool $approve = true): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chika Nwosu', 'email' => 'chika.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun',
            'dob' => '1999-05-01', 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        if ($approve) Volunteers::approve((int) $r['id'], 1);
        foreach ($caps as $c) $this->assertTrue(Grants::grant('member', $uid, $c, 'cycle', $this->cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $_SESSION['user_id'] = $uid;
        return Volunteers::of($uid);
    }

    private function me(string $page): string
    {
        $res = $this->ctrl()->me((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/me/' . $page), new Response(), ['page' => $page]);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    private function page(string $page, string $tab = ''): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/volunteer/' . $page . ($tab !== '' ? '/' . $tab : ''));
        $res = $this->ctrl()->root($req, new Response(), ['page' => $page] + ($tab !== '' ? ['tab' => $tab] : []));
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    public function test_a_leads_report_links_a_nominee_only_where_the_lead_can_open_the_page(): void
    {
        $nid = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $this->cat, 'name' => 'Halima Bello', 'status' => 'approved', 'nominated_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_nominee_details')->insert(['nominee_id' => $nid, 'phones' => json_encode(['+2348031112233']), 'emails' => json_encode(['halima@school.ng']),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $v = $this->volunteer([Outreach::CAPABILITY]);
        $this->assertTrue(Outreach::agree($v, $this->cycle));
        $this->assertTrue(Outreach::log($v, $nid, ['channel' => 'call', 'outcome' => 'reached'])['ok']);
        Outreach::reset();

        $lead = $this->volunteer(['vol.team_lead']);
        $html = $this->page('team', 'reports');
        $this->assertStringContainsString('Halima Bello', $html, 'the contact is reported');
        $this->assertStringNotContainsString('/nominees?n=' . $nid, $html, 'a lead without the outreach role was linked to a page that answers 404');

        // Given the role, the row links, and the page it links to opens for them.
        $this->assertTrue(Grants::grant('member', (int) $lead->user_id, Outreach::CAPABILITY, 'cycle', $this->cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        Outreach::reset();
        $this->assertStringContainsString('/volunteer/cycle/' . $this->cycle . '/nominees?n=' . $nid, $this->page('team', 'reports'));
        $res = $this->ctrl()->place((new ServerRequestFactory())->createServerRequest('GET', '/volunteer')->withQueryParams(['n' => (string) $nid]), new Response(),
            ['kind' => 'cycle', 'id' => (string) $this->cycle, 'page' => 'nominees']);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_an_applicant_is_not_offered_a_break_and_stepping_back_is_withdrawing(): void
    {
        $v = $this->volunteer([], false);
        $this->assertSame('applicant', $v->status);
        $html = $this->me('leave');
        $this->assertStringNotContainsString('Pause invites for 2, 4 or 8 weeks', $html, 'offered a break Volunteers::pause() refuses an applicant');
        $this->assertStringContainsString('Withdraw your application', $html);
        $this->assertStringNotContainsString('Every role you hold ends now', $html, 'an applicant holds no role');

        // Posted anyway (an old tab), the refusal says why rather than asking for what was sent.
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')->withParsedBody(['do' => 'pause', 'weeks' => '2', 'back' => '/volunteer/me/leave']);
        $this->ctrl()->act($req, new Response());
        $this->assertSame('Only an active volunteer can take a break.', (string) ($_SESSION['vol_error'] ?? ''));

        // An approved volunteer still gets the break and the old wording.
        unset($_SESSION['vol_error']);
        $this->volunteer([]);
        $html = $this->me('leave');
        $this->assertStringContainsString('Pause invites for 2, 4 or 8 weeks', $html);
        $this->assertStringContainsString('Leave for good', $html);
    }

    public function test_the_next_level_says_what_is_still_needed(): void
    {
        $v = $this->volunteer(['vol.team_lead']);
        $lv = Level::of($v);
        $this->assertSame(1, $lv['level']);
        $this->assertSame([Level::h($lv['next']['hours_to']) . ' more verified hours'], $lv['next']['needs']);
        $html = $this->page('home');
        $this->assertStringContainsString('Needs ' . Level::h($lv['next']['hours_to']) . ' more verified hours', $html);
        $this->assertDoesNotMatchRegularExpression('~>\s*' . preg_quote(Level::h($lv['next']['hours_to']), '~') . ' verified\s*<~', $html,
            '"10h verified" under "To Level 2", beside 0 verified hours');
    }

    public function test_an_events_stand_page_draws_no_zeros_before_anybody_applies(): void
    {
        $eid = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'au-' . bin2hex(random_bytes(3)), 'title' => 'Lagos night',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $_SESSION['admin_id'] = (int) DB::table('gates_admins')->insertGetId(['email' => 'ops-' . bin2hex(random_bytes(4)) . '@example.test',
            'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'name' => 'Ops', 'role' => 'superadmin', 'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $res = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin/events/' . $eid . '/stands'));
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('The call', $html, 'the page itself still draws');
        $this->assertStringNotContainsString('class="st-strip"', $html, 'six zero counts on an event nobody has applied to');
    }
}
