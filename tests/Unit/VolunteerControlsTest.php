<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Attendance;
use AfricaGates\Services\Volunteer\Hours;
use AfricaGates\Services\Volunteer\Tasks;
use AfricaGates\Services\Volunteer\Team;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The volunteer controls the 13 Nov 2026 audit found missing (AUDIT-AND-PLAN §7.4, VO-2 to
 * VO-12). Each test here failed on the code before its fix.
 */
final class VolunteerControlsTest extends TestCase
{
    private function member(string $name = 'Chika Nwosu'): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function applied(?int $uid = null): object
    {
        $uid ??= $this->member();
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01',
            'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        return Volunteers::byId($r['id']);
    }

    private function approved(?int $uid = null): object
    {
        $v = $this->applied($uid);
        Volunteers::approve((int) $v->id, 1);
        return Volunteers::byId((int) $v->id);
    }

    private function event(): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'c-' . bin2hex(random_bytes(3)), 'title' => 'Ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function until(): string
    {
        return date('Y-m-d', strtotime('+20 days'));
    }

    /** VO-2: staff accepted any figure, and the take closed before the hours could fail. */
    public function test_staff_accept_a_take_only_with_hours_a_day_can_hold(): void
    {
        $v = $this->approved();
        $t = Tasks::create(['title' => 'Digitise a booklet', 'checked_by' => 'lead'], 1);
        $this->assertTrue(Tasks::take($v, (int) $t['id'])['ok']);
        $take = (int) DB::table('gates_vol_task_takes')->where('task_id', $t['id'])->value('id');
        $this->assertFalse(Tasks::accept($take, 10000, 1, 'Kemi'), '10,000 hours overflows the column on MySQL');
        $this->assertFalse(Tasks::accept($take, -3, 1, 'Kemi'));
        $this->assertSame('taken', (string) DB::table('gates_vol_task_takes')->where('id', $take)->value('status'), 'a refused accept closed the task anyway');
        $this->assertTrue(Tasks::accept($take, 3, 1, 'Kemi'));
        $this->assertSame(3.0, (float) DB::table('gates_vol_hours')->where('ref_kind', 'take')->where('ref_id', $take)->value('hours'));
    }

    /** VO-5: a break that ran out stayed a break until the volunteer opened their own page. */
    public function test_a_break_that_has_run_out_ends_without_a_visit(): void
    {
        $v = $this->approved();
        DB::table('gates_volunteers')->where('id', $v->id)->update(['status' => 'paused', 'paused_until' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        \AfricaGates\Services\Volunteer\Reminders::sweep(null, static fn () => true, 'https://x.test');
        $this->assertSame('active', (string) DB::table('gates_volunteers')->where('id', $v->id)->value('status'));
    }

    /** VO-6: an approved hours correction wrote no hours. */
    public function test_an_approved_hours_correction_reaches_the_record(): void
    {
        $v = $this->approved();
        $r = Requests::create('volunteer', 'hours', 'member', (int) $v->user_id, 'Chika', ['title' => 'Saturday’s set-up was missed', 'amount' => '3 hours', 'why' => 'I was there 9 to 12']);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $a = Requests::act((int) $r['id'], 'approve', 'admin', 1, 'Staff', '', 'superadmin');
        $this->assertTrue($a['ok'], (string) ($a['error'] ?? ''));
        $row = DB::table('gates_vol_hours')->where('ref_kind', 'request')->where('ref_id', $r['id'])->first();
        $this->assertNotNull($row, 'the approval changed nothing on the record');
        $this->assertSame(['verified', 3.0], [(string) $row->status, (float) $row->hours]);

        $bad = Requests::create('volunteer', 'hours', 'member', (int) $v->user_id, 'Chika', ['title' => 'Some hours', 'amount' => 'a few', 'why' => 'x']);
        $this->assertFalse(Requests::act((int) $bad['id'], 'approve', 'admin', 1, 'Staff', '', 'superadmin')['ok'], 'no figure, nothing to approve');
    }

    /** VO-7: applicants and the departed logged hours; no day total; http accepted. */
    public function test_hours_are_logged_only_by_volunteers_within_a_day(): void
    {
        $applicant = $this->applied();
        $this->assertFalse(Hours::log($applicant, date('Y-m-d', strtotime('-1 day')), 'Help', 2)['ok'], 'an applicant logged hours');
        $v = $this->approved();
        $day = date('Y-m-d', strtotime('-1 day'));
        $this->assertTrue(Hours::log($v, $day, 'Morning', 16)['ok']);
        $this->assertFalse(Hours::log($v, $day, 'Evening', 10)['ok'], 'twenty-six hours in one day');
        $this->assertFalse(Hours::log($v, date('Y-m-d', strtotime('-2 days')), 'Proof', 1, '', 'http://example.test/p')['ok'], 'http, not https');
    }

    /** VO-8: a lead's team took in anybody with any grant at the place. */
    public function test_a_leads_team_is_volunteer_roles_only(): void
    {
        $ev = $this->event();
        $lead = $this->approved($this->member('Ngozi Lead'));
        Grants::grant('member', (int) $lead->user_id, 'vol.team_lead', 'event', $ev, $this->until(), 1);
        $vol = $this->approved($this->member('Ada Door'));
        Grants::grant('member', (int) $vol->user_id, 'vol.door', 'event', $ev, $this->until(), 1);
        $boss = $this->approved($this->member('Event Lead'));
        Grants::grant('member', (int) $boss->user_id, 'event.lead', 'event', $ev, $this->until(), 1);
        $ids = Team::memberIds(Volunteers::byId((int) $lead->id));
        $this->assertContains((int) $vol->id, $ids);
        $this->assertNotContains((int) $boss->id, $ids, 'the event lead was in a volunteer lead’s team');
    }

    /** VO-9: a role that has not started yet already counted. */
    public function test_a_role_that_has_not_started_allows_nothing_yet(): void
    {
        $v = $this->approved();
        $ev = $this->event();
        Grants::grant('member', (int) $v->user_id, 'vol.door', 'event', $ev, $this->until(), 1, '', date('Y-m-d H:i:s', strtotime('+3 days')));
        $this->assertFalse(Attendance::holdsRole($v, $ev), 'a role that starts on Tuesday allowed a check-in today');
    }

    /** VO-12: leaving kept the person's event team roles. */
    public function test_leaving_ends_event_team_roles_too(): void
    {
        $v = $this->approved();
        $ev = $this->event();
        Grants::grant('member', (int) $v->user_id, 'event.role', 'event', $ev, $this->until(), 1);
        $this->assertTrue(Volunteers::leave($v));
        $this->assertFalse(Grants::allows('member', (int) $v->user_id, 'event.role', 'event', $ev));
    }

    /**
     * VO-9's sweep: "is this grant live" is Grants::live and nowhere else. Seven readers had
     * their own copy and none checked starts_at; the next copy fails here by file.
     */
    public function test_no_reader_spells_grant_liveness_by_hand(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $bad = [];
        foreach (array_merge(glob($root . '/Services/Volunteer/*.php'), glob($root . '/Services/EventPlan/*.php')) as $f) {
            if (str_ends_with($f, 'Effect.php')) continue;   // past events: an EXPIRED role is the point there
            $src = (string) file_get_contents($f);
            if (preg_match("~->whereNull\\('(?:g\\.)?revoked_at'\\)\\s*->where\\((?:static fn|'(?:g\\.)?expires_at')~", $src)) $bad[] = basename($f);
        }
        $this->assertSame([], $bad, 'hand-rolled grant liveness; use Grants::live()');
    }
}
