<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * `GET /search.json?q=&scope=` — the search palette's JSON (REFERENCE §7.1). The owner
 * retired `/activity/search` and `/find` on 3 Oct 2026 (GAPS C16) and moved the endpoint
 * off `/search` on 5 Oct (AUDIT Q13): `/search` is now a page address, 301 to Discover.
 *
 * Four things are held, each silent when it breaks:
 *
 *  · ONE URL. The retired addresses must not still answer — a second live endpoint is two
 *    sets of promises about what search covers, and an alias that 301s `/search` to a page
 *    would take the palette's data address away from it.
 *  · GROUPED ON THE SERVER, in chip order, at most six to a group (§7.1), so the client
 *    never holds the source → chip map.
 *  · THE EMPTY PALETTE CLAIMS ONLY WHAT IS MEASURED: "Open now" from the cycle's COMPUTED
 *    phase, "Coming up" from published future events, and no trending group, because the
 *    platform records no trending signal (GAPS §3.6).
 *  · THE SANDBOX NEVER APPEARS: the rehearsal programme is `is_active = 0`.
 */
final class SearchEndpointTest extends TestCase
{
    /** @return array<string,mixed> */
    private function json(string $uri): array
    {
        $res = ChromeRender::page($uri);
        $this->assertSame(200, $res->getStatusCode(), "$uri did not answer 200");
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));

        return (array) json_decode((string) $res->getBody(), true);
    }

    private function seed(): void
    {
        $now = Carbon::now();
        DB::table('gates_award_programmes')->insert([
            ['id' => 9101, 'slug' => 'open-vote', 'title' => 'Zanzibar Choral Awards', 'is_active' => 1, 'sort_order' => 1],
            ['id' => 9102, 'slug' => 'open-noms', 'title' => 'Lagos Teaching Awards',   'is_active' => 1, 'sort_order' => 2],
            ['id' => 9103, 'slug' => 'judging',   'title' => 'Accra Design Awards',      'is_active' => 1, 'sort_order' => 3],
            ['id' => 9104, 'slug' => 'rehearsal', 'title' => 'Rehearsal Awards',         'is_active' => 0, 'sort_order' => 4],
        ]);
        $w = static fn (int $d): string => $now->copy()->addDays($d)->toDateTimeString();
        DB::table('gates_award_cycles')->insert([
            // The STORED status says nominations; the windows say voting opened yesterday.
            // The computed phase is the answer (CyclePhase: the column is a cache).
            ['id' => 9201, 'programme_id' => 9101, 'year' => 2026, 'edition_label' => '3rd Edition', 'status' => 'nominations',
             'nominations_open' => $w(-40), 'nominations_close' => $w(-10), 'voting_open' => $w(-1), 'voting_close' => $w(9)],
            ['id' => 9202, 'programme_id' => 9102, 'year' => 2026, 'edition_label' => '', 'status' => 'nominations',
             'nominations_open' => $w(-3), 'nominations_close' => $w(20), 'voting_open' => $w(30), 'voting_close' => $w(60)],
            ['id' => 9203, 'programme_id' => 9103, 'year' => 2026, 'edition_label' => '', 'status' => 'judging',
             'nominations_open' => $w(-90), 'nominations_close' => $w(-60), 'voting_open' => $w(-50), 'voting_close' => $w(-5)],
            ['id' => 9204, 'programme_id' => 9104, 'year' => 2026, 'edition_label' => '', 'status' => 'voting',
             'nominations_open' => $w(-40), 'nominations_close' => $w(-10), 'voting_open' => $w(-1), 'voting_close' => $w(9)],
        ]);
        DB::table('gates_site_events')->insert([
            ['slug' => 'gala-soon',  'title' => 'Choral gala',    'location' => 'Zanzibar', 'event_date' => $w(12), 'status' => 'published'],
            ['slug' => 'gala-past',  'title' => 'Last year gala', 'location' => 'Accra',    'event_date' => $w(-12), 'status' => 'published'],
            ['slug' => 'gala-draft', 'title' => 'Draft gala',     'location' => 'Lagos',    'event_date' => $w(20), 'status' => 'draft'],
        ]);
    }

    public function test_search_is_the_endpoint_and_the_retired_addresses_are_gone(): void
    {
        $this->assertTrue($this->json('/search.json?q=')['ok']);

        // `/search` is a page address now: Discover, keeping only the query.
        $r = ChromeRender::page('/search?q=kofi&scope=people&evil=1');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/discover?q=kofi', $r->getHeaderLine('Location'));
        $this->assertSame('/discover', ChromeRender::page('/search')->getHeaderLine('Location'));
        $this->assertStringContainsString("'/search.json?q='", ChromeRender::code('public/assets/js/search.js'),
            'the palette reads the endpoint, not the redirect');

        foreach (['/activity/search?q=x', '/find?q=x'] as $retired) {
            $res  = ChromeRender::page($retired);
            $code = $res->getStatusCode();
            // Not served, and not redirected: a 301 to `/search` would be a second address
            // for the endpoint, which is the thing retired. (The router answers 404 or, where
            // a catch-all OPTIONS route matches the path, 405.)
            $this->assertContains($code, [404, 405], "$retired still answers ($code) — one search URL, the one §7.1 names");
            $this->assertStringStartsNotWith('application/json', $res->getHeaderLine('Content-Type'));
        }
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertDoesNotMatchRegularExpression("~'/search'\\s*=>~", $routes, '/search is an alias again');
    }

    public function test_the_empty_palette_is_open_now_and_coming_up_and_nothing_invented(): void
    {
        $this->seed();
        $d = $this->json('/search.json?q=');

        $this->assertTrue($d['landing']);
        $this->assertSame(['Open now', 'Coming up'], array_column($d['groups'], 'label'),
            'a group appeared that nothing measures — there is no trending signal (GAPS §3.6)');

        $open = array_column($d['groups'][0]['items'], 'title');
        $this->assertSame(['Zanzibar Choral Awards', 'Lagos Teaching Awards'], $open,
            'open-now must follow the COMPUTED phase, skip a cycle with the jury, and never show the sandbox');
        $this->assertStringStartsWith('Voting open', $d['groups'][0]['items'][0]['meta']);

        $this->assertSame(['Choral gala'], array_column($d['groups'][1]['items'], 'title'),
            'coming up is published events that have not happened yet');

        // A chip narrows the landing too.
        $this->assertSame(['Coming up'], array_column($this->json('/search.json?q=&scope=events')['groups'], 'label'));
    }

    public function test_results_are_grouped_on_the_server_in_chip_order_and_six_at_most(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            DB::table('gates_profiles')->insert([
                'slug' => "kofi-$i", 'display_name' => "Kofi Annan $i", 'email' => "kofi$i@example.test",
                'status' => 'approved', 'category' => 'Teacher',
            ]);
        }
        DB::table('gates_award_programmes')->insert(['id' => 9105, 'slug' => 'kofi', 'title' => 'Kofi Awards', 'is_active' => 1]);

        $d = $this->json('/search.json?q=kofi&literal=1');
        $this->assertFalse($d['landing']);

        $keys = array_column($d['groups'], 'key');
        $order = array_merge(array_keys(ActivityFeedService::SCOPES), ['more']);
        $this->assertSame(array_values(array_intersect($order, $keys)), $keys, 'groups are not in chip order');
        $this->assertContains('people', $keys);
        foreach ($d['groups'] as $g) {
            $this->assertLessThanOrEqual(6, count($g['items']), "{$g['key']} carries more than six");
        }
        $people = $d['groups'][array_search('people', $keys, true)];
        $this->assertSame('KA', $people['items'][0]['ini'], 'a person is drawn by their initials');
        $this->assertArrayNotHasKey('kind_key', $people['items'][0]);

        // And a chip asks one bucket.
        $this->assertSame(['people'], array_column($this->json('/search.json?q=kofi&scope=people&literal=1')['groups'], 'key'));
    }

    public function test_an_unknown_scope_widens_rather_than_empties(): void
    {
        $this->assertSame('all', $this->json('/search.json?q=kofi&scope=../etc&literal=1')['scope']);
    }
}
