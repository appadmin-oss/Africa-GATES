<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Handlers\ErrorHandler;
use AfricaGates\Services\HelpCentre;
use AfricaGates\Services\LegalDocument;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The Phase 9 pages, booted through the real router: the legal documents, the Integrity
 * Centre, the help desk's doors, the error page and the bulk-mail stop button. Each test is a
 * rule one of the destroyed pages carried, asserted on what the rebuilt page SERVES.
 */
final class Phase9PagesTest extends TestCase
{
    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $err = $app->addErrorMiddleware(false, false, false);
        $err->setDefaultErrorHandler(new ErrorHandler($app));
        return $app;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    public function test_every_legal_document_answers_and_carries_its_own_download(): void
    {
        foreach (['/privacy', '/terms', '/cookies', '/refunds', '/vendor-terms'] as $p) {
            $res = $this->get($p);
            $this->assertSame(200, $res->getStatusCode(), $p);
            $html = (string) $res->getBody();
            // Page and download are one source: the .txt the page offers is the same document.
            $this->assertStringContainsString('href="' . $p . '/download/txt"', $html, "$p offers no plain-text copy");
            $this->assertSame(200, $this->get($p . '/download/txt')->getStatusCode(), "$p/download/txt");
        }
        // And the cookie section travels in the machine copy too (CLAUDE.md: /cookies.txt).
        $this->assertSame(200, $this->get('/cookies.txt')->getStatusCode());
    }

    public function test_the_cookie_page_draws_the_generated_list_and_the_control_card(): void
    {
        // The middleware primes this request's consent state; the bare app here has none.
        \AfricaGates\Services\CookiePrefs::observe((new ServerRequestFactory())->createServerRequest('GET', '/cookies'));
        $html = (string) $this->get('/cookies')->getBody();
        // The facts are generated (CookieRegistry via LegalDocument::cookiesHtml), never typed:
        // every cookie the registry declares is named on the page.
        foreach (\AfricaGates\Support\CookieRegistry::cookies() as $c) {
            $this->assertStringContainsString(htmlspecialchars((string) $c['name'], ENT_QUOTES), $html, 'cookie ' . $c['name'] . ' is not listed');
        }
        // The control is a plain form that posts (no JavaScript needed), at #choices.
        $this->assertMatchesRegularExpression('~id="choices"~', $html);
        $this->assertMatchesRegularExpression('~<form(?=[^>]*method="post")[^>]*action="/cookies/choice"~', $html);
        $this->assertStringContainsString('name="return" value="/cookies"', $html);
    }

    public function test_about_is_a_permanent_move_to_the_philosophy(): void
    {
        $res = $this->get('/about');
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/philosophy', $res->getHeaderLine('Location'));
        $this->assertSame(200, $this->get('/philosophy')->getStatusCode());
    }

    public function test_the_integrity_centre_states_the_exchange_rate(): void
    {
        $html = (string) $this->get('/integrity')->getBody();
        $this->assertStringContainsString('one verified supporter is worth about', $html);
        $this->assertStringContainsString('href="/help/why-is-voting-paid"', $html);
    }

    public function test_every_ask_gee_opens_the_help_desk_in_place_and_degrades_to_a_link(): void
    {
        $html = (string) $this->get('/help')->getBody();
        preg_match_all('~<a\b[^>]*data-ag-do="open-gee"[^>]*>~', $html, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $a) {
            $this->assertStringContainsString('data-gee-mode="support"', $a);
            $this->assertMatchesRegularExpression('~href="/help\?gee=support~', $a, 'with no script the link must still reach the desk');
        }
        $res = $this->get('/support/assistant');
        $this->assertSame(301, $res->getStatusCode());
        $this->assertStringStartsWith('/help?gee=support', $res->getHeaderLine('Location'));
    }

    public function test_the_owners_paid_voting_article_reads_its_price_from_the_setting(): void
    {
        \Illuminate\Database\Capsule\Manager::table('gates_settings')
            ->updateOrInsert(['key_name' => 'vote_price_naira'], ['value' => '275']);
        $html = (string) $this->get('/help/why-is-voting-paid')->getBody();
        $this->assertStringContainsString('₦275 votes', $html);
        $this->assertStringContainsString('class="ha__quote"', $html);
        $this->assertDoesNotMatchRegularExpression('~₦(100|200)\b~u', $html, 'a typed price outlives the setting');
        // Found by the help desk's own retrieval, which is what Gee reads.
        $this->assertSame('why-is-voting-paid', HelpCentre::search('why is voting paid')[0]['slug'] ?? null);
    }

    public function test_a_crash_shows_a_reference_and_never_the_exception(): void
    {
        $app = $this->app();
        $h   = new ErrorHandler($app);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        $res = $h($req, new \RuntimeException('SQLSTATE secret-detail /home/app/src/Thing.php:42'), false, false, false);
        $html = (string) $res->getBody();
        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringNotContainsString('secret-detail', $html);
        $this->assertStringNotContainsString('Thing.php', $html);
        $this->assertMatchesRegularExpression('~class="er__ref-code">[^<]+</code>~', $html, 'no reference to quote');
    }

    public function test_a_refusal_and_a_missing_page_are_not_called_a_crash(): void
    {
        $this->assertSame(404, $this->get('/no-such-page-anywhere-' . bin2hex(random_bytes(3)))->getStatusCode());
        $app = $this->app();
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        $res = (new ErrorHandler($app))($req, new \Slim\Exception\HttpForbiddenException($req), false, false, false);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringNotContainsString('er__ref-code', (string) $res->getBody());
    }

    public function test_the_stop_button_works_with_no_session_and_no_script(): void
    {
        $url = \AfricaGates\Services\EmailOptOut::url('', 'reader@africa-gates-test.org');
        $res = $this->get($url);
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertMatchesRegularExpression('~<form[^>]+method="post"[^>]+action="[^"]*/email/unsubscribe"~', $html);
        preg_match('~<form[^>]*action="[^"]*/email/unsubscribe".*?</form>~s', $html, $form);
        $this->assertStringNotContainsString('name="_token"', $form[0], 'a token from no session is a token the POST refuses');
        // Exempt from CSRF by design: the person arrives from an inbox with no session.
        $csrf = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/CsrfMiddleware.php');
        $this->assertStringContainsString("'/email/unsubscribe'", $csrf);
    }
}
