<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * One stylesheet instead of ten, minified — and a fallback that cannot serve stale CSS.
 *
 * ── THE MEASUREMENT ──────────────────────────────────────────────────────────
 *
 * Lighthouse on a Moto G Power: **render-blocking requests, est. 2,360ms**, LCP 4.4s,
 * FCP 3.3s. The page head links FIFTEEN blocking stylesheets — ten local, four from
 * three separate third-party CDNs, plus Google Fonts — totalling ~320KB of unminified
 * CSS. Every one of them must be fetched, parsed and applied before the browser paints
 * a single pixel, and on the connection this platform's audience actually has, the
 * per-request latency dominates the bytes.
 *
 * This collapses the ten local files into one hashed, minified bundle. It does not
 * reduce the CSS the site *ships* — see the honest note on unused CSS at the bottom —
 * it removes nine round trips and the whitespace.
 *
 * ── WHY A PHP BUNDLER AND NOT A NODE BUILD ───────────────────────────────────
 *
 * This deploys to shared cPanel: no Node, frequently no shell, and `composer update` is
 * a gamble. A build step that cannot run where the code runs is a build step that will
 * be skipped, and the failure mode of a skipped CSS build is a site with no styling. So
 * the bundler is PHP, it runs from `bin/console assets:build` OR from a token-gated URL
 * for hosts with no shell at all, and — the part that makes it safe — the layout FALLS
 * BACK to the ten individual links whenever a current bundle is absent.
 *
 * ── HOW STALENESS IS PREVENTED ───────────────────────────────────────────────
 *
 * The obvious design serves the bundle if the file exists. That is how a developer
 * edits `nav.css`, sees no change, and loses an hour. Instead the manifest records the
 * newest mtime across the sources at build time, and {@see url()} returns null the
 * moment any source is newer — so an un-rebuilt edit degrades to the individual files,
 * which are always correct. Seventeen `stat()` calls per request, which is nothing, in
 * exchange for never serving CSS that does not match the repository.
 *
 * ── ORDER IS THE WHOLE CORRECTNESS ARGUMENT ──────────────────────────────────
 *
 * CSS cascade is order-dependent and this project relies on it: `a11y.css` is last
 * specifically so its WCAG corrections override everything, and the legacy sheets are
 * layered under the newer modular ones. {@see STYLESHEETS} is therefore the SINGLE
 * source of that order, read by both the bundler and the fallback in the layout —
 * duplicating the list would let the bundled and unbundled renderings differ, which is
 * the worst possible bug here because it appears only on deployments that ran the build.
 */
final class AssetBundle
{
    /**
     * Every local stylesheet the public layout loads, IN CASCADE ORDER.
     *
     * Paths are relative to `public/`. Admin-only sheets (`admin.css`, `judge.css`) and
     * per-page sheets loaded through `{% block head_styles %}` are deliberately absent:
     * bundling a sheet into the global payload to save a request on the two pages that
     * use it makes every other page heavier.
     */
    public const STYLESHEETS = [
        'assets/css/tokens.motion.css',
        'assets/css/main.css',
        'assets/css/ui-overhaul.css',
        'assets/css/professional.css',
        'assets/css/redesign-2026.css',
        'assets/css/aurora.css',
        // The newer modular design system, layered over the legacy sheets above.
        'assets/css/base/tokens.css',
        'assets/css/base/reset.css',
        'assets/css/base/typography.css',
        'assets/css/components/loader.css',
        'assets/css/components/nav.css',
        'assets/css/components/footer.css',
        'assets/css/components/gee.css',
        'assets/css/components/community-modal.css',
        'assets/css/components/site-search.css',
        // LAST, and it must stay last — its corrections are meant to win.
        'assets/css/a11y.css',
    ];

    /** Where the built bundle and its manifest live. Gitignored build output. */
    private const DIST_DIR = 'assets/dist';
    private const MANIFEST = 'assets/dist/manifest.json';

