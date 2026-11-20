<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * The real app, through the real container and the middleware a request meets, as
 * public/index.php assembles it — for a test that must prove a route, a token in a path
 * or a CSRF exemption rather than call a controller directly.
 */
final class TestApp
{
    public static function build(): \Slim\App
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($b->build());
        $app = \Slim\Factory\AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(\Slim\Views\TwigMiddleware::createFromContainer($app, \Slim\Views\Twig::class));
        $app->add(new \AfricaGates\Middleware\CsrfMiddleware());
        $app->addBodyParsingMiddleware();
        $err = $app->addErrorMiddleware(false, false, false);
        $err->setDefaultErrorHandler(new \AfricaGates\Handlers\ErrorHandler($app));
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        return $app;
    }
}
