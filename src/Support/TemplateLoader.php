<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Twig\Loader\FilesystemLoader;

/**
 * The template loader, with a cache key that changes whenever the FILE does.
 *
 * Compiled templates are on in production (config/container.php), and Twig keys its cache
 * by the template's NAME and decides freshness by comparing times: a compiled copy is
 * current while it is newer than its source. Deploys here are a zip extracted onto the
 * host, and extraction restores the time each file had when the zip was BUILT — so a zip
 * built at 10:00, deployed at 11:00 onto a site that compiled the old template at 10:30,
 * leaves the source "older" than its compiled copy, and the old page is served for as long
 * as nobody empties `var/cache/twig`, on a host with no shell to do it from. The page
 * simply does not change, which reads as the deploy having failed.
 *
 * So the key carries the file's size and modification time as well as its name: ANY change
 * to either — earlier or later — names a different compiled class, and it is compiled fresh.
 * It costs the same `stat()` that `auto_reload` already makes, which PHP's stat cache
 * shares. A superseded compiled file is never read again; pruneCompiled() (the maintenance
 * cache task) removes any not written in a month. Removing a CURRENT one costs nothing but
 * a recompile on its next use, so the age is the only test it needs.
 */
final class TemplateLoader extends FilesystemLoader
{
    public function getCacheKey(string $name): string
    {
        $key = parent::getCacheKey($name);
        $path = $this->findTemplate($name, false);
        if (!is_string($path) || $path === '') return $key;
        $st = @stat($path);
        return $st === false ? $key : $key . '#' . $st['size'] . '.' . $st['mtime'];
    }

    public const PRUNE_AFTER_DAYS = 30;

    /** Delete compiled templates not written in PRUNE_AFTER_DAYS. @return int files removed */
    public static function pruneCompiled(string $dir, ?int $now = null): int
    {
        if (!is_dir($dir)) return 0;
        $cut = ($now ?? time()) - self::PRUNE_AFTER_DAYS * 86400;
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && $f->getMTime() < $cut && @unlink($f->getPathname())) $n++;
        }
        return $n;
    }
}
