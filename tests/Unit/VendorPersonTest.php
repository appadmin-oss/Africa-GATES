<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{OrgAuth, PartnerOrg};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A vendor can be a person, and nearly all of this is about not treating them as a suspect.
 *
 * ── THE FAILURE THIS CLASS EXISTS TO PREVENT ─────────────────────────────────
 *
 * A Nigerian bank returns "OKAFOR NGOZI CHIOMA". The same woman writes "Ngozi Okafor". Under
 * the ORGANISATION name rule those score around 0.5 — squarely inside the range the platform
 * treats as "somebody is collecting into a stranger's account". Applied across a vendor list
 * that would flag nearly every honest sole trader, and a warning that fires on everybody is a
 * warning reviewers learn to click past, which is how it stops working for the one case it
 * exists for.
 *
 * So the tests below are mostly assertions that legitimate people score HIGH, with a small
 * number checking that the rule has not been loosened into meaninglessness.
 */
class VendorPersonTest extends TestCase
{
    private function person(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug'        => 'mama-ngozi-' . bin2hex(random_bytes(4)),
            'name'        => 'Mama Ngozi’s Kitchen',
            'legal_name'  => 'Ngozi Okafor',
            'kind'        => PartnerOrg::KIND_VENDOR,
            'entity_type' => PartnerOrg::ENTITY_INDIVIDUAL,
            'status'      => PartnerOrg::STATUS_PENDING,
        ]);
    }

    private function business(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug'        => 'adaeze-foods-' . bin2hex(random_bytes(4)),
            'name'        => 'Adaeze Foods',
            'legal_name'  => 'Adaeze Foods Limited',
            'kind'        => PartnerOrg::KIND_VENDOR,
            'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'status'      => PartnerOrg::STATUS_PENDING,
        ]);
    }

    // ─────────────────────────── comparing people's names ───────────────────

    /** Surname first is the bank's convention, not a discrepancy. */
    public function test_a_reordered_name_is_a_strong_match(): void
    {
        $this->assertGreaterThanOrEqual(
            0.86,
            PartnerOrg::personNameSimilarity('Ngozi Okafor', 'OKAFOR NGOZI'),
            'Surname-first ordering must not read as a different person.'
        );
    }

    /** Middle names live on bank records and rarely on forms. */
    public function test_an_extra_middle_name_on_the_bank_side_still_matches(): void
    {
        $this->assertGreaterThanOrEqual(
            0.86,
            PartnerOrg::personNameSimilarity('Ngozi Okafor', 'OKAFOR NGOZI CHIOMA')
        );
    }

    /** "OKAFOR N C" is a real thing a Nigerian bank returns. */
    public function test_an_initial_stands_for_the_name_it_abbreviates(): void
    {
        $this->assertGreaterThanOrEqual(
            0.86,
            PartnerOrg::personNameSimilarity('Ngozi Okafor', 'OKAFOR N C')
        );
    }

    /** MRS is not a name, and half of Nigerian bank records carry one. */
    public function test_titles_are_stripped(): void
    {
        $this->assertGreaterThanOrEqual(
            0.86,
            PartnerOrg::personNameSimilarity('Ngozi Okafor', 'MRS OKAFOR NGOZI')
        );
        $this->assertGreaterThanOrEqual(
            0.86,
            PartnerOrg::personNameSimilarity('Alhaji Musa Bello', 'BELLO MUSA')
        );
    }

    /**
     * And the rule still says no.
     *
     * A shared surname is exactly the case a reviewer must be shown — two brothers, or
     * somebody using a relative's account. It must not clear the bar on its own.
     */
    public function test_a_shared_surname_alone_is_not_a_match(): void
    {
        $this->assertLessThan(
            0.86,
            PartnerOrg::personNameSimilarity('Ngozi Okafor', 'CHINEDU OKAFOR')
        );
    }

    public function test_an_unrelated_name_scores_nothing_like_a_match(): void
    {
        $this->assertLessThan(
            0.5,
            PartnerOrg::personNameSimilarity('Ngozi Okafor', 'ADEBAYO JOHN OLUWASEUN')
        );
    }

    /** A repeated part cannot score twice against one occurrence. */
    public function test_a_duplicated_part_is_consumed_once(): void
    {
        $this->assertLessThan(
            0.86,
            PartnerOrg::personNameSimilarity('Okafor Okafor', 'OKAFOR NGOZI')
        );
    }

    /**
     * The routing is the whole point: the same pair scores weak under the organisation rule
     * and strong under the person rule, and matchScore picks by what the party IS.
     */
    public function test_the_score_is_chosen_by_what_the_party_is(): void
    {
        $person   = PartnerOrg::find($this->person());
        $business = PartnerOrg::find($this->business(['legal_name' => 'Ngozi Okafor']));

        $bank = 'OKAFOR NGOZI CHIOMA';

        $this->assertGreaterThanOrEqual(0.86, PartnerOrg::matchScore($person, $bank),
            'An individual must be compared part-by-part.');
        $this->assertLessThan(0.86, PartnerOrg::matchScore($business, $bank),
            'A registered business is still compared as a string — the person rule must not leak.');
    }

    // ───────────────────────────── what is required ─────────────────────────

    public function test_an_individual_is_not_asked_for_a_cac_certificate(): void
    {
        $required = PartnerOrg::requiredDocuments($this->person());

        $this->assertArrayHasKey('id', $required, 'A person is identified by a photo ID.');
        $this->assertArrayHasKey('insurance', $required);
        $this->assertArrayNotHasKey('cac', $required,
            'Asking a sole trader for a CAC certificate makes them borrow somebody else’s.');
    }

    public function test_a_registered_vendor_is_still_asked_for_its_cac(): void
    {
        $required = PartnerOrg::requiredDocuments($this->business());
        $this->assertArrayHasKey('cac', $required);
        $this->assertArrayNotHasKey('id', $required);
    }

    public function test_a_donation_partner_is_unaffected(): void
    {
        $id = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'bf-' . bin2hex(random_bytes(4)), 'name' => 'Bright Futures',
            'kind' => PartnerOrg::KIND_PARTNER, 'status' => PartnerOrg::STATUS_PENDING,
        ]);
        $required = PartnerOrg::requiredDocuments($id);
        $this->assertSame(['cac', 'scuml'], array_keys($required));
    }

    // ──────────────────────────────── approving ─────────────────────────────

    public function test_an_individual_is_approved_without_a_cac_number(): void
    {
        $id = $this->person([
            'subaccount_code'       => 'ACCT_p',
            'account_name_resolved' => 'OKAFOR NGOZI CHIOMA',
        ]);

        $r = PartnerOrg::approve($id, 1);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(PartnerOrg::STATUS_APPROVED, PartnerOrg::find($id)->status);
    }

    /**
     * Because for a person the bank's answer IS the identity check.
     *
     * There is deliberately no NIN column to check instead — opening a Nigerian account
     * requires a BVN, so a resolved name is a regulated institution's identity check, and
     * storing Nigerians' identifiers here would be a liability with no matching use.
     */
    public function test_an_individual_without_a_resolved_account_name_is_refused(): void
    {
        $id = $this->person(['subaccount_code' => 'ACCT_p']);

        $r = PartnerOrg::approve($id, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('bank has not confirmed', $r['message']);
        $this->assertSame(PartnerOrg::STATUS_PENDING, PartnerOrg::find($id)->status);
    }

    public function test_an_individual_without_their_own_name_is_refused(): void
    {
        $id = $this->person([
            'legal_name'            => null,
            'name'                  => '',
            'subaccount_code'       => 'ACCT_p',
            'account_name_resolved' => 'OKAFOR NGOZI CHIOMA',
        ]);

        $r = PartnerOrg::approve($id, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('full legal name', $r['message']);
    }

    public function test_a_registered_vendor_still_needs_its_number(): void
    {
        $id = $this->business([
            'subaccount_code'       => 'ACCT_b',
            'account_name_resolved' => 'ADAEZE FOODS LIMITED',
        ]);

        $r = PartnerOrg::approve($id, 1);
        $this->assertFalse($r['ok'], 'A business claiming to be registered must give its number.');
        $this->assertStringContainsString('CAC registration number', $r['message']);
    }

    // ────────────────────────────── self-registration ───────────────────────

    private function form(array $over = []): array
    {
        return $over + [
            'entity_type'   => PartnerOrg::ENTITY_INDIVIDUAL,
            'name'          => 'Mama Ngozi’s Kitchen',
            'legal_name'    => 'Ngozi Okafor',
            'contact_name'  => 'Ngozi Okafor',
            'contact_email' => 'ngozi-' . bin2hex(random_bytes(4)) . '@example.test',
            'contact_phone' => '08030000000',
            'password'      => 'correct horse battery',
        ];
    }

    public function test_registering_creates_a_draft_vendor_and_an_owner_login(): void
    {
        $in = $this->form();
        $r  = PartnerOrg::registerVendor($in);
        $this->assertTrue($r['ok'], $r['message']);

        $org = PartnerOrg::find($r['org_id']);
        $this->assertSame(PartnerOrg::KIND_VENDOR, $org->kind);
        $this->assertSame(PartnerOrg::ENTITY_INDIVIDUAL, $org->entity_type);
        // Draft, and flagged. Registering buys a place in the queue and nothing else — no
        // money, no public listing, and no stand until somebody has read the record.
        $this->assertSame(PartnerOrg::STATUS_DRAFT, $org->status);
        $this->assertSame(1, (int) $org->self_registered);
        $this->assertFalse(PartnerOrg::canReceive($org));

        $user = OrgAuth::findByEmail($in['contact_email']);
        $this->assertNotNull($user);
        $this->assertSame('owner', $user->role);
        $this->assertSame((int) $org->id, (int) $user->org_id);
    }

    /** An individual is never asked for a number they do not have. */
    public function test_an_individual_needs_no_cac_number_to_register(): void
    {
        $r = PartnerOrg::registerVendor($this->form(['cac_number' => '']));
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertNull(PartnerOrg::find($r['org_id'])->cac_number);
    }

    /** A business claiming registration must say what it is registered as. */
    public function test_a_business_must_give_its_cac_number(): void
    {
        $r = PartnerOrg::registerVendor($this->form([
            'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'name'        => 'Adaeze Foods',
            'legal_name'  => 'Adaeze Foods Limited',
        ]));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('CAC registration number', $r['message']);
    }

    /**
     * A failure must leave nothing behind.
     *
     * An organisation nobody can sign into is a row an administrator eventually approves by
     * accident, and it would be invisible until they did.
     */
    public function test_a_failed_login_creation_rolls_the_organisation_back(): void
    {
        $before = (int) DB::table('gates_partner_orgs')->count();

        $r = PartnerOrg::registerVendor($this->form(['password' => 'short']));
        $this->assertFalse($r['ok']);
        $this->assertSame($before, (int) DB::table('gates_partner_orgs')->count(),
            'A half-built organisation must not survive a failed registration.');
    }

    public function test_an_email_that_already_signs_in_is_refused(): void
    {
        $in = $this->form();
        $this->assertTrue(PartnerOrg::registerVendor($in)['ok']);

        $again = PartnerOrg::registerVendor($this->form(['contact_email' => $in['contact_email']]));
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already has a sign-in', $again['message']);
    }

    /** Two markets, two "Mama's Kitchen"s. Both get a web address. */
    public function test_a_duplicate_trading_name_still_gets_its_own_address(): void
    {
        $a = PartnerOrg::registerVendor($this->form(['name' => 'Mama Kitchen']));
        $b = PartnerOrg::registerVendor($this->form(['name' => 'Mama Kitchen']));

        $this->assertTrue($a['ok'], $a['message']);
        $this->assertTrue($b['ok'], $b['message']);
        $this->assertNotSame(
            PartnerOrg::find($a['org_id'])->slug,
            PartnerOrg::find($b['org_id'])->slug
        );
    }

    /**
     * A draft vendor must be able to sign in.
     *
     * They registered ten seconds ago and the next thing they have to do is upload their
     * certificates. Locking them out at exactly that moment makes self-registration a form
     * that produces nothing.
     */
    public function test_a_freshly_registered_vendor_can_sign_in(): void
    {
        $in = $this->form();
        $this->assertTrue(PartnerOrg::registerVendor($in)['ok']);

        $user = (new OrgAuth())->attempt($in['contact_email'], $in['password'], '203.0.113.9');
        $this->assertNotNull($user, 'A draft vendor that cannot sign in cannot upload anything.');
    }
}
