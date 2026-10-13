<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ConsoleMeController;
use AfricaGates\Admin\Middleware\AdminAuthMiddleware;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Services\ConsolePins;
use AfricaGates\Admin\Services\HomeBoard;
use AfricaGates\Admin\Support\Permissions;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * THE CONSOLE SHELL'S OWN BEHAVIOUR (stage 1, 4 Oct 2026): pins stored per admin, the
 * viewer who reads and changes nothing, the reason a confirm collects, the alerts and
 * Home's board from real records — each held against the fault it exists to prevent.
 */
final class ConsoleShellTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConsoleAlerts::reset();
        HomeBoard::resetAll();
        unset($_POST['_reason']);
        DB::table('gates_admins')->insert([
            ['id' => 11, 'email' => 'v@example.org', 'name' => 'Vee Viewer', 'role' => 'viewer', 'is_active' => 1],
            ['id' => 12, 'email' => 'm@example.org', 'name' => 'Mo Moderator', 'role' => 'moderator', 'is_active' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        unset($_POST['_reason']);
        parent::tearDown();
    }

    // ══ pins: per admin, server-side, re-checked on every read ═══════════════

    public function test_a_pin_is_stored_for_its_admin_once_and_comes_back(): void
    {
        $r = ConsolePins::add(12, 'moderator', '/admin/nominations?status=pending', 'Waiting review');
        $this->assertTrue($r['ok']);
        $again = ConsolePins::add(12, 'moderator', '/admin/nominations?status=pending', 'Twice');
        $this->assertSame($r['id'], $again['id'], 'the same view pinned twice');
        $this->assertSame([['id' => $r['id'], 'href' => '/admin/nominations?status=pending', 'label' => 'Waiting review']],
            ConsolePins::forAdmin(12, 'moderator'));
        $this->assertSame([], ConsolePins::forAdmin(11, 'viewer'), 'a pin leaked to another admin');
    }

    public function test_a_pin_is_never_a_door_the_guard_closes(): void
    {
        // Written by role A, read by role B: the row survives, the link is not drawn.
        ConsolePins::add(12, 'moderator', '/admin/nominations', 'Nominations');
        $this->assertSame([], ConsolePins::forAdmin(12, 'editor'), 'a pin outlived the role that could open it');
        // And a role cannot pin what it cannot open, or anything that is not a console page.
        $this->assertFalse(ConsolePins::add(12, 'moderator', '/admin/finance', 'x')['ok']);
        foreach (['https://evil.example/admin/x', '//evil.example/admin/x', '/account/x', '/admin/logout', ''] as $bad) {
            $this->assertNull(ConsolePins::normalise($bad), "$bad was accepted as a console page");
        }
    }

    public function test_unpinning_is_only_ever_your_own_row_and_is_audited(): void
    {
        $id = ConsolePins::add(12, 'moderator', '/admin/interviews', 'Interviews')['id'];
        $this->assertNull(ConsolePins::remove(11, $id), 'another admin removed my pin');

        $_SESSION['admin_id'] = 12; $_SESSION['admin_role'] = 'moderator';
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/me/pins/' . $id . '/unpin')
            ->withHeader('Accept', 'application/json')->withParsedBody([]);
        $res = (new ConsoleMeController(new AuditService()))->unpin($req, (new ResponseFactory())->createResponse(), ['id' => (string) $id]);
        $body = json_decode((string) $res->getBody(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame('/admin/interviews', $body['pin']['href'], 'Undo needs the pin back');
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'console.unpin')->where('admin_id', 12)->count());
        $this->assertSame([], ConsolePins::forAdmin(12, 'moderator'));
    }

    public function test_the_sidebar_state_persists_per_admin(): void
    {
        $this->assertFalse(ConsolePins::sidebarClosed(12));
        ConsolePins::setSidebarClosed(12, true);
        $this->assertTrue(ConsolePins::sidebarClosed(12));
        $this->assertFalse(ConsolePins::sidebarClosed(11));
        ConsolePins::setSidebarClosed(12, false);
        $this->assertFalse(ConsolePins::sidebarClosed(12));
    }

    // ══ a viewer reads and changes nothing — on the server, whatever the page does ══

    private function auth(string $role, int $id, string $method, string $path): int
    {
        $_SESSION['admin_id'] = $id; $_SESSION['admin_role'] = $role;
        $auth = new class extends \AfricaGates\Admin\Services\AuthService {
            public function __construct() {}
            public function currentAdmin(): ?object { return DB::table('gates_admins')->where('id', (int) $_SESSION['admin_id'])->first(); }
        };
        $hit = false;
        $h = new class($hit) implements RequestHandlerInterface {
            public function __construct(private bool &$hit) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { $this->hit = true; return new Response(200); }
        };
        $res = (new AdminAuthMiddleware($auth))((new ServerRequestFactory())->createServerRequest($method, $path), $h);
        unset($_SESSION['flash_error']);
        return $hit ? 200 : $res->getStatusCode();
    }

    public function test_a_viewer_cannot_post_a_change_but_can_pin_and_ask(): void
    {
        $this->assertNotSame(200, $this->auth('viewer', 11, 'POST', '/admin/nominations/5/decide'), 'a viewer changed something');
        $this->assertNotSame(200, $this->auth('viewer', 11, 'POST', '/admin/settings/providers/run'));
        $this->assertNotSame(200, $this->auth('viewer', 11, 'POST', '/admin/me-but-not-really'));
        $this->assertSame(200, $this->auth('viewer', 11, 'POST', '/admin/me/pins'), 'a viewer cannot pin a view');
        $this->assertSame(200, $this->auth('viewer', 11, 'POST', '/admin/assistant/chat'), 'a viewer cannot ask the assistant');
        $this->assertSame(200, $this->auth('viewer', 11, 'GET', '/admin/data'));
        $this->assertSame(200, $this->auth('moderator', 12, 'POST', '/admin/nominations/5/decide'));
        $this->assertSame(Permissions::WRITERS, ['superadmin', 'admin', 'editor', 'moderator']);
    }

    public function test_the_layout_draws_every_write_at_45_percent_for_a_viewer_and_the_script_refuses_it(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/console/console.css');
        $this->assertMatchesRegularExpression('~body\[data-readonly\][^{]*\{\s*opacity:\s*\.45;~', $css);
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
        $this->assertStringContainsString("'Viewers can read but not change anything'", $js);
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/layout.twig');
        $this->assertStringContainsString('{% if not shell.can_write %} data-readonly{% endif %}', $layout);
        $this->assertStringContainsString('Read-only for {{ shell.role_label }}', $layout);
    }

    // ══ the reason a confirm collects reaches the audit log ═══════════════════

    public function test_the_confirm_reason_is_attached_where_the_audit_row_is_written(): void
    {
        $_POST['_reason'] = '  Requested by the host, ticket #4821  ';
        (new AuditService())->record(12, 'nomination.reject', 'nomination', 5, ['why' => 'x']);
        $meta = json_decode((string) DB::table('gates_audit_log')->where('action', 'nomination.reject')->value('meta'), true);
        $this->assertSame('Requested by the host, ticket #4821', $meta['reason']);

        // A caller's own reason wins, and no reason means no key.
        (new AuditService())->record(12, 'a.own', null, null, ['reason' => 'mine']);
        unset($_POST['_reason']);
        (new AuditService())->record(12, 'a.none', null, null, ['k' => 1]);
        $own  = json_decode((string) DB::table('gates_audit_log')->where('action', 'a.own')->value('meta'), true);
        $none = json_decode((string) DB::table('gates_audit_log')->where('action', 'a.none')->value('meta'), true);
        $this->assertSame('mine', $own['reason']);
        $this->assertArrayNotHasKey('reason', $none);

        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
        $this->assertStringContainsString("h.name = '_reason'", $js, 'the dialog no longer sends its reason');
        $this->assertStringContainsString('ok.disabled = needReason && input.value.trim() === \'\'', $js,
            'the confirm is no longer disabled until a reason is typed');
    }

    // ══ alerts and Home, from real records ═══════════════════════════════════

    public function test_a_chargeback_is_a_high_alert_and_the_pill_says_so_only_to_health_roles(): void
    {
        DB::table('gates_support_tickets')->insert(['reference' => 'T1', 'subject' => 'Chargeback on AFG-1', 'status' => 'open']);
        ConsoleAlerts::reset();
        $keys = array_column(ConsoleAlerts::high(), 'key');
        $this->assertContains('chargebacks', $keys);
        $pill = ConsoleAlerts::pill('admin');
        $this->assertSame('high', $pill['tone']);
        $this->assertSame(count(ConsoleAlerts::high()) . ' high alerts', $pill['label']);
        $this->assertNull(ConsoleAlerts::pill('moderator'), 'a moderator was linked to a page they cannot open');
        $this->assertNull(ConsoleAlerts::pill('editor'));
        $this->assertNotNull(ConsoleAlerts::pill('viewer'));
    }

    public function test_home_puts_the_handoffs_sources_first_and_shows_no_zero(): void
    {
        $board = static fn (string $role): array => array_column(HomeBoard::board($role), 'key');
        $this->assertNotContains('review', $board('superadmin'), 'a zero became a card');

        DB::table('gates_nominations')->insert(['cycle_id' => 1, 'nominee_name' => 'Amara', 'nominator_name' => 'Chidi', 'nominator_email' => 'c@example.org', 'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s', time() - 6 * 3600)]);
        DB::table('gates_partner_enquiries')->insert(['org_name' => 'Org', 'contact_name' => 'A', 'contact_email' => 'a@example.org', 'status' => 'new']);
        HomeBoard::resetAll(); ConsoleAlerts::reset();

        $keys = $board('superadmin');
        $this->assertSame(0, array_search('review', $keys, true), 'the review queue is the first source');
        $this->assertContains('partners', $keys);
        $review = HomeBoard::board('superadmin')[0];
        $this->assertSame('danger', $review['tone'], 'over four hours is late');
        $this->assertSame('/admin/nominations/review', $review['href']);
        // An editor cannot open the review queue, so it is not their job.
        $this->assertNotContains('review', $board('editor'));
        $this->assertContains('partners', $board('editor'));
    }

    public function test_the_quiet_numbers_are_the_handoffs_six_and_real(): void
    {
        DB::table('gates_judges')->insert(['name' => 'J', 'email' => 'j@example.org', 'is_active' => 1]);
        $q = HomeBoard::quiet();
        $this->assertSame(['Votes in 24 hours', 'Nominations this week', 'Nominees on the site', 'With the judges',
                           'Interviews published', 'Judges'], array_column($q, 'k'));
        $this->assertSame('1', $q[5]['v']);
    }

    public function test_the_shortcut_tiles_are_filtered_by_what_a_role_can_open(): void
    {
        $labels = static fn (string $r): array => array_column(HomeBoard::shortcuts($r), 'label');
        $this->assertContains('People', $labels('superadmin'));
        $this->assertNotContains('People', $labels('admin'));
        $this->assertNotContains('Payments', $labels('moderator'));
        $this->assertContains('Alerts', $labels('viewer'));
        $this->assertNotContains('Hosts', $labels('superadmin'), 'Hosts is not built (owner, 4 Oct 2026)');
    }
}
