<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdReview;
use AfricaGates\Services\Ads\AdStaff;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The ads pages as somebody uses them (owner, 11 Nov 2026: "improve UX"), each from a walk
 * through the real pages on a phone and a desktop:
 *
 *   · the builder's live preview came AFTER the send button on a phone, so nobody writing an
 *     ad saw it; it now follows the fields it shows, and a desktop lifts it into a column;
 *   · the builder is a flow: no tab bar, and its one action in a bar under the thumb;
 *   · a campaign's days start on the day it started, not fourteen rows of noughts;
 *   · "Add a version" opens the form it points at;
 *   · the empty Ads Manager no longer promises "a square image".
 */
final class AdsUxTest extends TestCase
{
    private function user(): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi Ade', 'email' => 'k' . bin2hex(random_bytes(3)) . '@example.test',
            'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string,string|int> */
    private function me(int $uid): array
    {
        return ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid];
    }

    public function test_the_builder_is_a_flow_with_its_preview_beside_the_fields_and_its_action_under_the_thumb(): void
    {
        $html = ChromeRender::html('/account/ads/new', $this->me($this->user()));
        $step3 = strpos($html, '3 of 3');
        $pv = strpos($html, 'id="ad-preview"');
        $agree = strpos($html, 'name="standards"');
        $this->assertNotFalse($pv);
        $this->assertTrue($step3 < $pv && $pv < $agree, 'the preview is not between the ad’s fields and the send button');
        $this->assertStringContainsString('class="ag-actionbar am-actbar"', $html);
        $this->assertMatchesRegularExpression('~<div class="ag-actionbar am-actbar"[^>]*>.*?<button type="submit"~s', $html, 'the bar has no button');
        $this->assertStringNotContainsString('class="ag-tabbar', $html, 'a flow kept the tab bar');

        $css = ChromeRender::code('public/assets/css/components/ads.css');
        $this->assertStringContainsString('.am-build__form{ display:contents }', $css, 'the desktop column is gone');
        $this->assertStringContainsString('.am-build__form > .am-build__pv{ grid-column:2; grid-row:1 / span 4; position:sticky', $css);
        $this->assertStringContainsString('.am-cats, .am-approvals{ margin:0; padding:0; border:0', $css, 'the browser’s groove is back round the choices');
        // A class the page uses for the bar must not collide with one ads.css already styles
        // (it did: .am-bar is the day table's bar, and it made the action bar static).
        $this->assertDoesNotMatchRegularExpression('~\.am-actbar\s*\{~', $css);
    }

    public function test_a_campaigns_days_start_when_it_did_and_the_advice_opens_the_form_it_names(): void
    {
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, ['objective' => 'traffic', 'advertiser' => 'Mama Put', 'category' => 'general', 'placements' => ['discover'],
            'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos.', 'cta' => 'shop_now',
            'url' => 'https://mamaput.example.ng', 'standards' => '1'], '/uploads/ads/t.jpg');
        $this->assertSame([], AdCampaigns::detail($r['id'], $uid)['days'], 'days listed for a campaign that has not started');
        $this->assertStringContainsString('Results appear here, day by day, from the day it starts.', ChromeRender::html('/account/ads/' . $r['id'], $this->me($uid)));

        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        AdReview::decide($ad, 1, true);
        $days = AdCampaigns::detail($r['id'], $uid)['days'];
        $this->assertCount(1, $days, 'thirteen days from before it started');
        $this->assertSame(date('Y-m-d'), $days[0]['day']);

        $closed = ChromeRender::html('/account/ads/' . $r['id'], $this->me($uid));
        $this->assertStringContainsString('href="?add=1#ad-version"', $closed);
        $this->assertDoesNotMatchRegularExpression('~id="ad-version" open~', $closed);
        $open = ChromeRender::html('/account/ads/' . $r['id'] . '?add=1', $this->me($uid));
        $this->assertMatchesRegularExpression('~id="ad-version" open~', $open, '"Add a version" lands on a closed form');
    }

    public function test_nothing_tells_an_advertiser_an_ad_must_be_square(): void
    {
        foreach (['templates/pages/account/ads.twig', 'templates/pages/account/ads-new.twig', 'templates/pages/account/ads-campaign.twig',
                  'templates/pages/advertise.twig', 'templates/partials/ad-slot.twig'] as $f) {
            $this->assertDoesNotMatchRegularExpression('~a square image|the square that shows|your square image~i', ChromeRender::source($f), $f);
        }
    }

    public function test_an_ad_carries_its_own_view_count_from_the_threshold(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'views_show_min'], ['value' => '100']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1000']);
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, ['objective' => 'traffic', 'advertiser' => 'Mama Put', 'category' => 'general', 'placements' => ['discover'],
            'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos.', 'cta' => 'shop_now',
            'url' => 'https://mamaput.example.ng', 'standards' => '1'], '/uploads/ads/t.jpg');
        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        AdReview::decide($ad, 1, true);
        $this->assertNull(\AfricaGates\Services\Ads\AdDelivery::pick('discover', ['reader' => true])['seen'], 'a count shown on an ad nobody has seen yet');

        DB::table('gates_ad_stats')->insert(['ad_id' => $ad, 'placement' => 'discover', 'day' => date('Y-m-d', time() - 86400), 'views' => 734, 'clicks' => 9]);
        $_SESSION = [];
        $seen = \AfricaGates\Services\Ads\AdDelivery::pick('discover', ['reader' => true])['seen'];
        $this->assertSame(['short' => '734', 'full' => '734'], $seen, 'the card’s number is not the views the advertiser is billed on');
        $this->assertSame($seen, AdCampaigns::detail($r['id'], $uid)['ads'][0]['seen'], 'the preview is not the card readers see');
        $this->assertSame($seen, AdCampaigns::library()['ads'][0]['seen']);
        $this->assertStringContainsString('<span class="ag-ad__seen"', ChromeRender::html('/account/ads/' . $r['id'], $this->me($uid)));
    }

    /**
     * Owner, 11 Nov 2026: "the ads still feel so unprofessional". Three things read as cheap,
     * and each is held here: a grey box with two letters where a logo goes, a black blob on a
     * grey strip, and a homepage band with a hole between its paragraph and its link bar.
     */
    public function test_the_card_is_finished_like_the_rest_of_the_site(): void
    {
        $uid = $this->user();
        $cid = AdCampaigns::createTrial($uid, ['objective' => 'traffic', 'advertiser' => 'Mama Put Kitchen', 'category' => 'general', 'placements' => ['discover'],
            'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos.', 'cta' => 'shop_now',
            'url' => 'https://mamaput.example.ng', 'standards' => '1'], '/uploads/ads/t.jpg')['id'];
        $html = ChromeRender::html('/account/ads/' . $cid, $this->me($uid));
        $tone = \AfricaGates\Support\AvatarMark::of('Mama Put Kitchen', null, 'business', 44)['tone'];
        $this->assertMatchesRegularExpression('~class="ag-ad__logo ag-ad__logo--' . $tone . '"~', $html, 'no logo still draws a grey placeholder box');
        $this->assertStringContainsString('<path d="M5 12h14M13 6l6 6-6 6"/></svg></span>', $html, 'the button does not point anywhere');

        $css = ChromeRender::code('public/assets/css/components/ad-slot.css');
        foreach (['green', 'gold', 'live', 'info', 'stone'] as $t) {
            $this->assertStringContainsString(".ag-ad__logo--$t{ background:var(--ag-cover-$t-bg)", $css, "the $t monogram has no colour");
        }
        $this->assertMatchesRegularExpression('~\.ag-ad__h\{ font-family:var\(--ag-font-display\)~', $css, 'the headline is not set like a headline');
        $this->assertMatchesRegularExpression('~\.ag-ad__foot\{[^}]*background:var\(--ag-surface\)~', $css, 'the link bar is a grey strip again');
        $this->assertStringContainsString('grid-template-rows:1fr auto auto auto 1fr', $css, 'the band\'s words are not one centred group');
        $this->assertStringContainsString('.ag-ad--wide .ag-ad__pic{ position:absolute; inset:0 }', $css, 'the image sets the band\'s height again');
    }

    public function test_the_card_reflows_by_its_content_and_the_wide_band_by_its_shape(): void
    {
        $css = ChromeRender::code('public/assets/css/components/ad-slot.css');
        $this->assertStringContainsString('.ag-ad__foot{ display:flex; flex-wrap:wrap;', $css, 'a long button squeezes the headline to two words on a phone');
        $this->assertStringContainsString('flex:1 1 170px', $css);
        $this->assertStringContainsString('.ag-ad--wide:has(.ag-ad__media--landscape){ grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) }', $css);
        $this->assertStringContainsString('.ag-ad--wide:has(.ag-ad__media--portrait){ grid-template-columns:minmax(0,300px) minmax(0,1fr) }', $css);
    }

    /**
     * The live fault (owner, 11 Nov 2026: a trial ran seven days for 43 views): site-nav.js
     * prefetches a page on the touch that starts every tap and shows that copy, and a
     * prefetch was drawn with no ad, so only the first page of a visit could carry one.
     */
    public function test_a_page_fetched_ahead_by_site_nav_carries_the_ad_without_spending_anything(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1000']);
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, ['objective' => 'traffic', 'advertiser' => 'Alimosho Choir', 'category' => 'events', 'placements' => ['discover'],
            'headline' => 'Hear the choir live', 'body' => 'An evening of choral music in Alimosho.', 'cta' => 'get_tickets',
            'url' => 'https://choir.example.ng', 'standards' => '1'], '/uploads/ads/t.jpg');
        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        AdReview::decide($ad, 1, true);

        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/discover')
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile')->withHeader('Accept-Language', 'en-NG')
            ->withHeader('Accept', 'text/html,application/xhtml+xml')->withHeader('X-Site-Nav', '1')->withHeader('X-Site-Prefetch', 'prefetch');
        $_SERVER['HTTP_X_SITE_PREFETCH'] = 'prefetch';
        try {
            $drawn = \AfricaGates\Services\Ads\AdDelivery::forRequest('discover', $req);
            $_SESSION = [];
            $this->assertSame('Alimosho Choir', $drawn['advertiser'] ?? null, 'a page fetched ahead is drawn with no ad, and it is the page that is shown');
            $this->assertSame(0, (int) DB::table('gates_ad_inventory')->sum('slots'), 'a prefetch filled the slot count');
            $this->assertNull(\AfricaGates\Services\Ads\AdDelivery::forRequest('nowhere', $req));

            DB::table('gates_ad_campaigns')->where('id', $r['id'])->update(['status' => 'cancelled']);
            $this->assertNull(\AfricaGates\Services\Ads\AdDelivery::forRequest('discover', $req), 'a prefetch spent the once-a-day "advertise here" notice');
            // A browser's own prefetch (no site-nav) is still nobody's page.
            $bare = $req->withoutHeader('X-Site-Nav');
            $this->assertNull(\AfricaGates\Services\Ads\AdDelivery::forRequest('discover', $bare));
        } finally {
            unset($_SERVER['HTTP_X_SITE_PREFETCH']);
        }
        $this->assertStringContainsString('Seen when shown', ChromeRender::code('templates/admin/ads/index.twig'));
    }

    public function test_an_under_delivered_trial_can_be_made_good_and_nothing_else_can(): void
    {
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, ['objective' => 'traffic', 'advertiser' => 'Alimosho Choir', 'category' => 'events', 'placements' => ['discover'],
            'headline' => 'Hear the choir live', 'body' => 'An evening of choral music in Alimosho.', 'cta' => 'get_tickets',
            'url' => 'https://choir.example.ng', 'standards' => '1'], '/uploads/ads/t.jpg');
        $this->assertFalse(\AfricaGates\Services\Ads\AdStaff::extendTrial($r['id'], 7), 'a trial still in review was extended');
        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        AdReview::decide($ad, 1, true);
        DB::table('gates_ad_sets')->where('campaign_id', $r['id'])->update(['starts_at' => date('Y-m-d H:i:s', time() - 8 * 86400), 'ends_at' => date('Y-m-d H:i:s', time() - 86400)]);
        $this->assertSame('completed', AdCampaigns::detail($r['id'], $uid)['delivery']);
        $this->assertTrue(\AfricaGates\Services\Ads\AdStaff::extendTrial($r['id'], 7));
        $end = strtotime((string) DB::table('gates_ad_sets')->where('campaign_id', $r['id'])->value('ends_at'));
        $this->assertEqualsWithDelta(time() + 7 * 86400, $end, 5, 'the days already gone were counted again');
        $this->assertSame('active', AdCampaigns::detail($r['id'], $uid)['delivery'], 'the make-good did not put it back on the site');
        $this->assertFalse(\AfricaGates\Services\Ads\AdStaff::extendTrial($r['id'], 60), 'more than a make-good allows');

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1000']);
        \AfricaGates\Services\Ads\AdMarket::forget();
        $paid = AdStaff::post(7, ['source' => 'direct', 'advertiser' => 'Lens', 'placements' => ['discover'], 'budget' => '50000', 'days' => '14',
            'headline' => 'Portraits in an hour', 'body' => 'Studio portraits in Yaba, ready to print.', 'cta' => 'book_now', 'url' => 'https://lens.example.ng'], '/uploads/ads/d.jpg');
        $this->assertTrue($paid['ok'], json_encode($paid));
        $this->assertFalse(\AfricaGates\Services\Ads\AdStaff::extendTrial($paid['id'], 7), 'a paid campaign’s days are what was bought');

        $aid = (int) DB::table('gates_admins')->insertGetId(['name' => 'Kemi', 'email' => 'kemi@example.com', 'role' => 'admin', 'is_active' => 1, 'password_hash' => 'x']);
        $html = ChromeRender::html('/admin/ads/' . $r['id'], ['admin_id' => $aid, 'admin_role' => 'admin', 'admin_name' => 'Kemi']);
        $this->assertStringContainsString('action="/admin/ads/' . $r['id'] . '/extend"', $html);
        $this->assertMatchesRegularExpression('~action="/admin/ads/' . $r['id'] . '/extend" data-confirm=~', $html, 'a make-good with no reason recorded');
    }
}
