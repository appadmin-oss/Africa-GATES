<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CookiePrefs;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The cookie notice and the preferences sheet, as a visitor receives them.
 *
 * Phase 2 item 6 (REFERENCE §7.9, CookieConsent.dc.html). Rendered through the real router
 * with VisitTrackingMiddleware on it — the middleware is what decides — so every assertion
 * here is about a page somebody can actually be served. Each rule was held by
 * `CookieChoiceScreenTest`, destroyed with the old notice and choice partials
 * (inventory/_partials.md, "Rules held by guard tests destroyed"), and is re-asserted here
 * against the rebuilt markup:
 *
 *  · both answers carry the same weight — the IDENTICAL class string;
 *  · it cannot be dismissed without answering, and it is not a modal;
 *  · neither screen needs JavaScript — plain forms that post, with the CSRF field;
 *  · it carries the page it was drawn on;
 *  · nothing is offered to a browser that has already refused;
 *  · a press is confirmed, and announced;
 *  · it cannot hide what the keyboard is on (AccessibilityFloorTest's notice rule).
 *
 * Every "not present" assertion is made against a page shown to have rendered the chrome,
 * because `assertStringNotContainsString` over an empty body proves nothing.
 */
final class CookieConsentNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CookiePrefs::forget();
        DB::table('gates_settings')->where('key_name', 'like', 'visits_%')->delete();
    }

    private function page(array $cookies = [], array $session = [], array $headers = []): string
    {
        $res = ChromeRender::page('/_dev/ui?bar=root', $session, $cookies + ['ag_lang' => 'en'], 'GET', null, $headers);
        $html = (string) $res->getBody();
        $this->assertStringContainsString('class="ag-tabbar"', $html, 'the shell did not render, so nothing below means anything');
        return $html;
    }

    private function consent(?bool $p, ?bool $a): array
    {
        return [CookiePrefs::COOKIE => rawurlencode((string) json_encode(
            ['v' => CookiePrefs::VERSION, 'preferences' => $p, 'analytics' => $a, 'marketing' => null]))];
    }

    /** The notice's <section>, or '' if there is none. */
    private function notice(string $html): string
    {
        return preg_match('~<section class="ag-consent"[^>]*>.*?</section>~s', $html, $m) ? $m[0] : '';
    }

    private function sheet(string $html): string
    {
        return preg_match('~<div class="ag-consent-layer".*?</form>~s', $html, $m) ? $m[0] : '';
    }

    public function test_every_answer_on_the_notice_carries_the_identical_class_string(): void
    {
        $n = $this->notice($this->page());
        $this->assertNotSame('', $n, 'a first-time visitor was not shown the notice');

        preg_match_all('~<button\b([^>]*)>~', $n, $b);
        $this->assertCount(3, $b[1], 'the notice no longer offers exactly its three answers');

        $classes = array_map(static fn (string $a): string => preg_match('~class="([^"]*)"~', $a, $c) ? $c[1] : '', $b[1]);
        $this->assertSame([$classes[0], $classes[0], $classes[0]], $classes,
            '"Allow all" and "Essential" must look the same — accept as a button and decline as '
            . 'grey text is the pattern the consent rules exist to stop');

        // And nothing in the stylesheet singles one of them out by its value or position.
        $css = ChromeRender::code('public/assets/css/components/consent.css');
        $this->assertDoesNotMatchRegularExpression('~\.ag-consent__answer(?:\[|:(?:first|last|nth))~', $css);
    }

    public function test_the_notice_cannot_be_dismissed_without_answering_and_is_not_a_modal(): void
    {
        $n = $this->notice($this->page());

        // Every control in it answers (or opens the full choice): no close, no "later".
        preg_match_all('~<button\b[^>]*>~', $n, $b);
        foreach ($b[0] as $btn) {
            $this->assertMatchesRegularExpression('~name="answer" value="(all|essential|choose)"~', $btn,
                "a control on the notice does something other than answer: {$btn}");
        }
        $this->assertStringNotContainsString('data-ag-close', $n);
        $this->assertStringNotContainsString('aria-modal', $n, 'the notice is a region, not a dialog over the page');
        $this->assertStringContainsString('role="region"', $n);
    }

    public function test_neither_screen_needs_javascript(): void
    {
        $html = $this->page();
        foreach (['the notice' => $this->notice($html), 'the sheet' => $this->sheet($html)] as $which => $part) {
            $this->assertNotSame('', $part, "{$which} is not on the page");
            $this->assertMatchesRegularExpression('~<form\b[^>]*method="post"[^>]*action="/cookies/choice"~', $part,
                "{$which} depends on a script, so it is missing for anybody who blocks them");
            $this->assertStringContainsString('name="_token" value="test-token"', $part,
                "{$which} posts without the CSRF field CsrfMiddleware reads");
            $this->assertStringNotContainsString('onclick', $part);
        }

        // The sheet's switches are real checkboxes that post.
        $this->assertMatchesRegularExpression('~<input class="ag-consent-switch__input" type="checkbox" role="switch"[^>]*name="preferences" value="1"~',
            $this->sheet($html));

        // "Choose" reaches the sheet without a script: it posts `choose`, the server sends the
        // visitor to `#ag-consent`, and the stylesheet opens the sheet on `:target`.
        $this->assertStringContainsString('id="ag-consent"', $html);
        $this->assertStringContainsString('.ag-consent-layer:target > .ag-consent-sheet',
            ChromeRender::code('public/assets/css/components/consent.css'));
        $this->assertDoesNotMatchRegularExpression('~<div class="ag-sheet ag-consent-sheet"[^>]*\binert\b~', $html,
            'an inert sheet cannot be used by a browser without the script that lifts it');
    }

    public function test_the_notice_carries_the_page_it_was_drawn_on(): void
    {
        $this->assertStringContainsString('name="return" value="/_dev/ui"', $this->notice($this->page()),
            'answering would throw the visitor back to the home page — and the query string must never travel');
    }

    public function test_nothing_is_offered_to_a_browser_that_already_refused(): void
    {
        foreach (['Sec-GPC' => 'Global Privacy Control', 'DNT' => 'Do Not Track'] as $h => $words) {
            $html = $this->page([], [], [$h => '1']);
            $this->assertSame('', $this->notice($html), "the notice asked a browser that sent {$h}");

            $sheet = $this->sheet($html);
            $this->assertStringContainsString('Respected your browser’s privacy signal', $sheet);
            $this->assertStringContainsString($words, $sheet);
            $this->assertStringNotContainsString('type="checkbox"', $sheet,
                'a switch was offered that the signal would silently overrule');
        }
    }

    public function test_an_answer_ends_the_notice_and_the_press_is_confirmed_out_loud(): void
    {
        $html = $this->page($this->consent(false, false), ['consent_saved' => 'essential']);
        $this->assertSame('', $this->notice($html), 'the notice returned after an answer');
        $this->assertMatchesRegularExpression('~<div class="ag-consent-saved" role="status"[^>]*>\s*<span[^>]*>Saved\. Only essential cookies are on\.~',
            $html, 'the confirmation is invisible to a screen reader unless it is announced');
        // And it re-opens the choice, which is how somebody changes their mind.
        $this->assertMatchesRegularExpression('~class="ag-consent-saved__change" href="#ag-consent" data-ag-do="consent-open"~', $html);
    }

    public function test_the_sheet_draws_the_four_categories_and_marketing_has_no_switch(): void
    {
        $sheet = $this->sheet($this->page());
        foreach (['Essential', 'Preferences', 'Analytics', 'Marketing'] as $name) {
            $this->assertStringContainsString('>' . $name . '<', $sheet, "{$name} is missing from the sheet");
        }
        $this->assertStringContainsString('Always on', $sheet);
        $this->assertStringContainsString('Not used', $sheet);
        $this->assertStringNotContainsString('name="marketing"', $sheet,
            'a switch for something nothing on this site does changes nothing — it is a control lying about a mechanism');

        // Nothing switched on in advance except what is actually happening (Analytics under
        // the exempt posture, said in words on the notice).
        $this->assertDoesNotMatchRegularExpression('~name="preferences" value="1"[^>]*checked~', $sheet,
            'Preferences was ticked before anybody allowed it');
    }

    public function test_the_display_store_is_told_whether_it_may_outlive_the_tab(): void
    {
        $this->assertStringContainsString('data-ag-keep="0"', $this->page());
        $this->assertStringContainsString('data-ag-keep="1"', $this->page($this->consent(true, null)));
        $this->assertStringContainsString('data-ag-keep="0"', $this->page($this->consent(true, null), [], ['Sec-GPC' => '1']),
            'a browser signal must beat a stored yes for the display store too');
    }

    public function test_the_notice_cannot_hide_what_the_keyboard_is_on(): void
    {
        // WCAG 2.4.11. On a phone the notice is a flex child of the shell between the
        // scroller and the tab bar — it takes height from the scroller instead of floating
        // over it, so nothing on the page can be under it. From 600 it floats, and the
        // scroller keeps scroll room for it.
        $shell = ChromeRender::source('templates/layout/shell.twig');
        $main = strpos($shell, '</main>');
        $inc  = strpos($shell, "{% include 'partials/cookie-consent.twig' %}");
        $bar  = strpos($shell, '{% block bottom_bar %}');
        $this->assertTrue($main !== false && $inc !== false && $bar !== false && $main < $inc && $inc < $bar,
            'the notice must sit between the scroller and the tab bar, inside the shell');

        $rules = ChromeRender::rules(ChromeRender::code('public/assets/css/components/consent.css'));
        $phone = null; $room = null;
        foreach ($rules as [$sel, $body]) {
            if ($sel === '.ag-consent' && $phone === null) $phone = $body;
            if (str_contains($sel, '.ag-shell:has(> .ag-consent) > .ag-main')) $room = $body;
        }
        $this->assertNotNull($phone);
        $this->assertDoesNotMatchRegularExpression('~position\s*:\s*(fixed|absolute)~', (string) $phone,
            'on a phone the notice floats over the page again');
        $this->assertNotNull($room, 'from 600 the scroller keeps no room for the floating notice');
        $this->assertStringContainsString('scroll-padding-bottom', (string) $room);
    }
}
