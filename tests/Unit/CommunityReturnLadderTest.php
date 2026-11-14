<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\CommunityReturnLadder as Ladder;
use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnService as Ret;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The flat, tiered and custom community-return models: one ladder engine.
 *
 * What these try to break: a share priced on money that arrived AFTER the contribution,
 * a refund re-pricing what is already on the ledger, a custom rule nobody wrote paying
 * anybody, and a locked ladder releasing money before its milestone is confirmed.
 */
class CommunityReturnLadderTest extends TestCase
{
    private int $minute = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Ada', 'status' => 'approved']);
        DB::table('gates_nominees')->insert(['id' => 2, 'category_id' => 1, 'name' => 'Bola', 'status' => 'approved']);
    }

    private function edition(array $in): void
    {
        $r = Milestone::saveEditionRules(1, $in);
        $this->assertTrue($r['ok'], $r['message']);
    }

    /** A confirmed contribution, each one a minute after the last so their order is plain. */
    private function pay(int $naira, int $nominee = 1, array $over = []): int
    {
        $this->minute++;
        return (int) DB::table('gates_donations')->insertGetId(array_merge([
            'donor_name' => 'X', 'donor_email' => 'x@example.com', 'amount_naira' => $naira,
            'tier' => 'paid-vote', 'bonus_votes' => 1, 'votes_used' => 1, 'intent_nominee_id' => $nominee,
            'payment_ref' => 'L' . $this->minute . '-' . $nominee, 'status' => 'confirmed',
            'created_at' => Carbon::now()->subHours(5)->addMinutes($this->minute)->toDateTimeString(),
        ], $over));
    }

    public function test_flat_earns_one_share_from_the_first_naira_and_waits_for_results(): void
    {
        $this->edition(['model' => 'flat', 'full_pct' => 20]);
        $r = Ret::accrue($this->pay(1000));
        $this->assertSame(20000, $r['kobo'], '20% of ₦1,000, no threshold to cross');

        $w = Ret::wallet(1);
        $this->assertSame(0, $w['locked_kobo']);
        $this->assertSame(20000, $w['awaiting_kobo'], 'released at results, like the threshold model');
        $this->assertSame(0, $w['payable_kobo']);

        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results']);
        $this->assertSame(20000, Ret::wallet(1)['payable_kobo']);
    }

    public function test_tiered_climbs_with_what_the_nominee_raised_before_each_contribution(): void
    {
        $this->edition(['model' => 'tiered', 'ladder' => '']);   // the house ladder
        $a = Ret::accrue($this->pay(50000));   // raised before: 0       → 10%
        $b = Ret::accrue($this->pay(150000));  // raised before: 50,000  → 30%
        $c = Ret::accrue($this->pay(10000));   // raised before: 200,000 → 50%

        $this->assertSame(500000, $a['kobo']);
        $this->assertSame(4500000, $b['kobo']);
        $this->assertSame(500000, $c['kobo']);
    }

    public function test_a_contribution_is_priced_before_anything_that_arrived_after_it(): void
    {
        $this->edition(['model' => 'tiered', 'ladder' => "0: 10\n1000: 50"]);
        $early = $this->pay(500);
        $later = $this->pay(5000);
        // The later one is accrued FIRST (a webhook landing out of order)...
        $this->assertSame(50000, Ret::accrue($later)['kobo'], '10%: only ₦500 had been raised before it');
        // ...and the early one is still priced on what came before IT: nothing.
        $this->assertSame(5000, Ret::accrue($early)['kobo']);
    }

    public function test_a_refund_does_not_reprice_what_is_already_on_the_ledger(): void
    {
        $this->edition(['model' => 'tiered', 'ladder' => "0: 10\n2000: 50"]);
        $first  = $this->pay(1000);
        $second = $this->pay(1000);
        Ret::accrue($first);
        Ret::accrue($second);

        DB::table('gates_donations')->where('id', $first)->update(['refunded_at' => Carbon::now()->toDateTimeString()]);
        Ret::reverse($first, 'refunded');
        $this->assertSame(10000, (int) DB::table('gates_community_returns')->where('donation_id', $second)
            ->where('entry_type', 'accrual')->value('amount_kobo'), 'the entry stands as it was priced');

        // Refunded money no longer lifts anybody: ₦1,000 eligible before this, not ₦2,000.
        $this->assertSame(10000, Ret::accrue($this->pay(1000))['kobo']);
    }

    public function test_a_custom_ladder_on_the_edition_basis_counts_everybody_in_it(): void
    {
        $this->edition(['model' => 'custom', 'ladder' => "0: 10\n2000: 40", 'basis' => 'edition']);
        Ret::accrue($this->pay(2000, 2));                       // Bola's money...
        $this->assertSame(40000, Ret::accrue($this->pay(1000, 1))['kobo'], '...moves Ada to the 40% step');
    }

    public function test_a_custom_ladder_that_starts_above_zero_pays_nothing_below_it(): void
    {
        $this->edition(['model' => 'custom', 'ladder' => '5000: 30']);
        $this->assertSame('RATE_OFF', Ret::accrue($this->pay(4000))['code'], 'nothing raised before it');
        $this->assertSame('RATE_OFF', Ret::accrue($this->pay(1000))['code'], '₦4,000 before it: still under the step');
        $this->assertSame(60000, Ret::accrue($this->pay(2000))['kobo'], '₦5,000 before it: 30%');
    }

    public function test_a_locked_custom_ladder_waits_for_its_milestone(): void
    {
        $this->edition(['model' => 'custom', 'ladder' => '0: 20', 'ladder_locked' => '1', 'milestone_naira' => 1000]);
        $r = Ret::accrue($this->pay(1000));
        $this->assertSame('ACCRUED_LOCKED', $r['code']);
        $this->assertSame(20000, Ret::wallet(1)['locked_kobo']);

        $this->assertTrue(Milestone::confirm(1, 1)['ok']);
        $this->assertSame(0, Ret::wallet(1)['locked_kobo']);
        $this->assertSame(20000, Ret::wallet(1)['payable_kobo'], 'released by the confirmation, not by results');
    }

    public function test_a_locked_ladder_needs_a_target_and_a_custom_ladder_needs_steps(): void
    {
        $this->assertFalse(Milestone::saveEditionRules(1, ['model' => 'custom', 'ladder' => '0: 20', 'ladder_locked' => '1'])['ok']);
        $this->assertFalse(Milestone::saveEditionRules(1, ['model' => 'custom', 'ladder' => ''])['ok']);
    }

    public function test_the_ladder_parser_refuses_rather_than_guesses(): void
    {
        $this->assertTrue(Ladder::parse("₦50,000 = 30%\n0: 10")['ok']);
        $this->assertSame([[0, 1000], [50000, 3000]], Ladder::parse("50000: 30\n0: 10")['steps'], 'sorted');
        $this->assertFalse(Ladder::parse('fifty thousand: 30')['ok']);
        $this->assertFalse(Ladder::parse('0: 120')['ok']);
        $this->assertFalse(Ladder::parse("0: 10\n0: 20")['ok']);
        $this->assertFalse(Ladder::parse(implode("\n", array_map(fn ($i) => ($i * 1000) . ': 10', range(0, 11))))['ok']);
    }

    public function test_the_published_sentence_comes_from_the_rules_in_force(): void
    {
        $this->edition(['model' => 'custom', 'ladder' => "0: 10\n50000: 30", 'basis' => 'edition']);
        $s = Milestone::summary(Milestone::rulesForCycle(1));
        $this->assertStringContainsString('10% from the first naira', $s);
        $this->assertStringContainsString('30% once the whole edition has raised ₦50,000', $s);
    }
}
