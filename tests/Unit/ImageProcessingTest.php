<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Support\ImageProfiles;
use Slim\Psr7\UploadedFile;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The image tools (owner, 2 Nov 2026: "add proper image processing tools"): a profile names
 * what an image is FOR, the editor in the browser lets the person choose the area, and the
 * server makes that area into the profile's shape. Every case here is a way an image was or
 * could be wrong while the page still drew something:
 *   · cut from the centre by CSS, so the product at the edge of a landscape photo vanished;
 *   · a phone photo turned on its side, because the editor measured the photo as the browser
 *     draws it (oriented) and the server cut the raw pixels;
 *   · the GPS position in a photo's EXIF published with it;
 *   · a WebP stored as JPEG bytes under a .webp name;
 *   · a "use a larger image" refusal about a 4000px photo zoomed into a corner.
 */
final class ImageProcessingTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/ag-img-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    /** A W×H JPEG, grey, with one blue block at the top right of its RAW pixels. */
    private function jpeg(int $w, int $h, ?string $app1 = null): string
    {
        $g = imagecreatetruecolor($w, $h);
        imagefill($g, 0, 0, imagecolorallocate($g, 200, 200, 200));
        imagefilledrectangle($g, (int) ($w * 0.8), 0, $w - 1, (int) ($h * 0.2), imagecolorallocate($g, 0, 0, 255));
        ob_start(); imagejpeg($g, null, 92); $bytes = (string) ob_get_clean();
        if ($app1 !== null) $bytes = substr($bytes, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($bytes, 2);
        $path = $this->root . '/src-' . bin2hex(random_bytes(3)) . '.jpg';
        file_put_contents($path, $bytes);
        return $path;
    }

    /** An EXIF block carrying an orientation, and a GPS string a test can look for. */
    private static function exif(int $orientation): string
    {
        $ifd = pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('nn', $orientation, 0) . pack('N', 0);
        return "Exif\0\0" . "MM\0\x2A" . pack('N', 8) . $ifd . 'GPSLatitude 6.5244N GPSLongitude 3.3792E';
    }

    /** @return array<string,mixed> */
    private function process(string $path, string $profile = 'ad', ?string $crop = null): array
    {
        $f = new UploadedFile($path, 'photo.jpg', 'image/jpeg', filesize($path), UPLOAD_ERR_OK);
        return (new UploadService($this->root))->uploadProcessed($f, 'ads', $profile, $crop);
    }

    private function colourAt(string $local, float $fx, float $fy): int
    {
        $im = imagecreatefromwebp($this->root . $local);
        return imagecolorat($im, (int) (imagesx($im) * $fx), (int) (imagesy($im) * $fy));
    }

    public function test_the_shape_is_the_profiles_and_the_chosen_area_is_the_one_kept(): void
    {
        // No script: the centre square, and the blue corner is outside it.
        $r = $this->process($this->jpeg(1600, 900));
        $this->assertSame([900, 900], [$r['width'], $r['height']]);
        $this->assertNotSame(0x0000FF, $this->colourAt($r['local'], 0.95, 0.05) & 0xFFFFFF);

        // The person chose the right-hand square: the blue corner is in it.
        $r = $this->process($this->jpeg(1600, 900), 'ad', '0.4375,0,0.5625,1');
        $this->assertSame($r['width'], $r['height']);
        $this->assertSame(0x0000FF, $this->colourAt($r['local'], 0.95, 0.05) & 0xFFFFFF, 'the area chosen is not the area kept');

        // Larger than the profile: scaled to it, never above it; a narrower copy for srcset.
        $r = $this->process($this->jpeg(3000, 2000));
        $this->assertSame([1080, 1080], [$r['width'], $r['height']]);
        $this->assertCount(1, $r['variants']);
        $this->assertStringEndsWith('-540w.webp', $r['variants'][0]);
        $this->assertSame('RIFF', substr((string) file_get_contents($this->root . $r['local']), 0, 4), 'not WebP');
    }

    public function test_the_photos_own_orientation_is_applied_before_the_cut(): void
    {
        // Orientation 6: drawn turned a quarter clockwise. The raw top-right lands at the
        // bottom-right of the photo as everybody sees it, and the editor's fractions are
        // measured on THAT — so the bottom-right square must hold the blue block.
        // The photo is 900 × 1600 raw, 1600 × 900 as drawn; the right-hand square, as drawn.
        $r = $this->process($this->jpeg(900, 1600, self::exif(6)), 'ad', '0.4375,0,0.5625,1');
        $this->assertSame(0x0000FF, $this->colourAt($r['local'], 0.95, 0.95) & 0xFFFFFF,
            'the cut was made on the raw pixels, not the photo as the browser drew it');
    }

    public function test_nothing_but_the_pixels_survives(): void
    {
        $src = $this->jpeg(1200, 1200, self::exif(1));
        $this->assertStringContainsString('GPSLatitude', (string) file_get_contents($src), 'the fixture carries no location');
        $r = $this->process($src);
        $out = (string) file_get_contents($this->root . $r['local']);
        $this->assertStringNotContainsString('GPSLatitude', $out, 'where the photo was taken was published with it');
        $this->assertStringNotContainsString('Exif', $out);
    }

    public function test_a_cut_too_small_is_refused_with_its_numbers(): void
    {
        try {
            $this->process($this->jpeg(4000, 3000), 'ad', '0.9,0.9,0.1,0.1');
            $this->fail('a 400px cut of a 4000px photo was accepted for a 600px profile');
        } catch (\RuntimeException $e) {
            // 10% of each side is 400 × 300, made square: 300 × 300, and it says so.
            $this->assertStringStartsWith('The square you chose is 300 × 300 px; it needs at least 600 px on each side.', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $this->process($this->jpeg(500, 500));
    }

    public function test_a_malformed_crop_field_is_ignored_never_fatal(): void
    {
        foreach (['', 'client', '1,2,3', '0,0,2,1', '-0.1,0,0.5,0.5', '0,0,0.5,0.5,45', '0.6,0,0.6,1'] as $bad) {
            $this->assertNull(ImageProfiles::parseCrop($bad), $bad);
        }
        $this->assertSame(['x' => 0.25, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0, 'r' => 90], ImageProfiles::parseCrop('0.25,0,0.5,1,90'));
        $r = $this->process($this->jpeg(1600, 900), 'ad', 'nonsense');
        $this->assertSame([900, 900], [$r['width'], $r['height']], 'a bad field must fall back to the centre');
    }

    public function test_srcset_names_only_the_sizes_that_exist(): void
    {
        $r = $this->process($this->jpeg(2000, 2000));
        $set = ImageProfiles::srcset($r['local'], $this->root);
        $this->assertSame($r['variants'][0] . ' 540w, ' . $r['local'] . ' 1080w', $set);
        $this->assertNull(ImageProfiles::srcset('/uploads/ads/old.jpg', $this->root), 'an image from before has no variants');
        $this->assertNull(ImageProfiles::srcset('https://res.cloudinary.com/x/image/upload/a.webp', $this->root), 'a remote image is sized by its preset');
        @unlink($this->root . $r['variants'][0]);
        $this->assertNull(ImageProfiles::srcset($r['local'], $this->root), 'a srcset naming a missing file is a broken image');
    }

    public function test_a_webp_upload_is_stored_as_webp(): void
    {
        $g = imagecreatetruecolor(400, 400);
        $path = $this->root . '/in.webp';
        imagewebp($g, $path, 80);
        $f = new UploadedFile($path, 'in.webp', 'image/webp', filesize($path), UPLOAD_ERR_OK);
        $r = (new UploadService($this->root))->uploadImage($f, 'misc');
        $this->assertStringEndsWith('.webp', $r['local']);
        $this->assertSame('RIFF', substr((string) file_get_contents($this->root . $r['local']), 0, 4), 'JPEG bytes under a .webp name');
    }

    /** The editor in the browser: its controls, its output, and the profile it reads. */
    public function test_the_editor_offers_every_drag_as_a_control_and_sends_the_cut(): void
    {
        $js = ChromeRender::code('public/assets/js/image-tool.js');
        foreach (["'Zoom out'", "'Zoom in'", "'Turn a quarter'", "'Start again'", "type: 'range'", "e.key === 'ArrowLeft'", "e.key === '+'"] as $needle) {
            $this->assertStringContainsString($needle, $js, "the editor lost $needle (WCAG 2.5.7: every drag has a control)");
        }
        $this->assertMatchesRegularExpression('~dt\.items\.add\(new File\(\[blob\]~', $js, 'the photo is no longer made small before it is sent');
        $this->assertMatchesRegularExpression("~crop\.value = f;~", $js, 'no fallback for a browser that cannot replace the file');
        $this->assertStringContainsString('input.setCustomValidity(small ?', $js, 'a too-small cut posts anyway and is refused a page later');
        $this->assertStringContainsString("Math.min(out, pw)", $js, 'the cut is upscaled to the output size');

        $this->assertSame('data-ag-image="ad" data-aspect="1" data-min="600" data-out="1080"', ImageProfiles::attrs('ad'));
        $this->assertSame(\AfricaGates\Services\Ads\AdRules::IMAGE_MIN, ImageProfiles::get('ad')['min'], 'two minimums for one image');
        $t = ChromeRender::source('templates/pages/account/ads-new.twig');
        $this->assertStringContainsString("{{ imgtool.attrs('ad') }}", $t);
        $this->assertStringContainsString("{{ imgtool.attrs('ad_logo', true) }}", $t);
    }
}
