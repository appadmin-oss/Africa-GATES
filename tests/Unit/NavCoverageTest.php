<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Support\AdminNav;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Tests\TestCase;

/**
 * EVERY CONSOLE PAGE IS IN THE RAIL'S TREE (20 Oct 2026).
 *
 * `AdminIaTest` asks whether a page is findable at all — in the rail, or linked from any
 * template. That passed while seven real pages (the judging schedule, recognitions, the
 * newsletter, seasonal greetings, shop codes, delivery charges, operations scripts) were
 * reachable only from a link buried in another page's body: not in the rail, not on any
 * page's "also here" strip, and so not in ⌘K either, which is generated from the tree.
 * An operator who did not already know where the link was could not find them.
 *
 * This asks the stricter question, of the router itself rather than a parse of the route
 * file: every admin GET route that RENDERS A PAGE is named in AdminNav, as a rail item or
 * as a sub-page. The exclusions are kinds, each with its reason, never a list of pages:
 *   · a route with a placeholder is a record, reached from its list;
 *   · `/new` is a create form, reached from its list's button;
 *   · an export or a download is a file, not a page;
 *   · sign-in and sign-out are the door, not a room;
 *   · a handler that never calls `render()` answers JSON, an image or a redirect.
 */
final class NavCoverageTest extends TestCase
{
    /** @return list<string> admin GET pages that render and are not in the tree */
    private function missing(): array
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $named = array_flip(array_values(AdminNav::hrefs()));
        $out = [];
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if (!in_array('GET', $r->getMethods(), true)) continue;
            $p = $r->getPattern();
            if (!str_starts_with($p, '/admin/')) continue;
            if (str_contains($p, '{') || str_contains($p, '[')) continue;          // a record
            if (preg_match('~/new$~', $p)) continue;                                // a create form
            if (preg_match('~(export|\.csv|\.zip|\.json|\.png|\.pdf)~', $p)) continue; // a file
            if (preg_match('~^/admin/(login|logout|magic)(/|$)~', $p)) continue;    // the door
            if (!$this->renders($r->getCallable())) continue;                       // not a page
            if (!isset($named[$p])) $out[] = $p;
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** Whether a handler renders a template — the definition of "a page" here. */
    private function renders(mixed $callable): bool
    {
        if (!is_string($callable) || !str_contains($callable, ':')) return true;   // a closure: assume a page
        [$class, $method] = explode(':', $callable, 2);
        if (!method_exists($class, $method)) return true;
        $m = new \ReflectionMethod($class, $method);
        $src = implode('', array_slice(file((string) $m->getFileName()) ?: [], $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        return str_contains($src, 'render(');
    }

    public function test_every_console_page_is_in_the_rail_or_under_a_rail_item(): void
    {
        $this->assertSame([], $this->missing(),
            "these console pages render, and the rail's tree does not name them — add each as a sub-page of the item it belongs under");
    }

    public function test_the_sweep_can_see_a_page_left_out_of_the_tree(): void
    {
        // The guard is only evidence once it has been seen to name a break: the judging
        // schedule is a rendered page, so taking it out of the tree must be reported.
        $this->assertTrue($this->renders(\AfricaGates\Admin\Controllers\JudgesController::class . ':schedule'));
        $this->assertFalse($this->renders(\AfricaGates\Admin\Controllers\NomineesController::class . ':duplicateScan'),
            'a JSON handler is not a page');
        $this->assertArrayHasKey('judge-schedule', AdminNav::hrefs());
    }

    public function test_the_rail_draws_a_pages_sub_pages_and_opens_them_where_you_are(): void
    {
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['admin_name'] = 'Chioma Obi';
        $_SERVER['REQUEST_URI'] = '/admin/rubric';
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $html = (string) $b->build()->get(\AfricaGates\Admin\Controllers\PayoutsController::class)->index(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/admin/rubric'),
            (new \Slim\Psr7\Factory\ResponseFactory())->createResponse()
        )->getBody();

        // Judges holds the page we are on, so its sub-pages are open and the child is marked.
        $this->assertMatchesRegularExpression('~data-kids="judges" data-kids-open data-here>~', $html);
        $this->assertMatchesRegularExpression('~class="cn-nav__kid" href="/admin/rubric" aria-current="page"~', $html);
        $this->assertStringContainsString('href="/admin/judges/schedule"', $html);
        // Elsewhere they are behind the toggle, closed.
        $this->assertMatchesRegularExpression('~data-kids="payments">~', $html);
        $this->assertMatchesRegularExpression('~aria-controls="cnKids-payments"~', $html);
    }
}
