<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The link in every monthly-gift receipt. It was a 500 from 3 Oct (the template was
 * destroyed with the old pages), so the one control that lets a donor stop giving us
 * money did not exist for anybody.
 */
final class DonorGiftPageTest extends TestCase
{
    private function gift(string $status): string
    {
        $token = bin2hex(random_bytes(16));
        DB::table('gates_donation_subscriptions')->insert([
            'donor_name' => 'Ada N', 'donor_email' => 'ada@example.com', 'amount_naira' => 5000,
            'status' => $status, 'manage_token' => $token, 'provider' => 'paystack', 'plan_code' => 'PLN_x',
            'interval_name' => 'monthly',
        ]);
        return $token;
    }

    public function test_an_active_gift_renders_with_its_stop_button(): void
    {
        $t = $this->gift('active');
        $html = ChromeRender::html(\AfricaGates\Support\GivingUrl::manage($t));
        $this->assertStringContainsString('₦5,000', $html);
        $this->assertStringContainsString('Stop my monthly gift', $html);
        $this->assertStringContainsString(\AfricaGates\Support\GivingUrl::stop($t), $html, 'the form posts to the stop route');
    }

    public function test_a_stopped_gift_says_so_and_offers_no_stop(): void
    {
        $html = ChromeRender::html(\AfricaGates\Support\GivingUrl::manage($this->gift('cancelled')));
        $this->assertStringContainsString('you will not be charged again', $html);
        $this->assertStringNotContainsString('Stop my monthly gift', $html);
    }

    public function test_an_unknown_link_is_a_page_not_an_error(): void
    {
        $html = ChromeRender::html(\AfricaGates\Support\GivingUrl::manage(str_repeat('a', 32)));
        $this->assertStringContainsString('This link is not valid', $html);
    }
}
