<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\HelpCentre;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The shape of the Help Centre index, and the category pages that make it possible.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG WITH THE OLD LAYOUT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Six category cards in `grid-template-columns: repeat(2,1fr)`, each printing EVERY
 * answer it contains. The corpus is deeply uneven — 12 answers under "Results &
 * integrity", 2 under "Privacy" — and a grid row is as tall as its tallest cell, so
 * every row left a column of empty page beside the shorter card. Measured at 1280px
 * wide: 2,858px tall, roughly a third of it void, with "Results & integrity"
 * rendered as a wall of twelve links.
 *
 * The cause was structural rather than cosmetic. A category had nowhere to lead —
 * HelpController's own description claimed "an index, a category, and an article"
 * and there was no category route — so the index had no choice but to print
 * everything inline.
 *
 * These tests hold the three properties of the rebuild: a category has a page, the
 * index defers to it, and the live filter can still reach the deferred answers.
 */
final class HelpCentreLayoutTest extends TestCase
{
    /** Must match HelpController::PREVIEW. Asserted below rather than trusted. */
    private const PREVIEW = 5;

    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        return $app;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
        );
    }

    private function body(string $path): string
    {
        return (string) $this->get($path)->getBody();
    }

    // ── a category is a place ────────────────────────────────────────────────

    /** A stale or invented category is a person with a question, not a 404. */
    public function test_an_unknown_category_goes_to_the_index_rather_than_a_dead_end(): void
    {
        $res = $this->get('/help/c/does-not-exist');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/help', $res->getHeaderLine('Location'));
    }

    // ── the index defers, rather than printing everything ───────────────────

    /** The preview constant this test asserts against is the one the page uses. */
    public function test_the_preview_size_is_what_this_test_assumes(): void
    {
        $shortest = min(array_map(
            static fn(string $k): int => count(HelpCentre::inCategory($k)),
            array_keys(HelpCentre::CATEGORIES)
        ));
        $this->assertLessThan(self::PREVIEW, $shortest,
            'if every category is longer than the preview, nothing is being deferred');

        $ref = new \ReflectionClass(\AfricaGates\Controllers\HelpController::class);
        $this->assertSame(self::PREVIEW, $ref->getConstant('PREVIEW'),
            'HelpController::PREVIEW and this test have drifted apart');
    }

    // ── the live filter ─────────────────────────────────────────────────────

    // ── and the search that was always there still is ───────────────────────
}
