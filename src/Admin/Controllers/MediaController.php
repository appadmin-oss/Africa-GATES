<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\CloudinaryService;
use AfricaGates\Services\MediaMigrationService;

/**
 * Media library — lets admins review and remove everything in gates_uploads
 * (nominee photos, nomination evidence, profile/judge/legacy images, …) with
 * previews, provenance and a one-click delete that also removes the file.
 *
 * ── AND IT IS WHERE THE CLOUDINARY SWEEP IS DRIVEN FROM ──────────────────────
 *
 * `bin/console media:cloudinary` is the better interface, and it is unavailable on the
 * host this platform actually runs on: shared cPanel, no SSH. That is not a hypothetical
 * — it is why `GET /__setup/migrate` exists for schema migrations. A bulk media
 * migration that can only be started from a shell is a feature this operator does not
 * have, so {@see migrate()} runs the same service in bounded batches from a button, and
 * the page continues itself until nothing is pending.
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
            'cloudinary' => $this->cloudinaryPanel($req),
        ]);
    }

    /**
     * State for the Cloudinary panel: configured or not, what is pending, and whether a
     * sweep is mid-flight (`?migrate=running`, which makes the page continue itself).
     */
    private function cloudinaryPanel(Request $req): array
    {
        $q = $req->getQueryParams();
        try {
            $status = (new MediaMigrationService())->status();
        } catch (\Throwable) {
            $status = ['configured' => CloudinaryService::enabled(), 'total' => 0, 'by_target' => [], 'migrated' => 0, 'missing' => 0, 'failed' => 0];
        }
        return $status + [
            'cloud'   => CloudinaryService::cloudName(),
            'folder'  => CloudinaryService::rootFolder(),
            'running' => ($q['migrate'] ?? '') === 'running',
            'dry_run' => ($q['migrate'] ?? '') === 'preview',
            'report'  => $this->takeMigrateReport(),
            // Drives whether the buttons render at all. The POST enforces this too —
            // hiding a control is presentation, not authorisation.
            'may_run' => $this->mayMigrate(),
        ];
    }

    /** Roles that may spend money on the operator's CDN account. */
    private function mayMigrate(): bool
    {
        return in_array((string) ($_SESSION['admin_role'] ?? ''), ['superadmin', 'admin'], true);
    }

    /** Read-and-clear the last batch's log lines, so they describe one batch only. */
    private function takeMigrateReport(): array
    {
        $r = $_SESSION['media_migrate_report'] ?? null;
        unset($_SESSION['media_migrate_report']);
        return is_array($r) ? array_values(array_map('strval', $r)) : [];
    }

    /**
     * POST /admin/media/cloudinary — run ONE batch, then bounce back to the page.
     *
     * One batch per request, deliberately. A loop-until-done here would be a request
     * that runs for minutes on a host whose `max_execution_time` an operator cannot
     * raise, and PHP's response to that is a blank page with the work half-finished and
     * no report. Bounded batches plus a self-continuing page get the same result and
     * survive being killed at any point, because the service commits per row and the
     * ledger makes a re-run a no-op for what is already done.
     */
    public function migrate(Request $req, Response $res): Response
    {
        // SectionGuardMiddleware maps /admin/media to the `content` section, which an
        // EDITOR may reach — correct for reviewing and deleting media, wrong for this.
        // A sweep uploads the platform's entire image estate to a paid third-party
        // account: it is an infrastructure decision with a bill attached, not an
        // editorial one, and the section guard cannot express "this one button is
        // narrower than its page".
        if (!$this->mayMigrate()) {
            $_SESSION['flash_error'] = 'Only an administrator can run the Cloudinary migration.';
            return $res->withHeader('Location', '/admin/media')->withStatus(302);
        }

        $b      = (array) $req->getParsedBody();
        $dryRun = !empty($b['preview']);
        $svc    = new MediaMigrationService();

        try {
            $r = $svc->run($dryRun, MediaMigrationService::BATCH);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Media migration failed: ' . $e->getMessage();
            return $res->withHeader('Location', '/admin/media')->withStatus(302);
        }

        // The report is a flash, so it describes the batch just run rather than
        // accumulating across a sweep the operator has stopped reading.
        $_SESSION['media_migrate_report'] = array_slice($r['lines'], -40);

        if (!$r['ok']) {
            $_SESSION['flash_error'] = implode(' ', $r['lines']);
            return $res->withHeader('Location', '/admin/media')->withStatus(302);
        }

        $this->audit->record((int)($_SESSION['admin_id'] ?? 0), $dryRun ? 'media.cloudinary_preview' : 'media.cloudinary_migrate', 'upload', null);

        // `running` only while there is genuinely more to do AND this batch moved
        // something. Without the second condition a batch of nothing but
        // missing-on-disk rows would re-arm the auto-continue forever.
        $more = !$dryRun && $r['pending'] > 0 && (int) $r['migrated'] > 0;
        if (!$more) {
            $_SESSION['flash_ok'] = $dryRun
                ? 'Preview complete — nothing was uploaded or changed.'
                : 'Media migration batch complete. ' . $r['pending'] . ' row(s) still reference local files.';
        }

        return $res->withHeader('Location', '/admin/media?migrate=' . ($more ? 'running' : ($dryRun ? 'preview' : 'done')))->withStatus(302);
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

        // A CDN-hosted asset is not on this filesystem, so there is nothing to stream:
        // the preview redirects to the delivery URL. Without this the console's preview
        // button 404s for every image uploaded after Cloudinary was switched on — the
        // stream path resolves the stored value against public/ and an https URL cannot
        // resolve there.
        $stored = (string)($row->path ?? '');
        if (CloudinaryService::isRemote($stored)) {
            $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'media.view', 'upload', $id);
            return $res->withHeader('Location', $stored)->withStatus(302);
        }

        // Resolve strictly inside the uploads tree (defence-in-depth against a
        // tampered/relative stored path).
        $abs         = $this->publicRoot() . $stored;
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
            // BOTH copies, not whichever one the path happens to name. After a
            // Cloudinary migration a row's `path` is the CDN URL while the original is
            // still on disk under `local_path`, so deleting only the one the path points
            // at leaves the other behind — and on the CDN side that is an asset still
            // publicly reachable and still billed after an admin was told it was removed.
            $publicId = (string)($row->public_id ?? '') ?: (string)(CloudinaryService::publicIdFromUrl((string)($row->path ?? '')) ?? '');
            if ($publicId !== '') {
                (new CloudinaryService())->destroy($publicId);
            }

            // Delete the physical file — but only if it genuinely resolves inside the
            // uploads tree (defence-in-depth against a tampered/relative path).
            foreach ([(string)($row->path ?? ''), (string)($row->local_path ?? '')] as $candidate) {
                if ($candidate === '' || CloudinaryService::isRemote($candidate)) continue;
                $real        = realpath($this->publicRoot() . $candidate);
                $uploadsRoot = realpath($this->publicRoot() . '/uploads');
                if ($real && $uploadsRoot && str_starts_with($real, $uploadsRoot) && is_file($real)) {
                    @unlink($real);
                }
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
