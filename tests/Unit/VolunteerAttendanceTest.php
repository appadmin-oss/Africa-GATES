<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Admin\Controllers\VolunteersController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\Volunteer\Attendance;
use AfricaGates\Services\Volunteer\Effect;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Hours and effectiveness after shifts (owner, 30 Oct 2026: "the hours should still be calculated.
 * We need to be able to know how effective the volunteers are. Volunteers are also required to
 * create an account first").
 *
 * What these try to break: hours that count on the volunteer's word alone; a check-in by somebody
 * with no role, or a week after the night; a forgotten check-out paid as a day and a half; a lead
 * confirming time at somebody else's event; a reliability figure drawn from one task; and an
 * application reachable without a verified account, or a signed-out visitor bounced with no reason.
 */
final class VolunteerAttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Plan::resetRules();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['lead_toast'], $_SESSION['lead_error'],
              $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_name']);
        parent::tearDown();
    }

    private function member(string $name, int $verified = 1): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => $verified, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function volunteer(string $name = 'Ngozi Okafor'): object
    {
        $uid = $this->member($name);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199',
            'area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        Volunteers::approve((int) $r['id'], 1);
        return Volunteers::byId((int) $r['id']);
    }

    private function event(string $when = '+1 hour', string $title = 'Alimosho ceremony'): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => $title,
            'event_date' => date('Y-m-d H:i:s', strtotime($when)), 'venue' => 'Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function role(object $v, int $event): void
    {
        $this->assertTrue(Grants::grant('member', (int) $v->user_id, 'vol.door', 'event', $event, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
    }

    private function ctrl(string $class): object
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get($class);
    }

    /** Pretend the open visit began `$hours` ago. */
    private function arrivedAgo(object $v, int $event, float $hours): void
    {
        DB::table('gates_vol_attendance')->where('volunteer_id', $v->id)->where('event_id', $event)->whereNull('out_at')
            ->update(['in_at' => date('Y-m-d H:i:s', (int) (time() - $hours * 3600))]);
    }

    public function test_a_volunteers_own_taps_wait_for_a_lead_and_a_leads_tap_counts_at_once(): void
    {
        $v = $this->volunteer();
        $ev = $this->event();
        $this->role($v, $ev);

        $this->assertTrue(Attendance::arrive($v, $ev, Attendance::SELF, 'Ngozi')['ok']);
        $this->assertFalse(Attendance::arrive($v, $ev, Attendance::SELF, 'Ngozi')['ok'], 'one visit open at a time');
        $this->arrivedAgo($v, $ev, 3.1);
        $r = Attendance::leave($v, $ev, Attendance::SELF, 'Ngozi');
        $this->assertSame([true, 3.0, true], [$r['ok'], $r['hours'], $r['review']], 'both taps her own: three hours, in review');
        $h = DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->first();
        $this->assertSame(['scan', 'attend', 'review'], [$h->source, $h->ref_kind, $h->status]);

        $this->assertTrue(Attendance::arrive($v, $ev, Attendance::LEAD, 'Tunde')['ok']);
        $this->arrivedAgo($v, $ev, 2);
        $r = Attendance::leave($v, $ev, Attendance::SELF, 'Ngozi');
        $this->assertFalse($r['review'], 'a lead saw her arrive');
        $this->assertSame('verified', DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->orderByDesc('id')->value('status'));
        $this->assertSame('Tunde', DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->orderByDesc('id')->value('witness'));
        $this->assertSame(5.0, Attendance::hoursAt((int) $v->id, $ev));
    }

    public function test_no_role_no_window_and_a_forgotten_check_out_are_all_refused_or_reviewed(): void
    {
        $v = $this->volunteer();
        $ev = $this->event();
        $this->assertFalse(Attendance::arrive($v, $ev, Attendance::LEAD, 'Tunde')['ok'], 'no role here');
        $later = $this->event('+3 days');
        $this->role($v, $later);
        $this->assertStringContainsString('six hours before', (string) Attendance::arrive($v, $later, Attendance::LEAD, 'Tunde')['error']);

        $this->role($v, $ev);
        Attendance::arrive($v, $ev, Attendance::LEAD, 'Tunde');
        $this->arrivedAgo($v, $ev, 30);
        $r = Attendance::leave($v, $ev, Attendance::LEAD, 'Tunde');
        $this->assertSame([16.0, true], [$r['hours'], $r['review']], 'capped at sixteen, and a person looks at it even though a lead tapped');
        $this->assertStringContainsString('capped at 16 hours', (string) DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->value('what'));
    }

    public function test_reliability_is_what_they_finished_or_turned_up_to_and_waits_for_three(): void
    {
        $v = $this->volunteer();
        $task = static fn (string $status, ?string $due, ?string $done = null) => DB::table('gates_vol_task_takes')->insert([
            'task_id' => DB::table('gates_vol_tasks')->insertGetId(['title' => 'T' . bin2hex(random_bytes(2)), 'checked_by' => 'lead', 'due_at' => $due, 'status' => 'open', 'created_at' => date('Y-m-d H:i:s')]),
            'volunteer_id' => $v->id, 'status' => $status, 'taken_at' => date('Y-m-d H:i:s', strtotime('-20 days')), 'done_at' => $done]);
        $task('done', date('Y-m-d H:i:s', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-6 days')));
        $task('dropped', null);
        $e = Effect::of($v);
        $this->assertNull($e['reliability'], 'two commitments are a coin');
        $this->assertSame('Too early to say', $e['word']);

        $task('taken', date('Y-m-d H:i:s', strtotime('-1 day')));   // past due
        $task('taken', date('Y-m-d H:i:s', strtotime('+5 days')));   // still open: not counted against them
        $past = $this->event('-10 days');
        $missed = $this->event('-12 days');
        $this->role($v, $past);
        $this->role($v, $missed);
        DB::table('gates_vol_attendance')->insert(['volunteer_id' => $v->id, 'event_id' => $past, 'in_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'in_by' => 'lead', 'out_at' => date('Y-m-d H:i:s', strtotime('-10 days +3 hours')), 'created_at' => date('Y-m-d H:i:s')]);

        $e = Effect::of($v);
        $this->assertSame([1, 1, 1, 1, 2, 1], [$e['done'], $e['dropped'], $e['late'], $e['open'], $e['roles'], $e['shown']]);
        $this->assertSame(40, $e['reliability'], '(1 finished + 1 turned up) of (1 + 1 + 1 + 2)');
        $this->assertSame('Needs a word', $e['word']);
        $this->assertStringContainsString('checked in at 1 of 2 events', Effect::explain($e));
    }

    public function test_the_lead_checks_people_in_on_their_event_and_confirms_only_their_own(): void
    {
        $leadId = $this->member('Tunde Akin');
        $v = $this->volunteer();
        $ev = $this->event();
        $theirs = $this->event('+2 hours', 'Somebody else’s night');
        $this->role($v, $ev);
        $this->role($v, $theirs);
        $this->assertTrue(Grants::grant('member', $leadId, 'event.lead', 'event', $ev, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $_SESSION['user_id'] = $leadId;
        $_SERVER['REQUEST_URI'] = '/lead';

        $html = (string) $this->ctrl(LeadController::class)->event((new ServerRequestFactory())->createServerRequest('GET', '/lead')->withQueryParams(['tab' => 'volunteers']), new Response(),
            ['id' => (string) $ev, 'page' => 'people'])->getBody();
        $this->assertMatchesRegularExpression('~name="do" value="attend_in".*?name="vol" value="' . $v->id . '"~s', $html);

        $post = function (array $b): string {
            unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
            $this->ctrl(LeadController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')->withParsedBody($b + ['back' => '/lead']), new Response());
            return (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '');
        };
        $this->assertSame('Checked in.', $post(['do' => 'attend_in', 'event' => $ev, 'vol' => $v->id]));
        $this->assertStringContainsString('Only a lead of this event', $post(['do' => 'attend_in', 'event' => $theirs, 'vol' => $v->id]));
        $this->assertSame('lead', DB::table('gates_vol_attendance')->where('event_id', $ev)->value('in_by'));

        // A visit she recorded alone at the OTHER event cannot be confirmed from this one.
        Attendance::arrive($v, $theirs, Attendance::SELF, 'Ngozi');
        Attendance::leave($v, $theirs, Attendance::SELF, 'Ngozi');
        $hour = (int) DB::table('gates_vol_attendance')->where('event_id', $theirs)->value('hours_id');
        $this->assertStringContainsString('Only a lead', $post(['do' => 'attend_confirm', 'event' => $theirs, 'hour' => $hour]));
        $this->assertStringContainsString('not at this event', $post(['do' => 'attend_confirm', 'event' => $ev, 'hour' => $hour]));
        $this->assertSame('review', DB::table('gates_vol_hours')->where('id', $hour)->value('status'));
    }

    public function test_the_volunteer_checks_in_from_their_event_page(): void
    {
        $v = $this->volunteer();
        $ev = $this->event();
        $this->role($v, $ev);
        $_SESSION['user_id'] = (int) $v->user_id;
        $_SERVER['REQUEST_URI'] = '/volunteer';
        $page = fn () => (string) $this->ctrl(VolunteerController::class)->place((new ServerRequestFactory())->createServerRequest('GET', '/volunteer'), new Response(),
            ['kind' => 'event', 'id' => (string) $ev])->getBody();
        $this->assertMatchesRegularExpression('~name="do" value="arrive"~', $page());
        $this->ctrl(VolunteerController::class)->act((new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')
            ->withParsedBody(['do' => 'arrive', 'event' => $ev, 'back' => '/volunteer']), new Response());
        $this->assertStringContainsString('Checked in', (string) ($_SESSION['vol_toast'] ?? ''));
        $html = $page();
        $this->assertMatchesRegularExpression('~name="do" value="leave"~', $html);
        $this->assertStringContainsString('Checked in at', $html);
    }

    public function test_an_account_with_a_verified_email_comes_first(): void
    {
        unset($_SESSION['user_id']);
        $form = fn () => (string) $this->ctrl(VolunteerController::class)->applyForm((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/apply'), new Response())->getBody();
        $html = $form();
        $this->assertStringContainsString('First, make your Africa GATES account', $html, 'told why, not bounced');
        $this->assertStringContainsString('next=%2Fvolunteer%2Fapply', $html, 'and brought back after');
        $this->assertStringNotContainsString('data-ap-form', $html);

        $uid = $this->member('Bisi Lawal', 0);
        $_SESSION['user_id'] = $uid;
        $this->assertStringContainsString('Verify your email first', $form());
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101']);
        $this->assertSame(['Verify your email address first. We sent you a link when you joined.'], $r['errors'], 'the server holds the rule too');
        DB::table('gates_users')->where('id', $uid)->update(['email_verified' => 1]);
        $this->assertStringContainsString('data-ap-form', $form());
    }

    public function test_staff_see_reliability_on_the_roster_and_can_sort_weakest_first(): void
    {
        $a = $this->volunteer('Ada Strong');
        $b = $this->volunteer('Bola Weak');
        foreach ([[$a, 'done'], [$a, 'done'], [$a, 'done'], [$b, 'dropped'], [$b, 'dropped'], [$b, 'done']] as $i => [$v, $st]) {
            DB::table('gates_vol_task_takes')->insert(['task_id' => 900 + $i, 'volunteer_id' => $v->id, 'status' => $st, 'taken_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
            DB::table('gates_vol_tasks')->insert(['id' => 900 + $i, 'title' => 'T' . $i, 'checked_by' => 'lead', 'status' => 'open', 'created_at' => date('Y-m-d H:i:s')]);
        }
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin'; $_SESSION['admin_name'] = 'Staff';
        $html = (string) $this->ctrl(VolunteersController::class)->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/volunteers')
            ->withQueryParams(['tab' => 'roster', 'sort' => 'reliability']), new Response())->getBody();
        $this->assertStringContainsString('Reliable (100%)', $html);
        $this->assertStringContainsString('Needs a word (33%)', $html);
        $this->assertLessThan(strpos($html, 'Ada Strong'), strpos($html, 'Bola Weak'), 'weakest first');
    }
}
