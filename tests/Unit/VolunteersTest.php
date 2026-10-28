<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Hours;
use AfricaGates\Services\Volunteer\Level;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Shifts;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Volunteers, scoped grants and requests (v7 consoles handoff; CONSOLES-V7-REVIEW.md K1–K3).
 *
 * Each test holds a rule a screen states to a person, so the screen cannot say it while the
 * code does something else:
 *   · every grant ends, expiry is read rather than swept, and a parent covers its children;
 *   · a request's decline and "ask for info" need a note, only the decider decides, and a
 *     decided request cannot be decided again;
 *   · applying checks the age rule, a shift that needs training refuses until it is passed,
 *     a clash is refused, and joining an event shift grants its role until the day after;
 *   · hours from a kept shift are verified at once, hours by hand wait for a person, a decline
 *     needs a reason, and a confirmed hour is never answered twice;
 *   · the level is computed from the record and the settings, never stored.
 */
final class VolunteersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
    }

    private function member(string $name = 'Chika Nwosu'): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function approved(?int $uid = null): object
    {
        $uid ??= $this->member();
        $r = Volunteers::apply($uid, ['area' => 'Ikotun, Alimosho', 'dob' => '1999-05-01', 'interests' => ['drive', 'door'],
            'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
        Volunteers::approve($r['id'], 1);
        return Volunteers::byId($r['id']);
    }

    private function event(): int
    {
        return (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ceremony-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function shift(int $event, string $start, string $end, array $o = []): int
    {
        $r = Shifts::create($o + ['title' => 'Door, Gate B', 'scope_kind' => 'event', 'scope_id' => $event, 'starts_at' => $start,
            'ends_at' => $end, 'slots' => 4, 'location' => 'Alimosho Civic Centre'], 1);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        return $r['id'];
    }

    public function test_every_grant_ends_expiry_is_read_and_a_parent_covers_its_children(): void
    {
        $u = $this->member();
        $this->assertFalse(Grants::grant('member', $u, 'vol.door', 'event', 5, '')['ok'], 'no end date, no grant');
        $this->assertFalse(Grants::grant('member', $u, 'vol.door', 'event', 5, date('Y-m-d', strtotime('-1 day')))['ok'], 'an end already past');

        $g = Grants::grant('member', $u, 'vol.door', 'event', 5, date('Y-m-d H:i:s', strtotime('+2 days')));
        $this->assertTrue(Grants::allows('member', $u, 'vol.door', 'event', 5));
        $this->assertFalse(Grants::allows('member', $u, 'vol.door', 'event', 6), 'another event is another scope');
        // Expiry is compared on every read: move the end into the past and it stops, with no sweep.
        DB::table('gates_grants')->where('id', $g['id'])->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);
        $this->assertFalse(Grants::allows('member', $u, 'vol.door', 'event', 5));

        $p = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Teachers Prize', 'slug' => 'tp-' . bin2hex(random_bytes(2)), 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $c = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations', 'created_at' => date('Y-m-d H:i:s')]);
        $m = Grants::grant('member', $u, 'award.moderate', 'programme', $p, date('Y-m-d', strtotime('+30 days')));
        $this->assertTrue(Grants::allows('member', $u, 'award.moderate', 'cycle', $c), 'the programme covers its edition');
        Grants::revoke($m['id'], 1);
        $this->assertFalse(Grants::allows('member', $u, 'award.moderate', 'cycle', $c), 'revoked is revoked');
        $this->assertSame(1, DB::table('gates_grants')->where('id', $m['id'])->whereNotNull('revoked_at')->count(), 'a stamp, never a delete');
    }

    public function test_requests_need_a_note_to_decline_and_only_the_decider_decides(): void
    {
        $vol = $this->member('Bayo Adewale');
        $lead = $this->member('Kemi Bello');
        $ev = $this->event();
        Grants::grant('member', $lead, 'vol.team_lead', 'event', $ev, date('Y-m-d', strtotime('+30 days')));

        $this->assertFalse(Requests::create('volunteer', 'swap', 'member', $vol, 'Bayo', ['title' => 'Swap Sat'], 'event', $ev)['ok'], 'why is required');
        $r = Requests::create('volunteer', 'swap', 'member', $vol, 'Bayo', ['title' => 'Swap Sat 31 Oct for Wed 4 Nov', 'why' => 'Family event'], 'event', $ev);
        $this->assertTrue($r['ok']);
        $this->assertSame('your lead, Kemi Bello', $r['decider'], 'routed to the lead who holds the grant, never a typed name');

        $this->assertFalse(Requests::act($r['id'], 'approve', 'member', $vol, 'Bayo')['ok'], 'the requester cannot decide');
        $no = Requests::act($r['id'], 'decline', 'member', $lead, 'Kemi', '');
        $this->assertFalse($no['ok']);
        $this->assertStringContainsString('Say why', $no['error']);
        $this->assertTrue(Requests::act($r['id'], 'ask_info', 'member', $lead, 'Kemi', 'Which shift can you take instead?')['ok']);
        $this->assertTrue(Requests::act($r['id'], 'add_info', 'member', $vol, 'Bayo', 'Wed 4 Nov at Egbeda')['ok']);
        $this->assertTrue(Requests::act($r['id'], 'approve', 'member', $lead, 'Kemi')['ok']);
        $this->assertFalse(Requests::act($r['id'], 'decline', 'member', $lead, 'Kemi', 'changed my mind')['ok'], 'decided is read-only');
        $this->assertTrue(Requests::undo($r['id'], 'member', $lead, 'Kemi'), 'undo, within its seconds');
        $this->assertSame('waiting', Requests::find($r['id'])->status);
        $this->assertSame(['asked', 'ask_info', 'add_info', 'approve', 'undo'], array_column(Requests::history($r['id']), 'action'), 'append-only');

        $s = Requests::create('volunteer', 'hours', 'member', $vol, 'Bayo', ['title' => 'Sat 17 Oct', 'amount' => '3.5', 'why' => 'Scanner was down']);
        $this->assertFalse(Requests::act($s['id'], 'approve', 'member', $lead, 'Kemi')['ok'], 'staff decide hours, not a lead');
        $this->assertTrue(Requests::act($s['id'], 'approve', 'admin', 1, 'Admin', '', 'admin')['ok']);
    }

    public function test_applying_checks_age_and_training_gates_a_shift(): void
    {
        $u = $this->member();
        $young = Volunteers::apply($u, ['area' => 'Ikeja', 'dob' => date('Y-m-d', strtotime('-15 years')), 'interests' => ['drive'], 'ref_name' => 'A', 'ref_contact' => 'b', 'conduct' => 1]);
        $this->assertFalse($young['ok']);
        $this->assertStringContainsString('16 or older', implode(' ', $young['errors']), 'the age is read from the setting');
        $teen = ['area' => 'Ikeja', 'dob' => date('Y-m-d', strtotime('-17 years')), 'interests' => ['drive'], 'ref_name' => 'A', 'ref_contact' => 'b', 'conduct' => 1];
        $this->assertFalse(Volunteers::apply($u, $teen)['ok'], 'under 18 needs a guardian');
        $this->assertTrue(Volunteers::apply($u, $teen + ['guardian' => 1])['ok']);

        $v = $this->approved();
        $this->assertSame('new', Volunteers::role($v), 'approved with nothing verified yet');
        DB::table('gates_vol_modules')->insert(['slug' => 'door', 'title' => 'Door steward', 'minutes' => 15, 'questions' => json_encode([['q' => 'A red pass goes to?', 'options' => ['The queue', 'The gate lead'], 'answer' => '1']]),
            'pass_mark' => 1, 'unlocks' => 'vol.door', 'status' => 'live', 'created_at' => date('Y-m-d H:i:s')]);
        $ev = $this->event();
        $sh = $this->shift($ev, '+5 days 15:30', '+5 days 21:00', ['module_key' => 'door', 'capability' => 'vol.door']);
        $r = Shifts::join($v, $sh);
        $this->assertFalse($r['ok']);
        $this->assertSame('door', $r['train'] ?? null, 'the refusal names the training, so the screen can say Train first');
        $this->assertFalse(Training::take($v, 'door', [0 => '0'])['passed'], 'a wrong answer does not pass');
        $this->assertTrue(Training::take($v, 'door', [0 => '1'])['passed']);
        $this->assertTrue(Shifts::join($v, $sh)['ok']);
        $this->assertTrue(Grants::allows('member', (int) $v->user_id, 'vol.door', 'event', $ev), 'joining grants the shift\'s role at the event');

        $other = $this->shift($ev, '+5 days 17:00', '+5 days 22:00', ['title' => 'Hall ushers']);
        $c = Shifts::join($v, $other);
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('clashes with Door, Gate B', $c['error']);

        Shifts::drop($v, $sh);
        $this->assertFalse(Grants::allows('member', (int) $v->user_id, 'vol.door', 'event', $ev), 'dropping the last shift ends the role');
    }

    public function test_hours_are_verified_from_evidence_and_wait_for_a_person_by_hand(): void
    {
        $v = $this->approved();
        $ev = $this->event();
        $sh = $this->shift($ev, '+3 days 10:00', '+3 days 14:00', ['title' => 'Voter drive, Ikotun']);
        $this->assertTrue(Shifts::join($v, $sh)['ok']);
        $signup = (int) DB::table('gates_vol_signups')->where('shift_id', $sh)->value('id');
        $this->assertTrue(Shifts::mark($signup, true, 'Kemi Bello'));
        $this->assertFalse(Shifts::mark($signup, true, 'Kemi Bello'), 'answered once');
        $this->assertSame(4.0, Hours::summary($v)['verified'], 'a kept shift is verified hours at once, from its own times');
        $this->assertSame('active', Volunteers::role(Volunteers::byId((int) $v->id)));

        $this->assertFalse(Hours::log($v, date('Y-m-d', strtotime('+1 day')), 'Carried chairs', 2)['ok'], 'not in the future');
        $h = Hours::log($v, date('Y-m-d'), 'Carried chairs to the hall', 2.0, 'Kemi');
        $this->assertTrue($h['ok']);
        $this->assertSame(4.0, Hours::summary($v)['verified'], 'by hand counts for nothing until confirmed');
        $this->assertSame(2.0, Hours::summary($v)['review']);
        $this->assertFalse(Hours::confirm($h['id'], false, 'member', 9, 'Kemi', '')['ok'], 'a decline needs a reason');
        $this->assertTrue(Hours::confirm($h['id'], true, 'member', 9, 'Kemi')['ok']);
        $this->assertFalse(Hours::confirm($h['id'], false, 'member', 9, 'Kemi', 'oops')['ok'], 'a confirmed hour is never answered twice');
        $this->assertSame(6.0, Hours::summary($v)['verified']);
    }

    public function test_the_level_is_computed_from_the_record_and_the_settings(): void
    {
        $v = $this->approved();
        $this->assertSame(1, Level::of($v)['level']);
        $ev = $this->event();
        foreach ([1, 2, 3] as $i) {
            $sh = $this->shift($ev, '+' . (10 + $i) . ' days 09:00', '+' . (10 + $i) . ' days 13:00', ['title' => 'Drive ' . $i]);
            Shifts::join($v, $sh);
            Shifts::mark((int) DB::table('gates_vol_signups')->where('shift_id', $sh)->value('id'), true, 'Lead');
        }
        $l = Level::of($v);
        $this->assertSame(12.0, $l['hours']);
        $this->assertSame(2, $l['level'], '10 hours and 3 shifts kept, by default');
        $this->assertSame(100, $l['reliability']);
        $this->assertContains('Team lead training', $l['next']['needs']);

        DB::table('gates_settings')->insert(['key_name' => 'vol_level2_hours', 'value' => '20']);
        Rules::reset();
        $this->assertSame(1, Level::of($v)['level'], 'the threshold is a setting, read on every computation');
        $this->assertStringContainsString('8h verified', implode(' ', Level::of($v)['next']['needs']));
    }
}
