<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\BonusVoteService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Refund / chargeback clawback: a reversed donation's PURCHASED votes (bonus +
 * paid) are voided and the nominee's counters rebuilt, while organic votes — and
 * therefore CPI rank — are never touched. Idempotent, and a refunded donation
 * can't be redeemed again.
 */
class DonationClawbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 10, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_donations')->insert(['id' => 1, 'donor_name' => 'D', 'donor_email' => 'd@x.io', 'amount_naira' => 5000, 'bonus_votes' => 5, 'votes_used' => 5, 'status' => 'confirmed']);
    }

    private function vote(string $type, int $weight, ?int $donationId): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 10,
            'voter_email_hash' => $type . ':' . ($donationId ?? 'org') . ':' . bin2hex(random_bytes(4)),
            'vote_type' => $type, 'weight' => $weight, 'donation_id' => $donationId,
        ]);
    }

    public function test_clawback_voids_purchased_votes_but_keeps_organic(): void
    {
        $this->vote('standard', 1, null);
        $this->vote('standard', 1, null);
        $this->vote('standard', 1, null);   // 3 organic
        $this->vote('bonus', 5, 1);         // 5 bonus from donation 1
        $this->vote('paid', 2, 1);          // 2 paid from donation 1
        // Denormalised counters as the mint paths would have left them: 3 + 5 + 2.
        DB::table('gates_nominees')->where('id', 1)->update(['vote_count' => 10, 'organic_vote_count' => 3]);

        $r = BonusVoteService::clawbackDonation(1);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['cleared']);      // bonus + paid rows
        $this->assertSame(7, $r['weight']);       // 5 + 2

        // Purchased rows gone; the 3 organic rows survive.
        $this->assertSame(0, (int) DB::table('gates_votes')->where('donation_id', 1)->count());
        $this->assertSame(3, (int) DB::table('gates_votes')->where('vote_type', 'standard')->count());

        // Counters rebuilt: display back to organic-only; CPI signal unchanged.
        $keep = DB::table('gates_nominees')->where('id', 1)->first();
        $this->assertSame(3, (int) $keep->vote_count, 'paid boost removed');
        $this->assertSame(3, (int) $keep->organic_vote_count, 'organic (CPI signal) untouched');

        // Donation stamped refunded.
        $this->assertNotNull(DB::table('gates_donations')->where('id', 1)->value('refunded_at'));
    }

    public function test_clawback_is_idempotent(): void
    {
        $this->vote('bonus', 5, 1);
        DB::table('gates_nominees')->where('id', 1)->update(['vote_count' => 5]);
        $first  = BonusVoteService::clawbackDonation(1);
        $second = BonusVoteService::clawbackDonation(1);
        $this->assertSame(1, $first['cleared']);
        $this->assertSame(0, $second['cleared'], 'second clawback finds nothing to reverse');
    }

    public function test_refunded_donation_cannot_be_redeemed_again(): void
    {
        DB::table('gates_donations')->where('id', 1)->update(['votes_used' => 0, 'refunded_at' => date('Y-m-d H:i:s')]);
        $r = (new BonusVoteService())->redeem(1, 1, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('refunded', $r['message']);
    }
}
