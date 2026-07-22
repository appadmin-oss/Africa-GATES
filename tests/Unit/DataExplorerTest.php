<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Admin\Controllers\DataController;
use AfricaGates\Admin\Support\DataRegistry;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/** The generic data explorer: registry resilience, filtered CSV export, secret-column hiding, 404s. */
class DataExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_role'] = 'superadmin';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_role']);
        parent::tearDown();
    }

    private function ctrl(): DataController
    {
        return new DataController(Twig::create(__DIR__ . '/../../templates'));
    }

    public function test_registry_only_exposes_existing_tables(): void
    {
        $sets = DataRegistry::available();
        $this->assertArrayHasKey('users', $sets);
        $this->assertArrayHasKey('votes', $sets);
        // every exposed table really exists
        foreach ($sets as $d) {
            $this->assertTrue(DB::schema()->hasTable($d['table']));
        }
    }

    public function test_export_filters_and_hides_secrets(): void
    {
        DB::table('gates_users')->insert(['name' => 'Ada Obi', 'email' => 'ada@x.io', 'password_hash' => 'SECRETHASH', 'points' => 10, 'status' => 'active', 'created_at' => '2026-06-01 00:00:00']);
        DB::table('gates_users')->insert(['name' => 'Bob Roy', 'email' => 'bob@x.io', 'password_hash' => 'SECRETHASH', 'points' => 5, 'status' => 'active', 'created_at' => '2026-06-02 00:00:00']);

        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/users/export')->withQueryParams(['q' => 'ada']);
        $res = $this->ctrl()->export($req, new Response(), ['dataset' => 'users']);

        $this->assertStringContainsString('text/csv', $res->getHeaderLine('Content-Type'));
        $csv = (string) $res->getBody();
        $this->assertStringContainsString('Ada Obi', $csv);
        $this->assertStringNotContainsString('Bob Roy', $csv);          // search filter applied
        $this->assertStringNotContainsString('password_hash', $csv);    // secret column hidden from header
        $this->assertStringNotContainsString('SECRETHASH', $csv);       // and from data
    }

    public function test_unknown_dataset_is_404(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/nope/export');
        $this->expectException(\Slim\Exception\HttpNotFoundException::class);
        $this->ctrl()->export($req, new Response(), ['dataset' => 'nope']);
    }

    public function test_role_scoping(): void
    {
        // see-all tier gets everything
        $this->assertArrayHasKey('donations', DataRegistry::availableForRole('viewer'));
        $this->assertArrayHasKey('users', DataRegistry::availableForRole('admin'));
        // moderator: community/integrity yes, financial/PII no
        $this->assertTrue(DataRegistry::canRole('comments', 'moderator'));
        $this->assertTrue(DataRegistry::canRole('moderation-log', 'moderator'));
        $this->assertFalse(DataRegistry::canRole('donations', 'moderator'));
        $this->assertFalse(DataRegistry::canRole('users', 'moderator'));
        // editor: analytics yes, financial no
        $this->assertTrue(DataRegistry::canRole('funnel', 'editor'));
        $this->assertFalse(DataRegistry::canRole('orders', 'editor'));
    }

    public function test_dataset_export_blocked_for_role_without_access(): void
    {
        $_SESSION['admin_role'] = 'moderator';
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/donations/export');
        $this->expectException(\Slim\Exception\HttpNotFoundException::class);
        $this->ctrl()->export($req, new Response(), ['dataset' => 'donations']);
    }

    public function test_export_honours_date_range_filter(): void
    {
        $today = date('Y-m-d H:i:s');
        $old   = date('Y-m-d H:i:s', strtotime('-60 days'));
        DB::table('gates_users')->insert(['name' => 'Recent Ruth', 'email' => 'ruth@x.io', 'points' => 0, 'status' => 'active', 'created_at' => $today]);
        DB::table('gates_users')->insert(['name' => 'Ancient Amos', 'email' => 'amos@x.io', 'points' => 0, 'status' => 'active', 'created_at' => $old]);

        // range=30d must include the recent row and exclude the 60-day-old one.
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/users/export')->withQueryParams(['range' => '30d']);
        $csv = (string) $this->ctrl()->export($req, new Response(), ['dataset' => 'users'])->getBody();
        $this->assertStringContainsString('Recent Ruth', $csv);
        $this->assertStringNotContainsString('Ancient Amos', $csv);
    }
}
