<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Opening a document somebody uploaded, from inside the admin.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A ROUTE AND NOT A LINK TO THE FILE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The vendor screen linked straight at `/uploads/org-docs/…`, and a vendor's CAC or SCUML
 * certificate is a PDF. `public/uploads/.htaccess` — correctly — serves everything in that
 * tree under `Content-Security-Policy: default-src 'none'; img-src 'self' data:; sandbox`
 * and `X-Frame-Options: DENY`.
 *
 * An image survives that: `img-src 'self'` permits it. A PDF does not — the browser's
 * viewer is blocked by the sandbox and `default-src 'none'`, so the tab opens empty, or
 * downloads, or shows nothing at all depending on the browser. Which is exactly the
 * reported "the non-image files are not viewable", and it looked like a broken upload when
 * the bytes were fine the whole time.
 *
 * The right fix is not to weaken that policy. It exists because those bytes are untrusted
 * and it is the last line if the upload filter is ever wrong. The fix is to stop serving
 * documents from there directly: this route reads the file with PHP and sends its own
 * headers, so the sandbox that protects a direct hit does not apply to a deliberate,
 * authenticated, audited open.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * SCOPED BY WHAT THE ROW IS, NOT BY A PATH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The id names a ROW in a known table, and the path is read from that row. A route that
 * took a path — even a validated one — is a file-read primitive with an admin session in
 * front of it, and the next person to add a caller would eventually pass it something from
 * a query string.
 */
final class DocumentsController
{
    /**
     * Where a document may come from.
     *
     * A closed list. Each entry names the table, the column holding the stored path, and
     * the column holding a human name for the download.
     *
     * @var array<string, array{table:string, path:string, name:string, subject:string}>
     */
    private const SCOPES = [
        'org-doc' => [
            'table'   => 'gates_org_documents',
            'path'    => 'stored_path',
            'name'    => 'original_name',
            'subject' => 'org_document',
        ],
        'evidence' => [
            'table'   => 'gates_nominee_evidence',
            'path'    => 'source_url',
            'name'    => 'title',
            'subject' => 'nominee_evidence',
        ],
    ];

    /**
     * Types that may render in the browser.
     *
     * Anything else downloads. An inline render of an unexpected type is how an upload
     * becomes a script in the reader's origin — the same rule the judge's evidence streamer
     * applies, and it must not diverge from it.
     */
    private const INLINE = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'text/plain', 'text/csv',
    ];

    public function __construct(private readonly ?AuditService $audit = null) {}

    public function view(Request $req, Response $res, array $args = []): Response
    {
        $scope = (string) ($args['scope'] ?? '');
        $id    = (int) ($args['id'] ?? 0);

        if (!isset(self::SCOPES[$scope]) || $id < 1) return $res->withStatus(404);
        $spec = self::SCOPES[$scope];

        try {
            $row = DB::table($spec['table'])->where('id', $id)->first();
        } catch (\Throwable) {
            $row = null;
        }
        if (!$row) return $res->withStatus(404);

        $stored = trim((string) ($row->{$spec['path']} ?? ''));
        if ($stored === '') return $res->withStatus(404);

        // An absolute URL was never ours to stream. Redirect, so a Cloudinary-hosted item
        // still opens rather than 404ing.
        if (preg_match('~^https?://~i', $stored)) {
            return $res->withHeader('Location', $stored)->withStatus(302);
        }

        // Resolved strictly inside the uploads tree. realpath FIRST and then the prefix
        // check, so a stored value containing `..` cannot escape — it came from an upload
        // and is still data, and this is the point where it becomes a filesystem read.
        $publicRoot  = dirname(__DIR__, 3) . '/public';
        $real        = realpath($publicRoot . '/' . ltrim($stored, '/'));
        $uploadsRoot = realpath($publicRoot . '/uploads');

        if (!$real || !$uploadsRoot
            || !str_starts_with($real, $uploadsRoot . DIRECTORY_SEPARATOR)
            || !is_file($real)) {
            return $res->withStatus(404);
        }

        $mime   = self::sniff($real);
        $inline = in_array($mime, self::INLINE, true)
               && ($req->getQueryParams()['download'] ?? '') !== '1';

        $stream = fopen($real, 'rb');
        if ($stream === false) return $res->withStatus(500);

        // Recorded. Somebody's registration certificate being opened is a thing that
        // happened to a real business, and a vetting decision that is later disputed is
        // easier to defend when it is known who looked at what.
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0),
                              'document.view', $spec['subject'], $id);

        return $res
            ->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->withHeader('Content-Length', (string) filesize($real))
            ->withHeader('Content-Disposition',
                ($inline ? 'inline' : 'attachment')
                . '; filename="' . self::safeName((string) ($row->{$spec['name']} ?? ''), $real) . '"')
            // Private, and never in a shared cache: this is somebody's paperwork.
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Narrow, but NOT the uploads-directory policy. `object-src 'self'` is what
            // lets the browser's PDF viewer run, which is the entire point of this route
            // existing; everything else is still denied.
            ->withHeader('Content-Security-Policy',
                "default-src 'none'; img-src 'self' data:; object-src 'self'; "
                . "plugin-types application/pdf; sandbox");
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
     * A filename the reader recognises, with nothing in it that can break the header.
     *
     * The name is user-supplied, so quotes, newlines and semicolons all go before it lands
     * in a Content-Disposition.
     */
    private static function safeName(string $given, string $real): string
    {
        $base = trim($given) !== '' ? trim($given) : pathinfo($real, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9 ._-]+/', '', $base) ?? '';
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? '') ?: 'document';

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if ($ext !== '' && !str_ends_with(strtolower($base), '.' . $ext)) {
            $base .= '.' . preg_replace('/[^a-z0-9]+/', '', $ext);
        }

        return mb_substr($base, 0, 120);
    }
}
