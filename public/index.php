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
// A referral link works on any page, not only /events. The capture used to live inside
// EventsController, so a member who shared their link to the shop or the home page earned
// nothing — the code was dropped on the first navigation. Middleware because the failure
// mode is FORGETTING: a call at the top of each controller is a rule somebody has to
// remember on the next page they add, and the next page they add is where a link gets
// shared. GET only — a `?ref=` on a POST is a form action, not somebody following a link.
$app->add(new \AfricaGates\Middleware\ReferralCaptureMiddleware());
// Where the people who arrive here came from — one row per session, written on the way
// in. Middleware for the same reason the referral capture is: the failure mode is
// forgetting, and the next public page somebody adds is the one a campaign link points
// at. Records nothing for bots, admin pages, or anybody sending DNT / Sec-GPC, and
// swallows its own errors — a tracker that can 500 the home page is worse than none.
$app->add(new \AfricaGates\Middleware\VisitTrackingMiddleware());
$app->add(new CsrfMiddleware());
$app->addBodyParsingMiddleware();

// Error handler — reuse the single error-visibility decision computed above.
$errMiddleware = $app->addErrorMiddleware($showErrors, true, true);
$errMiddleware->setDefaultErrorHandler(new ErrorHandler($app));

/**
 * SECURITY HEADERS ARE ADDED LAST, WHICH MAKES THEM THE OUTERMOST LAYER.
 *
 * Slim runs middleware LIFO, so this must come AFTER addErrorMiddleware() or the
 * error middleware wraps it instead of the reverse — and then no error response
 * ever passes back through here. It was the other way round, and the measurement
 * was unambiguous: a request for a URL that does not exist came back
 *
 *     HTTP/1.1 404 Not Found
 *     Content-type: text/html; charset=UTF-8
 *     Set-Cookie: PHPSESSID=…
 *     X-Powered-By: PHP/8.4.19
 *
 * — the entire header list. No Content-Security-Policy, and none of the six in
 * SecurityHeadersMiddleware::SHARED, on a body containing 11 inline <script>
 * blocks that each carried a nonce with no policy on the other side to honour it.
 *
 * public/.htaccess does not cover the gap. Its six `Header always set` lines do
 * survive an error response, but the CSP is deliberately not among them — it
 * carries a per-request nonce, which a static file cannot hold (the reasoning is
 * written out there). So the error path was the one case with a CSP from neither
 * source: every 404, every 500 and every rejected-CSRF page went out unprotected.
 * That is also the page an attacker can always reach without authenticating, and
 * the one whose job is to say something about the request that failed.
 *
 * Being outermost costs nothing else: the middleware only reads the finished
 * response and sets headers on it, so it cannot change what any inner layer did.
 */
$app->add(new SecurityHeadersMiddleware());

// Routes
(require __DIR__ . '/../src/routes.php')($app);

$app->run();

/**
 * Opportunistic "web cron" for hosts with no shell cron and no external scheduler:
 * once the response is off to the client, run due maintenance. Throttled (~15 min),
 * single-instance locked and admin-gated, so a normal request costs one filemtime().
 *
 * ── THE PROMISE THIS MAKES, AND HOW IT WAS BROKEN ────────────────────────────
 *
 * "the visitor never waits on it" is true only if the response is genuinely detached
 * first. That was guarded by `fastcgi_finish_request()` alone — which exists on
 * PHP-FPM and NOT on LiteSpeed, where the function is `litespeed_finish_request()`.
 * This site runs LiteSpeed (see public/.htaccess on mod_headers emulation), so the
 * guard was false on every request and the maintenance run happened INLINE, with the
 * browser still on the open connection.
 *
 * What that costs is not theoretical. `run('auto')` reconciles stale pending payments
 * on EVERY tick, and each stale order is a gateway verify with a 15-second timeout;
 * it then sends abandoned-checkout mail over SMTP. Twenty abandoned orders is five
 * minutes of work attached to whichever visitor happened to arrive when the throttle
 * expired. LiteSpeed kills the worker long before that, and because the response was
 * already being written, the browser gets a truncated HTTP/2 stream —
 * ERR_HTTP2_PROTOCOL_ERROR, which reads like a network fault rather than a timeout.
 *
 * It is also self-reinforcing, which is why it got worse rather than staying flat: a
 * failing checkout creates pending orders, pending orders lengthen reconciliation,
 * longer reconciliation kills more requests. The reported symptom — the paid-vote
 * POST specifically — is the request most likely to be hit, because it is already the
 * slowest on the site (it makes its own synchronous call to the gateway) and so has
 * the least headroom left.
 *
 * ── THE FIX ─────────────────────────────────────────────────────────────────
 *
 * Detach with whichever function this SAPI actually provides. If NEITHER exists, do
 * not run maintenance at all: making a visitor wait is never the right trade, and
 * these hosts still have the token-gated /__cron/run endpoint and real cron. Silence
 * would hide that, so it is logged once per tick rather than skipped quietly.
 */
register_shutdown_function(static function () use ($container) {
    // LiteSpeed first — it is what this deployment runs, and on it the FPM function
    // is absent, which is precisely how the response failed to be detached.
    // Presence of the function is the signal, not its return value: builds differ on
    // what they return, and treating a falsy return as "not detached" would silently
    // stop maintenance running at all on the very SAPI this fix is for.
    $detached = false;
    if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); $detached = true; }
    elseif (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); $detached = true; }

    if (!$detached) {
        // Cannot get off the connection, so anything slow here is time the visitor
        // spends staring at a spinner — and, past the server's limit, a killed worker
        // and a truncated response. Skip, and say so.
        error_log('[webcron tick] skipped — no way to detach the response on this SAPI ('
            . PHP_SAPI . '). Use real cron or the token-gated /__cron/run endpoint.');
        return;
    }

    try { \AfricaGates\Support\Maintenance::tick($container); }
    catch (\Throwable $e) { error_log('[webcron tick] ' . $e->getMessage()); }
});
