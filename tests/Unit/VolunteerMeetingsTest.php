<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Meetings;
use AfricaGates\Services\Volunteer\Reminders;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Meetings between volunteers, ported from the Afrovanguard site (owner, 29 Oct 2026).
 *
 * What these try to break: inviting a stranger, a child invited by another volunteer or a child
 * organising; a meeting seen by somebody not in it; a pasted link to a look-alike host; an
 * invitation sent twice; a call-off that misses somebody, or reaches somebody who said no;
 * notes written by an invitee; a monthly series that drifts off its day; and a form a screen
 * reader cannot work.
 */
final class VolunteerMeetingsTest extends TestCase
{
    private int $cycle = 0;
    private array $mail = [];

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        $this->mail = [];
        Meetings::$makeLink = null;
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'tp-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'voting']);
    }

    protected function tearDown(): void
    {
        Meetings::$makeLink = null;
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['vol_old']);
        parent::tearDown();
    }

    /** An approved volunteer at the test award, who has done some work (so may organise). */
    private function vol(string $name, string $dob = '1995-05-01', array $caps = ['vol.voter_drive'], bool $worked = true, ?int $cycle = null): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(strtok($name, ' ')) . bin2hex(random_bytes(2)) . '@vol-mail.ng',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => $dob, 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1', 'guardian' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        Volunteers::approve((int) $r['id'], 1);
        foreach ($caps as $c) Grants::grant('member', $uid, $c, 'cycle', $cycle ?? $this->cycle, date('Y-m-d', strtotime('+20 days')), 1);
        if ($worked) DB::table('gates_vol_hours')->insert(['volunteer_id' => $r['id'], 'source' => 'manual', 'worked_on' => date('Y-m-d', strtotime('-3 days')),
            'what' => 'Voter drive', 'hours' => 2, 'status' => 'verified', 'created_at' => date('Y-m-d H:i:s')]);
        return Volunteers::of($uid);
    }

    private function send(): \Closure
    {
        return function (string $to, string $subject, string $html, string $plain): bool { $this->mail[] = [$to, $subject, $plain]; return true; };
    }

    private function form(array $people, array $f = []): array
    {
        return $f + ['title' => 'Drive planning', 'date' => date('Y-m-d', strtotime('+3 days')), 'time' => '18:00', 'minutes' => '30', 'repeats' => 'once',
                     'mode' => 'online', 'link' => 'https://meet.google.com/abc-defg-hij', 'agenda' => "Where we go on Saturday\nWho brings flyers",
                     'people' => array_map(static fn ($p) => (string) $p->id, $people)];
    }

    private function email(object $v): string
    {
        return (string) DB::table('gates_users')->where('id', $v->user_id)->value('email');
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    public function test_only_people_you_volunteer_with_and_never_a_child_unless_a_lead_invites(): void
    {
        $org = $this->vol('Chika Nwosu');
        $mate = $this->vol('Bola Ade');
        $child = $this->vol('Tobi Ade', date('Y-m-d', strtotime('-16 years')));
        $p2 = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'xx-' . bin2hex(random_bytes(3)), 'title' => 'Other Prize', 'is_active' => 1]);
        $stranger = $this->vol('Kemi Obi', '1990-01-01', ['vol.voter_drive'], true, (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p2, 'year' => 2026, 'status' => 'voting']));

        $people = Meetings::people($org);
        $this->assertArrayHasKey((int) $mate->id, $people);
        $this->assertArrayNotHasKey((int) $stranger->id, $people, 'nobody from a place the organiser does not help at');
        $this->assertArrayNotHasKey((int) $child->id, $people, 'a child is not offered to another volunteer');
        $this->assertArrayNotHasKey((int) $org->id, $people);

        $this->assertFalse(Meetings::schedule($org, $this->form([$stranger]), null, $this->send())['ok'], 'a crafted post naming a stranger is refused');
        $this->assertFalse(Meetings::schedule($org, $this->form([$child]), null, $this->send())['ok'], 'and naming a child');
        $this->assertFalse(Meetings::schedule($child, $this->form([$mate]), null, $this->send())['ok'], 'a child never organises');
        $this->assertFalse(Meetings::schedule($this->vol('New Person', '1990-01-01', ['vol.voter_drive'], false), $this->form([$mate]), null, $this->send())['ok'], 'not before any work is done');
        $this->assertSame([], $this->mail);

        $lead = $this->vol('Femi Lead', '1985-01-01', ['vol.voter_drive', 'vol.team_lead']);
        $this->assertArrayHasKey((int) $child->id, Meetings::people($lead), 'a team lead may invite a child on their team');
    }

    public function test_a_meeting_is_seen_only_by_the_people_in_it(): void
    {
        [$org, $a, $b] = [$this->vol('Chika Nwosu'), $this->vol('Bola Ade'), $this->vol('Ada Eze')];
        $r = Meetings::schedule($org, $this->form([$a]), null, $this->send());
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertNotNull(Meetings::get($a, $r['id']));
        $this->assertNull(Meetings::get($b, $r['id']), 'a colleague not invited does not see it');
        $this->assertFalse(Meetings::answer($b, $r['id'], 'yes'));
        $this->assertSame([], Meetings::mine($b));

        $_SESSION['user_id'] = $b->user_id;
        try {
            $this->ctrl()->meeting((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings/' . $r['id']), new Response(), ['id' => (string) $r['id']]);
            $this->fail('an outsider gets not found');
        } catch (HttpNotFoundException) {}
        try {
            $this->ctrl()->meetingIcs((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings/' . $r['id'] . '.ics'), new Response(), ['id' => (string) $r['id']]);
            $this->fail('and no calendar file');
        } catch (HttpNotFoundException) {}

        $this->assertTrue(Meetings::answer($a, $r['id'], 'no'));
        $this->assertFalse(Meetings::answer($org, $r['id'], 'no'), 'the organiser is not an invitee');
        $this->assertFalse(Meetings::notes($org, $r['id'], 'early')['ok'], 'notes open once it has started');
        DB::table('gates_vol_meetings')->where('id', $r['id'])->update(['starts_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $this->assertFalse(Meetings::notes($a, $r['id'], 'mine now')['ok'], 'and only the organiser writes them');
        $this->assertTrue(Meetings::notes($org, $r['id'], 'Saturday at the market. Bola brings flyers.')['ok']);
        $this->assertSame('Saturday at the market. Bola brings flyers.', Meetings::get($a, $r['id'])->notes);
    }

    public function test_each_person_is_invited_once_and_a_call_off_reaches_everybody_who_had_not_said_no(): void
    {
        [$org, $a, $b, $c] = [$this->vol('Chika Nwosu'), $this->vol('Bola Ade'), $this->vol('Ada Eze'), $this->vol('Uche Obi')];
        $r = Meetings::schedule($org, $this->form([$a, $b, $c]), null, $this->send());
        $this->assertSame(3, $r['invited']);
        $this->assertEqualsCanonicalizing([$this->email($a), $this->email($b), $this->email($c)], array_column($this->mail, 0));
        $this->assertStringContainsString('Chika Nwosu has invited you to a meeting: Drive planning.', $this->mail[0][2]);
        $this->assertStringContainsString('/volunteer/meetings/' . $r['id'], $this->mail[0][2]);
        $this->assertStringNotContainsString('meet.google.com', $this->mail[0][2], 'the link is behind sign-in, not in an inbox');

        Meetings::answer($b, $r['id'], 'no');
        $this->mail = [];
        // A call-off that died half way and is pressed again: whoever was already told is not told twice.
        \AfricaGates\Support\BroadcastLog::claim('vol-meet-off:' . $r['id'], $this->email($c));
        $this->assertFalse(Meetings::cancel($a, $r['id'], null, $this->send()), 'only the organiser calls it off');
        $this->assertTrue(Meetings::cancel($org, $r['id'], null, $this->send()));
        $this->assertFalse(Meetings::cancel($org, $r['id'], null, $this->send()), 'once');
        $this->assertSame([$this->email($a)], array_column($this->mail, 0), 'never to somebody who said no, never twice');
        $this->assertSame('cancelled', DB::table('gates_vol_meetings')->where('id', $r['id'])->value('status'), 'kept, never deleted');
        $this->assertFalse(Meetings::answer($a, $r['id'], 'yes'), 'a called-off meeting is not answered');
    }

    public function test_a_pasted_link_must_be_a_meeting_service_and_google_makes_one_otherwise(): void
    {
        [$org, $a] = [$this->vol('Chika Nwosu'), $this->vol('Bola Ade')];
        foreach (['https://meet.google.com.evil.example/x', 'http://meet.google.com/abc', 'https://evil.example/meet.google.com', 'https://user@zoom.us/j/1', 'javascript:alert(1)'] as $bad) {
            $this->assertFalse(Meetings::linkOk($bad), $bad);
            $this->assertFalse(Meetings::schedule($org, $this->form([$a], ['link' => $bad]), null, $this->send())['ok'], $bad);
        }
        foreach (['https://us02web.zoom.us/j/123', 'https://teams.microsoft.com/l/meetup-join/x', 'https://meet.jit.si/AfricaGates'] as $good) $this->assertTrue(Meetings::linkOk($good), $good);

        $asked = [];
        Meetings::$makeLink = function (array $o) use (&$asked): array { $asked = $o; return ['ok' => true, 'meet_url' => 'https://meet.google.com/xyz-abcd-efg', 'event_id' => 'ev1']; };
        $r = Meetings::schedule($org, $this->form([$a], ['link' => '']), null, $this->send());
        $this->assertTrue($r['ok']);
        $this->assertSame([$this->email($a)], $asked['guests'], 'the people invited are the guests, so Meet lets them straight in');
        $this->assertSame('https://meet.google.com/xyz-abcd-efg', DB::table('gates_vol_meetings')->where('id', $r['id'])->value('meet_url'));

        Meetings::$makeLink = static fn (): array => ['ok' => false];
        $no = Meetings::schedule($org, $this->form([$a], ['link' => '', 'title' => 'Second']), null, $this->send());
        $this->assertFalse($no['ok']);
        $this->assertStringContainsString('Paste a meeting link', $no['error']);
        $this->assertTrue(Meetings::schedule($org, $this->form([$a], ['mode' => 'place', 'location' => 'Ikotun market, by the gate', 'link' => '']), null, $this->send())['ok'], 'in person needs no link');
    }

    public function test_a_series_keeps_its_day_and_each_one_is_reminded_once(): void
    {
        [$org, $a] = [$this->vol('Chika Nwosu'), $this->vol('Bola Ade')];
        $r = Meetings::schedule($org, $this->form([$a], ['date' => date('Y', strtotime('+1 year')) . '-01-31', 'repeats' => 'monthly', 'times' => '3']), null, $this->send());
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $y = date('Y', strtotime('+1 year'));
        $feb = date('L', strtotime($y . '-01-01')) ? '29' : '28';
        $this->assertSame([$y . '-01-31 18:00:00', $y . '-02-' . $feb . ' 18:00:00', $y . '-03-31 18:00:00'], Meetings::get($org, $r['id'])->dates, 'the 31st, or the month’s last day');

        $w = Meetings::schedule($org, $this->form([$a], ['title' => 'Weekly catch-up', 'repeats' => 'weekly', 'times' => '4']), null, $this->send());
        // The first one is over; the second is tomorrow.
        DB::table('gates_vol_meetings')->where('id', $w['id'])->update(['starts_at' => date('Y-m-d H:i:00', strtotime('+20 hours -1 week'))]);
        $keys = array_column(array_filter(Reminders::due(), static fn ($d) => str_starts_with($d[0], 'vol-meet:' . $w['id'] . ':')), 0);
        $this->assertCount(2, $keys, 'the organiser and the one invited');
        $this->assertSame(['vol-meet:' . $w['id'] . ':1'], array_values(array_unique($keys)), 'keyed to the occurrence, so next week is reminded again');
        Meetings::answer($a, $w['id'], 'no');
        $this->assertCount(1, array_filter(Reminders::due(), static fn ($d) => str_starts_with($d[0], 'vol-meet:' . $w['id'] . ':')), 'not to somebody who said no');

        $_SESSION['user_id'] = $a->user_id;
        $ics = str_replace("\r\n ", '', (string) $this->ctrl()->meetingIcs((new ServerRequestFactory())->createServerRequest('GET', '/x'), new Response(), ['id' => (string) $r['id']])->getBody());
        $this->assertStringContainsString('RRULE:FREQ=MONTHLY;COUNT=3', $ics);
        $this->assertStringContainsString('Where we go on Saturday\nWho brings flyers', $ics);
    }

    public function test_the_pages_can_be_worked_by_a_screen_reader(): void
    {
        [$org, $a] = [$this->vol('Chika Nwosu'), $this->vol('Bola Ade')];
        $_SESSION['user_id'] = $org->user_id;
        $form = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings/new'), new Response(), ['page' => 'meetings', 'tab' => 'new'])->getBody();
        $this->assertMatchesRegularExpression('~<fieldset class="sh7-field sh7-field--choice"><legend class="sh7-field__label">Invite</legend>\s*<label class="sh7-choice sh7-choice--check"><input type="checkbox" name="people\[\]" value="' . $a->id . '"> Bola Ade · Teachers Prize~', $form, 'a named group of labelled checkboxes');
        $this->assertMatchesRegularExpression('~<label class="sh7-field" for="f_date"><span class="sh7-field__label">Date</span>\s*<input class="sh7-input" id="f_date" type="date"~', $form);

        // A refusal keeps what was typed, and the people chosen.
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')->withParsedBody($this->form([$a], ['do' => 'meet', 'date' => 'soon', 'back' => '/volunteer/meetings/new']));
        $this->ctrl()->act($req, new Response());
        $form = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings/new'), new Response(), ['page' => 'meetings', 'tab' => 'new'])->getBody();
        $this->assertStringContainsString('value="Drive planning"', $form);
        $this->assertStringContainsString('value="' . $a->id . '" checked', $form);

        $id = Meetings::schedule($org, $this->form([$a]), null, $this->send())['id'];
        $_SESSION['user_id'] = $a->user_id;
        $page = (string) $this->ctrl()->meeting((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings/' . $id), new Response(), ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer">Join the meeting<span class="sh7-sr"> (opens in a new tab)</span>', $page, 'a new tab is said, not sprung');
        $this->assertStringContainsString('I’m going', $page);
        $this->assertStringContainsString('Bola Ade (you)', $page);
        $list = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/meetings'), new Response(), ['page' => 'meetings'])->getBody();
        $this->assertStringContainsString('Can you come?', $list);
        $this->assertMatchesRegularExpression('~>Meetings</span><span class="sh7-badge sh7-badge--warn" aria-hidden="true">1</span><span class="sh7-sr">, 1 waiting</span>~', $list, 'the rail counts the invitation waiting for an answer');
    }
}
