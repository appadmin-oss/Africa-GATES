<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\FinanceService;
use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdCheckout;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdMarket;
use AfricaGates\Services\Ads\AdReview;
use AfricaGates\Services\PaymentDestination;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Paying for a campaign (owner, 3 Nov 2026: "please do"). Prepaid with VAT on top as its own
 * line, under its own finance stream; it runs from when it is both paid and approved; what it
 * does not spend goes back once, with its VAT; a chargeback ends it.
 */
final class AdCheckoutTest extends TestCase
{
    private object $pay;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1000']);
        AdMarket::forget();
        $this->pay = new class extends PaymentService {
            public array $init = [], $refunds = [];
            public int $paidNaira = 0;
            public bool $refuse = false;
            public function enabledProviderIds(): array { return ['paystack']; }
            public function isEnabled(string $provider): bool { return true; }
            public function initialize(string $provider, int $amountNaira, string $email, string $reference, string $callbackUrl, array $meta = [], string $planCode = ''): array
            {
                $this->init[] = [$amountNaira, $reference];
                return ['ok' => true, 'checkout_url' => 'https://checkout.example/' . $reference, 'message' => ''];
            }
            public function verify(string $provider, string $reference): array
            {
                return ['ok' => true, 'status' => $this->paidNaira > 0 ? 'success' : 'pending', 'amount' => $this->paidNaira, 'currency' => 'NGN', 'meta' => []];
            }
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array
            {
                $this->refunds[] = [$reference, $amountNaira];
                return $this->refuse ? ['ok' => false, 'status' => 'failed', 'message' => 'no'] : ['ok' => true, 'status' => 'refunded', 'message' => ''];
            }
        };
    }

    private function user(): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi Ade', 'email' => 'k' . bin2hex(random_bytes(3)) . '@example.test',
            'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array{0:int,1:string} campaign id and reference */
    private function book(int $uid, int $budget = 20000, int $days = 10, array $places = ['discover', 'vote_hub']): array
    {
        $r = AdCheckout::create($uid, ['objective' => 'traffic', 'advertiser' => 'Mama Put', 'category' => 'general', 'placements' => $places,
            'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos.', 'cta' => 'shop_now',
            'url' => 'https://mamaput.example.ng', 'standards' => '1', 'budget' => (string) $budget, 'days' => (string) $days], '/uploads/ads/x.jpg');
        $this->assertTrue($r['ok'], json_encode($r));
        return [(int) $r['id'], (string) $r['reference']];
    }

    private function adOf(int $cid): object
    {
        return DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $cid)->select('a.*', 's.id as set_id')->first();
    }

    public function test_a_booking_is_the_budget_plus_vat_on_its_own_stream(): void
    {
        [$cid, $ref] = $this->book($this->user());
        $this->assertSame('ads', PaymentDestination::streamForReference($ref), 'advertising money counted as votes');
        $c = DB::table('gates_ad_campaigns')->where('id', $cid)->first();
        $set = DB::table('gates_ad_sets')->where('campaign_id', $cid)->first();
        $this->assertSame(2000000, (int) $set->budget_kobo);
        $this->assertSame(150000, (int) $c->vat_kobo, '7.5% VAT on ₦20,000 is ₦1,500');
        $this->assertSame('discover', $set->placements, 'a place not on sale was booked');
        $this->assertSame(['discover' => 100000], json_decode($set->rates, true), 'not at today’s price');

        $this->assertTrue(AdCheckout::start($this->pay, $cid, (int) $c->user_id, 'k@example.test', 'https://x/cb')['ok']);
        $this->assertSame([21500, $ref], $this->pay->init[0], 'the gateway was asked for the budget without its VAT');

        $this->assertFalse(AdCheckout::create($this->user(), ['budget' => '100', 'days' => '0'], null)['ok']);
    }

    public function test_nothing_runs_before_it_is_paid_and_approved_and_then_it_runs_the_days_bought(): void
    {
        $uid = $this->user();
        [$cid, $ref] = $this->book($uid, 20000, 10);
        $ad = $this->adOf($cid);
        $this->assertSame('unpaid', AdCampaigns::summary(DB::table('gates_ad_campaigns')->find($cid))['delivery']);
        AdReview::decide((int) $ad->id, 1, true);
        $this->assertNull(DB::table('gates_ad_sets')->where('id', $ad->set_id)->value('starts_at'), 'it started unpaid');
        $this->assertNull(AdDelivery::pick('discover', ['reader' => true]));

        $this->pay->paidNaira = 21000;                        // short by the VAT
        $this->assertSame('mismatch', AdCheckout::confirm($ref, $this->pay)['state']);
        $this->pay->paidNaira = 21500;
        $this->assertSame('confirmed', AdCheckout::confirm($ref, $this->pay)['state']);
        $this->assertSame('already', AdCheckout::confirm($ref, $this->pay)['state'], 'the webhook and the return page both counted it');
        $s = DB::table('gates_ad_sets')->where('id', $ad->set_id)->first();
        $this->assertNotNull($s->starts_at);
        $this->assertSame(10, (int) round((strtotime($s->ends_at) - strtotime($s->starts_at)) / 86400), 'not the days bought');
        $this->assertSame('Mama Put', AdDelivery::pick('discover', ['reader' => true])['advertiser'] ?? null);
    }

    public function test_paid_first_it_waits_for_the_review(): void
    {
        [$cid, $ref] = $this->book($this->user());
        $ad = $this->adOf($cid);
        $this->pay->paidNaira = 21500;
        AdCheckout::confirm($ref, $this->pay);
        $this->assertNull(DB::table('gates_ad_sets')->where('id', $ad->set_id)->value('starts_at'),
            'its days started running before a person had checked it');
        AdReview::decide((int) $ad->id, 1, true);
        $this->assertNotNull(DB::table('gates_ad_sets')->where('id', $ad->set_id)->value('starts_at'), 'approval did not start it');
    }

    public function test_what_was_not_spent_goes_back_once_with_its_vat(): void
    {
        $uid = $this->user();
        [$cid, $ref] = $this->book($uid, 20000, 10);
        $ad = $this->adOf($cid);
        AdReview::decide((int) $ad->id, 1, true);
        $this->pay->paidNaira = 21500;
        AdCheckout::confirm($ref, $this->pay);
        // 5,000 views at ₦1,000 per 1,000 = ₦5,000 spent of ₦20,000; then it is cancelled.
        DB::table('gates_ad_stats')->insert(['ad_id' => $ad->id, 'placement' => 'discover', 'day' => date('Y-m-d'), 'views' => 5000, 'clicks' => 50]);
        $this->assertSame(0, AdCheckout::settle($this->pay), 'a running campaign was refunded');
        AdCampaigns::setStatus($cid, $uid, AdCampaigns::ST_CANCELLED);

        $this->pay->refuse = true;
        AdCheckout::settle($this->pay);
        $this->assertNull(DB::table('gates_ad_campaigns')->where('id', $cid)->value('refunded_at'), 'a refused refund was recorded as made');
        $this->pay->refuse = false;
        $this->assertSame(1, AdCheckout::settle($this->pay));
        $this->assertSame(0, AdCheckout::settle($this->pay), 'refunded twice');
        $this->assertSame([$ref, 16125], end($this->pay->refunds), '₦15,000 unspent plus its ₦1,125 VAT');

        $inc = AdCheckout::income();
        $this->assertSame(5000, $inc['gross'], 'Finance counts the VAT, or the refund, as income');
        $this->assertSame(375, $inc['vat']);
        $this->assertSame(5000, FinanceService::bySource()['ads']['gross']);
    }

    public function test_a_chargeback_ends_it(): void
    {
        $uid = $this->user();
        [$cid, $ref] = $this->book($uid);
        $this->pay->paidNaira = 21500;
        AdCheckout::confirm($ref, $this->pay);
        $this->assertTrue(AdCheckout::reverse($ref, 'charge.dispute.create'));
        $this->assertSame('cancelled', AdCampaigns::summary(DB::table('gates_ad_campaigns')->find($cid))['delivery']);
        $this->assertSame(0, AdCheckout::settle($this->pay), 'money already taken back by the bank was refunded again');
        $this->assertStringContainsString("if (\$stream === 'ads')", ChromeRender::code('src/Controllers/PaymentController.php'));
    }

    public function test_the_builder_offers_it_and_the_policy_states_the_vat(): void
    {
        $this->assertStringContainsString('VAT at 7.5%', \AfricaGates\Services\LegalDocument::advertisingHtml());
        $src = ChromeRender::source('templates/pages/account/ads-new.twig');
        $this->assertStringContainsString('name="kind" value="paid"', $src);
        $this->assertStringContainsString('name="budget"', $src);
        $this->assertStringContainsString('action="/account/ads/{{ c.id }}/pay"', ChromeRender::source('templates/pages/account/ads-campaign.twig'));
    }
}
