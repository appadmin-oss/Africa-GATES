<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;

/**
 * Image upload + processing.
 * Stores files under public/uploads/<bucket>/<yyyy>/<mm>/<uuid>.<ext>
 * Records metadata in gates_uploads.
 */
class UploadService
{
    private readonly ImageManager $img;
    private readonly string $publicRoot;

    public function __construct(?string $publicRoot = null)
    {
        $this->publicRoot = $publicRoot ?? dirname(__DIR__, 3) . '/public';
        $this->img = new ImageManager(new Driver());
    }

    /**
     * Upload an image and produce a web-optimised variant.
     * @return array{path:string,url:string,width:int,height:int,size:int}
     */
    public function uploadImage(
        UploadedFileInterface $file,
        string $bucket = 'images',
        int $maxWidth = 1600,
        int $quality = 82,
        ?int $adminId = null,
        ?string $attachedToType = null,
        ?int $attachedToId = null
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed: error code ' . $file->getError());
        }
        $mime = (string)$file->getClientMediaType();
        if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
            throw new \RuntimeException('Unsupported image type: ' . $mime);
        }
        if ($file->getSize() && $file->getSize() > 10 * 1024 * 1024) {
            throw new \RuntimeException('Image larger than 10MB');
        }

        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
        $uuid = Uuid::uuid4()->toString();
        $relDir = sprintf('uploads/%s/%s/%s', $bucket, date('Y'), date('m'));
        $absDir = $this->publicRoot . '/' . $relDir;
        if (!is_dir($absDir)) @mkdir($absDir, 0775, true);

        $relPath = $relDir . '/' . $uuid . '.' . $ext;
        $absPath = $this->publicRoot . '/' . $relPath;

        $tmp = tempnam(sys_get_temp_dir(), 'ag_up_');
        $file->moveTo($tmp);

        $image = $this->img->read($tmp);
        if ($image->width() > $maxWidth) {
            $image->scaleDown(width: $maxWidth);
        }

        if ($ext === 'jpg' || $ext === 'webp') {
            $image->toJpeg($quality)->save($absPath);
        } else {
            $image->save($absPath);
        }
        @unlink($tmp);

        $w = $image->width(); $h = $image->height();
        $size = filesize($absPath) ?: 0;

        try {
            DB::table('gates_uploads')->insert([
                'uploader_id' => $adminId,
                'uploader_type' => 'admin',
                'path' => '/' . $relPath,
                'mime' => $mime,
                'size_bytes' => $size,
                'width' => $w,
                'height' => $h,
                'attached_to_type' => $attachedToType,
                'attached_to_id' => $attachedToId,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }

        return [
            'path' => '/' . $relPath,
            'url'  => '/' . $relPath,
            'width' => $w,
            'height' => $h,
            'size' => $size,
        ];
    }
}
