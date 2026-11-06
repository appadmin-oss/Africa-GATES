<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Middleware\LanguageMiddleware;
use AfricaGates\Support\Languages;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\TestCase;

/**
 * The language mechanism: one resolver, one writer, and a `dir` that is really set.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS GUARDING AND WHY EACH PART OF IT MATTERS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `Support\Languages` is the mechanism; the words are `Support\Translator`'s, held by
 * `TranslatorTest`. What this file checks is the mechanism — which language, which
 * direction, which cookie — and each part of it has a failure mode this codebase has
 * already paid for once:
 *
 * · TWO STORES FOR ONE VALUE. The first cut of this work kept the language in the
 *   `ag-a11y` localStorage bag as well as in the cookie, because the settings surfaces
 *   write everything else there. The server renders `<html lang>` from the cookie, so the
 *   two would disagree the first moment one was cleared without the other — and the
 *   symptom is a page that announces itself in the wrong language to a screen reader while
 *   every menu shows the right one. The store no longer holds it; this asserts that the
 *   shipped JS does not either.
 *
 * · A CONTROL THAT NEEDS JAVASCRIPT. Every language control on the site is a plain link
 *   carrying `?lang=`, because the person likeliest to be blocked by a script failure is
 *   the person likeliest to need a different language. `templates/` is swept for a
 *   language control that is not one.
 *
 * · A BARE `?lang=xx` HREF. It replaces the whole query string, so switching language on
 *   `/discover?q=lagos` would silently drop the search and read as the switch breaking the
 *   page. {@see Languages::url()} builds it; this proves it carries the rest.
 *
 * · A COOKIE NOBODY DECLARED. `CookieRegistryTest` owns that sweep; this only pins that
 *   `ag_lang` is in it, so removing it from the policy fails here too rather than only in
 *   a file somebody might read as a formality.
 */
