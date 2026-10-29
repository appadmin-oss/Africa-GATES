<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Hours;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Tasks;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * THE VOLUNTEER WORKSPACE, DRIVEN THROUGH ITS CONTROLLER (consoles v7 §6; review slice V1).
 *
 * The review's exit test for V1, without shifts (owner, 29 Oct 2026): a person applies, is
 * approved, holds a role at an event, takes a task, staff accept it, and the verified hours and
 * the level move — with no figure on any screen the database did not produce. Each
 * case renders the real page and reads the HTML, because the promise is about what a person
 * SEES, not what a service returns.
 */
final class VolunteerWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['vol_errors'], $_SESSION['vol_apply_old']);
        parent::tearDown();
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    private function member(string $name = 'Chika Nwosu'): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function applied(int $uid): int
    {
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun, Alimosho', 'dob' => '1999-05-01', 'interests' => ['drive', 'door'],
            'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
        return (int) $r['id'];
    }

    private function event(): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ceremony-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** A volunteer role at an event, as the volunteer team gives it. */
    private function role(int $uid, int $event, string $cap = 'vol.door'): void
    {
        $this->assertTrue(Grants::grant('member', $uid, $cap, 'event', $event, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
    }

    private function task(array $o = []): int
    {
        $r = Tasks::create($o + ['title' => 'Digitise the 1st Edition booklet', 'checked_by' => 'lead', 'from_home' => '1', 'est_minutes' => '30'], 1);
        $this->assertTrue($r['ok']);
        return (int) $r['id'];
    }

    /** GET a workspace page as the signed-in member; returns [status, location, html]. */
    private function get(string $method, array $args = [], array $query = []): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/volunteer')->withQueryParams($query);
        $res = $this->ctrl()->{$method}($req, new Response(), $args);
        return [$res->getStatusCode(), $res->getHeaderLine('Location'), (string) $res->getBody()];
    }

    private function post(array $body): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')->withParsedBody($body);
        $res = $this->ctrl()->act($req, new Response());
        return [$res->getStatusCode(), $res->getHeaderLine('Location'), $_SESSION['vol_toast'] ?? null, $_SESSION['vol_error'] ?? null];
    }

    public function test_a_member_who_has_not_applied_is_sent_to_the_application_and_it_states_the_age_rule(): void
    {
        $_SESSION['user_id'] = $this->member();
        [$st, $loc] = $this->get('root');
        $this->assertSame(303, $st);
        $this->assertSame('/volunteer/apply', $loc);
        [, , $html] = $this->get('applyForm');
        $this->assertStringContainsString('Volunteers are ' . Rules::get('vol_min_age') . ' or older', $html, 'the age rule is read from Rules, never typed');
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_an_applicant_sees_their_application_and_only_the_pages_an_applicant_has(): void
    {
        $uid = $this->member();
        $_SESSION['user_id'] = $uid;
        $this->applied($uid);
        [$st, , $html] = $this->get('root');
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Reference from Mrs Adeyemi', $html, 'the step it waits on names the referee');
        $this->assertStringContainsString('Applicant', $html, 'the brand line is the role');
        $this->assertStringNotContainsString('href="/volunteer/tasks"', $html, 'an applicant has no Tasks page on the rail');
        $this->expectException(HttpNotFoundException::class);
        $this->get('root', ['page' => 'tasks']);
    }

    public function test_a_new_volunteer_is_offered_good_first_tasks_and_nothing_is_drawn_as_zero(): void
    {
        $uid = $this->member();
        $_SESSION['user_id'] = $uid;
        Volunteers::approve($this->applied($uid), 1);
        $this->task(['title' => 'Voter drive flyers, Ikotun Market']);
        [, , $html] = $this->get('root');
        $this->assertStringContainsString('Getting started', $html);
        $this->assertStringContainsString('Voter drive flyers, Ikotun Market', $html, 'a real open task is offered');
        $this->assertStringContainsString('Nothing is expected of you until you pick something', $html);
        $this->assertDoesNotMatchRegularExpression('~class="sh7-badge[^"]*"[^>]*>0<~', $html, 'no 0 badge anywhere (§8)');
        $this->assertStringNotContainsString('shift', strtolower($html), 'volunteering has no shifts');
    }

    public function test_the_v1_exit_path_hold_a_role_take_a_task_and_watch_the_hours_and_level_move(): void
    {
        $uid = $this->member();
        $_SESSION['user_id'] = $uid;
        $vid = $this->applied($uid);
        Volunteers::approve($vid, 1);
        $ev = $this->event();
        $this->role($uid, $ev);
        [, , $place] = $this->get('place', ['kind' => 'event', 'id' => (string) $ev]);
        $this->assertStringContainsString('Alimosho ceremony', $place, 'the event is a place they help');
        $this->assertStringContainsString('Door steward ends', $place, 'Your access says when it ends');

        $tid = $this->task();
        [$st, $loc, $ok] = $this->post(['do' => 'take', 'task' => $tid, 'back' => '/volunteer/tasks/library']);
        $this->assertSame([303, '/volunteer/tasks/library'], [$st, $loc]);
        $this->assertStringContainsString('It’s yours', (string) $ok);
        // Staff accept it: the hours are verified at once, and the record moves.
        $take = (int) DB::table('gates_vol_task_takes')->where('task_id', $tid)->value('id');
        $this->assertTrue(Tasks::accept($take, 5.5, 1, 'Kemi Bello'));
        [, , $hours] = $this->get('root', ['page' => 'hours']);
        $this->assertStringContainsString('5.5h', $hours, 'five and a half verified hours, from the accepted task');
        [, , $record] = $this->get('root', ['page' => 'record', 'tab' => 'badges']);
        $this->assertStringContainsString('First task accepted', $record, 'a badge earned from the record');
        $this->assertSame('active', Volunteers::role(Volunteers::byId($vid)), 'verified hours make an active volunteer');
    }

    public function test_a_volunteer_cannot_reach_a_place_they_have_no_role_at(): void
    {
        $uid = $this->member();
        $_SESSION['user_id'] = $uid;
        Volunteers::approve($this->applied($uid), 1);
        $this->expectException(HttpNotFoundException::class);
        $this->get('place', ['kind' => 'event', 'id' => (string) $this->event()]);
    }

    public function test_hours_logged_by_hand_wait_for_a_lead_and_only_their_own_teams_lead_can_answer(): void
    {
        $ev = $this->event();
        $a = $this->member('Bayo Adewale');
        $va = Volunteers::byId($this->applied($a));
        Volunteers::approve((int) $va->id, 1);
        $va = Volunteers::byId((int) $va->id);
        $this->role($a, $ev);

        $_SESSION['user_id'] = $a;
        [, , $ok, $err] = $this->post(['do' => 'log', 'date' => date('Y-m-d', strtotime('-1 day')), 'what' => 'Carried chairs to the hall', 'hours' => '2', 'back' => '/volunteer/hours/log']);
        $this->assertNull($err, (string) $err);
        $this->assertStringContainsString('Sent for confirming', (string) $ok);
        $hour = (int) DB::table('gates_vol_hours')->where('volunteer_id', $va->id)->where('status', 'review')->value('id');

        // A volunteer who leads ELSEWHERE cannot confirm it.
        $other = $this->member('Ada Kalu');
        $vo = Volunteers::byId($this->applied($other));
        Volunteers::approve((int) $vo->id, 1);
        Grants::grant('member', $other, 'vol.team_lead', 'event', $this->event(), date('Y-m-d', strtotime('+30 days')));
        $_SESSION['user_id'] = $other;
        unset($_SESSION['vol_error']);
        [, , , $err] = $this->post(['do' => 'hours_confirm', 'hour' => $hour, 'verdict' => 'yes']);
        $this->assertStringContainsString('not your team’s', (string) $err);

        // The lead where Bayo volunteers can, and a decline needs a reason.
        $lead = $this->member('Kemi Bello');
        $vl = Volunteers::byId($this->applied($lead));
        Volunteers::approve((int) $vl->id, 1);
        Grants::grant('member', $lead, 'vol.team_lead', 'event', $ev, date('Y-m-d', strtotime('+30 days')));
        $_SESSION['user_id'] = $lead;
        [, , $team] = $this->get('root', ['page' => 'team', 'tab' => 'approvals']);
        $this->assertStringContainsString('Bayo Adewale reported 2h', $team);
        unset($_SESSION['vol_error']);
        [, , , $err] = $this->post(['do' => 'hours_confirm', 'hour' => $hour, 'verdict' => 'no', 'reason' => '']);
        $this->assertStringContainsString('Say why', (string) $err, 'a decline with no reason is refused');
        unset($_SESSION['vol_error']);
        [, , $ok] = $this->post(['do' => 'hours_confirm', 'hour' => $hour, 'verdict' => 'yes']);
        $this->assertSame('verified', DB::table('gates_vol_hours')->where('id', $hour)->value('status'));
    }

    public function test_a_request_is_visible_to_its_asker_and_decider_and_nobody_else(): void
    {
        $uid = $this->member();
        $_SESSION['user_id'] = $uid;
        Volunteers::approve($this->applied($uid), 1);
        [, $loc, $ok, $err] = $this->post(['do' => 'request', 'type' => 'off', 'title' => 'Away 14 to 21 Nov', 'why' => 'Exams', 'back' => '/volunteer/requests']);
        $this->assertNull($err, (string) $err);
        $this->assertSame('/volunteer/requests', $loc);
        $rid = (int) DB::table('gates_requests')->where('from_id', $uid)->value('id');
        [, , $html] = $this->get('request', ['id' => (string) $rid]);
        $this->assertStringContainsString('Away 14 to 21 Nov', $html);
        $this->assertStringContainsString('value="withdraw"', $html, 'the asker may withdraw an open request');

        $stranger = $this->member('Hauwa Musa');
        Volunteers::approve($this->applied($stranger), 1);
        $_SESSION['user_id'] = $stranger;
        $this->expectException(HttpNotFoundException::class);
        $this->get('request', ['id' => (string) $rid]);
    }

    public function test_a_post_can_only_send_somebody_back_inside_the_workspace(): void
    {
        $uid = $this->member();
        $_SESSION['user_id'] = $uid;
        Volunteers::approve($this->applied($uid), 1);
        [, $loc] = $this->post(['do' => 'resume', 'back' => 'https://evil.example/']);
        $this->assertSame('/volunteer', $loc);
        [, $loc] = $this->post(['do' => 'resume', 'back' => '//evil.example/volunteer']);
        $this->assertSame('/volunteer', $loc);
    }

    public function test_the_pages_never_send_a_phone_number_or_an_email_to_a_volunteer(): void
    {
        $ev = $this->event();
        $a = $this->member('Bayo Adewale');
        Volunteers::approve($this->applied($a), 1);
        $this->role($a, $ev);
        $b = $this->member('Hauwa Musa');
        Volunteers::approve($this->applied($b), 1);
        $this->role($b, $ev, 'vol.usher');
        $emailA = (string) DB::table('gates_users')->where('id', $a)->value('email');
        $_SESSION['user_id'] = $b;
        [, , $html] = $this->get('place', ['kind' => 'event', 'id' => (string) $ev, 'page' => 'team']);
        $this->assertStringContainsString('Bayo Adewale', $html, 'who’s on with you, by name');
        $this->assertStringNotContainsString($emailA, $html, 'never their email (§7: masking is server-side)');
        $this->assertStringNotContainsString('0803 000 0000', $html, 'never a referee’s or a member’s phone');
    }

    public function test_time_off_goes_to_the_lead_where_they_help_and_that_lead_can_decide_it(): void
    {
        $ev = $this->event();
        $a = $this->member('Bayo Adewale');
        Volunteers::approve($this->applied($a), 1);
        $this->role($a, $ev);
        $lead = $this->member('Kemi Bello');
        Volunteers::approve($this->applied($lead), 1);
        Grants::grant('member', $lead, 'vol.team_lead', 'event', $ev, date('Y-m-d', strtotime('+30 days')));

        $_SESSION['user_id'] = $a;
        [, , $form] = $this->get('root', ['page' => 'requests'], ['new' => 'off']);
        $this->assertStringContainsString('value="event:' . $ev . '"', $form, 'the composer offers the place they help at');
        [, , $ok, $err] = $this->post(['do' => 'request', 'type' => 'off', 'title' => 'Away the week of the ceremony', 'why' => 'Exams', 'place' => 'event:' . $ev]);
        $this->assertNull($err, (string) $err);
        $this->assertStringContainsString('your lead', (string) $ok);
        $rid = (int) DB::table('gates_requests')->where('from_id', $a)->value('id');
        $this->assertSame([$lead, 'member'], [(int) DB::table('gates_requests')->where('id', $rid)->value('decider_id'), (string) DB::table('gates_requests')->where('id', $rid)->value('decider_kind')]);

        // A place the asker has never helped at is not theirs to route to: it goes to the team.
        unset($_SESSION['vol_toast']);
        $this->post(['do' => 'request', 'type' => 'off', 'title' => 'Somewhere else', 'why' => 'x', 'place' => 'event:' . $this->event()]);
        $this->assertSame('role', (string) DB::table('gates_requests')->where('title', 'Somewhere else')->value('decider_kind'));

        $_SESSION['user_id'] = $lead;
        [, , $team] = $this->get('root', ['page' => 'team', 'tab' => 'requests']);
        $this->assertStringContainsString('Away the week of the ceremony', $team);
        unset($_SESSION['vol_error']);
        $this->post(['do' => 'request_act', 'request' => $rid, 'action' => 'decline', 'note' => '']);
        $this->assertStringContainsString('Say why', (string) $_SESSION['vol_error'], 'declining needs a note');
        unset($_SESSION['vol_error']);
        $this->post(['do' => 'request_act', 'request' => $rid, 'action' => 'approve']);
        $this->assertSame('approved', DB::table('gates_requests')->where('id', $rid)->value('status'));
    }
}
