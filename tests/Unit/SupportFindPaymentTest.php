<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupportContext;
use AfricaGates\Services\SupportPlan;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "I paid, and I don't know the reference."
 *
 * A real ticket asked for the AFG- reference three times, was told three times that the
 * person did not have it, and was handed to the team — about a payment the platform could
 * have found from the address alone. find_payment is that path: shown in the chat for the
 * person's own address, sent to the inbox for any other, and worded identically whether or
 * not a stranger's address ever paid.
 */
final class SupportFindPaymentTest extends TestCase
{
    private const REF = 'AFG-PVOTE-FINDME000001';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        DB::table('gates_donations')->insert([
            'donor_name' => 'Ada N', 'donor_email' => 'ada@example.com', 'amount_naira' => 2000,
            'status' => 'confirmed', 'payment_ref' => self::REF, 'tier' => 'paid-vote',
            'bonus_votes' => 10, 'votes_used' => 10, 'created_at' => date('Y-m-d H:i:s', time() - 86400),
        ]);
    }

    public function test_a_member_finds_their_own_payment_without_the_reference(): void
    {
        $r = (new SupportContext(viewerId: 42, viewerEmail: 'ada@example.com'))->run('find_payment', ['email' => ''])['data'];
        $this->assertSame('FOUND', $r['outcome'] ?? null);
        $this->assertSame(self::REF, $r['payments'][0]['reference'] ?? null);
        $this->assertStringContainsString('all 10 votes delivered', $r['payments'][0]['state']);
    }

    public function test_the_address_this_browser_typed_is_answered_in_the_chat(): void
    {
        $_SESSION['user_login_email'] = 'ada@example.com';
        $r = (new SupportContext())->run('find_payment', ['email' => 'Ada@Example.com'])['data'];
        $this->assertSame(self::REF, $r['payments'][0]['reference'] ?? null);
    }

    public function test_somebody_elses_address_is_never_answered_in_the_chat(): void
    {
        $paid  = (new SupportContext())->run('find_payment', ['email' => 'ada@example.com'])['data'];
        $never = (new SupportContext())->run('find_payment', ['email' => 'nobody@example.com'])['data'];
        $this->assertArrayNotHasKey('payments', $paid);
        $this->assertStringNotContainsString(self::REF, json_encode($paid));
        // One sentence for both, so this cannot be asked "did X pay us?".
        $this->assertSame($paid['say'], $never['say']);
        $this->assertSame($paid['outcome'], $never['outcome']);
    }

    public function test_no_address_asks_for_the_address_not_the_reference(): void
    {
        $r = (new SupportContext())->run('find_payment', ['email' => ''])['data'];
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('email address', $r['say']);
        $this->assertStringNotContainsStringIgnoringCase('reference', $r['say']);
    }

    public function test_the_plan_runs_it_when_the_reference_is_missing(): void
    {
        $guest  = SupportPlan::steps("I paid but I don't know the reference. I used ada@example.com", new SupportContext());
        $this->assertContains('find_payment', array_column($guest, 'tool'));
        $member = SupportPlan::steps("I don't no the reference no", new SupportContext(viewerId: 42, viewerEmail: 'ada@example.com'));
        $this->assertContains('find_payment', array_column($member, 'tool'));
        // A reference in hand still goes straight to the repair, not the search.
        $withRef = SupportPlan::steps('I paid, ref ' . self::REF . ', no votes', new SupportContext());
        $this->assertNotContains('find_payment', array_column($withRef, 'tool'));
    }
}
