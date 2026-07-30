<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\CloudinaryService;
use AfricaGates\Support\MediaPublicId;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;

/**
 * Image upload + processing.
 *
 * Writes to `public/uploads/<bucket>/<yyyy>/<mm>/<uuid>.<ext>` first, ALWAYS, and
 * records metadata in `gates_uploads`.
 *
 * ── AND THEN TO CLOUDINARY, WHEN IT IS CONFIGURED ────────────────────────────
 *
 * The local write is not a fallback that Cloudinary replaces; it is the first step of
 * both paths. The image has to land on disk to be sniffed by finfo, re-encoded, size-
 * checked and scaled — that is the hardening, and it happens before anything leaves
 * this server. Only then is the (already sanitised) file handed to Cloudinary.
 *
 * Order matters for a second reason: an upload that succeeds locally and then fails to
 * reach Cloudinary still returns a usable path, so a nomination form does not reject a
 * supporter's photo because of a CDN outage or an expired API key. The stored path is
 * whichever URL is actually serveable, and `gates_uploads.provider` records which one
 * it is so {@see \AfricaGates\Services\MediaMigrationService} can sweep up the misses
 * later.
 */
class UploadService
{
    private readonly ImageManager $img;
    private readonly string $publicRoot;

    /** Does `gates_uploads` carry the provider columns yet? Resolved on first need. */
    private ?bool $hasProviderColumns = null;

    public function __construct(
        ?string $publicRoot = null,
        private readonly ?CloudinaryService $cloud = null,
    ) {
        $this->publicRoot = $publicRoot ?? dirname(__DIR__, 3) . '/public';
        $this->img = new ImageManager(new Driver());
    }

    /**
     * The hardening rules Apache must apply to `public/uploads/`.
     *
     * ── WHY THIS IS WRITTEN AT RUNTIME AND NOT JUST COMMITTED ────────────────
     *
     * It IS committed — `public/uploads/.htaccess`, and `.gitignore` now negates that
     * one path so it ships. But a dotfile at the root of a directory is exactly what
     * goes missing on the way to a shared host: cPanel's File Manager hides dotfiles by
     * default, an FTP client can be configured not to transfer them, and unzipping an
     * archive built with `zip -r` from a GUI frequently drops them. The failure is
     * silent and the thing it protects is the one directory on the server holding
     * attacker-influenced bytes.
     *
     * So the file is re-created whenever a bucket directory is created and found to be
     * missing. Never overwritten — an operator who has deliberately tightened it keeps
     * their version.
     */
    private function ensureUploadsGuard(): void
    {
        $guard = $this->publicRoot . '/uploads/.htaccess';
        if (is_file($guard)) return;
        if (!is_dir(dirname($guard))) return;

        // Kept deliberately short: the committed file is the documented, fuller version
        // and this is the emergency floor — no script execution, no CSP-less SVG.
        //
        // EVERY directive here must be one that cannot 500 a shared host, because this
        // is written unattended onto a server nobody is watching. So: no `Options`
        // (needs AllowOverride Options, commonly restricted), no `php_flag` (same), no
        // `setifempty` (Apache 2.4.7+ and unsupported by LiteSpeed), and `RemoveHandler`
        // only inside its mod_mime guard. The first version of the committed file used
        // three of those four and took a deployment down; writing them from PHP would
        // have been the same outage with no file to point at.
        $rules = <<<'HTACCESS'
        # Re-created by AfricaGates\Admin\Services\UploadService because it was missing.
        # The committed public/uploads/.htaccess is the fuller, documented version —
        # if you are reading this, that file did not survive the deploy.
        <FilesMatch "\.(?:php|phtml|phps|php[0-9]|phar|cgi|pl|py|asp|sh)$">
          <IfModule mod_authz_core.c>
            Require all denied
          </IfModule>
          <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
          </IfModule>
        </FilesMatch>
        <IfModule mod_mime.c>
          RemoveHandler .php .phtml .phar .cgi .pl .py
          RemoveType .php .phtml .phar .cgi .pl .py
        </IfModule>
        <IfModule mod_headers.c>
          Header set Content-Security-Policy "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"
          Header set X-Content-Type-Options "nosniff"
          Header set X-Frame-Options "DENY"
          Header set Referrer-Policy "no-referrer"
        </IfModule>
        HTACCESS;

        @file_put_contents($guard, preg_replace('/^        /m', '', $rules) . "\n");
    }

