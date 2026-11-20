<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A file sent with BYTE RANGES, for audio served through PHP rather than as a static file.
 *
 * Safari will not play an `<audio>` whose server ignores `Range`: it asks for `bytes=0-1`
 * first, and a 200 with the whole body reads to it as a server that cannot seek, so the player
 * shows a dash and never starts — on every iPhone, with nothing in any log. A static file gets
 * this from Apache for free; a route has to do it itself. One range only (that is all a media
 * element asks for); anything else is answered whole, which is always allowed.
 */
final class RangeFile
{
    public static function respond(Request $req, Response $res, string $path, string $mime, string $cache = 'private, max-age=300'): Response
    {
        $size = (int) @filesize($path);
        $res = $res->withHeader('Content-Type', $mime)->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Cache-Control', $cache)->withHeader('X-Content-Type-Options', 'nosniff');
        $start = 0; $end = $size - 1; $partial = false;
        if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', trim($req->getHeaderLine('Range')), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
            else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
            if ($start > $end || $start >= $size) {
                return $res->withStatus(416)->withHeader('Content-Range', 'bytes */' . $size);
            }
            $partial = true;
        }
        $len = $size > 0 ? $end - $start + 1 : 0;
        $fh = @fopen($path, 'rb');
        if ($fh === false) return $res->withStatus(404);
        if ($start > 0) fseek($fh, $start);
        $res->getBody()->write($len > 0 ? (string) fread($fh, $len) : '');
        fclose($fh);
        $res = $res->withHeader('Content-Length', (string) $len);
        return $partial ? $res->withStatus(206)->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size) : $res;
    }
}
