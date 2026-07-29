<?php
declare(strict_types=1);

use AfricaGates\Support\Env;
require __DIR__ . '/../vendor/autoload.php';

// Load environment — tolerate a malformed .env so the site doesn't go full
// 500 if one line has unquoted whitespace; log the parse error and continue.
try {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
} catch (\Throwable $e) {
    error_log('[.env parse] ' . $e->getMessage());
    // Best-effort fallback parser: ignore broken lines, accept valid KEY=VALUE pairs.
    $envFile = __DIR__ . '/../.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#')) continue;
            if (!preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $m)) continue;
            $val = trim($m[2]);
            // Strip surrounding quotes if any
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            $_ENV[$m[1]] = $val;
            $_SERVER[$m[1]] ??= $val;
        }
    }
}

// Every process must agree on what time it is: the award-cycle phase is
// computed from date windows against the clock, so a CLI/web SAPI timezone
// disagreement would make cron and web requests disagree about whether voting
// is open — permanently and silently. Runs after .env so APP_TIMEZONE applies.
\AfricaGates\Support\Clock::boot();

// Production hardening: never leak PHP warnings / notices / stack traces to the
// browser outside genuine local development. The decision lives in one tested
// seam (AfricaGates\Support\Environment) so no single stale value — e.g. a
// shipped .env that still says APP_ENV=development — can open the door on a
// real public host: details require debug ON, a non-prod/demo env, AND a local
// hostname.
$appEnv     = Env::get('APP_ENV', 'production');
$appDebug   = Env::bool('APP_DEBUG');
$showErrors = \AfricaGates\Support\Environment::showErrorDetails(
    $appEnv, $appDebug, $_SERVER['HTTP_HOST'] ?? null
);
if ($appEnv === 'production' && $appDebug) {
    error_log('[africa-gates] APP_DEBUG=true is ignored in production; error details stay hidden.');
}
if (!$showErrors) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    // Secure cookie on HTTPS / in production. Override with SESSION_SECURE=false
    // only for local plain-HTTP development.
    $isHttps  = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // Tri-state: an explicit SESSION_SECURE wins, otherwise derive it. An
    // unrecognised spelling falls back to TRUE — the safe direction for a cookie.
    $secure = Env::has('SESSION_SECURE')
        ? Env::bool('SESSION_SECURE', true)
        : ($isHttps || $appEnv === 'production');
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database (Eloquent)
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

$capsule = new DB();
$capsule->addConnection(require __DIR__ . '/../config/database.php');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// App factory
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\TwigMiddleware;
use AfricaGates\Middleware\SecurityHeadersMiddleware;
use AfricaGates\Middleware\CsrfMiddleware;
use AfricaGates\Handlers\ErrorHandler;

$builder = new ContainerBuilder();
$builder->addDefinitions(require __DIR__ . '/../config/container.php');
$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

// ── IMPORTANT: Set base path if app lives in a subdirectory ──────
// e.g. if accessed via http://localhost/mysite/africa-gates/
// Leave empty string '' if public/ IS the document root
$basePath = Env::get('APP_BASE_PATH', '');
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

// Middleware (order matters — outermost added last)
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, \Slim\Views\Twig::class));
// Trailing-slash canonicalisation. Runs OUTSIDE the routing layer because Slim
// matches paths exactly: without it every route answered 404 for a trailing slash,
// so `/awards/` was a dead end and any hand-shared link that picked one up broke.
$app->add(new \AfricaGates\Middleware\TrailingSlashMiddleware());
$app->add(new SecurityHeadersMiddleware());
$app->add(new CsrfMiddleware());
$app->addBodyParsingMiddleware();

// Error handler — reuse the single error-visibility decision computed above.
$errMiddleware = $app->addErrorMiddleware($showErrors, true, true);
$errMiddleware->setDefaultErrorHandler(new ErrorHandler($app));

// Routes
(require __DIR__ . '/../src/routes.php')($app);

$app->run();

// Opportunistic "web cron" for hosts with no shell cron and no external
// scheduler: after the response is flushed to the client, run due maintenance.
// Throttled (~15 min) + single-instance locked + admin-gated (webcron_auto), so
// the cost on a normal request is a single filemtime() check and the visitor
// never waits on it. Harmless no-op when disabled or not yet due.
register_shutdown_function(static function () use ($container) {
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    try { \AfricaGates\Support\Maintenance::tick($container); }
    catch (\Throwable $e) { error_log('[webcron tick] ' . $e->getMessage()); }
});
