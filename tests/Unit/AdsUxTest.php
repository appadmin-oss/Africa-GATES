<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdReview;
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
}
