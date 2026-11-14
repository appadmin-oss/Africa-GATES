<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\AuthController as AdminAuthController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\AuthService;
use AfricaGates\Judge\Controllers\AuthController as JudgeAuthController;
use AfricaGates\Middleware\AccountReconfirmMiddleware;
use AfricaGates\Services\Account\Roles;
use AfricaGates\Services\OrgAuth;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * ONE ACCOUNT, ONE LOGIN, ROLES GIVEN (owner, 29 Oct 2026). The promises a person relies on:
 *   · a staff, judging or organisation role is GIVEN to an email address and held by the
 *     account that has proved that address — nobody joins anything, and somebody else's is
 *     never reachable;
 *   · the console, the panel and the organisation open on the way in from that account,
 *     asking nothing on a proof their old doors accepted and an emailed code when the session
 *     proved only a phone;
 *   · switched off, or given to another address, the role is gone on the next request;
 *   · the old sign-in pages forward to the one sign-in, carrying where the person was going;
 *   · a sign-in lands where the account last worked;
 *   · money and configuration ask again — an organisation's payout on its own password;
 *   · signing out of the account closes everything it opened.
 */
final class AccountRolesTest extends TestCase
{
    private array $sent = [];

    protected function tearDown(): void
    {
        foreach (['user_id', 'user_name', 'user_email', 'admin_id', 'admin_name', 'admin_role', 'admin_email', 'org_user_id', 'org_id',
                  'judge_id', 'judge_name', 'judge_email', 'acct_opened', 'acct_proof', 'acct_confirmed_at', 'acct_confirmed_user',
                  'acct_org_proven_at', 'acct_org_proven_user', 'acct_confirm_note', 'acct_confirm_error', 'flash_error'] as $k) unset($_SESSION[$k]);
        parent::tearDown();
    }

    private function member(string $email, int $verified = 1, string $proof = 'password'): object
    {
        $id = (int) DB::table('gates_users')->insertGetId(['name' => 'Ade Bello', 'email' => $email, 'password_hash' => 'x', 'status' => 'active',
            'email_verified' => $verified, 'created_at' => date('Y-m-d H:i:s')]);
        foreach (['acct_opened', 'acct_proof', 'acct_confirmed_at', 'acct_confirmed_user', 'admin_id', 'org_user_id', 'org_id', 'judge_id'] as $k) unset($_SESSION[$k]);
        $_SESSION['user_id'] = $id;
        Roles::proved($proof);
        return DB::table('gates_users')->where('id', $id)->first();
    }

    private function admin(string $email, int $active = 1): int
    {
        return (int) DB::table('gates_admins')->insertGetId(['email' => $email, 'name' => 'Ade Staff', 'role' => 'editor', 'is_active' => $active,
            'password_hash' => 'x', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function orgUser(string $email, string $password): int
    {
        $org = (int) DB::table('gates_partner_orgs')->insertGetId(['slug' => 'owo-' . bin2hex(random_bytes(3)), 'name' => 'Owo Reading Trust', 'status' => 'approved',
            'contact_name' => 'Ade', 'contact_email' => $email, 'kind' => 'partner', 'entity_type' => 'organisation']);
        return (int) DB::table('gates_org_users')->insertGetId(['org_id' => $org, 'email' => $email, 'name' => 'Ade Treasurer',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'owner', 'is_active' => 1]);
    }

    private function code(object $user): string
    {
        $this->assertTrue(Roles::sendCode($user, function (string $to, string $c): bool { $this->sent[] = [$to, $c]; return true; })['ok']);
        return end($this->sent)[1];
    }

    private function post(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)->withHeader('Referer', 'https://x.test' . $path);
    }

    private function through(AccountReconfirmMiddleware $mw, ServerRequestInterface $req): ResponseInterface
    {
        return $mw($req, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return (new Response())->withStatus(200); }
        });
    }

    // ── given to an address ─────────────────────────────────────────────────────────

    public function test_a_role_is_held_by_the_account_that_has_proved_its_address(): void
    {
        $staff = $this->admin('ade@example.test');
        $this->assertSame([], Roles::held($this->member('ade@example.test', 0)), 'an address nobody has proved holds nothing');
        DB::table('gates_users')->where('email', 'ade@example.test')->delete();

        $ade = $this->member('ADE@example.test');
        $this->assertSame($staff, (int) Roles::held($ade)['admin']->id, 'the address, whatever its case');
        $this->assertSame([], Roles::held($this->member('someone@example.test')), 'nobody else holds it');
        $this->assertSame('refused', Roles::derive('admin')['state']);
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
    }

