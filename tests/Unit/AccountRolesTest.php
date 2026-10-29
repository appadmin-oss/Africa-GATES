<?php
declare(strict_types=1);

namespace Tests\Unit;

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
 * ONE ACCOUNT, SWITCHED FROM THE ACCOUNT MENU (owner, 28–29 Oct 2026; no roles page).
 * The promises a person relies on:
 *   · a staff or judging sign-in joins on the account's VERIFIED address, an organisation only
 *     on its own password; somebody else's is never offered; one role account, one person;
 *   · switching asks nothing when the session holds the proof the role's own door accepts, and
 *     an emailed code when it does not (a phone code proves a phone);
 *   · a sign-in lands where the account last worked, and a console page asked for opens it;
 *   · a console opened from the account asks again before a write in Finance or
 *     Configuration, and an organisation's payout needs the organisation's password;
 *   · signing out of the account closes what it opened, and nothing else.
 */
final class AccountRolesTest extends TestCase
{
    private array $sent = [];

    protected function tearDown(): void
    {
        foreach (['user_id', 'user_name', 'user_email', 'admin_id', 'admin_name', 'admin_role', 'admin_email', 'org_user_id', 'org_id',
                  'judge_id', 'judge_name', 'judge_email', 'acct_opened', 'acct_proof', 'acct_confirmed_at', 'acct_confirmed_user',
                  'acct_org_proven_at', 'acct_org_proven_user', 'acct_confirm_note', 'acct_confirm_error'] as $k) unset($_SESSION[$k]);
        parent::tearDown();
    }

