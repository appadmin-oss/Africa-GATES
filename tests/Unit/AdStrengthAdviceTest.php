<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdAdvice;
use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdMarket;
use AfricaGates\Services\Ads\AdPlanner;
use AfricaGates\Services\Ads\AdRateCard;
use AfricaGates\Services\Ads\AdReview;
use AfricaGates\Services\Ads\AdRules;
use AfricaGates\Services\Ads\AdStaff;
use AfricaGates\Services\Ads\AdStrength;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * What the large self-serve platforms put around an ad, brought here (owner, 11 Nov 2026:
 * "research top services too … improve the ads and ads management"):
 *
 *   · ad strength with the change worth most (Google), which moves nothing about delivery;
 *   · a forecast of what the budget reaches, and how much of it the places can take
 *     (LinkedIn, Meta), stated before anybody pays;
 *   · click-to-WhatsApp (Meta), the way most small businesses here actually sell;
 *   · pacing and "what to try next" on a running campaign, read off its own rows;
 *   · and a console page per campaign with the list filtered as Ads Manager filters it.
 */
final class AdStrengthAdviceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1000']);
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
        return $over + ['objective' => 'traffic', 'advertiser' => 'Mama Put', 'category' => 'general', 'placements' => ['discover'],
            'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos, hot and on time.', 'cta' => 'shop_now',
            'url' => 'https://mamaput.example.ng', 'standards' => '1'];
    }

    // ── strength ─────────────────────────────────────────────────────────────

    public function test_a_complete_ad_is_excellent_and_a_thin_one_is_told_the_change_worth_most_first(): void
    {
        $full = AdStrength::score(['headline' => 'Party jollof by the tray', 'body' => 'Hot trays for 20 guests, delivered anywhere in Lagos the same day.',
            'cta' => 'shop_now', 'offer' => '10% off this week'], 'sales', true, true);
        $this->assertSame('excellent', $full['level']);
        $this->assertSame(100, $full['score']);
        $this->assertSame([], $full['tips']);

        // The headline (10 points) comes before the offer (15) on the card; the tip worth more comes first.
        $thin = AdStrength::score(['headline' => 'The very best party jollof in Lagos', 'body' => 'Good food here.', 'cta' => 'learn_more', 'offer' => ''], 'sales', true, false);
        $this->assertContains($thin['level'], ['poor', 'average']);
        $at = static function (string $start) use ($thin): int {
            foreach ($thin['tips'] as $i => $t) if (str_starts_with($t, $start)) return $i;
            return -1;
        };
        $this->assertGreaterThanOrEqual(0, $at('Shorten the headline'));
        $this->assertLessThan($at('Shorten the headline'), $at('Add an offer'), 'a 10-point tip came before a 15-point one');
        $this->assertSame(0, $at('Say a little more'), 'the heaviest missing check is not the first tip');
        $this->assertTrue((bool) array_filter($thin['tips'], static fn ($t) => str_contains($t, 'button')), 'a selling ad with "Learn more" was not told');

        $loud = AdStrength::score(['headline' => 'BEST JOLLOF IN LAGOS', 'body' => 'Order now!! Party trays for 20, delivered.', 'cta' => 'shop_now', 'offer' => 'Free delivery'], 'traffic', true, true);
        $this->assertTrue((bool) array_filter($loud['tips'], static fn ($t) => str_contains($t, 'sentence case')), 'shouting passed');
        $bang = AdStrength::score(['headline' => 'Best jollof in Lagos', 'body' => 'Order now! Party trays for 20, delivered!', 'cta' => 'shop_now', 'offer' => 'Free delivery'], 'traffic', true, true);
        $this->assertTrue((bool) array_filter($bang['tips'], static fn ($t) => str_contains($t, 'sentence case')), 'exclamation marks passed');
        $calm = AdStrength::score(['headline' => 'ARCON-approved KFC catering', 'body' => 'Party trays for 20 people, delivered across Lagos with VAT included.', 'cta' => 'shop_now', 'offer' => 'Free delivery'], 'traffic', true, true);
        $this->assertFalse((bool) array_filter($calm['tips'], static fn ($t) => str_contains($t, 'sentence case')), 'three acronyms in a sentence read as shouting');

        $same = AdStrength::score(['headline' => 'Party trays', 'body' => 'Party trays delivered across Lagos for 20 guests.', 'cta' => 'shop_now', 'offer' => 'x'], 'traffic', true, true);
        $this->assertTrue((bool) array_filter($same['tips'], static fn ($t) => str_contains($t, 'Repeating')), 'a headline repeating the text passed');
    }

    public function test_strength_is_words_only_and_never_decides_price_or_delivery(): void
    {
        foreach (['AdDelivery', 'AdMarket', 'AdPlanner', 'AdCheckout', 'AdReview', 'AdRateCard'] as $cls) {
            $this->assertStringNotContainsString('AdStrength', ChromeRender::code('src/Services/Ads/' . $cls . '.php'),
                $cls . ' reads the strength score: an advertiser would be paying for a heuristic’s opinion of their copy');
        }
    }

    // ── WhatsApp ─────────────────────────────────────────────────────────────

    public function test_a_whatsapp_button_takes_a_phone_number_and_opens_a_chat_that_says_where_it_came_from(): void
    {
        $c = AdRules::creative($this->creative(['cta' => 'whatsapp', 'url' => '0803 123 4567']));
        $this->assertSame([], $c['errors']);
        $this->assertStringStartsWith('https://wa.me/2348031234567?text=', $c['row']['url'], 'a local number lost its country code');
        $this->assertStringContainsString(rawurlencode(AdRules::WHATSAPP_GREETING), $c['row']['url']);
        $this->assertSame('WhatsApp', AdRules::displayDomain($c['row']['url']));
        $this->assertArrayHasKey('url', AdRules::creative($this->creative(['cta' => 'whatsapp', 'url' => 'https://mamaput.example.ng']))['errors'],
            'a WhatsApp button pointing at a website');
        $this->assertArrayHasKey('url', AdRules::creative($this->creative(['cta' => 'whatsapp', 'url' => '12345']))['errors']);
        $this->assertStringContainsString('text=Hi%20there', (string) AdRules::whatsappUrl('https://wa.me/2348031234567?text=Hi%20there'),
            'the advertiser’s own greeting was replaced');

        // And it goes all the way: approved, drawn, and a tap lands in the chat.
        $r = AdCampaigns::createTrial($this->user(), $this->creative(['cta' => 'whatsapp', 'url' => '+234 803 123 4567']), '/uploads/ads/t.jpg');
        $this->assertTrue($r['ok'], json_encode($r));
        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        AdReview::decide($ad, 1, true);
        $drawn = AdDelivery::pick('discover', ['reader' => true]);
        $this->assertSame('Chat on WhatsApp', $drawn['cta'] ?? null);
        $this->assertSame('WhatsApp', $drawn['domain']);
        $this->assertStringStartsWith('https://wa.me/2348031234567', (string) AdDelivery::click((string) $drawn['token'], true));
    }

    // ── the forecast ─────────────────────────────────────────────────────────

    /** A measured discover: 1,000 slots and 600 views a day, a 1% click rate. */
    private function measuredDiscover(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $d = date('Y-m-d', time() - $i * 86400);
            DB::table('gates_ad_inventory')->insert(['placement' => 'discover', 'day' => $d, 'slots' => 1000]);
            DB::table('gates_ad_stats')->insert(['ad_id' => 99001, 'placement' => 'discover', 'day' => $d, 'views' => 600, 'clicks' => 6]);
        }
        AdMarket::forget();
    }

    public function test_the_forecast_says_what_a_budget_buys_and_how_much_the_places_cannot_take(): void
    {
        $this->measuredDiscover();
        $price = (int) AdMarket::price('discover');
        $room = (int) floor(600 * 7 * AdMarket::SHARE_MAX);

        $big = AdPlanner::forecast(100000, 7, ['discover']);
        $this->assertSame($room, $big['views'], 'a forecast promised more views than the place can give one advertiser');
        $this->assertSame(AdRateCard::cost($room, $price * 100), $big['spend_kobo']);
        $this->assertSame(10_000_000, $big['spend_kobo'] + $big['unspent_kobo'], 'the budget does not add up');
        $this->assertGreaterThan(0, $big['unspent_kobo']);
        $this->assertSame((int) floor($room * AdMarket::smoothedCtr('discover')), $big['visits']);

        $small = AdPlanner::forecast(1000, 7, ['discover']);
        $this->assertSame((int) floor(100000 * 1000 / ($price * 100)), $small['views'], 'a small budget was not spent at today’s price');
        $this->assertLessThan(100, $small['unspent_kobo']);

        $this->assertNull(AdPlanner::forecast(10000, 7, ['nowhere']));
    }

    public function test_the_builder_draws_both_panels_and_asks_the_server_for_them(): void
    {
        $this->measuredDiscover();
        $uid = $this->user();
        $me = ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid];
        $html = ChromeRender::html('/account/ads/new', $me);
        $this->assertStringContainsString('data-ad-strength', $html);
        $this->assertStringContainsString('value="whatsapp"', $html);
        $this->assertStringContainsString('name="url" type="text"', $html, 'a phone number is refused by type="url"');

        $res = ChromeRender::page('/account/ads/check', $me, [], 'POST', ['_token' => 'test-token', 'kind' => 'paid', 'budget' => '100000', 'days' => '7',
            'placements' => ['discover'], 'objective' => 'traffic', 'headline' => 'Jollof', 'body' => 'Good food here.', 'cta' => 'learn_more', 'has_image' => '1']);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $j = json_decode((string) $res->getBody(), true);
        $this->assertTrue($j['ok']);
        $this->assertStringContainsString('Ad strength', $j['strength']);
        $this->assertStringContainsString('visits to your page', $j['forecast']);
        $this->assertStringContainsString('would come back to you', $j['forecast'], 'the part the places cannot take was not said before paying');

        $adv = ChromeRender::html('/advertise');
        $this->assertStringContainsString('Chat on WhatsApp', $adv, 'the page selling ads never says it works without a website');
        $this->assertNotSame(200, ChromeRender::page('/account/ads/check', [], [], 'POST', ['_token' => 'test-token'])->getStatusCode(),
            'the panels answer somebody who is not signed in');

        $js = ChromeRender::code('public/assets/js/ads-builder.js');
        $this->assertStringContainsString("fetch('/account/ads/check'", $js);
        $this->assertMatchesRegularExpression('~/\^\(image\|logo\)\(_crop\)\?\$/~', $js, 'the builder would send the photo with every keystroke');
        $this->assertMatchesRegularExpression('~var tok = form\.querySelector\(\'input\[name="_token"\]\'\);\s*var mine~', $js,
            'the token is copied at load rather than read when it is sent');
    }

    // ── advice and pacing ────────────────────────────────────────────────────

    public function test_advice_is_read_off_the_campaigns_own_rows_and_never_says_spend_more(): void
    {
        $base = ['delivery' => 'active', 'benchmark' => 1.0, 'can_add' => true, 'leader' => null, 'places' => [],
                 'ads' => [['id' => 1, 'review' => 'approved', 'views' => 2000, 'clicks' => 4, 'headline' => 'Jollof by the tray',
                            'strength' => ['level' => 'good', 'label' => 'Good', 'tips' => ['x']]]]];
        $keys = array_column(AdAdvice::for($base), 'key');
        $this->assertSame(['weak_ad'], $keys, 'a 0.2% ad against a 1% average: told once, not twice, to add a version');
        $fine = $base;
        $fine['ads'][0]['clicks'] = 30;
        $this->assertSame(['test'], array_column(AdAdvice::for($fine), 'key'), 'a single version was not offered a test');
        $weakAndThin = $base;
        $weakAndThin['ads'][0]['strength'] = ['level' => 'poor', 'label' => 'Poor', 'tips' => ['Add an offer.']];
        $acts = array_column(AdAdvice::for($weakAndThin), 'action');
        $this->assertSame(1, count(array_filter($acts, static fn ($x) => $x === 'add')), 'the same link offered twice');

        $young = $base;
        $young['ads'][0]['views'] = 300;
        $young['ads'][0]['clicks'] = 0;
        $this->assertNotContains('weak_ad', array_column(AdAdvice::for($young), 'key'), 'judged on 300 views');

        $places = $base;
        $places['places'] = [['key' => 'discover', 'views' => 1000, 'clicks' => 20], ['key' => 'events', 'views' => 1000, 'clicks' => 2]];
        $this->assertContains('place', array_column(AdAdvice::for($places), 'key'));

        $done = $base;
        $done['delivery'] = 'completed';
        $this->assertSame([], AdAdvice::for($done));

        foreach (AdAdvice::for($places) as $a) {
            $this->assertDoesNotMatchRegularExpression('~(increase|raise|add (to )?(your|the) budget|spend more)~i', $a['text'], 'advice to spend more');
        }
    }

    public function test_pacing_says_when_a_budget_will_come_back_unspent(): void
    {
        $now = time();
        $c = ['delivery' => 'active', 'budget_kobo' => 2_000_000, 'spent_kobo' => 200_000,
              'starts_at' => date('Y-m-d H:i:s', $now - 5 * 86400), 'ends_at' => date('Y-m-d H:i:s', $now + 5 * 86400)];
        $p = AdAdvice::pace($c, $now);
        $this->assertSame('behind', $p['key']);
        $this->assertSame(50, $p['elapsed_pct']);
        $this->assertSame(1_600_000, $p['returned_kobo'], '₦2,000 spent in half the time comes to ₦4,000 of ₦20,000');
        $this->assertContains('pace', array_column(AdAdvice::for($c + ['ads' => []], $now), 'key'));

        $c['spent_kobo'] = 1_000_000;
        $this->assertSame('on_track', AdAdvice::pace($c, $now)['key']);
        $c['starts_at'] = date('Y-m-d H:i:s', $now - 3600);
        $this->assertNull(AdAdvice::pace($c, $now), 'judged an hour in');
        $this->assertNull(AdAdvice::pace(['delivery' => 'active', 'budget_kobo' => 0] + $c, $now), 'a trial has no pace');
    }

    public function test_the_campaign_page_shows_strength_on_every_version(): void
    {
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, $this->creative(), '/uploads/ads/t.jpg');
        $html = ChromeRender::html('/account/ads/' . $r['id'], ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringContainsString('Ad strength:', $html);
    }

    // ── the console ──────────────────────────────────────────────────────────

    public function test_staff_open_any_campaign_and_filter_the_list(): void
    {
        $aid = (int) DB::table('gates_admins')->insertGetId(['name' => 'Kemi', 'email' => 'kemi@example.com', 'role' => 'admin', 'is_active' => 1, 'password_hash' => 'x']);
        $as = ['admin_id' => $aid, 'admin_role' => 'admin', 'admin_name' => 'Kemi'];
        $direct = AdStaff::post($aid, $this->creative(['source' => 'direct', 'budget' => '50000', 'days' => '14', 'headline' => 'Sold on the phone']), '/uploads/ads/d.jpg');
        $self = AdCampaigns::createTrial($this->user(), $this->creative(['headline' => 'Booked by themselves']), '/uploads/ads/t.jpg');

        $page = ChromeRender::html('/admin/ads/' . $direct['id'], $as);
        $this->assertStringContainsString('Sold on the phone', $page);
        $this->assertStringContainsString('Posted by', $page);
        $this->assertStringContainsString('action="/admin/ads/' . $direct['id'] . '/pause"', $page);
        $other = ChromeRender::html('/admin/ads/' . $self['id'], $as);
        $this->assertStringContainsString('Booked by themselves', $other);
        $this->assertStringNotContainsString('/admin/ads/' . $self['id'] . '/pause', $other, 'staff can switch off an advertiser’s own campaign');

        $list = ChromeRender::html('/admin/ads?show=staff', $as);
        $this->assertStringContainsString('href="/admin/ads/' . $direct['id'] . '"', $list, 'the campaign page is linked from nowhere');
        $this->assertStringNotContainsString('href="/admin/ads/' . $self['id'] . '"', $list, 'the filter did not filter');
        $this->assertStringContainsString('data-tabs="ads"', $list);
        $this->assertStringContainsString('By place', $list);

        $this->assertFalse(\AfricaGates\Admin\Support\Permissions::canOpen('editor', '/admin/ads/' . $direct['id']));
    }
}
