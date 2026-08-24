<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{OrgCampaign, PartnerOrg};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Fundraising attached to an event.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS ACTUALLY MISSING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Events sold tickets and that was all. Fundraising existed and worked — OrgCampaign runs a
 * proper appeal with a target, a review workflow and a total summed from confirmed gifts —
 * but nothing connected the two. An organiser running a fundraising dinner had a ticket page
 * and an appeal page that did not know about each other, and the event page never asked.
 *
 * The join is a nullable column rather than a second table, because an event appeal IS a
 * campaign: same title, story, target, dates, workflow and total. A second table would have
 * been a copy of all of it plus a second progress calculation that would drift from the
 * first — and a fundraising bar that disagrees with the ledger is worse than no bar, because
 * it is the number people screenshot.
 */
final class EventFundraisingTest extends TestCase
{
    private int $orgId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_org_campaigns')->delete();
        DB::table('gates_donations')->delete();

        $this->orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'trust-' . bin2hex(random_bytes(4)),
            'name' => 'Enugu Water Trust', 'legal_name' => 'Enugu Water Trust Limited',
            'kind' => PartnerOrg::KIND_PARTNER,
            'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_t',
        ]);
    }

    private function event(string $when = '+30 days', string $status = 'published'): int
    {
        return (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Fundraising Dinner', 'slug' => 'dinner-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime($when)), 'status' => $status,
        ]);
    }

    private function appeal(array $over = []): int
    {
        $r = OrgCampaign::save($this->orgId, $over + [
            'title'        => 'Nine more boreholes',
            'summary'      => 'One borehole serves about four hundred people.',
            'story'        => 'We have built nine and the community maintains them.',
            'target_naira' => '500000',
        ]);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        return (int) $r['id'];
    }

    private function gift(int $campaignId, int $naira, string $status = 'confirmed'): void
    {
        DB::table('gates_donations')->insert([
            'campaign_id' => $campaignId,
            'donor_name' => 'A Supporter', 'donor_email' => 'giver@example.test',
            'amount_naira' => $naira, 'platform_fee_naira' => 0,
            'status' => $status, 'payment_ref' => 'D' . bin2hex(random_bytes(5)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ══ the join ═════════════════════════════════════════════════════════════

    public function test_an_appeal_can_name_the_event_it_is_raising_for(): void
    {
        $ev = $this->event();
        $id = $this->appeal(['event_id' => $ev]);

        $this->assertSame($ev, (int) OrgCampaign::find($id)->event_id);
    }

    public function test_an_appeal_with_no_event_is_the_normal_case(): void
    {
        // Most appeals are not about an event and most events raise nothing. The column is
        // nullable and the event page is empty for almost everybody.
        $id = $this->appeal();

        $this->assertNull(OrgCampaign::find($id)->event_id);
        $this->assertSame([], OrgCampaign::forEvent($this->event()));
    }

    public function test_an_event_id_pointing_at_nothing_is_discarded(): void
    {
        // It arrives from a form. Stored as-is, the appeal would look correctly configured
        // on the organisation's own screen and appear on no page at all.
        $id = $this->appeal(['event_id' => 987654]);

        $this->assertNull(OrgCampaign::find($id)->event_id);
    }

    // ══ what the event page shows ════════════════════════════════════════════

    public function test_a_live_appeal_appears_on_its_event_with_its_progress(): void
    {
        $ev = $this->event();
        $id = $this->appeal(['event_id' => $ev]);

        DB::table('gates_org_campaigns')->where('id', $id)
            ->update(['status' => OrgCampaign::STATUS_LIVE]);

        $this->gift($id, 120_000);
        $this->gift($id, 30_000);

        $found = OrgCampaign::forEvent($ev);
        $this->assertCount(1, $found);
        $this->assertSame(150_000, $found[0]['progress']['raised']);
        $this->assertSame(500_000, $found[0]['progress']['target']);
        $this->assertSame(30, $found[0]['progress']['pct']);
        $this->assertSame(2, $found[0]['progress']['count']);
        $this->assertStringContainsString('/donate/', $found[0]['url']);
    }

    public function test_an_unconfirmed_gift_is_not_counted(): void
    {
        // The bar is the number people screenshot. Counting a pending payment would show a
        // total the ledger does not have, and a card that later fails would take it away.
        $ev = $this->event();
        $id = $this->appeal(['event_id' => $ev]);
        DB::table('gates_org_campaigns')->where('id', $id)
            ->update(['status' => OrgCampaign::STATUS_LIVE]);

        $this->gift($id, 100_000, 'pending');
        $this->gift($id, 50_000, 'confirmed');

        $this->assertSame(50_000, OrgCampaign::forEvent($ev)[0]['progress']['raised']);
    }

    public function test_a_draft_appeal_never_reaches_the_event_page(): void
    {
        // A draft is somebody's unfinished writing. Asking a stranger for money with it is
        // worse than showing nothing.
        $ev = $this->event();
        $this->appeal(['event_id' => $ev]);

        $this->assertSame([], OrgCampaign::forEvent($ev));
    }

    public function test_a_closed_appeal_drops_off_the_event_page(): void
    {
        $ev = $this->event();
        $id = $this->appeal(['event_id' => $ev]);
        DB::table('gates_org_campaigns')->where('id', $id)
            ->update(['status' => OrgCampaign::STATUS_CLOSED]);

        $this->assertSame([], OrgCampaign::forEvent($ev));
    }

    public function test_an_appeal_past_its_closing_date_drops_off_without_anybody_closing_it(): void
    {
        // isOpen() reads the dates as well as the status, so nobody has to remember.
        $ev = $this->event();
        $id = $this->appeal(['event_id' => $ev]);
        DB::table('gates_org_campaigns')->where('id', $id)->update([
            'status'    => OrgCampaign::STATUS_LIVE,
            'closes_on' => date('Y-m-d', strtotime('-2 days')),
        ]);

        $this->assertSame([], OrgCampaign::forEvent($ev));
    }

    public function test_one_appeal_does_not_leak_onto_another_event(): void
    {
        $a = $this->event();
        $b = $this->event();
        $id = $this->appeal(['event_id' => $a]);
        DB::table('gates_org_campaigns')->where('id', $id)
            ->update(['status' => OrgCampaign::STATUS_LIVE]);

        $this->assertCount(1, OrgCampaign::forEvent($a));
        $this->assertSame([], OrgCampaign::forEvent($b));
    }

    // ══ the page itself ══════════════════════════════════════════════════════

    public function test_the_event_page_renders_the_appeal_and_never_a_payment_form(): void
    {
        // It links to the appeal, which is where the money actually moves and where the
        // refund terms live. A second payment path on the event page would be a second
        // place for those terms to be wrong.
        $html = file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertStringContainsString('ed-fund', $html);
        $this->assertStringContainsString('Give to this appeal', $html);
        $this->assertStringContainsString('{{ a.url }}', $html);
        // And it says plainly that a ticket is not a gift, because somebody who has just
        // bought one will otherwise read the bar as including it.
        $this->assertStringContainsString('A ticket is not a donation', $html);
    }

    public function test_the_bar_carries_a_text_alternative(): void
    {
        // A progress bar that is only a coloured div tells a screen reader nothing, and the
        // figure is the entire content.
        $html = file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('raised of a', $html);
    }

    public function test_only_upcoming_events_are_offered_to_attach_to(): void
    {
        // An appeal attached to last year's event is asking an empty room, and offering it
        // in the picker invites the mistake rather than preventing it.
        $c = file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/OrgDashboardController.php');

        $this->assertStringContainsString('fundableEvents', $c);
        $this->assertStringContainsString("where('event_date', '>=', date('Y-m-d H:i:s'))", $c);
        $this->assertStringContainsString("where('status', 'published')", $c);
    }

    public function test_the_total_is_summed_and_never_cached(): void
    {
        // There is no raised_naira column anywhere, by design. A cached total on a
        // fundraising page is a number that drifts from the rows behind it.
        $this->assertFalse(DB::schema()->hasColumn('gates_org_campaigns', 'raised_naira'));
    }
}
