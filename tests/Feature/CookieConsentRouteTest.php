<?php
declare(strict_types=1);

namespace Tests\Feature;

use AfricaGates\Services\CookiePrefs;
use AfricaGates\Support\CookieRegistry;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The whole refusal, through the real router.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ONE IS END-TO-END AND THE REST ARE NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because every part of this feature was individually correct in an earlier version of
 * itself and the whole did nothing. That is this repository's most expensive pattern —
 * `manageUrl()` built a donor's cancellation link with a passing test and no caller, so
 * the stop button on a monthly gift was reachable only by somebody who could read the
 * database. The distinguishing question is not "does this work?" but **who is ever handed
 * this?** — and for a privacy control the answer has to be "anybody who opens /cookies".
 *
 * So this boots the container and the route file the way `public/index.php` does, asks for
 * the page, presses the button, and then checks that the arrival which follows is not
 * recorded. (Rebuilt on 3 Oct 2026 for the four categories in `ag_consent`; what the notice
 * and sheet DRAW is CookieConsentNoticeTest's.) Nothing here reads a class it is testing except to name a cookie.
 *
 * The first draft of this feature also shipped both forms with `name="csrf_token"` — the
 * name of the Twig GLOBAL, not of the field `CsrfMiddleware` reads — so a correct token
 * travelled in a box nothing opened and every press would have been rejected as a forgery.
 * `CsrfFieldNameTest` caught it. A round trip through the real middleware stack catches the
 * next one of those without anybody having to have thought of it.
 */
final class CookieConsentRouteTest extends TestCase
{
    private static ?App $app = null;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['csrf_token' => 'test-token'];
        CookiePrefs::forget();
        DB::table('gates_settings')->where('key_name', 'like', 'visits_%')->delete();
    }

    /**
     * Built once: the container and seven hundred routes are expensive, and the routes do
     * not change between tests. Same memo as RouteTableIntegrityTest, for the same reason.
     */
    private function app(): App
    {
        if (self::$app instanceof App) return self::$app;

        $root    = dirname(__DIR__, 2);
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $root . '/config/container.php');

        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require $root . '/src/routes.php')($app);
        $app->add(new \AfricaGates\Middleware\VisitTrackingMiddleware());
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        return self::$app = $app;
    }

    private function get(string $path, array $cookies = [], array $headers = []): ResponseInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path)
            ->withCookieParams($cookies);

        $headers += ['User-Agent' => 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36'];
        foreach ($headers as $k => $v) $r = $r->withHeader($k, $v);

        return $this->app()->handle($r);
    }

    private function post(array $body, array $cookies = [], array $headers = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/cookies/choice')
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withCookieParams($cookies)
            ->withParsedBody($body + ['_token' => 'test-token']);
        foreach ($headers as $k => $v) $req = $req->withHeader($k, $v);

        return $this->app()->handle($req);
    }

    /** The `ag_consent` record a response set, decoded. */
    private function stored(ResponseInterface $res): ?array
    {
        foreach ($res->getHeader('Set-Cookie') as $line) {
            if (str_starts_with($line, CookiePrefs::COOKIE . '=')) {
                $raw = explode(';', substr($line, strlen(CookiePrefs::COOKIE) + 1))[0];
                return json_decode(rawurldecode($raw), true);
            }
        }
        return null;
    }

    private function consentCookie(?bool $p, ?bool $a): array
    {
        return [CookiePrefs::COOKIE => rawurlencode((string) json_encode(
            ['v' => CookiePrefs::VERSION, 'preferences' => $p, 'analytics' => $a, 'marketing' => null]))];
    }

    public function test_pressing_essential_stores_the_refusal_and_comes_back(): void
    {
        $res = $this->post(['answer' => 'essential', 'return' => '/nominees']);

        $this->assertSame(303, $res->getStatusCode(), 'the press was rejected — most likely the CSRF field name');
        $this->assertSame('/nominees', $res->getHeaderLine('Location'));
        $this->assertSame(['v' => CookiePrefs::VERSION, 'preferences' => false, 'analytics' => false, 'marketing' => false],
            $this->stored($res));

        $set = implode("\n", $res->getHeader('Set-Cookie'));
        $this->assertStringContainsString('HttpOnly', $set);
        $this->assertStringContainsString('SameSite=Lax', $set);
        $this->assertSame('essential', $_SESSION['consent_saved'] ?? null, 'the page has nothing to confirm with');
    }

    public function test_an_unreadable_answer_is_stored_as_a_refusal(): void
    {
        // The only safe reading of a request we could not understand about whether
        // somebody agreed. Storing it rather than leaving it unanswered is also the kinder
        // outcome: unanswered means the notice returns on the next page.
        $doc = $this->stored($this->post(['answer' => 'maybe']));
        $this->assertFalse($doc['preferences']);
        $this->assertFalse($doc['analytics']);
    }

    public function test_choose_stores_nothing_and_opens_the_sheet_without_a_script(): void
    {
        // The notice's third button, for a browser with no script: the server sends it back
        // to the page with `#ag-consent`, which the sheet answers with `:target`.
        $res = $this->post(['answer' => 'choose', 'return' => '/nominees']);

        $this->assertSame(303, $res->getStatusCode());
        $this->assertSame('/nominees#ag-consent', $res->getHeaderLine('Location'));
        $this->assertSame([], $res->getHeader('Set-Cookie'), 'Choose answered something nobody answered');
    }

    public function test_the_return_is_refused_off_site(): void
    {
        $this->assertSame('/', $this->post(['answer' => 'all', 'return' => '//evil.example'])->getHeaderLine('Location'));
    }

    public function test_answering_retires_the_old_cookie_and_the_language_follows_preferences(): void
    {
        $res = $this->post(['answer' => 'all'], [CookiePrefs::LEGACY => 'n', 'ag_lang' => 'fr']);
        $set = $res->getHeader('Set-Cookie');

        $this->assertContains(CookiePrefs::LEGACY . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax', $set,
            'the old cookie was left in the browser after the new answer was stored');

        // Saying yes to Preferences makes a session-only language a remembered one at once.
        $lang = implode("\n", array_filter($set, static fn (string $l): bool => str_starts_with($l, 'ag_lang=')));
        $this->assertStringContainsString('ag_lang=fr', $lang);
        $this->assertStringContainsString('Max-Age=', $lang);

        // And saying no takes the year away again.
        $no = $this->post(['answer' => 'essential'], ['ag_lang' => 'fr']);
        $lang = implode("\n", array_filter($no->getHeader('Set-Cookie'), static fn (string $l): bool => str_starts_with($l, 'ag_lang=')));
        $this->assertStringContainsString('ag_lang=fr', $lang);
        $this->assertStringNotContainsString('Max-Age', $lang, 'Preferences was refused and the language is still kept for a year');

        // A browser signal beats the yes the visitor just gave.
        $gpc = $this->post(['answer' => 'all'], ['ag_lang' => 'fr'], ['Sec-GPC' => '1']);
        $lang = implode("\n", array_filter($gpc->getHeader('Set-Cookie'), static fn (string $l): bool => str_starts_with($l, 'ag_lang=')));
        $this->assertStringNotContainsString('Max-Age', $lang);
        $this->assertSame('signal', $_SESSION['consent_saved'] ?? null, 'the confirmation must say the signal still holds');
    }

    public function test_a_stored_refusal_actually_stops_the_next_arrival_being_recorded(): void
    {
        // The end of the chain: the button, the cookie and the tracker all agreeing.
        $before = (int) DB::table('gates_visits')->count();

        $_SESSION = ['csrf_token' => 'test-token'];
        $this->get('/', $this->consentCookie(null, false));
        $this->assertSame($before, (int) DB::table('gates_visits')->count(),
            'the visitor refused and was counted on the very next page');

        // A refusal stored under the retired cookie is honoured the same way.
        $_SESSION = ['csrf_token' => 'test-token'];
        $this->get('/', [CookiePrefs::LEGACY => 'n']);
        $this->assertSame($before, (int) DB::table('gates_visits')->count());

        // And the control is not one-way: a yes counts again.
        $_SESSION = ['csrf_token' => 'test-token'];
        $this->get('/', $this->consentCookie(null, true));
        $this->assertSame($before + 1, (int) DB::table('gates_visits')->count(),
            'somebody who agreed to be counted was not');
    }

    public function test_the_downloadable_editions_carry_the_generated_list(): void
    {
        // /cookies.txt and /cookies.md are real routes, and a policy somebody downloaded
        // to keep would otherwise be missing the one section they downloaded it for. The
        // AI disclosure had exactly this fault before it moved into LegalDocument.
        foreach (['/cookies.txt', '/cookies.md'] as $path) {
            $body = (string) $this->get($path)->getBody();

            $this->assertNotSame('', trim($body), $path . ' is empty');
            foreach (CookieRegistry::names() as $name) {
                $this->assertStringContainsString($name, $body,
                    "{$path} omits '{$name}', which the page lists");
            }
        }
    }
}
