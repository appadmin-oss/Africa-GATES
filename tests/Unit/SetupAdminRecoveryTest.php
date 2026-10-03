<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * /__setup/admin — the no-SSH recovery door, and why "locked" is not a reason to open it.
 *
 * The reset used to be allowed for any account that was locked or disabled. A lock is
 * something an attacker can CAUSE — five wrong passwords at /admin/login — so a leaked
 * SETUP_TOKEN plus a deliberate lockout seized a live superadmin. And `is_active = 0` is
 * how an admin is deliberately removed; the reset flipped it back on. Recovery is now the
 * one case it exists for: no superadmin can sign in at all.
 */
final class SetupAdminRecoveryTest extends TestCase
{
    private const TOKEN = 'setupadmintoken12345';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['SETUP_TOKEN'] = self::TOKEN;
    }

    protected function tearDown(): void
    {
        unset($_ENV['SETUP_TOKEN']);
        parent::tearDown();
    }

    private function post(string $email, string $pass = 'brand-new-password-1'): string
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/__setup/admin?token=' . self::TOKEN)
            ->withQueryParams(['token' => self::TOKEN])
            ->withParsedBody(['email' => $email, 'name' => 'X', 'password' => $pass]);

        return (string) $app->handle($req)->getBody();
    }

    private function admin(string $email, string $role, int $active, ?string $lockedUntil = null): int
    {
        return (int) DB::table('gates_admins')->insertGetId([
            'email' => $email, 'name' => $email, 'role' => $role,
            'password_hash' => password_hash('original-password', PASSWORD_BCRYPT),
            'is_active' => $active, 'locked_until' => $lockedUntil, 'failed_attempts' => $lockedUntil ? 5 : 0,
        ]);
    }

    private function passwordUnchanged(int $id): bool
    {
        return password_verify('original-password', (string) DB::table('gates_admins')->where('id', $id)->value('password_hash'));
    }

    public function test_a_locked_superadmin_is_not_resettable_while_it_is_the_live_account(): void
    {
        $id = $this->admin('boss@agates.test', 'superadmin', 1, date('Y-m-d H:i:s', time() + 900));

        $this->post('boss@agates.test');

        $this->assertTrue($this->passwordUnchanged($id),
            'a lockout an attacker can cause with five wrong passwords opened the reset');
    }

    public function test_a_deliberately_disabled_admin_is_not_re_armed_while_a_superadmin_is_active(): void
    {
        $this->admin('boss@agates.test', 'superadmin', 1);
        $gone = $this->admin('removed@agates.test', 'superadmin', 0);

        $this->post('removed@agates.test');

        $this->assertTrue($this->passwordUnchanged($gone));
        $this->assertSame(0, (int) DB::table('gates_admins')->where('id', $gone)->value('is_active'),
            'an admin a colleague switched off was switched back on by the token');
    }

    public function test_a_non_superadmin_is_not_recovered_even_when_no_superadmin_can_sign_in(): void
    {
        $this->admin('boss@agates.test', 'superadmin', 0);
        $ed = $this->admin('editor@agates.test', 'editor', 0);

        $this->post('editor@agates.test');

        $this->assertTrue($this->passwordUnchanged($ed));
        $this->assertSame(0, (int) DB::table('gates_admins')->where('id', $ed)->value('is_active'));
    }

    public function test_with_no_active_superadmin_the_recovery_still_works(): void
    {
        // The guard is not simply refusing: the case the door exists for still opens it.
        $id = $this->admin('boss@agates.test', 'superadmin', 0, date('Y-m-d H:i:s', time() + 900));

        $html = $this->post('boss@agates.test');

        $this->assertStringContainsString('Password reset', $html);
        $row = DB::table('gates_admins')->where('id', $id)->first();
        $this->assertTrue(password_verify('brand-new-password-1', (string) $row->password_hash));
        $this->assertSame(1, (int) $row->is_active);
        $this->assertNull($row->locked_until);
    }
}
