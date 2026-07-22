<?php
declare(strict_types=1);
namespace AfricaGates\Support;

/**
 * Cache-busting `?v=` token shared by every CSS/JS/img link in the layout.
 *
 * Production pins ASSET_VERSION (bumped at deploy) so browsers can cache assets
 * far into the future. In debug/dev we instead derive the token from the NEWEST
 * modification time across every css/js file, so editing ANY of them forces a
 * fresh fetch. The previous implementation watched a single sentinel stylesheet
 * (redesign-2026.css), so edits to main.css, tokens, components, ui-overhaul,
 * or the JS bundles silently served stale assets until that one file changed.
 */
final class Assets
{
    /** Extensions whose edits must bust the browser cache in dev. */
    private const CACHE_BUSTING_EXTENSIONS = ['css', 'js'];

    /**
     * Absolute URL for a record's own image, for og:image / twitter:image.
     * Social crawlers require absolute URLs; relative upload paths get the
     * APP_URL prefix. Null in = null out (the layout's branded default wins).
     */
    public static function absoluteOg(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        return $base !== '' ? $base . '/' . ltrim($path, '/') : null;
    }

    /** Every css/js file under $assetsDir (recursive); other types ignored. */
    public static function collect(string $assetsDir): array
    {
        if (!is_dir($assetsDir)) {
            return [];
        }
        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()
                && in_array(strtolower($file->getExtension()), self::CACHE_BUSTING_EXTENSIONS, true)) {
                $found[] = $file->getPathname();
            }
        }
        return $found;
    }

    /** Newest mtime across the given files. Missing files are skipped silently. */
    public static function latestMtime(array $files): int
    {
        $latest = 0;
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $m = @filemtime($file);
            if ($m !== false && $m > $latest) {
                $latest = $m;
            }
        }
        return $latest;
    }

    /**
     * Cache-bust token. Production: the pinned version (or 'v1' if unset). Dev:
     * the newest css/js mtime so any edit busts the cache (or 'dev' if no assets).
     */
    public static function version(bool $debug, ?string $pinnedVersion, string $assetsDir): string
    {
        if (!$debug) {
            $pinned = trim((string) $pinnedVersion);
            return $pinned !== '' ? $pinned : 'v1';
        }
        $mtime = self::latestMtime(self::collect($assetsDir));
        return $mtime > 0 ? (string) $mtime : 'dev';
    }
}
