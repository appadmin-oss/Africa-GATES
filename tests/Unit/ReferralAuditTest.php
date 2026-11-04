<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventTicketService;
use AfricaGates\Services\ReferralPayout;
use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The referral commission audit (owner, 3 Nov 2026: "the community return was not the only
 * sharing I was talking about"). Each case is commission paid where it should not be, or a
 * payout that could be recorded wrongly, while every screen looked ordinary.
 */
final class ReferralAuditTest extends TestCase
{
    private const OWNER = 5;
    private int $reg = 100;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_users')->insert(['id' => self::OWNER, 'name' => 'Amara Okonkwo', 'email' => 'amara@example.com',
            'phone_e164' => '+2348031234567', 'status' => 'active', 'email_verified' => 1]);
        DB::table('gates_referral_codes')->insert(['id' => 1, 'user_id' => self::OWNER, 'code' => 'AGBCD234', 'created_at' => '2026-08-01 10:00:00']);
    }

    /** A paid registration on the owner's code, through the real credit path. */
    private function sale(string $email, int $naira = 10000, string $phone = '+2348000000000'): int
    {
        $id = (int) DB::table('gates_event_registrations')->insertGetId(['event_id' => 1, 'name' => 'Buyer', 'email' => $email,
            'phone' => $phone, 'amount_naira' => $naira, 'reference' => 'EVT-' . (++$this->reg), 'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s')]);
        ReferralService::creditSale('registration', $id, 'AGBCD234', $naira, null, null, $email);
        return $id;
    }

    private function credits(): int
    {
        return (int) DB::table('gates_referral_credits')->where('user_id', self::OWNER)->count();
    }

    public function test_the_owner_cannot_buy_through_their_own_link_under_another_spelling(): void
    {
        $this->sale('amara+1@example.com');
        $this->assertSame(0, $this->credits(), 'a "+tag" of the owner’s own address earned commission');
        $this->sale('somebody@else.test', 10000, '0803 123 4567');
        $this->assertSame(0, $this->credits(), 'bought with the owner’s own phone number, under another address');
        $this->sale('friend@else.test');
        $this->assertSame(1, $this->credits(), 'a genuine friend still earns the referrer commission');
    }

    public function test_the_gate_counts_people_not_purchases(): void
    {
        for ($i = 0; $i < ReferralService::threshold(); $i++) $this->sale('one.person@else.test', 1000);
        $s = ReferralService::stats(self::OWNER);
        $this->assertSame(1, $s['referrals'], 'ten orders by one buyer unlocked "ten people have paid"');
        $this->assertFalse($s['unlocked']);
        for ($i = 0; $i < ReferralService::threshold(); $i++) $this->sale('p' . $i . '@else.test', 1000);
        $this->assertTrue(ReferralService::stats(self::OWNER)['unlocked']);
    }

    public function test_a_released_seat_refunded_at_the_gateway_takes_its_commission_back(): void
    {
        $id = $this->sale('friend@else.test');
        // The organiser releases the seat (no refund recorded), then refunds it in the gateway.
        DB::table('gates_event_registrations')->where('id', $id)->update(['status' => 'cancelled']);
        EventTicketService::reverse((string) DB::table('gates_event_registrations')->where('id', $id)->value('reference'), 'refund.processed');
        $this->assertNotNull(DB::table('gates_referral_credits')->where('source_id', $id)->value('reversed_at'),
            'commission stayed on a ticket the buyer had their money back for');
    }

    public function test_shop_commission_is_on_the_goods_not_the_delivery(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ShopOrderService.php');
        $this->assertStringContainsString("max(0, (int) \$order->subtotal_naira - (int) (\$order->shipping_naira ?? 0))", $src,
            'commission paid on the courier’s fee');
    }

    public function test_one_request_is_settled_once_and_its_credits_follow_its_outcome(): void
    {
        for ($i = 0; $i < ReferralService::threshold(); $i++) $this->sale('p' . $i . '@else.test');
        $r = ReferralPayout::request(self::OWNER, 'GTBank', 'Amara Okonkwo', '0123456789');
        $this->assertTrue($r['ok'], $r['message']);
        $id = (int) $r['id'];
        $this->assertTrue(ReferralPayout::reject($id, 'Wrong account')['ok']);
        // Recording it paid now must change nothing: refused, with its credits still owed.
        // (Sequentially the early status read refuses this; the claim inside markPaid's
        // transaction is what holds when two people press at once, and no test here stages that.)
        $this->assertFalse(ReferralPayout::markPaid($id, 'REF-1', 1, true)['ok'], 'a refused request was recorded as paid');
        $this->assertSame(0, (int) DB::table('gates_referral_credits')->whereNotNull('paid_out_at')->count(),
            'credits stamped paid under a refused request — owed, and impossible to ask for again');
        $this->assertSame('rejected', (string) DB::table('gates_referral_payouts')->where('id', $id)->value('status'));
    }

    public function test_commission_paid_then_refunded_is_shown_not_netted_silently(): void
    {
        $id = $this->sale('friend@else.test');
        DB::table('gates_referral_credits')->where('source_id', $id)->update(['paid_out_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue(ReferralService::reverseSale('registration', $id, 'refund'));
        $this->assertSame(1000, ReferralService::liability()['paid_then_reversed_naira']);
    }

    public function test_looking_at_an_event_never_creates_a_referral_code(): void
    {
        DB::table('gates_users')->insert(['id' => 6, 'name' => 'Chidi', 'email' => 'chidi@example.com']);
        $this->assertNull(ReferralService::existingCode(6));
        $this->assertSame(0, (int) DB::table('gates_referral_codes')->where('user_id', 6)->count(), 'asking whether there is a code made one');
        foreach (['src/Controllers/EventsController.php', 'src/Services/EventFlier.php'] as $f) {
            $this->assertStringNotContainsString('ReferralService::codeFor(', ChromeRender::code($f), "$f mints a code on a GET");
        }
        $this->assertStringNotContainsString('action="/account/payout"', ChromeRender::source('templates/pages/account/dashboard.twig'),
            'a payout form that skips the wallet’s checks');
    }
}
