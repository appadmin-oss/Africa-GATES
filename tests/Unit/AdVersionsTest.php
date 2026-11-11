<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdReview;
use AfricaGates\Services\Ads\AdRules;
use AfricaGates\Support\Media;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Ad versions and the image an ad is drawn with (owner, 2 Nov 2026: "improve the
 * advertising feature").
 *
 *   · a set could hold three ads and the builder made one, so the rotation had nothing to
 *     rotate and an advertiser never learnt which picture worked: now up to three versions,
 *     each reviewed, and once one is measurably ahead it is shown most — by ONE function
 *     the delivery and the page's "Leading" mark both ask;
 *   · two lucky clicks are not a measurement: no leader before every version has its views
 *     and the leader a margin and a floor of clicks;
 *   · the card drew the ad at Cloudinary's 320px 'thumb' — soft on every phone — and the
 *     reviewer judged a 96px thumbnail with nothing to open.
 */
final class AdVersionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    private function user(): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi Adebayo', 'email' => 'v-' . bin2hex(random_bytes(3)) . '@example.test',
            'phone' => '08031234567', 'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string,mixed> */
    private function ad(array $over = []): array
    {
        return $over + [
            'objective' => 'traffic', 'advertiser' => 'Mama Put Kitchen', 'name' => '', 'category' => 'general',
            'placements' => ['discover'], 'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos, same day.',
            'cta' => 'shop_now', 'url' => 'https://mamaput.example.ng/trays', 'standards' => '1',
        ];
    }

    /** @return array{0:int,1:int,2:int} campaign, first ad, set */
    private function campaign(int $uid): array
    {
        $r = AdCampaigns::createTrial($uid, $this->ad(), '/uploads/ads/a.webp');
        $this->assertTrue($r['ok'], json_encode($r));
        $set = (int) DB::table('gates_ad_sets')->where('campaign_id', $r['id'])->value('id');
        $ad = (int) DB::table('gates_ads')->where('ad_set_id', $set)->value('id');
        $this->assertTrue(AdReview::decide($ad, 1, true)['ok']);
        return [(int) $r['id'], $ad, $set];
    }

    private function stats(int $adId, int $views, int $clicks): void
    {
        DB::table('gates_ad_stats')->insert(['ad_id' => $adId, 'placement' => 'discover', 'day' => date('Y-m-d'), 'views' => $views, 'clicks' => $clicks]);
    }

    public function test_a_leader_is_a_measurement_and_not_luck(): void
    {
        $v = AdCampaigns::TEST_VIEWS;
        $this->assertNull(AdCampaigns::leader([1 => ['views' => 999, 'clicks' => 99]]), 'one version leads nothing');
        $this->assertNull(AdCampaigns::leader([1 => ['views' => $v, 'clicks' => 20], 2 => ['views' => $v - 1, 'clicks' => 0]]), 'before every version has its views');
        $this->assertNull(AdCampaigns::leader([1 => ['views' => $v, 'clicks' => 11], 2 => ['views' => $v, 'clicks' => 10]]), 'inside the margin');
        $this->assertNull(AdCampaigns::leader([1 => ['views' => $v, 'clicks' => 4], 2 => ['views' => $v, 'clicks' => 1]]), 'four clicks are not a result');
        $this->assertNull(AdCampaigns::leader([1 => ['views' => $v, 'clicks' => 6], 2 => ['views' => $v, 'clicks' => 6]]), 'a tie');
        $this->assertSame(2, AdCampaigns::leader([1 => ['views' => $v, 'clicks' => 6], 2 => ['views' => $v, 'clicks' => 15], 3 => ['views' => $v * 2, 'clicks' => 10]]));
    }

    public function test_up_to_three_versions_each_reviewed_only_the_owners_and_each_with_a_picture(): void
    {
        $uid = $this->user();
        [$cid] = $this->campaign($uid);
        $this->assertFalse(AdCampaigns::addAd($cid, $this->user(), $this->ad(['headline' => 'Another']), '/uploads/ads/b.webp')['ok'], 'somebody else added a version');
        $none = AdCampaigns::addAd($cid, $uid, $this->ad(['headline' => 'No picture']), null);
        $this->assertFalse($none['ok']);
        $this->assertArrayHasKey('image', $none['errors']);

        $two = AdCampaigns::addAd($cid, $uid, $this->ad(['headline' => 'Suya on Fridays']), '/uploads/ads/b.webp');
        $this->assertTrue($two['ok']);
        $this->assertSame('pending', DB::table('gates_ads')->where('id', $two['id'])->value('review'), 'a version ran without a person checking it');
        $this->assertTrue(AdCampaigns::addAd($cid, $uid, $this->ad(['headline' => 'Small chops too']), '/uploads/ads/c.webp')['ok']);
        $four = AdCampaigns::addAd($cid, $uid, $this->ad(['headline' => 'One too many']), '/uploads/ads/d.webp');
        $this->assertFalse($four['ok'], 'more versions than a set may hold');
        $this->assertSame(AdRules::ADS_PER_SET, (int) DB::table('gates_ads')->whereIn('ad_set_id', DB::table('gates_ad_sets')->where('campaign_id', $cid)->pluck('id'))->count());
        $this->assertFalse(AdCampaigns::detail($cid, $uid)['can_add']);
    }

    public function test_the_leader_is_shown_most_and_the_page_names_the_same_one(): void
    {
        $uid = $this->user();
        [$cid, $first, $set] = $this->campaign($uid);
        $second = (int) AdCampaigns::addAd($cid, $uid, $this->ad(['headline' => 'Suya on Fridays']), '/uploads/ads/b.webp')['id'];
        AdReview::decide($second, 1, true);
        $this->stats($first, 300, 6);
        $this->stats($second, 300, 15);

        $d = AdCampaigns::detail($cid, $uid);
        $this->assertSame($second, $d['leader'], 'the page and the rotation disagree about who leads');

        $rotate = new \ReflectionMethod(AdDelivery::class, 'rotate');
        $hits = 0;
        for ($i = 0; $i < 400; $i++) if ((int) $rotate->invoke(null, $set, date('Y-m-d'))->id === $second) $hits++;
        $share = $hits / 400;
        $this->assertGreaterThan(0.6, $share, 'the leader is not favoured');
        $this->assertLessThan(0.8, $share, 'the other version stopped running, so the result is never checked again');

        // Before the views are in, they share: the one with fewer views today goes next.
        DB::table('gates_ad_stats')->delete();
        $this->stats($first, 50, 9);
        $this->assertSame($second, (int) $rotate->invoke(null, $set, date('Y-m-d'))->id);
    }

    public function test_the_ad_is_drawn_at_its_size_and_the_reviewer_can_open_it(): void
    {
        $remote = 'https://res.cloudinary.com/demo/image/upload/v1/ads/a.jpg';
        $this->assertStringContainsString('w_1080,h_1080', (string) Media::url($remote, 'ad'));
        $src = ChromeRender::code('src/Services/Ads/AdDelivery.php');
        $this->assertStringContainsString("Media::url(\$ad->image_path ? (string) \$ad->image_path : null, 'ad')", $src, 'the card is drawn from the 320px thumb again');
        $this->assertStringContainsString("'image_srcset' => \\AfricaGates\\Support\\ImageProfiles::srcset(", $src);
        $slot = ChromeRender::source('templates/partials/ad-slot.twig');
        $this->assertStringContainsString('(min-width: 600px) 520px, calc(100vw - 32px)"', $slot);

        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, $this->ad(), '/uploads/ads/full.webp');
        $q = array_values(array_filter(AdReview::queue(), fn ($a) => $a['image_full'] === '/uploads/ads/full.webp'));
        $this->assertCount(1, $q, 'the queue does not carry the full image');
        $mod = ChromeRender::source('templates/admin/moderation/index.twig');
        $this->assertSame(2, substr_count($mod, '<a href="{{ a.image_full|default(a.image) }}" target="_blank" rel="noopener"'), 'the reviewer cannot open the image they are judging');
        $this->assertStringContainsString("\$a->post('/ads/{id:[0-9]+}/ads'", ChromeRender::code('src/routes.php'));
    }
}
