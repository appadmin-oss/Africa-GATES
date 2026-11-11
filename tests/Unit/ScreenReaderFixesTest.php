<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PlannedWork;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * "Fix it" (owner, 11 Nov 2026), after the accessibility and screen-reader audit. Each test is
 * one finding, and where the finding was an instance of a RULE the test asserts the rule over
 * every template, because the audit found each of these on more pages than it first named.
 */
final class ScreenReaderFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    /** Every template, with Twig comments removed — a comment reaches nobody. */
    private static function templates(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.twig')) {
                $rel = substr($f->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
                $out[$rel] = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($f->getPathname()));
            }
        }
        return $out;
    }

    /**
     * A bar fixed to the bottom of a phone holds a flow's primary action — "Get tickets", the
     * ballot's progress, "Give". Outside every landmark, a screen reader moving by landmark
     * never reaches it, and one reading the page in order meets it after the footer. Each is
     * a named region now; `data-bottom-ui` is what marks one, so the sweep reads that.
     */
    public function test_every_bar_fixed_to_the_bottom_is_a_named_landmark(): void
    {
        $bad = [];
        foreach (self::templates() as $file => $src) {
            if (!preg_match_all('/<(\w+)\b[^>]*\bdata-bottom-ui\b[^>]*>/', $src, $m, PREG_SET_ORDER)) continue;
            foreach ($m as [$tag, $name]) {
                $named = (bool) preg_match('/\baria-label(?:ledby)?="[^"]+"/', $tag);
                $landmark = $name === 'nav' || (bool) preg_match('/\brole="(?:region|status)"/', $tag);
                if (!($named && $landmark) && !($name === 'nav' && $named) && !str_contains($tag, 'role="status"')) {
                    $bad[] = "$file: " . substr($tag, 0, 90);
                }
            }
        }
        $this->assertSame([], $bad, 'a bottom bar a screen reader moving by landmark cannot reach');
    }

    /**
     * `aria-pressed` makes a control a TOGGLE BUTTON. On a link it was announced "toggle
     * button, pressed" over something that navigates — the console's alert chips and review
     * views. A link marks where you are with `aria-current`, and only when it is.
     */
    public function test_no_link_says_it_is_a_toggle_button(): void
    {
        $bad = [];
        foreach (self::templates() as $file => $src) {
            if (preg_match_all('/<a\b[^>]*\baria-pressed\b[^>]*>/', $src, $m)) {
                foreach ($m[0] as $t) $bad[] = "$file: " . substr($t, 0, 90);
            }
        }
        $this->assertSame([], $bad);
        foreach (['alerts.css' => '.al2-chip[aria-current="true"]', 'review.css' => '.rq-view[aria-current="true"]'] as $f => $sel) {
            $this->assertStringContainsString($sel, ChromeRender::code('public/assets/css/console/' . $f), "$f lost the chosen chip's look");
        }
    }

    /**
     * On a phone the app bar draws the page's title as its `<h1>`. Results and Pulse ALSO
     * draw their own `<h1>`, so a screen reader listing headings heard the page named twice
     * at level one. The bar is a `<header>` (a banner landmark: it sat outside every one), and
     * a page with its own heading asks the bar for a paragraph.
     */
    public function test_one_page_has_one_level_one_heading_and_the_bar_is_a_banner(): void
    {
        foreach (['/results', '/pulse'] as $path) {
            $html = ChromeRender::html($path);
            $this->assertSame(1, preg_match_all('/<h1\b/', $html), "$path names itself twice at level one");
            $this->assertMatchesRegularExpression('/<header class="ag-appbar\b/', $html, "$path: the phone title outside every landmark");
        }
    }

    /**
     * Two "Hide this notice" buttons on one page are two controls a screen reader lists with
     * one name, and nothing to say which notice either removes.
     */
    public function test_each_hide_button_names_its_own_notice(): void
    {
        foreach ([PlannedWork::KEYS['title'] => 'Card payments',
                  PlannedWork::KEYS['from'] => Carbon::now()->addHour()->toDateTimeString(),
                  PlannedWork::KEYS['to'] => Carbon::now()->addHours(3)->toDateTimeString(),
                  PlannedWork::KEYS['close'] => '0',
                  'announce_text' => 'Voting closes on Friday'] as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
        $html = ChromeRender::html('/privacy');
        preg_match_all('/class="ag-notice__b" aria-label="([^"]+)"/', $html, $m);
        $this->assertCount(2, $m[1], 'both notices should be drawn');
        $this->assertCount(2, array_unique($m[1]), 'two hide buttons with one name');
    }

    /**
     * The skip link pointed at `<main>`, and focus there lands on a scroll container a
     * screen reader announces as nothing. It goes to the page's heading now, and so does an
     * in-place navigation — the same place, so arriving by a link and skipping agree.
     */
    public function test_skipping_and_arriving_both_land_on_the_heading(): void
    {
        $shell = ChromeRender::code('public/assets/js/shell.js');
        $this->assertStringContainsString('AGShell.pageHeading()', ChromeRender::code('public/assets/js/accessibility-focus.js'), 'the skip link goes to <main>');
        $this->assertMatchesRegularExpression('/pageHeading\s*:\s*pageHeading/', $shell, 'site-nav has nothing to call');
        $this->assertStringContainsString('AGShell.pageHeading', ChromeRender::code('public/assets/js/site-nav.js'));
        $this->assertStringContainsString('data-skip-link', ChromeRender::html('/privacy'));
    }

    /** A form that fails offline failed silently; the page now says so while it lasts. */
    public function test_going_offline_is_said_on_the_page(): void
    {
        $shell = ChromeRender::code('public/assets/js/shell.js');
        $this->assertStringContainsString("'offline'", $shell);
        $this->assertStringContainsString('role', $shell);
        $this->assertMatchesRegularExpression('/data-ag-offline-text="[^"]+"/', ChromeRender::html('/privacy'));
        $this->assertStringContainsString('.ag-offline', ChromeRender::code('public/assets/css/components/notice.css'));
    }

    /**
     * WCAG 2.2.2: the hero turned every 5.5 seconds with no way to stop it except hovering,
     * which nobody on a phone can do — and its card was a polite live region, so a screen
     * reader was interrupted with a new name on every turn.
     */
    public function test_the_hero_can_be_paused_and_does_not_talk_over_the_reader(): void
    {
        $tpl = ChromeRender::code('templates/pages/home.twig');
        $this->assertStringContainsString('data-hm-pause', $tpl);
        $this->assertStringContainsString('aria-live="off" data-hm-live', $tpl);
        $js = ChromeRender::code('public/assets/js/home.js');
        $this->assertMatchesRegularExpression('/if \(reduce \|\| hover \|\| held \|\| document\.hidden\) return;/', $js, 'pausing does not stop the timer');
        $this->assertStringContainsString("quiet(true); show(i + 1)", $js, 'the timer still speaks');
    }

    /**
     * A refusal on the event card was one alert line under three boxes: "Enter your email",
     * and no box marked. It names its field now — invalid, described, focused.
     */
    public function test_a_refused_field_is_marked_on_the_event_card_and_the_gift_form(): void
    {
        $js = ChromeRender::code('public/assets/js/event-detail.js');
        $this->assertStringContainsString("bad('[data-ed-email]', T('email'))", $js);
        $this->assertMatchesRegularExpression("/setAttribute\('aria-invalid', 'true'\);\s*el\.setAttribute\('aria-describedby', err\.id\)/", $js);
        $this->assertStringContainsString('.ed-input[aria-invalid="true"]', ChromeRender::code('public/assets/css/components/events.css'));

        // The gift form gets the shared validator's per-field messages; the busy label waits
        // until every submit listener has had its say.
        $this->assertMatchesRegularExpression('/<form class="gv-co"[^>]*novalidate data-ag-validate/', ChromeRender::code('templates/pages/donate.twig'));
        $this->assertMatchesRegularExpression('/setTimeout\(function \(\) \{\s*if \(e\.defaultPrevented\) return;/', ChromeRender::code('public/assets/js/giving.js'));
        $this->assertStringContainsString('novalidate data-ag-validate', ChromeRender::code('templates/pages/community/new-thread.twig'));
    }

    /** Smaller findings, each one line. */
    public function test_the_small_findings_hold(): void
    {
        // A link in a sentence is told apart by more than colour (1.4.1).
        $this->assertStringContainsString('.chl__empty > span a { text-decoration: underline', ChromeRender::code('public/assets/css/components/challenge.css'));
        // A note inside an article is part of it, not a complementary landmark of its own.
        $this->assertStringNotContainsString('<aside class="dp-note">', ChromeRender::code('templates/partials/article.twig'));
        // The homepage tiles read their name once, not "Kemi Adeyemi, Kemi Adeyemi".
        $home = ChromeRender::code('templates/pages/home.twig');
        $this->assertStringNotContainsString('alt="{{ t.alt }}"', $home);
        $this->assertStringContainsString('<h2 class="hm-ctr__h">', $home);
    }
}
