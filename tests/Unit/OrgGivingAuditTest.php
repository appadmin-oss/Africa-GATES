<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OrgPayout;
use AfricaGates\Services\PartnerOrg;
use AfricaGates\Services\PaymentDestination;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The partner-organisation giving audit (owner, 3 Nov 2026: "fix all other findings too").
 *
 * Each case is money counted, settled or offered in a way the screens did not show: a
 * balance that included gifts the gateway had already paid to the organisation, a tipped
 * gift that settled the platform's fee to the partner, requests nobody here could see, and
 * transfers whose answer was lost and were then never asked about.
 */
final class OrgGivingAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['PARTNER_PAYOUT_MODE']);
        parent::tearDown();
    }

    private function org(int $bps = 0): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'bf-' . bin2hex(random_bytes(4)), 'name' => 'Bright Futures Initiative',
            'cac_number' => 'IT/1', 'scuml_number' => 'SC-1', 'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_abcdef12', 'platform_fee_bps' => $bps, 'settlement_bank' => '058',
            'account_last4' => '6789',
        ]);
    }

    private function gift(int $orgId, int $amount, int $fee = 0, bool $routed = false, int $tip = 0): string
    {
        $ref = 'AFG-GIVE-' . bin2hex(random_bytes(4));
        DB::table('gates_donations')->insert([
            'donor_name' => 'D', 'donor_email' => 'd@example.com', 'amount_naira' => $amount,
            'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0, 'payment_ref' => $ref,
            'status' => 'confirmed', 'recipient_org_id' => $orgId, 'platform_fee_naira' => $fee,
            'platform_tip_naira' => $tip, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($routed) {
            DB::table('gates_payment_routes')->insert(['reference' => $ref, 'revenue_stream' => 'partner:' . $orgId,
                'subaccount' => 'ACCT_abcdef12', 'fee_bearer' => 'account', 'amount_naira' => $amount,
                'created_at' => date('Y-m-d H:i:s')]);
        }
        return $ref;
    }

    private function queued(int $orgId, int $amount, int $attempts = 0): int
    {
        return (int) DB::table('gates_org_payouts')->insertGetId([
            'org_id' => $orgId, 'reference' => OrgPayout::mintReference($orgId), 'amount_naira' => $amount,
            'status' => OrgPayout::ST_QUEUED, 'attempts' => $attempts,
            'requested_at' => date('Y-m-d H:i:s', time() - 600),
        ]);
    }

    public function test_a_gift_the_gateway_settled_to_the_organisation_is_not_theirs_to_request_again(): void
    {
        $id = $this->org();
        $this->gift($id, 50000, 0, true);    // split at the gateway: already in their bank
        $this->gift($id, 8000);              // reached our account: genuinely held here
        $this->assertSame(8000, OrgPayout::available($id), 'the organisation could ask for money it had already been paid');
        $this->assertSame(58000, PartnerOrg::totals($id)['net'], 'what they raised is still everything they raised');
    }

    public function test_a_tipped_gift_still_settles_the_agreed_fee_to_the_platform(): void
    {
        $id  = $this->org(500);              // 5%
        $ref = $this->gift($id, 10000, 500 + 200, false, 200);
        $f = PaymentDestination::initFieldsForPartner($id, PaymentDestination::platformTipForReference($ref),
            PaymentDestination::platformShareForReference($ref));
        $this->assertSame((string) (700 * 100), $f['transaction_charge'] ?? null,
            'transaction_charge overrides the split, so a charge of the tip alone gave the partner our fee');
        $this->assertArrayNotHasKey('transaction_charge', PaymentDestination::initFieldsForPartner($id, 0, 500),
            'an untipped gift leaves the subaccount’s own percentage to apply');
        $src = ChromeRender::code('src/Services/PaymentService.php');
        $this->assertStringContainsString('PaymentDestination::platformShareForReference($reference)', $src, 'checkout never passes the fee');
    }

    public function test_an_organisations_request_reaches_a_person_and_is_settled_once(): void
    {
        $id = $this->org();
        $this->gift($id, 20000);
        $p = $this->queued($id, 15000);
        $rows = OrgPayout::awaitingHand();
        $this->assertSame([$p], array_map(static fn ($r) => (int) $r->id, $rows), 'a request nobody here could see');
        $this->assertSame(20000, $rows[0]->held_naira);

        $this->assertFalse(OrgPayout::settleByHand($p, '', 1)['ok'], 'paid with no reference');
        $this->assertTrue(OrgPayout::settleByHand($p, 'GTB/1', 1)['ok']);
        $this->assertFalse(OrgPayout::settleByHand($p, 'GTB/2', 1)['ok'], 'one payment recorded twice');
        $this->assertFalse(OrgPayout::refuse($p, 'late', 1)['ok'], 'a paid request refused after the money went');
        $this->assertSame(OrgPayout::ST_SUCCESS, DB::table('gates_org_payouts')->where('id', $p)->value('status'));
        $this->assertSame(5000, OrgPayout::available($id));

        $q = $this->queued($id, 5000);
        $this->assertTrue(OrgPayout::refuse($q, 'Account name does not match', 1)['ok']);
        $this->assertSame(5000, OrgPayout::available($id), 'a refused request still held the balance down');

        $_ENV['PARTNER_PAYOUT_MODE'] = 'transfer';
        $r = $this->queued($id, 1000);
        $this->assertFalse(OrgPayout::settleByHand($r, 'GTB/3', 1)['ok'], 'paid by hand while the transfer may also send it');

        $tpl = ChromeRender::source('templates/admin/payouts.twig');
        $this->assertStringContainsString('/admin/payouts/org/{{ p.id }}/pay', $tpl);
        $this->assertStringContainsString('/admin/payouts/org/{{ p.id }}/refuse', $tpl);
    }

    public function test_a_transfer_whose_answer_was_lost_is_asked_about(): void
    {
        $_ENV['PARTNER_PAYOUT_MODE'] = 'transfer';
        $id = $this->org();
        $this->gift($id, 20000);
        $tried   = $this->queued($id, 5000, 1);
        $untried = $this->queued($id, 5000, 0);
        $pay = new class extends PaymentService {
            public array $asked = [];
            public function verifyTransfer(string $reference): array
            {
                $this->asked[] = $reference;
                return ['ok' => true, 'status' => 'success', 'reachable' => true, 'absent' => false, 'message' => ''];
            }
        };
        $r = OrgPayout::sweep($pay);
        $this->assertSame(1, $r['checked'], 'a queued transfer that had been tried was never asked about');
        $this->assertSame(OrgPayout::ST_SUCCESS, DB::table('gates_org_payouts')->where('id', $tried)->value('status'));
        $this->assertSame(OrgPayout::ST_QUEUED, DB::table('gates_org_payouts')->where('id', $untried)->value('status'),
            'an untried request is a person’s, never the sweep’s');
    }

    public function test_the_organisation_console_reads_the_right_fields(): void
    {
        $s = ChromeRender::source('templates/pages/org/_sections.twig');
        $this->assertStringContainsString("p.requested_at|default('')", $s, 'the payout table has no created_at: every date was blank');
        $d = ChromeRender::source('templates/pages/org/dashboard.twig');
        $this->assertStringContainsString('totals.raised|default(0)', $d, '"Given in total" counted the donors’ tips to us');
    }
}