    public function test_the_console_opens_on_the_way_in_on_a_proof_its_old_door_accepted(): void
    {
        foreach (['password', 'passkey', 'email'] as $proof) {
            $ade = $this->member("ade.{$proof}@example.test", 1, $proof);
            $staff = $this->admin("ade.{$proof}@example.test");
            $this->assertSame('open', Roles::derive('admin', '/admin/profiles')['state'], $proof);
            $this->assertSame($staff, $_SESSION['admin_id']);
            $this->assertSame('editor', $_SESSION['admin_role']);
            $this->assertSame((int) $ade->id, $_SESSION['user_id'], 'the account stays signed in');
        }
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'login')->where('admin_id', $staff)->count(), 'the console records who came in, and how');
    }

    public function test_nobody_signed_in_is_sent_to_the_one_sign_in_carrying_the_page(): void
    {
        unset($_SESSION['user_id']);
        $d = Roles::derive('admin', '/admin/finance');
        $this->assertSame(['anonymous', '/account/login?next=%2Fadmin%2Ffinance'], [$d['state'], $d['to']]);
    }

    public function test_a_phone_code_sign_in_is_asked_for_an_emailed_code_before_the_console(): void
    {
        $ade = $this->member('ade@example.test', 1, 'phone');
        $staff = $this->admin('ade@example.test');
        $d = Roles::derive('admin', '/admin');
        $this->assertSame('confirm', $d['state']);
        $this->assertStringStartsWith('/account/confirm?for=admin', $d['to']);
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
        $code = $this->code($ade);
        $this->assertSame('ade@example.test', $this->sent[0][0]);
        $this->assertTrue(Roles::verifyCode($ade, $code)['ok']);
        $this->assertFalse(Roles::verifyCode($ade, $code)['ok'], 'a code works once');
        $this->assertSame('open', Roles::derive('admin')['state']);
        $this->assertSame($staff, $_SESSION['admin_id']);
    }

    public function test_guesses_are_capped(): void
    {
        $ade = $this->member('ade@example.test');
        $code = $this->code($ade);
        $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        for ($i = 0; $i < Roles::MAX_ATTEMPTS; $i++) $this->assertFalse(Roles::verifyCode($ade, $wrong)['ok']);
        $this->assertFalse(Roles::verifyCode($ade, $code)['ok'], 'after five wrong guesses the right code is dead too');
        $this->assertFalse(Roles::confirmed());
    }

    public function test_a_role_switched_off_or_given_to_another_address_is_gone_on_the_next_request(): void
    {
        $this->member('ade@example.test');
        $staff = $this->admin('ade@example.test');
        $this->assertSame('open', Roles::derive('admin')['state']);

        DB::table('gates_admins')->where('id', $staff)->update(['email' => 'bisi@example.test']);
        $this->assertSame('refused', Roles::derive('admin')['state']);
        $this->assertArrayNotHasKey('admin_id', $_SESSION, 'closed now, not at the next sign-out');

        DB::table('gates_admins')->where('id', $staff)->update(['email' => 'ade@example.test', 'is_active' => 0]);
        $this->assertSame('refused', Roles::derive('admin')['state']);
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
    }

    public function test_the_old_sign_in_pages_forward_to_the_one_sign_in(): void
    {
        $get = static fn (string $p) => (new ServerRequestFactory())->createServerRequest('GET', $p);
        $admin = new AdminAuthController(new AuthService(new AuditService()), new UserAccountService());
        $this->assertSame('/account/login?next=%2Fadmin%2Fnominations', $admin->toAccount($get('/admin/login?next=/admin/nominations'), new Response())->getHeaderLine('Location'));
        $this->assertSame('/account/login?next=%2Fadmin', $admin->toAccount($get('/admin/login?next=https://evil.example'), new Response())->getHeaderLine('Location'), 'never off-site');
        $judge = new JudgeAuthController(new UserAccountService());
        $this->assertSame('/account/login?next=%2Fjudge%2Fballot', $judge->toAccount($get('/judge/login'), new Response())->getHeaderLine('Location'));
    }

    // ── landing ─────────────────────────────────────────────────────────────────────

    public function test_a_sign_in_lands_where_the_account_last_worked(): void
    {
        $ade = $this->member('ade@example.test');
        $staff = $this->admin('ade@example.test');
        $this->assertSame('/account', Roles::landing($ade, null), 'nowhere yet: the account');
        Roles::open($ade, 'admin');
        $this->assertSame('admin', Roles::lastPlace((int) $ade->id));
        Roles::close('admin');
        $this->assertSame('/admin', Roles::landing($ade, null));
        $this->assertSame($staff, $_SESSION['admin_id'] ?? null, 'and the console is open when they get there');
    }

    // ── confirming again ────────────────────────────────────────────────────────────

    public function test_a_console_opened_from_the_account_asks_again_before_money_or_settings(): void
    {
        $ade = $this->member('ade@example.test');
        $this->admin('ade@example.test');
        Roles::derive('admin');
        $mw = new AccountReconfirmMiddleware('console');

        $this->assertSame(200, $this->through($mw, $this->post('/admin/content'))->getStatusCode(), 'ordinary work asks nothing');
        $this->assertSame(200, $this->through($mw, (new ServerRequestFactory())->createServerRequest('GET', '/admin/finance'))->getStatusCode(), 'reading money asks nothing');
        foreach (['/admin/finance', '/admin/refunds', '/admin/settings', '/admin/admins'] as $p) {
            $r = $this->through($mw, $this->post($p));
            $this->assertSame(303, $r->getStatusCode(), $p);
            $this->assertStringStartsWith('/account/confirm?for=console', $r->getHeaderLine('Location'));
        }
        $this->assertTrue(Roles::verifyCode($ade, $this->code($ade))['ok']);
        $this->assertSame(200, $this->through($mw, $this->post('/admin/finance'))->getStatusCode(), 'confirmed, it goes through');
    }

    public function test_an_organisation_needs_its_own_password_for_a_payout(): void
    {
        $orgUser = $this->orgUser('ade@example.test', 'Owo-trust-pass-9');
        $ade = $this->member('ade@example.test', 1, 'email');
        $this->assertSame($orgUser, (int) (OrgAuth::user()->id ?? 0), 'the dashboard opens from the account, asking nothing');
        $mw = new AccountReconfirmMiddleware('org');
        $r = $this->through($mw, $this->post('/org/payout'));
        $this->assertSame(303, $r->getStatusCode());
        $this->assertStringStartsWith('/account/confirm?for=org', $r->getHeaderLine('Location'));
        $this->assertTrue(Roles::verifyCode($ade, $this->code($ade))['ok']);
        $this->assertSame(303, $this->through($mw, $this->post('/org/payout'))->getStatusCode(), 'an emailed code is not the organisation’s proof');
        $auth = new OrgAuth();
        $this->assertFalse(Roles::proveOrg($auth, 'wrong')['ok']);
        $this->assertTrue(Roles::proveOrg($auth, 'Owo-trust-pass-9')['ok']);
        $this->assertSame(200, $this->through($mw, $this->post('/org/payout'))->getStatusCode());
    }

    // ── the menu and signing out ────────────────────────────────────────────────────

    public function test_the_menu_is_what_the_account_is(): void
    {
        $ade = $this->member('ade@example.test');
        $this->assertSame(['member'], array_column(Roles::menu($ade), 'kind'), 'only a member: nothing to switch to');
        $r = Volunteers::apply((int) $ade->id, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        $this->admin('ade@example.test');
        $this->assertSame(['member', 'volunteer', 'admin'], array_column(Roles::menu($ade), 'kind'));
        $this->assertSame('/volunteer', Roles::open($ade, 'volunteer')['to']);
        $this->assertSame('volunteer', Roles::lastPlace((int) $ade->id));
    }

    public function test_signing_out_closes_everything_the_account_opened(): void
    {
        $this->member('ade@example.test');
        $this->admin('ade@example.test');
        DB::table('gates_judges')->insert(['name' => 'Ade Judge', 'is_active' => 1, 'email' => 'ade@example.test']);
        $this->assertSame('open', Roles::derive('admin')['state']);
        $this->assertSame('open', Roles::derive('judge')['state']);
        (new UserAccountService())->logout();
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
        $this->assertArrayNotHasKey('judge_id', $_SESSION);
        $this->assertArrayNotHasKey('acct_proof', $_SESSION);
    }
}
