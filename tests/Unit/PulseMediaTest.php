<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\PulseMediaService;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * What may be attached to a post.
 *
 * The load-bearing assertion here is the hostile one: a file's TYPE is decided by
 * its bytes, never by its name or the Content-Type the browser sent, because both
 * of those are chosen by whoever is uploading. A video cannot be re-encoded on the
 * target host (no ffmpeg), so unlike an image it is stored as given — which makes
 * the sniff the only thing standing between `payload.php` renamed to `clip.mp4`
 * and a file sitting in the web root.
 */
final class PulseMediaTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ag_media_' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** A structurally real MP4 — finfo reads the container from the ftyp box. */
    private function mp4(): string
    {
        $box  = static fn(string $t, string $p = '') => pack('N', 8 + strlen($p)) . $t . $p;
        $path = $this->dir . '/clip.mp4';
        file_put_contents($path,
            $box('ftyp', 'isom' . pack('N', 0x200) . 'isomiso2avc1mp41')
            . $box('free', str_repeat("\0", 8))
            . $box('mdat', str_repeat("\0", 1024)));
        return $path;
    }

    private function upload(string $path, string $clientName, string $clientType): UploadedFile
    {
        // A copy, because the service MOVES the file it is given.
        $copy = $this->dir . '/up_' . bin2hex(random_bytes(4)) . '_' . basename($path);
        copy($path, $copy);
        return new UploadedFile($copy, $clientName, $clientType, filesize($copy), UPLOAD_ERR_OK);
    }

    public function test_a_real_video_is_accepted(): void
    {
        $r = (new PulseMediaService())->store($this->upload($this->mp4(), 'clip.mp4', 'video/mp4'));

        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame('video', $r['type']);
        $this->assertStringEndsWith('.mp4', $r['path']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/public' . $r['path']);
        @unlink(dirname(__DIR__, 2) . '/public' . $r['path']);
    }

    /**
     * THE test. A PHP script named clip.mp4, announced as video/mp4.
     *
     * Every client-supplied signal says "video". Only the bytes say otherwise, and
     * the bytes are what decide.
     */
    public function test_a_script_wearing_a_video_extension_is_refused(): void
    {
        $evil = $this->dir . '/evil.mp4';
        file_put_contents($evil, "<?php echo shell_exec(\$_GET['c']); ?>\n");

        $r = (new PulseMediaService())->store($this->upload($evil, 'clip.mp4', 'video/mp4'));

        $this->assertFalse($r['ok'], 'a PHP script must never be stored as a video');
        $this->assertArrayNotHasKey('path', $r);
        $this->assertStringContainsString('not a video', $r['message']);
    }

    /** The stored extension comes from the DETECTED type, not the client's name. */
    public function test_the_stored_name_ignores_the_clients_filename(): void
    {
        $r = (new PulseMediaService())->store(
            $this->upload($this->mp4(), '../../evil.php', 'video/mp4'));

        $this->assertTrue($r['ok']);
        $this->assertStringNotContainsString('evil', $r['path']);
        $this->assertStringNotContainsString('..', $r['path']);
        $this->assertMatchesRegularExpression('#^/uploads/pulse/\d{4}/\d{2}/[0-9a-f]{32}\.mp4$#', $r['path']);
        @unlink(dirname(__DIR__, 2) . '/public' . $r['path']);
    }

    public function test_an_unknown_type_is_refused_outright(): void
    {
        $doc = $this->dir . '/notes.pdf';
        file_put_contents($doc, "%PDF-1.4\n%%EOF\n");

        $r = (new PulseMediaService())->store($this->upload($doc, 'notes.pdf', 'application/pdf'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('photo', $r['message']);
    }

    /** An image with no UploadService wired must fail cleanly, not fatally. */
    public function test_an_image_without_the_upload_service_degrades_to_a_message(): void
    {
        $png = $this->dir . '/x.png';
        imagepng(imagecreatetruecolor(10, 10), $png);

        $r = (new PulseMediaService(null))->store($this->upload($png, 'x.png', 'image/png'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('unavailable', $r['message']);
    }

    public function test_a_failed_upload_reports_the_servers_own_limit(): void
    {
        $f = new UploadedFile($this->dir . '/none', 'big.mp4', 'video/mp4', 0, UPLOAD_ERR_INI_SIZE);
        $r = (new PulseMediaService())->store($f);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString(PulseMediaService::humanLimit(), $r['message'],
            'the reader is told the limit that actually applies, not our nominal one');
    }

    /**
     * The advertised limit is the SMALLER of ours and PHP's. A host with
     * upload_max_filesize = 8M silently discards a 9MB file, so promising 25MB
     * there produces a bug that cannot be reproduced anywhere else.
     */
    public function test_the_limit_never_exceeds_what_php_will_accept(): void
    {
        $limit = PulseMediaService::limitBytes();
        $this->assertLessThanOrEqual(PulseMediaService::MAX_VIDEO_BYTES, $limit);
        $this->assertGreaterThan(0, $limit);
        $this->assertMatchesRegularExpression('/^[\d.]+MB$/', PulseMediaService::humanLimit());
    }
}
