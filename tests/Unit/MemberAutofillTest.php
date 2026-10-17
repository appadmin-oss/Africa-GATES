<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\UserAccountService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * memberForForms() powers the opt-in "use my profile details" chip on the
 * nomination / vote / RSVP forms: null for guests and non-active accounts,
 * fresh DB values (not session copies) for signed-in members.
 */
final class MemberAutofillTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        parent::tearDown();
    }

    public function test_guest_gets_null(): void
    {
        unset($_SESSION['user_id']);
        $this->assertNull(UserAccountService::memberForForms());
    }

    public function test_member_gets_fresh_contact_details(): void
    {
        $id = DB::table('gates_users')->insertGetId([
            'name' => 'Ada Obi', 'email' => 'ada@x.io', 'phone' => '+2348031234567',
            'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $_SESSION['user_id'] = $id;
        $m = UserAccountService::memberForForms();
        $this->assertSame('Ada Obi', $m['name']);
        $this->assertSame('ada@x.io', $m['email']);
        $this->assertSame('+2348031234567', $m['phone']);

        // A just-edited phone is honoured (fresh read, not a session copy).
        DB::table('gates_users')->where('id', $id)->update(['phone' => '+233201234567']);
        $this->assertSame('+233201234567', UserAccountService::memberForForms()['phone']);
    }

    public function test_non_active_account_gets_null(): void
    {
        $id = DB::table('gates_users')->insertGetId([
            'name' => 'Sus Pended', 'email' => 'sus@x.io', 'status' => 'suspended',
            'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $_SESSION['user_id'] = $id;
        $this->assertNull(UserAccountService::memberForForms());
    }
}
