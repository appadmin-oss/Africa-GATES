<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\FinanceController;
use AfricaGates\Admin\Support\Permissions;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The finance page itself — access, and that the totals reach the HTML.
 *
 * The second half matters more than it looks. This page is the one an admin opens to
 * check whether a payment arrived, so its numbers have to be IN THE MARKUP rather than
 * fetched afterwards: a figure that appears a moment after the page does is a figure
 * that can be read while it is still empty, and "₦0" is a very believable wrong answer.
 * That is also why the tab strip is radio inputs and CSS instead of a JS component.
 */
final class FinancePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['admin_id']   = 1;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_role'], $_SESSION['admin_id'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function get(string $path = '/admin/finance'): Response
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get(FinanceController::class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $method = str_contains($path, '/export') ? 'export' : 'index';
        return $ctrl->$method($req, new Response());
    }

    // ── Access ───────────────────────────────────────────────────────────────

    /**
     * Narrower than /admin/data on purpose. The data explorer lets a viewer read
     * gates_donations row by row — an operational lookup. A page that totals every
     * naira taken and ranks the largest donors is a different disclosure.
     */
    public function test_only_superadmin_and_admin_can_see_the_money(): void
    {
        foreach (['superadmin', 'admin'] as $role) {
            $_SESSION['admin_role'] = $role;
            $this->assertSame(200, $this->get()->getStatusCode(), "{$role} should reach finance");
        }
        foreach (['editor', 'moderator', 'viewer', ''] as $role) {
            $_SESSION['admin_role'] = $role;
            $res = $this->get();
            $this->assertSame(302, $res->getStatusCode(), "{$role} must be turned away");
            $this->assertSame('/admin/dashboard', $res->getHeaderLine('Location'));
        }
    }

    /** The export is the same data, so it carries the same gate. */
    public function test_the_export_is_gated_identically(): void
    {
        $_SESSION['admin_role'] = 'viewer';
        $this->assertSame(302, $this->get('/admin/finance/export')->getStatusCode());

        $_SESSION['admin_role'] = 'admin';
        $res = $this->get('/admin/finance/export');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/csv', $res->getHeaderLine('Content-Type'));
    }

    /**
     * The permission matrix and the controller must agree. If the nav shows a link the
     * controller then bounces, the console looks broken; if the controller allows a
     * role the matrix hides the link from, the page is reachable but undiscoverable.
     */
    public function test_the_nav_section_and_the_controller_agree(): void
    {
        $this->assertSame(['superadmin', 'admin'], Permissions::MATRIX['finance']);
    }

    // ── The numbers are in the HTML ──────────────────────────────────────────

    public function test_the_confirmed_total_is_rendered_server_side(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'A Supporter', 'donor_email' => 'a@example.test',
            'amount_naira' => 125000, 'tier' => 'donation', 'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $html = (string) $this->get()->getBody();

        $this->assertStringContainsString('₦125,000', $html);
        $this->assertStringContainsString('Confirmed revenue', $html);
        // Every tab's content ships in the same response — no fetch to wait on.
        foreach (['By source', 'Transactions', 'Needs attention', 'Paid votes'] as $tab) {
            $this->assertStringContainsString($tab, $html);
        }
    }

    /** Pending must be visible AND separate. Folding it into the total inflates revenue. */
    public function test_pending_money_is_shown_apart_from_revenue(): void
    {
        foreach ([['confirmed', 40000], ['pending', 60000]] as [$status, $amt]) {
            DB::table('gates_donations')->insert([
                'donor_name' => 'X', 'donor_email' => 'x@example.test',
                'amount_naira' => $amt, 'tier' => 'donation', 'status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $html = (string) $this->get()->getBody();

        $this->assertStringContainsString('₦40,000', $html, 'confirmed revenue');
        $this->assertStringContainsString('₦60,000', $html, 'pending, shown separately');
        $this->assertStringNotContainsString('₦100,000', $html, 'the two must never be summed');
    }

    /** An empty database is a legitimate state, not an error page. */
    public function test_the_page_renders_with_no_payments_at_all(): void
    {
        $res = $this->get();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('No confirmed payments in this period.', (string) $res->getBody());
    }

    public function test_the_export_carries_a_header_row_and_the_data(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Ada Obi', 'donor_email' => 'ada@example.test',
            'amount_naira' => 7500, 'tier' => 'paid-vote', 'status' => 'confirmed',
            'payment_ref' => 'ref-abc', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $csv = (string) $this->get('/admin/finance/export')->getBody();

        $this->assertStringContainsString('Date,Source,Name,Email,"Amount (NGN)",Status,Reference', $csv);
        $this->assertStringContainsString('Ada Obi', $csv);
        $this->assertStringContainsString('"Paid votes"', $csv);
        $this->assertStringContainsString('7500', $csv);
        $this->assertStringContainsString('ref-abc', $csv);
    }
}
