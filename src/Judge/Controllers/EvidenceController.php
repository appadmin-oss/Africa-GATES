<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Controllers;

use AfricaGates\Judge\Services\JudgeService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nominee evidence files, streamed to a judge who is entitled to see them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ROUTE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A nominee's uploaded file was stored as a relative path in
 * `gates_nominee_evidence.source_url` and rendered by the ballot as a link. The browser
 * resolved `uploads/nominee-evidence/…/x.pdf` against `/judge/ballot` and asked for
 * `/judge/uploads/…` — a 404. Only Cloudinary-hosted images worked, because those come
 * back as absolute URLs; every locally stored PDF was a dead link.
 *
 * The obvious fix — emit `/uploads/...` — is the wrong one. `UploadService` keeps PDFs off
 * the CDN deliberately: nomination evidence is private moderation material, and a public
 * path makes an unguessable filename the only thing between it and the internet. So it is
 * streamed, and the stream is authorised.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AUTHORISED PER NOMINEE, NOT PER ROLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Is a judge signed in" is not the question. Broken Access Control is the most common
 * serious web vulnerability there is, and the shape it takes here is a judge on one panel
 * reading another panel's dossier by incrementing an id. So every request re-derives the
 * chain — evidence → nominee → category → cycle → programme — and checks that programme
 * against this judge's own assignments. {@see JudgeService::evidenceFor()}.
 *
 * `visible_to_judges` is honoured as well: an item withheld from the panel stays withheld
 * even from a judge who is otherwise entitled to the nominee.
 */
final class EvidenceController
{
    public function stream(Request $req, Response $res, array $args): Response
    {
        $judgeId = (int) ($_SESSION['judge_id'] ?? 0);
        $id      = (int) ($args['id'] ?? 0);
        if ($judgeId < 1 || $id < 1) return $res->withStatus(404);

        $row = (new JudgeService())->evidenceFor($judgeId, $id);
        // 404 rather than 403 for an evidence row this judge may not see: a 403 confirms
        // the id exists, which is itself a fact about another panel's dossier.
        if (!$row) return $res->withStatus(404);

        $stored = trim((string) ($row->source_url ?? ''));

        // ── AN ABSOLUTE URL: OURS, OR NOT AT ALL ────────────────────────────
        //
        // Redirect rather than 404, so a CDN-hosted item still opens — that was always
        // the intent, and this now names the condition it assumed. Only the platform's
        // own delivery host: `EvidenceService::fileFor()` produces a file_url for
        // Cloudinary items and nothing else, so this route is a FRAME TARGET now, and a
        // route that will forward a judge to any URL a nominee stored is an open redirect
        // with a signed-in reader on the other end of it.
        //
        // 404 rather than a message, matching the entitlement check above: the two
        // failures an unauthorised caller can produce should look the same.
        if (preg_match('~^https?://~i', $stored)) {
            if (!\AfricaGates\Services\CloudinaryService::isRemote($stored)) {
                return $res->withStatus(404);
            }

            // `?download=1` has to survive the hop. Without this the fallback link under
            // the preview — the one route through on a browser with no PDF viewer — sent
            // the reader to the same inline render that had already failed them.
            $to = ($req->getQueryParams()['download'] ?? '') === '1'
                ? (\AfricaGates\Services\CloudinaryService::transformed($stored, 'fl_attachment') ?? $stored)
                : $stored;

            return $res->withHeader('Location', $to)->withStatus(302);
        }

        // Resolved strictly inside the uploads tree. `realpath` first, then the prefix
        // check, so a stored path containing `..` cannot escape — the stored value comes
        // from an upload but is still data, and this is the one place it becomes a
        // filesystem read.
        $publicRoot  = dirname(__DIR__, 3) . '/public';
        $real        = realpath($publicRoot . '/' . ltrim($stored, '/'));
        $uploadsRoot = realpath($publicRoot . '/uploads');
        if (!$real || !$uploadsRoot || !str_starts_with($real, $uploadsRoot) || !is_file($real)) {
            return $res->withStatus(404);
        }

        $mime = self::sniff($real);
        // Only these preview inline. Anything else downloads, because an inline render of
        // an unexpected type is how an upload becomes a script in the reader's origin.
        $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
        $wantsDownload = ($req->getQueryParams()['download'] ?? '') === '1';

        $stream = fopen($real, 'rb');
        if ($stream === false) return $res->withStatus(500);

        return $res
            ->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->withHeader('Content-Length', (string) filesize($real))
            ->withHeader('Content-Disposition',
                ($inline && !$wantsDownload ? 'inline' : 'attachment')
                . '; filename="' . self::safeName($row, $real) . '"')
            // A dossier is private and must not sit in a shared cache.
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            // Belt and braces against the file being interpreted as anything but its type.
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; object-src 'self'; sandbox");
    }

    /** The bytes decide the type, never the extension. */
    private static function sniff(string $path): string
    {
        try {
            $m = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            return is_string($m) && $m !== '' ? $m : 'application/octet-stream';
        } catch (\Throwable) {
            return 'application/octet-stream';
        }
    }

    /**
     * A filename the reader will recognise, with nothing in it that can break the header.
     *
     * The evidence title is nominee-supplied text, so quotes, newlines and semicolons all
     * have to go before it lands in a Content-Disposition.
     */
    private static function safeName(object $row, string $real): string
    {
        $title = trim((string) ($row->title ?? ''));
        $base  = $title !== '' ? $title : pathinfo($real, PATHINFO_FILENAME);
        $base  = preg_replace('/[^A-Za-z0-9 ._-]+/', '', $base) ?? '';
        $base  = trim(preg_replace('/\s+/', ' ', $base) ?? '') ?: 'evidence';

        return mb_substr($base, 0, 80) . '.' . strtolower(pathinfo($real, PATHINFO_EXTENSION) ?: 'bin');
    }
}
