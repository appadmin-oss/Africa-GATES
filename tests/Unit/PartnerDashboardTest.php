<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{OrgAuth, PartnerOrg};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The partner dashboard — one page serving two very different accounts.
 *
 * A vendor and a donation partner sign in to the same template, and what separates them is
 * which sections have anything in them. That is the right design and it is also the thing
 * most likely to go quietly wrong: a rail entry rendered for an account it does not apply to
 * is a door onto an empty room, and one MISSING for an account that needs it is a feature the
 * user cannot find at all. So these tests are mostly about which doors exist for whom.
 */
class PartnerDashboardTest extends TestCase
{
    private function ctrl(): \AfricaGates\Controllers\OrgDashboardController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Controllers\OrgDashboardController::class);
    }

    private function org(string $kind = PartnerOrg::KIND_PARTNER, array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug' => 'org-' . bin2hex(random_bytes(4)),
            'name' => 'Bright Futures', 'legal_name' => 'Bright Futures Ltd',
            'kind' => $kind, 'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'cac_number' => 'RC/1234567', 'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_x', 'contact_phone' => '08031234567',
        ]);
    }

    private function signIn(int $orgId): void
    {
        $email = 'owner-' . bin2hex(random_bytes(3)) . '@example.test';
        OrgAuth::createUser($orgId, $email, 'a-very-long-password', 'Owner', 'owner');
        $_SESSION['org_user_id'] = (int) DB::table('gates_org_users')->where('email', $email)->value('id');
        $_SESSION['org_id']      = $orgId;
    }

    private function docs(int $orgId): void
    {
        foreach (array_keys(PartnerOrg::requiredDocuments($orgId)) as $kind) {
            DB::table('gates_org_documents')->insert([
                'org_id' => $orgId, 'kind' => $kind, 'stored_path' => 'uploads/org-docs/x.pdf',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function gift(int $orgId, int $naira, string $when): void
    {
        DB::table('gates_donations')->insert([
            'recipient_org_id' => $orgId, 'donor_name' => 'A supporter',
            'donor_email' => 'd@example.test', 'amount_naira' => $naira,
            'platform_fee_naira' => (int) round($naira * 0.05), 'show_name' => 1,
            'status' => 'confirmed', 'payment_ref' => 'AGD-' . bin2hex(random_bytes(4)),
            'created_at' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['org_user_id'], $_SESSION['org_id'],
              $_SESSION['org_flash_ok'], $_SESSION['org_flash_error']);
        parent::tearDown();
    }

    private function page(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/org');
        return (string) $this->ctrl()->dashboard($req, new Response())->getBody();
    }

    /** @return list<string> the section ids the rail offers */
    private function rail(string $html): array
    {
        preg_match_all('~data-pd-go="([a-z]+)"~', $html, $m);
        return array_values(array_unique($m[1]));
    }

    // ────────────────────────────────────────────────────────────────────────

    public function test_the_rail_offers_a_donation_partner_the_money_and_not_the_stands(): void
    {
        $id = $this->org(PartnerOrg::KIND_PARTNER);
        $this->signIn($id);
        $this->docs($id);

        $rail = $this->rail($this->page());
        $this->assertContains('overview', $rail);
        $this->assertContains('donations', $rail);
        $this->assertContains('appeals', $rail);
        $this->assertContains('payouts', $rail);
        $this->assertNotContains('fees', $rail, 'stand fees are not a donation partner\'s business');
    }

    public function test_the_rail_offers_a_vendor_the_stands_and_not_the_appeals(): void
    {
        // A vendor with no donations has no appeal to run and no payout to request. Showing
        // those anyway is three doors onto empty rooms on the account of somebody who is
        // trying to find out whether their certificates went through.
        $id = $this->org(PartnerOrg::KIND_VENDOR);
        $this->signIn($id);

        $rail = $this->rail($this->page());
        $this->assertContains('overview', $rail);
        $this->assertContains('applications', $rail);
        $this->assertContains('fees', $rail);
        $this->assertNotContains('appeals', $rail);
        $this->assertNotContains('donations', $rail);
    }

    public function test_a_vendor_who_later_takes_donations_gets_both(): void
    {
        // The template branches on CONTENT rather than on `kind`, which is what makes this
        // work: a vendor who starts fundraising does not need somebody to change a column.
        $id = $this->org(PartnerOrg::KIND_VENDOR);
        $this->signIn($id);
        $this->gift($id, 50000, '-10 days');

        $rail = $this->rail($this->page());
        $this->assertContains('applications', $rail);
        $this->assertContains('donations', $rail);
    }

    public function test_the_section_switch_needs_no_framework_and_no_javascript(): void
    {
        $id = $this->org();
        $this->signIn($id);
        $html = $this->page();

        $this->assertStringContainsString('data-pd', $html);
        $this->assertMatchesRegularExpression('~\[data-pd\]\s*\.pd-sec\{\s*display:none~', $html);
        $this->assertMatchesRegularExpression('~\[data-pd="donations"\]\s*#donations~', $html);
        // Every hide depends on the attribute, so a reader with scripting off sees the long
        // scroll this replaced rather than one section and no way to the other five.
        $this->assertSame(
            substr_count($html, '.pd-sec{ display:none'),
            substr_count($html, '[data-pd] .pd-sec{ display:none'),
        );
    }

    public function test_the_money_chart_appears_only_once_there_is_money(): void
    {
        $id = $this->org();
        $this->signIn($id);
        $this->assertStringNotContainsString('Received, last 90 days', $this->page(),
            'a graph of nothing, shown to the partner still waiting on a review');

        $this->gift($id, 120000, '-40 days');
        $this->gift($id, 80000, '-6 days');
        $html = $this->page();
        $this->assertStringContainsString('Received, last 90 days', $html);
        $this->assertStringContainsString('class="viz__line"', $html);
        // The same component the account page and the stands admin use.
        $this->assertStringContainsString('id="viz-money-tbl"', $html);
    }

    public function test_the_chart_counts_the_partners_share_and_not_the_gross(): void
    {
        // The figure that matters to a fundraiser is what reaches their account. Charting the
        // gross would overstate every total on the page by the platform's fee.
        $id = $this->org();
        $this->signIn($id);
        $this->gift($id, 100000, '-3 days');

        $html = $this->page();
        // 100,000 less the 5% fee. Read out of the chart's own table.
        $this->assertStringContainsString('₦95,000', $html);
    }

    public function test_the_rail_says_what_is_outstanding_in_a_word(): void
    {
        // "2" beside Documents reads as two filed. The count has to carry its own verb.
        $id = $this->org(PartnerOrg::KIND_VENDOR);
        $this->signIn($id);

        $html = $this->page();
        $this->assertMatchesRegularExpression('~is-todo[^>]*>\s*\d+ to do~', $html,
            'and it is marked as outstanding, not merely counted');

        // File everything, and the badge stops shouting rather than reading "0 to do".
        $this->docs($id);
        $after = $this->page();
        $this->assertSame(0, preg_match('~is-todo[^>]*>\s*\d+ to do~', $after));
        $this->assertMatchesRegularExpression('~pd-rail__c[^>]*>\s*done~', $after);
    }

    public function test_the_page_draws_its_icons_rather_than_borrowing_them_from_a_font(): void
    {
        $id = $this->org();
        $this->signIn($id);
        $html = $this->page();
        $at   = strpos($html, '<div class="pd"');
        $mine = $at !== false ? substr($html, $at) : $html;

        $this->assertSame(0, preg_match('~[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]~u', $mine),
            'an emoji is a font lookup, and several useful ones are simply missing');
        $this->assertStringContainsString('class="ico"', $mine, 'the shared coloured set');
    }
}
