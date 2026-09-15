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

    /**
     * Every category resolves, and shows every answer it has WITH ITS SUMMARY.
     *
     * The summary is the point of the page rather than a decoration: somebody who
     * has already chosen "Payments" is choosing between seven answers that all
     * sound relevant, and the title alone does not tell them which is theirs.
     */
    public function test_every_category_has_a_page_listing_all_of_its_answers(): void
    {
        foreach (HelpCentre::CATEGORIES as $key => $cat) {
            $html = $this->body('/help/c/' . $key);
            $articles = HelpCentre::inCategory($key);

            $this->assertNotSame([], $articles, $key . ' has no articles at all');
            // Escaped: five of the six titles contain an ampersand.
            $this->assertStringContainsString(htmlspecialchars((string) $cat['title'], ENT_QUOTES), $html);

            foreach ($articles as $a) {
                $this->assertStringContainsString('/help/' . $a['slug'], $html,
                    $key . ' page omits ' . $a['slug']);
                $this->assertStringContainsString(htmlspecialchars((string) $a['summary'], ENT_QUOTES),
                    $html, $key . ' page shows ' . $a['slug'] . ' without its summary');
            }
        }
    }

    /** A stale or invented category is a person with a question, not a 404. */
    public function test_an_unknown_category_goes_to_the_index_rather_than_a_dead_end(): void
    {
        $res = $this->get('/help/c/does-not-exist');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/help', $res->getHeaderLine('Location'));
    }

    /**
     * The category route must not be shadowed by the article route.
     *
     * Both patterns exclude slashes so `c/payments` could never match {slug}, but
     * that is a property of the patterns rather than of the intent — this fails
     * loudly if either is ever loosened.
     */
    public function test_the_category_route_is_not_swallowed_by_the_article_route(): void
    {
        $res = $this->get('/help/c/payments');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Other topics', (string) $res->getBody(),
            'this is the category template, not an article');
    }

    // ── the index defers, rather than printing everything ───────────────────

    /**
     * A long category shows a few titles and a real link to the rest.
     *
     * "A few" is the fix for the 2,858px page. The link is what makes it honest:
     * hiding twelve answers behind five with no route to them would be worse than
     * the wall it replaces.
     */
    public function test_a_long_category_defers_the_rest_to_its_own_page(): void
    {
        $html = $this->body('/help');

        foreach (HelpCentre::CATEGORIES as $key => $cat) {
            $n = count(HelpCentre::inCategory($key));
            if ($n <= self::PREVIEW) continue;

            $this->assertStringContainsString('/help/c/' . $key, $html,
                $key . ' has ' . $n . ' answers and no link to its page');
            $this->assertStringContainsString('All ' . $n . ' answers', $html,
                'the link must name how many are behind it');
        }
    }

    /**
     * Everything past the preview is rendered but marked out, not omitted.
     *
     * Two reasons it must be in the HTML. The live filter has to reach the whole
     * category without a round trip; and a reader with no JavaScript gets the
     * first few plus the link, which is a working page rather than a broken one.
     */
    public function test_the_deferred_titles_are_present_in_the_page_but_marked_out(): void
    {
        $html = $this->body('/help');

        $long = null;
        foreach (array_keys(HelpCentre::CATEGORIES) as $key) {
            if (count(HelpCentre::inCategory($key)) > self::PREVIEW) { $long = $key; break; }
        }
        $this->assertNotNull($long, 'no category is long enough for this test to mean anything');

        $articles = HelpCentre::inCategory($long);
        $beyond   = $articles[self::PREVIEW];   // the first one past the cap

        $this->assertStringContainsString('/help/' . $beyond['slug'], $html,
            'a deferred answer is missing from the markup, so the filter can never find it');
        $this->assertStringContainsString('is-out', $html,
            'nothing is marked out, so every answer is being shown after all');
    }

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

    /**
     * The corpus is embedded as JSON in a script tag, NOT interpolated into an
     * x-data attribute.
     *
     * This is a real bug that shipped once. Putting `{{ …|json_encode|raw }}` in a
     * double-quoted HTML attribute means the first quote inside the JSON closes the
     * attribute, and Alpine received `x-data="{ pvName: ""` — an "Unexpected token"
     * in the console and a dead widget. Thirty-three titles, several containing
     * apostrophes and em dashes, is exactly the payload that triggers it.
     */
    public function test_the_search_corpus_is_embedded_as_json_not_as_an_attribute(): void
    {
        $html = $this->body('/help');

        $this->assertStringContainsString('type="application/json"', $html);
        $this->assertMatchesRegularExpression('/x-data="[^"]*"/', $html,
            'the x-data attribute is unterminated — the corpus has leaked into it');

        // And the index really is in there, with the keywords that make it useful.
        $this->assertStringContainsString('paid-but-no-votes', $html);
        $this->assertStringContainsString('debited', $html,
            'keywords must be indexed: "debited" appears in no article title, and it is '
            . 'what people type');
    }

    /**
     * `:class` bindings use OBJECT syntax.
     *
     * ── WHY THIS IS ASSERTED ON THE MARKUP ───────────────────────────────────
     *
     * `:class="cond ? '' : 'is-out'"` adds the classes it evaluates to and removes
     * only ones it added before — it never clears a class that was in the static
     * `class` attribute. Every <li> past the preview is rendered with
     * `class="is-out"` for the no-JavaScript cap, so with string syntax those items
     * stayed hidden forever: searching "refund" showed a Payments card containing
     * nothing, because the matching answer is the sixth in its category.
     *
     * The object form sets AND unsets the named class. Same family of bug as the
     * `:style` clobber on the vote page, and the same reason to pin it.
     */
    public function test_class_bindings_use_object_syntax_so_the_static_class_can_be_cleared(): void
    {
        $html = $this->body('/help');

        $this->assertMatchesRegularExpression("/:class=\"\{\s*'is-out':/", $html,
            'a :class binding is using string syntax, which cannot clear the static '
            . 'is-out class — deferred answers will be unreachable by the filter');
        $this->assertDoesNotMatchRegularExpression("/:class=\"[^\"]*\?\s*''\s*:\s*'is-out'/", $html,
            'string-ternary :class has come back');
    }

    // ── and the search that was always there still is ───────────────────────

    /**
     * Enter still performs the real, scored, shareable server search. The live
     * narrowing is additive; if it ever replaced this, a search would stop being
     * something you can bookmark or send to support.
     */
    public function test_the_get_search_still_works_and_is_still_scored(): void
    {
        $res  = $this->get('/help?q=debited');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        // "debited" appears in no title — only the keywords reach this article.
        $this->assertStringContainsString('/help/paid-but-no-votes', $html);
        $this->assertStringContainsString('Clear search', $html);
    }

    public function test_a_search_with_no_answer_hands_over_instead_of_dead_ending(): void
    {
        $html = $this->body('/help?q=zzzzqqqqwwww');

        $this->assertStringContainsString('Nothing written covers that yet', $html);
        $this->assertStringContainsString('/support/assistant?q=', $html,
            'and it carries the question across rather than making them retype it');
    }
}
