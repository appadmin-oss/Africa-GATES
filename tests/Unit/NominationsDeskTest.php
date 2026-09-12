<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DI\ContainerBuilder;
use AfricaGates\Admin\Controllers\NominationsController;
use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Review-desk SPA back-end: the fragment endpoint returns the next pending
 * nomination as JSON+HTML, and approve/reject over fetch answer JSON (so the
 * desk swaps in the next one) while the classic no-JS flow still redirects.
 */
class NominationsDeskTest extends TestCase
{
    private Twig $twig;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'tok';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'stem', 'title' => 'STEM', 'icon_emoji' => '🔬']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'nominations']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'innovation', 'title' => 'Innovation', 'description' => 'd', 'sort_order' => 1]);
        DB::table('gates_nominations')->insert([
            'id' => 10, 'cycle_id' => 1, 'nominee_name' => 'Ada Obi', 'country_code' => 'NG',
            'nominator_name' => 'Ben', 'nominator_email' => 'ben@x.io', 'reason' => 'Great work',
            'status' => 'pending', 'created_at' => '2026-07-01 10:00:00',
        ]);

        // Real container-built Twig so the fetched partial has all globals/filters.
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $this->twig = $builder->build()->get(Twig::class);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function controller(): NominationsController
    {
        return new NominationsController($this->twig, new AuditService());
    }

    private function json(Response $res): array
    {
        return (array) json_decode((string) $res->getBody(), true);
    }

    public function test_desk_fragment_returns_next_pending_as_html(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/nominations/review/next');
        $res = $this->controller()->deskFragment($req, new Response());

        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $j = $this->json($res);
        $this->assertTrue($j['ok']);
        $this->assertFalse($j['done']);
        $this->assertSame(10, $j['id']);
        $this->assertSame('Ada Obi', $j['nominee']);
        $this->assertSame(1, $j['position']);
        $this->assertSame(1, $j['total']);
        $this->assertStringContainsString('Ada Obi', $j['html']);
        $this->assertStringContainsString('data-desk-form="approve"', $j['html']);   // SPA hooks present
        $this->assertStringContainsString('Innovation', $j['html']);                 // category rendered
    }

    public function test_desk_fragment_reports_done_at_end_of_queue(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/nominations/review/next?after=10')
            ->withQueryParams(['after' => '10']);
        $j = $this->json($this->controller()->deskFragment($req, new Response()));
        $this->assertTrue($j['ok']);
        $this->assertTrue($j['done']);
        $this->assertNotEmpty($j['message']);
    }

    public function test_approve_over_fetch_returns_json_and_creates_nominee(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/approve')
            ->withHeader('X-Requested-With', 'fetch')
            ->withParsedBody(['category_id' => 1, 'desk' => '1']);
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'approve']);

        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $j = $this->json($res);
        $this->assertTrue($j['ok']);
        $this->assertNotEmpty($j['message']);
        $this->assertSame('approved', DB::table('gates_nominations')->where('id', 10)->value('status'));
        $this->assertSame(1, (int) DB::table('gates_nominees')->where('category_id', 1)->count());
    }

    public function test_approve_over_fetch_without_category_returns_422(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/approve')
            ->withHeader('X-Requested-With', 'fetch')
            ->withParsedBody(['desk' => '1']);
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'approve']);

        $this->assertSame(422, $res->getStatusCode());
        $j = $this->json($res);
        $this->assertFalse($j['ok']);
        $this->assertNotEmpty($j['error']);
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status')); // untouched
    }

    public function test_reject_over_fetch_returns_json(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/reject')
            ->withHeader('X-Requested-With', 'fetch')
            ->withParsedBody(['desk' => '1', 'decision_reason' => 'Not enough detail.']);
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'reject']);

        $j = $this->json($res);
        $this->assertTrue($j['ok']);
        $this->assertSame('rejected', DB::table('gates_nominations')->where('id', 10)->value('status'));
    }

    public function test_classic_approve_still_redirects(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/approve')
            ->withParsedBody(['category_id' => 1]); // no X-Requested-With
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'approve']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_ok'] ?? '');
    }
}
