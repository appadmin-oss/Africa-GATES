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
 *   · a handler that never calls `render()` answers JSON, an image or a redirect;
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

    public function test_the_rail_is_one_level_and_a_sub_page_lights_its_item(): void
    {
        // Owner, 20 Oct 2026: only a GROUP opens and closes; pages are not nested in the
        // rail. A sub-page is reached from its item's "also here" strip and from ⌘K, and
        // while you are on one the rail item it belongs under is lit.
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

        preg_match('~<nav class="cn-nav".*?</nav>~s', $html, $m);
        $rail = $m[0] ?? '';
        $this->assertNotSame('', $rail);
        $this->assertStringNotContainsString('href="/admin/rubric"', $rail, 'a sub-page was nested in the rail');
        $this->assertStringNotContainsString('nav-kids', $rail);
        $this->assertMatchesRegularExpression('~href="/admin/judges" title="Judges" aria-current="page"~', $rail);
        // …and the sub-page is one press away on the strip under the title.
        $this->assertMatchesRegularExpression('~class="cn-related__i" href="/admin/rubric" aria-current="page"~', $html);
        $this->assertStringContainsString('class="cn-related__i" href="/admin/judges/schedule"', $html);
    }

    public function test_every_page_a_role_can_open_is_reachable_from_its_rail(): void
    {
        // A page is reachable when it is a rail item for the role, or on the "also here"
        // strip of one — and it is in the role's ⌘K either way. The case this exists for:
        // a role that may open a sub-page but not the item it sits under (Analytics under
        // Audit log for an admin) must still find it; the rail promotes it to an item.
        foreach (array_keys(\AfricaGates\Admin\Support\Permissions::ROLES) as $role) {
            $nav = AdminNav::forRole($role);
            $rail = []; $strip = [];
            foreach ($nav['groups'] as $g) foreach ($g['items'] as $i) {
                $rail[] = $i['href'];
                foreach ($i['children'] as $c) $strip[] = $c['href'];
            }
            $palette = array_column(AdminNav::destinations($role), 'href');
            foreach (AdminNav::groups() as $g) foreach ($g['items'] as $i) {
                foreach (array_merge([$i], $i['children']) as $p) {
                    if (!\AfricaGates\Admin\Support\Permissions::canOpen($role, $p['href'])) continue;
                    $this->assertTrue(in_array($p['href'], $rail, true) || in_array($p['href'], $strip, true),
                        "$role can open {$p['href']} and cannot reach it from the rail or a strip");
                    $this->assertContains($p['href'], $palette, "$role can open {$p['href']} and ⌘K does not list it");
                }
            }
        }
    }
}
