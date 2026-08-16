<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\PartnerOrg;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The admin onboarding screens actually render, and the decisions behind them are gated.
 *
 * A controller and a template that have never been rendered together are two files that
 * happen to be in the same repository. Everything else about partner onboarding is unit
 * tested; this is the test that catches a Twig variable nobody passed.
 */
class PartnerAdminSmokeTest extends TestCase
{
    private function ctrl(): \AfricaGates\Admin\Controllers\PartnerOrgsController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Admin\Controllers\PartnerOrgsController::class);
    }

    private function asAdmin(string $role = 'superadmin'): void
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = $role;
    }

    private function seed(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug' => 'bright-futures', 'name' => 'Bright Futures Initiative',
            'legal_name' => 'Bright Futures Initiative',
            'cac_number' => 'IT/1234567', 'scuml_number' => 'SC-9988',
            'status' => PartnerOrg::STATUS_PENDING,
            'subaccount_code' => 'ACCT_x', 'account_last4' => '6789',
            'settlement_bank' => '058', 'account_name_resolved' => 'BRIGHT FUTURES INITIATIVE',
            'platform_fee_bps' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    public function test_the_list_renders_with_the_partners_public_path(): void
    {
        $this->asAdmin();
        $this->seed();

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/partner-orgs');
        $html = (string) $this->ctrl()->index($req, new Response())->getBody();

        $this->assertStringContainsString('Bright Futures Initiative', $html);
        $this->assertStringContainsString('/donate/bright-futures', $html);
    }

    /** Every section a reviewer needs must be on the one page, or the record is not a record. */
    public function test_the_detail_page_carries_the_whole_vetting_record(): void
    {
        $this->asAdmin();
        $id = $this->seed();

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/partner-orgs/' . $id);
        $html = (string) $this->ctrl()->show($req, new Response(), ['id' => $id])->getBody();

        foreach ([
            'Settlement account', 'Registry checks', 'Certificates', 'Dashboard logins', 'Decision',
            'IT/1234567',                       // the number itself
            'search.cac.gov.ng',                // the one-click manual check
            'BRIGHT FUTURES INITIATIVE',        // what the BANK said the account name is
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing from the vetting record: $needle");
        }
    }

    /**
     * The name comparison is the anti-impersonation signal, so it has to be legible on the
     * page rather than buried. A weak match says so in words, not only in a colour.
     */
    public function test_a_weak_name_match_is_called_out_on_the_page(): void
    {
        $this->asAdmin();
        $id = $this->seed(['account_name_resolved' => 'ADEBAYO JOHN OLUWASEUN']);

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/partner-orgs/' . $id);
        $html = (string) $this->ctrl()->show($req, new Response(), ['id' => $id])->getBody();

        $this->assertStringContainsString('Weak', $html);
        $this->assertStringContainsString('personal account', $html,
            'The page should say what a weak match might mean, not just score it.');
    }

    /** With no verifier configured the page must say so rather than offering a button that lies. */
    public function test_the_page_admits_when_nothing_is_automatically_verified(): void
    {
        $this->asAdmin();
        $id = $this->seed();

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/partner-orgs/' . $id);
        $html = (string) $this->ctrl()->show($req, new Response(), ['id' => $id])->getBody();

        $this->assertStringContainsString('stored, not verified', $html);
        $this->assertStringNotContainsString('Verify via API', $html,
            'No verifier configured means no API button.');
    }

    // ─────────────────────────────── the gate ───────────────────────────────

    /**
     * Attaching a settlement account decides where donations land and approving decides
     * whether an organisation may collect at all. Both are integrity decisions, behind the
     * same gate as deleting a nominee — and checked in the CONTROLLER, because a button the
     * template merely hid is not a control.
     */
    public function test_a_non_integrity_admin_cannot_approve_or_attach_an_account(): void
    {
        $this->asAdmin('editor');
        $id = $this->seed();

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/x')
            ->withParsedBody(['bank_code' => '058', 'account_number' => '0123456789']);

        $this->ctrl()->attachAccount($req, new Response(), ['id' => $id]);
        $this->assertStringContainsString('Only an admin', (string) ($_SESSION['flash_error'] ?? ''));

        $this->ctrl()->approve(
            $req->withParsedBody(['note' => 'looks fine']), new Response(), ['id' => $id]
        );
        $this->assertSame(PartnerOrg::STATUS_PENDING, PartnerOrg::find($id)->status,
            'An editor must not be able to put a partner live.');
    }

    /** And an integrity admin recording a check writes their id against it. */
    public function test_recording_a_registry_check_stamps_who_did_it(): void
    {
        $this->asAdmin();
        $id = $this->seed();

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/x')
            ->withParsedBody(['which' => 'cac', 'verdict' => 'confirmed']);
        $this->ctrl()->check($req, new Response(), ['id' => $id]);

        $org = PartnerOrg::find($id);
        $this->assertSame('confirmed', $org->cac_check);
        $this->assertSame(1, (int) $org->checked_by);
        $this->assertNotNull($org->checked_at);
    }
}
