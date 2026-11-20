<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use AfricaGates\Services\PulseMediaService;
use AfricaGates\Support\SchemaHas;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A short video on an ad (owner, 11 Nov 2026: "video ads too").
 *
 * Every large platform sells video in the feed, and they agree on the shape: short, square or
 * upright, playing SILENTLY as it scrolls into view, with sound one tap away, and judged by
 * how many people watched rather than only by who tapped (Meta's "ThruPlay": watched to the
 * end, or for fifteen seconds). This is that shape, sized for the people who read this site:
 *
 *   · OPTIONAL, ON TOP OF THE IMAGE, IN ITS SHAPE. The image is the poster the card shows before
 *     the video plays, and the whole ad wherever the video does not: with no script, under
 *     Save-Data or on a 2G connection, for somebody who asked for reduced motion, and in an
 *     email. An ad is never a black box waiting for bytes.
 *   · SHORT AND SMALL. Up to MAX_SECONDS and MAX_BYTES, and never more than the server will
 *     actually accept (PulseMediaService::limitBytes — a host that silently drops a large
 *     POST must not be promised a size it discards). A reader pays for every byte of an ad
 *     they did not ask for; on a metered phone a 60MB clip is a cost, not a creative choice.
 *   · ONLY WHEN SEEN. The card loads nothing (`preload="none"`) until it is half on screen
 *     and then plays muted; it pauses when it leaves. Readers on Save-Data, 2G or reduced
 *     motion get a play button instead of autoplay (ads.js).
 *   · MP4 ONLY. It is the one container every phone browser plays; nothing here can
 *     re-encode (no ffmpeg on the host), so what is stored is what was sent, and a WebM
 *     that will not play on an iPhone would be an ad its buyer paid for and half the
 *     audience never saw.
 *
 * SAFETY is PulseMediaService's, for the same reason and with the same honesty: the TYPE is
 * decided by finfo on the stored bytes, the extension on disk comes from that decision and
 * never from the browser's filename, the name is random, and `public/uploads/.htaccess`
 * refuses to execute anything under that tree. The contents of a video are not inspected
 * by a machine — nothing here could — which is why every ad's video is WATCHED by the
 * reviewer before it runs (AdReview), the same person who checks the image.
 *
 * The duration is read from the file's own header (the `mvhd` box), not from anything the
 * browser said, so the length the advertiser is held to is the length that plays.
 */
final class AdVideo
{
    public const MAX_SECONDS = 30;
    public const MIN_SECONDS = 2;
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Meta's ThruPlay: a play counts as watched once it reaches the end or this many seconds. */
    public const WATCHED_SECONDS = 15;

    /** Where videos are written; the tests point it at a scratch directory. */
    public static ?string $publicRoot = null;

    public static function ready(): bool
    {
        return SchemaHas::column('gates_ads', 'video_path');
    }

    /** The largest video this server will take: ours, or PHP's, whichever is smaller. */
    public static function limitBytes(): int
    {
        return min(self::MAX_BYTES, PulseMediaService::limitBytes());
    }

    public static function limitMb(): int
    {
        return max(1, intdiv(self::limitBytes(), 1048576));
    }

    /**
     * Store an uploaded video, or say why not in words the advertiser can act on.
     *
     * @return array{ok:true, path:string, seconds:int}|array{ok:false, message:string}
     */
    public static function store(UploadedFileInterface $file): array
    {
        $size = (int) ($file->getSize() ?? 0);
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE || $size > self::limitBytes()) {
            return ['ok' => false, 'message' => sprintf('Keep the video under %dMB. Most phones can save a smaller copy; a video sent through WhatsApp comes back small enough.', self::limitMb())];
        }
        if ($file->getError() !== UPLOAD_ERR_OK) return ['ok' => false, 'message' => 'The video did not arrive. Try again.'];

