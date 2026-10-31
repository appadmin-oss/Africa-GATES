<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Volunteer\Reminders;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Meetings;
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
 * What these try to break: a reminder sent twice, or sent to somebody on a break; and a page
 * whose counts, buttons or outcome a screen reader cannot hear. (The waitlist, the shift
 * calendar and booked hours left with shifts, owner 29 Oct 2026.)
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
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        return Volunteers::of($uid);
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    public function test_reminders_go_once_to_the_person_and_never_to_somebody_on_a_break(): void
    {
        [$a, $b] = [$this->vol('Ada One'), $this->vol('Bola Two')];
        $m = (int) DB::table('gates_vol_modules')->insertGetId(['slug' => 'door', 'title' => 'Door steward', 'minutes' => 10, 'pass_mark' => 1, 'status' => 'live', 'created_at' => date('Y-m-d H:i:s')]);
        foreach ([$a, $b] as $v) DB::table('gates_vol_passes')->insert(['module_id' => $m, 'volunteer_id' => $v->id, 'score' => 3, 'passed_at' => date('Y-m-d H:i:s', strtotime('-360 days')),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+3 days'))]);
        DB::table('gates_volunteers')->where('id', $b->id)->update(['status' => 'paused', 'paused_until' => date('Y-m-d H:i:s', strtotime('+2 weeks'))]);

        $mail = [];
        $send = function (string $to, string $subject, string $html, string $plain) use (&$mail): bool { $mail[] = [$to, $subject, $plain]; return true; };
        $this->assertSame(1, Reminders::sweep(null, $send, 'https://example.test'));
        $this->assertSame(0, Reminders::sweep(null, $send, 'https://example.test'), 'a second tick sends nothing: the claim came first');
        $this->assertCount(1, $mail);
        $this->assertSame((string) DB::table('gates_users')->where('id', $a->user_id)->value('email'), $mail[0][0]);
        $this->assertStringContainsString('Your Door steward training runs out on', $mail[0][2]);
        $this->assertStringContainsString('https://example.test/volunteer/training/take/door', $mail[0][2]);
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

    public function test_a_screen_reader_hears_the_outcome_the_counts_and_what_each_button_is_for(): void
    {
        [$a, $b] = [$this->vol('Ada One'), $this->vol('Bola Two')];
        DB::table('gates_vol_hours')->insert(['volunteer_id' => $a->id, 'source' => 'manual', 'worked_on' => date('Y-m-d', strtotime('-2 days')), 'what' => 'Flyers',
            'hours' => 2, 'status' => 'verified', 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_volunteers')->where('id', $a->id)->update(['dob' => '1990-01-01']);
        DB::table('gates_volunteers')->where('id', $b->id)->update(['dob' => '1990-01-01']);
        foreach ([$a, $b] as $v) \AfricaGates\Services\Grants::grant('member', (int) $v->user_id, 'vol.door', 'event', $this->event, date('Y-m-d', strtotime('+20 days')), 1);
        Meetings::$makeLink = null;
        $r = Meetings::schedule(Volunteers::byId((int) $a->id), ['title' => 'Door briefing', 'date' => date('Y-m-d', strtotime('+3 days')), 'time' => '18:00', 'minutes' => '30',
            'repeats' => 'once', 'mode' => 'place', 'location' => 'Civic Centre', 'people' => [(string) $b->id]], null, static fn () => true);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        $_SESSION['user_id'] = $b->user_id;
        $_SESSION['vol_toast'] = 'Thanks. They know you’re going.';
        $html = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings'), new Response(), ['page' => 'meetings'])->getBody();
        $this->assertMatchesRegularExpression('~<title>Thanks\. They know you’re going\. · ~u', $html, 'the outcome is first in the title, which is read on load');
        $this->assertStringContainsString('id="sh7Live" role="status"', $html, 'an empty polite region, filled after load');
        $this->assertStringContainsString('id="sh7Alert" role="alert"', $html);
        $this->assertStringContainsString('<span class="sh7-sr">, 1 waiting</span>', $html, 'a count carries hidden words');
        $this->assertDoesNotMatchRegularExpression('~<span[^>]*class="sh7-badge[^"]*"[^>]*aria-label=~', $html, 'aria-label on a span is not read; counts carry hidden words');

        $_SESSION['vol_error'] = 'That meeting can’t be answered now.';
        $html = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings'), new Response(), ['page' => 'meetings'])->getBody();
        $this->assertStringContainsString('<title>Not done: That meeting can’t be answered now. · ', $html);
        $this->assertStringContainsString('data-sh7-stay', $html, 'an error stays until dismissed');
        $this->assertMatchesRegularExpression('~href="/volunteer/meetings/\d+">Door briefing</a>~', $html, 'a row names its record in its link');
    }
}