final class LanguageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The memo is per PROCESS and the suite is one process, so the first test to run
        // would otherwise seed every later one's answers.
        Languages::forget();
    }

    protected function tearDown(): void
    {
        Languages::forget();
        parent::tearDown();
    }

    private function request(string $uri = '/', array $cookies = []): ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);

        return $r->withQueryParams($q)->withCookieParams($cookies);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The resolver
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_parameter_beats_the_cookie_which_beats_english(): void
    {
        $this->assertSame('en', Languages::resolve([], []));
        $this->assertSame('sw', Languages::resolve([], ['ag_lang' => 'sw']));
        // A link somebody was sent carries the language, and it wins over what the
        // browser already remembered — otherwise sharing a Hausa page with a friend
        // shows them whatever they last chose.
        $this->assertSame('ha', Languages::resolve(['lang' => 'ha'], ['ag_lang' => 'sw']));
    }

    public function test_an_unsupported_code_is_ignored_rather_than_corrected(): void
    {
        // It arrives in a query string, so the worst a stranger can do is be ignored.
        $this->assertSame('en', Languages::resolve(['lang' => 'xx'], []));
        $this->assertSame('sw', Languages::resolve(['lang' => '../etc'], ['ag_lang' => 'sw']));
        $this->assertFalse(Languages::supported(['lang' => 'en']));
        $this->assertFalse(Languages::supported(null));
        $this->assertTrue(Languages::supported('  FR '));
    }

    public function test_arabic_is_the_only_right_to_left_language_and_it_really_is(): void
    {
        foreach (Languages::ALL as $code => $l) {
            $this->assertSame(
                $code === 'ar' ? 'rtl' : 'ltr',
                Languages::dir($code),
                "$code declares the wrong direction"
            );
        }
    }

    public function test_every_language_carries_all_its_facts(): void
    {
        // Asked of options(), the shape every template loops over, rather than of ALL:
        // the prompt's words moved into the catalogues and are read back here, so a
        // template still sees `ask` and `yes` on every row and never a missing key.
        foreach (Languages::options() as $l) {
            $code = $l['code'];
            foreach (['name', 'english', 'dir', 'ask', 'yes'] as $k) {
                $this->assertArrayHasKey($k, $l, "$code is missing $k");
            }
            $this->assertNotSame('', $l['name'], "$code has no name");
            $this->assertNotSame('', $l['english'], "$code has no English name");
        }
        $this->assertCount(count(Languages::ALL), Languages::options());
    }

    public function test_the_name_is_written_in_the_language_itself(): void
    {
        // Somebody looking for their own language scans for the word they would use, and
        // cannot be assumed to read the word the current interface would use for it.
        $this->assertSame('Français', Languages::name('fr'));
        $this->assertSame('العربية', Languages::name('ar'));
        $this->assertSame('Yorùbá', Languages::name('yo'));
    }

    public function test_the_prompt_list_excludes_english_and_anything_unwritten(): void
    {
        $codes = array_column(Languages::prompts(), 'code');

        // The prompt offers a way OUT of English, so English is never in it.
        $this->assertNotContains('en', $codes);
        // And a language with no sentence written for it is left out rather than having
        // one composed at runtime, which is how a placeholder reaches a screen.
        foreach (Languages::prompts() as $l) {
            $this->assertNotSame('', $l['ask'], $l['code'] . ' is offered with no question');
            $this->assertNotSame('', $l['yes'], $l['code'] . ' is offered with no answer');
        }
        $this->assertGreaterThan(0, count($codes));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The per-request answer
    // ══════════════════════════════════════════════════════════════════════════

    public function test_switching_language_keeps_the_rest_of_the_query_string(): void
    {
        Languages::observe($this->request('/discover?q=lagos&page=2'));

        $url = Languages::url('fr');

        // A bare `?lang=fr` would drop both of these, and the page would come back
        // looking like the language switch had broken the search.
        $this->assertStringContainsString('q=lagos', $url);
        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('lang=fr', $url);
        $this->assertStringStartsWith('/discover?', $url);
    }

    public function test_the_carried_fields_never_include_the_language_itself(): void
    {
        Languages::observe($this->request('/shop?q=tee&lang=pt'));

        $carry = Languages::carry();

        // The select posts `lang` itself; a hidden field of the same name would submit
        // two values and the browser would send the hidden one.
        $this->assertArrayNotHasKey('lang', $carry);
        $this->assertSame(['q' => 'tee'], $carry);
    }

    public function test_url_answers_even_when_nobody_primed_the_memo(): void
    {
        // A console render or a test that never went through the middleware. An empty
        // href would reload the page and read as the control doing nothing.
        $this->assertNotSame('', Languages::url('ig'));
        $this->assertStringContainsString('lang=ig', Languages::url('ig'));
    }

    public function test_the_prompt_asks_only_somebody_who_has_not_answered(): void
    {
        Languages::observe($this->request('/'));
        $this->assertTrue(Languages::shouldAsk(), 'a first visit should be asked');

        Languages::observe($this->request('/', ['ag_lang' => 'en']));
        $this->assertFalse(Languages::shouldAsk(), 'somebody who chose English is not asked again');

        // THE TRAP: reading the RESOLVED language instead of the cookie's presence makes
        // this false for everybody, because an unanswered visitor resolves to English
        // exactly like somebody who chose it — and the prompt becomes unaskable.
        Languages::observe($this->request('/?lang=fr'));
        $this->assertFalse(Languages::shouldAsk());
        $this->assertSame('fr', Languages::current());
        $this->assertSame('ltr', Languages::currentDir());

        Languages::observe($this->request('/?lang=ar'));
        $this->assertSame('rtl', Languages::currentDir());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The writer
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_middleware_writes_the_cookie_only_when_a_language_was_asked_for(): void
    {
        $mw = new LanguageMiddleware();

        $none = $mw->process($this->request('/'), $this->handler());
        $this->assertSame([], $none->getHeader('Set-Cookie'),
            'a plain page view must not refresh a year-long cookie on every request');

        $bad = $mw->process($this->request('/?lang=xx'), $this->handler());
        $this->assertSame([], $bad->getHeader('Set-Cookie'),
            'an unsupported code stores nothing, so the prompt may still ask');

        // Remembered for a year only with the visitor's Preferences answer (CookiePrefs,
        // GAPS Q11). Without it the language still applies — the visitor asked for it just
        // now — as a session cookie, forgotten when the browser closes.
        $session = $mw->process($this->request('/?lang=fr'), $this->handler());
        $once = implode(' ', $session->getHeader('Set-Cookie'));
        $this->assertStringContainsString('ag_lang=fr', $once);
        $this->assertStringNotContainsString('Max-Age', $once,
            'the language was kept for a year for somebody who never allowed Preferences');

        $yes  = rawurlencode((string) json_encode(['v' => 1, 'preferences' => true, 'analytics' => null, 'marketing' => null]));
        $good = $mw->process($this->request('/?lang=fr')->withCookieParams(['ag_consent' => $yes]), $this->handler());
        $set = implode(' ', $good->getHeader('Set-Cookie'));
        $this->assertStringContainsString('ag_lang=fr', $set);
        $this->assertStringContainsString('Max-Age=' . Languages::TTL, $set);

        // And a browser signal beats that yes: if anything said no, the answer is no.
        $gpc = $mw->process($this->request('/?lang=fr')->withCookieParams(['ag_consent' => $yes])
            ->withHeader('Sec-GPC', '1'), $this->handler());
        $this->assertStringNotContainsString('Max-Age', implode(' ', $gpc->getHeader('Set-Cookie')));
        $this->assertStringContainsString('SameSite=Lax', $set);
        // Nothing on any page needs to read it: the language is already on `<html lang>`
        // in the markup the server sent. Handing scripts one more stable value would
        // widen the fingerprinting surface to save a round trip nobody is making.
        $this->assertStringContainsString('HttpOnly', $set);
    }

    public function test_the_middleware_settles_the_language_before_the_handler_runs(): void
    {
        // The page has to render in the NEW language on the very request that asked for
        // it; settling it on the way out would show one English page first.
        $seen = null;
        $h = new class ($seen) implements RequestHandlerInterface {
            public function __construct(public ?string &$seen) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = Languages::current();
                return new Response(200);
            }
        };

        (new LanguageMiddleware())->process($this->request('/?lang=sw'), $h);

        $this->assertSame('sw', $h->seen);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The surfaces
    // ══════════════════════════════════════════════════════════════════════════

    public function test_no_language_control_in_a_template_needs_javascript(): void
    {
        // A `<button data-ag-pick-lang>` was the first cut of every one of these, and it
        // does nothing at all with scripting off.
        $offenders = [];
        foreach ($this->templates() as $path => $body) {
            if (preg_match('/<button[^>]*data-ag-pick-lang/', $body)) {
                $offenders[] = $path . ': a language control that only a script can follow';
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_the_display_and_reading_language_form_carries_the_rest_of_the_query(): void
    {
        $body = (string) file_get_contents(__DIR__ . '/../../templates/partials/display-reading.twig');

        $this->assertStringContainsString('method="get"', $body, 'the select must sit in a real form');
        $this->assertStringContainsString('lang_carry()', $body,
            'without the hidden fields, changing language drops whatever else was in the query string');
        $this->assertStringContainsString('name="lang"', $body);
    }

    public function test_the_language_is_not_kept_in_the_display_and_reading_store(): void
    {
        // Two stores for one value. The server renders `<html lang>` from the cookie, so
        // a second copy in localStorage disagrees the first time one is cleared.
        foreach (['a11y.js', 'chrome.js'] as $file) {
            $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/' . $file);
            $this->assertDoesNotMatchRegularExpression(
                '/\bset\(\s*\{\s*lang\s*:/',
                $js,
                "$file writes a language into the ag-a11y store; the cookie is the one place it lives"
            );
        }

        $head = (string) file_get_contents(__DIR__ . '/../../templates/partials/a11y-head.twig');
        $this->assertStringNotContainsString('d.lang=', $head,
            'the head script must not re-answer a question the server already answered in the markup');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The first-visit prompt, RENDERED through its gate (GAPS §3.8)
    // ══════════════════════════════════════════════════════════════════════════
    //
    // `shouldAsk()` above was always right, and the prompt still asked again on every page:
    // the include was unconditional and `lang_ask()` had no caller. A test of the gate in
    // isolation passed over exactly that, so these render the page the way a visitor gets
    // it — through LanguageMiddleware, with and without the cookie — and look for the row.

    public function test_the_prompt_is_drawn_for_somebody_who_has_not_answered(): void
    {
        $html = \Tests\Support\ChromeRender::html('/_dev/ui?bar=root');

        $this->assertStringContainsString('data-ag-langask', $html, 'an unanswered visitor is never asked');
        // In the visitor's language, written by a speaker, never composed: the French row
        // carries the French catalogue's question and its own `lang`.
        $this->assertMatchesRegularExpression('~data-ag-langask-for="fr"~', $html);
        $this->assertStringContainsString('Voir Africa GATES en français ?', $html);
        $this->assertMatchesRegularExpression('~<p class="ag-langask__q" lang="fr" dir="ltr">~', $html);
        // Both answers are links carrying ?lang=, so the middleware stores either one.
        $this->assertMatchesRegularExpression('~class="ag-langask__yes" href="[^"]*lang=fr~', $html);
        $this->assertMatchesRegularExpression('~class="ag-langask__no" href="[^"]*lang=en~', $html);
    }

    public function test_the_prompt_never_returns_once_either_answer_is_stored(): void
    {
        foreach (['en', 'fr'] as $answer) {
            $html = \Tests\Support\ChromeRender::html('/_dev/ui?bar=root', [], ['ag_lang' => $answer]);
            $this->assertStringNotContainsString('data-ag-langask', $html,
                "somebody who answered \"$answer\" is asked again — a prompt that returns is an advert");
        }
    }

    public function test_the_prompt_hangs_under_the_root_bar_only(): void
    {
        // §7.2: "one dismissible row under the root AppBar". A pushed screen is somebody in
        // the middle of something.
        $this->assertStringNotContainsString('data-ag-langask', \Tests\Support\ChromeRender::html('/_dev/ui'));

        $bar = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents(__DIR__ . '/../../templates/partials/app-bar.twig'));
        $this->assertMatchesRegularExpression("~\{% if _root and lang_ask\(\) %\}\{% include 'partials/lang-prompt\.twig' %\}~", $bar,
            'the prompt must be included only inside the lang_ask() gate');

        // And the script hides it on the first scroll and never brings it back on the page.
        $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/chrome.js');
        $this->assertMatchesRegularExpression('~navigator\.languages\[0\]~', $js, 'the FIRST language decides');
        $this->assertMatchesRegularExpression('~if \(!scrolled\) return;\s*box\.hidden = true;\s*if \(stop\) stop\(\);~', $js);
    }

    public function test_arabic_mirrors_the_document_and_every_direction_mark(): void
    {
        $html = \Tests\Support\ChromeRender::html('/_dev/ui?lang=ar');
        $this->assertMatchesRegularExpression('~<html lang="ar" dir="rtl"~', $html);

        // Back and the row chevrons are the reading direction's, and turn with it.
        $this->assertMatchesRegularExpression('~<svg class="ag-ico-dir"[^>]*>\s*<path d="m15 18-6-6 6-6">~', $html);
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/components/chrome.css');
        $this->assertStringContainsString('[dir="rtl"] .ag-ico-dir{ transform:scaleX(-1) }', $css);
        // The chrome is laid out with logical properties: no physical left/right margin or
        // padding that would hold a control on the wrong side in Arabic.
        $this->assertDoesNotMatchRegularExpression('~(?:margin|padding)-(?:left|right)\s*:~', $css);
    }

    public function test_the_cookie_is_declared_in_the_published_policy(): void
    {
        $this->assertContains(
            Languages::COOKIE,
            \AfricaGates\Support\CookieRegistry::names(),
            'a cookie the policy does not name is the fault CookieRegistry exists to stop'
        );
    }

    /** @return array<string,string> path relative to templates/ → body */
    private function templates(): array
    {
        $root = realpath(__DIR__ . '/../../templates');
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $out[ltrim(str_replace($root, '', $f->getPathname()), '/')] = (string) file_get_contents($f->getPathname());
        }
        return $out;
    }
}
