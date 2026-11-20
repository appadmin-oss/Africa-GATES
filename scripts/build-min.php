<?php
declare(strict_types=1);

/**
 * Make the minified twins of every stylesheet and script (Support\MinifiedAssets).
 *
 *   php scripts/build-min.php                 CSS and JS (JS needs esbuild: see below)
 *   php scripts/build-min.php --css           CSS only — what the maintenance run does on the host
 *
 * JS is minified by esbuild, found as $ESBUILD, else ./node_modules/.bin/esbuild, else
 * `npx --yes esbuild@0.25.12`. Run it after editing a script and commit public/assets/min;
 * until then that script is served as written, which is slower and never wrong.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use AfricaGates\Support\Assets;
use AfricaGates\Support\MinifiedAssets;

$public = dirname(__DIR__) . '/public';
Assets::configure($public);
MinifiedAssets::enable(true);
$cssOnly = in_array('--css', $argv, true);

$r = MinifiedAssets::buildCss($public);
printf("css: %d made, %d already current, %d stale removed%s\n", $r['made'], $r['kept'], $r['removed'],
    $r['failed'] ? ', not minified: ' . implode(' ', $r['failed']) : '');
if ($cssOnly) exit(0);

$bin = getenv('ESBUILD') ?: (is_file(dirname(__DIR__) . '/node_modules/.bin/esbuild') ? dirname(__DIR__) . '/node_modules/.bin/esbuild' : 'npx --yes esbuild@0.25.12');
$esbuild = static function (string $js, string $url) use ($bin): ?string {
    // No --target: the syntax is kept as written, so nothing is "lowered" into a form the
    // source never had. Comments and whitespace go, local names shrink. Legal comments too —
    // the vendored files that carry licences are never minified here.
    $p = proc_open($bin . ' --minify --legal-comments=none --charset=utf8 --loader=js --log-level=error',
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return null;
    fwrite($pipes[0], $js); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0 || $out === false || $out === '') { fwrite(STDERR, "$url: $err\n"); return null; }
    return $out;
};
$r = MinifiedAssets::build($public, 'js', $esbuild);
printf("js:  %d made, %d already current, %d stale removed%s\n", $r['made'], $r['kept'], $r['removed'],
    $r['failed'] ? ', not minified: ' . implode(' ', $r['failed']) : '');
