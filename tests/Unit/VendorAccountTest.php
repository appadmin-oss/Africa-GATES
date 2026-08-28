<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{OrgAuth, PartnerOrg, StandApplication, StandCall, StandType,
                          VendorAccount, VendorCatalogue, VendorPolicy};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A vendor, managed from the page a trader actually opens.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `/org` was built for a DONATION PARTNER: totals from confirmed gifts, appeals, payout
 * schedules, settlement accounts. A stand vendor has none of that and never will — they PAY
 * for a pitch rather than receiving anything through it — so most of that screen is another
 * product's interface, and what is left is a console a market trader has to remember a
 * second password for.
 *
 * They already have an account here. The stall now lives on it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE LINK IS THE SECURITY-SENSITIVE PART
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A vendor account holds a settlement account, uploaded registration certificates, and the
 * power to accept a pitch and be charged for it. It is reached from a member account by
 * matching the EMAIL — so if an unverified address counted, a sign-up form would be the
 * whole exploit. Most of what is below is about that one property.
 */
final class VendorAccountTest extends TestCase
{
    // ───────────────────────────────── fixtures ─────────────────────────────

    /**
     * `gates_users.email` is UNIQUE, and several tests here need the SAME address twice —
     * once verified and once not. Upserted rather than inserted for that reason.
     */
    private function member(string $email, bool $verified = true): object
    {
        $existing = DB::table('gates_users')->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if ($existing) {
            DB::table('gates_users')->where('id', $existing->id)
              ->update(['email_verified' => $verified ? 1 : 0]);
            return DB::table('gates_users')->where('id', $existing->id)->first();
        }

        $id = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Adaeze', 'email' => $email, 'status' => 'active',
            'email_verified' => $verified ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return DB::table('gates_users')->where('id', $id)->first();
    }

