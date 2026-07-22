<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Services\AuditService;

/**
 * Media library — lets admins review and remove everything in gates_uploads
 * (nominee photos, nomination evidence, profile/judge/legacy images, …) with
 * previews, provenance and a one-click delete that also removes the file.
 */
class MediaController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
    ) {}

    private function publicRoot(): string { return dirname(__DIR__, 3) . '/public'; }

    public function index(Request $req, Response $res): Response
    {
        $filter = (string)($req->getQueryParams()['type'] ?? '');
        $rows = $counts = [];
        try {
            $q = DB::table('gates_uploads')->orderByDesc('id');
            if ($filter === 'image')   $q->where('mime', 'like', 'image/%');
            elseif ($filter === 'doc') $q->where('mime', 'not like', 'image/%');
            $rows = $q->limit(240)->get()->map(fn($r) => (array)$r)->all();
            $counts = [
                'all'   => DB::table('gates_uploads')->count(),
                'image' => DB::table('gates_uploads')->where('mime', 'like', 'image/%')->count(),
                'doc'   => DB::table('gates_uploads')->where('mime', 'not like', 'image/%')->count(),
            ];
        } catch (\Throwable $e) { /* empty/missing table → empty state */ }

        return $this->view->render($res, 'admin/media/index.twig', [
            'page_title' => 'Media — Admin',
            'admin_page' => 'media',
            'rows'       => $rows,
            'filter'     => in_array($filter, ['image', 'doc'], true) ? $filter : '',
            'counts'     => $counts,
        ]);
    }

    /**
     * Stream an uploaded file by id with the correct Content-Type so images,
     * PDFs and documents all preview/download correctly in the console — even
     * buckets a browser wouldn't render from the raw path. Admin-gated (this
     * runs inside the /admin group), path-traversal-hardened, and range-free
     * (small assets). `?download=1` forces a Save-As instead of inline preview.
     */
    public function view(Request $req, Response $res, array $args): Response
    {
        $id  = (int)($args['id'] ?? 0);
        $row = $id ? DB::table('gates_uploads')->where('id', $id)->first() : null;
        if (!$row) return $res->withStatus(404);

        // Resolve strictly inside the uploads tree (defence-in-depth against a
        // tampered/relative stored path).
        $abs         = $this->publicRoot() . (string)($row->path ?? '');
        $real        = realpath($abs);
        $uploadsRoot = realpath($this->publicRoot() . '/uploads');
        if (!$real || !$uploadsRoot || !str_starts_with($real, $uploadsRoot) || !is_file($real)) {
            return $res->withStatus(404);
        }

        // Trust the stored mime, but re-detect when it is missing/generic so a
        // PDF never streams as octet-stream (which forces a download).
        $mime = (string)($row->mime ?? '');
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = $this->sniffMime($real) ?: 'application/octet-stream';
        }

        $download = ($req->getQueryParams()['download'] ?? '') === '1';
        // PDFs + images preview inline; everything else downloads by default.
        $inlineable = str_starts_with($mime, 'image/') || $mime === 'application/pdf' || str_starts_with($mime, 'text/');
        $disposition = ($download || !$inlineable) ? 'attachment' : 'inline';
        $filename = basename($real);

        $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'media.view', 'upload', $id);

        $stream = fopen($real, 'rb');
        if ($stream === false) return $res->withStatus(500);
        $res = $res->withBody(new \Slim\Psr7\Stream($stream));

        return $res
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string)(filesize($real) ?: 0))
            ->withHeader('Content-Disposition', $disposition . '; filename="' . preg_replace('/["\r\n]/', '', $filename) . '"')
            // Private: these are moderation assets, never CDN/proxy-cacheable.
            ->withHeader('Cache-Control', 'private, max-age=300, must-revalidate')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Minimal magic-byte sniff for the common upload types (no ext/fileinfo dep). */
    private function sniffMime(string $path): ?string
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) return null;
        $head = fread($fh, 16) ?: '';
        fclose($fh);
        if (str_starts_with($head, "\xFF\xD8\xFF"))                 return 'image/jpeg';
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n"))           return 'image/png';
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) return 'image/gif';
        if (str_starts_with($head, 'RIFF') && str_contains($head, 'WEBP')) return 'image/webp';
        if (str_starts_with($head, '%PDF-'))                        return 'application/pdf';
        if (str_starts_with($head, "PK\x03\x04"))                   return 'application/zip'; // docx/xlsx are zip
        return null;
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id   = (int)($args['id'] ?? 0);
        $b    = (array)$req->getParsedBody();
        $type = in_array($b['type'] ?? '', ['image', 'doc'], true) ? $b['type'] : '';
        $row  = $id ? DB::table('gates_uploads')->where('id', $id)->first() : null;

        if ($row) {
            // Delete the physical file — but only if it genuinely resolves inside the
            // uploads tree (defence-in-depth against a tampered/relative path).
            $abs         = $this->publicRoot() . (string)($row->path ?? '');
            $real        = realpath($abs);
            $uploadsRoot = realpath($this->publicRoot() . '/uploads');
            if ($real && $uploadsRoot && str_starts_with($real, $uploadsRoot) && is_file($real)) {
                @unlink($real);
            }
            DB::table('gates_uploads')->where('id', $id)->delete();
            $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'media.delete', 'upload', $id);
            $_SESSION['flash_ok'] = 'Media removed.';
        } else {
            $_SESSION['flash_error'] = 'That media item no longer exists.';
        }

        return $res->withHeader('Location', '/admin/media' . ($type ? '?type=' . $type : ''))->withStatus(302);
    }
}
