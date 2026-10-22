<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnService as Ret;
use AfricaGates\Services\MemberWallet as W;
use AfricaGates\Services\PaidVoteService;
use AfricaGates\Services\RuleEngine;
use AfricaGates\Services\VoterReach;
use AfricaGates\Services\WalletVotes;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Votes paid from a member's wallet, and the three rules that stop a community return
 * paying for itself (WalletVotes):
 *
 *   1. a wallet vote earns no return,
 *   2. it carries nobody over the qualifying line,
 *   3. it is bought on the card checkout's own terms — price, per-order maximum, window.
 *
 * And the two things money must never do here: leave a pocket that is not the buyer's,
 * or leave one twice.
 */
final class WalletVotesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(5)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'Ada', 'status' => 'approved', 'vote_count' => 0],
            ['id' => 2, 'category_id' => 1, 'name' => 'Bisi', 'status' => 'approved', 'vote_count' => 0],
        ]);
        DB::table('gates_users')->insert(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com', 'points' => 0]);
        DB::table('gates_users')->insert(['id' => 8, 'name' => 'Bola', 'email' => 'bola@example.com', 'points' => 0]);
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);

        // Ada holds nominee 1's claim and ₦1,000 of released return (MemberWalletTest's fixture).
        DB::table('gates_nominee_claims')->insert([
            'nominee_id' => 1, 'user_id' => 7, 'status' => 'active', 'active_nominee_id' => 1,
            'claimed_at' => Carbon::now()->subDays(20)->toDateTimeString(),
            'activated_at' => Carbon::now()->subDays(20)->toDateTimeString(),
        ]);
        $this->assertTrue(Milestone::saveEditionRules(1, ['model' => 'milestone', 'full_pct' => 10, 'locked_pct' => 10,
                                                          'milestone_naira' => 10000])['ok']);
        Ret::accrue((int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com', 'amount_naira' => 10000,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 10, 'intent_nominee_id' => 1,
            'payment_ref' => 'PV1', 'status' => 'confirmed',
            'created_at' => Carbon::now()->subMinute()->toDateTimeString(),
        ]));
        $this->assertTrue(Milestone::confirm(1, 1)['ok']);
        (new RuleEngine())->merge('global', null, [
            'community_return_terms_published' => true,
            'community_return_tax_note' => 'No tax is withheld.',
            'community_return_min_withdrawal_naira' => 100,
        ]);
        $this->assertSame(100000, Ret::wallet(1)['available_kobo'], 'fixture: ₦1,000 available');
    }

    public function test_a_member_pays_for_votes_from_their_return_and_the_balance_falls_by_the_card_price(): void
    {
        $this->assertSame([['key' => 'page-1', 'label' => 'Community return', 'sub' => W::pageName(1), 'available_kobo' => 100000]],
            WalletVotes::sources(7));

        $r = WalletVotes::buy(7, 'page-1', 2, 3);
        $this->assertTrue($r['ok'], $r['message']);
        $cost = PaidVoteService::price(3) * 100;
        $this->assertSame($cost, $r['amount_kobo'], 'rule 3: the card checkout\'s own price');
        $this->assertSame(100000 - $cost, Ret::wallet(1)['available_kobo']);
        $this->assertSame(3, (int) DB::table('gates_nominees')->where('id', 2)->value('vote_count'));

        $v = DB::table('gates_votes')->where('nominee_id', 2)->first();
        $this->assertSame('paid', $v->vote_type);
        $this->assertSame(3, (int) $v->weight);
        $this->assertNull($v->donation_id);
        $this->assertStringStartsWith(WalletVotes::HASH_PREFIX . '7:', $v->voter_email_hash);

        $spent = array_values(array_filter(W::transactions(7), fn ($t) => $t['title'] === 'Spent on votes'));
        $this->assertCount(1, $spent, 'the spend is in the wallet history');
        $this->assertSame($cost, $spent[0]['amount_kobo']);
        $this->assertSame($r['reference'], $spent[0]['ref']);
    }

    public function test_rule_one_a_wallet_vote_earns_no_return(): void
    {
        $donations = DB::table('gates_donations')->count();
        $accruals  = DB::table('gates_community_returns')->where('entry_type', 'accrual')->count();
        $this->assertTrue(WalletVotes::buy(7, 'page-1', 1, 5)['ok']);
        $this->assertSame($donations, DB::table('gates_donations')->count(), 'no contribution row, so nothing for the accrual to read');
        $this->assertSame($accruals, DB::table('gates_community_returns')->where('entry_type', 'accrual')->count());
    }

    public function test_rule_two_a_wallet_vote_carries_nobody_over_the_qualifying_line(): void
    {
        $before = Ret::qualification(1);
        $this->assertTrue(WalletVotes::buy(7, 'page-1', 1, 5)['ok']);
        $after = Ret::qualification(1);
        $this->assertSame($before['raw'], $after['raw'], 'a nominee\'s own return must not count towards qualifying for one');
        $this->assertSame($before['supporters'], $after['supporters']);
    }

    public function test_rule_three_the_checkouts_limits_and_window(): void
    {
        // A maximum the wallet could afford, so it is the maximum that refuses, not the balance.
        DB::table('gates_settings')->insert(['key_name' => 'vote_max_qty', 'value' => '2']);
        $this->assertSame(2, PaidVoteService::maxQtyForOrder());
        $r = WalletVotes::buy(7, 'page-1', 2, 3);
        $this->assertFalse($r['ok'], 'the per-order maximum');
        $this->assertStringContainsString('maximum', $r['message']);

        // Inside the card cutoff before the bell: the ballot is still open for free votes,
        // and money — card or wallet — has stopped.
        DB::table('gates_award_cycles')->where('id', 1)->update(['voting_close' => Carbon::now()->addMinutes(3)->toDateTimeString()]);
        $this->assertFalse(PaidVoteService::checkoutOpenFor(1), 'fixture: inside the cutoff');
        $r = WalletVotes::buy(7, 'page-1', 2, 1);
        $this->assertFalse($r['ok'], 'wallet votes stop when card votes do');
        $this->assertStringContainsString('closed', $r['message']);

        DB::table('gates_award_cycles')->where('id', 1)->update(['voting_close' => Carbon::now()->subDay()->toDateTimeString()]);
        $this->assertFalse(WalletVotes::buy(7, 'page-1', 2, 1)['ok'], 'no wallet votes once the ballot has closed');
        $this->assertSame(100000, Ret::wallet(1)['available_kobo'], 'a refusal takes nothing');
    }

    public function test_money_leaves_only_the_buyers_pocket_and_never_more_than_is_available(): void
    {
        $this->assertFalse(WalletVotes::buy(8, 'page-1', 2, 1)['ok'], 'somebody else\'s pocket');
        $this->assertFalse(WalletVotes::buy(7, 'points', 2, 1)['ok'], 'points have their own path');
        $this->assertFalse(WalletVotes::buy(7, 'ref', 2, 1)['ok'], 'referral earnings are paid out whole');

        $tooMany = intdiv(100000, PaidVoteService::pricePerVote() * 100) + 1;
        $r = WalletVotes::buy(7, 'page-1', 2, $tooMany);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('available', $r['message']);
        $this->assertSame(0, (int) DB::table('gates_nominees')->where('id', 2)->value('vote_count'));
        $this->assertSame(100000, Ret::wallet(1)['available_kobo']);
    }

    public function test_a_wallet_voter_is_one_person_however_many_times_they_spend(): void
    {
        $this->assertTrue(WalletVotes::buy(7, 'page-1', 2, 1)['ok']);
        $this->assertTrue(WalletVotes::buy(7, 'page-1', 2, 1)['ok']);
        $keys = [];
        foreach (DB::table('gates_votes')->where('nominee_id', 2)->get() as $v) $keys[] = VoterReach::personKey($v, []);
        $this->assertSame(['points:7', 'points:7'], $keys, 'two spends by one member are one supporter, the same one a points redemption is');
    }
}
