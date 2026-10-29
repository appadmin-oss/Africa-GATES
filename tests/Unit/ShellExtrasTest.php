<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\EventPlan\Outreach;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\Scope;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Shell\Find;
use AfricaGates\Services\Shell\Updates;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The four pieces that finished both homes of the v7 shell: the volunteer workspace's Find and
 * switcher, the bell's grouped Updates, seating by record, and a lead's letters and big sends.
 * Each case asserts the promise a person relies on, not the markup.
 */
final class ShellExtrasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        Plan::resetRules();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error']);
        parent::tearDown();
    }

    private function event(string $title, string $when = '+10 days'): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => $title,
            'event_date' => date('Y-m-d 18:00:00', strtotime($when)), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function volunteer(string $name): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        return Volunteers::of($uid);
    }

    /** An event lead: a member account (Plan::ACTOR), never a staff admin. */
    private function admin(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['email' => strtolower(str_replace(' ', '.', $name)) . bin2hex(random_bytes(2)) . '@example.test',
            'name' => $name, 'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function lead(int $event, int $admin): void
    {
        Grants::grant('member', $admin, 'event.lead', 'event', $event, date('Y-m-d', strtotime('+40 days')), 1);
    }

    // ── the volunteer workspace's Find and switcher ──────────────────────────────────────

    public function test_a_volunteers_find_offers_their_places_and_pages_and_nobody_elses(): void
    {
        $v = $this->volunteer('Chika Nwosu');
        $mine = $this->event('Alimosho ceremony');
        $other = $this->event('Abuja Teachers Gala');
        Grants::grant('member', (int) $v->user_id, 'vol.door', 'event', $mine, date('Y-m-d', strtotime('+12 days')), 1);
        $items = Workspace::findItems($v, 'active');
        $titles = array_column($items, 't');
        $this->assertContains('Alimosho ceremony', $titles);
        $this->assertContains('Briefing', $titles, 'a place’s own pages are findable');
        $this->assertContains('Log hours', $titles);
        $this->assertNotContains('Abuja Teachers Gala', $titles);
        $this->assertNotContains('My team', $titles, 'a page their role does not have is not offered');
        $hits = Find::match($items, 'alimosho briefing');
        $this->assertSame([['Briefing', 'Alimosho ceremony']], array_map(static fn ($h) => [$h['t'], $h['sub']], $hits), 'every word must match');

        $_SESSION['user_id'] = (int) $v->user_id;
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $c = $b->build()->get(VolunteerController::class);
        $res = $c->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer')->withQueryParams(['q' => 'briefing']), new Response(), []);
        $html = (string) $res->getBody();
        $this->assertStringContainsString('1 match for “briefing”', $html, 'Find without script is a results page');
        $this->assertStringNotContainsString('data-sh7-ask', $html, 'a volunteer is never offered the console’s assistant');
    }

    public function test_the_volunteer_switcher_keeps_the_page_when_the_other_place_has_it(): void
    {
        $v = $this->volunteer('Chika Nwosu');
        $a = $this->event('Alimosho ceremony');
        $b = $this->event('Lagos Education Honours Night', '+30 days');
        foreach ([$a, $b] as $e) Grants::grant('member', (int) $v->user_id, 'vol.door', 'event', $e, date('Y-m-d', strtotime('+40 days')), 1);
        $s = Workspace::siblings($v, 'event', $a, 'brief');
        $hrefs = array_column($s, 'href', 'name');
        $this->assertSame('/volunteer/event/' . $b . '/brief', $hrefs['Lagos Education Honours Night']);
        $this->assertTrue($s[array_search('Alimosho ceremony', array_column($s, 'name'), true)]['current']);
    }

    // ── the bell ─────────────────────────────────────────────────────────────────────────

    public function test_the_leads_bell_puts_what_affects_the_night_first_and_counts_only_what_needs_them(): void
    {
        $ev = $this->event('Alimosho ceremony');
        $lead = $this->admin('Tunde Akin');
        $this->lead($ev, $lead);
        $events = Plan::leadEvents($lead);
        $this->assertSame(['n' => 0, 'groups' => []], Updates::forLead(['id' => $lead], $events), 'nothing to say draws nothing');
        Plan::addWork($ev, ['title' => 'Venue floor plan', 'due_at' => date('Y-m-d', strtotime('-3 days')), 'blocks_night' => '1', 'blocks_note' => 'the permit'], $lead);
        Requests::create('lead', 'spend', 'member', $lead, 'Tunde Akin', ['title' => 'MC fee', 'why' => 'Holds the date'], 'event', $ev);
        $u = Updates::forLead(['id' => $lead], $events);
        $this->assertSame('May affect the night', $u['groups'][0]['h']);
        $this->assertSame('Venue floor plan is late', $u['groups'][0]['items'][0]['t']);
        $this->assertSame('Waiting on others', $u['groups'][1]['h']);
        $this->assertSame(1, $u['n'], 'a request waiting on finance is shown, not counted');
        Plan::setStatus((int) DB::table('gates_event_work')->value('id'), 'done', $lead);
        $this->assertSame(0, Updates::forLead(['id' => $lead], $events)['n'], 'the update goes when its cause does');
    }

    public function test_a_volunteers_bell_says_a_lead_asked_them_something(): void
    {
        $v = $this->volunteer('Bayo Adewale');
        $r = Requests::create('volunteer', 'off', 'member', (int) $v->user_id, 'Bayo', ['title' => 'Away 14 to 21 Nov', 'why' => 'Exams']);
        $this->assertSame(0, Updates::forVolunteer($v)['n']);
        DB::table('gates_requests')->where('id', $r['id'])->update(['status' => 'needs_info']);
        $u = Updates::forVolunteer($v);
        $this->assertSame(1, $u['n']);
        $this->assertStringContainsString('asked about “Away 14 to 21 Nov”', $u['groups'][0]['items'][0]['t']);
    }

    // ── seating by record ────────────────────────────────────────────────────────────────

    public function test_a_guest_sits_at_one_table_a_full_table_refuses_and_a_stranger_cannot_be_seated(): void
    {
        $ev = $this->event('Alimosho ceremony');
        $other = $this->event('Abuja Teachers Gala');
        $lead = $this->admin('Tunde Akin');
        $this->lead($ev, $lead);
        $reg = static fn (int $e, string $n) => (int) DB::table('gates_event_registrations')->insertGetId(['event_id' => $e, 'name' => $n,
            'email' => bin2hex(random_bytes(4)) . '@example.test', 'status' => 'confirmed', 'quantity' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $ada = $reg($ev, 'Ada Kalu');
        $bayo = $reg($ev, 'Bayo Adewale');
        $stranger = $reg($other, 'Not Ours');
        $t1 = Scope::saveTable($ev, ['label' => 'Table 1', 'seats' => 2, 'guests' => 'MC plus one'], $lead)['id'];
        $t2 = Scope::saveTable($ev, ['label' => 'Table 2', 'seats' => 4], $lead)['id'];
        $this->assertTrue(Scope::seat($t1, 'ticket', $ada, $lead)['ok']);
        $this->assertStringContainsString('Table 1 is full', Scope::seat($t1, 'ticket', $bayo, $lead)['error'], 'a typed name and a record share the seats');
        $this->assertTrue(Scope::seat($t2, 'ticket', $ada, $lead)['ok']);
        $this->assertSame(1, (int) DB::table('gates_event_seats')->where('ref_id', $ada)->count(), 'seating again is a move, not a second seat');
        $this->assertStringContainsString('not on this event’s list', Scope::seat($t2, 'ticket', $stranger, $lead)['error']);
        $names = array_column(Scope::unseated($ev)['list'], 'name');
        $this->assertSame(['Bayo Adewale'], $names);
    }

    // ── letters and big sends ────────────────────────────────────────────────────────────

    public function test_a_big_send_needs_approval_and_nobody_is_mailed_twice(): void
    {
        $ev = $this->event('Alimosho ceremony');
        $lead = $this->admin('Tunde Akin');
        $this->lead($ev, $lead);
        foreach (['ada', 'bayo', 'ada'] as $n) DB::table('gates_event_registrations')->insert(['event_id' => $ev, 'name' => $n, 'email' => $n . '@example.org',
            'status' => 'confirmed', 'quantity' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Requests::create('lead', 'message', 'member', $lead, 'Tunde Akin', ['title' => 'Venue reminder to ticket holders', 'why' => 'Gate B is closed'], 'event', $ev);
        $sent = [];
        $send = function (string $to) use (&$sent): bool { $sent[] = $to; return true; };
        $this->assertStringContainsString('approved first', Outreach::sendMessage($r['id'], 'tickets', 'Gate B', 'Use Gate A', $lead, 'Tunde Akin', $send)['error']);
        DB::table('gates_requests')->where('id', $r['id'])->update(['status' => 'approved']);
        $other = $this->admin('Femi Ade');
        $this->assertStringContainsString('Only a lead', Outreach::sendMessage($r['id'], 'tickets', 'Gate B', 'Use Gate A', $other, 'Femi Ade', $send)['error']);
        $res = Outreach::sendMessage($r['id'], 'tickets', 'Gate B', 'Use Gate A', $lead, 'Tunde Akin', $send);
        $this->assertSame([2, 0], [$res['sent'], $res['left']], 'two distinct addresses, once each');
        $again = Outreach::sendMessage($r['id'], 'tickets', 'Gate B', 'Use Gate A', $lead, 'Tunde Akin', $send);
        $this->assertSame(0, $again['sent'], 'a second press mails nobody again');
        $this->assertCount(2, $sent);
        $this->assertSame('sent', DB::table('gates_request_events')->where('request_id', $r['id'])->orderByDesc('id')->value('action'));
    }

    public function test_only_a_lead_sends_their_events_letters(): void
    {
        $ev = $this->event('Alimosho ceremony');
        $this->assertStringContainsString('Only a lead', Outreach::sendLetters($ev, $this->admin('Femi Ade'))['error']);
    }
}
