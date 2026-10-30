<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Outreach;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Nominee outreach (owner, 30 Oct 2026): a volunteer reaches the nominees of an award they help
 * on, one at a time, and logs how it went.
 *
 * What these try to break: a nominee's phone reaching a volunteer without the outreach role, or
 * before they agreed to the rules on using it; a volunteer under 18 holding the role; a nominee on
 * another award; a stop that one volunteer logs and another can still call through; a nominee
 * emailed twice in a day by the team; an email that fails and is logged as sent; a follow-up the
 * outcome should have set; and a rail that still carries the retired Contacts page.
 */
final class VolunteerOutreachTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        Outreach::reset();   // per process, and the suite is one
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'op-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'c' . bin2hex(random_bytes(2)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['vol_old']);
        parent::tearDown();
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    private function volunteer(array $caps, string $dob = '1999-05-01', ?int $cycle = null): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chika Nwosu', 'email' => 'chika.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun',
            'dob' => $dob, 'guardian' => 'Mrs Nwosu, 0803 111 2222', 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        Volunteers::approve((int) $r['id'], 1);
        foreach ($caps as $c) $this->assertTrue(Grants::grant('member', $uid, $c, 'cycle', $cycle ?? $this->cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $_SESSION['user_id'] = $uid;
        return Volunteers::of($uid);
    }

    private function nominee(string $name, string $phone = '+2348031112233', string $email = 'halima@school.ng', ?int $cat = null): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat ?? $this->cat, 'name' => $name, 'status' => 'approved', 'nominated_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_nominee_details')->insert(['nominee_id' => $id, 'phones' => json_encode([$phone]), 'emails' => json_encode([$email]),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    private function page(string $tab = 'all', array $q = []): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/volunteer')->withQueryParams($q);
        $res = $this->ctrl()->place($req, new Response(), ['kind' => 'cycle', 'id' => (string) $this->cycle, 'page' => 'nominees'] + ($tab !== 'all' ? ['tab' => $tab] : []));
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    public function test_the_role_is_needed_and_the_rules_come_before_any_number(): void
    {
        $n = $this->nominee('Halima Bello');
        $checker = $this->volunteer(['vol.nomination_check']);
        $this->assertSame([], Outreach::awards($checker), 'checking nominations is not reaching nominees');
        $this->assertNull(Outreach::nominee($checker, $n));
        try { $this->page(); $this->fail('a page the role does not carry'); } catch (HttpNotFoundException) {}

        $v = $this->volunteer([Outreach::CAPABILITY]);
        $html = $this->page('all', ['n' => $n]);
        $this->assertStringContainsString('Halima Bello', $html);
        $this->assertStringNotContainsString('8031112233', $html, 'no number before the rules are agreed');
        $this->assertStringNotContainsString('halima@school.ng', $html);
        $this->assertStringContainsString('Never sell, share or post them', $html);
        $this->assertStringContainsString('name="do" value="outreach_agree"', $html);
        $this->assertFalse(Outreach::log($v, $n, ['channel' => 'call', 'outcome' => 'reached'])['ok'], 'nothing is logged before agreeing');

        $this->assertTrue(Outreach::agree($v, $this->cycle));
        $this->assertTrue(Outreach::agreed($v, $this->cycle));
        $this->assertSame(1, DB::table('gates_vol_outreach_terms')->where('volunteer_id', $v->id)->count(), 'the agreement is recorded');
        $html = $this->page('all', ['n' => $n]);
        $this->assertStringContainsString('href="tel:+2348031112233"', $html);
        $this->assertStringContainsString('href="https://wa.me/2348031112233"', $html);
        $this->assertStringContainsString('halima@school.ng', $html);
        $this->assertStringContainsString('No personal use, no selling, no sharing', $html, 'the rule stays on the page once agreed');
    }

    public function test_a_volunteer_under_18_cannot_reach_nominees(): void
    {
        $this->nominee('Halima Bello');
        $v = $this->volunteer([Outreach::CAPABILITY], date('Y-m-d', strtotime('-16 years')));
        $this->assertSame([], Outreach::awards($v));
    }

    public function test_only_nominees_on_their_own_award(): void
    {
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'other-' . bin2hex(random_bytes(3)), 'title' => 'Other Prize', 'is_active' => 1]);
        $cy = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cy, 'slug' => 'o' . bin2hex(random_bytes(2)), 'title' => 'Other', 'sort_order' => 1]);
        $theirs = $this->nominee('Somebody Else', '+2348039998877', 'else@x.ng', $cat);
        $v = $this->volunteer([Outreach::CAPABILITY]);
        Outreach::agree($v, $this->cycle);
        $this->assertNull(Outreach::nominee($v, $theirs));
        $this->assertFalse(Outreach::agree($v, $cy), 'no agreeing to an award you are not on');
        $this->assertFalse(Outreach::log($v, $theirs, ['channel' => 'call', 'outcome' => 'reached'])['ok']);
    }

    public function test_the_outcome_sets_the_follow_up_and_a_stop_holds_for_everyone(): void
    {
        $n = $this->nominee('Halima Bello');
        $a = $this->volunteer([Outreach::CAPABILITY]);
        Outreach::agree($a, $this->cycle);
        $this->assertTrue(Outreach::log($a, $n, ['channel' => 'call', 'outcome' => 'no_answer'])['ok']);
        $this->assertSame(date('Y-m-d', strtotime('+2 days')), DB::table('gates_vol_outreach')->where('nominee_id', $n)->value('follow_up_on'), 'no answer: try again in two days');
        $this->assertFalse(Outreach::log($a, $n, ['channel' => 'call', 'outcome' => 'reached', 'follow_up_on' => date('Y-m-d', strtotime('-1 day'))])['ok'], 'not in the past');

        $b = $this->volunteer([Outreach::CAPABILITY]);
        Outreach::agree($b, $this->cycle);
        $this->assertTrue(Outreach::log($b, $n, ['channel' => 'whatsapp', 'outcome' => 'stop', 'follow_up_on' => date('Y-m-d', strtotime('+5 days'))])['ok']);
        $this->assertNull(DB::table('gates_vol_outreach')->where('outcome', 'stop')->value('follow_up_on'), 'a stop has no next step');

        $seen = Outreach::nominee($a, $n);
        $this->assertTrue($seen->stopped);
        $this->assertSame([], $seen->phones, 'the other volunteer no longer sees a number');
        $this->assertCount(2, $seen->history, 'one history for the whole team');
        $this->assertFalse(Outreach::log($a, $n, ['channel' => 'call', 'outcome' => 'reached'])['ok']);
        $this->assertFalse(Outreach::email($a, $n, ['to' => 'halima@school.ng', 'subject' => 'Hello', 'body' => 'Just checking in.'], null, static fn () => true)['ok']);
        $_SESSION['user_id'] = $a->user_id;
        $this->assertStringNotContainsString('tel:', $this->page('all', ['n' => $n]));
    }

    public function test_one_email_to_one_person_and_one_a_day_from_the_team(): void
    {
        $n = $this->nominee('Halima Bello');
        $a = $this->volunteer([Outreach::CAPABILITY]);
        Outreach::agree($a, $this->cycle);
        $sent = [];
        $send = static function (string $to, string $subject, string $html, string $plain) use (&$sent): bool { $sent[] = [$to, $plain]; return true; };

        $this->assertFalse(Outreach::email($a, $n, ['to' => 'someone@else.ng', 'subject' => 'Hi', 'body' => 'A message for you.'], null, $send)['ok'], 'only their own address');
        $this->assertFalse(Outreach::email($a, $n, ['to' => 'halima@school.ng', 'subject' => 'Hi', 'body' => 'Hi'], null, $send)['ok'], 'a message, not a word');

        // A send that fails gives nothing to the nominee: nothing logged, and the volunteer may try again.
        $this->assertFalse(Outreach::email($a, $n, ['to' => 'halima@school.ng', 'subject' => 'Your profile', 'body' => 'Could you add a photo?'], null, static fn () => false)['ok']);
        $this->assertSame(0, DB::table('gates_vol_outreach')->count());

        $r = Outreach::email($a, $n, ['to' => 'halima@school.ng', 'subject' => 'Your profile', 'body' => 'Could you add a photo?'], null, $send);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Africa GATES volunteer', $sent[0][1], 'signed, so they know who wrote');
        $this->assertStringContainsString('@example.test', $sent[0][1], 'with an address to reply to a person');
        $row = DB::table('gates_vol_outreach')->first();
        $this->assertSame('Could you add a photo?', $row->body, 'the team reads exactly what was sent');
        $this->assertSame(date('Y-m-d', strtotime('+3 days')), $row->follow_up_on);

        $b = $this->volunteer([Outreach::CAPABILITY]);
        Outreach::agree($b, $this->cycle);
        $this->assertFalse(Outreach::email($b, $n, ['to' => 'halima@school.ng', 'subject' => 'Hello', 'body' => 'Another message.'], null, $send)['ok'], 'one a day from the whole team');
        $this->assertCount(1, $sent);
    }

    public function test_follow_ups_due_lead_the_list_and_reach_the_rail_and_today(): void
    {
        $due = $this->nominee('Zainab Musa', '+2348030000001', 'z@x.ng');
        $this->nominee('Ada Obi', '+2348030000002', 'a@x.ng');
        $v = $this->volunteer([Outreach::CAPABILITY]);
        Outreach::agree($v, $this->cycle);
        DB::table('gates_vol_outreach')->insert(['volunteer_id' => $v->id, 'nominee_id' => $due, 'cycle_id' => $this->cycle, 'channel' => 'call',
            'outcome' => 'no_answer', 'follow_up_on' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        Outreach::reset();   // written behind the service's back
        $list = Outreach::nominees($v);
        $this->assertSame('Zainab Musa', $list[0]->name, 'due first, though Ada sorts before her by name');
        $this->assertSame(1, Outreach::dueCount($v));
        $this->assertSame(['n' => 1, 'tone' => 'warn'], Workspace::counts($v, Volunteers::role($v))['@cycle:' . $this->cycle]);
        $html = $this->page('due');
        // Find carries every nominee by name, so the list is read by its own heading and links.
        $this->assertStringContainsString('>1 nominee<', $html);
        $this->assertStringContainsString('?n=' . $due . '">Zainab Musa</a>', $html);
        $this->assertStringNotContainsString('">Ada Obi</a>', $html);
    }

    public function test_contacts_is_retired_from_the_rail_and_forwards(): void
    {
        $v = $this->volunteer([Outreach::CAPABILITY]);
        foreach (['new', 'active', 'lead'] as $role) {
            foreach (Workspace::rootNav($v, $role, 'home', []) as $g) {
                foreach ($g['items'] as $i) $this->assertNotSame('contacts', $i['key'], 'Contacts is award work now, not a page of its own');
            }
        }
        $res = $this->ctrl()->contacts((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/contacts'), new Response());
        $this->assertSame('/volunteer/cycle/' . $this->cycle . '/nominees', $res->getHeaderLine('Location'));
        $this->assertArrayHasKey(Outreach::CAPABILITY, Grants::CAPABILITIES, 'staff can give the role');
    }

    public function test_the_list_is_read_once_a_request_and_a_write_reads_it_again(): void
    {
        $n = $this->nominee('Halima Bello');
        $v = $this->volunteer([Outreach::CAPABILITY]);
        Outreach::agree($v, $this->cycle);
        $db = DB::connection();
        $db->flushQueryLog();
        $db->enableQueryLog();
        Outreach::nominees($v);
        $first = count($db->getQueryLog());
        $this->assertGreaterThan(0, $first, 'the first call reaches the database');
        Outreach::nominees($v, $this->cycle, '', 'due');
        Outreach::dueCount($v);
        Outreach::nominees($v, 0, 'hal');
        $this->assertSame($first, count($db->getQueryLog()), 'the rest are answered from the first');
        Outreach::log($v, $n, ['channel' => 'call', 'outcome' => 'no_answer', 'follow_up_on' => date('Y-m-d')]);
        $this->assertTrue(Outreach::nominees($v)[0]->due, 'a conversation logged is seen at once');
        $db->disableQueryLog();
    }
}
