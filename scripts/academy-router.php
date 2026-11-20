<?php
// Dev router for scripts/academy-shots.mjs: public/router.php, except that on a PHP older than
// the 8.4 public/index.php insists on (this repo's own tooling containers ship 8.3) it runs the
// front controller with only that version guard removed. Never used in production.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pub = dirname(__DIR__) . '/public';
if ($path !== '/' && is_file($pub . $path)) return false;
if (PHP_VERSION_ID >= 80400) { require $pub . '/index.php'; return; }
$src = (string) file_get_contents($pub . '/index.php');
$src = (string) preg_replace('~if \(PHP_VERSION_ID < 80400\) \{.*?\n\}\n~s', '', $src, 1);
$src = str_replace('__DIR__', var_export($pub, true), $src);
$shim = sys_get_temp_dir() . '/academy-index-' . md5($src) . '.php';
if (!is_file($shim)) file_put_contents($shim, $src);
require $shim;
