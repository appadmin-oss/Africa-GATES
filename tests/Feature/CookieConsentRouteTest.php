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
 * recorded. Nothing here reads a class it is testing except to name a cookie.
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

    public function test_the_cookies_page_carries_the_control_and_the_real_list(): void
    {
        $res  = $this->get('/cookies');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('id="your-choice"', $html,
            'the switch the page promises is not on the page');
        $this->assertStringContainsString('action="/cookies/choice"', $html);
        $this->assertStringContainsString('name="_token"', $html);

        // Every cookie, named, on the page that exists to name them. `ag_region` and
        // `ag_currency` were set for a year and unmentioned for as long.
        foreach (CookieRegistry::names() as $name) {
            $this->assertStringContainsString($name, $html, "'{$name}' is not published");
        }

        // And the counting is admitted, in the section a visitor can be linked to.
        $this->assertStringContainsString('id="counting-arrivals"', $html);

        $low = strtolower($html);
        $this->assertStringNotContainsString('we set one cookie', $low);
        $this->assertStringNotContainsString('we run no analytics', $low);
    }

    public function test_pressing_the_button_stores_the_refusal_and_comes_back(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/cookies/choice')
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['choice' => 'no', '_token' => 'test-token', 'return' => '/nominees']);

        $res = $this->app()->handle($req);

        $this->assertSame(303, $res->getStatusCode(),
            'the press was rejected — most likely the CSRF field name');
        $this->assertSame('/nominees#your-choice', $res->getHeaderLine('Location'));

        $set = $res->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString(CookiePrefs::COOKIE . '=' . CookiePrefs::NO, $set);
        $this->assertStringContainsString('HttpOnly', $set);
        $this->assertStringContainsString('SameSite=Lax', $set);
    }

    public function test_an_unreadable_answer_is_stored_as_a_refusal(): void
    {
        // The only safe reading of a request we could not understand about whether
        // somebody agreed to be counted. Storing it rather than leaving it unanswered is
        // also the kinder outcome: unanswered means the notice returns on the next page.
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/cookies/choice')
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120')
            ->withParsedBody(['choice' => 'maybe', '_token' => 'test-token']);

        $set = $this->app()->handle($req)->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString(CookiePrefs::COOKIE . '=' . CookiePrefs::NO, $set);
    }

    public function test_a_stored_refusal_actually_stops_the_next_arrival_being_recorded(): void
    {
        // The end of the chain, and the only assertion that proves the feature rather than
        // its parts: the page, the button, the cookie and the tracker all agreeing.
        $before = (int) DB::table('gates_visits')->count();

        $_SESSION = ['csrf_token' => 'test-token'];
        $this->get('/', [CookiePrefs::COOKIE => CookiePrefs::NO]);

        $this->assertSame($before, (int) DB::table('gates_visits')->count(),
            'the visitor refused on /cookies and was counted on the very next page');

        // And the control is not one-way: a yes counts again.
        $_SESSION = ['csrf_token' => 'test-token'];
        $this->get('/', [CookiePrefs::COOKIE => CookiePrefs::YES]);

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

    public function test_no_consent_notice_is_drawn_under_the_default_posture(): void
    {
        // A banner under the exempt posture would be asking permission for something we
        // are not doing — which is the argument the page itself makes.
        //
        // ASSERTED AGAINST A PAGE THAT RENDERED. `assertStringNotContainsString` on an
        // empty body passes and proves nothing, which is the shape of vacuous assertion
        // this repository keeps finding — so the page is checked for a body first.
        $html = $this->ordinaryPage();

        $this->assertStringNotContainsString('May we count this visit?', $html);
    }

    /** A public page that definitely rendered, so a "not present" assertion means something. */
    private function ordinaryPage(): string
    {
        $html = (string) $this->get('/')->getBody();

        $this->assertNotSame('', trim($html), 'the home page rendered nothing to assert about');
        $this->assertStringContainsString('</body>', $html, 'the page did not reach the layout');

        return $html;
    }

    public function test_the_notice_appears_on_an_ordinary_page_in_ask_first_mode(): void
    {
        DB::table('gates_settings')->where('key_name', CookiePrefs::MODE_KEY)->delete();
        DB::table('gates_settings')->insert([
            'key_name' => CookiePrefs::MODE_KEY, 'value' => CookiePrefs::MODE_CONSENT,
        ]);

        $html = $this->ordinaryPage();

        // A `consent` mode with nowhere to consent is a switch that counts nobody for
        // ever: never asked, so never a yes, so the report goes quiet and the screen says
        // "nobody came". The mode and the question are one feature.
        $this->assertStringContainsString('May we count this visit?', $html);
        $this->assertStringContainsString('name="return" value="/"', $html);

        // Not on the page that carries the full control, two inches below.
        $this->assertStringNotContainsString('May we count this visit?',
            (string) $this->get('/cookies')->getBody());

        // And not once they have answered.
        $this->assertStringNotContainsString('May we count this visit?',
            (string) $this->get('/', [CookiePrefs::COOKIE => CookiePrefs::NO])->getBody());

        // Nor when their browser has already said no for them.
        $this->assertStringNotContainsString('May we count this visit?',
            (string) $this->get('/', [], ['Sec-GPC' => '1'])->getBody());
    }
}