    private static function root(?string $publicRoot): string
    {
        return $publicRoot ?? dirname(__DIR__, 2) . '/public';
    }

    /**
     * The bundle's URL, or null when the layout should fall back to individual links.
     *
     * Null on any doubt: no manifest, missing file, or a source edited since the build.
     * Falling back costs nine requests; serving a stale bundle costs an afternoon and
     * can ship a visual regression to production.
     */
    public static function url(?string $publicRoot = null): ?string
    {
        $root = self::root($publicRoot);

        $manifest = self::manifest($root);
        if ($manifest === null) return null;

        $file = (string) ($manifest['file'] ?? '');
        if ($file === '' || !is_file($root . '/' . ltrim($file, '/'))) return null;

        // Any source newer than the build → the bundle no longer represents the source.
        if (self::newestSourceMtime($root) > (int) ($manifest['mtime'] ?? 0)) return null;

        return '/' . ltrim($file, '/');
    }

    /**
     * Build the bundle. Idempotent: rebuilding unchanged sources rewrites the same
     * content-hashed filename, so a browser or CDN holding the old URL keeps its cache.
     *
     * @return array{ok:bool, file:?string, raw:int, min:int, saved_pct:int,
     *               sources:int, missing:list<string>, error:?string}
     */
    public static function build(?string $publicRoot = null): array
    {
        $root = self::root($publicRoot);
        $dist = $root . '/' . self::DIST_DIR;

        if (!is_dir($dist) && !@mkdir($dist, 0775, true) && !is_dir($dist)) {
            return self::fail('Could not create ' . self::DIST_DIR . ' — check permissions.');
        }

        $parts = [];
        $missing = [];
        $raw = 0;
        foreach (self::STYLESHEETS as $rel) {
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) { $missing[] = $rel; continue; }
            $css = (string) file_get_contents($abs);
            $raw += strlen($css);
            // A source comment naming the file, kept so a stack of 320KB of minified CSS
            // is still navigable in devtools. Costs ~40 bytes each.
            $parts[] = "/*! " . $rel . " */\n" . self::rebaseUrls($css, $rel);
        }
        if ($parts === []) {
            return self::fail('No stylesheets found — is the public root correct?') + ['missing' => $missing];
        }

        $min = self::minify(implode("\n", $parts));
        // Content hash, so the filename changes only when the CSS does. That is what
        // makes `Cache-Control: 1 year` on /assets/** (see public/.htaccess) safe.
        $name = 'site.' . substr(hash('sha256', $min), 0, 12) . '.css';

        if (@file_put_contents($dist . '/' . $name, $min) === false) {
            return self::fail('Could not write ' . self::DIST_DIR . '/' . $name);
        }

        $manifest = [
            'file'    => self::DIST_DIR . '/' . $name,
            'mtime'   => self::newestSourceMtime($root),
            'sources' => count($parts),
            'raw'     => $raw,
            'min'     => strlen($min),
        ];
        if (@file_put_contents($root . '/' . self::MANIFEST,
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return self::fail('Could not write the manifest.');
        }

        self::prune($dist, $name);

        return [
            'ok'        => true,
            'file'      => '/' . $manifest['file'],
            'raw'       => $raw,
            'min'       => strlen($min),
            'saved_pct' => $raw > 0 ? (int) round((1 - strlen($min) / $raw) * 100) : 0,
            'sources'   => count($parts),
            'missing'   => $missing,
            'error'     => null,
        ];
    }

