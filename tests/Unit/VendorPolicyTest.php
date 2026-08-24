<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{PartnerOrg, StandType, VendorPolicy};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What a vendor must supply, and what they may sell.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * BOTH WERE CONSTANTS ON A HOST WITH NO SSH, WHICH MEANT UNCHANGEABLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A craft market of twenty market traders had no way to stop demanding company
 * registration certificates — and asking for one somebody cannot get is how they end up
 * borrowing a number that is not theirs. A book fair could not add "publishing" to the
 * trades it sells stands for, and since the quota is set against those categories, the
 * fairness mechanism only ever worked on a list somebody else had chosen.
 *
 * The tests that matter here are the SAFETY ones: defaults that cannot silently relax a
 * compliance requirement, and an identity document nobody can switch off.
 */
final class VendorPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->whereIn('key_name', [
            'vendor_require_cac', 'vendor_require_scuml',
            'vendor_require_insurance', 'vendor_categories',
        ])->delete();
    }

    // ══ defaults ═════════════════════════════════════════════════════════════

    public function test_an_unset_toggle_keeps_the_behaviour_the_platform_already_had(): void
    {
        // Reading an unset setting as "off" would silently drop a compliance requirement on
        // every deployment that has never opened the screen — in an upgrade, with nobody
        // told. That is the failure this whole default-handling exists to prevent.
        $this->assertTrue(VendorPolicy::requireCac());
        $this->assertTrue(VendorPolicy::requireInsurance());

        // SCUML defaults OFF because vendors were never asked for it. Turning it on for
        // everybody in an upgrade would make every existing application incomplete
        // overnight — including accepted ones whose stands are booked.
        $this->assertFalse(VendorPolicy::requireScuml());
    }

    public function test_the_default_list_matches_what_the_code_shipped_with(): void
    {
        // Nothing is seeded: an empty setting means "use what the code says", so a
        // deployment that never opens the screen behaves exactly as before.
        $this->assertSame(VendorPolicy::DEFAULT_CATEGORIES, VendorPolicy::categories());
        $this->assertSame(StandType::CATEGORIES, StandType::categories());
    }

    // ══ the one thing nobody can switch off ══════════════════════════════════

    public function test_a_vendor_always_supplies_something_that_identifies_them(): void
    {
        // Turning off CAC falls back to photo ID rather than to nothing. An event that
        // takes money from the public on behalf of traders it cannot name is not a
        // lighter-touch event, it is an unattributable one, and the first time it matters
        // is a food-safety complaint.
        VendorPolicy::saveRequirements(['cac' => 0, 'scuml' => 0, 'insurance' => 0], 1);

        foreach ([PartnerOrg::ENTITY_INDIVIDUAL, PartnerOrg::ENTITY_BUSINESS] as $entity) {
            $docs = VendorPolicy::documentsFor($entity);
            $this->assertNotSame([], $docs, $entity . ' must still be identifiable');
            $this->assertArrayHasKey('id', $docs, $entity);
        }
    }

    public function test_an_individual_is_never_asked_for_a_cac_number(): void
    {
        // They do not have one by definition, and asking is how somebody borrows a number
        // that is not theirs — which puts the wrong name on the paperwork and on the money.
        VendorPolicy::saveRequirements(['cac' => 1, 'scuml' => 1, 'insurance' => 1], 1);

        $this->assertArrayNotHasKey('cac',
            VendorPolicy::documentsFor(PartnerOrg::ENTITY_INDIVIDUAL));
        $this->assertArrayHasKey('cac',
            VendorPolicy::documentsFor(PartnerOrg::ENTITY_BUSINESS));
    }

    // ══ the toggles actually reach the application ═══════════════════════════

    public function test_turning_cac_off_stops_a_business_being_asked_for_it(): void
    {
        VendorPolicy::saveRequirements(['cac' => 0, 'insurance' => 1], 1);

        $docs = PartnerOrg::vendorDocuments(PartnerOrg::ENTITY_BUSINESS);
        $this->assertArrayNotHasKey('cac', $docs);
        $this->assertArrayHasKey('insurance', $docs);
    }

    public function test_turning_scuml_on_asks_both_kinds_of_vendor_for_it(): void
    {
        VendorPolicy::saveRequirements(['cac' => 1, 'scuml' => 1, 'insurance' => 1], 1);

        foreach ([PartnerOrg::ENTITY_INDIVIDUAL, PartnerOrg::ENTITY_BUSINESS] as $entity) {
            $this->assertArrayHasKey('scuml', PartnerOrg::vendorDocuments($entity), $entity);
        }
    }

    public function test_the_application_form_and_the_completeness_check_cannot_disagree(): void
    {
        // The failure this split exists to prevent: a vendor uploads exactly what the form
        // asked for and is then told the application is incomplete. Both must resolve
        // through the same method.
        VendorPolicy::saveRequirements(['cac' => 0, 'scuml' => 1, 'insurance' => 0], 1);

        $this->assertSame(
            VendorPolicy::documentsFor(PartnerOrg::ENTITY_BUSINESS),
            PartnerOrg::vendorDocuments(PartnerOrg::ENTITY_BUSINESS)
        );
    }

    // ══ categories ═══════════════════════════════════════════════════════════

    public function test_an_organiser_can_add_and_rename_a_trade(): void
    {
        $r = VendorPolicy::saveCategories([
            'food'    => 'Hot food',
            'books'   => 'Books and print',
            ''        => 'Publishing',
        ], 1);

        $this->assertTrue($r['ok'], $r['message']);
        $live = VendorPolicy::categories();

        $this->assertSame('Hot food', $live['food'], 'renaming keeps the slug');
        $this->assertArrayHasKey('publishing', $live, 'a new row gets a slug from its name');
        $this->assertArrayNotHasKey('beauty', $live, 'an omitted category is removed');
    }

    public function test_renaming_never_changes_the_stored_short_name(): void
    {
        // Deriving the slug from the label would orphan every stand type already filed
        // under the old one — and the quotas with them, which is the fairness mechanism.
        VendorPolicy::saveCategories(['food' => 'Street food and drink'], 1);

        $this->assertArrayHasKey('food', VendorPolicy::categories());
        $this->assertArrayNotHasKey('street-food-and-drink', VendorPolicy::categories());
    }

    public function test_an_empty_list_is_refused(): void
    {
        // A stand form with no categories cannot be filled in at all.
        $r = VendorPolicy::saveCategories(['' => '   '], 1);

        $this->assertFalse($r['ok']);
        $this->assertNotSame([], VendorPolicy::categories());
    }

    public function test_a_corrupt_override_falls_back_rather_than_emptying_the_form(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'vendor_categories'],
            ['value' => 'not json at all', 'updated_at' => date('Y-m-d H:i:s')]
        );

        $this->assertSame(VendorPolicy::DEFAULT_CATEGORIES, VendorPolicy::categories());
    }

    public function test_a_stand_type_can_use_a_category_the_organiser_added(): void
    {
        // The point of the whole change. Validated against the LIVE list — against the
        // constant, a category somebody added would be silently rewritten to 'general' the
        // moment it was used.
        VendorPolicy::saveCategories(VendorPolicy::DEFAULT_CATEGORIES + ['' => 'Publishing'], 1);

        $ev = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Book Fair', 'slug' => 'fair-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+40 days')), 'status' => 'published',
        ]);

        $r = StandType::save($ev, [
            'name' => 'Publisher table', 'category' => 'publishing',
            'price_naira' => '15000', 'quota' => '8', 'size_preset' => '3x3',
        ]);

        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame('publishing', StandType::find((int) $r['id'])->category);
    }

    public function test_every_offered_certificate_is_one_the_platform_can_receive(): void
    {
        // A closed list. Each of these has an upload slot, a label on the form and a place
        // in the completeness check — an invented slug would be a requirement nothing can
        // satisfy, which is an application that can never be completed.
        VendorPolicy::saveRequirements(['cac' => 1, 'scuml' => 1, 'insurance' => 1], 1);

        foreach ([PartnerOrg::ENTITY_INDIVIDUAL, PartnerOrg::ENTITY_BUSINESS] as $entity) {
            foreach (array_keys(VendorPolicy::documentsFor($entity)) as $slug) {
                $this->assertArrayHasKey($slug, VendorPolicy::DOCUMENTS, $entity . '/' . $slug);
            }
        }
    }

    // ══ the screen ═══════════════════════════════════════════════════════════

    public function test_the_screen_shows_what_a_vendor_would_actually_be_asked_for(): void
    {
        // "CAC off" and "so a business uploads photo ID instead" are different sentences,
        // and only the second answers the question somebody is asking.
        $html = file_get_contents(dirname(__DIR__, 2) . '/templates/admin/vendor-policy.twig');

        $this->assertStringContainsString('As it stands, a vendor is asked for', $html);
        $this->assertStringContainsString('preview.individual', $html);
        $this->assertStringContainsString('preview.business', $html);
        // And it warns about the one edit that breaks things.
        $this->assertStringContainsString('changing a short name is not', $html);
    }

    public function test_changing_the_rules_does_not_recheck_accepted_vendors(): void
    {
        // Said on the screen because it is the first thing an organiser will worry about,
        // and the answer is reassuring: their booked stands are not at risk.
        $html = file_get_contents(dirname(__DIR__, 2) . '/templates/admin/vendor-policy.twig');
        $this->assertStringContainsString('are not re-checked', $html);
    }
}
