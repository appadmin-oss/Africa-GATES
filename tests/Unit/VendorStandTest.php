<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{PartnerOrg, StandApplication, StandCall, StandType};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Vendor stands: the rules from VENDOR-STANDS-SPEC §5, under the pressure §10 describes.
 *
 * Nearly every test here is a refusal, and that is the point. Allocating stands is only
 * defensible if the platform can say no to the organiser — no, you cannot edit the criteria
 * now that you have seen who applied; no, you cannot offer an eleventh place in a category
 * of ten; no, you cannot reject somebody without writing down why.
 */
class VendorStandTest extends TestCase
{
    /** Unique per call — see the note in PartnerDonationTest about MySQL isolation. */
    private function makeEvent(): int
    {
        return (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Lagos Market Day', 'slug' => 'market-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+60 days')), 'status' => 'published',
        ]);
    }

    private function makeVendor(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug' => 'adaeze-foods-' . bin2hex(random_bytes(4)),
            'name' => 'Adaeze Foods', 'legal_name' => 'Adaeze Foods',
            'kind' => PartnerOrg::KIND_VENDOR,
            'cac_number' => 'BN9988', 'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_v',
        ]);
    }

    private function doc(int $orgId, string $kind, ?string $expires = null): void
    {
        DB::table('gates_org_documents')->insert([
            'org_id' => $orgId, 'kind' => $kind, 'stored_path' => 'uploads/org-docs/x.pdf',
            'expires_on' => $expires, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The photographs an application needs before it is complete.
     *
     * Rows rather than uploads: what completeness counts is how many are on file, and
     * driving real bytes through the re-encode here would test {@see StandPhotos} a
     * second time instead of testing this.
     */
    private function photos(int $appId, int $orgId, int $n = 3): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_stand_application_photos')->insert([
                'application_id' => $appId, 'org_id' => $orgId,
                'path' => 'uploads/stand-photos/2026/10/p' . $i . '.jpg',
                'width' => 900, 'height' => 700, 'bytes' => 1000,
                'sort_order' => $i, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** An event with one stand type and an open call. */
    private function openCall(int $eventId, array $typeOver = []): array
    {
        $t = StandType::save($eventId, $typeOver + [
            'name' => 'Food pitch', 'category' => 'food', 'price_naira' => '50000', 'quota' => '2',
        ]);
        $this->assertTrue($t['ok'], $t['message'] ?? '');

        $c = StandCall::save($eventId, ['closes_at' => date('Y-m-d H:i:s', strtotime('+14 days'))]);
        $this->assertTrue($c['ok']);
        $o = StandCall::open($c['id'], 1);
        $this->assertTrue($o['ok'], $o['message']);

        return ['call' => $c['id'], 'type' => $t['id']];
    }

    // ─────────────────────── the call, and locking its terms ────────────────

    /**
     * §5.1, and the single most important rule in the specification. Once the call is open,
     * the terms cannot be edited — because a rule you can change after seeing who applied is
     * not a rule.
     */
    public function test_opening_a_call_locks_its_prices_and_quotas(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);

        $edit = StandType::save($e, ['name' => 'Food pitch', 'price_naira' => '5000', 'quota' => '20'], $s['type']);
        $this->assertFalse($edit['ok'], 'A price change while the call is open must be refused.');
        $this->assertStringContainsString('locked', $edit['message']);

        $t = StandType::find($s['type']);
        $this->assertSame(50000, (int) $t->price_naira, 'The published price must survive.');
        $this->assertSame(2, (int) $t->quota);
    }

    /** The terms are copied onto the call, not referenced, so a later edit cannot rewrite history. */
    public function test_the_published_terms_are_snapshotted_at_lock_time(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);

        $crit = StandCall::criteria(StandCall::find($s['call']));
        $this->assertNotSame([], $crit);
        $this->assertSame(2,     $crit['types'][0]['quota']);
        $this->assertSame(50000, $crit['types'][0]['price']);
        $this->assertArrayHasKey('locked_at', $crit);
    }

    public function test_a_call_cannot_open_without_a_closing_date(): void
    {
        $e = $this->makeEvent();
        StandType::save($e, ['name' => 'Food pitch', 'price_naira' => '1000', 'quota' => '1']);
        $c = StandCall::save($e, []);
        $r = StandCall::open($c['id'], 1);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('closing date', $r['message']);
    }

    public function test_a_call_cannot_open_with_nothing_to_apply_for(): void
    {
        $e = $this->makeEvent();
        $c = StandCall::save($e, ['closes_at' => date('Y-m-d H:i:s', strtotime('+7 days'))]);
        $r = StandCall::open($c['id'], 1);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('stand type', $r['message']);
    }

    /** The clock closes a call, not a status somebody remembers to change. */
    public function test_a_past_closing_date_stops_applications_even_while_marked_open(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        DB::table('gates_stand_calls')->where('id', $s['call'])
            ->update(['closes_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);

        $this->assertFalse(StandCall::isAccepting(StandCall::find($s['call'])));

        $v = $this->makeVendor();
        $r = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof']);
        $this->assertFalse($r['ok']);
    }

    /** A quota of zero would mean nobody can ever be allocated one. */
    public function test_a_stand_type_needs_a_real_quota(): void
    {
        $e = $this->makeEvent();
        $r = StandType::save($e, ['name' => 'Ghost pitch', 'price_naira' => '1000', 'quota' => '0']);
        $this->assertFalse($r['ok']);
    }

    /** Deleting a stand type somebody applied for would erase what they applied FOR. */
    public function test_a_stand_type_with_applications_cannot_be_deleted(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();
        StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof']);

        StandCall::close($s['call']);                     // so the open-call guard is not what refuses
        $r = StandType::delete($s['type']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('applied', $r['message']);
    }

    // ────────────────────────────── applying ────────────────────────────────

    public function test_a_vendor_can_apply_once_per_stand_type(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();

        $first = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof and small chops']);
        $this->assertTrue($first['ok']);

        $again = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof']);
        $this->assertFalse($again['ok'], 'A second application for the same stand type is refused.');
        $this->assertSame($first['id'], $again['id'], 'It should point at the existing one.');
    }

    /**
     * Applying for two DIFFERENT types is legitimate — a vendor may take either a food or a
     * craft pitch and is saying so, not trying for two places in one queue.
     *
     * Both types are created BEFORE the call opens, because adding one afterwards is refused
     * — which is the rule under test in test_opening_a_call_locks_its_prices_and_quotas.
     */
    public function test_a_vendor_may_apply_for_two_different_stand_types(): void
    {
        $e = $this->makeEvent();

        $food  = StandType::save($e, ['name' => 'Food pitch',  'category' => 'food',
                                      'price_naira' => '50000', 'quota' => '2']);
        $craft = StandType::save($e, ['name' => 'Craft pitch', 'category' => 'craft',
                                      'price_naira' => '30000', 'quota' => '3']);
        $this->assertTrue($food['ok']);
        $this->assertTrue($craft['ok'], $craft['message'] ?? '');

        $c = StandCall::save($e, ['closes_at' => date('Y-m-d H:i:s', strtotime('+14 days'))]);
        $this->assertTrue(StandCall::open($c['id'], 1)['ok']);

        $v = $this->makeVendor();
        $this->assertTrue(StandApplication::submit($v, $food['id'],  ['what_they_sell' => 'Jollof'])['ok']);
        $this->assertTrue(StandApplication::submit($v, $craft['id'], ['what_they_sell' => 'Beadwork'])['ok']);

        $this->assertCount(2, StandApplication::forOrg($v));
    }

    public function test_saying_what_you_sell_is_required(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();

        $r = StandApplication::submit($v, $s['type'], ['what_they_sell' => '   ']);
        $this->assertFalse($r['ok']);
    }

    public function test_a_suspended_vendor_cannot_apply(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor(['status' => PartnerOrg::STATUS_SUSPENDED]);

        $this->assertFalse(StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['ok']);
    }

    // ───────────────────────────── eligibility ──────────────────────────────

    /** A vendor needs CAC and insurance; SCUML is a donation-partner requirement and not asked for. */
    public function test_vendors_and_donation_partners_need_different_documents(): void
    {
        $vendor  = $this->makeVendor();
        $partner = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'hope-' . bin2hex(random_bytes(4)), 'name' => 'Hope Alive',
            'kind' => PartnerOrg::KIND_PARTNER, 'status' => PartnerOrg::STATUS_APPROVED,
        ]);

        $this->assertSame(['cac', 'insurance'], array_keys(PartnerOrg::requiredDocuments($vendor)));
        $this->assertSame(['cac', 'scuml'],     array_keys(PartnerOrg::requiredDocuments($partner)));
    }

    public function test_missing_documents_fail_the_eligibility_gate(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();
        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];

        $r = StandApplication::checkEligibility($a);
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('insurance', $r['missing']);
        $this->assertSame(StandApplication::ELIGIBILITY_FAIL, StandApplication::find($a)->eligibility);
    }

    /**
     * §10.4 — the certificate that lapsed three weeks out. An expired document is not a
     * document, and this is the whole reason expiries are stored: the vendor is told at
     * confirmation, weeks before the day, rather than at the gate on the morning.
     */
    public function test_an_expired_document_counts_as_missing(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();

        $this->doc($v, 'cac');                                          // never expires
        $this->doc($v, 'insurance', date('Y-m-d', strtotime('-21 days')));   // lapsed

        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];
        $r = StandApplication::checkEligibility($a);

        $this->assertFalse($r['ok'], 'A lapsed policy must not pass.');
        $this->assertArrayHasKey('insurance', $r['missing']);
    }

    public function test_a_complete_vendor_passes_eligibility(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();
        $this->doc($v, 'cac');
        $this->doc($v, 'insurance', date('Y-m-d', strtotime('+300 days')));

        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];
        $this->assertTrue(StandApplication::checkEligibility($a)['ok']);
    }

    /**
     * §5.4 — the tiebreak is the earliest COMPLETE application, so completeness is stamped
     * once and never moved. A vendor must not improve their queue position by re-touching
     * the form.
     */
    public function test_completeness_is_stamped_once_and_does_not_move(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();
        $this->doc($v, 'cac');
        $this->doc($v, 'insurance', date('Y-m-d', strtotime('+300 days')));

        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];
        $this->photos($a, $v);
        $this->assertTrue(StandApplication::refreshCompleteness($a));

        $first = (string) StandApplication::find($a)->completed_at;
        $this->assertNotSame('', $first);

        StandApplication::refreshCompleteness($a);
        $this->assertSame($first, (string) StandApplication::find($a)->completed_at);
    }

    /** An application whose documents arrive later is not complete at submission. */
    public function test_an_incomplete_application_carries_no_completion_stamp(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();

        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];
        $this->assertNull(StandApplication::find($a)->completed_at);

        $this->doc($v, 'cac');
        $this->doc($v, 'insurance', date('Y-m-d', strtotime('+300 days')));
        $this->photos($a, $v);
        $this->assertTrue(StandApplication::refreshCompleteness($a));
        $this->assertNotNull(StandApplication::find($a)->completed_at);
    }

    /**
     * Documents alone no longer complete an application — the photographs count too.
     *
     * The photographs are the only thing on a stand application that SHOWS what every
     * other field claims, and completeness is the §5.4 tiebreak. Pinned here beside the
     * document rules so the two are read as the one shelf they are.
     */
    public function test_an_application_with_every_document_and_no_photographs_is_not_complete(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();
        $this->doc($v, 'cac');
        $this->doc($v, 'insurance', date('Y-m-d', strtotime('+300 days')));

        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];

        $this->assertNull(StandApplication::find($a)->completed_at);
        $this->assertArrayHasKey('stand_photos', StandApplication::missingForCompleteness($a));

        // Two is not three, and the tiebreak does not round up.
        $this->photos($a, $v, 2);
        $this->assertFalse(StandApplication::refreshCompleteness($a));

        $this->photos($a, $v, 1);
        $this->assertTrue(StandApplication::refreshCompleteness($a));
    }

    /**
     * …and it is still ELIGIBLE without them, which is the line that matters.
     *
     * A vendor with every certificate in order and no camera is eligible to trade. Making
     * photographs a rule would refuse them outright, and a refusal is not something a
     * missing photograph should ever produce.
     */
    public function test_an_application_with_no_photographs_is_still_eligible(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();
        $this->doc($v, 'cac');
        $this->doc($v, 'insurance', date('Y-m-d', strtotime('+300 days')));

        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];

        $this->assertTrue(StandApplication::checkEligibility($a)['ok'],
            'a missing photograph refused an application outright');
    }

    // ────────────────────────────── decisions ───────────────────────────────

    private function eligibleApplication(int $eventId, int $typeId): int
    {
        $v = $this->makeVendor();
        $this->doc($v, 'cac');
        $this->doc($v, 'insurance', date('Y-m-d', strtotime('+300 days')));
        $a = StandApplication::submit($v, $typeId, ['what_they_sell' => 'Jollof'])['id'];
        StandApplication::checkEligibility($a);
        return $a;
    }

    /** Eligibility is a GATE. An ineligible application cannot be offered a stand. */
    public function test_an_ineligible_application_cannot_be_offered(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $v = $this->makeVendor();
        $a = StandApplication::submit($v, $s['type'], ['what_they_sell' => 'Jollof'])['id'];
        StandApplication::checkEligibility($a);

        $r = StandApplication::offer($a, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('eligibility', $r['message']);
    }

    /**
     * §5.2 and §10.1 — the quota was published before anybody applied, so an eleventh place
     * in a category of ten cannot be offered. This is the test that makes the quota a
     * constraint rather than a suggestion.
     */
    public function test_the_published_quota_cannot_be_exceeded(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);                       // quota of 2

        $a1 = $this->eligibleApplication($e, $s['type']);
        $a2 = $this->eligibleApplication($e, $s['type']);
        $a3 = $this->eligibleApplication($e, $s['type']);

        $this->assertTrue(StandApplication::offer($a1, 1)['ok']);
        $this->assertTrue(StandApplication::offer($a2, 1)['ok']);

        $third = StandApplication::offer($a3, 1);
        $this->assertFalse($third['ok'], 'The third offer exceeds a quota of two.');
        $this->assertStringContainsString('quota', $third['message']);
    }

    /** An outstanding OFFER holds a place — otherwise an organiser over-offers and hopes. */
    public function test_an_outstanding_offer_holds_a_place(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $a1 = $this->eligibleApplication($e, $s['type']);
        StandApplication::offer($a1, 1);

        $cap = StandCall::capacity($e);
        $this->assertSame(1, $cap[0]['taken']);
        $this->assertSame(1, $cap[0]['left']);
    }

    /** §5.7 — every applicant gets a reason, enforced by making the record unwritable without one. */
    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $a = $this->eligibleApplication($e, $s['type']);

        $bad = StandApplication::decide($a, StandApplication::DECISION_REJECTED, 1, '  ');
        $this->assertFalse($bad['ok']);

        $good = StandApplication::decide($a, StandApplication::DECISION_REJECTED, 1,
            'Eleventh of thirty-four in a category with ten places.');
        $this->assertTrue($good['ok']);
        $this->assertStringContainsString('Eleventh', (string) StandApplication::find($a)->decision_reason);
    }

    // ───────────────────────────── the offer window ─────────────────────────

    public function test_a_vendor_can_accept_an_open_offer(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $a = $this->eligibleApplication($e, $s['type']);
        StandApplication::offer($a, 1);

        $orgId = (int) StandApplication::find($a)->org_id;
        $this->assertTrue(StandApplication::accept($a, $orgId)['ok']);
        $this->assertSame(StandApplication::DECISION_ACCEPTED, StandApplication::find($a)->decision);
    }

    /** One vendor must never be able to accept another's offer. */
    public function test_a_vendor_cannot_accept_somebody_elses_offer(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $a = $this->eligibleApplication($e, $s['type']);
        StandApplication::offer($a, 1);

        $intruder = $this->makeVendor();
        $r = StandApplication::accept($a, $intruder);
        $this->assertFalse($r['ok']);
        $this->assertSame(StandApplication::DECISION_OFFERED, StandApplication::find($a)->decision);
    }

    /** An expired offer cannot be accepted, and the place returns to the waiting list. */
    public function test_an_expired_offer_is_refused_and_released(): void
    {
        $e = $this->makeEvent();
        $s = $this->openCall($e);
        $a = $this->eligibleApplication($e, $s['type']);
        StandApplication::offer($a, 1);

        DB::table('gates_stand_applications')->where('id', $a)
            ->update(['offer_expires_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);

        $orgId = (int) StandApplication::find($a)->org_id;
        $this->assertFalse(StandApplication::accept($a, $orgId)['ok']);

        $this->assertSame(1, StandApplication::expireStaleOffers());
        $this->assertSame(StandApplication::DECISION_WAITLIST, StandApplication::find($a)->decision);

        // And the place is available again, which is what the waiting list was promised.
        $this->assertSame(2, StandCall::capacity($e)[0]['left']);
    }
}
