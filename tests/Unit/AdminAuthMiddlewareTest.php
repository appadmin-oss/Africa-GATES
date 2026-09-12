<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Middleware\AdminAuthMiddleware;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\AuthService;
use AfricaGates\Admin\Services\LogService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Res;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Access-control contract for the admin gate: authenticated admins reach
 * protected routes, unauthenticated users are bounced to login, and the
 * read-only 'viewer' role is denied any state-changing (non-GET) request —
 * least privilege, with zero impact on other roles.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND: THE SESSION IS NOT THE ACCOUNT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every test below used to set `$_SESSION['admin_role']` and nothing else, which is
 * exactly how the middleware read it — role and `is_active` were stamped into the
 * session at login and never compared against the row again.
 *
 * So the console had two buttons that did nothing to anybody already signed in.
 * Deactivating an admin ended no session; demoting a superadmin to viewer left them
 * writing. Nothing was visible from either side: the operator saw the row change, and
 * the person they had revoked carried on.
 *
 * The suite now seeds real `gates_admins` rows, because a test that stubs the row away
 * is testing the bug. The last four cases are the revocation itself.
 */
final class AdminAuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function mw(): AdminAuthMiddleware
    {
        return new AdminAuthMiddleware(
            new AuthService(new LogService(sys_get_temp_dir()), new AuditService())
        );
    }

    private function handler(): Handler
    {
        return new class implements Handler {
            public function handle(Req $r): Res { return new Response(200); }
        };
    }

    private function req(string $method = 'GET', string $path = '/admin/profiles', array $headers = []): Req
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, 'https://afg.local' . $path);
        foreach ($headers as $k => $v) { $r = $r->withHeader($k, $v); }
        return $r;
    }

    /** Seed an admin and sign them in. Returns the id. */
    private function signIn(string $role, int $active = 1, string $sessionRole = null): int
    {
        $id = (int) DB::table('gates_admins')->insertGetId([
            'email'         => $role . '-' . bin2hex(random_bytes(4)) . '@example.test',
            'password_hash' => password_hash('x', PASSWORD_BCRYPT),
            'name'          => ucfirst($role),
            'role'          => $role,
            'is_active'     => $active,
            'created_at'    => '2026-01-01 00:00:00',
            'updated_at'    => '2026-01-01 00:00:00',
        ]);
        $_SESSION['admin_id'] = $id;
        // What login wrote. Defaults to the truth; a caller passes something else to
        // model a session that has gone stale against the row.
        $_SESSION['admin_role'] = $sessionRole ?? $role;
        return $id;
    }

    // ══ the original contract ════════════════════════════════════════════════

    public function test_unauthenticated_redirects_to_login(): void
    {
        $res = $this->mw()($this->req('GET', '/admin/profiles'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith('/admin/login', $res->getHeaderLine('Location'));
    }

    public function test_authenticated_admin_write_allowed(): void
    {
        $this->signIn('admin');
        $res = $this->mw()($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_viewer_read_allowed(): void
    {
        $this->signIn('viewer');
        $res = $this->mw()($this->req('GET', '/admin/profiles'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_viewer_write_denied_html_redirect(): void
    {
        $this->signIn('viewer');
        $res = $this->mw()($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/dashboard', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function test_viewer_write_denied_json_403(): void
    {
        $this->signIn('viewer');
        $res = $this->mw()($this->req('POST', '/admin/profiles/1', ['Accept' => 'application/json']), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_editor_write_allowed(): void
    {
        // 'editor' is a content-management role — writes are permitted.
        $this->signIn('editor');
        $res = $this->mw()($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_judge_denied_entirely_html(): void
    {
        // Judges have NO admin access — every route + method bounces to /judge/login.
        $this->signIn('judge');
        $res = $this->mw()($this->req('GET', '/admin/nominees'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/judge/login', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['flash_error']);
    }

    public function test_every_role_outside_the_writer_allowlist_is_read_only(): void
    {
        // Fail closed, asserted across the whole role space rather than on one typo.
        //
        // This used to seed role='contributor' to stand for "unrecognised". It cannot any
        // more: the role column is an ENUM in MySQL and a CHECK in SQLite, so the schema
        // now refuses a value the console does not know — the middleware is no longer the
        // last line against a typo, and pretending otherwise tests nothing.
        //
        // What IS still worth holding is the boundary itself. Every role the schema
        // permits and the writer allowlist omits must be refused a write, so that adding
        // a role to the enum and forgetting the allowlist fails here rather than in
        // production as a moderator-shaped account that can save.
        $schemaRoles = ['superadmin', 'admin', 'editor', 'moderator', 'judge', 'viewer'];
        $writers     = ['superadmin', 'admin', 'editor', 'moderator'];

        foreach (array_diff($schemaRoles, $writers) as $role) {
            $_SESSION = [];
            $this->signIn($role);
            $res = $this->mw()($this->req('POST', '/admin/profiles/1', ['Accept' => 'application/json']),
                               $this->handler());
            $this->assertSame(403, $res->getStatusCode(), $role . ' must not be able to write');
        }
    }

    public function test_judge_denied_entirely_json_403(): void
    {
        // JSON / admin-API requests from a judge account get a hard 403.
        $this->signIn('judge');
        $res = $this->mw()($this->req('POST', '/admin/nominations/1/approve', ['Accept' => 'application/json']), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_viewer_logout_allowed_via_exempt(): void
    {
        // Logout is exempt — a read-only viewer must still be able to end their session.
        $this->signIn('viewer');
        $res = $this->mw()($this->req('POST', '/admin/logout'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    // ══ revocation: the four the session copy could not express ══════════════

    public function test_a_deactivated_account_loses_the_console_on_its_next_request(): void
    {
        // The button an operator presses when somebody leaves, or when a laptop goes
        // missing. It used to change a column and nothing else.
        $this->signIn('superadmin', 0);
        $res = $this->mw()($this->req('GET', '/admin/profiles'), $this->handler());

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/login', $res->getHeaderLine('Location'));
        // And the session is actually gone, not merely bounced once.
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
    }

    public function test_a_deleted_account_loses_the_console_too(): void
    {
        $id = $this->signIn('admin');
        DB::table('gates_admins')->where('id', $id)->delete();

        $res = $this->mw()($this->req('GET', '/admin/profiles'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/login', $res->getHeaderLine('Location'));
    }

    public function test_a_deactivated_account_gets_401_on_the_admin_api(): void
    {
        // An XHR must not be answered with a redirect to an HTML login page — the
        // screen would render the login form inside a JSON handler and say nothing.
        $this->signIn('admin', 0);
        $res = $this->mw()($this->req('POST', '/admin/api/thing', ['Accept' => 'application/json']),
                           $this->handler());
        $this->assertSame(401, $res->getStatusCode());
    }

    public function test_a_demotion_takes_effect_without_signing_out(): void
    {
        // Signed in as superadmin, since demoted to viewer. The stale session role is
        // what every guard downstream reads, so the middleware must correct it BEFORE
        // the writer allowlist runs — not at the next login.
        $this->signIn('viewer', 1, 'superadmin');

        $res = $this->mw()($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(302, $res->getStatusCode(), 'a demoted superadmin must not still write');
        $this->assertSame('/admin/dashboard', $res->getHeaderLine('Location'));
        $this->assertSame('viewer', $_SESSION['admin_role'], 'the live role must replace the login copy');
    }

    public function test_a_promotion_takes_effect_without_signing_out(): void
    {
        // The same property in the direction that is not a security hole but is a
        // support ticket: "I made them an admin and they still cannot save anything."
        $this->signIn('admin', 1, 'viewer');

        $res = $this->mw()($this->req('POST', '/admin/profiles/1'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('admin', $_SESSION['admin_role']);
    }

    public function test_an_account_moved_to_judge_is_put_out_of_the_console(): void
    {
        // Appointing a sitting admin to the panel. The judge refusal reads the role,
        // and the role it read was the one from login.
        $this->signIn('judge', 1, 'admin');

        $res = $this->mw()($this->req('GET', '/admin/nominees'), $this->handler());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/judge/login', $res->getHeaderLine('Location'));
    }
}
