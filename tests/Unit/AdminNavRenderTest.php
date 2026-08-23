<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The restructured sidebar and sub-nav, rendered through the real container.
 *
 * The tree itself is held by {@see AdminNavTest}. What can only be seen by rendering is
 * whether the layout actually loops it, whether the icon sprite resolves, and whether the
 * section containing the current page opens — the three things that would leave an
 * operator staring at an empty rail while every unit test stayed green.
 */
final class AdminNavRenderTest extends TestCase
{
    private function render(string $class, string $method): string
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get($class)->{$method}(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/x'),
            (new ResponseFactory())->createResponse()
        );

        $this->assertSame(200, $res->getStatusCode(), "{$method} did not render");
        return (string) $res->getBody();
    }

    public function test_the_sidebar_renders_collapsible_sections(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index');

        $this->assertStringContainsString('ad-side__sec', $html, 'the sidebar did not render the tree');
        $this->assertStringContainsString('<summary class="ad-side__sectitle"', $html);
        // Every section heading should be present, collapsed or not.
        foreach (['Overview', 'Entries', 'Judging', 'Outreach', 'Finance', 'Payments'] as $label) {
            $this->assertStringContainsString('>' . $label . '<', $html, "missing the {$label} section");
        }
    }

    /** Arriving anywhere must show where you are without a click. */
    public function test_the_section_holding_the_current_page_is_open(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index');

        // The Finance section holds `payouts`, so it — and only a section holding the
        // current page — carries `open`.
        $this->assertMatchesRegularExpression(
            '~<details class="ad-side__sec" open>\s*<summary[^>]*>\s*<span>Finance</span>~',
            $html,
            "the current page's section did not open"
        );
        $this->assertSame(1, substr_count($html, 'class="ad-side__sec" open'),
            'more than one section opened, or none did');
    }

    public function test_the_icon_sprite_is_present_and_referenced(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index');

        $this->assertStringContainsString('id="ic-payouts"', $html, 'the sprite was not included');
        $this->assertStringContainsString('href="#ic-payouts"', $html, 'nothing referenced the sprite');
    }

    /** The second level: this page's siblings, under the title. */
    public function test_the_sub_nav_shows_the_pages_siblings(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\PayoutsController::class, 'index');

        $this->assertStringContainsString('ad-subnav', $html, 'no in-page sub-nav');
        $this->assertStringContainsString('/admin/finance', $html, 'a sibling is missing from the strip');
        $this->assertStringContainsString('/admin/partner-orgs', $html);
        $this->assertStringContainsString('aria-current="page"', $html, 'the current page is not marked');
    }

    /**
     * A role must not be shown a rail it cannot use — the sidebar mirrors
     * SectionGuardMiddleware so the UI never offers a 403.
     */
    public function test_a_moderator_is_not_shown_the_finance_rail(): void
    {
        $_SESSION['admin_id']   = 2;
        $_SESSION['admin_role'] = 'moderator';

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get(\AfricaGates\Admin\Controllers\InterviewsController::class)->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/interviews'),
            (new ResponseFactory())->createResponse()
        );

        $html = (string) $res->getBody();
        $this->assertStringContainsString('>Judging<', $html, 'the moderator lost their own section');
        $this->assertStringNotContainsString('>Configuration<', $html, 'a moderator was offered the superadmin rail');
        $this->assertStringNotContainsString('/admin/payouts', $html, 'a moderator was offered payouts');
    }
}
