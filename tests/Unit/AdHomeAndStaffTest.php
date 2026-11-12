<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdCheckout;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdMarket;
use AfricaGates\Services\Ads\AdReview;
use AfricaGates\Services\Ads\AdStaff;
use AfricaGates\Services\PaymentService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The homepage ad slot and ads posted by staff (owner, 4 Nov 2026: "build the homepage ad
 * slot the way you described. Allow admin be able to post ads … make it more enticing").
 *
 * The homepage takes paying campaigns and our own and never a free trial or the "advertise
 * here" notice; staff post two kinds, labelled for what they are; delivery serves paid,
 * then trials, then our own; and the card carries its offer on the picture and one filled
 * button.
 */
final class AdHomeAndStaffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        foreach (['discover' => '1000', 'home' => '2000'] as $p => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_' . $p], ['value' => $v]);
        }
        AdMarket::forget();
    }

    private function user(): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi Ade', 'email' => 'k' . bin2hex(random_bytes(3)) . '@example.test',
            'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string,mixed> */
    private function creative(array $over = []): array
    {
        return $over + ['objective' => 'traffic', 'advertiser' => 'Mama Put', 'category' => 'general',
            'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos.', 'cta' => 'shop_now',
            'url' => 'https://mamaput.example.ng', 'standards' => '1', 'offer' => '10% off this week', 'offer_code' => 'TRAY10'];
    }

    private function liveTrial(array $places): int
    {
        $r = AdCampaigns::createTrial($this->user(), $this->creative(['placements' => $places, 'advertiser' => 'Trial Co']), '/uploads/ads/t.jpg');
        $this->assertTrue($r['ok'], json_encode($r));
        $ad = DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        AdReview::decide((int) $ad, 1, true);
        return (int) $r['id'];
    }

    public function test_the_homepage_never_takes_a_free_trial_or_the_advertise_here_notice(): void
    {
        $r = AdCampaigns::createTrial($this->user(), $this->creative(['placements' => ['home']]), '/uploads/ads/t.jpg');
        $this->assertFalse($r['ok'], 'a trial booked the homepage');
        $cid = $this->liveTrial(['home', 'discover']);
        // And delivery refuses it on its own, for a trial booked before the rule (or by hand).
        DB::table('gates_ad_sets')->where('campaign_id', $cid)->update(['placements' => 'discover,home']);
        $this->assertNull(AdDelivery::pick('home', ['reader' => true]), 'a free trial was shown on the homepage');
        $this->assertNotNull(AdDelivery::pick('discover', ['reader' => true]));
        $this->assertStringContainsString("!in_array(\$placement, AdRules::PAID_ONLY, true) ? self::house()", ChromeRender::code('src/Services/Ads/AdDelivery.php'),
            'the "advertise here" notice fills the homepage');
    }

    public function test_a_sale_posted_by_staff_runs_at_once_as_sponsored_and_is_income(): void
    {
        $r = AdStaff::post(7, $this->creative(['source' => 'direct', 'placements' => ['home'], 'budget' => '50000', 'days' => '14']), '/uploads/ads/d.jpg');
        $this->assertTrue($r['ok'], json_encode($r));
        $c = DB::table('gates_ad_campaigns')->find($r['id']);
        $this->assertSame('direct', $c->source);
        $this->assertSame(5375000, (int) $c->paid_kobo, '₦50,000 and 7.5% VAT recorded as paid');
        $this->assertSame('approved', DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.review'));
        $ad = AdDelivery::pick('home', ['reader' => true]);
        $this->assertSame('Mama Put', $ad['advertiser'] ?? null, 'a direct sale did not reach the homepage');
        $this->assertFalse($ad['own'], 'a business paid for it: it is Sponsored, not ours');
        $this->assertSame(50000, AdCheckout::income()['gross'], 'its money is not advertising income');

        AdCampaigns::setStatus((int) $r['id'], 0, AdCampaigns::ST_CANCELLED);
        DB::table('gates_ad_campaigns')->where('id', $r['id'])->update(['status' => 'cancelled']);
        $pay = new class extends PaymentService {
            public array $refunds = [];
            public function refund(string $provider, string $reference, ?int $amountNaira = null): array { $this->refunds[] = $reference; return ['ok' => true, 'status' => 'refunded', 'message' => '']; }
        };
        AdCheckout::settle($pay);
        $this->assertSame([], $pay->refunds, 'the gateway was asked to refund money it never took');
    }

    public function test_our_own_ad_says_so_and_only_fills_space_nobody_bought(): void
    {
        $house = AdStaff::post(7, $this->creative(['source' => 'house', 'placements' => ['discover'], 'days' => '7', 'offer' => '', 'offer_code' => '']), '/uploads/ads/h.jpg');
        $this->assertTrue($house['ok'], json_encode($house));
        $own = AdDelivery::pick('discover', ['reader' => true]);
        $this->assertSame('Africa GATES', $own['advertiser'] ?? null);
        $this->assertTrue($own['own']);

        $_SESSION = [];
        $this->liveTrial(['discover']);
        $this->assertSame('Trial Co', AdDelivery::pick('discover', ['reader' => true])['advertiser'] ?? null,
            'our own ad took a slot a trial wanted');

        $tpl = ChromeRender::source('templates/partials/ad-slot.twig');
        $this->assertStringContainsString("_own ? 'From Africa GATES'|trans : 'Sponsored'|trans", $tpl);
        $this->assertTrue(AdStaff::setStatus((int) $house['id'], AdCampaigns::ST_PAUSED));
    }

    public function test_staff_cannot_switch_off_an_advertisers_own_campaign_from_here(): void
    {
        $cid = $this->liveTrial(['discover']);
        $this->assertFalse(AdStaff::setStatus($cid, AdCampaigns::ST_CANCELLED), 'the advertiser’s campaign is theirs and moderation’s');
    }

    public function test_the_console_page_posts_and_lists(): void
    {
        $aid = (int) DB::table('gates_admins')->insertGetId(['name' => 'Kemi', 'email' => 'kemi@example.com', 'role' => 'admin', 'is_active' => 1, 'password_hash' => 'x']);
        $html = ChromeRender::html('/admin/ads', ['admin_id' => $aid, 'admin_role' => 'admin', 'admin_name' => 'Kemi']);
        $this->assertStringContainsString('action="/admin/ads"', $html, 'an admin cannot post');
        $this->assertStringContainsString('value="direct"', $html);
        $this->assertStringContainsString('value="house"', $html);
        $this->assertSame('finance', \AfricaGates\Admin\Support\Permissions::sectionForPath('/admin/ads'));
        $this->assertTrue(\AfricaGates\Admin\Support\Permissions::canOpen('admin', '/admin/ads'));
        $this->assertFalse(\AfricaGates\Admin\Support\Permissions::canOpen('editor', '/admin/ads'));
    }

    public function test_the_homepage_draws_the_wide_card_and_the_card_is_more_enticing(): void
    {
        AdStaff::post(7, $this->creative(['source' => 'direct', 'placements' => ['home'], 'budget' => '50000', 'days' => '14']), '/uploads/ads/d.jpg');
        $html = (string) ChromeRender::page('/', [], [], 'GET', null, [
            'User-Agent' => 'Mozilla/5.0 Chrome/120', 'Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Accept-Language' => 'en-GB',
        ])->getBody();
        $this->assertStringContainsString('class="ag-ad ag-ad--wide"', $html, 'no ad band on the homepage');
        $this->assertStringContainsString('Sponsored', $html);
        $this->assertMatchesRegularExpression('~<div class="ag-ad__media[^"]*">.*?class="ag-ad__offer"[^>]*>10% off this week~s', $html,
            'the offer is not on the picture');
        $this->assertStringContainsString('<span class="ag-btn ag-ad__cta"', $html, 'the button is an outline again');
        $this->assertStringContainsString('/assets/js/ads.js', $html, 'views on the homepage are never counted');
    }

    public function test_advertise_answers_the_first_questions_with_the_rules_own_figures(): void
    {
        $html = ChromeRender::html('/advertise');
        $this->assertStringContainsString('Questions advertisers ask', $html);
        $this->assertStringContainsString('₦' . number_format(AdCheckout::MIN_BUDGET_NAIRA), $html);
        $this->assertStringContainsString('Paid campaigns only', $html, 'the homepage reads as trial space');
        $this->assertStringContainsString('href="#plan"', $html);
    }
}
