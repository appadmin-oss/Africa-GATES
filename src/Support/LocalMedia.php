<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The file on this server behind a URL we serve.
 *
 * ── THE GAP THIS CLOSES ──────────────────────────────────────────────────────
 *
 * {@see \AfricaGates\Admin\Services\UploadService} writes every upload to disk FIRST and
 * only then hands it to Cloudinary, and when Cloudinary is configured the URL it stores is
 * the remote one. Everything that renders in a browser is happy with that. Everything that
 * needs the BYTES is not, and the two are not the same set:
 *
 *   • {@see \AfricaGates\Services\TicketPdf} embeds the event's artwork in a PDF. It refuses
 *     to fetch a remote URL — correctly, because that would put a third party's server in
 *     the path of a ticket download and a slow host would hang the request — so on any
 *     deployment with Cloudinary switched on, every ticket printed with no photograph at
 *     all. Nothing was broken and nothing was logged; the picture was just never there.
 *
 * The local file has been sitting on disk the whole time. `gates_uploads.local_path` records
 * exactly where, for precisely this reason, and nothing was reading it.
 *
 * ── WHY IT IS A LOOKUP AND NOT A STRING TRANSFORM ────────────────────────────
 *
 * A Cloudinary URL carries a public id derived from the local path, so it is TEMPTING to
 * reverse it. It is not reversible in practice: the transform is lossy on the directory
 * separators, Cloudinary rewrites the extension when it re-encodes, and an operator who
 * changes the cloud name or folder breaks a rule nobody knew existed. The table already
 * holds the answer, and one indexed read is cheaper than being wrong on a document
 * somebody prints.
 */
final class LocalMedia
{
    /**
     * The on-disk relative path (`uploads/…`) behind a stored URL, or ''.
     *
     * A LOCAL url is returned as itself, trimmed of its leading slash — the caller is asking
     * "where are the bytes", and for a same-site path the answer is the path.
     *
     * Never throws and never returns a path that escaped the uploads tree: this feeds
     * file_get_contents(), so a value that walked up out of `public/` would be a file
     * disclosure with an image decoder in front of it.
     */
    public static function path(string $url): string
    {
        $u = trim($url);
        if ($u === '') return '';

        if (preg_match('#^https?://#i', $u) === 1) {
            $u = self::lookup($u);
            if ($u === '') return '';
        }

        // Protocol-relative is off-site, and a lookup on it would not have matched anyway.
        if (str_starts_with($u, '//')) return '';

        $rel = ltrim($u, '/');
        // `..` anywhere, not just at the head: `uploads/../../config/.env` is the same attack
        // written differently, and the stored value can come from a hand-edited row.
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) return '';
        return $rel;
    }

    /**
     * The absolute path, but only if the file is actually there and inside `public/`.
     *
     * `realpath()` and a prefix check rather than trusting {@see path()} alone: a symlink
     * inside the uploads tree is a legitimate thing for an operator to create and an
     * illegitimate way out of it, and only the resolved path can tell the difference.
     */
    public static function file(string $url, ?string $publicRoot = null): string
    {
        $rel = self::path($url);
        if ($rel === '') return '';

        $root = rtrim($publicRoot ?? (dirname(__DIR__, 2) . '/public'), '/');
        $abs  = realpath($root . '/' . $rel);
        $base = realpath($root);
        if ($abs === false || $base === false) return '';
        if (!str_starts_with($abs, $base . DIRECTORY_SEPARATOR)) return '';
        return is_file($abs) && is_readable($abs) ? $abs : '';
    }

    /** What `gates_uploads` remembers about a delivered URL. '' when it has never seen it. */
    private static function lookup(string $url): string
    {
        try {
            if (!DB::schema()->hasColumn('gates_uploads', 'local_path')) return '';
            $row = DB::table('gates_uploads')
                ->where('path', $url)
                ->whereNotNull('local_path')
                ->orderByDesc('id')
                ->value('local_path');
            return trim((string) $row);
        } catch (\Throwable) {
            // A missing table, a pre-migration schema, no database at all. The caller's
            // fallback is "no picture", which is a ticket; an exception here is not.
            return '';
        }
    }
}
