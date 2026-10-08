<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\SupportAttachmentService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The only way an attachment's bytes leave the server.
 *
 * Files live outside the document root, so there is no static URL to guess and no
 * misconfigured directory listing to find. Everything goes through here, and here
 * asks {@see SupportAttachmentService::mayView()} first.
 *
 * ── REFUSALS ARE 404, NOT 403 ────────────────────────────────────────────────
 *
 * A 403 confirms the attachment exists. On a support ticket that is already a
 * disclosure — it tells a stranger that a particular reference has evidence
 * attached to it. The same page a missing file produces is the honest answer to
 * "you may not see this", and it is what the ticket thread itself already does
 * with a bad token.
 *
 * ── AND THE HEADERS MATTER ───────────────────────────────────────────────────
 *
 * `X-Content-Type-Options: nosniff` and an explicit Content-Type, because the one
 * type here that a browser will happily execute in a document context is the one
 * people will upload most: a PDF. `Content-Disposition: inline` for images so
 * they render in the thread, `attachment` for PDFs so nothing is opened as an
 * active document in the site's own origin.
 */
final class SupportAttachmentController
{
    public function show(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id < 1) return $res->withStatus(404);

        try {
            $att = DB::table('gates_support_attachments')
                ->where('id', $id)->whereNull('deleted_at')->first();
        } catch (\Throwable) { $att = null; }
        if (!$att) return $res->withStatus(404);

        $q     = (array) $req->getQueryParams();
        $token = trim((string) ($q['t'] ?? ''));

        $adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $userId  = isset($_SESSION['user_id'])  ? (int) $_SESSION['user_id']  : null;

        if (!SupportAttachmentService::mayView($att, $adminId, $userId, $token !== '' ? $token : null)) {
            return $res->withStatus(404);
        }

        $path = SupportAttachmentService::pathOf($att);
        if ($path === null) return $res->withStatus(404);

        $mime   = (string) $att->mime;
        $inline = str_starts_with($mime, 'image/');
        $name   = (string) ($att->original_name ?: 'attachment');
        // Quoted and stripped of anything that could break out of the header.
        $safe   = preg_replace('/[^A-Za-z0-9._ \-]/', '', $name) ?: 'attachment';

        $res->getBody()->write((string) file_get_contents($path));

        return $res
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"')
            // Private evidence must not sit in a shared cache. `no-store` rather
            // than a max-age, because the access check is per request and a cached
            // copy would outlive somebody's permission to see it.
            ->withHeader('Cache-Control', 'private, no-store')
            ->withStatus(200);
    }
}
