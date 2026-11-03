<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GatewayFee;
use AfricaGates\Services\OrgPayout;
use AfricaGates\Services\PartnerOrg;
use AfricaGates\Services\PaymentDestination;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The processing fee on a gift, to the modern standard (owner, 3 Nov 2026): the recipient
 * bears it, the donor is offered — never defaulted into — the exact amount that covers it,
 * and every figure an organisation reads keeps the gift, our share and the gateway's apart.
 */
final class GatewayFeeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GatewayFee::forget();
    }

    public function test_the_fee_is_the_gateways_schedule_not_a_percentage(): void
    {
        $this->assertSame(30, GatewayFee::fee(2000), 'under ₦2,500 the flat ₦100 is waived');
        $this->assertSame(175, GatewayFee::fee(5000), '1.5% plus ₦100');
        $this->assertSame(2000, GatewayFee::fee(1_000_000), 'capped');
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'processing_fee_cap'], ['value' => '0']);
        GatewayFee::forget();
        $this->assertSame(15100, GatewayFee::fee(1_000_000), 'a setting moves it — the schedule is not typed');
    }

    public function test_the_cover_leaves_the_recipient_with_the_gift_to_the_naira(): void
    {
        foreach ([1000, 2400, 2500, 5000, 47000, 133333, 500000, 2_000_000] as $gift) {
            foreach ([0, 500] as $tip) {
                $c = GatewayFee::cover($gift, $tip);
                $this->assertGreaterThanOrEqual($gift, $gift + $c - GatewayFee::fee($gift + $tip + $c),
                    "₦$gift (tip ₦$tip): the cover left the recipient short");
                $this->assertLessThan($gift, $gift + ($c - 1) - GatewayFee::fee($gift + $tip + $c - 1),
                    "₦$gift (tip ₦$tip): the cover is more than it needs to be");
            }
        }
        $this->assertNotSame((int) ceil(5000 * 0.015), GatewayFee::cover(5000), 'still a percentage of the gift');
    }

    public function test_the_box_is_never_ticked_and_states_naira(): void
    {
        $src = ChromeRender::source('templates/pages/donate.twig');
        $this->assertMatchesRegularExpression('~<input type="checkbox" name="cover_fees" value="1" data-gv-cover>~', $src, 'pre-ticked');
        $this->assertStringContainsString('data-gv-cover-amt', $src, 'the cover is a percentage the donor must work out');
        $js = ChromeRender::code('public/assets/js/giving.js');
        $this->assertStringContainsString('function coverFor(a, also)', $js, 'the page computes a different cover from the server');
        $this->assertStringNotContainsString("getAttribute('data-fee')", $js);
    }

    public function test_the_organisation_bears_the_fee_and_its_figures_say_so(): void
    {
        $org = (int) DB::table('gates_partner_orgs')->insertGetId(['slug' => 'bf-' . bin2hex(random_bytes(3)), 'name' => 'Bright Futures',
            'cac_number' => 'IT/1', 'scuml_number' => 'SC-1', 'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_abcdef12', 'platform_fee_bps' => 0, 'settlement_bank' => '058']);
        $this->assertSame('subaccount', PaymentDestination::initFieldsForPartner($org)['bearer'] ?? null,
            'Paystack charged the platform for every gift to every organisation');

        // A ₦5,000 gift, uncovered, routed to us; and a ₦5,000 gift the donor covered.
        foreach ([[5000, 0], [5000 + GatewayFee::cover(5000), GatewayFee::cover(5000)]] as [$charge, $cover]) {
            DB::table('gates_donations')->insert(['donor_name' => 'D', 'donor_email' => 'd@example.com', 'amount_naira' => $charge,
                'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0, 'payment_ref' => 'AFG-GIVE-' . bin2hex(random_bytes(4)),
                'status' => 'confirmed', 'recipient_org_id' => $org, 'platform_fee_naira' => 0, 'fee_cover_naira' => $cover,
                'gateway_fee_naira' => GatewayFee::fee($charge), 'created_at' => date('Y-m-d H:i:s')]);
        }
        $t = PartnerOrg::totals($org);
        $this->assertSame(10000, $t['raised'], 'a fee cover was counted as given to them');
        $this->assertSame(10000 - GatewayFee::fee(5000), $t['net'], 'what reached them ignores the fee they bore');
        $this->assertSame($t['net'], OrgPayout::available($org), 'the payable balance pays out the gateway’s fee');

        DB::table('gates_settings')->updateOrInsert(['key_name' => GatewayFee::BEARER_KEY], ['value' => 'platform']);
        $this->assertArrayNotHasKey('bearer', PaymentDestination::initFieldsForPartner($org), 'the setting does not switch it back');
    }

    public function test_a_covered_gift_settles_our_share_exactly(): void
    {
        $org = (int) DB::table('gates_partner_orgs')->insertGetId(['slug' => 'bf-' . bin2hex(random_bytes(3)), 'name' => 'Bright Futures',
            'cac_number' => 'IT/1', 'scuml_number' => 'SC-1', 'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_abcdef12', 'platform_fee_bps' => 500, 'settlement_bank' => '058']);
        $f = PaymentDestination::initFieldsForPartner($org, 0, 250, GatewayFee::cover(5000));
        $this->assertSame('25000', $f['transaction_charge'] ?? null,
            'with a cover in the charge, the subaccount’s percentage took a cut of money meant for the gateway');
    }
}
