<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Volunteer\Reminders;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Shifts;
use AfricaGates\Services\Volunteer\Team;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The gaps the comparison with Asana, HubSpot, Zoho, Notion and the volunteer tools found
 * (docs/architecture/WORKSPACE-COMPARISON.md §3), and the screen-reader rules the workspace
 * is held to.
 *
 * What these try to break: a waitlist that promotes somebody on a break or out of turn, or
 * that counts leaving it as a late drop; a reminder sent twice, or sent to somebody on a
 * break; a calendar file that carries another volunteer's name; and a page whose counts,
 * buttons or outcome a screen reader cannot hear.
 */
final class VolunteerWorkspaceGapsTest extends TestCase
{
    private int $event = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        $this->event = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error']);
        parent::tearDown();
    }

    private function vol(string $name): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        return Volunteers::of($uid);
    }

    private function shift(int $slots, string $start = '+3 days', array $o = []): int
    {
        $r = Shifts::create($o + ['title' => 'Door, Gate B', 'scope_kind' => 'event', 'scope_id' => $this->event,
            'starts_at' => date('Y-m-d H:00:00', strtotime($start)), 'ends_at' => date('Y-m-d H:00:00', strtotime($start . ' +4 hours')),
            'slots' => $slots, 'location' => 'Civic Centre, Alimosho', 'bring' => 'Water; a pen', 'lead_name' => 'Femi'], 1);
        $this->assertTrue($r['ok']);
        return (int) $r['id'];
    }

    private function signupStatus(object $v, int $shift): ?string
    {
        return DB::table('gates_vol_signups')->where('shift_id', $shift)->where('volunteer_id', $v->id)->value('status');
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    public function test_a_full_shift_keeps_a_line_and_a_drop_moves_on_the_first_who_can_take_it(): void
    {
        $s = $this->shift(1);
        [$on, $first, $second] = [$this->vol('Ada One'), $this->vol('Bola Two'), $this->vol('Chi Three')];
        $this->assertTrue(Shifts::join($on, $s)['ok']);
        $full = Shifts::join($first, $s);
        $this->assertFalse($full['ok']);
        $this->assertTrue($full['full'] ?? false, 'a full shift says the waitlist is open, not just "filled"');
        $this->assertSame(1, Shifts::wait($first, $s)['place']);
        $this->assertSame(2, Shifts::wait($second, $s)['place']);

        // The first in line goes on a break: passed over, and keeps their place.
        DB::table('gates_volunteers')->where('id', $first->id)->update(['status' => 'paused', 'paused_until' => date('Y-m-d H:i:s', strtotime('+2 weeks'))]);
        $this->assertTrue(Shifts::drop($on, $s));
        $this->assertSame('waiting', $this->signupStatus($first, $s), 'somebody on a break is not moved onto a shift');
        $this->assertSame('on', $this->signupStatus($second, $s));
        $this->assertNotNull(DB::table('gates_vol_signups')->where('shift_id', $s)->where('volunteer_id', $second->id)->value('promoted_at'));
        $this->assertSame(1, Shifts::placeInLine($first, $s), 'and they are still first in line');
    }

    public function test_leaving_a_waitlist_is_not_a_late_drop(): void
    {
        $s = $this->shift(1, '+20 hours');
        [$on, $w] = [$this->vol('Ada One'), $this->vol('Bola Two')];
        Shifts::join($on, $s);
        Shifts::wait($w, $s);
        $this->assertTrue(Shifts::unwait($w, $s));
        $this->assertSame('withdrawn', $this->signupStatus($w, $s));
        $this->assertSame(0, DB::table('gates_vol_signups')->where('volunteer_id', $w->id)->where('status', 'dropped')->count(),
            'reliability reads dropped rows; leaving a line is not one');
    }

    public function test_reminders_go_once_to_the_person_and_never_to_somebody_on_a_break(): void
    {
        $s = $this->shift(3, '+20 hours');
        [$a, $b] = [$this->vol('Ada One'), $this->vol('Bola Two')];
        Shifts::join($a, $s);
        Shifts::join($b, $s);
        DB::table('gates_volunteers')->where('id', $b->id)->update(['status' => 'paused', 'paused_until' => date('Y-m-d H:i:s', strtotime('+2 weeks'))]);

        $mail = [];
        $send = function (string $to, string $subject, string $html, string $plain) use (&$mail): bool { $mail[] = [$to, $subject, $plain]; return true; };
        $this->assertSame(1, Reminders::sweep(null, $send, 'https://example.test'));
        $this->assertSame(0, Reminders::sweep(null, $send, 'https://example.test'), 'a second tick sends nothing: the claim came first');
        $this->assertCount(1, $mail);
        $this->assertSame((string) DB::table('gates_users')->where('id', $a->user_id)->value('email'), $mail[0][0]);
        $this->assertStringContainsString('Bring: Water; a pen.', $mail[0][2]);
        $this->assertStringContainsString('Your lead: Femi.', $mail[0][2]);
        $this->assertStringContainsString('https://example.test/volunteer/shifts/' . $s, $mail[0][2]);
    }

    public function test_a_lapsing_pass_is_reminded_unless_a_newer_one_replaced_it(): void
    {
        $v = $this->vol('Ada One');
        $m = (int) DB::table('gates_vol_modules')->insertGetId(['slug' => 'door', 'title' => 'Door steward', 'minutes' => 10, 'pass_mark' => 1, 'status' => 'live', 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_vol_passes')->insert(['module_id' => $m, 'volunteer_id' => $v->id, 'score' => 3, 'passed_at' => date('Y-m-d H:i:s', strtotime('-360 days')),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+3 days'))]);
        $keys = static fn () => array_map(static fn ($d) => explode(':', $d[0])[0], Reminders::due());
        $this->assertContains('vol-train', $keys());
        DB::table('gates_vol_passes')->insert(['module_id' => $m, 'volunteer_id' => $v->id, 'score' => 3, 'passed_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+365 days'))]);
        $this->assertNotContains('vol-train', $keys(), 'renewed: nothing to remind');
    }

    public function test_the_calendar_file_holds_only_the_persons_own_shifts(): void
    {
        $s = $this->shift(3, '+3 days', ['brief' => 'Gate B, by the car park, opens at six']);
        [$a, $b] = [$this->vol('Ada One'), $this->vol('Bola Two')];
        Shifts::join($a, $s);
        Shifts::join($b, $s);
        $_SESSION['user_id'] = $a->user_id;
        $res = $this->ctrl()->ics((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/shifts.ics'), new Response());
        $ics = (string) $res->getBody();
        $this->assertStringStartsWith('text/calendar', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('UID:vol-shift-' . $s . '@', $ics);
        $this->assertMatchesRegularExpression('/DTSTART:\d{8}T\d{6}Z/', $ics, 'times in UTC');
        $this->assertStringContainsString('Gate B\, by the car park\, opens at six', str_replace("\r\n ", '', $ics), 'commas escaped (RFC 5545)');
        $this->assertStringNotContainsString('Bola', $ics, 'no other volunteer is named');
        foreach (explode("\r\n", $ics) as $line) $this->assertLessThanOrEqual(75, strlen($line), 'lines folded at 75 octets');
    }

    public function test_a_leads_team_shows_booked_hours_against_each_persons_own_limit(): void
    {
        $v = $this->vol('Ada One');
        DB::table('gates_volunteers')->where('id', $v->id)->update(['max_hours_week' => 3]);
        $s = $this->shift(2, 'monday this week 10:00');
        DB::table('gates_vol_signups')->insert(['shift_id' => $s, 'volunteer_id' => $v->id, 'status' => 'kept', 'joined_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(4.0, Team::bookedThisWeek(Volunteers::of((int) $v->user_id)));
    }

    public function test_a_screen_reader_hears_the_outcome_the_counts_and_what_each_button_is_for(): void
    {
        $s = $this->shift(1);
        [$a, $b] = [$this->vol('Ada One'), $this->vol('Bola Two')];
        Shifts::join($a, $s);
        $_SESSION['user_id'] = $b->user_id;
        $_SESSION['vol_toast'] = 'You’re on the waitlist, 1st in line.';
        $html = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/shifts/all'), new Response(), ['page' => 'shifts', 'tab' => 'all'])->getBody();

        $this->assertMatchesRegularExpression('~<title>You’re on the waitlist, 1st in line\. · ~u', $html, 'the outcome is first in the title, which is read on load');
        $this->assertStringContainsString('id="sh7Live" role="status"', $html, 'an empty polite region, filled after load');
        $this->assertStringContainsString('id="sh7Alert" role="alert"', $html);
        $this->assertStringContainsString('Full · waitlist open', $html, 'a full shift says so in words');
        $this->assertDoesNotMatchRegularExpression('~<span[^>]*class="sh7-badge[^"]*"[^>]*aria-label=~', $html, 'aria-label on a span is not read; counts carry hidden words');

        Shifts::wait($b, $s);
        $_SESSION['vol_error'] = 'That shift has already started.';
        $html = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/shifts'), new Response(), ['page' => 'shifts'])->getBody();
        $this->assertStringContainsString('<title>Not done: That shift has already started. · ', $html);
        $this->assertStringContainsString('data-sh7-stay', $html, 'an error stays until dismissed');
        $this->assertStringContainsString('Leave the waitlist<span class="sh7-sr">, Door, Gate B</span>', $html, 'a button names what it acts on');
    }
}
