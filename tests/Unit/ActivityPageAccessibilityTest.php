<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\ActivityController;
use AfricaGates\Services\ActivityFeedService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * The activity search's accessibility contract, asserted on rendered HTML.
 *
 * Two things are being pinned, and the first is the one that usually gets skipped.
 *
 * IT WORKS WITHOUT JAVASCRIPT. The search is a real `<form method="get">` whose
 * results are rendered server-side. That is not a courtesy: a large share of this
 * platform's traffic is low-end Android on an intermittent connection, where a script
 * that failed to arrive is a routine event rather than an edge case, and a search box
 * that is only a script is a search box that is sometimes simply absent. So the
 * no-JS path is tested first and the enhancement second.
 *
 * THE COMBOBOX ATTRIBUTES ARE ADDED BY SCRIPT, NOT PRESENT IN THE MARKUP. This is
 * deliberate and is itself worth a test. Announcing `role="combobox"` on an input that
 * has no listbox behaviour — because the script never ran — tells a screen reader
 * there is a popup to arrow into when there is not. That is worse than the plain
 * input it replaced, so the markup must describe a plain input and the script must
 * upgrade it.
 */
class ActivityPageAccessibilityTest extends TestCase
{
    private function render(string $query = ''): string
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $builder->build();

        $controller = new ActivityController($c->get(Twig::class), new ActivityFeedService());
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/activity' . ($query !== '' ? '?q=' . urlencode($query) : ''))
            ->withQueryParams($query !== '' ? ['q' => $query] : []);

