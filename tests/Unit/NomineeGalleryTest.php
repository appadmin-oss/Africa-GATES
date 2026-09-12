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
 * Multiple nominee photos: uploads accumulate in the gallery (gates_uploads),
 * the first becomes primary, another can be promoted, and deleting the primary
 * repoints it to a remaining photo.
 */
class NomineeGalleryTest extends TestCase
{
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'admin';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        DB::table('gates_nominees')->insert(['id' => 5, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $abs) { if (is_file($abs)) @unlink($abs); }
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function ctrl(): NomineesController
    {
        return new NomineesController(\Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'), new AuditService(), new UploadService());
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'aggal_') . '.png';
        $im = imagecreatetruecolor(300, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 40));
        imagepng($im, $tmp); imagedestroy($im);
        return new UploadedFile($tmp, 'p.png', 'image/png', filesize($tmp) ?: null, UPLOAD_ERR_OK, false);
    }

    private function addPhoto(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominees/5/photo')
            ->withUploadedFiles(['photo' => $this->pngUpload()]);
        $this->ctrl()->photo($req, new Response(), ['id' => 5]);
        $path = (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path');
        return $path;
    }

    private function galleryPaths(): array
    {
        return DB::table('gates_uploads')->where('attached_to_type', 'nominee')->where('attached_to_id', 5)->orderBy('id')->pluck('path')->all();
    }

    public function test_multiple_uploads_accumulate_first_is_primary(): void
    {
        $this->addPhoto();
        $primary = (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path');
        $this->assertStringStartsWith('/uploads/nominees/', $primary);

        $this->addPhoto(); // second upload
        $gallery = $this->galleryPaths();
        foreach ($gallery as $p) { $this->written[] = dirname(__DIR__, 2) . '/public' . $p; }

        $this->assertCount(2, $gallery, 'both photos are in the gallery');
        // Primary unchanged by the second add.
        $this->assertSame($primary, (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path'));
    }

    public function test_promote_then_delete_primary_repoints(): void
    {
        $this->addPhoto(); $this->addPhoto();
        $gallery = $this->galleryPaths();
        foreach ($gallery as $p) { $this->written[] = dirname(__DIR__, 2) . '/public' . $p; }
        [$first, $second] = $gallery;

        // Promote the second photo to primary.
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominees/5/photo/primary')->withParsedBody(['path' => $second]);
        $this->ctrl()->photoPrimary($req, new Response(), ['id' => 5]);
        $this->assertSame($second, (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path'));

        // Delete the primary (second) → repoints to the remaining (first).
        $req2 = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominees/5/photo/delete')->withParsedBody(['path' => $second]);
        $this->ctrl()->photoDelete($req2, new Response(), ['id' => 5]);
        $this->assertCount(1, $this->galleryPaths());
        $this->assertSame($first, (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path'));
    }

    public function test_primary_rejects_foreign_path(): void
    {
        $this->addPhoto();
        foreach ($this->galleryPaths() as $p) { $this->written[] = dirname(__DIR__, 2) . '/public' . $p; }
        $before = (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path');

        // A path not in this nominee's gallery must be refused (no arbitrary repoint).
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominees/5/photo/primary')->withParsedBody(['path' => '/uploads/nominees/evil.jpg']);
        $this->ctrl()->photoPrimary($req, new Response(), ['id' => 5]);
        $this->assertSame($before, (string) DB::table('gates_nominees')->where('id', 5)->value('photo_path'));
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }
}
