<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SearchMisses;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Discover, end to end through the real page: a typo is corrected and said so, the original is
 * one tap away, a plural finds its singular, and what still finds nothing is counted —
 * anonymously, and never an email or a phone number — for the admin Analytics page.
 */
final class DiscoverSearchQualityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new \AfricaGates\Services\CacheService())->forget('discover:vocab');
        DB::table('gates_award_programmes')->insert(['id' => 213, 'slug' => 'dv-quality', 'title' => 'Sahel Awards', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 2213, 'programme_id' => 213, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 22113, 'cycle_id' => 2213, 'slug' => 'teacher', 'title' => 'Teacher of the Year']);
        DB::table('gates_nominees')->insert(['category_id' => 22113, 'name' => 'Chiamaka Okafor', 'status' => 'approved', 'country_code' => 'NG',
            'nominated_at' => Carbon::now()->subDays(2)->toDateTimeString()]);
    }

    private function people(string $uri): string
    {
        $html = (string) ChromeRender::page($uri)->getBody();
        preg_match('~<section class="dv-sec dv-only dv-only--people"(.*?)</section>~s', $html, $m);
        return ($m[1] ?? '') . "\n" . (preg_match('~<p class="dv-fix">.*?</p>~s', $html, $f) ? $f[0] : '');
    }

    public function test_a_typo_is_corrected_said_so_and_the_original_is_one_tap_away(): void
    {
        $out = $this->people('/discover?tab=people&q=Chiamka');
        $this->assertStringContainsString('Chiamaka Okafor', $out);
        $this->assertStringContainsString('Showing results for <b>chiamaka</b>', $out);
        $this->assertMatchesRegularExpression('~href="/discover\?tab=people&amp;q=Chiamka&amp;literal=1"~', $out);
        $this->assertSame([], SearchMisses::top(1), 'a search the correction rescued is not a miss');

        $literal = $this->people('/discover?tab=people&q=Chiamka&literal=1');
        $this->assertStringNotContainsString('Chiamaka Okafor', $literal, 'asked for exactly what was typed');
        $this->assertSame('chiamka', SearchMisses::top(1)[0]['query'] ?? null);
    }

    public function test_a_plural_finds_the_singular(): void
    {
        $this->assertStringContainsString('Chiamaka Okafor', $this->people('/discover?tab=people&q=teachers'));
    }

    public function test_what_still_finds_nothing_is_counted_and_a_contact_detail_never_is(): void
    {
        ChromeRender::page('/discover?q=Qwertyzz');
        ChromeRender::page('/discover?q=qwertyzz');
        ChromeRender::page('/discover?q=someone%40mail.example.org');
        ChromeRender::page('/discover?q=0803%20123%204567');
        $top = SearchMisses::top(1);
        $this->assertSame([['query' => 'qwertyzz', 'tab' => 'all', 'hits' => 2]], array_map(static fn ($r) => ['query' => $r['query'], 'tab' => $r['tab'], 'hits' => $r['hits']], $top));
        $this->assertSame(0, (int) DB::table('gates_search_misses')->where('query', 'like', '%@%')->count());
    }

    public function test_the_analytics_page_is_handed_the_misses(): void
    {
        $this->assertStringContainsString("'search_misses' => \\AfricaGates\\Services\\SearchMisses::top(",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/AnalyticsController.php'));
        $this->assertStringContainsString('{% for m in search_misses %}', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/analytics.twig'));
    }
}
