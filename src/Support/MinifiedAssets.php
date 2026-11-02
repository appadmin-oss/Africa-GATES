<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * A minified TWIN of each stylesheet and script, named by the content hash of the file it was
 * made from (owner, 2 Nov 2026: "optimize all assets").
 *
 * The sources stay exactly as they are — commented, readable, the files people edit — and
 * `Assets::url()` serves `/assets/min/<js|css>/<path>.<hash>.<ext>` in their place when a twin
 * exists for the source's CURRENT hash. Measured on this tree: every page stylesheet together
 * is 188K gzipped as written and 118K minified; every script 246K and 140K.
 *
 * ── WHY A HASH IN THE NAME, AND NO MANIFEST ──────────────────────────────────
 *
 * A twin can never be stale, by construction. Edit a source on the server and its hash
 * changes; no twin has that hash; the source itself is served until a twin is made for it.
 * Slower, never wrong — the same direction AssetBundle falls in. And the hash is the one
 * `Assets::url()` already computes for its cache token, so looking a twin up costs one stat.
 *
 * ── WHO MAKES THEM ───────────────────────────────────────────────────────────
 *
 * CSS: this class, with AssetBundle's conservative minifier — on the server too, by the
 * maintenance run, so an edited stylesheet is minified again within the hour.
 * JS: esbuild, which a shared host does not have — so `php scripts/build-min.php` makes
 * them where Node exists and they are committed. A script edited since serves as written.
 *
 * Never the vendored files (they are someone else's, and some are already minified), and
 * never under the test harness unless a test asks: rendered pages in tests name sources.
 */
final class MinifiedAssets
{
    public const DIR = 'assets/min';
    private const SKIP = '~/(vendor|min|dist)/~';

    private static ?bool $on = null;

    /** Tests turn it on to see it; it is on everywhere else. */
    public static function enable(?bool $on): void { self::$on = $on; }

    public static function enabled(): bool
    {
        return self::$on ?? !defined('PHPUNIT_COMPOSER_INSTALL');
    }

    /** The twin's URL path for `$urlPath` at `$hash`, or null when there is none. */
    public static function twin(string $urlPath, string $hash, string $publicDir): ?string
    {
        if (!self::enabled() || !preg_match('~^/assets/(js|css)/(.+)\.(js|css)$~', $urlPath, $m) || $m[1] !== $m[3]) return null;
        if (preg_match(self::SKIP, '/' . $m[2] . '/') === 1) return null;
        $rel = '/' . self::DIR . '/' . $m[1] . '/' . $m[2] . '.' . $hash . '.' . $m[3];
        return is_file($publicDir . $rel) ? $rel : null;
    }

    /**
     * Every source under assets/<kind>/, as [urlPath, absPath].
     * @return list<array{0:string,1:string}>
     */
    public static function sources(string $publicDir, string $kind): array
    {
        $base = $publicDir . '/assets/' . $kind;
        if (!is_dir($base)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== $kind) continue;
            $sub = substr($f->getPathname(), strlen($base));          // e.g. /components/ads.css
            if (preg_match(self::SKIP, $sub) === 1) continue;
            $url = '/assets/' . $kind . $sub;
            $out[] = [$url, $f->getPathname()];
        }
        sort($out);
        return $out;
    }

    /**
     * Make every missing twin of one kind, and delete twins no source has any more.
     *
     * @param callable(string $source, string $urlPath): ?string $minify bytes in, bytes out (null = skip)
     * @return array{made:int, kept:int, removed:int, failed:list<string>}
     */
    public static function build(string $publicDir, string $kind, callable $minify): array
    {
        $made = $kept = $removed = 0;
        $failed = [];
        $want = [];
        foreach (self::sources($publicDir, $kind) as [$url, $abs]) {
            $hash = Assets::contentHash($url);
            $rel = self::DIR . '/' . $kind . substr($url, strlen('/assets/' . $kind), -strlen($kind) - 1) . '.' . $hash . '.' . $kind;
            $want[$rel] = true;
            $dest = $publicDir . '/' . $rel;
            if (is_file($dest)) { $kept++; continue; }
            $src = (string) @file_get_contents($abs);
            $min = $minify($src, $url);
            // A twin larger than its source helps nobody, and an empty one breaks the page.
            if ($min === null || trim($min) === '' || strlen($min) >= strlen($src)) { $failed[] = $url; continue; }
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true) && !is_dir(dirname($dest))) { $failed[] = $url; continue; }
            if (@file_put_contents($dest . '.tmp', $min) === false || !@rename($dest . '.tmp', $dest)) { $failed[] = $url; continue; }
            $made++;
        }
        $root = $publicDir . '/' . self::DIR . '/' . $kind;
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = self::DIR . '/' . $kind . substr($f->getPathname(), strlen($root));
                if ($f->isFile() && !isset($want[$rel]) && preg_match('~\.[0-9a-f]{8,16}\.' . $kind . '$~', $rel) === 1 && @unlink($f->getPathname())) $removed++;
            }
        }
        return ['made' => $made, 'kept' => $kept, 'removed' => $removed, 'failed' => $failed];
    }

    /** The CSS twins, with relative url()s made absolute: a twin does not live where its source does. */
    public static function buildCss(string $publicDir): array
    {
        return self::build($publicDir, 'css', static fn (string $css, string $url): string =>
            AssetBundle::minify(AssetBundle::rebaseUrls($css, ltrim($url, '/'))));
    }
}
