<?php
/**
 * Dev-server router for the browser checks: serve real files from public/ directly,
 * hand everything else to the app.
 *
 * Without this, `php -S … public/index.php` routes EVERY request through Slim, which
 * 404s every static asset. The first CSP run reported "17 pages, 0 violations" and was
 * worthless: Alpine and every vendored script had 404'd, so no JavaScript executed at
 * all. A clean CSP result on a page with no scripts proves nothing.
 *
 * Pair it with PHP_CLI_SERVER_WORKERS — see tools/browser/README.md.
 */
$root = dirname(__DIR__, 2) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file($root . $path)) {
    return false;   // let the built-in server stream it
}
require $root . '/index.php';
