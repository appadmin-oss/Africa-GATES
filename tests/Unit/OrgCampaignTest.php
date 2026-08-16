<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{OrgCampaign, PartnerOrg};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Appeals, and the fact that money given to one is restricted to it.
 *
 * The tests worth reading are the ones about closing: a donor who followed a link to a
 * specific cause must never have their gift quietly absorbed into a general fund, and an
 * appeal that closed while somebody had the page open must refuse the money at the gateway
 * call rather than at the next page render.
 */
class OrgCampaignTest extends TestCase
{
    private function makeOrg(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug' => 'bright-futures', 'name' => 'Bright Futures Initiative',
            'cac_number' => 'IT/1', 'scuml_number' => 'SC-1',
            'status' => PartnerOrg::STATUS_APPROVED, 'subaccount_code' => 'ACCT_x',
            'platform_fee_bps' => 0,
        ]);
    }

    private function makeCampaign(int $orgId, array $over = []): int
    {
        return (int) DB::table('gates_org_campaigns')->insertGetId($over + [
            'org_id' => $orgId, 'slug' => 'school-roof', 'title' => 'Rebuild the school roof',
            'target_naira' => 2000000, 'shortfall_policy' => 'same_purpose',
            'status' => OrgCampaign::STATUS_LIVE, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function gift(int $orgId, ?int $campaignId, int $amount, string $status = 'confirmed', int $fee = 0): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'D', 'donor_email' => 'd@example.com', 'amount_naira' => $amount,
            'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
            'payment_ref' => 'AFG-GIVE-' . bin2hex(random_bytes(4)), 'status' => $status,
            'recipient_org_id' => $orgId, 'campaign_id' => $campaignId,
            'platform_fee_naira' => $fee, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ────────────────────────────── when it is open ─────────────────────────

    public function test_a_live_campaign_inside_its_dates_is_open(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o, [
            'opens_on'  => date('Y-m-d', strtotime('-2 days')),
            'closes_on' => date('Y-m-d', strtotime('+7 days')),
        ]);
        $this->assertTrue(OrgCampaign::isOpen(OrgCampaign::find($c)));
    }

    /**
     * The clock closes an appeal, not a status somebody has to remember to change. An
     * appeal still marked live a week after its closing date must not take money.
     */
    public function test_a_past_closing_date_closes_it_even_while_still_marked_live(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o, ['closes_on' => date('Y-m-d', strtotime('-1 day'))]);

        $row = OrgCampaign::find($c);
        $this->assertSame(OrgCampaign::STATUS_LIVE, $row->status);
        $this->assertFalse(OrgCampaign::isOpen($row), 'A past closing date must close it.');
        $this->assertSame([], OrgCampaign::openFor($o));
    }

    public function test_a_future_opening_date_is_not_yet_open(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o, ['opens_on' => date('Y-m-d', strtotime('+3 days'))]);
        $this->assertFalse(OrgCampaign::isOpen(OrgCampaign::find($c)));
    }

    public function test_draft_review_and_closed_are_all_shut(): void
    {
        $o = $this->makeOrg();
        foreach ([OrgCampaign::STATUS_DRAFT, OrgCampaign::STATUS_REVIEW, OrgCampaign::STATUS_CLOSED] as $st) {
            DB::table('gates_org_campaigns')->truncate();
            $c = $this->makeCampaign($o, ['status' => $st]);
            $this->assertFalse(OrgCampaign::isOpen(OrgCampaign::find($c)), $st);
        }
    }

    // ─────────────────────────────── progress ───────────────────────────────

    /**
     * Never cached, and confirmed rows only. A pending gift on a progress bar is a number
     * that goes DOWN when the payment fails, which is the one direction a fundraising
     * figure must never move.
     */
    public function test_progress_counts_confirmed_gifts_only(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o);

        $this->gift($o, $c, 500000, 'confirmed', 25000);
        $this->gift($o, $c, 300000, 'confirmed', 15000);
        $this->gift($o, $c, 900000, 'pending');
        $this->gift($o, $c, 400000, 'failed');

        $p = OrgCampaign::progress($c);
        $this->assertSame(800000, $p['raised']);
        $this->assertSame(760000, $p['net']);
        $this->assertSame(2,      $p['count']);
        $this->assertSame(40,     $p['pct']);
        $this->assertFalse($p['met']);
    }

    /** A gift to the general fund is not a gift to an appeal. */
    public function test_general_fund_gifts_do_not_count_towards_an_appeal(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o);

        $this->gift($o, null, 1000000);
        $this->assertSame(0, OrgCampaign::progress($c)['raised']);
        $this->assertSame(1000000, PartnerOrg::totals($o)['gross'], 'It still counts for the organisation.');
    }

    /** One appeal must never show another's money. */
    public function test_progress_is_scoped_to_one_appeal(): void
    {
        $o  = $this->makeOrg();
        $c1 = $this->makeCampaign($o);
        $c2 = $this->makeCampaign($o, ['slug' => 'library-books', 'title' => 'Books']);

        $this->gift($o, $c1, 250000);
        $this->assertSame(250000, OrgCampaign::progress($c1)['raised']);
        $this->assertSame(0,      OrgCampaign::progress($c2)['raised']);
    }

    /**
     * An appeal that beat its target says so. The bar is capped at 100 for its width and
     * the raised figure is not, because a bar overflowing its container is a rendering bug
     * rather than good news.
     */
    public function test_beating_the_target_caps_the_bar_but_not_the_total(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o, ['target_naira' => 100000]);
        $this->gift($o, $c, 250000);

        $p = OrgCampaign::progress($c);
        $this->assertSame(250000, $p['raised']);
        $this->assertSame(100,    $p['pct']);
        $this->assertTrue($p['met']);
    }

    // ───────────────────────────── writing rules ────────────────────────────

    /**
     * The rule that protects donors: a donor gave against the words on the page, so
     * rewriting a LIVE appeal's story or target sends it back for review rather than
     * silently changing what people are giving for.
     */
    public function test_editing_a_live_appeals_story_sends_it_back_for_review(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o, ['story' => 'The roof fell in during the rains.']);

        $r = OrgCampaign::save($o, [
            'title' => 'Rebuild the school roof', 'slug' => 'school-roof',
            'story' => 'Actually we are buying a bus.', 'target_naira' => '2000000',
            'shortfall_policy' => 'same_purpose',
        ], $c);

        $this->assertTrue($r['ok']);
        $this->assertSame(OrgCampaign::STATUS_REVIEW, OrgCampaign::find($c)->status);
    }

    /** A cosmetic edit does not. Sending an appeal back for a typo would teach people not to fix typos. */
    public function test_a_cosmetic_edit_leaves_a_live_appeal_live(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o, ['story' => 'The roof fell in.', 'summary' => 'Old summary']);

        $r = OrgCampaign::save($o, [
            'title' => 'Rebuild the school roof', 'slug' => 'school-roof',
            'story' => 'The roof fell in.', 'summary' => 'A clearer summary',
            'target_naira' => '2000000', 'shortfall_policy' => 'same_purpose',
        ], $c);

        $this->assertTrue($r['ok']);
        $this->assertSame(OrgCampaign::STATUS_LIVE, OrgCampaign::find($c)->status);
    }

    /** Two appeals in one organisation cannot share a slug — a collision sends money astray. */
    public function test_a_duplicate_slug_within_one_organisation_is_refused(): void
    {
        $o = $this->makeOrg();
        $this->makeCampaign($o);

        $r = OrgCampaign::save($o, ['title' => 'Something else', 'slug' => 'school-roof']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already has an appeal', $r['message']);
    }

    /** But two DIFFERENT organisations may both run a "school-roof" appeal. */
    public function test_two_organisations_may_share_a_slug(): void
    {
        $a = $this->makeOrg();
        $b = $this->makeOrg(['slug' => 'hope-alive', 'name' => 'Hope Alive']);
        $this->makeCampaign($a);

        $r = OrgCampaign::save($b, ['title' => 'Rebuild the school roof', 'slug' => 'school-roof']);
        $this->assertTrue($r['ok'], 'Slugs are unique within an organisation, not globally.');
    }

    /**
     * Accented names must fold, not vanish. The naive slug expression deletes them and
     * turns a Yoruba title into a string of hyphens — SlugTest exists for this and caught
     * a copy of it here.
     */
    public function test_an_accented_title_still_makes_a_usable_address(): void
    {
        $o = $this->makeOrg();
        $r = OrgCampaign::save($o, ['title' => 'Ẹ̀kọ́ Reads']);

        $this->assertTrue($r['ok']);
        $slug = (string) OrgCampaign::find($r['id'])->slug;
        $this->assertNotSame('', trim($slug, '-'));
        $this->assertStringContainsString('reads', $slug);
        $this->assertStringNotContainsString('--', $slug);
    }

    public function test_a_closing_date_before_the_opening_date_is_refused(): void
    {
        $o = $this->makeOrg();
        $r = OrgCampaign::save($o, [
            'title' => 'Backwards', 'opens_on' => '2026-06-01', 'closes_on' => '2026-05-01',
        ]);
        $this->assertFalse($r['ok']);
    }

    /** A new appeal is a draft. Nothing an organisation writes goes public by itself. */
    public function test_a_new_appeal_starts_as_a_draft(): void
    {
        $o = $this->makeOrg();
        $r = OrgCampaign::save($o, ['title' => 'New thing']);
        $this->assertSame(OrgCampaign::STATUS_DRAFT, OrgCampaign::find($r['id'])->status);
    }

    /** An appeal for a suspended organisation cannot go live. */
    public function test_publishing_is_refused_while_the_organisation_cannot_receive(): void
    {
        $o = $this->makeOrg(['status' => PartnerOrg::STATUS_SUSPENDED]);
        $c = $this->makeCampaign($o, ['status' => OrgCampaign::STATUS_REVIEW]);

        $r = OrgCampaign::publish($c, 1);
        $this->assertFalse($r['ok']);
        $this->assertSame(OrgCampaign::STATUS_REVIEW, OrgCampaign::find($c)->status);
    }

    // ──────────────────────── the public page's behaviour ───────────────────

    /**
     * The donate FORM only renders when a payment provider is configured — without one the
     * page correctly shows "online giving is being set up" instead. So the two tests that
     * read the form need a key present.
     */
    private function withPaystack(callable $fn): mixed
    {
        $keys = ['PAYSTACK_SECRET_KEY' => 'sk_test_x', 'PAYSTACK_PUBLIC_KEY' => 'pk_test_x'];
        foreach ($keys as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
        try { return $fn(); }
        finally { foreach (array_keys($keys) as $k) { putenv($k); unset($_ENV[$k]); } }
    }

    private function renderDonate(array $args): array
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(\AfricaGates\Controllers\DonationController::class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/donate');
        $res  = $ctrl->page($req, new Response(), $args);
        return [$res->getStatusCode(), (string) $res->getBody()];
    }

    /**
     * The one that matters. Somebody follows a link to a closed appeal and must be TOLD it
     * closed — not silently handed the organisation's general-fund page, which would spend
     * their intention on something they did not choose.
     */
    public function test_a_closed_appeal_404s_rather_than_falling_through_to_the_general_fund(): void
    {
        $o = $this->makeOrg();
        $this->makeCampaign($o, ['status' => OrgCampaign::STATUS_CLOSED]);

        [$code, $html] = $this->renderDonate(['slug' => 'bright-futures', 'campaign' => 'school-roof']);

        $this->assertSame(404, $code);
        $this->assertStringContainsString('closed', strtolower($html));
    }

    public function test_an_open_appeal_renders_its_real_progress(): void
    {
        $o = $this->makeOrg();
        $c = $this->makeCampaign($o);
        $this->gift($o, $c, 500000);

        [$code, $html] = $this->withPaystack(
            fn() => $this->renderDonate(['slug' => 'bright-futures', 'campaign' => 'school-roof']));

        $this->assertSame(200, $code);
        $this->assertStringContainsString('Rebuild the school roof', $html);
        $this->assertStringContainsString('500,000', $html);
        // The shortfall promise is shown BEFORE anybody gives.
        $this->assertStringContainsString('If the target is not reached', $html);
    }

    /** An organisation's own page lists its open appeals so a donor can choose a cause. */
    public function test_the_organisation_page_offers_its_open_appeals(): void
    {
        $o = $this->makeOrg();
        $this->makeCampaign($o);

        [$code, $html] = $this->withPaystack(
            fn() => $this->renderDonate(['slug' => 'bright-futures']));

        $this->assertSame(200, $code);
        $this->assertStringContainsString('/donate/bright-futures/school-roof', $html);
    }
}
