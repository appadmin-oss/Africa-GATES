<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\ChallengeController;
use AfricaGates\Support\SeedRunner;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * /challenges/{slug}, rendered through the real controller and container.
 *
 * Nothing rendered this page before its rebuild, so nothing could notice it opening a
 * second `<main>` inside the layout's, or painting the gold theme's primary action in
 * white on #f3b416. What is held here is the page's contract with the comp — its
 * sections, in order, from the shared library — and the two rules that are not
 * visible in a screenshot of the default theme.
 */
final class ChallengePageTest extends TestCase
{
    private const SLUG = 'celebrate-nigeria-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00'));
        $p = (int) DB::table('gates_award_programmes')->insertGetId(
            ['slug' => 'alimosho-awards', 'title' => 'Alimosho Awards', 'is_active' => 1]);
        $cy = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2026, 'status' => 'nominations',
            'nominations_open' => '2026-09-01 00:00:00', 'nominations_close' => '2026-11-30 23:59:59',
        ]);
        foreach (['choral' => 'Choral', 'business' => 'Business', 'impact' => 'Impact'] as $slug => $t) {
            DB::table('gates_award_categories')->insert(['cycle_id' => $cy, 'slug' => $slug, 'title' => $t]);
        }
        DB::table('gates_settings')->whereIn('key_name',
            ['seed_ran_2026_10_01_celebrate_nigeria', 'seed_last_2026_10_01_celebrate_nigeria'])->delete();
        $this->assertSame('done', SeedRunner::run('2026_10_01_celebrate_nigeria')['status']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function html(): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get(ChallengeController::class)->show(
            (new ServerRequestFactory())->createServerRequest('GET', '/challenges/' . self::SLUG),
            new Response(), ['slug' => self::SLUG]);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    public function test_the_comps_sections_render_in_order_from_the_library(): void
    {
        $h = $this->html();

        $order = ['id="ch-h1"', 'id="h-how"', 'id="h-count"', 'id="h-win"', 'id="h-faq"'];
        $last = -1;
        foreach ($order as $mark) {
            $at = strpos($h, $mark);
            $this->assertNotFalse($at, "{$mark} is missing");
            $this->assertGreaterThan($last, $at, "{$mark} is out of the comp's order");
            $last = $at;
        }

        // From the shared library, not page-local copies of it.
        foreach (['ag-steps ag-steps--cards', 'class="ag-ticks"', 'class="ag-faq"', 'class="ag-notice"',
                  'ag-meter ag-meter--outline', 'class="ag-facts ag-facts--text"', 'ag-actionbar'] as $piece) {
            $this->assertStringContainsString($piece, $h, "{$piece} is not on the page");
        }

        $this->assertStringContainsString('Celebrate Nigeria', $h);
        $this->assertStringContainsString('₦6,000', $h);
        $this->assertStringContainsString('Hosted by', $h);
        // The comp rests with the first answer open.
        $this->assertMatchesRegularExpression('~<details class="ag-faq__i" open>~', $h);
        $this->assertSame(1, substr_count($h, '<details class="ag-faq__i" open>'));
    }

    public function test_one_main_landmark(): void
    {
        $h = $this->html();
        $this->assertSame(1, preg_match_all('~<main\b~', $h), 'the page opens a second <main>');
        $this->assertSame(1, substr_count($h, 'id="main"'));
    }

    /**
     * Every theme's primary action carries white text on a colour that holds it. This
     * used to be the theme's FILL, and the gold fill under white is 1.85:1.
     */
    public function test_each_themes_solid_holds_white_text(): void
    {
        $css    = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/challenge.css');
        // The palette is emitted by Support\Accent; tokens.css carries no colour.
        $tokens = \AfricaGates\Support\Accent::css();
        $lum = static function (string $hex): float {
            $c = array_map(static fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4,
                           sscanf(ltrim($hex, '#'), '%02x%02x%02x'));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };

        foreach (['green', 'blue', 'gold', 'rose'] as $t) {
            $this->assertMatchesRegularExpression('~\[data-theme="' . $t . '"\][^}]*--ch-solid:var\((--ag-[a-z-]+)\)~', $css, $t);
            preg_match('~\[data-theme="' . $t . '"\][^}]*--ch-solid:var\((--ag-[a-z-]+)\)~', $css, $m);
            $this->assertSame(1, preg_match('~' . preg_quote($m[1], '~') . ':\s*(#[0-9a-f]{6})~i', $tokens, $hex), $m[1]);
            $ratio = 1.05 / ($lum($hex[1]) + 0.05);
            $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s: white on %s is %.2f:1', $t, $m[1], $ratio));
        }
        $this->assertStringContainsString('.ch-cta{ background:var(--ch-solid)', $css);
        $this->assertStringContainsString('--ag-step-fill:var(--ch-solid)', $css);
    }
}
