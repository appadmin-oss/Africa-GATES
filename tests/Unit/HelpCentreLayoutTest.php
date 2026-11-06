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
    /**
     * Must match HelpController::PREVIEW. Asserted below rather than trusted. Three since the
     * Phase 9 rebuild: HelpCentre.dc.html draws three 48px links per card, then "All N".
     */
    private const PREVIEW = 3;

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

    /**
     * Every category card leads to its own page, and shows no more than PREVIEW titles
     * before it does. The rest are in the markup but `hidden`, so the in-place filter can
     * still reveal them (below) — which is the whole reason they are not simply omitted.
     */
    public function test_the_index_defers_to_each_category_page(): void
    {
        $html = $this->body('/help');
        foreach (array_keys(HelpCentre::CATEGORIES) as $k) {
            $n = count(HelpCentre::inCategory($k));
            if ($n === 0) continue;
            $this->assertStringContainsString('href="/help/c/' . $k . '"', $html, "$k has no way to its own page");
            $this->assertSame(200, $this->get('/help/c/' . $k)->getStatusCode(), "/help/c/$k does not answer");
        }
        preg_match_all('~<section class="hc-cat"[^>]*>(.*?)</section>~s', $html, $cards);
        $this->assertNotEmpty($cards[1], 'no category cards were found — the sweep below would pass over nothing');
        foreach ($cards[1] as $card) {
            $shown = preg_match_all('~<li(?![^>]*\bhidden\b)[^>]*data-help-slug~', $card);
            $this->assertLessThanOrEqual(self::PREVIEW, $shown, 'a card prints more than the preview');
        }
    }

    // ── the live filter ─────────────────────────────────────────────────────

    /** The filter narrows in the browser from #help-index, so that list must hold EVERY answer. */
    public function test_the_live_filter_can_reach_every_answer(): void
    {
        $html = $this->body('/help');
        $this->assertMatchesRegularExpression('~<script type="application/json" id="help-index"~', $html);
        preg_match('~<script type="application/json" id="help-index"[^>]*>(.*?)</script>~s', $html, $m);
        $index = json_decode($m[1] ?? 'null', true);
        $this->assertIsArray($index);
        $slugs = array_map(static fn ($r) => (string) ($r['s'] ?? ''), $index);
        foreach (HelpCentre::all() as $a) {
            $this->assertContains($a['slug'], $slugs, $a['slug'] . ' is unreachable by the filter');
        }
    }
}
