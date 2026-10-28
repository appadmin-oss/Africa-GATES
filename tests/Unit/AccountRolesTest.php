<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Account\Roles;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * ONE ACCOUNT, EVERY ROLE (owner, 28 Oct 2026; plan §7.1). The promises a person relies on:
 * a role is added only by somebody who has just signed in to it; a role account belongs to one
 * person; opening a staff, organisation or judging role needs a fresh emailed code, whose
 * guesses are capped; opening writes exactly that role's session; signing out of the account
 * closes what it opened and nothing else; volunteer is a role of the account, or an offer.
 */
final class AccountRolesTest extends TestCase
{
    private array $sent = [];

    protected function tearDown(): void
    {
        foreach (['user_id', 'user_name', 'user_email', 'admin_id', 'admin_name', 'admin_role', 'admin_email', 'org_user_id', 'org_id',
                  'judge_id', 'judge_name', 'judge_email', 'acct_opened', 'acct_stepup_at', 'acct_stepup_user'] as $k) unset($_SESSION[$k]);
        parent::tearDown();
    }

    private function member(string $name): object
    {
        $id = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $_SESSION['user_id'] = $id;
        return DB::table('gates_users')->where('id', $id)->first();
    }

    private function admin(string $name, int $active = 1): int
    {
        return (int) DB::table('gates_admins')->insertGetId(['email' => strtolower(str_replace(' ', '.', $name)) . bin2hex(random_bytes(2)) . '@example.test',
            'name' => $name, 'role' => 'editor', 'is_active' => $active, 'password_hash' => 'x', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function judge(string $name): int
    {
        return (int) DB::table('gates_judges')->insertGetId(['name' => $name, 'is_active' => 1, 'email' => 'j' . bin2hex(random_bytes(3)) . '@example.test']);
    }

    /** Send the code into $this->sent instead of the mail, and hand it back. */
    private function code(object $user): string
    {
        $r = Roles::sendCode($user, function (string $to, string $c): bool { $this->sent[] = [$to, $c]; return true; });
        $this->assertTrue($r['ok']);
        return end($this->sent)[1];
    }

    private function linkAdmin(object $user, int $adminId): void
    {
        $_SESSION['admin_id'] = $adminId;
        $this->assertTrue(Roles::link((int) $user->id, 'admin')['ok']);
        unset($_SESSION['admin_id']);
    }

    public function test_a_role_is_added_only_by_somebody_signed_in_to_it_and_belongs_to_one_person(): void
    {
        $ade = $this->member('Ade Bello');
        $staff = $this->admin('Ade Staff');
        // Not signed in to the console in this browser: nothing to add, whatever the email says.
        $this->assertFalse(Roles::link((int) $ade->id, 'admin')['ok']);
        $this->assertSame([], Roles::linkable((int) $ade->id));

        $_SESSION['admin_id'] = $staff;
        $this->assertSame('admin', Roles::linkable((int) $ade->id)[0]['kind']);
        $this->assertTrue(Roles::link((int) $ade->id, 'admin')['ok']);
        $this->assertSame((int) $ade->id, Roles::ownerOf('admin', $staff));
        $this->assertTrue(Roles::link((int) $ade->id, 'admin')['already'] ?? false, 'pressing twice is not a second link');

        // Somebody else signed in to the same console account cannot take it.
        $bisi = $this->member('Bisi Okon');
        $_SESSION['admin_id'] = $staff;
        $r = Roles::link((int) $bisi->id, 'admin');
        $this->assertFalse($r['ok']);
        $this->assertTrue(Roles::linkable((int) $bisi->id)[0]['taken']);
        $this->assertSame(1, DB::table('gates_account_links')->where('kind', 'admin')->where('account_id', $staff)->whereNull('revoked_at')->count());

        // A link for a different person's session is refused: the account must be the one signed in.
        $this->assertFalse(Roles::link((int) $ade->id, 'admin')['ok']);
    }

    public function test_opening_a_guarded_role_needs_a_fresh_code_and_writes_that_roles_session(): void
    {
        $ade = $this->member('Ade Bello');
        $staff = $this->admin('Ade Staff');
        $this->linkAdmin($ade, $staff);

        $r = Roles::open($ade, 'admin');
        $this->assertTrue($r['stepup'] ?? false, 'no code yet, so no console');
        $this->assertArrayNotHasKey('admin_id', $_SESSION);

        $code = $this->code($ade);
        $this->assertSame(strtolower((string) $ade->email), $this->sent[0][0], 'the code goes to the account’s own address');
        $this->assertTrue(Roles::verifyCode($ade, $code)['ok']);
        $this->assertFalse(Roles::verifyCode($ade, $code)['ok'], 'a code works once');

        $r = Roles::open($ade, 'admin');
        $this->assertTrue($r['ok']);
        $this->assertSame('/admin', $r['to']);
        $this->assertSame($staff, $_SESSION['admin_id']);
        $this->assertSame('editor', $_SESSION['admin_role']);
        $this->assertSame((int) $ade->id, $_SESSION['user_id'], 'the account stays signed in');

        // A role the account does not hold cannot be opened, confirmed or not.
        $this->assertFalse(Roles::open($ade, 'judge')['ok']);
        $this->assertArrayNotHasKey('judge_id', $_SESSION);
    }

    public function test_the_confirmation_belongs_to_the_account_that_earned_it_and_expires(): void
    {
        $ade = $this->member('Ade Bello');
        $this->assertTrue(Roles::verifyCode($ade, $this->code($ade))['ok']);
        $this->assertTrue(Roles::steppedUp());
        $_SESSION['acct_stepup_at'] = time() - Roles::STEPUP_MINUTES * 60 - 1;
        $this->assertFalse(Roles::steppedUp(), 'thirty minutes, then a new code');
        $_SESSION['acct_stepup_at'] = time();
        $this->member('Bisi Okon'); // another account signed in on this browser
        $this->assertFalse(Roles::steppedUp(), 'Ade’s code does not open Bisi’s roles');
    }

    public function test_guesses_are_capped_and_a_new_code_cancels_the_last(): void
    {
        $ade = $this->member('Ade Bello');
        $first = $this->code($ade);
        $second = $this->code($ade);
        if ($first !== $second) $this->assertFalse(Roles::verifyCode($ade, $first)['ok'], 'the earlier code is cancelled');

        $code = $this->code($ade);
        $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        for ($i = 0; $i < Roles::MAX_ATTEMPTS; $i++) $this->assertFalse(Roles::verifyCode($ade, $wrong)['ok']);
        $this->assertFalse(Roles::verifyCode($ade, $code)['ok'], 'after five wrong guesses the right code is dead too');
        $this->assertFalse(Roles::steppedUp());
    }

    public function test_a_switched_off_role_cannot_be_opened(): void
    {
        $ade = $this->member('Ade Bello');
        $staff = $this->admin('Ade Staff');
        $this->linkAdmin($ade, $staff);
        DB::table('gates_admins')->where('id', $staff)->update(['is_active' => 0]);
        $this->assertTrue(Roles::verifyCode($ade, $this->code($ade))['ok']);
        $this->assertFalse(Roles::open($ade, 'admin')['ok']);
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
        $row = array_values(array_filter(Roles::of($ade), static fn ($r) => $r['kind'] === 'admin'))[0];
        $this->assertFalse($row['live']);
    }

    public function test_signing_out_closes_what_the_account_opened_and_nothing_else(): void
    {
        $ade = $this->member('Ade Bello');
        $staff = $this->admin('Ade Staff');
        $this->linkAdmin($ade, $staff);
        $judge = $this->judge('Ade Judge');
        $this->assertTrue(Roles::verifyCode($ade, $this->code($ade))['ok']);
        $this->assertTrue(Roles::open($ade, 'admin')['ok']);
        // A judge session signed in on its own page, for its own reasons, in the same browser.
        $_SESSION['judge_id'] = $judge;

        (new UserAccountService())->logout();
        $this->assertArrayNotHasKey('admin_id', $_SESSION, 'the console opened from the account closes with it');
        $this->assertSame($judge, $_SESSION['judge_id'] ?? null, 'a separately signed-in role is left alone');
        $this->assertFalse(Roles::steppedUp());
    }

    public function test_volunteer_is_a_role_of_the_account_or_an_offer_to_apply(): void
    {
        $ade = $this->member('Ade Bello');
        $row = array_values(array_filter(Roles::of($ade), static fn ($r) => $r['kind'] === 'volunteer'))[0];
        $this->assertTrue($row['offer'] ?? false);
        $this->assertSame('/volunteer/apply', $row['href']);

        $r = Volunteers::apply((int) $ade->id, ['area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        $row = array_values(array_filter(Roles::of($ade), static fn ($r) => $r['kind'] === 'volunteer'))[0];
        $this->assertTrue($row['live']);
        $this->assertFalse($row['guarded'], 'volunteering needs no second sign-in and no code');
        $this->assertSame('/volunteer', $row['href']);
    }

    public function test_removing_a_role_stamps_the_link_and_keeps_the_record(): void
    {
        $ade = $this->member('Ade Bello');
        $staff = $this->admin('Ade Staff');
        $this->linkAdmin($ade, $staff);
        $link = (int) DB::table('gates_account_links')->where('user_id', $ade->id)->value('id');
        $bisi = $this->member('Bisi Okon');
        $this->assertFalse(Roles::unlink((int) $bisi->id, $link)['ok'], 'only the account holding it may remove it');
        $this->assertTrue(Roles::unlink((int) $ade->id, $link)['ok']);
        $row = DB::table('gates_account_links')->where('id', $link)->first();
        $this->assertNotNull($row->revoked_at);
        $this->assertNull(Roles::ownerOf('admin', $staff));
        $this->assertFalse(Roles::open($ade, 'admin')['ok']);
    }
}
