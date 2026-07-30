<?php
declare(strict_types=1);

/**
 * ── BOOTSTRAP PRECONDITIONS ──────────────────────────────────────────────────
 *
 * Two checks, before anything else, because both have already taken the site down and
 * both presented as a blank 403 with nothing on screen.
 *
 * WHAT HAPPENED. The production error log for 30 Jul 2026 is 388 lines and every single
 * one is the same:
 *
 *     PHP Fatal error: require(): Failed opening required
 *     '/home/afrovang/africa-gates/africa-gates/public/../vendor/autoload.php'
 *
 * Read the path: `africa-gates/africa-gates`. The deploy landed one directory deeper
 * than it should have, so `vendor/` — which lives in the OUTER copy, where composer was
 * run — is not where `index.php` looks for it. Every request fataled on line 6. The
 * visible symptom was a 403 and no explanation.
 *
 * The same log also shows `ea-php74` serving until 15:43 and `ea-php84` from 10:25.
 * composer.json requires >= 8.4 and this codebase uses PHP 8 syntax throughout, so on
 * the 7.4 handler nothing here can even PARSE — which is the likeliest reason an older
 * copy of the application kept answering while edits to this one appeared to do nothing.
 *
 * WHY IT IS WRITTEN LIKE THIS. Everything below must PARSE on PHP 7.4, or the version
 * check cannot report that PHP is too old — a parse error is a blank 500 and tells the
 * operator nothing. So: no `match`, no `?->`, no named arguments, no promoted
 * constructors, and `str_contains()` and friends are avoided (they are PHP 8 *functions*,
 * fine at parse time but fatal if called). Keep it that way.
 *
 * It writes plain HTML rather than throwing, because the audience is an operator looking
 * at a broken site, not a stack trace reader.
 */
if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Africa GATES — PHP too old</title>'
       . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:44rem;margin:8vh auto;padding:0 1.5rem">'
       . '<h1 style="font-size:1.3rem">This PHP version cannot run Africa GATES</h1>'
       . '<p>Running <b>PHP ' . PHP_VERSION . '</b>; this application requires <b>8.4</b> or newer '
       . '(see <code>composer.json</code>). Most of the codebase does not parse on PHP 7.x, so the '
       . 'site cannot start — and an older copy of the app may keep answering while your edits '
       . 'appear to have no effect.</p>'
       . '<p><b>Fix:</b> cPanel &rarr; <i>MultiPHP Manager</i> &rarr; select this domain &rarr; set '
       . '<b>ea-php84</b> &rarr; Apply. Then reload.</p></div>';
    exit;
}

$__autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($__autoload)) {
    // Is this the nested-deploy case? If a vendor/ exists one level FURTHER up, the tree
    // was extracted one directory too deep — which is exactly what the production log
    // shows, and it is not something an operator would guess from a 403.
    $__nested = is_file(__DIR__ . '/../../vendor/autoload.php');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    echo '<!doctype html><meta charset="utf-8"><title>Africa GATES — dependencies missing</title>'
       . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:46rem;margin:8vh auto;padding:0 1.5rem">'
       . '<h1 style="font-size:1.3rem">Dependencies are not installed</h1>'
       . '<p>The application looked for its autoloader at:</p>'
       . '<p><code style="display:block;padding:.6rem .8rem;background:#f4f4f4;border-radius:6px;'
       . 'word-break:break-all">' . htmlspecialchars($__autoload, ENT_QUOTES, 'UTF-8') . '</code></p>';
    if ($__nested) {
        echo '<p><b>This deploy is nested one directory too deep.</b> A <code>vendor/</code> '
           . 'directory exists one level further up, so the application files were extracted '
           . 'into a subdirectory of themselves — the classic '
           . '<code>africa-gates/africa-gates/</code> shape.</p>'
           . '<p><b>Fix (either one):</b></p><ul>'
           . '<li>Point the domain&rsquo;s <i>Document Root</i> at the <code>public/</code> of the '
           . 'copy that has <code>vendor/</code> beside it; or</li>'
           . '<li>Move the application files up one level so <code>vendor/</code> sits next to '
           . '<code>public/</code>, <code>src/</code> and <code>composer.json</code>.</li></ul>';
    } else {
        echo '<p><b>Fix:</b> run <code>composer install --no-dev --optimize-autoloader</code> in the '
           . 'directory containing <code>composer.json</code>. No shell? Upload the '
           . '<code>vendor/</code> directory built elsewhere — it must sit beside '
           . '<code>public/</code>, not inside it.</p>';
    }
    echo '<p style="color:#666;font-size:.92rem">Nothing is wrong with your data. The site returns '
       . 'as soon as the autoloader is in place.</p></div>';
    exit;
}

use AfricaGates\Support\Env;
use AfricaGates\Support\Http;
require $__autoload;

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
    // Before session_start(), or PHP sends its own Expires/Pragma/Cache-Control and
    // the middleware's policy ends up contradicting them. See Support\Http.
    Http::disableSessionCacheLimiter();
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
