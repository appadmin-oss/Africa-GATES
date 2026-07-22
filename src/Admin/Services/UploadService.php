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
        ?int $attachedToId = null,
        int $minDim = 0
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed: error code ' . $file->getError());
        }
        if ($file->getSize() && $file->getSize() > 10 * 1024 * 1024) {
            throw new \RuntimeException('Image larger than 10MB');
        }

        // Move first, then trust the BYTES (finfo magic), never the client-supplied
        // Content-Type — the nominee-photo path is reachable by anonymous visitors,
        // so the client MIME is a hint only. The stored extension is derived from the
        // detected type, and every image is re-encoded below as a second guarantee.
        $tmp = tempnam(sys_get_temp_dir(), 'ag_up_');
        $file->moveTo($tmp);

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowed[$mime])) {
            @unlink($tmp);
            throw new \RuntimeException('Unsupported image type' . ($mime !== '' ? ': ' . $mime : '') . '. Upload a JPEG, PNG, WebP or GIF.');
        }
        $ext = $allowed[$mime];

        $uuid = Uuid::uuid4()->toString();
        $relDir = sprintf('uploads/%s/%s/%s', $bucket, date('Y'), date('m'));
        $absDir = $this->publicRoot . '/' . $relDir;
        if (!is_dir($absDir)) @mkdir($absDir, 0775, true);

        $relPath = $relDir . '/' . $uuid . '.' . $ext;
        $absPath = $this->publicRoot . '/' . $relPath;

        $image = $this->img->read($tmp);
        // Reject unsuitable images (too small to be a usable portrait) before saving,
        // so no orphan file is written. Decode failures already throw above.
        if ($minDim > 0 && ($image->width() < $minDim || $image->height() < $minDim)) {
            @unlink($tmp);
            throw new \RuntimeException("Image too small — use at least {$minDim}×{$minDim}px.");
        }
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

    /**
     * Upload a supporting document (PDF) or scanned image — e.g. judge-dossier
     * evidence or nomination supporting files. Unlike uploadImage(), the true
     * content type is detected from the file's bytes (finfo) rather than trusting
     * the client-supplied MIME, and PDFs are verified by magic bytes. Images are
     * routed through the same hardened re-encode path; PDFs are stored verbatim.
     * The bucket .htaccess neutralises any script execution defence-in-depth.
     *
     * @return array{path:string,url:string,mime:string,ext:string,size:int}
     */
    public function uploadDocument(
        UploadedFileInterface $file,
        string  $bucket = 'documents',
        int     $maxMb = 15,
        ?int    $uploaderId = null,
        string  $uploaderType = 'admin',
        ?string $attachedToType = null,
        ?int    $attachedToId = null
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed: error code ' . $file->getError());
        }
        if ($file->getSize() && $file->getSize() > $maxMb * 1024 * 1024) {
            throw new \RuntimeException("File larger than {$maxMb}MB");
        }
        if (!in_array($uploaderType, ['admin', 'public', 'system'], true)) {
            $uploaderType = 'admin';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ag_doc_');
        $file->moveTo($tmp);
        try {
            // Trust the bytes, not the client MIME.
            $real   = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $images = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $isPdf  = ($real === 'application/pdf');
            $isImg  = isset($images[$real]);
            if (!$isPdf && !$isImg) {
                throw new \RuntimeException('Unsupported file type: ' . $real . '. Upload a PDF or an image.');
            }
            if ($isPdf && strncmp((string)file_get_contents($tmp, false, null, 0, 5), '%PDF-', 5) !== 0) {
                throw new \RuntimeException('File is not a valid PDF.');
            }

            $ext    = $isPdf ? 'pdf' : $images[$real];
            $uuid   = Uuid::uuid4()->toString();
            $relDir = sprintf('uploads/%s/%s/%s', $bucket, date('Y'), date('m'));
            $absDir = $this->publicRoot . '/' . $relDir;
            if (!is_dir($absDir)) @mkdir($absDir, 0775, true);
            $relPath = $relDir . '/' . $uuid . '.' . $ext;
            $absPath = $this->publicRoot . '/' . $relPath;

            $w = 0; $h = 0;
            if ($isPdf) {
                copy($tmp, $absPath);
            } else {
                $image = $this->img->read($tmp);
                if ($image->width() > 2000) { $image->scaleDown(width: 2000); }
                if ($ext === 'jpg' || $ext === 'webp') { $image->toJpeg(85)->save($absPath); }
                else { $image->save($absPath); }
                $w = $image->width(); $h = $image->height();
            }
            $size = filesize($absPath) ?: 0;

            try {
                DB::table('gates_uploads')->insert([
                    'uploader_id'      => $uploaderId,
                    'uploader_type'    => $uploaderType,
                    'path'             => '/' . $relPath,
                    'mime'             => $real,
                    'size_bytes'       => $size,
                    'width'            => $w,
                    'height'           => $h,
                    'attached_to_type' => $attachedToType,
                    'attached_to_id'   => $attachedToId,
                    'created_at'       => Carbon::now()->toDateTimeString(),
                ]);
            } catch (\Throwable $e) { /* metadata is non-fatal */ }

            return [
                'path' => '/' . $relPath,
                'url'  => '/' . $relPath,
                'mime' => $real,
                'ext'  => $ext,
                'size' => $size,
            ];
        } finally {
            @unlink($tmp);
        }
    }
}
