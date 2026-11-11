<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdMarket;
use AfricaGates\Services\Ads\AdReview;
use AfricaGates\Services\Ads\AdRules;
use AfricaGates\Services\Ads\AdStaff;
use AfricaGates\Services\Ads\AdVideo;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\UploadedFile;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Video ads, and ads that are not square (owner, 11 Nov 2026: "video ads too"; "the vid
 * should play by default without sound"; "the ads should not allow just square posts
 * anymore").
 *
 * The MP4s here are built byte by byte — a `ftyp`, a `moov` holding an `mvhd`, an `mdat` —
 * so the suite needs no encoder, and so each header shape the reader must handle (version 0
 * and 1, a 64-bit box size, the movie header after the media) can be written on purpose.
 */
final class AdVideoShapeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1000']);
        AdMarket::forget();
        $this->root = sys_get_temp_dir() . '/agvid-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        AdVideo::$publicRoot = $this->root;
    }

    protected function tearDown(): void
    {
        AdVideo::$publicRoot = null;
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    private function mp4(float $secs, bool $v1 = false, bool $moovLast = false, bool $large = false, string $brand = 'isom'): string
    {
        $box = static fn (string $t, string $b): string => pack('N', 8 + strlen($b)) . $t . $b;
        $ftyp = $box('ftyp', $brand . pack('N', 512) . ($brand === 'isom' ? 'isomiso2avc1mp41' : $brand));
        $ts = 1000;
        $d = (int) round($secs * $ts);
        $mvhd = $v1
            ? $box('mvhd', "\x01\0\0\0" . pack('J', 0) . pack('J', 0) . pack('N', $ts) . pack('J', $d) . str_repeat("\0", 80))
            : $box('mvhd', "\0\0\0\0" . pack('N', 0) . pack('N', 0) . pack('N', $ts) . pack('N', $d) . str_repeat("\0", 80));
        $moov = $box('moov', $mvhd);
        $payload = str_repeat("\0", 64);
        // A 64-bit size: the 32-bit field says 1 and the real length follows the type.
        $mdat = $large ? pack('N', 1) . 'mdat' . pack('J', 16 + strlen($payload)) . $payload : $box('mdat', $payload);
        return $ftyp . ($moovLast ? $mdat . $moov : $moov . $mdat);
    }

    private function upload(string $bytes, ?int $size = null): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'agup');
        file_put_contents($f, $bytes);
        return new UploadedFile($f, 'clip.mp4', 'video/mp4', $size ?? strlen($bytes), UPLOAD_ERR_OK);
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

    // ── reading and storing a video ──────────────────────────────────────────

    public function test_the_length_is_read_from_the_files_own_header_in_every_shape_it_comes_in(): void
    {
        foreach ([[false, false, false], [true, false, false], [false, true, false], [false, true, true]] as [$v1, $last, $large]) {
            $f = tempnam(sys_get_temp_dir(), 'agd');
            file_put_contents($f, $this->mp4(7.5, $v1, $last, $large));
            $this->assertEqualsWithDelta(7.5, AdVideo::duration($f), 0.001, sprintf('v1=%d moovLast=%d large=%d', $v1, $last, $large));
            unlink($f);
        }
        $f = tempnam(sys_get_temp_dir(), 'agd');
        file_put_contents($f, 'not a video at all, just words');
        $this->assertNull(AdVideo::duration($f));
        unlink($f);
    }

    public function test_only_a_short_small_mp4_is_kept_under_a_name_we_chose(): void
    {
        $ok = AdVideo::store($this->upload($this->mp4(12)));
        $this->assertTrue($ok['ok'], json_encode($ok));
        $this->assertSame(12, $ok['seconds']);
        $this->assertSame($ok['path'], AdVideo::url($ok['path']), 'a stored path the reader then refuses');
        $this->assertFileExists($this->root . $ok['path']);
        $this->assertStringNotContainsString('clip', $ok['path'], 'the browser’s filename reached the disk');

        $long = AdVideo::store($this->upload($this->mp4(AdVideo::MAX_SECONDS + 15)));
        $this->assertFalse($long['ok']);
        $this->assertStringContainsString((string) AdVideo::MAX_SECONDS, $long['message']);
        $this->assertFalse(AdVideo::store($this->upload($this->mp4(1)))['ok'], 'a one-second flash');
        $this->assertFalse(AdVideo::store($this->upload("<?php echo 'hi';"))['ok'], 'a script called clip.mp4 was kept');
        // A QuickTime movie with a perfectly good header: only the TYPE check refuses it, and a
        // .mov from an iPhone will not play in most browsers that are not Safari.
        $mov = AdVideo::store($this->upload($this->mp4(7, false, false, false, 'qt  ')));
        $this->assertFalse($mov['ok'], 'a QuickTime file was kept as an MP4');
        $this->assertStringContainsString('MP4', $mov['message']);
        $big = AdVideo::store($this->upload($this->mp4(10), AdVideo::limitBytes() + 1));
        $this->assertFalse($big['ok']);
        $this->assertStringContainsString('MB', $big['message']);

        foreach (['/uploads/ads/video/2026/11/../../../../index.php', 'https://evil.example/x.mp4', '/uploads/ads/video/2026/11/x.mp4', ''] as $bad) {
            $this->assertNull(AdVideo::url($bad), $bad);
        }
    }

    public function test_a_video_path_only_ever_comes_from_the_server_that_stored_it(): void
    {
        $b = ['_video' => '/uploads/ads/video/2026/11/' . str_repeat('a', 32) . '.mp4', '_video_seconds' => 5, 'remove_video' => '1'];
        $this->assertNull(AdVideo::take([], $b));
        $this->assertArrayNotHasKey('_video', $b, 'a path a form posted would be stored as the ad’s video');

        $edit = ['remove_video' => '1'];
        AdVideo::take([], $edit, true);
        $this->assertArrayHasKey('_video', $edit);
        $this->assertNull($edit['_video'], 'taking the video off an ad did nothing');

        $up = [];
        $this->assertNull(AdVideo::take(['video' => $this->upload($this->mp4(9))], $up));
        $this->assertSame(9, $up['_video_seconds']);
        $this->assertNotNull(AdVideo::url($up['_video']));

        $c = AdRules::creative($this->creative(['_video' => '/etc/passwd', '_video_seconds' => 9]));
        $this->assertNull($c['row']['video_path'], 'creative() carried a path it did not recognise');
    }

    // ── shapes ───────────────────────────────────────────────────────────────

    public function test_an_ad_takes_any_of_three_shapes_and_the_frame_follows_it_everywhere(): void
    {
        $this->assertSame(['square', 'portrait', 'landscape'], array_keys(AdRules::SHAPES));
        $this->assertSame('portrait', AdRules::creative($this->creative(['shape' => 'portrait']))['row']['shape']);
        $this->assertSame('square', AdRules::creative($this->creative(['shape' => 'banner']))['row']['shape'], 'an unknown shape was stored');
        $this->assertEqualsWithDelta(0.8, \AfricaGates\Support\ImageProfiles::get(AdRules::imageProfile('portrait'))['aspect'], 0.001);
        $this->assertEqualsWithDelta(16 / 9, \AfricaGates\Support\ImageProfiles::get(AdRules::imageProfile('landscape'))['aspect'], 0.001);

        $uid = $this->user();
        $html = ChromeRender::html('/account/ads/new', ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        foreach (array_keys(AdRules::SHAPES) as $k) $this->assertStringContainsString('name="shape" value="' . $k . '"', $html);
        $this->assertStringContainsString('data-shape-from="shape"', $html, 'the image editor does not follow the shape');
        $this->assertStringContainsString("input.addEventListener('ag:reshape'", ChromeRender::code('public/assets/js/image-tool.js'));
        $css = ChromeRender::code('public/assets/css/components/ad-slot.css');
        $this->assertStringContainsString('.ag-ad__media--portrait{ aspect-ratio:4/5 }', $css);
        $this->assertStringContainsString('.ag-ad__media--landscape{ aspect-ratio:16/9 }', $css);
    }

    public function test_a_new_shape_needs_a_new_image(): void
    {
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, $this->creative(), '/uploads/ads/t.jpg');
        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        $no = AdCampaigns::resubmitAd($ad, $uid, $this->creative(['shape' => 'landscape']), null);
        $this->assertArrayHasKey('image', $no['errors'] ?? [], 'a square image would be cropped into a wide box');
        $this->assertTrue(AdCampaigns::resubmitAd($ad, $uid, $this->creative(['shape' => 'landscape']), '/uploads/ads/w.jpg')['ok']);
        $this->assertSame('landscape', DB::table('gates_ads')->where('id', $ad)->value('shape'));
    }

    // ── on the page ──────────────────────────────────────────────────────────

    public function test_a_video_ad_plays_silently_in_its_shape_and_counts_plays_and_watch_throughs_once(): void
    {
        $vid = AdVideo::store($this->upload($this->mp4(8)));
        $r = AdCampaigns::createTrial($this->user(), $this->creative(['shape' => 'portrait', '_video' => $vid['path'], '_video_seconds' => $vid['seconds']]), '/uploads/ads/t.jpg');
        $this->assertTrue($r['ok'], json_encode($r));
        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        $this->assertSame($vid['path'], DB::table('gates_ads')->where('id', $ad)->value('video_path'));
        AdReview::decide($ad, 1, true);

        $drawn = AdDelivery::pick('discover', ['reader' => true]);
        $this->assertSame($vid['path'], $drawn['video']);
        $this->assertSame('portrait', $drawn['shape']);
        $html = (string) ChromeRender::page('/discover', [], [], 'GET', null, ['User-Agent' => 'Mozilla/5.0 Chrome/120', 'Accept' => 'text/html',
            'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Accept-Language' => 'en-GB'])->getBody();
        $this->assertMatchesRegularExpression('~<div class="ag-ad__media ag-ad__media--portrait ag-ad__media--video" data-ad-video data-watch="15">~', $html);
        $this->assertMatchesRegularExpression('~<video class="ag-ad__vid" muted playsinline loop preload="none"[^>]* poster="[^"]+"><source src="' . preg_quote($vid['path'], '~') . '" type="video/mp4"></video>~', $html,
            'not silent, not lazy, or with no poster for the readers it does not play for');
        $this->assertStringContainsString('data-ad-play hidden', $html);
        $this->assertStringContainsString('data-ad-sound hidden', $html);

        $t = (string) $drawn['token'];
        $this->assertTrue(AdDelivery::watched($t, 'play'));
        $this->assertFalse(AdDelivery::watched($t, 'play'), 'a looping clip counted every loop');
        $this->assertTrue(AdDelivery::watched($t, 'done'));
        $this->assertFalse(AdDelivery::watched($t, 'stop'));
        $row = DB::table('gates_ad_stats')->where('ad_id', $ad)->first();
        $this->assertSame([1, 1], [(int) $row->plays, (int) $row->completes]);
        $sum = AdCampaigns::summary(DB::table('gates_ad_campaigns')->find($r['id']));
        $this->assertSame([1, 1], [$sum['plays'], $sum['completes']]);

        $res = ChromeRender::page('/ads/video', [], [], 'POST', ['_token' => 'test-token', 't' => $t, 'k' => 'play']);
        $this->assertSame(202, $res->getStatusCode(), 'counted twice through the endpoint');
    }

    public function test_a_still_ad_has_no_plays_to_count(): void
    {
        $r = AdCampaigns::createTrial($this->user(), $this->creative(), '/uploads/ads/t.jpg');
        $ad = (int) DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $r['id'])->value('a.id');
        AdReview::decide($ad, 1, true);
        $drawn = AdDelivery::pick('discover', ['reader' => true]);
        $this->assertNull($drawn['video']);
        $this->assertFalse(AdDelivery::watched((string) $drawn['token'], 'play'), 'a still ad reported a video play');
    }

    public function test_the_player_plays_by_default_and_the_reader_can_always_stop_it(): void
    {
        $js = ChromeRender::code('public/assets/js/ads.js');
        $this->assertMatchesRegularExpression('~var auto = !lowData && !calm~', $js, 'autoplay is not the default');
        $this->assertStringContainsString('var wanted = auto', $js);
        // The site's own reduce-motion switch counts as much as the device's (ReduceMotionSwitchTest
        // reads the file for the token, which a comment satisfies; this reads the code).
        $this->assertMatchesRegularExpression("~var calm = document\.documentElement\.classList\.contains\('ag-rm'\)~", $js, 'the site’s reduce-motion switch is ignored');
        $this->assertStringContainsString("playBtn.hidden = false;", $js, 'no way to pause a clip that moves by itself (WCAG 2.2.2)');
        $this->assertMatchesRegularExpression('~else \{ wanted = false; v\.pause\(\); \}~', $js, 'a pause is overridden the next time it scrolls into view');
        $this->assertMatchesRegularExpression('~if \(seen && wanted && v\.paused\) go\(\);~', $js);
        $this->assertStringContainsString("post('/ads/video'", $js);
        $this->assertStringNotContainsString('v.muted = false', $js, 'sound turned on without being asked for');
    }

    public function test_the_reviewer_watches_it_and_staff_can_post_one(): void
    {
        $aid = (int) DB::table('gates_admins')->insertGetId(['name' => 'Kemi', 'email' => 'kemi@example.com', 'role' => 'superadmin', 'is_active' => 1, 'password_hash' => 'x']);
        $as = ['admin_id' => $aid, 'admin_role' => 'superadmin', 'admin_name' => 'Kemi'];
        $vid = AdVideo::store($this->upload($this->mp4(6)));
        AdCampaigns::createTrial($this->user(), $this->creative(['_video' => $vid['path'], '_video_seconds' => 6]), '/uploads/ads/t.jpg');
        $mod = ChromeRender::html('/admin/moderation', $as);
        $this->assertStringContainsString('<source src="' . $vid['path'] . '"', $mod, 'a reviewer approves a video they cannot watch');
        $this->assertMatchesRegularExpression('~<video controls~', $mod);

        $vid2 = AdVideo::store($this->upload($this->mp4(10)));
        $p = AdStaff::post($aid, $this->creative(['source' => 'house', 'shape' => 'landscape', 'days' => '7', '_video' => $vid2['path'], '_video_seconds' => 10]), '/uploads/ads/h.jpg');
        $this->assertTrue($p['ok'], json_encode($p));
        $row = DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')->where('s.campaign_id', $p['id'])->select('a.*')->first();
        $this->assertSame(['landscape', $vid2['path']], [$row->shape, $row->video_path]);
        $this->assertStringContainsString('name="video"', ChromeRender::html('/admin/ads', $as));
    }
}
