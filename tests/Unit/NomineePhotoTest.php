<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Admin\Controllers\NomineesController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\UploadService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Admin nominee photo add/replace: hardened upload → photo_path repointed,
 * audited, with graceful failures when no file / no upload service.
 */
class NomineePhotoTest extends TestCase
{
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'admin';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        DB::table('gates_nominees')->insert(['id' => 5, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $abs) { if (is_file($abs)) @unlink($abs); }
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function controller(?UploadService $up): NomineesController
    {
        // photo() never touches the view; a bare Twig satisfies the constructor.
        return new NomineesController(
            \Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'),
            new AuditService(),
            $up
        );
    }

    private function req(array $files): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominees/5/photo')
            ->withUploadedFiles($files);
    }

    private function pngUpload(int $side = 300): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agtest_') . '.png';
        $im = imagecreatetruecolor($side, $side);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 30));
        imagepng($im, $tmp);
        imagedestroy($im);
        // sapi=false → moveTo() uses rename(), which works outside a real upload.
        return new UploadedFile($tmp, 'me.png', 'image/png', filesize($tmp) ?: null, UPLOAD_ERR_OK, false);
    }

    public function test_missing_file_is_rejected(): void
    {
        $res = $this->controller(new UploadService())->photo($this->req([]), new Response(), ['id' => 5]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        $this->assertNull(DB::table('gates_nominees')->where('id', 5)->value('photo_path'));
    }

    public function test_no_upload_service_fails_gracefully(): void
    {
        $files = ['photo' => $this->pngUpload()];
        $res = $this->controller(null)->photo($this->req($files), new Response(), ['id' => 5]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        $this->assertNull(DB::table('gates_nominees')->where('id', 5)->value('photo_path'));
    }

    public function test_valid_upload_sets_photo_path_and_audits(): void
    {
        $files = ['photo' => $this->pngUpload()];
        $res = $this->controller(new UploadService())->photo($this->req($files), new Response(), ['id' => 5]);

        $this->assertSame(302, $res->getStatusCode());
        $path = (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path');
        $this->assertStringStartsWith('/uploads/nominees/', $path);
        $abs = dirname(__DIR__, 2) . '/public' . $path;
        $this->written[] = $abs;
        $this->assertFileExists($abs);
        $this->assertNotEmpty($_SESSION['flash_ok'] ?? '');
        $this->assertSame(1, (int) DB::table('gates_audit_log')->where('action', 'nominee.photo')->count());
    }

    public function test_too_small_image_is_rejected(): void
    {
        $files = ['photo' => $this->pngUpload(80)]; // below the 200px min side
        $res = $this->controller(new UploadService())->photo($this->req($files), new Response(), ['id' => 5]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        $this->assertNull(DB::table('gates_nominees')->where('id', 5)->value('photo_path'));
    }
}
