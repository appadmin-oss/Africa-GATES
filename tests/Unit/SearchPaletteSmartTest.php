<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The header's search palette (/ or Ctrl-K), 10 Nov 2026: a slip is corrected and said so,
 * the visitor's recent searches are offered on the empty box, and a nominee found is a link
 * to the NOMINEE — it was '/registry', which redirects to a list, so the one click a search
 * exists for went nowhere (the Live tab's nomination rows too).
 *
 * Measured in a browser: "Chiamka" showed Chiamaka Eze with "Search instead for …", which
 * restored the original; Enter on "Ibrahim" opened Ibrahim's page; reopening offered
 * "Recent searches", and Clear emptied it.
 */
final class SearchPaletteSmartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new \AfricaGates\Services\CacheService())->forget('discover:vocab');
        DB::table('gates_award_programmes')->insert(['id' => 214, 'slug' => 'palette-awards', 'title' => 'Delta Awards', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 2214, 'programme_id' => 214, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 22114, 'cycle_id' => 2214, 'slug' => 'nurse', 'title' => 'Nurse of the Year']);
        DB::table('gates_nominees')->insert(['id' => 9214, 'category_id' => 22114, 'name' => 'Chiamaka Okafor', 'status' => 'approved',
            'country_code' => 'NG', 'nominated_at' => Carbon::now()->subHour()->toDateTimeString()]);
    }

    private function json(string $uri): array
    {
        return (array) json_decode((string) ChromeRender::page($uri)->getBody(), true);
    }

    public function test_a_slip_is_corrected_and_said_so_and_literal_turns_it_off(): void
    {
        $d = $this->json('/search.json?q=Chiamka');
        $this->assertSame(['from' => 'Chiamka', 'to' => 'chiamaka'], $d['corrected']);
        $this->assertNotEmpty($d['groups']);
        $this->assertNull($this->json('/search.json?q=Chiamka&literal=1')['corrected'], 'asked for exactly what was typed');
    }

    public function test_a_nominee_found_links_to_the_nominee(): void
    {
        $items = array_merge(...array_map(static fn ($g) => $g['items'], $this->json('/search.json?q=Chiamaka')['groups']));
        $urls = array_column($items, 'url');
        $this->assertContains('/vote/palette-awards/9214-chiamaka-okafor', $urls);
        $this->assertNotContains('/registry', $urls);
    }

    public function test_recent_searches_are_declared_kept_by_consent_and_never_a_contact_detail(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/search.js');
        $this->assertStringContainsString("var RECENT = 'ag-recent-q'", $js);
        $this->assertStringContainsString("getAttribute('data-ag-keep') === '1' ? localStorage : sessionStorage", $js, 'across visits only with Preferences');
        $this->assertStringContainsString('never an email or a phone number', $js);
        $this->assertContains('ag-recent-q', array_column(\AfricaGates\Support\CookieRegistry::storage(), 'key'), 'declared on /cookies');
    }
}
