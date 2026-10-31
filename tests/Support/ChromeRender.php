<?php
declare(strict_types=1);

namespace Tests\Support;

use AfricaGates\Middleware\LanguageMiddleware;
use AfricaGates\Support\Languages;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Render a real page through the real router, with the shared chrome on it, as a given
 * visitor — for the Phase 2 chrome guards.
 *
 * ── WHY THROUGH THE APP AND NOT A BARE TWIG ENVIRONMENT ─────────────────────
 *
 * The chrome reads Twig GLOBALS (`is_member`, `member_name`, `csrf_token`) and FUNCTIONS
 * (`lang_ask()`, `member_display()`, `status_light()`) that `config/container.php`
 * registers. A test that builds its own `Environment` has to mirror every one of them, and
 * CLAUDE.md records sixteen tests breaking at once the day a screen gained a form for
 * exactly that reason. So the container is built the way `public/index.php` builds it,
 * and the globals see the session the test set BEFORE the build — they are computed then.
 *
 * `LanguageMiddleware` is on the app, because "is the prompt drawn" is a question about
 * what the middleware settled from the request's cookie, and a render that skipped it
 * would answer for a request nobody can make.
 *
 * `/_dev/ui` is the page: the one route on the shell today (every other public page is
 * awaiting its phase), and it composes the full chrome. `?bar=root` draws the root app
 * bar, `?flow=1` drops the tab bar.
 */
final class ChromeRender
{
    /**
     * @param array<string,mixed>  $session  $_SESSION as the visitor's (e.g. ['user_id' => 1, 'user_name' => 'Chioma Obi'])
     * @param array<string,string> $cookies
     */
    public static function page(string $uri = '/_dev/ui', array $session = [], array $cookies = [], string $method = 'GET', ?array $body = null, array $headers = []): ResponseInterface
    {
        $root = dirname(__DIR__, 2);
        $prevEnv = $_ENV['APP_ENV'] ?? null;
        $prevSession = $_SESSION ?? [];
        $_ENV['APP_ENV'] = 'development';
        $_SESSION = $session + ['csrf_token' => 'test-token'];
        Languages::forget();
        \AfricaGates\Services\CookiePrefs::forget();

        try {
            $builder = new ContainerBuilder();
            $builder->addDefinitions($root . '/config/container.php');
            AppFactory::setContainer($builder->build());
            $app = AppFactory::create();
            (require $root . '/src/routes.php')($app);
            $app->addBodyParsingMiddleware();
            // In public/index.php's order. VisitTrackingMiddleware is what primes the consent
            // state the layout draws (CookiePrefs::observe()); without it every render here
            // would agree that the cookie notice is never shown.
            $app->add(new \AfricaGates\Middleware\VisitTrackingMiddleware());
            $app->add(new LanguageMiddleware());
            $app->addRoutingMiddleware();
            $app->addErrorMiddleware(false, false, false);

            $req = (new ServerRequestFactory())->createServerRequest($method, $uri);
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
            $req = $req->withQueryParams($q)->withCookieParams($cookies);
            if ($body !== null) $req = $req->withParsedBody($body);
            foreach ($headers as $k => $v) $req = $req->withHeader($k, $v);

            return $app->handle($req);
        } finally {
            if ($prevEnv === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $prevEnv;
            $_SESSION = $prevSession;
            Languages::forget();
            \AfricaGates\Services\CookiePrefs::forget();
        }
    }

    /** The body, as a string. */
    public static function html(string $uri = '/_dev/ui', array $session = [], array $cookies = []): string
    {
        return (string) self::page($uri, $session, $cookies)->getBody();
    }

    /** A Twig template's source with `{# … #}` removed — a comment reaches nobody. */
    public static function source(string $rel): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel));
    }

    /** A stylesheet or script with its block comments removed. */
    public static function code(string $rel): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel));
    }

    /**
     * Innermost CSS rules as [selector, body].
     *
     * @return list<array{0:string,1:string}>
     */
    public static function rules(string $css): array
    {
        $out = [];
        $stack = [];
        $buf = '';
        foreach (str_split($css) as $ch) {
            if ($ch === '{') { $stack[] = trim($buf); $buf = ''; continue; }
            if ($ch === '}') {
                $sel = array_pop($stack);
                if ($sel !== null && !str_starts_with($sel, '@') && trim($buf) !== '') $out[] = [$sel, $buf];
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        return $out;
    }
}
