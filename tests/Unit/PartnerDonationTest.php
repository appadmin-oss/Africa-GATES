<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{PartnerOrg, PaymentDestination};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Partner donations: the money goes to the partner, or it does not go at all.
 *
 * Everything here is about one question — WHICH ACCOUNT does a given naira settle into —
 * because that is the question this feature exists to answer and the one where being wrong
 * is not a bug report but a missing donation belonging to a charity.
 */
class PartnerDonationTest extends TestCase
{
    /**
     * A slug unique to each call.
     *
     * Not for tidiness: the MySQL parity run isolates tests by transaction rollback, and any
     * test anywhere in the suite that issues DDL implicitly COMMITs — so rows can survive
     * into a later file for reasons that have nothing to do with either test. A fixture that
     * cannot collide does not care.
     */
    private function uniqueSlug(string $stem = 'bright-futures'): string
    {
        return $stem . '-' . bin2hex(random_bytes(4));
    }

    private function makeOrg(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug'            => $this->uniqueSlug(),
            'name'            => 'Bright Futures Initiative',
            'cac_number'      => 'IT/1234567',
            'scuml_number'    => 'SC-9988',
            'status'          => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_partner01',
            'platform_fee_bps'=> 0,
        ]);
    }

    private function pendingDonation(string $ref, ?int $orgId, int $amount = 5000): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'A Donor', 'donor_email' => 'd@example.com',
            'amount_naira' => $amount, 'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
            'payment_ref' => $ref, 'status' => 'pending',
            'recipient_org_id' => $orgId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ───────────────────────────── routing ──────────────────────────────────

    public function test_a_partner_donation_routes_to_that_partners_subaccount(): void
    {
        $id = $this->makeOrg();
        $this->pendingDonation('AFG-GIVE-aaaa1111', $id);

        $this->assertSame($id, PaymentDestination::partnerOrgIdForReference('AFG-GIVE-aaaa1111'));
        $this->assertSame(
            ['subaccount' => 'ACCT_partner01'],
            PaymentDestination::initFieldsForPartner($id)
        );
    }

    /**
     * The platform's own donations must be untouched by all of this. A regression here
     * silently re-routes every gift that funds the programmes.
     */
    public function test_a_platform_donation_is_not_treated_as_a_partner_donation(): void
    {
        $this->pendingDonation('AFG-GIVE-bbbb2222', null);
        $this->assertSame(0, PaymentDestination::partnerOrgIdForReference('AFG-GIVE-bbbb2222'));
    }

    /** Ticket and shop references must never be mistaken for donations. */
    public function test_other_payment_kinds_are_never_partner_routed(): void
    {
        foreach (['AFG-EVT-1234', 'AFG-SHP-1234', 'AFG-9999', ''] as $ref) {
            $this->assertSame(0, PaymentDestination::partnerOrgIdForReference($ref), $ref);
        }
    }

    /**
     * A suspended partner stops being able to take money AT THE GATEWAY CALL, not merely on
     * the page. The page is a cache of the decision; this is the decision.
     *
     * The fallback is deliberately "settle to the main account" rather than "refuse the
     * payment": money that arrives somewhere visible and refundable is a far better failure
     * than a donor who cannot pay, and far better than money reaching a suspended charity.
     */
    public function test_a_suspended_partner_cannot_receive_even_with_a_valid_subaccount(): void
    {
        $id = $this->makeOrg(['status' => PartnerOrg::STATUS_SUSPENDED]);
        $this->pendingDonation('AFG-GIVE-cccc3333', $id);

        $this->assertSame($id, PaymentDestination::partnerOrgIdForReference('AFG-GIVE-cccc3333'));
        $this->assertSame([], PaymentDestination::initFieldsForPartner($id),
            'A suspended partner must not receive a split.');
        $this->assertFalse(PartnerOrg::canReceive(PartnerOrg::find($id)));
    }

    /** Approved but with no settlement account is a configuration mistake, not a live partner. */
    public function test_an_approved_partner_without_a_subaccount_cannot_receive(): void
    {
        $id = $this->makeOrg(['subaccount_code' => '']);
        $this->assertFalse(PartnerOrg::canReceive(PartnerOrg::find($id)));
        $this->assertSame([], PaymentDestination::initFieldsForPartner($id));
        $this->assertSame([], PartnerOrg::listReceivable());
    }

    // ─────────────────────────────── vetting ────────────────────────────────

    public function test_approval_requires_a_settlement_account(): void
    {
        $id = $this->makeOrg(['status' => PartnerOrg::STATUS_PENDING, 'subaccount_code' => '']);
        $r  = PartnerOrg::approve($id, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('settlement account', $r['message']);
    }

    /**
     * CAC and SCUML are legal requirements for a Nigerian non-profit collecting donations —
     * SCUML registration is mandatory under the Money Laundering (Prevention and
     * Prohibition) Act 2022 and an offence to trade without. Approving a partner that has
     * neither on file is the platform helping somebody break the law.
     */
    public function test_approval_requires_cac_and_scuml_on_file(): void
    {
        foreach (['cac_number', 'scuml_number'] as $missing) {
            // delete(), never truncate(). TRUNCATE is DDL and DDL implicitly COMMITs in MySQL,
            // which breaks the per-test transaction the harness rolls back — rows leak into
            // whichever test runs next, in a different file, for no visible reason. TestCase
            // documents this trap; the MySQL parity run is what found that I had walked into it.
            DB::table('gates_partner_orgs')->delete();
            $id = $this->makeOrg(['status' => PartnerOrg::STATUS_PENDING, $missing => '']);
            $r  = PartnerOrg::approve($id, 1);
            $this->assertFalse($r['ok'], "Approved with no $missing");
        }
    }

    public function test_suspend_stops_collection_without_erasing_the_partner(): void
    {
        $id = $this->makeOrg();
        PartnerOrg::suspend($id, 'CAC restricted its trustees.');

        $org = PartnerOrg::find($id);
        $this->assertSame(PartnerOrg::STATUS_SUSPENDED, $org->status);
        $this->assertNotNull($org->subaccount_code, 'History must survive a suspension.');
        $this->assertFalse(PartnerOrg::canReceive($org));
    }

    // ──────────────────────────── the name match ────────────────────────────

    /**
     * The fraud case: an organisation registering under a charity name whose settlement
     * account resolves to a person. It must not score as a match.
     */
    public function test_a_personal_account_name_does_not_match_a_charity_name(): void
    {
        $score = PartnerOrg::nameSimilarity('Bright Futures Initiative', 'ADEBAYO JOHN OLUWASEUN');
        $this->assertLessThan(0.5, $score, 'A personal name must be flagged for review.');
    }

    /** And the legitimate case: a trading variant of the same name must not be blocked. */
    public function test_trading_variants_of_the_same_name_still_match(): void
    {
        foreach ([
            ['Bright Futures Initiative', 'BRIGHT FUTURES INITIATIVE'],
            ['Bright Futures Initiative', 'BRIGHT FUTURES INIT LTD'],
            ['Hope Alive Foundation',     'HOPE ALIVE FOUNDATION NIGERIA'],
        ] as [$a, $b]) {
            $this->assertGreaterThanOrEqual(0.86, PartnerOrg::nameSimilarity($a, $b), "$a vs $b");
        }
    }

    /** Two different charities sharing a common word are not the same organisation. */
    public function test_two_different_charities_sharing_a_word_do_not_match(): void
    {
        $this->assertLessThan(
            0.86,
            PartnerOrg::nameSimilarity('Hope Alive Foundation', 'Grace Children Foundation')
        );
    }

    // ──────────────────────────────── totals ────────────────────────────────

    /**
     * A dashboard that counts money which has not arrived is a dashboard that causes an
     * argument. Only confirmed rows count, and the partner's own share is gross minus the
     * fee STORED on each row — never recomputed from today's rate, because a fee that
     * changes next quarter must not restate what a partner earned last quarter.
     */
    public function test_totals_count_only_confirmed_rows_and_use_the_stored_fee(): void
    {
        $id = $this->makeOrg();
        foreach ([['confirmed', 10000, 500], ['confirmed', 5000, 250], ['pending', 99000, 0], ['failed', 77000, 0]] as [$st, $amt, $fee]) {
            DB::table('gates_donations')->insert([
                'donor_name' => 'D', 'donor_email' => 'd@example.com',
                'amount_naira' => $amt, 'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
                'payment_ref' => 'AFG-GIVE-' . bin2hex(random_bytes(4)), 'status' => $st,
                'recipient_org_id' => $id, 'platform_fee_naira' => $fee,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $t = PartnerOrg::totals($id);
        $this->assertSame(15000, $t['gross']);
        $this->assertSame(750,   $t['platform_fee']);
        $this->assertSame(14250, $t['net']);
        $this->assertSame(2,     $t['count']);
    }

    /** One partner must never see another's money. */
    public function test_totals_are_scoped_to_one_organisation(): void
    {
        $a = $this->makeOrg();
        $b = $this->makeOrg(['slug' => 'hope-alive', 'name' => 'Hope Alive']);

        DB::table('gates_donations')->insert([
            'donor_name' => 'D', 'donor_email' => 'd@example.com', 'amount_naira' => 4000,
            'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
            'payment_ref' => 'AFG-GIVE-dddd4444', 'status' => 'confirmed',
            'recipient_org_id' => $a, 'platform_fee_naira' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(4000, PartnerOrg::totals($a)['gross']);
        $this->assertSame(0,    PartnerOrg::totals($b)['gross']);
    }
}
