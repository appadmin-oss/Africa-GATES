<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Services\EventPlan\EventPages;
use AfricaGates\Services\EventPlan\LeadPages;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\Scope;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * ONE EVENT, AS ITS LEAD RUNS IT (consoles v7 §5, `nav-admin.json` scope `event`), plus the
 * shell's Find, Ask and switcher and the finance reminder.
 *
 * Rendered through the real controller and read as HTML, because each promise is about what
 * a lead sees or can do: every page of the handoff's menu answers; a decision is reversed,
 * never edited; a table's seats are a refusal; a reminder is the asker's, once a day, and says
 * whether any mail left; a scanning link is shown once; nothing of another lead's event leaks
 * through Find or the switcher.
 */
final class LeadEventScopeTest extends TestCase
{
    private int $lead;
    private int $other;
    private int $ev;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::resetRules();
        // A lead is a member account (Plan::ACTOR), signed in through the one account sign-in.
        $this->lead = $this->member('Tunde Akin');
        $this->other = $this->member('Femi Ade');
        $this->as($this->lead);
        $this->ev = $this->event('Alimosho ceremony');
        $this->grant($this->ev, $this->lead);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['lead_toast'], $_SESSION['lead_error'], $_SESSION['lead_door_link']);
        parent::tearDown();
    }

    private function admin(string $name, string $role): int
    {
        return (int) DB::table('gates_admins')->insertGetId(['email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'name' => $name, 'role' => $role, 'is_active' => 1, 'password_hash' => 'x', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function as(int $id): void
    {
        $_SESSION['user_id'] = $id;
    }

    private function event(string $title, string $when = '+10 days'): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => $title,
            'event_date' => date('Y-m-d 18:00:00', strtotime($when)), 'venue' => 'Alimosho Civic Centre', 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function grant(int $event, int $lead): void
    {
        $this->assertTrue(Grants::grant('member', $lead, 'event.lead', 'event', $event, date('Y-m-d', strtotime('+40 days')), 1)['ok']);
    }

    private function ctrl(): LeadController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(LeadController::class);
    }

    /** GET an event page; returns [status, location, html]. */
    private function get(string $page = 'today', array $query = [], ?int $ev = null, string $method = 'event'): array
    {
        $args = $method === 'event' ? ['id' => $ev ?? $this->ev, 'page' => $page] : ($page === 'overview' ? [] : ['page' => $page]);
        $res = $this->ctrl()->{$method}((new ServerRequestFactory())->createServerRequest('GET', '/lead')->withQueryParams($query), new Response(), $args);
        return [$res->getStatusCode(), $res->getHeaderLine('Location'), (string) $res->getBody()];
    }

    private function post(array $body): string
    {
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $this->ctrl()->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')->withParsedBody($body + ['back' => '/lead']), new Response());
        return (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '');
    }

    public function test_every_page_and_tab_of_the_handoffs_event_menu_answers_and_draws_no_zero(): void
    {
        $this->post(['do' => 'work_add', 'event' => $this->ev, 'title' => 'Venue floor plan', 'due_at' => date('Y-m-d', strtotime('-2 days'))]);
        foreach (EventPages::NAV as [, $pages]) {
            foreach (array_keys($pages) as $p) {
                foreach (array_keys(EventPages::TABS[$p] ?? ['' => '']) as $tab) {
                    [$code, , $html] = $this->get($p, $tab !== '' ? ['tab' => $tab] : []);
                    $this->assertSame(200, $code, "$p $tab");
                    $this->assertDoesNotMatchRegularExpression('~sh7-badge[^>]*>0<~', $html, "$p $tab");
                    $this->assertStringContainsString('aria-current="page"', $html, "$p $tab");
                }
            }
        }
    }

    public function test_the_first_builds_addresses_forward_to_the_page_and_tab_that_replaced_them(): void
    {
        [$code, $to] = $this->get('work', ['owner' => 'member:' . $this->lead]);
        $this->assertSame([301, '/lead/event/' . $this->ev . '/plan?owner=member%3A' . $this->lead], [$code, $to]);
        [$code, $to] = $this->get('risks');
        $this->assertSame([301, '/lead/event/' . $this->ev . '/plan?tab=risks'], [$code, $to]);
    }

    public function test_a_decision_is_reversed_with_a_reason_and_both_stay_on_the_record(): void
    {
        $this->assertStringContainsString('Recorded', $this->post(['do' => 'decision_add', 'event' => $this->ev, 'title' => 'Doors open at 17:00', 'why' => 'The permit says so']));
        $id = (int) DB::table('gates_event_decisions')->value('id');
        $this->assertStringContainsString('Say why', $this->post(['do' => 'decision_reverse', 'decision' => $id, 'why' => '']));
        $this->assertStringContainsString('Reversed', $this->post(['do' => 'decision_reverse', 'decision' => $id, 'why' => 'Permit amended to 16:30']));
        $this->assertStringContainsString('already reversed', $this->post(['do' => 'decision_reverse', 'decision' => $id, 'why' => 'again']));
        [, , $html] = $this->get('plan', ['tab' => 'decisions']);
        $this->assertStringContainsString('Doors open at 17:00', $html);
        $this->assertStringContainsString('Permit amended to 16:30', $html);
        $this->assertSame(1, DB::table('gates_event_decisions')->count(), 'reversing adds nothing and deletes nothing');
    }

    public function test_a_bare_time_in_the_running_order_is_on_the_night_and_a_cue_that_ran_stays(): void
    {
        $this->post(['do' => 'cue_add', 'event' => $this->ev, 'at' => '18:30', 'minutes' => '10', 'title' => 'Welcome from the MC']);
        $c = DB::table('gates_event_show')->first();
        $this->assertSame(date('Y-m-d', strtotime('+10 days')) . ' 18:30:00', (string) $c->starts_at);
        $this->assertStringContainsString('Give the time', $this->post(['do' => 'cue_add', 'event' => $this->ev, 'at' => 'later', 'title' => 'X']));
        $this->post(['do' => 'cue_done', 'cue' => $c->id]);
        $this->assertStringContainsString('stays in the record', $this->post(['do' => 'cue_remove', 'cue' => $c->id]));
        // On the night, the first cue not done whose time has come is NOW.
        $cues = [(object) ['id' => 1, 'starts_at' => date('Y-m-d H:i:s', time() - 600), 'done_at' => date('Y-m-d H:i:s')],
                 (object) ['id' => 2, 'starts_at' => date('Y-m-d H:i:s', time() - 60), 'done_at' => null],
                 (object) ['id' => 3, 'starts_at' => date('Y-m-d H:i:s', time() + 600), 'done_at' => null]];
        $this->assertSame(2, Scope::nowCue($cues)->id);
    }

    public function test_more_guests_than_seats_is_refused_and_says_by_how_many(): void
    {
        $this->post(['do' => 'table_save', 'event' => $this->ev, 'label' => 'Table 1', 'seats' => '3', 'guests' => 'Ada, Bayo']);
        $t = (int) DB::table('gates_event_tables')->value('id');
        $this->assertStringContainsString('2 more than the 3 seats at Table 1', $this->post(['do' => 'table_save', 'table' => $t, 'guests' => 'Ada, Bayo, Chika, Dayo, Emeka']));
        $this->assertSame("Ada\nBayo", (string) DB::table('gates_event_tables')->where('id', $t)->value('guests'), 'a refusal changes nothing');
    }

    public function test_sponsor_money_counts_as_income_from_agreed(): void
    {
        $this->post(['do' => 'sponsor_add', 'event' => $this->ev, 'name' => 'Lagos Water', 'stage' => 'asked', 'value' => '1,500,000']);
        $this->assertStringNotContainsString('₦1.5m', $this->get('money', ['tab' => 'income'])[2], 'a sponsor being asked is not income');
        $s = (int) DB::table('gates_event_sponsors')->value('id');
        $this->assertStringContainsString('not a stage', $this->post(['do' => 'sponsor_stage', 'sponsor' => $s, 'stage' => 'maybe']));
        $this->post(['do' => 'sponsor_stage', 'sponsor' => $s, 'stage' => 'agreed']);
        $this->assertStringContainsString('₦1.5m', $this->get('money', ['tab' => 'income'])[2]);
    }

    public function test_a_reminder_is_the_askers_once_a_day_and_says_who_was_emailed(): void
    {
        $finance = $this->admin('Sade Bello', 'admin');
        $r = Requests::create('lead', 'spend', 'member', $this->lead, 'Tunde Akin', ['title' => 'MC fee', 'amount' => '450000', 'why' => 'Holds the date'], 'event', $this->ev);
        $sent = [];
        $send = function (string $to, string $subject) use (&$sent): bool { $sent[] = [$to, $subject]; return true; };
        $this->assertStringContainsString('a day after you last asked', Requests::remind($r['id'], 'member', $this->lead, 'Tunde Akin', $send)['error']);
        DB::table('gates_request_events')->where('request_id', $r['id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 25 * 3600)]);
        $this->assertStringContainsString('Only the person who asked', Requests::remind($r['id'], 'member', $this->other, 'Femi Ade', $send)['error']);
        $ok = Requests::remind($r['id'], 'member', $this->lead, 'Tunde Akin', $send);
        $this->assertTrue($ok['ok']);
        $emails = DB::table('gates_admins')->whereIn('id', [$finance])->pluck('email')->all();
        $this->assertContains($emails[0], array_column($sent, 0), 'finance is emailed');
        $this->assertSame('Reminder: MC fee', $sent[0][1]);
        $this->assertSame('remind', DB::table('gates_request_events')->where('request_id', $r['id'])->orderByDesc('id')->value('action'));
        $this->assertFalse(Requests::remind($r['id'], 'member', $this->lead, 'Tunde Akin', $send)['ok'], 'not twice in a day');
        // Through the console, a reminder whose mail could not leave says so.
        DB::table('gates_request_events')->where('request_id', $r['id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 25 * 3600)]);
        $msg = $this->post(['do' => 'request_remind', 'request' => $r['id']]);
        $this->assertMatchesRegularExpression('~(no email could be sent|people were emailed|person was emailed)~', $msg);
    }

    public function test_a_scanning_link_is_shown_once_and_only_a_lead_makes_one(): void
    {
        $day = date('Y-m-d', strtotime('+10 days'));
        $this->assertStringContainsString('Scanning link made', $this->post(['do' => 'door_issue', 'event' => $this->ev, 'label' => 'Main gate', 'opens_at' => $day . 'T15:00:00', 'closes_at' => $day . 'T23:00:00']));
        [, , $html] = $this->get('settings', ['tab' => 'devices']);
        $this->assertMatchesRegularExpression('~/door/[a-f0-9]{64}~', $html, 'the link is shown');
        [, , $again] = $this->get('settings', ['tab' => 'devices']);
        $this->assertDoesNotMatchRegularExpression('~/door/[a-f0-9]{64}~', $again, 'and never again');
        $this->assertStringContainsString('Main gate', $again);
        $theirs = $this->event('Lagos Education Honours Night');
        $this->grant($theirs, $this->other);
        $this->assertStringContainsString('Only a lead', $this->post(['do' => 'door_issue', 'event' => $theirs, 'closes_at' => $day . 'T23:00:00']));
        $pass = (int) DB::table('gates_event_scan_passes')->value('id');
        $this->assertStringContainsString('Only a lead', $this->post(['do' => 'door_revoke', 'event' => $theirs, 'pass' => $pass]));
        $this->assertStringContainsString('Turned off', $this->post(['do' => 'door_revoke', 'event' => $this->ev, 'pass' => $pass]));
    }

    public function test_a_lead_posts_a_shift_at_their_own_event_only(): void
    {
        $day = date('Y-m-d', strtotime('+10 days'));
        $this->assertStringContainsString('Posted', $this->post(['do' => 'shift_add', 'event' => $this->ev, 'title' => 'Door, Gate B', 'starts_at' => $day . 'T15:00:00', 'ends_at' => $day . 'T21:00:00', 'slots' => '4', 'capability' => 'vol.door']));
        $s = DB::table('gates_vol_shifts')->first();
        $this->assertSame(['event', $this->ev, 'vol.door'], [(string) $s->scope_kind, (int) $s->scope_id, (string) $s->capability]);
        $theirs = $this->event('Lagos Education Honours Night');
        $this->assertStringContainsString('Only a lead', $this->post(['do' => 'shift_add', 'event' => $theirs, 'title' => 'X', 'starts_at' => $day . 'T15:00:00', 'ends_at' => $day . 'T21:00:00']));
    }

    public function test_every_change_is_audited_against_the_event_and_listed_on_its_audit_tab(): void
    {
        $this->post(['do' => 'decision_add', 'event' => $this->ev, 'title' => 'Doors open at 17:00']);
        $this->post(['do' => 'check_add', 'event' => $this->ev, 'title' => 'Fire exits clear']);
        $c = (int) DB::table('gates_event_checks')->value('id');
        $this->post(['do' => 'check_done', 'check' => $c]);
        $this->assertSame(3, DB::table('gates_audit_log')->where('target_type', 'event')->where('target_id', $this->ev)->count());
        [, , $html] = $this->get('reports', ['tab' => 'audit']);
        $this->assertStringContainsString('Tunde Akin recorded a decision', $html);
        $this->assertStringContainsString('Tunde Akin signed off a check', $html);
    }

    public function test_find_and_the_switcher_offer_only_the_leads_own_events(): void
    {
        $mine2 = $this->event('Lagos Education Honours Night', '+38 days');
        $this->grant($mine2, $this->lead);
        $theirs = $this->event('Abuja Teachers Gala');
        $this->grant($theirs, $this->other);
        $this->post(['do' => 'work_add', 'event' => $this->ev, 'title' => 'Book the photographer']);
        DB::table('gates_event_work')->insert(['event_id' => $theirs, 'title' => 'Secret gala work', 'status' => 'todo', 'created_at' => date('Y-m-d H:i:s')]);

        $items = LeadPages::findItems(['id' => $this->lead, 'name' => 'Tunde Akin'], Plan::leadEvents($this->lead));
        $titles = array_column($items, 't');
        $this->assertContains('Book the photographer', $titles);
        $this->assertContains('Run of show', $titles);
        $this->assertNotContains('Abuja Teachers Gala', $titles);
        $this->assertNotContains('Secret gala work', $titles);

        [, , $html] = $this->get('overview', ['q' => 'photographer'], null, 'root');
        $this->assertStringContainsString('Book the photographer', $html, 'Find without script is a results page');
        $this->assertStringContainsString('id="sh7FindData"', $html);
        $this->assertStringNotContainsString('data-sh7-ask', $html, 'no Ask: the assistant is the staff console’s, and a lead is not staff');

        [, , $html] = $this->get('night', ['tab' => 'show']);
        $this->assertStringContainsString('sh7-switch', $html, 'two events: the identity block switches');
        $this->assertStringContainsString('/lead/event/' . $mine2 . '/night', $html, 'switching keeps the page');
        $this->assertStringNotContainsString('Abuja Teachers Gala', $html);
        $this->assertStringNotContainsString('Ask about', $html);
    }
}
