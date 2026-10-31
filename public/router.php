<?php
// Dev-only router for PHP's built-in server (`php -S`). Serves existing static
// assets directly; every other path goes through the Slim front controller so
// clean URLs (e.g. /nominate) resolve. Not used in production (Apache/nginx).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && $path !== '/router.php' && is_file(__DIR__ . $path)) {
    return false; // let the built-in server serve the static file as-is
}
require __DIR__ . '/index.php';