    private function vendorOrg(string $ownerEmail, string $role = 'owner'): int
    {
        $orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'ada-' . bin2hex(random_bytes(4)),
            'name' => 'Adaeze Foods', 'legal_name' => 'Adaeze Foods Limited',
            'kind' => PartnerOrg::KIND_VENDOR, 'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'cac_number' => 'BN9988', 'status' => PartnerOrg::STATUS_APPROVED,
            'contact_email' => $ownerEmail,
        ]);
        DB::table('gates_org_users')->insert([
            'org_id' => $orgId, 'email' => $ownerEmail, 'name' => 'Adaeze',
            'password_hash' => password_hash('x' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'role' => $role, 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $orgId;
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE LINK
    // ════════════════════════════════════════════════════════════════════════

    public function test_a_verified_member_reaches_their_vendor_account(): void
    {
        $email = 'ada@example.test';
        $orgId = $this->vendorOrg($email);

        $links = VendorAccount::forMember($this->member($email));
        $this->assertCount(1, $links);
        $this->assertSame($orgId, (int) $links[0]['org']->id);
        $this->assertTrue($links[0]['is_owner']);
    }

    /**
     * The load-bearing guard.
     *
     * An unverified address is an address somebody typed. If it counted, registering with
     * a trader's email would hand over their settlement account, their certificates and the
     * power to accept a charge — a takeover with a sign-up form as the exploit.
     */
    public function test_an_unverified_member_reaches_nothing(): void
    {
        $email = 'ada2@example.test';
        $this->vendorOrg($email);

        $this->assertSame([], VendorAccount::forMember($this->member($email, false)),
            'an unverified address linked to a vendor account');
        $this->assertNull(VendorAccount::primary($this->member($email, false)));
        $this->assertNull(VendorAccount::panel($this->member($email, false)));
    }

    /** A member with no vendor account gets no section rather than an empty one. */
    public function test_an_ordinary_member_has_no_vendor_panel(): void
    {
        $this->assertNull(VendorAccount::panel($this->member('nobody@example.test')));
    }

    public function test_the_match_ignores_case_the_way_the_rest_of_the_platform_does(): void
    {
        $orgId = $this->vendorOrg('Trader@Example.Test');
        $links = VendorAccount::forMember($this->member('trader@example.test'));

        $this->assertCount(1, $links);
        $this->assertSame($orgId, (int) $links[0]['org']->id);
    }

    /** A deactivated org login is not a way in. */
    public function test_a_deactivated_org_login_does_not_link(): void
    {
        $email = 'gone@example.test';
        $orgId = $this->vendorOrg($email);
        DB::table('gates_org_users')->where('org_id', $orgId)->update(['is_active' => 0]);

        $this->assertSame([], VendorAccount::forMember($this->member($email)));
    }

    // ════════════════════════════════════════════════════════════════════════
    // WHO MAY CHANGE ANYTHING
    // ════════════════════════════════════════════════════════════════════════

    /**
     * A `viewer` reads the dashboard and changes nothing, on EITHER route.
     *
     * The role check has to hold on the member path as well as the org path, or the account
     * page becomes a way around a permission the console enforces.
     */
    public function test_a_viewer_cannot_write_from_either_session(): void
    {
        $email = 'view@example.test';
        $this->vendorOrg($email, 'viewer');
        $member = $this->member($email);

        $this->assertSame(0, VendorAccount::writableOrgId(null, $member),
            'a viewer got write access through the member session');

        // And the panel still renders — read-only is a real state, not a hidden one.
        $panel = VendorAccount::panel($member);
        $this->assertIsArray($panel);
        $this->assertFalse($panel['is_owner']);
    }

    public function test_an_owner_may_write_from_the_member_session(): void
    {
        $email = 'own@example.test';
        $orgId = $this->vendorOrg($email);

        $this->assertSame($orgId, VendorAccount::writableOrgId(null, $this->member($email)));
    }

    /** A signed-out visitor writes nothing. */
    public function test_nobody_signed_in_may_write(): void
    {
        $this->assertSame(0, VendorAccount::writableOrgId(null, null));
    }

    /** The ORG session still works — this adds a route, it does not replace one. */
    public function test_the_org_session_is_unaffected(): void
    {
        $email = 'both@example.test';
        $orgId = $this->vendorOrg($email);
        $orgUser = OrgAuth::findByEmail($email);

        $this->assertSame($orgId, VendorAccount::writableOrgId($orgUser, null));
    }

    // ════════════════════════════════════════════════════════════════════════
    // WHAT THE PANEL SAYS
    // ════════════════════════════════════════════════════════════════════════

    public function test_the_panel_surfaces_a_live_offer_separately_from_everything_else(): void
    {
        $email = 'off@example.test';
        $orgId = $this->vendorOrg($email);

        $event = DB::table('gates_site_events')->insertGetId([
            'title' => 'Market Day', 'slug' => 'md-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+40 days')), 'status' => 'published',
        ]);
        $t = StandType::save((int) $event, [
            'name' => 'Food pitch', 'category' => 'food', 'price_naira' => '35000',
            'quota' => '2', 'size_preset' => '3x3',
        ]);
        $c = StandCall::save((int) $event, ['closes_at' => date('Y-m-d H:i:s', strtotime('+9 days'))]);
        StandCall::open($c['id'], 1);

        foreach (array_keys(PartnerOrg::requiredDocuments($orgId)) as $kind) {
            DB::table('gates_org_documents')->insert([
                'org_id' => $orgId, 'kind' => $kind, 'stored_path' => 'uploads/org-docs/x.pdf',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $app = StandApplication::submit($orgId, (int) $t['id'], ['what_they_sell' => 'Jollof.']);
        StandApplication::checkEligibility((int) $app['id']);
        StandApplication::offer((int) $app['id'], 1);

        $panel = VendorAccount::panel($this->member($email));
        $this->assertCount(1, $panel['applications']);
        $this->assertCount(1, $panel['live_offers'],
            'the one thing with a deadline on it is not surfaced');
        $this->assertMatchesRegularExpression('~^[a-f0-9]{48}$~',
            (string) $panel['live_offers'][0]['token'],
            'the offer has no link, so the panel can only send them to a sign-in form');
    }

    /** An offer whose clock has already run out is NOT a live offer. */
    public function test_an_expired_offer_is_not_counted_as_live(): void
    {
        $email = 'exp@example.test';
        $orgId = $this->vendorOrg($email);

        $event = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Market Day', 'slug' => 'md-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+40 days')), 'status' => 'published',
        ]);
        $t = StandType::save($event, ['name' => 'Pitch', 'category' => 'food',
                                      'price_naira' => '1000', 'quota' => '1']);
        $c = StandCall::save($event, ['closes_at' => date('Y-m-d H:i:s', strtotime('+9 days'))]);
        StandCall::open($c['id'], 1);
        foreach (array_keys(PartnerOrg::requiredDocuments($orgId)) as $kind) {
            DB::table('gates_org_documents')->insert([
                'org_id' => $orgId, 'kind' => $kind, 'stored_path' => 'uploads/org-docs/x.pdf',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $app = StandApplication::submit($orgId, (int) $t['id'], ['what_they_sell' => 'Rice.']);
        StandApplication::checkEligibility((int) $app['id']);
        StandApplication::offer((int) $app['id'], 1);

        DB::table('gates_stand_applications')->where('id', (int) $app['id'])
          ->update(['offer_expires_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);

        $panel = VendorAccount::panel($this->member($email));
        $this->assertSame([], $panel['live_offers'],
            'a run-out offer was shown with an accept button, which can only fail');
        $this->assertCount(1, $panel['applications']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE CATALOGUE
    // ════════════════════════════════════════════════════════════════════════

    public function test_the_catalogue_belongs_to_the_business_and_not_to_one_application(): void
    {
        $orgId = $this->vendorOrg('cat@example.test');

        $r = VendorCatalogue::save($orgId, 0, ['name' => 'Jollof rice, one plate',
                                               'category' => 'food', 'price_naira' => '1,500']);
        $this->assertTrue($r['ok'], $r['message'] ?? '');

        $items = VendorCatalogue::forOrg($orgId);
        $this->assertCount(1, $items);
        $this->assertSame(1500, (int) $items[0]->price_naira, 'the comma broke the price');
        $this->assertSame('food', (string) $items[0]->category);
    }

    /**
     * No price is "Ask at the stand", never ₦0.
     *
     * A column that defaults to zero prints "Free" beside a bag of rice for everybody who
     * has not decided yet, and "not saying" is a real answer at a market.
     */
    public function test_an_item_with_no_price_says_so_rather_than_saying_free(): void
    {
        $orgId = $this->vendorOrg('noprice@example.test');
        VendorCatalogue::save($orgId, 0, ['name' => 'Ankara headwrap']);

        $item = VendorCatalogue::forOrg($orgId)[0];
        $this->assertNull($item->price_naira);
        $this->assertSame('Ask at the stand', VendorCatalogue::priceLabel($item));
    }

    /**
     * A category outside the admin's own list is refused.
     *
     * Stands are allocated against published category quotas, so an item filed under a
     * category the organiser does not recognise is an item the quota cannot see — and a
     * vendor typing "streetfood" would quietly fall out of the food quota.
     */
    public function test_a_category_the_quota_cannot_count_is_refused(): void
    {
        $orgId = $this->vendorOrg('badcat@example.test');
        $r = VendorCatalogue::save($orgId, 0, ['name' => 'Suya', 'category' => 'streetfood']);

        $this->assertFalse($r['ok']);
        $this->assertSame('category', $r['field']);
        $this->assertStringContainsString('quota', strtolower($r['message']));
    }

    public function test_every_shipped_category_is_actually_accepted(): void
    {
        $orgId = $this->vendorOrg('allcat@example.test');
        foreach (array_keys(VendorPolicy::categories()) as $slug) {
            $r = VendorCatalogue::save($orgId, 0, ['name' => 'Item ' . $slug, 'category' => $slug]);
            $this->assertTrue($r['ok'], "the shipped category '{$slug}' was refused");
        }
    }

    /** One vendor cannot edit another's line, even holding its id. */
    public function test_an_item_belonging_to_somebody_else_cannot_be_touched(): void
    {
        $mine   = $this->vendorOrg('mine@example.test');
        $theirs = $this->vendorOrg('theirs@example.test');

        $r = VendorCatalogue::save($theirs, 0, ['name' => 'Their jollof']);
        $id = (int) $r['id'];

        foreach ([VendorCatalogue::save($mine, $id, ['name' => 'Stolen']),
                  VendorCatalogue::delete($mine, $id),
                  VendorCatalogue::setAvailable($mine, $id, false)] as $attempt) {
            $this->assertFalse($attempt['ok'], 'one vendor reached another vendor row');
        }

        $this->assertSame('Their jollof', (string) VendorCatalogue::find($id)->name);
    }

    /**
     * Sold out is recorded against the line rather than removing it.
     *
     * ── AND THE READER THAT USED TO BE ASSERTED HERE ─────────────────────────
     *
     * This used to end on `VendorCatalogue::publicFor()`, "a sold-out line was shown to
     * somebody deciding whether to make the trip". Nothing called publicFor(), and its
     * sibling forOrgs() — a whole event's accepted vendors — was never called either,
     * because THERE IS NO PUBLIC VENDOR CATALOGUE. The event page carries the call for
     * stands; no surface anywhere shows a visitor what a vendor is bringing.
     *
     * So the toggle a vendor presses on their dashboard is seen by that dashboard and by
     * nobody else, and two readers stood ready for a page that was never built. The audit
     * of 2026-08-27 found them; they are gone, and the flag is what is held here — the
     * line stays in the catalogue, and `is_available` records the truth about it, which
     * is what any such page would need on the day it exists.
     */
    public function test_an_unavailable_item_keeps_its_place_and_records_that_it_is_sold_out(): void
    {
        $orgId = $this->vendorOrg('avail@example.test');
        $id = (int) VendorCatalogue::save($orgId, 0, ['name' => 'Moi moi'])['id'];

        VendorCatalogue::setAvailable($orgId, $id, false);

        $items = VendorCatalogue::forOrg($orgId);
        $this->assertCount(1, $items, 'sold out is not deleted — the line comes back');
        $this->assertSame(0, (int) $items[0]->is_available);
    }

    /**
     * The category mix, which is the number an organiser needs and the paragraph never gave.
     */
    public function test_the_leading_category_is_a_count_and_not_a_verdict(): void
    {
        $orgId = $this->vendorOrg('mix@example.test');
        foreach (['food', 'food', 'food', 'craft'] as $i => $c) {
            VendorCatalogue::save($orgId, 0, ['name' => 'Item ' . $i, 'category' => $c]);
        }

        $this->assertSame('food', VendorCatalogue::leadingCategory($orgId));
        $this->assertSame(['food' => 3, 'craft' => 1], VendorCatalogue::categoryMix($orgId));
    }

    public function test_the_list_stops_at_the_ceiling_rather_than_growing_without_bound(): void
    {
        $orgId = $this->vendorOrg('many@example.test');
        for ($i = 0; $i < VendorCatalogue::MAX_ITEMS; $i++) {
            $this->assertTrue(VendorCatalogue::save($orgId, 0, ['name' => 'Item ' . $i])['ok']);
        }

        $over = VendorCatalogue::save($orgId, 0, ['name' => 'One too many']);
        $this->assertFalse($over['ok']);
        $this->assertStringContainsString('Remove one', $over['message']);
    }
}