        $tmp = tempnam(sys_get_temp_dir(), 'ag_adv_');
        if ($tmp === false) return ['ok' => false, 'message' => 'The server has nowhere to put the video.'];
        try {
            $file->moveTo($tmp);
        } catch (\Throwable) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'The video could not be read.'];
        }
        // THE decision, on the stored bytes; never the name or the Content-Type the browser sent.
        if ((string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'video/mp4') {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'Use an MP4 video: it is the kind every phone can play.'];
        }
        $secs = self::duration($tmp);
        if ($secs === null) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'That video could not be read. Save it again as an MP4 and try once more.'];
        }
        if ($secs > self::MAX_SECONDS + 0.5) {
            @unlink($tmp);
            return ['ok' => false, 'message' => sprintf('Keep the video to %d seconds or less. This one is %d.', self::MAX_SECONDS, (int) round($secs))];
        }
        if ($secs < self::MIN_SECONDS) {
            @unlink($tmp);
            return ['ok' => false, 'message' => sprintf('A video needs at least %d seconds.', self::MIN_SECONDS)];
        }

        $root = self::$publicRoot ?? dirname(__DIR__, 3) . '/public';
        $relDir = sprintf('uploads/ads/video/%s/%s', date('Y'), date('m'));
        if (!is_dir($root . '/' . $relDir) && !@mkdir($root . '/' . $relDir, 0775, true) && !is_dir($root . '/' . $relDir)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'The server could not make a place for the video.'];
        }
        // A random name and the extension from the DETECTED type: the browser's filename never
        // reaches the disk, so it cannot carry a path, a second extension or a null byte.
        $rel = $relDir . '/' . bin2hex(random_bytes(16)) . '.mp4';
        if (!@rename($tmp, $root . '/' . $rel)) {
            if (!@copy($tmp, $root . '/' . $rel)) {
                @unlink($tmp);
                return ['ok' => false, 'message' => 'The video could not be stored.'];
            }
            @unlink($tmp);
        }
        @chmod($root . '/' . $rel, 0644);
        return ['ok' => true, 'path' => '/' . $rel, 'seconds' => max(self::MIN_SECONDS, (int) round($secs))];
    }

    /**
     * The ad's video from a request, if one was sent: stored, and its path and length put
     * into the body for AdRules::creative() to carry — AFTER anything the form itself posted
     * under those names has been dropped, so a path can only ever be one this server wrote.
     * On an edit, "remove the video" with no new file takes it off. Returns the reason a
     * video was refused, or null. One function, for the Ads Manager and the console alike.
     *
     * @param array<string,mixed> $files the request's uploaded files
     * @param array<string,mixed> $b     the request body, changed in place
     */
    public static function take(array $files, array &$b, bool $editing = false): ?string
    {
        unset($b['_video'], $b['_video_seconds']);
        $file = $files['video'] ?? null;
        if ($file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            if (!self::ready()) return 'Video ads are not available yet. Send it without the video.';
            $r = self::store($file);
            if (!$r['ok']) return $r['message'];
            $b['_video'] = $r['path'];
            $b['_video_seconds'] = $r['seconds'];
        } elseif ($editing && !empty($b['remove_video'])) {
            $b['_video'] = null;
        }
        return null;
    }

    /**
     * A stored ad video's address, re-checked on the way out: only a path this class could
     * have written. A row outlives the code that wrote it, and whatever is in it reaches a
     * `src` on every page an ad is drawn on.
     */
    public static function url(?string $path): ?string
    {
        $p = (string) $path;
        return preg_match('~^/uploads/ads/video/\d{4}/\d{2}/[a-f0-9]{32}\.mp4$~', $p) === 1 ? $p : null;
    }

    /**
     * Seconds, from the MP4's own movie header (`moov` → `mvhd`: timescale and duration), or
     * null when the file has none. Reads only the boxes it needs, never the whole file; walks
     * at most a few thousand boxes so a crafted file cannot keep it busy.
     */
    public static function duration(string $file): ?float
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) return null;
        try {
            $size = (int) filesize($file);
            $moov = self::box($fh, 0, $size, 'moov');
            if ($moov === null) return null;
            $mvhd = self::box($fh, $moov[0], $moov[1], 'mvhd');
            if ($mvhd === null || $mvhd[1] - $mvhd[0] < 20) return null;
            fseek($fh, $mvhd[0]);
            $head = (string) fread($fh, 32);
            if (strlen($head) < 20) return null;
            if (ord($head[0]) === 1) {
                if (strlen($head) < 32) return null;
                $scale = unpack('N', substr($head, 20, 4))[1];
                $dur = unpack('J', substr($head, 24, 8))[1];
            } else {
                $scale = unpack('N', substr($head, 12, 4))[1];
                $dur = unpack('N', substr($head, 16, 4))[1];
            }
            return $scale > 0 ? $dur / $scale : null;
        } finally {
            fclose($fh);
        }
    }

    /**
     * The first box of a type between two offsets, as [content start, content end], or null.
     *
     * @param resource $fh
     * @return array{0:int,1:int}|null
     */
    private static function box($fh, int $from, int $to, string $type): ?array
    {
        $pos = $from;
        for ($n = 0; $n < 4096 && $pos + 8 <= $to; $n++) {
            fseek($fh, $pos);
            $h = (string) fread($fh, 8);
            if (strlen($h) < 8) return null;
            $len = unpack('N', substr($h, 0, 4))[1];
            $hdr = 8;
            if ($len === 1) {
                $x = (string) fread($fh, 8);
                if (strlen($x) < 8) return null;
                $len = unpack('J', $x)[1];
                $hdr = 16;
            } elseif ($len === 0) {
                $len = $to - $pos;               // "to the end of the enclosing box"
            }
            if ($len < $hdr) return null;
            if (substr($h, 4, 4) === $type) return [$pos + $hdr, min($to, $pos + $len)];
            $pos += $len;
        }
        return null;
    }
}
