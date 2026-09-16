<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OrgPayout;
use AfricaGates\Services\PartnerOrg;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Money that was given back is not money an organisation has.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY `status = 'confirmed'` IS NOT THE QUESTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A donation clawback stamps `refunded_at` and LEAVES the status alone — deliberately,
 * because the row is the record that the money once cleared and rewriting that to
 * 'refunded' destroys the fact. So `status` never goes backwards, and every reader that
 * asks only `where('status','confirmed')` is counting refunded gifts.
 *
 * Four did, each spelling the clause by hand. Three of them were displays that all agreed
 * with each other — the dashboard headline, the 90-day line and the recent list — which is
 * exactly what makes this invisible: nothing on the screen contradicts anything else on
 * the screen, so there is no symptom to report.
 *
 * The fourth was not a display. {@see OrgPayout::available()} takes its figure straight
 * out of {@see PartnerOrg::totals()}, so a refunded gift stayed inside the balance an
 * organisation is allowed to REQUEST. The donor had their money back and the withdrawable
 * number never moved.
 *
 * ── AND THE FIFTH WAS THE PUBLIC ONE ─────────────────────────────────────────
 *
 * `PartnerOrg::publicTotals()`' raised figure is printed on the page that asks people for
 * money. Its own neighbouring comment says a missing column must read as a zero rather
 * than "a five-hundred on the public page" — the same instinct, one clause short.
 *
 * So there is ONE definition now and the readers compose on it. This test pins the
 * behaviour rather than the clause: it refunds a real row and requires every figure to
 * move, which is the only way to tell a scope that is applied from one that is merely
 * present in the source.
 */
final class RefundedMoneyTest extends TestCase
{
    private int $orgId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'name' => 'Kigali Signal Trust', 'slug' => 'kigali-signal-trust',
            'status' => 'approved', 'contact_email' => 'compliance@example.org',
        ]);

        foreach ([[40000, 4000], [10000, 1000]] as $i => [$gross, $fee]) {
            DB::table('gates_donations')->insert([
                'donor_name' => 'A supporter ' . $i, 'donor_email' => "d$i@example.org",
                'amount_naira' => $gross, 'platform_fee_naira' => $fee,
                'recipient_org_id' => $this->orgId, 'status' => 'confirmed',
                'payment_ref' => 'REF-' . $i, 'bonus_votes' => 0,
                'created_at' => '2026-09-01 10:00:00', 'confirmed_at' => '2026-09-01 10:00:00',
            ]);
        }
    }

    /** The clawback's own write: the stamp, and the status left exactly where it was. */
    private function refundTheLargerGift(): void
    {
        DB::table('gates_donations')->where('payment_ref', 'REF-0')
            ->update(['refunded_at' => '2026-09-08 09:00:00']);

        $this->assertSame('confirmed',
            (string) DB::table('gates_donations')->where('payment_ref', 'REF-0')->value('status'),
            'this test is not testing what it claims: the refund changed the status, so a '
            . 'status filter alone would already have been enough');
    }

    public function test_a_refunded_gift_leaves_the_organisations_totals(): void
    {
        $before = PartnerOrg::totals($this->orgId);
        $this->assertSame(50000, $before['gross']);
        $this->assertSame(2, $before['count']);

        $this->refundTheLargerGift();

        $after = PartnerOrg::totals($this->orgId);
        $this->assertSame(10000, $after['gross'], 'a refunded gift is still in the gross');
        $this->assertSame(1000, $after['platform_fee'], 'a refunded gift is still in the fee');
        $this->assertSame(9000, $after['net'], 'a refunded gift is still in the net');
        $this->assertSame(1, $after['count'], 'a refunded gift is still in the count');
    }

    /**
     * The one that is money rather than a number on a screen.
     */
    public function test_a_refunded_gift_leaves_the_balance_the_organisation_may_request(): void
    {
        $this->assertSame(45000, OrgPayout::available($this->orgId));

        $this->refundTheLargerGift();

        $this->assertSame(9000, OrgPayout::available($this->orgId),
            'an organisation can still request money the donor has had back');
    }

    /**
     * Every figure comes off one scope, so a reader added later cannot quietly
     * reintroduce the clause with a piece missing.
     */
    public function test_the_public_raised_figure_drops_too(): void
    {
        $raised = static fn (): int =>
            (int) PartnerOrg::countableDonations()->sum('amount_naira');

        $this->assertSame(50000, $raised());
        $this->refundTheLargerGift();
        $this->assertSame(10000, $raised(), 'the public page still counts a refunded gift');
    }
}
