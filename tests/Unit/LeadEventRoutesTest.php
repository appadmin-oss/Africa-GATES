<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventPlan\EventPages;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Tests\TestCase;

/**
 * EVERY PAGE THE EVENT MENU DRAWS HAS A ROUTE (owner, 5 Nov 2026: "the event roles 400s").
 *
 * The lead console's event pages were registered from a typed list, and "Team & roles" was
 * added to the menu (EventPages::NAV) and never to that list, so the link answered 404 for
 * every lead who pressed it. The routes are read from the menu now; this asks the router the
 * same question from the other side, so a page added to the menu any other way fails here.
 */
final class LeadEventRoutesTest extends TestCase
{
    public function test_every_event_menu_page_is_a_registered_route(): void
    {
        $root = dirname(__DIR__, 2);
        $b = new ContainerBuilder();
        $b->addDefinitions(require $root . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require $root . '/src/routes.php')($app);
        $patterns = [];
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if (in_array('GET', $r->getMethods(), true)) $patterns[] = $r->getPattern();
        }
        $missing = [];
        foreach (EventPages::NAV as [, $pages]) {
            foreach (array_keys($pages) as $page) {
                $want = $page === 'today' ? '/lead/event/{id:[0-9]+}' : '/lead/event/{id:[0-9]+}/' . $page;
                if (!in_array($want, $patterns, true)) $missing[] = $page;
            }
        }
        $this->assertSame([], $missing, 'event menu pages with no route: ' . implode(', ', $missing));
        $this->assertContains('/lead/event/{id:[0-9]+}/roles', $patterns, 'the page that answered 404');
    }
}
