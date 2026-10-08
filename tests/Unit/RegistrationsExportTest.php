<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Admin\Controllers\RegistrationsController;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/** The admin registrations CSV export streams the (optionally filtered) rows with a header. */
class RegistrationsExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_role'] = 'admin'; // see-all tier (export is PII-guarded)
        DB::table('gates_site_events')->insert(['id' => 1, 'slug' => 'gala', 'title' => 'Awards Gala', 'event_date' => '2026-09-15 18:00:00', 'status' => 'published', 'created_at' => '2026-01-01 00:00:00']);
        DB::table('gates_site_events')->insert(['id' => 2, 'slug' => 'webinar', 'title' => 'Webinar', 'event_date' => '2026-07-01 12:00:00', 'status' => 'published', 'created_at' => '2026-01-01 00:00:00']);
        DB::table('gates_event_registrations')->insert(['event_id' => 1, 'name' => 'Ada Obi', 'email' => 'ada@x.io', 'phone' => '+2348011112222', 'tier' => 'VIP', 'created_at' => '2026-06-01 10:00:00']);
        DB::table('gates_event_registrations')->insert(['event_id' => 2, 'name' => 'Bob Roy', 'email' => 'bob@x.io', 'phone' => '+2348033334444', 'tier' => null, 'created_at' => '2026-06-02 10:00:00']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_role']);
        parent::tearDown();
    }

    private function export(array $query = []): string
    {
        $ctrl = new RegistrationsController(Twig::create(__DIR__ . '/../../templates'));
        $req  = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/registrations/export')->withQueryParams($query);
        $res  = $ctrl->export($req, new Response());
        $this->assertStringContainsString('text/csv', $res->getHeaderLine('Content-Type'));
        return (string) $res->getBody();
    }

    public function test_export_includes_all_rows_with_header(): void
    {
        $csv = $this->export();
        $this->assertStringContainsString('Event,Name,Email,Phone,Tier', $csv);
        $this->assertStringContainsString('Ada Obi', $csv);
        $this->assertStringContainsString('Awards Gala', $csv);
        $this->assertStringContainsString('VIP', $csv);
        $this->assertStringContainsString('Bob Roy', $csv);
    }

    public function test_export_respects_event_filter(): void
    {
        $csv = $this->export(['event' => 1]);
        $this->assertStringContainsString('Ada Obi', $csv);
        $this->assertStringNotContainsString('Bob Roy', $csv); // filtered to event 1 only
    }
}
