<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Tests\TestCase;

/**
 * Boots the real route table and forces FastRoute to compile it. Catches
 * duplicate/shadowed route definitions (BadRouteException) at test time
 * instead of 500-ing the whole app in production — the exact class of bug the
 * service/controller unit tests cannot see because they never build the router.
 */
final class RouteCompileTest extends TestCase
{
    public function test_route_table_compiles_without_conflicts(): void
    {
        // Real DI container + app, wired exactly like public/index.php.
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require __DIR__ . '/../../config/container.php');
        $container = $builder->build();

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        (require __DIR__ . '/../../src/routes.php')($app);

        // Forcing routing to resolve builds the FastRoute dispatcher, which is
        // where duplicate/shadowed routes throw BadRouteException.
        $results = $app->getRouteResolver()->computeRoutingResults('/', 'GET');
        $this->assertNotNull($results, 'route dispatcher built without conflicts');

        // Spot-check a few paths across methods so a shadow that only bites one
        // verb still surfaces here.
        foreach ([['GET', '/vote'], ['GET', '/vote/x/y'], ['POST', '/vote/paid/start'],
                  ['GET', '/vote/paid/callback'], ['GET', '/terms'], ['GET', '/legal/x']] as [$m, $p]) {
            $this->assertNotNull($app->getRouteResolver()->computeRoutingResults($p, $m), "routing resolved for {$m} {$p}");
        }
    }
}
