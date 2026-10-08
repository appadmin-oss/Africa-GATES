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
 * it, that the current page is marked, that a long group folds and is forced open when
 * the current page is past its fourth item, that the sprite resolves, that the "also
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

    public function test_the_rail_renders_the_handoffs_groups_for_a_superadmin(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index', 'superadmin', '/admin/payouts');

        foreach (['Daily work', 'Programmes', 'Entries', 'Money', 'Publishing', 'Monitoring', 'Settings'] as $g) {
            $this->assertStringContainsString('>' . $g . '</span>', $html, "missing the {$g} group");
        }
        $this->assertMatchesRegularExpression('~href="/admin/payouts" aria-current="page"~', $html, 'the current page is not marked');
        $this->assertSame(1, substr_count($html, 'class="cn-nav__i" href="/admin/payouts"'));
        $this->assertStringContainsString('id="ic-payouts"', $html, 'the sprite was not included');
        $this->assertStringContainsString('href="#ic-payouts"', $html);
    }

    public function test_a_long_group_folds_and_opens_itself_when_you_are_past_its_fourth_page(): void
    {
        // Money has six pages; Payouts is the second, so Money stays folded…
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index', 'superadmin', '/admin/payouts');
        $this->assertMatchesRegularExpression('~<div class="cn-nav__group" data-fold>\s*<span class="cn-nav__label"[^>]*>Money~', $html);
        $this->assertStringContainsString('2 more', $html);

        // …and on Vote delivery, the fifth, it is forced open with "Show less".
        $html = $this->render(\AfricaGates\Admin\Controllers\VoteDeliveryController::class, 'index', 'superadmin', '/admin/vote-delivery');
        $this->assertMatchesRegularExpression('~<div class="cn-nav__group" data-fold data-open>\s*<span class="cn-nav__label"[^>]*>Money~', $html);
        $this->assertStringContainsString('Show less', $html);
    }

    public function test_the_also_here_strip_links_a_pages_sub_pages(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index', 'superadmin', '/admin/payouts');
        $this->assertStringContainsString('class="cn-related"', $html, 'no "also here" strip');
        $this->assertStringContainsString('class="cn-related__i" href="/admin/partner-orgs"', $html);
    }

    public function test_a_moderator_is_shown_neither_money_nor_settings(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\InterviewsController::class, 'index', 'moderator', '/admin/interviews');
        $this->assertStringContainsString('>Entries</span>', $html, 'the moderator lost their own group');
        $this->assertStringNotContainsString('>Settings</span>', $html, 'a moderator was offered configuration');
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
                  'Reason, for the audit log', 'What needs doing today?', 'The quiet numbers',
                  'Only jobs you can act on are shown', 'Search everything'] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing $needle");
        }
        $this->assertStringContainsString('Every job waiting on a person, across everything your role can reach, most urgent first.', $html);
    }
}
