<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnPool as Pool;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\CommunityReturnWithdrawals as Wd;
use AfricaGates\Services\RuleEngine;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The target pool (owner, 30 Oct 2026): one pool per edition, the same vote target for every
 * approved nominee, shared at close BY VOTES between those who reached it, the reserves when
 * nobody did, and a verified account to withdraw.
 *
 * What these try to break: a pool shared equally; a nominee under the target paid; a lone
 * winner given less than all of it; shares that do not add up to the pool; a bonus or a
 * fraud-flagged vote counted; a pool settled twice or while voting is open; money withdrawable
 * before the pool is settled; a refund after settling taken off a winner; and a withdrawal from
 * an account that never verified its address.
 */
final class CommunityReturnPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(), 'voting_close' => Carbon::now()->addDays(5)->toDateTimeString()]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        foreach ([1 => 'Ada', 2 => 'Bola', 3 => 'Chuka'] as $id => $n) {
            DB::table('gates_nominees')->insert(['id' => $id, 'category_id' => 1, 'name' => $n, 'status' => 'approved']);
        }
        DB::table('gates_nominees')->insert(['id' => 4, 'category_id' => 1, 'name' => 'Pending Pat', 'status' => 'pending']);
        $r = Milestone::saveEditionRules(1, ['model' => 'pool', 'full_pct' => 20, 'milestone_naira' => 30000]);
        $this->assertTrue($r['ok'], $r['message']);
    }

    private function votes(int $nominee, int $n, string $type = 'standard', int $fraud = 0): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => 1, 'voter_email_hash' => bin2hex(random_bytes(8)),
            'vote_type' => $type, 'weight' => $n, 'fraud_flag' => $fraud]);
    }

    private function contribution(int $nominee, int $naira): int
    {
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 0, 'votes_used' => 1, 'intent_nominee_id' => $nominee,
            'payment_ref' => 'R' . bin2hex(random_bytes(4)), 'status' => 'confirmed', 'created_at' => Carbon::now()->toDateTimeString()]);
        $this->assertTrue(Ret::accrue($id)['ok']);
        return $id;
    }

    private function close(): void
    {
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results', 'voting_close' => Carbon::now()->subDay()->toDateTimeString()]);
    }

    private function share(int $nominee): int
    {
        return (int) DB::table('gates_community_returns')->where('nominee_id', $nominee)->where('cycle_id', 1)->sum('amount_kobo');
    }

    public function test_the_combined_target_counts_approved_nominees_and_each_only_up_to_the_target(): void
    {
        $this->votes(1, 300);              // a landslide counts only up to the target
        $this->votes(2, 40);
        $this->votes(2, 500, 'bonus');     // a grant is not support gathered
        $this->votes(3, 500, 'standard', 1); // fraud-flagged
        $p = Pool::progress(1);
        $this->assertSame(3, $p['nominees'], 'only approved nominees are counted');
        $this->assertSame(300, $p['combined_target']);
        $this->assertSame(140, $p['combined_votes'], '100 for Ada (capped) + 40 for Bola');
        $this->assertSame(160, $p['gap']);
        $this->assertSame(1, $p['reached']);
    }

    public function test_winners_share_by_votes_and_the_parts_add_up_to_the_pool(): void
    {
        $this->votes(1, 300);
        $this->votes(2, 100, 'paid');      // free and paid both count
        $this->votes(3, 99);               // one short
        $this->contribution(1, 10000);     // 20% → ₦2,000
        $this->contribution(3, 5001);      // 20% → ₦1,000.20
        $pool = Pool::poolKobo(1);
        $this->assertSame(300020, $pool);
        $this->assertFalse(Pool::settle(1, 1)['ok'], 'not while voting is open');
        $this->assertSame(0, Ret::wallet(1)['available_kobo'], 'nothing is available before the pool is settled');

        $this->close();
        $r = Pool::settle(1, 1);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(225015, $this->share(1), 'Ada: 300 of 400 votes');
        $this->assertSame(75005, $this->share(2), 'Bola: 100 of 400, though no contribution was for her');
        $this->assertSame(0, $this->share(3), 'Chuka fed the pool and missed the target by one vote');
        $this->assertSame($pool, $this->share(1) + $this->share(2), 'to the kobo');
        $this->assertSame(0, (int) Pool::settlement(1)->reserves_kobo);
        $this->assertFalse(Pool::settle(1, 1)['ok'], 'settled once');
    }

    public function test_a_lone_winner_takes_all_and_nobody_reaching_it_sends_it_to_the_reserves(): void
    {
        $this->votes(2, 150);
        $this->contribution(1, 10000);
        $this->contribution(3, 10000);
        $this->close();
        $this->assertTrue(Pool::settle(1, 1)['ok']);
        $this->assertSame(400000, $this->share(2), 'the one who reached it gets all of it');
    }

    public function test_nobody_reaching_the_target_sends_the_pool_to_the_reserves(): void
    {
        $this->votes(1, 99);
        $this->contribution(1, 10000);
        $this->close();
        $r = Pool::settle(1, 1);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('reserves', $r['message']);
        $this->assertSame(200000, (int) Pool::settlement(1)->reserves_kobo);
        $this->assertSame(0, $this->share(1), 'no return');
    }

    public function test_a_refund_after_settling_is_not_taken_off_a_winner(): void
    {
        $this->votes(2, 100);
        $don = $this->contribution(1, 10000);
        $this->close();
        Pool::settle(1, 1);
        $this->assertSame('POOL_SETTLED', Ret::reverse($don, 'refund')['code']);
        $this->assertSame(200000, $this->share(2));
    }

    public function test_withdrawing_needs_a_verified_account(): void
    {
        $this->votes(1, 200);
        $this->contribution(1, 10000);
        $this->close();
        Pool::settle(1, 1);
        DB::table('gates_users')->insert(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'email_verified' => 0]);
        DB::table('gates_nominee_claims')->insert(['nominee_id' => 1, 'user_id' => 7, 'status' => 'active', 'active_nominee_id' => 1,
            'claimed_at' => Carbon::now()->subDays(20)->toDateTimeString(), 'activated_at' => Carbon::now()->subDays(20)->toDateTimeString()]);
        (new RuleEngine())->merge('global', null, ['community_return_terms_published' => true, 'community_return_tax_note' => 'Your own tax.',
            'community_return_min_withdrawal_naira' => 100]);
        $bank = ['bank' => 'GTBank', 'account_name' => 'Ada Obi', 'account_number' => '0123456789'];
        $c = Wd::checks(1, 7, true, $bank);
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('Verify your account', $c['reason']);
        DB::table('gates_users')->where('id', 7)->update(['email_verified' => 1]);
        $this->assertTrue(Wd::checks(1, 7, true, $bank)['ok'], Wd::checks(1, 7, true, $bank)['reason']);
    }

    public function test_the_target_is_the_milestone_in_votes_divided_across_the_approved_nominees(): void
    {
        $t = Pool::target(1);
        $this->assertSame(['milestone_naira' => 30000, 'price_naira' => 100, 'votes' => 300, 'nominees' => 3, 'each' => 100], $t);

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'vote_price_naira'], ['value' => '250']);
        $this->assertSame(40, Pool::target(1)['each'], '₦30,000 ÷ ₦250 = 120 votes ÷ 3 nominees');
        DB::table('gates_nominees')->where('id', 4)->update(['status' => 'approved']);
        $this->assertSame(30, Pool::target(1)['each'], 'a fourth approved nominee lowers each target');
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'vote_price_naira'], ['value' => '700']);
        $this->assertSame(11, Pool::target(1)['each'], 'rounded up: ₦30,000 ÷ ₦700 is 43 votes, ÷ 4 is 10.75');

        $this->assertFalse(Milestone::saveEditionRules(1, ['model' => 'pool', 'full_pct' => 20, 'milestone_naira' => 0])['ok'], 'no milestone, no target');
    }

    public function test_no_milestone_means_nobody_has_reached_anything(): void
    {
        DB::table('gates_award_cycles')->insert(['id' => 2, 'programme_id' => 1, 'year' => 1999, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 2, 'cycle_id' => 2, 'slug' => 'c2', 'title' => 'Old']);
        DB::table('gates_nominees')->insert(['id' => 9, 'category_id' => 2, 'name' => 'Zed', 'status' => 'approved']);
        $p = Pool::progress(2);
        $this->assertSame(0, $p['target']);
        $this->assertSame(0, $p['reached'], 'a target of nothing is not set, not reached by everybody');
    }

    public function test_a_settled_target_does_not_move(): void
    {
        $this->votes(1, 120);
        $this->close();
        $this->assertTrue(Pool::settle(1, 1)['ok']);
        DB::table('gates_nominees')->where('id', 4)->update(['status' => 'approved']);   // approved after settling
        $p = Pool::progress(1);
        $this->assertSame(100, $p['target'], 'the target written at settlement, not today’s 75');
        $this->assertSame(1, $p['reached']);
    }
}
