<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use AfricaGates\Admin\Services\{EventCoverUpload, UploadService};
use Slim\Psr7\UploadedFile;
final class EventCoverUploadTest extends TestCase
{
    public function test_cover_is_a_local_image_usable_by_invitation_attachments(): void
    {
        $root = sys_get_temp_dir() . '/event-cover-' . bin2hex(random_bytes(4)); mkdir($root);
        $tmp = tempnam(sys_get_temp_dir(), 'cover');
        copy(dirname(__DIR__, 2) . '/public/assets/img/honour/teacher-960.webp', $tmp);
        try {
            $file = new UploadedFile($tmp, 'cover.webp', 'image/webp', filesize($tmp), UPLOAD_ERR_OK, false);
            $path = EventCoverUpload::save($file, 0, new UploadService($root));
            $this->assertStringStartsWith('uploads/event-covers/', $path);
            $this->assertFileExists($root . '/' . $path);
            $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->file($root . '/' . $path));
        } finally {
            if (is_file($tmp)) unlink($tmp);
            $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            rmdir($root);
        }
    }
}