    private function member(string $email, int $verified = 1, string $proof = 'password'): object
    {
        $id = (int) DB::table('gates_users')->insertGetId(['name' => 'Ade Bello', 'email' => $email, 'password_hash' => 'x', 'status' => 'active',
            'email_verified' => $verified, 'created_at' => date('Y-m-d H:i:s')]);
        foreach (['acct_opened', 'acct_proof', 'acct_confirmed_at', 'acct_confirmed_user', 'admin_id', 'org_user_id', 'judge_id'] as $k) unset($_SESSION[$k]);
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

    private function joinAdmin(object $user, int $admin): void
    {
        $this->assertTrue(Roles::join($user, 'admin', $admin)['ok']);
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

    // ── joining ─────────────────────────────────────────────────────────────────────

    public function test_a_staff_sign_in_is_found_on_the_verified_address_and_joined_once(): void
    {
        $staff = $this->admin('ade@example.test');
        $unverified = $this->member('ade@example.test', 0);
        $this->assertSame([], Roles::found($unverified), 'an address nobody has proved finds nothing');
        DB::table('gates_users')->where('id', $unverified->id)->delete();

        $ade = $this->member('ade@example.test');
        $f = Roles::found($ade);
        $this->assertCount(1, $f);
        $this->assertSame(['admin', $staff, 'email'], [$f[0]['kind'], $f[0]['id'], $f[0]['how']]);
        $this->joinAdmin($ade, $staff);
        $this->assertSame((int) $ade->id, Roles::ownerOf('admin', $staff));
        $this->assertSame([], Roles::found($ade), 'joined, so no longer offered');
        $_SESSION['admin_id'] = $staff;
        $this->assertSame([], Roles::found($ade), 'nor offered again while signed in to it here');
        unset($_SESSION['admin_id']);
        $this->assertSame('verified_email', DB::table('gates_account_links')->where('account_id', $staff)->value('via'));

        // Another person cannot join it, and is not even told it exists.
        $other = $this->member('someone@example.test');
        $this->assertSame([], Roles::found($other));
        $this->assertFalse(Roles::join($other, 'admin', $staff)['ok']);
    }

    public function test_an_organisation_joins_only_on_its_own_password(): void
    {
        $orgUser = $this->orgUser('ade@example.test', 'Owo-trust-pass-9');
        $ade = $this->member('ade@example.test');
        $f = Roles::found($ade);
        $this->assertSame(['org', 'password'], [$f[0]['kind'], $f[0]['how']], 'an email alone never moves a charity’s money');
        $auth = new OrgAuth();
        $this->assertFalse(Roles::join($ade, 'org', $orgUser, '', $auth)['ok']);
        $this->assertFalse(Roles::join($ade, 'org', $orgUser, 'wrong-password', $auth)['ok']);
        $this->assertNull(Roles::ownerOf('org', $orgUser));
        $this->assertTrue(Roles::join($ade, 'org', $orgUser, 'Owo-trust-pass-9', $auth)['ok']);
        $this->assertSame((int) $ade->id, Roles::ownerOf('org', $orgUser));
    }

    // ── switching ───────────────────────────────────────────────────────────────────

    public function test_switching_asks_nothing_on_a_proof_the_consoles_own_door_accepts(): void
    {
        foreach (['password', 'passkey', 'email'] as $proof) {
            $ade = $this->member("ade.{$proof}@example.test", 1, $proof);
            $staff = $this->admin("ade.{$proof}@example.test");
            $this->joinAdmin($ade, $staff);
            $r = Roles::open($ade, 'admin');
            $this->assertTrue($r['ok'], $proof);
            $this->assertSame('/admin', $r['to']);
            $this->assertSame($staff, $_SESSION['admin_id']);
            $this->assertSame((int) $ade->id, $_SESSION['user_id'], 'the account stays signed in');
        }
    }

    public function test_a_phone_code_sign_in_is_asked_for_an_emailed_code_before_the_console(): void
    {
        $ade = $this->member('ade@example.test', 1, 'phone');
        $staff = $this->admin('ade@example.test');
        $this->joinAdmin($ade, $staff);
        $this->assertTrue(Roles::open($ade, 'admin')['confirm'] ?? false);
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
        $code = $this->code($ade);
        $this->assertSame('ade@example.test', $this->sent[0][0]);
        $this->assertTrue(Roles::verifyCode($ade, $code)['ok']);
        $this->assertFalse(Roles::verifyCode($ade, $code)['ok'], 'a code works once');
        $this->assertTrue(Roles::open($ade, 'admin')['ok']);
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

    public function test_a_switched_off_or_removed_sign_in_cannot_be_opened(): void
    {
        $ade = $this->member('ade@example.test');
        $staff = $this->admin('ade@example.test');
        $this->joinAdmin($ade, $staff);
        DB::table('gates_admins')->where('id', $staff)->update(['is_active' => 0]);
        $this->assertFalse(Roles::open($ade, 'admin')['ok']);
        $this->assertFalse(array_values(array_filter(Roles::menu($ade), static fn ($m) => $m['kind'] === 'admin'))[0]['live']);
        DB::table('gates_admins')->where('id', $staff)->update(['is_active' => 1]);
        $link = (int) DB::table('gates_account_links')->where('user_id', $ade->id)->value('id');
        $this->assertTrue(Roles::unlink((int) $ade->id, $link)['ok']);
        $this->assertNotNull(DB::table('gates_account_links')->where('id', $link)->value('revoked_at'), 'the record stays');
        $this->assertFalse(Roles::open($ade, 'admin')['ok']);
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
    }

    // ── landing ─────────────────────────────────────────────────────────────────────

    public function test_a_sign_in_lands_where_the_account_last_worked(): void
    {
        $ade = $this->member('ade@example.test');
        $staff = $this->admin('ade@example.test');
        $this->joinAdmin($ade, $staff);
        $this->assertSame('/account', Roles::landing($ade, null), 'nowhere yet: the account');
        Roles::open($ade, 'admin');
        $this->assertSame('admin', Roles::lastPlace((int) $ade->id));

        unset($_SESSION['admin_id'], $_SESSION['acct_opened']);
        $this->assertSame('/admin', Roles::landing($ade, null));
        $this->assertSame($staff, $_SESSION['admin_id'] ?? null, 'and the console is open when they get there');

        unset($_SESSION['admin_id'], $_SESSION['acct_opened']);
        $this->assertSame('/admin/dashboard', Roles::landing($ade, '/admin/dashboard'), 'the staff page’s own sign-in link comes back in');
        $this->assertSame($staff, $_SESSION['admin_id'] ?? null);
    }

    // ── confirming again ────────────────────────────────────────────────────────────

    public function test_a_console_opened_from_the_account_asks_again_before_money_or_settings(): void
    {
        $ade = $this->member('ade@example.test');
        $staff = $this->admin('ade@example.test');
        $this->joinAdmin($ade, $staff);
        Roles::open($ade, 'admin');
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

        // A console signed in on its own page gave that door's proof, and is not asked.
        unset($_SESSION['acct_opened'], $_SESSION['acct_confirmed_at']);
        $this->assertSame(200, $this->through($mw, $this->post('/admin/finance'))->getStatusCode());
    }

    public function test_an_organisation_opened_from_the_account_needs_its_password_for_a_payout(): void
    {
        $orgUser = $this->orgUser('ade@example.test', 'Owo-trust-pass-9');
        $ade = $this->member('ade@example.test', 1, 'email');
        $auth = new OrgAuth();
        $this->assertTrue(Roles::join($ade, 'org', $orgUser, 'Owo-trust-pass-9', $auth)['ok']);
        $this->assertTrue(Roles::open($ade, 'org')['ok'], 'reading the dashboard asks nothing');
        $mw = new AccountReconfirmMiddleware('org');
        $r = $this->through($mw, $this->post('/org/payout'));
        $this->assertSame(303, $r->getStatusCode());
        $this->assertStringStartsWith('/account/confirm?for=org', $r->getHeaderLine('Location'));
        // An emailed code is not the organisation's proof.
        $this->assertTrue(Roles::verifyCode($ade, $this->code($ade))['ok']);
        $this->assertSame(303, $this->through($mw, $this->post('/org/payout'))->getStatusCode());
        $this->assertFalse(Roles::proveOrg($auth, 'wrong')['ok']);
        $this->assertTrue(Roles::proveOrg($auth, 'Owo-trust-pass-9')['ok']);
        $this->assertSame(200, $this->through($mw, $this->post('/org/payout'))->getStatusCode());
    }

    // ── the menu and signing out ────────────────────────────────────────────────────

    public function test_the_menu_is_what_the_account_is(): void
    {
        $ade = $this->member('ade@example.test');
        $this->assertSame(['member'], array_column(Roles::menu($ade), 'kind'), 'only a member: nothing to switch to');
        $r = Volunteers::apply((int) $ade->id, ['area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        $this->joinAdmin($ade, $this->admin('ade@example.test'));
        $this->assertSame(['member', 'volunteer', 'admin'], array_column(Roles::menu($ade), 'kind'));
        $this->assertSame('/volunteer', Roles::open($ade, 'volunteer')['to']);
        $this->assertSame('volunteer', Roles::lastPlace((int) $ade->id));
    }

    public function test_signing_out_closes_what_the_account_opened_and_nothing_else(): void
    {
        $ade = $this->member('ade@example.test');
        $this->joinAdmin($ade, $this->admin('ade@example.test'));
        $judge = (int) DB::table('gates_judges')->insertGetId(['name' => 'Ade Judge', 'is_active' => 1, 'email' => 'judge.' . bin2hex(random_bytes(3)) . '@example.test']);
        $this->assertTrue(Roles::open($ade, 'admin')['ok']);
        $_SESSION['judge_id'] = $judge; // signed in on the panel's own page, for its own reasons
        (new UserAccountService())->logout();
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
        $this->assertSame($judge, $_SESSION['judge_id'] ?? null);
        $this->assertArrayNotHasKey('acct_proof', $_SESSION);
    }
}
