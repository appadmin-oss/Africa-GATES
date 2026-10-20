<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Services\HomeBoard;
use DI\ContainerBuilder;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The rebuilt console shell, rendered through the real container (4 Oct 2026).
 *
 * The tree is held by AdminNavTest. What only a render can show: that the layout loops
 * it, that the current page is marked, that a group opens for the page you are on or for
 * work waiting and is idle otherwise (AdminConsole v2), that the sprite resolves, that the "also
 * here" strip is drawn — and that a role is shown no door it cannot open.
 */
final class AdminNavRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConsoleAlerts::reset();
        HomeBoard::resetAll();
    }

    private function render(string $class, string $method, string $role, string $path): string
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = $role;
        $_SESSION['admin_name'] = 'Chioma Obi';
        $_SERVER['REQUEST_URI'] = $path;

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get($class)->{$method}(
            (new ServerRequestFactory())->createServerRequest('GET', $path),
            (new ResponseFactory())->createResponse()
        );
        $this->assertSame(200, $res->getStatusCode(), "{$method} did not render");
        return (string) $res->getBody();
    }

    public function test_the_rail_renders_the_v2_groups_for_a_superadmin(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index', 'superadmin', '/admin/payouts');

        foreach (['Awards', 'Engage', 'Audience', 'Money', 'Content', 'Organisation'] as $g) {
            $this->assertStringContainsString('<span class="cn-nav__fold-l">' . $g . '</span>', $html, "missing the {$g} group");
        }
        $this->assertMatchesRegularExpression('~href="/admin/payouts" title="Payouts" aria-current="page"~', $html, 'the current page is not marked');
        $this->assertSame(1, substr_count($html, 'class="cn-nav__i" href="/admin/payouts"'));
        $this->assertStringContainsString('id="ic-send"', $html, 'the sprite was not included');
        $this->assertStringContainsString('href="#ic-send"', $html);
        // The crumb names the group, then the page.
        $this->assertMatchesRegularExpression('~<span class="cn-crumb__a">Money</span>~', $html);
    }

    public function test_a_group_opens_for_the_page_you_are_on_or_for_work_waiting_and_is_idle_otherwise(): void
    {
        // Payouts is in Money, so Money is open and marked as here; Content has nothing
        // waiting and nothing current, so it is idle.
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index', 'superadmin', '/admin/payouts');
        $this->assertMatchesRegularExpression('~data-group="money" data-open data-here>~', $html);
        $this->assertMatchesRegularExpression('~data-group="content">~', $html);

        // One held comment is work for Moderation: Content opens, and its sum is drawn.
        \Illuminate\Database\Capsule\Manager::table('gates_comments')->insert([
            'target_type' => 'profile', 'target_id' => 1, 'author_name' => 'A reader', 'body' => 'Held for a person.',
            'status' => \AfricaGates\Services\CommunityService::HELD,
        ]);
        HomeBoard::resetAll();
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index', 'superadmin', '/admin/payouts');
        $this->assertMatchesRegularExpression('~data-group="content" data-open>~', $html);
        $this->assertMatchesRegularExpression('~<span class="cn-nav__num" aria-label="1 waiting">1</span>~', $html);
        $this->assertMatchesRegularExpression('~href="/admin/moderation"[^>]*>.*?<span class="cn-nav__n">1~s', $html);
    }

    public function test_the_also_here_strip_links_a_pages_sub_pages(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\RefundsController::class, 'index', 'superadmin', '/admin/refunds');
        $this->assertStringContainsString('class="cn-related"', $html, 'no "also here" strip');
        $this->assertStringContainsString('class="cn-related__i" href="/admin/payments/disputes"', $html);
    }

    public function test_a_moderator_is_shown_neither_money_nor_settings(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\InterviewsController::class, 'index', 'moderator', '/admin/interviews');
        $this->assertStringContainsString('<span class="cn-nav__fold-l">Awards</span>', $html, 'the moderator lost their own group');
        $this->assertStringNotContainsString('href="/admin/settings"', $html, 'a moderator was offered configuration');
        $this->assertStringNotContainsString('href="/admin/payouts"', $html, 'a moderator was offered payouts');
        $this->assertStringNotContainsString('cn-alertpill', $html, 'a moderator was shown the health pill');
    }

    public function test_a_viewer_reads_only_and_is_told_so(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index', 'viewer', '/admin/data');
        $this->assertMatchesRegularExpression('~<body[^>]*data-readonly~', $html);
        $this->assertStringContainsString('Read-only for Viewer', $html);
        $this->assertStringContainsString('cn-alertpill', $html, 'health is the viewer\'s too');
    }

    public function test_the_shell_draws_the_four_overlays_and_the_page_header(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\DashboardController::class, 'index', 'admin', '/admin/dashboard');
        foreach (['id="cnConfirm"', 'id="cnAssist"', 'id="cnPal"', 'id="cnToasts"', 'class="cn-h1"',
                  'Reason, for the audit log', 'At a glance', 'Activity', 'Jump to',
                  'Only jobs you can act on are shown', 'class="cn-side__search"', 'class="cn-tabbar"'] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing $needle");
        }
        $this->assertStringContainsString('Every job waiting on a person, across everything your role can reach, most urgent first.', $html);
    }
}
