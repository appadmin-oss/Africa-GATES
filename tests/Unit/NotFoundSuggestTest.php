<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NotFoundSuggest;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Were you looking for …" on the 404 page (10 Nov 2026). A broken address usually still holds
 * what somebody wanted; the page offered only "Back to home". Measured in a browser:
 * /vote/lagos-youth/99-chiamka-eze offered Chiamaka Eze (the last segment first, corrected);
 * /awards/lagos-yuoth the award; /wp-login.php searched nothing.
 */
final class NotFoundSuggestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new \AfricaGates\Services\CacheService())->forget('discover:vocab');
        DB::table('gates_award_programmes')->insert(['id' => 215, 'slug' => 'nf-awards', 'title' => 'Benue Awards', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 2215, 'programme_id' => 215, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 22115, 'cycle_id' => 2215, 'slug' => 'poet', 'title' => 'Poet of the Year']);
        DB::table('gates_nominees')->insert(['id' => 9215, 'category_id' => 22115, 'name' => 'Ngozi Adichie', 'status' => 'approved',
            'country_code' => 'NG', 'nominated_at' => Carbon::now()->subHour()->toDateTimeString()]);
    }

    public function test_a_scanners_probe_or_a_file_is_never_searched(): void
    {
        foreach (['/wp-login.php', '/wp-admin/setup', '/.env', '/assets/js/missing.js', '/uploads/x/y.jpg', '/xmlrpc.php', '/', '/12345'] as $p) {
            $this->assertSame([], NotFoundSuggest::words($p), "$p must not reach the search index");
        }
        $this->assertSame(['ngozi', 'adichie', 'benue'], NotFoundSuggest::words('/vote/benue-awards/9-ngozi-adichie'), 'the last segment first; structure words dropped');
    }

    public function test_the_person_in_a_broken_nominee_link_is_offered_even_misspelled(): void
    {
        $s = NotFoundSuggest::for('/vote/nf-awards/77-ngozi-adichei');
        $this->assertSame('/vote/nf-awards/9215-ngozi-adichie', $s['items'][0]['url'] ?? null);
    }

    public function test_the_404_page_offers_it_first_and_fills_the_search_box(): void
    {
        // The site's own handler hands it over, for a GET 404 only.
        $h = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Handlers/ErrorHandler.php');
        $this->assertStringContainsString("'suggest' => \$code === 404 && \$req->getMethod() === 'GET'", $h);
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/error.twig');
        $this->assertLessThan(strpos($tpl, '<div class="er__act">'), strpos($tpl, 'Were you looking for…'), 'the answer before the buttons');
        $this->assertStringContainsString('name="q" value="{{ suggest.q }}"', $tpl, 'the box filled with the words of the address');
    }
}