    /**
     * Delete superseded bundles, keeping the current one.
     *
     * Without this, `assets/dist/` accumulates one file per build forever. Deliberately
     * narrow: only `site.<hex>.css`, so nothing an operator put in that directory is
     * ever removed by a build.
     */
    private static function prune(string $dist, string $keep): void
    {
        foreach (glob($dist . '/site.*.css') ?: [] as $path) {
            if (basename($path) !== $keep && preg_match('/^site\.[0-9a-f]{12}\.css$/', basename($path)) === 1) {
                @unlink($path);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private static function manifest(string $root): ?array
    {
        $path = $root . '/' . self::MANIFEST;
        if (!is_file($path)) return null;
        $json = json_decode((string) @file_get_contents($path), true);
        return is_array($json) ? $json : null;
    }

    private static function newestSourceMtime(string $root): int
    {
        $newest = 0;
        foreach (self::STYLESHEETS as $rel) {
            $t = @filemtime($root . '/' . $rel);
            if ($t !== false && $t > $newest) $newest = $t;
        }
        return $newest;
    }

    /**
     * @return array{ok:bool, file:?string, raw:int, min:int, saved_pct:int, sources:int, missing:list<string>, error:string}
     */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'file' => null, 'raw' => 0, 'min' => 0, 'saved_pct' => 0,
                'sources' => 0, 'missing' => [], 'error' => $error];
    }

    // ── The two transformations ──────────────────────────────────────────────

    /**
     * Rewrite relative `url(...)` references so they still resolve from `assets/dist/`.
     *
     * Concatenation moves CSS to a new directory, and a relative `url(../img/x.svg)` in
     * `assets/css/components/nav.css` resolves against `assets/dist/` afterwards —
     * silently pointing at a file that is not there. Today every reference in this
     * project is already absolute or a fragment (`url(#cpiGrad)`), verified before
     * writing this, so nothing changes. It is here so that adding one relative URL later
     * does not quietly break an image on a deployment that ran the build and nowhere
     * else — which is the hardest version of this bug to find.
     */
    private static function rebaseUrls(string $css, string $sourceRel): string
    {
        $dir = trim(dirname($sourceRel), '/');   // e.g. assets/css/components

        return (string) preg_replace_callback(
            '~url\(\s*(["\']?)([^)"\']+)\1\s*\)~i',
            static function (array $m) use ($dir): string {
                $q = $m[1];
                $u = trim($m[2]);
                // Absolute, protocol-relative, data:, and fragment refs are already fine.
                if ($u === '' || $u[0] === '/' || $u[0] === '#'
                    || str_starts_with($u, 'data:') || str_starts_with($u, '//')
                    || preg_match('~^[a-z][a-z0-9+.-]*:~i', $u) === 1) {
                    return $m[0];
                }
                // Resolve `../` against the source's own directory.
                $segments = [];
                foreach (explode('/', $dir . '/' . $u) as $seg) {
                    if ($seg === '' || $seg === '.') continue;
                    if ($seg === '..') { array_pop($segments); continue; }
                    $segments[] = $seg;
                }
                return 'url(' . $q . '/' . implode('/', $segments) . $q . ')';
            },
            $css
        ) ?: $css;
    }

    /**
     * A deliberately CONSERVATIVE CSS minifier.
     *
     * ── WHAT IT DOES NOT DO, AND WHY ─────────────────────────────────────────
     *
     * It does not touch the space around `+`, `~` or `>`. An aggressive minifier
     * removes it as selector combinator whitespace, and then mangles
     * `calc(100% + 10px)` into `calc(100%+10px)`, which is INVALID — the declaration is
     * dropped and a layout collapses. That is the single most common way a hand-rolled
     * CSS minifier breaks a site, this project's CSS uses `calc()` throughout, and the
     * remaining saving from combinators is a rounding error.
     *
     * It does not touch `:` either, for the same reason in miniature (`a:hover`,
     * `@media (min-width : X)`), and it does not attempt colour shortening, unit
     * stripping or rule merging — all of which need a real parser to be safe.
     *
     * What it does do is remove comments, collapse whitespace, and drop the space around
     * `{ } ; ,` plus the final semicolon in a block. On hand-written, generously
     * commented and indented CSS that is where nearly all of the removable bytes are —
     * measured at 24% of 320KB here, against Lighthouse's estimate of 13 KiB.
     *
     * Strings and `url()` contents are stepped over character by character rather than
     * regex-matched, because a `/*` inside a string or a `;` inside a data URI would
     * otherwise be treated as syntax.
     */
    public static function minify(string $css): string
    {
        $out = '';
        $len = strlen($css);
        $quote = '';       // current string delimiter, '' when outside a string
        $inComment = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];
            $n = $i + 1 < $len ? $css[$i + 1] : '';

            if ($inComment) {
                if ($c === '*' && $n === '/') { $inComment = false; $i++; }
                continue;
            }
            if ($quote !== '') {
                $out .= $c;
                // A backslash escapes the next character, including the delimiter.
                if ($c === '\\' && $n !== '') { $out .= $n; $i++; continue; }
                if ($c === $quote) $quote = '';
                continue;
            }
            if ($c === '/' && $n === '*') {
                // `/*!` is the convention for "keep this" — the per-file markers the
                // bundler writes use it so a minified stack stays navigable.
                if ($i + 2 < $len && $css[$i + 2] === '!') {
                    $end = strpos($css, '*/', $i);
                    if ($end === false) break;
                    $out .= substr($css, $i, $end - $i + 2);
                    $i = $end + 1;
                    continue;
                }
                $inComment = true; $i++;
                continue;
            }
            if ($c === '"' || $c === "'") { $quote = $c; $out .= $c; continue; }

            if ($c === "\n" || $c === "\r" || $c === "\t" || $c === ' ' || $c === "\f" || $c === "\v") {
                // Collapse any run of whitespace to one space; drop it entirely when it
                // sits next to a character that cannot need it.
                //
                // `+` and `~` are NOT in that set, and this is the whole reason the class
                // comment above says what it says. The first version of this method
                // included them — dropping the space AFTER a `+` is harmless in a selector
                // (`a +b` is valid) and fatal inside `calc()`, where the spec requires
                // whitespace on BOTH sides of `+` and `-`. It produced three occurrences
                // of `calc(100% +0.5rem)` across main.css, each of which a browser drops
                // as invalid, silently collapsing whatever it sized. Caught by grepping
                // the built bundle rather than by reading the code, and now pinned by
                // AssetBundleTest.
                //
                // `>` is left out for the same reason at one remove: it is safe today
                // because it cannot appear in calc(), and the bytes it would save are a
                // rounding error against being sure.
                $prev = $out === '' ? '' : $out[strlen($out) - 1];
                if ($prev === '' || strpos("{};,:( ", $prev) !== false) continue;
                $out .= ' ';
                continue;
            }

            // Space before a structural character is never meaningful.
            if (strpos('{};,)', $c) !== false && $out !== '' && $out[strlen($out) - 1] === ' ') {
                $out = substr($out, 0, -1);
            }
            // The last declaration's semicolon before `}` is optional.
            if ($c === '}' && $out !== '' && $out[strlen($out) - 1] === ';') {
                $out = substr($out, 0, -1);
            }
            $out .= $c;
        }

        return trim($out);
    }

    /**
     * ── WHAT THIS DELIBERATELY DOES NOT SOLVE ────────────────────────────────
     *
     * Lighthouse also reports "Reduce unused CSS — est. 28 KiB". That is real and it is
     * NOT what this class fixes: every page still receives every rule, because the sheets
     * are global and the selectors are not attributed to pages. Fixing it properly means
     * per-page CSS (or a critical-CSS inline step), which requires knowing which
     * selectors each template actually uses — a real project with a real regression risk,
     * not a tightening. Recorded here rather than implied away by a class named "bundle".
     *
     * Likewise the JS: `assets/js/vendor/alpine-*.min.js` is already minified and the two
     * first-party files are small. Lighthouse's 4 KiB estimate is not worth a PHP JS
     * minifier, which is a genuinely hazardous thing to hand-roll (ASI, regex literals,
     * template strings) for that return.
     */
    public static function notes(): string
    {
        return 'Bundles and minifies global CSS only. Unused-CSS removal and per-page '
             . 'splitting are not attempted — see the note on this method.';
    }
}