        return (string) $controller->index($req, new Response())->getBody();
    }

    private function seed(): void
    {
        DB::table('gates_nominees')->insert([
            'id' => 4242, 'category_id' => 1, 'name' => 'Adaeze Nwankwo', 'status' => 'approved',
            'vote_count' => 0, 'nominated_at' => Carbon::now()->subMinutes(3)->toDateTimeString(),
        ]);
    }

    // ── Works with no JavaScript ─────────────────────────────────────────────

    public function test_the_search_is_a_real_get_form_that_submits_to_a_real_route(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '~<form[^>]+method="get"[^>]+action="/activity"~i',
            (string) preg_replace('~\s+~', ' ', $html),
            'the search must submit without JavaScript'
        );
        $this->assertStringContainsString('name="q"', $html, 'the query must be a real form field');
    }

    public function test_results_are_rendered_server_side(): void
    {
        // The assertion that the no-JS path actually WORKS rather than merely existing.
        $this->seed();
        $html = $this->render('Adaeze');

        $this->assertStringContainsString('Adaeze Nwankwo', $html,
            'a ?q= request must return results in the HTML, not an empty shell for a script to fill');
    }

    public function test_the_form_carries_the_search_landmark(): void
    {
        $this->assertStringContainsString('role="search"', $this->render());
    }

    public function test_the_input_has_a_real_visible_label_not_only_a_placeholder(): void
    {
        // A placeholder disappears the moment you type, is low-contrast by default, and
        // is not reliably announced. It is not a label.
        $html = $this->render();

        $this->assertMatchesRegularExpression('~<label[^>]+for="actQ"~', $html);
        $this->assertMatchesRegularExpression('~id="actQ"~', $html);
        $this->assertStringContainsString('placeholder=', $html, 'a placeholder as well is fine');
    }

    // ── The combobox is added by script, not asserted in markup ──────────────

    public function test_the_markup_does_not_claim_to_be_a_combobox(): void
    {
        // If the script does not run, `role="combobox"` would promise a popup that
        // cannot be opened and options that cannot be reached. A plain input that
        // behaves like a plain input is the honest degraded state.
        //
        // Script and style blocks are stripped before scanning. The script EXPLAINS
        // this rule in a comment — "role=\"combobox\" on an input with no listbox
        // behaviour would be a lie" — so scanning the whole page flagged the very
        // prose that documents the decision.
        $markup = $this->markupOnly($this->render());

        $this->assertStringNotContainsString('role="combobox"', $markup);
        $this->assertStringNotContainsString('aria-activedescendant', $markup);
        $this->assertStringNotContainsString('role="listbox"', $markup);
    }

    /** The page with every &lt;script&gt; and &lt;style&gt; block removed. */
    private function markupOnly(string $html): string
    {
        return (string) preg_replace(
            ['~<script\b[^>]*>.*?</script>~is', '~<style\b[^>]*>.*?</style>~is'],
            '',
            $html
        );
    }

    public function test_the_script_declares_the_full_combobox_contract(): void
    {
        // Each of these is load-bearing for a screen-reader user: expanded state,
        // which element the popup is, that completions are a list, and which option is
        // current. A partial set is a combobox that announces itself and then cannot
        // be navigated.
        $html = $this->render();
        foreach ([
            "setAttribute('role', 'combobox')",
            "setAttribute('aria-expanded'",
            "setAttribute('aria-controls'",
            "setAttribute('aria-autocomplete', 'list')",
            "setAttribute('role', 'listbox')",
            'aria-activedescendant',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing: {$needle}");
        }
    }

    public function test_the_active_option_is_tracked_without_moving_focus(): void
    {
        // The classic way this pattern breaks. Calling focus() on an option takes focus
        // out of the input, so the next keystroke goes nowhere and the query can no
        // longer be edited. aria-activedescendant exists precisely to avoid that.
        $html = $this->render();

        $this->assertStringContainsString('aria-activedescendant', $html);
        $this->assertDoesNotMatchRegularExpression('~opts\[\w+\]\.focus\(\)~', $html,
            'the active option must not be focused — use aria-activedescendant');
    }

    public function test_every_key_the_pattern_requires_is_handled(): void
    {
        $html = $this->render();
        foreach (["'ArrowDown'", "'ArrowUp'", "'Home'", "'End'", "'Enter'", "'Escape'"] as $key) {
            $this->assertStringContainsString($key, $html, "keyboard support missing for {$key}");
        }
    }

    public function test_enter_with_nothing_highlighted_still_submits_the_form(): void
    {
        // Someone who types and presses Enter must get a search. Swallowing Enter to
        // "handle it in JS" is how a live search becomes unusable for anyone who does
        // not arrow into the list first — and it breaks the no-JS path's muscle memory.
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '~case\s+\'Enter\':.*?if\s*\(active\s*>=\s*0~s',
            $html,
            'preventDefault on Enter must be conditional on an option being active'
        );
    }

    public function test_escape_closes_before_it_clears(): void
    {
        // Clearing the box on the first Escape destroys a query someone is still
        // working on. Close, then clear.
        $html = $this->render();
        $this->assertMatchesRegularExpression(
            "~case\s+'Escape':.*?aria-expanded.*?else if\s*\(input\.value~s",
            $html,
            'the first Escape must close the list and only a second one clear the input'
        );
    }

    // ── Announcement ─────────────────────────────────────────────────────────

    public function test_there_is_a_polite_status_region_that_exists_before_it_has_content(): void
    {
        // A live region inserted at the same moment as its content is frequently not
        // announced at all, because the browser has nothing to compare against. The
        // element must already be in the DOM.
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '~<p[^>]+id="actStatus"[^>]+role="status"[^>]+aria-live="polite"~',
            (string) preg_replace('~\s+~', ' ', $html)
        );
        $this->assertStringContainsString('aria-atomic="true"', $html,
            'the whole sentence must be re-read, not the changed word alone');
    }

    public function test_the_status_line_is_visible_text_and_not_only_announced(): void
    {
        // Same information to everyone. A visually-hidden count means a sighted
        // keyboard user has to infer the result count from the list.
        $this->seed();
        $html = $this->render('Adaeze');

        $this->assertMatchesRegularExpression('~id="actStatus"[^>]*>\s*1 result~', $html);
        $this->assertDoesNotMatchRegularExpression('~id="actStatus"[^>]*class="[^"]*sr-only~', $html);
    }

    public function test_the_result_list_is_not_itself_a_live_region(): void
    {
        // A live region wrapping the whole list makes a screen reader read every item
        // on every keystroke, which is unusable. The count is announced instead.
        $html = (string) preg_replace('~\s+~', ' ', $this->render());

        $this->assertDoesNotMatchRegularExpression('~<ul[^>]+id="actResults"[^>]+aria-live~', $html);
    }

    public function test_a_count_of_one_is_not_pluralised(): void
    {
        $this->seed();
        $this->assertStringContainsString('1 result for', $this->render('Adaeze'));
        $this->assertStringNotContainsString('1 results for', $this->render('Adaeze'));
    }

    public function test_an_empty_result_explains_what_to_try_instead(): void
    {
        // "No results" is a dead end. Naming what the box searches turns it into a
        // next step.
        $html = $this->render('zzzzznothingmatchesthis');

        $this->assertStringContainsString('Nothing matches', $html);
        $this->assertMatchesRegularExpression('~name|category|country~i', $html);
    }

    // ── Presentation that is an accessibility requirement ────────────────────

    public function test_focus_is_visible_on_every_interactive_element(): void
    {
        // outline:none with no replacement is the single most common keyboard failure.
        $html = $this->render();

        $this->assertStringContainsString(':focus-visible', $html);
        $this->assertMatchesRegularExpression('~outline:\s*3px~', $html);
    }

    public function test_the_keyboard_active_state_is_styled_and_not_only_hover(): void
    {
        // A keyboard user never hovers. Styling only :hover leaves them unable to see
        // which option they are on, even with aria-activedescendant set correctly.
        $html = $this->render();

        $this->assertStringContainsString('[data-active="true"]', $html);
    }

    public function test_reduced_motion_is_respected_without_hiding_the_busy_state(): void
    {
        // The spinner becomes a static ring rather than vanishing, so someone who
        // cannot tolerate the animation can still tell a search is running.
        $html = $this->render();

        $this->assertStringContainsString('prefers-reduced-motion', $html);
        $this->assertMatchesRegularExpression('~prefers-reduced-motion[^}]*animation:\s*none~s', $html);
    }

    public function test_touch_targets_meet_the_minimum_size(): void
    {
        // WCAG 2.5.8. The difference between a usable and an infuriating search box on
        // a phone, which is how most of this audience arrives.
        $html = $this->render();

        $this->assertMatchesRegularExpression('~min-height:\s*4[4-9]px~', $html);
    }

    public function test_a_timestamp_is_machine_readable_as_well_as_human_readable(): void
    {
        $this->seed();
        $html = $this->render('Adaeze');

        $this->assertMatchesRegularExpression('~<time[^>]+datetime="\d{4}-\d{2}-\d{2}[^"]*"~', $html);
        $this->assertMatchesRegularExpression('~(just now|minute)~', $html);
    }

    public function test_the_page_has_exactly_one_h1_and_it_names_the_page(): void
    {
        $html = $this->render();

        $this->assertSame(1, preg_match_all('~<h1\b~', $html), 'exactly one h1');
        $this->assertMatchesRegularExpression('~<h1[^>]*>\s*Activity\s*</h1>~', $html);
        $this->assertStringContainsString('aria-labelledby="actHeading"', $html,
            'the region must be named by its own heading');
    }

    public function test_every_inline_block_carries_the_csp_nonce(): void
    {
        // The page would render and do nothing at all otherwise — a nonce-less inline
        // script is refused, silently, with the failure visible only in a console.
        $html = $this->render();

        preg_match_all('~<(script|style)\b[^>]*>~i', $html, $m);
        $this->assertNotSame([], $m[0]);
        foreach ($m[0] as $tag) {
            if (preg_match('~\ssrc=~i', $tag)) continue;   // external, governed by host allowlist
            $this->assertStringContainsString('nonce=', $tag, "un-nonced inline block: {$tag}");
        }
    }

    public function test_the_query_is_escaped_where_it_is_echoed_back(): void
    {
        // The query is reflected into the status line, the input value, the empty-state
        // message and the <title>. Four reflection points on one page.
        $html = $this->render('<img src=x onerror=alert(1)>');

        // What matters is that no TAG is formed. `onerror=alert` survives escaping as
        // a literal substring — none of its characters are escapable — so asserting
        // its absence would report a vulnerability on correctly-escaped output. That
        // exact mistake produced a false positive in an earlier XSS sweep here.
        $payload = '<img src=x onerror=alert(1)>';

        // The precise property: the payload never appears VERBATIM. Escaping turns
        // `<` into `&lt;`, so a verbatim occurrence is the only way a tag could have
        // been formed — and unlike a scan for `onerror=`, it cannot fire on inert
        // text. A pattern like `<[a-z]+[^>]*onerror=` DOES fire here, on the input
        // element whose `value` legitimately contains the escaped query, because
        // `[^>]*` walks straight past `&gt;`. That is the escaped-output false
        // positive an earlier XSS sweep already produced once.
        $this->assertStringNotContainsString($payload, $html,
            'the query must never be reflected unescaped');
        $this->assertStringNotContainsString('<img ', $html, 'no tag may be formed from the query');
        $this->assertStringContainsString('&lt;img', $html, 'escaped, not stripped');

        // Every reflection point, since the query reaches four of them.
        $this->assertSame(0, preg_match('~<title>[^<]*<img~', $html), 'the <title>');
        $this->assertStringContainsString('value="&lt;img', $html, 'the input value');
        $this->assertMatchesRegularExpression('~id="actStatus"[^>]*>[^<]*&lt;img~', $html, 'the status line');
    }
}
