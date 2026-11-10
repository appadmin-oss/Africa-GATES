<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SearchMisses;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Discover searches as it is typed, on every tab, and its timeline stays live
 * (owner, 10 Nov 2026: "We need live search with constant reloads", "fix the activity").
 *
 * Measured in a browser when written: typing on People swapped results with no page load,
 * the URL followed, the box kept focus; Enter settled in place; a profile added while the
 * Live tab was open was offered as "1 new update — show it". This file holds the server half
 * and the wiring of the script.
 */
final class DiscoverLiveTypingTest extends TestCase
{
    public function test_a_keystroke_is_not_a_miss_but_a_settled_search_is(): void
    {
        ChromeRender::page('/discover?q=Qwertyzz', headers: ['X-Discover-Live' => '1']);
        $this->assertSame([], SearchMisses::top(1), '"q", "qw", "qwe" are typing, not searches');
        ChromeRender::page('/discover?q=Qwertyzz', headers: ['X-Discover-Live' => 'settled']);
        $this->assertSame('qwertyzz', SearchMisses::top(1)[0]['query'] ?? null);
    }

    public function test_a_keystroke_is_never_served_an_ad(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DiscoverController.php');
        $this->assertStringContainsString("'ad' => \$typing !== '' ? null : ", $src, 'an impression per letter typed is money');
    }

    public function test_the_script_searches_every_tab_as_typed_and_keeps_the_timeline_live(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/discover.js');
        $this->assertStringContainsString("'X-Discover-Live': settled ? 'settled' : '1'", $js);
        $this->assertMatchesRegularExpression('/if \(!armed\) \{\s*var pq = input\.value\.trim\(\);/', $js, 'tabs other than Live search as typed');
        $this->assertStringContainsString("section.dv-sec", $js, 'only the result sections are swapped, never the ad slot');
        $this->assertStringContainsString('pg.every(checkNew, 30000);', $js, 'through agPage, so it stops when the page is left');
        $this->assertStringContainsString('pg.every(retime, 60000);', $js);
    }

    public function test_the_timeline_says_what_it_shows_names_countries_and_links_the_award(): void
    {
        DB::table('gates_profiles')->insert(['display_name' => 'Kemi Afolabi', 'slug' => 'kemi-afolabi', 'profile_type' => 'individual',
            'category' => 'Arts', 'location_city' => 'Ibadan', 'country_code' => 'NG', 'email' => 'k@mail.example.org',
            'status' => 'approved', 'registered_at' => date('Y-m-d H:i:s')]);
        $html = (string) ChromeRender::page('/discover?tab=live&q=Kemi')->getBody();
        $this->assertStringContainsString('Arts · Ibadan · Nigeria', $html, 'a country by name, never "Ibadan NG"');

        $vote = (string) ChromeRender::page('/discover?tab=live&kind=vote')->getBody();
        $this->assertStringContainsString('Latest in Voting', $vote, 'the heading names the chosen chip');
        $this->assertStringContainsString('Nothing new yet.', $vote);

        $this->assertStringContainsString("'/awards/' . rawurlencode((string) \$r->programme_slug)",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ActivityFeedService.php'), 'a phase links to its award');
    }
}
