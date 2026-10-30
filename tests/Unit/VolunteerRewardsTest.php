<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Grants;
use AfricaGates\Services\PointsService;
use AfricaGates\Services\Volunteer\Rewards;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Volunteer rewards (owner, 30 Oct 2026: "Rewards, not cash"): verified hours earn points into
 * the member's one points balance.
 *
 * What these try to break: an hour in review earning; the same hour earning twice; an hour that
 * stops being verified keeping its points; a second balance beside the member's; and a
 * volunteer spending points on votes in an award they help run.
 */
final class VolunteerRewardsTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'points_enabled'], ['value' => '1']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'points_per_vote'], ['value' => '100']);
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'rw-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-1 day')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+5 days'))]);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'c' . bin2hex(random_bytes(2)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
    }

    private function volunteer(): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chika Nwosu', 'email' => 'chika.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'points' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun',
            'dob' => '1999-05-01', 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok']);
        Volunteers::approve((int) $r['id'], 1);
        return Volunteers::of($uid);
    }

    private function hours(object $v, float $h, string $status): int
    {
        return (int) DB::table('gates_vol_hours')->insertGetId(['volunteer_id' => $v->id, 'hours' => $h, 'status' => $status, 'what' => 'Door at the gala',
            'source' => 'manual', 'worked_on' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function test_only_verified_hours_earn_and_each_once(): void
    {
        $v = $this->volunteer();
        $this->hours($v, 2.5, 'verified');
        $review = $this->hours($v, 4, 'review');
        $this->assertSame(250, Rewards::sync($v), '100 a verified hour; the hour in review earns nothing yet');
        $this->assertSame(0, Rewards::sync($v), 'a second sweep pays nothing');
        $this->assertSame(250, PointsService::balance((int) $v->user_id), 'into the member’s one balance');

        DB::table('gates_vol_hours')->where('id', $review)->update(['status' => 'verified']);
        $this->assertSame(400, Rewards::sync($v));
        $this->assertSame(650, Rewards::earned($v));
    }

    public function test_an_hour_that_stops_being_verified_is_taken_back_once(): void
    {
        $v = $this->volunteer();
        $h = $this->hours($v, 3, 'verified');
        Rewards::sync($v);
        DB::table('gates_vol_hours')->where('id', $h)->update(['status' => 'declined']);
        Rewards::sync($v);
        Rewards::sync($v);
        $this->assertSame(0, PointsService::balance((int) $v->user_id));
        $this->assertSame(0, Rewards::earned($v));
        $this->assertSame(2, DB::table('gates_points_ledger')->where('user_id', $v->user_id)->count(), 'one credit, one taking back');
    }

    public function test_nothing_moves_while_points_are_off_and_it_catches_up_after(): void
    {
        DB::table('gates_settings')->where('key_name', 'points_enabled')->update(['value' => '0']);
        $v = $this->volunteer();
        $this->hours($v, 1, 'verified');
        $this->assertSame(0, Rewards::sync($v));
        DB::table('gates_settings')->where('key_name', 'points_enabled')->update(['value' => '1']);
        $this->assertSame(100, Rewards::sync($v));
    }

    public function test_a_volunteer_cannot_vote_with_points_in_an_award_they_help_run(): void
    {
        $v = $this->volunteer();
        $this->hours($v, 5, 'verified');
        Rewards::sync($v);
        $n = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $this->cat, 'name' => 'Halima Bello', 'status' => 'approved', 'nominated_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue(Grants::grant('member', (int) $v->user_id, 'vol.nominee_outreach', 'cycle', $this->cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $r = PointsService::redeemForVote((int) $v->user_id, $n);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('help run this award', $r['message']);
        $this->assertSame(500, PointsService::balance((int) $v->user_id), 'nothing spent');
        $this->assertTrue(Rewards::helpsRun((int) $v->user_id, $n));
        $this->assertFalse(Rewards::helpsRun(999999, $n), 'a member with no role there is not stopped by this rule');
    }
}
