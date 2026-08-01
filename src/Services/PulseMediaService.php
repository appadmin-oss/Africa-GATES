<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Admin\Services\UploadService;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The one attachment on a Pulse post.
 *
 * ── IMAGES REUSE THE HARDENED PATH; VIDEO CANNOT ────────────────────────────
 *
 * A photo goes through {@see UploadService::uploadImage()}, which already does
 * the things that matter: it trusts finfo's reading of the BYTES rather than the
 * client's Content-Type, RE-ENCODES the image (so a payload hidden in a comment
 * block or an EXIF field does not survive), scales it down, records the upload,
 * and pushes to Cloudinary when that is configured. Writing a second image path
 * here would mean a second, weaker one.
 *
 * Video cannot be re-encoded — there is no ffmpeg on the target shared host — so
 * the bytes we store are the bytes we were given. That changes what the defences
 * have to be, and this file is honest about which ones are actually load-bearing:
 *
 *   1. finfo on the stored bytes decides the type. A file called `clip.mp4` that
 *      is really a PHP script is rejected here, not on the way in.
 *   2. The extension written to disk is derived from the DETECTED type, never
 *      from the name the browser sent.
 *   3. `public/uploads/.htaccess` denies script handlers for everything under
 *      that tree — see {@see UploadService::ensureUploadsGuard()}. That is what
 *      stops an unre-encoded file being executed if 1 and 2 were ever bypassed.
 *
 * What this does NOT do is inspect a video's contents for anything beyond its
 * container type. A malicious video targeting a decoder bug in the viewer's
 * browser would pass. That risk is the reason video is members-only, rate
 * limited, attributed to an account, and lands in the same moderation queue as
 * the text — the mitigation is that a human can see it and it is traceable, not
 * that the file has been proven safe.
 *
 * ── SIZE ────────────────────────────────────────────────────────────────────
 *
 * The cap the reader is told about is the SMALLER of our limit and what PHP will
 * actually accept, because a shared host with `upload_max_filesize = 8M` silently
 * discards anything larger and the browser reports a successful POST with no
 * file in it. Telling someone "up to 25MB" on a server that drops 9MB is how you
 * get a bug report that cannot be reproduced anywhere else.
 */
final class PulseMediaService
{
    /** Our own ceiling for a post's video, before PHP's limits are considered. */
    public const MAX_VIDEO_BYTES = 25 * 1024 * 1024;

    /** Detected MIME → stored extension. Nothing else is accepted. */
    private const VIDEO_TYPES = [
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
    ];

    public function __construct(private readonly ?UploadService $uploads = null) {}

    /**
     * Store one attachment.
     *
     * @return array{ok:bool, path?:string, type?:string, w?:int, h?:int, message?:string}
     */
    public function store(UploadedFileInterface $file, ?int $userId = null): array
    {
        $err = $file->getError();
        if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'message' => 'No file was received.'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'message' => 'That file is larger than this server accepts (' . self::humanLimit() . ').'];
        }
        if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'message' => 'The upload did not complete. Please try again.'];

        // The client's Content-Type is a hint used ONLY to choose which handler to
        // try. Both handlers re-derive the real type from the bytes, so a lie here
        // buys nothing: claim "image/png" on a video and the image path rejects it.
        $claimed = strtolower((string) $file->getClientMediaType());

        if (str_starts_with($claimed, 'video/')) return $this->storeVideo($file);
        if (str_starts_with($claimed, 'image/')) return $this->storeImage($file, $userId);

        return ['ok' => false, 'message' => 'Attach a photo (JPEG, PNG, WebP, GIF) or a video (MP4, WebM, MOV).'];
    }

    private function storeImage(UploadedFileInterface $file, ?int $userId): array
    {
        if ($this->uploads === null) return ['ok' => false, 'message' => 'Photo upload is unavailable right now.'];

        try {
            // 1600px wide is what the feed column can ever show on a 2× display;
            // anything larger is bytes the reader pays for and never sees.
            $r = $this->uploads->uploadImage($file, 'pulse', 1600, 82, $userId, 'thread', null);
        } catch (\Throwable $e) {
            // The message is the operator-facing reason (unsupported type, too
            // small, decode failure) and is safe to show — uploadImage throws
            // RuntimeExceptions written for a person.
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $path = (string) ($r['path'] ?? $r['url'] ?? '');
        if ($path === '') return ['ok' => false, 'message' => 'The photo could not be stored.'];

        return [
            'ok'   => true,
            'path' => str_starts_with($path, 'http') || str_starts_with($path, '/') ? $path : '/' . $path,
            'type' => 'image',
            'w'    => (int) ($r['width'] ?? 0),
            'h'    => (int) ($r['height'] ?? 0),
        ];
    }

    private function storeVideo(UploadedFileInterface $file): array
    {
        $size = (int) ($file->getSize() ?? 0);
        if ($size > self::MAX_VIDEO_BYTES) {
            return ['ok' => false, 'message' => 'Keep videos under ' . (int) (self::MAX_VIDEO_BYTES / 1048576) . 'MB.'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ag_vid_');
        if ($tmp === false) return ['ok' => false, 'message' => 'The server has nowhere to put the file.'];

        try {
            $file->moveTo($tmp);
        } catch (\Throwable) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'The upload could not be read.'];
        }

        // THE decision. Made on the stored bytes, never on the filename or the
        // Content-Type, both of which the client chose.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::VIDEO_TYPES[$mime])) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'That is not a video we can play. Use MP4, WebM or MOV.'];
        }
        $ext = self::VIDEO_TYPES[$mime];

        $root   = dirname(__DIR__, 2) . '/public';
        $relDir = sprintf('uploads/pulse/%s/%s', date('Y'), date('m'));
        $absDir = $root . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'The server could not create the upload folder.'];
        }

        // A random name, and an extension derived from the DETECTED type — the
        // client's filename never reaches the filesystem, so it cannot carry a
        // path traversal, a second extension, or a null byte.
        $rel = $relDir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@rename($tmp, $root . '/' . $rel)) {
            // rename() fails across filesystems; copy is the fallback, not the default.
            if (!@copy($tmp, $root . '/' . $rel)) {
                @unlink($tmp);
                return ['ok' => false, 'message' => 'The video could not be stored.'];
            }
            @unlink($tmp);
        }
        @chmod($root . '/' . $rel, 0644);

        return ['ok' => true, 'path' => '/' . $rel, 'type' => 'video', 'w' => 0, 'h' => 0];
    }

    /**
     * The largest upload this server will actually accept, in bytes.
     *
     * PHP silently drops anything over `upload_max_filesize` or `post_max_size`,
     * so the effective limit is the smallest of those and ours. Shared cPanel
     * hosts commonly set these to 8M without saying so.
     */
    public static function limitBytes(): int
    {
        $limits = [self::MAX_VIDEO_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $v = self::toBytes((string) ini_get($k));
            if ($v > 0) $limits[] = $v;
        }
        return min($limits);
    }

    /** The same number, phrased for a person. */
    public static function humanLimit(): string
    {
        $mb = self::limitBytes() / 1048576;
        return ($mb >= 1 ? (string) (int) floor($mb) : rtrim(rtrim(number_format($mb, 1), '0'), '.')) . 'MB';
    }

    /** "8M", "512K", "1G" → bytes. Returns 0 for an unset or unparseable value. */
    private static function toBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') return 0;          // -1 means no limit
        $unit = strtolower(substr($v, -1));
        $n    = (int) $v;
        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
