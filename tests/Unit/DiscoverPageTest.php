<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\Discover;
use AfricaGates\Services\SitemapService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Views\Twig;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * `/discover` (Phase 4) — and `/activity`, which it absorbed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ACTIVITY PAGE'S RULES, RE-ASSERTED AGAINST THE PAGE THAT REPLACED IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `ActivityPageAccessibilityTest` held twenty-four rules about the timeline and was
 * destroyed with its page (inventory: pages--activity.md, "Rules held by guard tests
 * destroyed with this page"). The timeline now lives in Discover's Live tab, and every
 * rule that still has a subject is held here against the new markup: a real GET form, the
 * results in the HTML, a combobox declared only by the script, `aria-activedescendant`,
 * the status line as the ONLY live region and the list never one, a count of one in the
 * singular, an empty result that says what to try, the query escaped where it is echoed,
 * every row a real link with a machine-readable time.
 *
 * And what §8.23 adds: `/activity` is a 301 that keeps `q` and `literal` and nothing else;
 * Live is the second tab; the kind chips are the seven named, and one with no source says
 * so rather than listing something else under its word; "Show older updates" is real
 * pagination; nothing from the sandbox reaches the page; Activity is in no nav and the
 * Live tab is in the sitemap.
 *
 * Every test here was watched failing against a planted break before it was trusted
 * (docs/handoff/PHASE-4.md, "Discover", Tests).
 */
final class DiscoverPageTest extends TestCase
{
    private function get(string $uri): \Psr\Http\Message\ResponseInterface
    {
        return ChromeRender::page($uri);
    }

    private function html(string $uri): string
    {
        $res = $this->get($uri);
        $this->assertSame(200, $res->getStatusCode(), "$uri did not answer 200");

        return (string) $res->getBody();
    }

    /** The page's own content — <main> with the chrome around it removed. */
    private function main(string $html): string
    {
        $a = strpos($html, '<main');
        $b = strpos($html, '</main>');
        $this->assertNotFalse($a);

        return substr($html, (int) $a, (int) $b - (int) $a);
    }

    /** One section of the page — every tab's sections are in the HTML; CSS draws one. */
    private function section(string $main, string $key): string
    {
        $this->assertMatchesRegularExpression('~<section class="dv-sec dv-only dv-only--' . $key . '"(.*?)</section>~s', $main);
        preg_match('~<section class="dv-sec dv-only dv-only--' . $key . '"(.*?)</section>~s', $main, $m);

        return $m[1];
    }

    private function timeline(string $main): string
    {
        preg_match('~<ul class="dv-list"(.*?)</ul>~s', $main, $m);
        $this->assertNotEmpty($m, 'the timeline list is on the page');

        return $m[1];
    }

    /** An active programme with a cycle open for nominations and one category. */
    private function seedAward(): int
    {
        $now = Carbon::now();
        DB::table('gates_award_programmes')->insert(['id' => 211, 'slug' => 'dv-awards', 'title' => 'Savanna Teaching Awards', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 2211, 'programme_id' => 211, 'year' => 2026, 'edition_label' => '2nd Edition',
            'status' => 'nominations', 'nominations_open' => $now->copy()->subDays(5)->toDateTimeString(),
            'nominations_close' => $now->copy()->addDays(3)->toDateTimeString(),
            'voting_open' => $now->copy()->addDays(10)->toDateTimeString(), 'voting_close' => $now->copy()->addDays(20)->toDateTimeString()]);
        DB::table('gates_award_categories')->insert(['id' => 22111, 'cycle_id' => 2211, 'slug' => 'teacher', 'title' => 'Teacher of the Year']);

        return 22111;
    }

    private function nominee(int $cat, string $name, string $cc = 'NG', ?string $at = null, int $id = 0): int
    {
        $row = ['category_id' => $cat, 'name' => $name, 'country_code' => $cc, 'status' => 'approved',
                'nominated_at' => $at ?? Carbon::now()->subMinutes(5)->toDateTimeString()];
        if ($id) $row['id'] = $id;

        return (int) DB::table('gates_nominees')->insertGetId($row);
    }

    // ── /activity is retired into the Live tab ─────────────────────────────────

    public function test_activity_is_a_301_to_the_live_tab_keeping_only_q_and_literal(): void
    {
        $res = $this->get('/activity?q=choral%20night&literal=1&utm_source=x&tab=people');
        $this->assertSame(301, $res->getStatusCode(), 'a retired page is a 301 — never "not right now"');
        $this->assertSame('/discover?tab=live&q=choral%20night&literal=1', $res->getHeaderLine('Location'));

        $bare = $this->get('/activity');
        $this->assertSame(301, $bare->getStatusCode());
        $this->assertSame('/discover?tab=live', $bare->getHeaderLine('Location'));

        $this->assertSame('/discover?tab=live&q=x', $this->get('/activity?q=x')->getHeaderLine('Location'));
    }

    public function test_discover_is_the_page_and_no_longer_a_redirect(): void
    {
        $res = $this->get('/discover');
        $this->assertSame(200, $res->getStatusCode(), '/discover was a 302 to /registry until Phase 4');
        $this->assertStringContainsString('<h1 class="dv__h1">Discover</h1>', (string) $res->getBody());
    }

    public function test_activity_is_in_no_nav_and_the_live_tab_is_in_the_sitemap(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach ([$root . '/templates', $root . '/public/assets/js'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
                if (!$f->isFile() || !preg_match('/\.(twig|js)$/', $f->getFilename())) continue;
                if (str_contains($f->getPathname(), '/vendor/') || str_contains($f->getPathname(), '/admin/')) continue;
                // Comments stripped: a comment reaches nobody, and the partial's own
                // docblock rightly says what `/activity` used to be.
                $body = (string) preg_replace(['/\{#.*?#\}/s', '~/\*.*?\*/~s'], '', (string) file_get_contents($f->getPathname()));
                if (preg_match('~["\'`]/activity(?![a-z/-])~', $body)) {
                    $hits[] = str_replace($root . '/', '', $f->getPathname());
                }
            }
        }
        $this->assertSame([], $hits, 'something still links the retired /activity page');

        $paths = array_column((new SitemapService())->urls('core'), 'path');
        $this->assertContains('/discover', $paths);
        $this->assertContains('/discover?tab=live', $paths);
        $this->assertNotContains('/activity', $paths);
    }

    // ── The search: a real form, results in the HTML ───────────────────────────

    public function test_the_search_is_a_real_get_form_with_a_real_label(): void
    {
        $main = $this->main($this->html('/discover'));
        $this->assertMatchesRegularExpression('~<form class="dv__form" method="get" action="/discover" role="search"~', $main);
        $this->assertMatchesRegularExpression('~<input class="dv__q" id="dvQ" type="search" name="q"~', $main);
        $this->assertMatchesRegularExpression('~<label class="dv__field" for="dvQ">.*?<span class="ag-sr">Search Africa GATES</span>~s', $main,
            'a placeholder is not a label');
        $this->assertSame(1, substr_count($main, 'name="q" value'), 'one search field on the page, never a second');
    }

    public function test_results_are_rendered_server_side_and_every_row_is_a_real_link(): void
    {
        $this->nominee($this->seedAward(), 'Zanele Mokoena');
        $main = $this->main($this->html('/discover?tab=live&q=Zanele'));

        $this->assertMatchesRegularExpression(
            '~<a class="dv-row" href="[^"]+" id="dv-r1">.*?<time class="dv-row__when" datetime="\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d">[^<]+</time>.*?Zanele Mokoena~s',
            $main, 'a ?q= request returns its rows in the HTML, each a link with a machine-readable time');
    }

    public function test_the_status_line_is_the_only_live_region_and_the_list_is_never_one(): void
    {
        $this->nominee($this->seedAward(), 'Zanele Mokoena');
        $main = $this->main($this->html('/discover?tab=live&q=Zanele'));

        preg_match_all('~<[a-z]+[^>]*(?:aria-live=|role="(?:status|alert|log)")[^>]*>~', $main, $live);
        $this->assertCount(1, $live[0], 'exactly one live region on the page');
        $this->assertStringContainsString('id="dvStatus" role="status" aria-live="polite" aria-atomic="true"', $live[0][0]);

        $this->assertMatchesRegularExpression('~<ul class="dv-list" id="dvLiveList"(?![^>]*aria-live)[^>]*>~', $main);
        $this->assertMatchesRegularExpression('~<p class="dv-live__status"[^>]*>\s*1 result for “Zanele”\s*</p>~', $main,
            'the status line is visible words, and a count of one is singular');
    }

    public function test_an_empty_result_says_what_to_try_and_echoes_the_query_escaped(): void
    {
        $main = $this->main($this->html('/discover?tab=live&q=' . rawurlencode('<b>zz"q</b>')));
        $this->assertStringContainsString('Nothing matches “&lt;b&gt;zz&quot;q&lt;/b&gt;” yet. Try a nominee’s name, an award category or a country.', $main);
        $this->assertStringNotContainsString('<b>zz', $main, 'the query must never be reflected unescaped');
    }

    // ── The combobox is the script's, never the markup's ───────────────────────

    public function test_the_markup_does_not_claim_to_be_a_combobox_and_the_script_declares_the_whole_contract(): void
    {
        $main = $this->main($this->html('/discover?tab=live'));
        $this->assertStringNotContainsString('role="combobox"', $main, 'without the script it is a plain search field');
        $this->assertStringNotContainsString('role="listbox"', $main);

        $js = ChromeRender::code('public/assets/js/discover.js');
        foreach (["'role', 'combobox'", "'aria-controls', 'dvLiveList'", "'aria-autocomplete', 'list'",
                  "'aria-expanded'", "'aria-activedescendant'", "'role', 'listbox'", "'role', 'option'"] as $needle) {
            $this->assertStringContainsString($needle, $js, "missing: $needle");
        }
        foreach (['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', 'Escape'] as $key) {
            $this->assertStringContainsString("case '$key'", $js, "keyboard support missing for $key");
        }
        // The active option is tracked, never focused — focus stays in the field.
        preg_match('~function setActive\(i\) \{(.*?)\n  \}~s', $js, $set);
        $this->assertNotEmpty($set);
        $this->assertStringNotContainsString('.focus(', $set[1], 'the active option must not be focused — use aria-activedescendant');
        // Enter with nothing highlighted still submits; the first Escape closes, a second clears.
        $this->assertMatchesRegularExpression("~case 'Enter':.*?if \(active >= 0 && opts\[active\]\) \{ e\.preventDefault\(\)~s", $js);
        $this->assertMatchesRegularExpression("~case 'Escape':.*?if \(active >= 0 \|\| input\.getAttribute\('aria-expanded'\) === 'true'\).*?\} else if \(input\.value !== ''\)~s", $js);
        // Armed on the Live tab only, where the list IS the result list.
        $this->assertStringContainsString("combobox(tab() === 'live')", $js);
    }

    public function test_the_keyboard_active_option_is_styled_and_not_only_hovered(): void
    {
        $css = ChromeRender::code('public/assets/css/components/discover.css');
        $this->assertMatchesRegularExpression('~\.dv-list__i\[data-active="true"\] > \.dv-row\{[^}]*outline:~', $css);
        $this->assertStringNotContainsString('@keyframes', $css,
            'nothing here animates; a busy list dims (aria-busy) so reduced motion loses nothing');
        $this->assertMatchesRegularExpression('~\.dv-live\[aria-busy="true"\] \.dv-list\{[^}]*opacity~', $css);
    }

    // ── What activity.twig said around the rows ────────────────────────────────

    private function partial(array $s, array $live): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        return $b->build()->get(Twig::class)->fetch('partials/discover-live.twig',
            ['s' => array_merge(Discover::state([]), $s), 'live' => $live + [
                'rows' => [], 'more' => false, 'deepest' => false, 'page' => 1, 'sources' => 7, 'asked' => 7,
                'understood' => null, 'unsourced' => false], 'min_query' => 2]);
    }

    public function test_understood_as_carries_its_way_out_and_literal_carries_its_way_back(): void
    {
        $html = $this->partial(['tab' => 'live', 'q' => 'winners in kenya'], ['understood' => ['Results', 'Kenya']]);
        $this->assertMatchesRegularExpression('~<b class="dv-read__t">Understood as</b>\s*<span class="dv-read__chip">Results</span><span class="dv-read__chip">Kenya</span>\s*'
            . '<a class="dv-read__go" href="/discover\?tab=live&amp;q=winners%20in%20kenya&amp;literal=1">Search these words literally instead</a>~', $html);

        $lit = $this->partial(['tab' => 'live', 'q' => 'winners', 'literal' => true], []);
        $this->assertMatchesRegularExpression('~<b class="dv-read__t">Literal\.</b> Matching your words exactly\.\s*'
            . '<a class="dv-read__go" href="/discover\?tab=live&amp;q=winners">Let the search interpret it</a>~', $lit);

        // Not on the compact All tab, which the DC draws without either line.
        $this->assertStringNotContainsString('Understood as', $this->partial(['q' => 'x'], ['understood' => ['Results']]));
    }

    public function test_a_partial_read_says_how_many_sources_answered_out_of_how_many_were_asked(): void
    {
        $this->assertStringContainsString('Some activity sources are unavailable on this deployment (4 of 9 responded)',
            $this->partial(['tab' => 'live'], ['sources' => 4, 'asked' => 9]));
        $this->assertStringNotContainsString('unavailable', $this->partial(['tab' => 'live'], []));

        // The denominator is what was ASKED, read from the index — never a typed 7.
        $t = (new ActivityFeedService())->timeline('', false, [], null);
        $this->assertSame(count(ActivityFeedService::datedKinds()), $t['asked']);
        $this->assertSame($t['asked'], $t['sources'], 'every source answers on a healthy install');
    }

    // ── Tabs, chips, pagination ─────────────────────────────────────────────────

    public function test_live_is_the_second_tab_and_the_tablist_is_drawn_twice_with_one_exposed(): void
    {
        $main = $this->main($this->html('/discover?tab=live'));
        preg_match('~<div class="dv__tabs" role="tablist"[^>]*>(.*?)</div>~s', $main, $row);
        preg_match_all('~data-dv-tab="([a-z]+)"~', $row[1], $keys);
        $this->assertSame(['all', 'live', 'people', 'orgs', 'awards', 'results', 'events'], $keys[1]);
        $this->assertMatchesRegularExpression('~id="dvTab-live" aria-controls="dvPanel"\s*aria-selected="true"\s*tabindex="0"~', $main);

        // The docked copy starts hidden from assistive technology and out of the tab order.
        preg_match('~<span class="dv__dock" data-dv-dock aria-hidden="true">(.*?)</span>\s*</span>~s', $main, $dock);
        $this->assertNotEmpty($dock, 'the docked copy is in the DOM, aria-hidden at rest');
        $this->assertSame(7, substr_count($dock[1], 'tabindex="-1"'));
        $this->assertStringNotContainsString('id="dvTab-', $dock[1], 'one copy owns the ids');
    }

    public function test_the_tabs_dock_beside_filters_on_the_shell_scroll_threshold(): void
    {
        $css = ChromeRender::code('public/assets/css/components/discover.css');
        $this->assertMatchesRegularExpression('~\.dv__bar\[data-scrolled\] \.dv__tabrow\{ grid-template-rows:0fr; opacity:0; margin-top:0 \}~', $css);
        $this->assertMatchesRegularExpression('~\.dv__bar\[data-scrolled\] \.dv__dock\{ max-width:900px; opacity:1 \}~', $css);
        $this->assertMatchesRegularExpression('~\.dv__tabrow\{[^}]*transition:grid-template-rows var\(--ag-dur-2\) var\(--ag-ease\)~', $css);
        $this->assertMatchesRegularExpression('~\.dv__dock\{[^}]*max-width:0;[^}]*transition:max-width \.3s var\(--ag-ease\)~', $css);
        // The bar is the shell's sticky block, so shell.js's one threshold drives it.
        $this->assertStringContainsString('class="ag-sticky dv__bar"', $this->html('/discover'));
        $this->assertStringContainsString('syncTabStops', ChromeRender::code('public/assets/js/discover.js'));
    }

    public function test_the_kind_chips_are_the_seven_and_a_kind_with_no_source_says_so(): void
    {
        $this->nominee($this->seedAward(), 'Zanele Mokoena');
        $main = $this->main($this->html('/discover?tab=live'));
        preg_match('~role="toolbar" aria-label="Kind of update">(.*?)</div>~s', $main, $bar);
        preg_match_all('~</span>([A-Za-z]+)</button>~', $bar[1], $labels);
        $this->assertSame(['Everything', 'Results', 'Nominations', 'Voting', 'Events', 'Stories', 'Recognitions'], $labels[1]);
        $this->assertMatchesRegularExpression('~name="kind" value="all" class="dv-kind" aria-pressed="true"~', $main);

        $rec = $this->main($this->html('/discover?tab=live&kind=recognition'));
        $this->assertStringContainsString('No recognitions are recorded on Africa GATES yet.', $rec);
        $this->assertStringNotContainsString('Zanele', $this->timeline($rec), 'nothing else may be listed under the word');
    }

    public function test_show_older_updates_is_real_pagination_that_works_without_a_script(): void
    {
        $cat = $this->seedAward();
        for ($i = 1; $i <= 25; $i++) {
            $this->nominee($cat, sprintf('Paged Nominee %02d', $i), 'NG', Carbon::now()->subMinutes($i)->toDateTimeString());
        }
        $one = $this->main($this->html('/discover?tab=live&kind=nomination'));
        $this->assertSame(ActivityFeedService::TIMELINE_PER, substr_count($one, 'class="dv-row"'));
        $this->assertStringContainsString('<a class="dv-older" href="/discover?tab=live&amp;kind=nomination&amp;page=2#dv-r21"', $one);

        $two = $this->main($this->html('/discover?tab=live&kind=nomination&page=2'));
        $this->assertSame(25, substr_count($two, 'class="dv-row"'), 'page 2 keeps what was above it');
        $this->assertStringContainsString('id="dv-r21"', $two);
        $this->assertStringNotContainsString('Show older updates', $two, 'no link to a page that has nothing on it');
    }

    // ── Sections, filters, the sandbox ─────────────────────────────────────────

    public function test_the_filters_are_a_get_form_and_each_applied_filter_is_a_link_that_removes_it(): void
    {
        $cat = $this->seedAward();
        $this->nominee($cat, 'Ngozi Ade', 'NG');
        $this->nominee($cat, 'Wanjiru Kamau', 'KE');

        $all = $this->section($this->main($this->html('/discover?tab=people')), 'people');
        $this->assertStringContainsString('Ngozi Ade', $all);
        $this->assertStringContainsString('Wanjiru Kamau', $all);

        $ke = $this->html('/discover?tab=people&where=KE');
        $this->assertStringNotContainsString('Ngozi Ade', $this->section($this->main($ke), 'people'), 'Where narrows the people');
        $this->assertStringContainsString('Wanjiru Kamau', $this->section($this->main($ke), 'people'));
        $this->assertStringContainsString('<a class="dv__applied" href="/discover?tab=people" aria-label="Remove filter Kenya">', $ke);
        $this->assertMatchesRegularExpression('~<form class="dv-facets__form" method="get" action="/discover"~', $ke);
        $this->assertStringContainsString('Show 1 result', $ke, 'the button counts what it will show');

        // An unknown filter value is dropped, not obeyed.
        $this->assertStringContainsString('Ngozi Ade', $this->section($this->main($this->html('/discover?tab=people&where=ZZ')), 'people'));
    }

    public function test_reviewed_evidence_means_a_verified_dossier_item(): void
    {
        $cat = $this->seedAward();
        $yes = $this->nominee($cat, 'Reviewed Person');
        $no  = $this->nominee($cat, 'Claimed Person');
        DB::table('gates_nominee_evidence')->insert([
            ['nominee_id' => $yes, 'title' => 'Licence', 'provenance' => 'platform_verified', 'verified' => 1, 'verified_at' => Carbon::now()->toDateTimeString()],
            ['nominee_id' => $no,  'title' => 'A claim', 'provenance' => 'nominator_claim',   'verified' => 0, 'verified_at' => null],
        ]);
        $main = $this->section($this->main($this->html('/discover?tab=people&trust%5B%5D=ev')), 'people');
        $this->assertStringContainsString('Reviewed Person', $main);
        $this->assertStringNotContainsString('Claimed Person', $main);
    }

    public function test_the_count_endpoint_answers_what_the_tab_would_list(): void
    {
        $cat = $this->seedAward();
        $this->nominee($cat, 'Ngozi Ade', 'NG');
        $this->nominee($cat, 'Wanjiru Kamau', 'KE');
        $res = $this->get('/discover/count?tab=people&where=KE');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertSame(['ok' => true, 'count' => 1], json_decode((string) $res->getBody(), true));
    }

    public function test_a_decided_award_is_an_announced_one_never_a_passed_date(): void
    {
        $this->seedAward();
        $now = Carbon::now();
        DB::table('gates_award_programmes')->insert(['id' => 212, 'slug' => 'dv-late', 'title' => 'Late Results Awards', 'is_active' => 1, 'sort_order' => 2]);
        DB::table('gates_award_cycles')->insert(['id' => 2212, 'programme_id' => 212, 'year' => 2026, 'status' => 'judging',
            'nominations_open' => $now->copy()->subDays(90)->toDateTimeString(), 'nominations_close' => $now->copy()->subDays(60)->toDateTimeString(),
            'voting_open' => $now->copy()->subDays(50)->toDateTimeString(), 'voting_close' => $now->copy()->subDays(20)->toDateTimeString(),
            'results_date' => $now->copy()->subDays(3)->toDateTimeString()]);
        $main = $this->section($this->main($this->html('/discover?tab=awards&status=decided')), 'awards');
        $this->assertStringContainsString('<h2 class="dv-sec__h" id="dvH-awards">Decided</h2>', $main);
        $this->assertStringNotContainsString('Late Results Awards', $main,
            'a results date that has passed is a promise, not an announcement');
    }

    public function test_nothing_from_the_sandbox_reaches_discover(): void
    {
        $this->seedAward();
        DemoSeeder::seed(1);
        $names = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
            ->where('y.programme_id', DemoSeeder::programmeId())
            ->pluck('n.name')->all();
        $title = (string) DB::table('gates_award_programmes')->where('id', DemoSeeder::programmeId())->value('title');
        $this->assertNotEmpty($names, 'the sandbox seeded nobody — this test would pass vacuously');

        foreach (['/discover', '/discover?tab=live', '/discover?tab=people', '/discover?tab=orgs', '/discover?tab=awards&status=voting'] as $uri) {
            $main = $this->main($this->html($uri));
            $this->assertStringNotContainsString($title, $main, "$uri names the sandbox programme");
            foreach ($names as $n) $this->assertStringNotContainsString((string) $n, $main, "$uri shows sandbox nominee $n");
        }
    }

    public function test_no_inline_handler_and_every_inline_style_is_a_data_custom_property(): void
    {
        $this->nominee($this->seedAward(), 'Zanele Mokoena');
        $main = $this->main($this->html('/discover?tab=live'));
        $this->assertDoesNotMatchRegularExpression('~\son[a-z]+=~i', $main, 'the CSP has no unsafe-inline');
        preg_match_all('~style="([^"]*)"~', $main, $st);
        $this->assertNotEmpty($st[1]);
        foreach ($st[1] as $s) {
            $this->assertMatchesRegularExpression('~^--dv-dot:var\(--ag-[a-z0-9-]+\)$~', $s, 'only data-driven custom properties inline (REFERENCE §4.6)');
        }
    }
}