    /**
     * Hand a locally-stored, already-sanitised file to Cloudinary.
     *
     * Returns the remote descriptor, or null when Cloudinary is off or the upload
     * failed — in which case the caller keeps the local path it already has. Never
     * throws: this is an enhancement of a completed upload, and the completed upload
     * must survive the enhancement failing.
     *
     * @return array{public_id:string, url:string, width:int, height:int, bytes:int}|null
     */
    private function toCloud(string $absPath, string $bucket, string $relPath): ?array
    {
        if (!CloudinaryService::enabled()) return null;

        // Deterministic public id derived from the local relative path, so re-uploading
        // the same file (a retry, or the migration sweep later finding this row still
        // marked local) overwrites one asset rather than accumulating copies.
        $publicId = MediaPublicId::forPath($relPath);

        $r = ($this->cloud ?? new CloudinaryService())->upload($absPath, $bucket, $publicId);
        if (!($r['ok'] ?? false)) return null;

        return [
            'public_id' => (string) $r['public_id'],
            'url'       => (string) $r['url'],
            'width'     => (int) ($r['width'] ?? 0),
            'height'    => (int) ($r['height'] ?? 0),
            'bytes'     => (int) ($r['bytes'] ?? 0),
        ];
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
        $this->ensureUploadsGuard();

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

        // The image is now sanitised and on disk. Only now does it leave this server.
        $remote  = $this->toCloud($absPath, $bucket, $relPath);
        $stored  = $remote !== null ? $remote['url'] : '/' . $relPath;

        try {
            DB::table('gates_uploads')->insert([
                'uploader_id' => $adminId,
                'uploader_type' => 'admin',
                'path' => $stored,
                'mime' => $mime,
                'size_bytes' => $size,
                'width' => $w,
                'height' => $h,
                'attached_to_type' => $attachedToType,
                'attached_to_id' => $attachedToId,
                'created_at' => Carbon::now()->toDateTimeString(),
            ] + $this->providerColumns($remote, $relPath));
        } catch (\Throwable $e) { /* non-fatal */ }

        return [
            'path' => $stored,
            'url'  => $stored,
            'width' => $w,
            'height' => $h,
            'size' => $size,
        ];
    }

    /**
     * The provider bookkeeping columns for a `gates_uploads` row.
     *
     * Separate from the insert array because the columns arrive with a dated migration:
     * on a database where it has not run yet, the insert must not fail with "unknown
     * column" and silently lose an upload's metadata. So they are appended only when
     * the schema actually has them, and a pre-migration deployment behaves exactly as
     * it did before.
     *
     * `local_path` is kept even for a Cloudinary upload. It is what lets an operator
     * re-derive the deterministic public id, verify an asset, or move to a different
     * host later without having to reverse-engineer a URL.
     */
    private function providerColumns(?array $remote, string $relPath): array
    {
        // Memoised per instance, not per process: a process-wide static would cache
        // "column missing" from a connection that has since been migrated (and, in the
        // test suite, from one in-memory database into the next).
        if ($this->hasProviderColumns === null) {
            try { $this->hasProviderColumns = DB::schema()->hasColumn('gates_uploads', 'provider'); }
            catch (\Throwable) { $this->hasProviderColumns = false; }
        }
        if (!$this->hasProviderColumns) return [];

        return [
            'provider'   => $remote !== null ? 'cloudinary' : 'local',
            'public_id'  => $remote !== null ? $remote['public_id'] : null,
            'local_path' => '/' . ltrim($relPath, '/'),
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
            $this->ensureUploadsGuard();
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

            // Images go to Cloudinary; PDFs stay local on purpose. A PDF here is
            // nomination evidence or a judge dossier — private moderation material that
            // is streamed through the admin-gated /admin/media/{id}/view route, never
            // linked publicly. Putting it on a public CDN URL would make an unlisted
            // guess the only thing between it and the internet, so the CDN's benefit
            // (image transformation, cheap delivery) is nil and the cost is a
            // confidentiality change nobody asked for.
            $remote = $isImg ? $this->toCloud($absPath, $bucket, $relPath) : null;
            $stored = $remote !== null ? $remote['url'] : '/' . $relPath;

            try {
                DB::table('gates_uploads')->insert([
                    'uploader_id'      => $uploaderId,
                    'uploader_type'    => $uploaderType,
                    'path'             => $stored,
                    'mime'             => $real,
                    'size_bytes'       => $size,
                    'width'            => $w,
                    'height'           => $h,
                    'attached_to_type' => $attachedToType,
                    'attached_to_id'   => $attachedToId,
                    'created_at'       => Carbon::now()->toDateTimeString(),
                ] + $this->providerColumns($remote, $relPath));
            } catch (\Throwable $e) { /* metadata is non-fatal */ }

            return [
                'path' => $stored,
                'url'  => $stored,
                'mime' => $real,
                'ext'  => $ext,
                'size' => $size,
            ];
        } finally {
            @unlink($tmp);
        }
    }
}
