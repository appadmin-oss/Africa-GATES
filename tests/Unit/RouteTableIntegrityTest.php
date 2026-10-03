<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Tests\TestCase;

/**
 * SEVEN HUNDRED AND FIFTY ROUTES, AND TWO WAYS THE TABLE CAN LIE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SWEEP AND NOT A REVIEW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Slim serves the FIRST route whose pattern matches, so both faults below are silent: the
 * application boots, every page somebody clicks during the review works, and the one that
 * does not is the one nobody clicked.
 *
 *   · A DUPLICATE — the same verb and pattern registered twice. The second handler is dead
 *     code that reads as live, and the two can diverge for years. Middleware attached to
 *     the second never runs either, which is how a route comes to be permissioned in the
 *     source and open in production.
 *
 *   · A SHADOWING — a literal path registered AFTER a placeholder that matches it.
 *     `/results/{id}` before `/results/late` means `/results/late` is served by the detail
 *     handler with `id = 'late'`. It answers 200 with the wrong page rather than 404, so it
 *     reads as a bug in the handler rather than in the table.
 *
 * Both are clean today, which is exactly when this is worth writing: it costs nothing now
 * and it is the only thing that will notice the fiftieth route added to a file with
 * fourteen nested groups.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT ASKS SLIM, BECAUSE READING THE FILE DOES NOT WORK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A first cut parsed `src/routes.php`. Routes are declared RELATIVE to their group and the
 * groups nest, so the prefix has to be reassembled by tracking brace depth — and the group
 * proxy variable is `$a` for the API and `/account` trees as well as `/admin`, so a sweep
 * keyed on the variable name reports routes from three unrelated trees as each other's
 * (thirteen false findings, the first time that was tried here).
 *
 * Depth tracking fixes that and still misses the thing that matters: the API routes live in
 * a CLOSURE mounted twice, under `/api/v1` and `/api`. The parser saw 563 routes where the
 * router has 755 verb-path pairs — a hundred and ninety-two of them invisible, including
 * every versioned API endpoint, and every one of those appearing under a bare prefix where
 * it could collide with a public page of the same name.
 *
 * So this boots the container and the route file the way `public/index.php` does and reads
 * the route collector. There is no parsing, no prefix arithmetic, and no shape of
 * declaration it can fail to understand — the table it checks is the table that serves.
 */
final class RouteTableIntegrityTest extends TestCase
{
    /**
     * Every registered verb-path pair, in registration order.
     *
     * Memoised: building the container and registering seven hundred routes is the
     * expensive part, and it is identical for every test in this file.
     *
     * @return list<array{verb:string, path:string}>
     */
    private static function routes(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;

        $root    = dirname(__DIR__, 2);
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $root . '/config/container.php');

        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require $root . '/src/routes.php')($app);

        $out = [];
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            foreach ($r->getMethods() as $verb) {
                $out[] = ['verb' => (string) $verb, 'path' => $r->getPattern()];
            }
        }
        return $memo = $out;
    }

    /**
     * A pattern as a regex, or null when it cannot be expressed as one.
     *
     * THE CONSTRAINT IS THE WHOLE DIFFICULTY. `{id}` matches one segment, but
     * `{id:[0-9]+}` matches only digits — and treating that as `[^/]+` reports
     * `/admin/shop/codes` as shadowed by `/admin/shop/{id:[0-9]+}`, which is a false
     * finding on almost every admin sub-page this codebase has. The constraint is honoured
     * where one is written.
     *
     * An OPTIONAL segment (`[/{page}]`) is skipped rather than approximated: it expands to
     * two patterns and guessing wrong in either direction is worse than not asking.
     *
     * AND THAT TEST IS FOR A BRACKET OUTSIDE A PLACEHOLDER. A plain `str_contains($path,
     * '[')` also matches the constraint in `{id:[0-9]+}` — which is most of this route
     * table — so the sweep declined nearly every route it was written to check while
     * reporting a clean pass. Caught by the self-test below, which is the only reason it
     * is not still doing that.
     */
    private static function matcher(string $path): ?string
    {
        $bare = (string) preg_replace('~\{[^}]*\}~', '', $path);
        if (str_contains($bare, '[')) return null;

        $out = '';
        foreach (preg_split('~(\{[^}]*\})~', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $bit) {
            if (!str_starts_with($bit, '{')) { $out .= preg_quote($bit, '~'); continue; }

            $body  = substr($bit, 1, -1);
            $colon = strpos($body, ':');
            // Grouped, so an alternation in the constraint cannot swallow the rest of the
            // pattern — `{k:a|b}` as a bare fragment would match `a` OR `b<everything>`.
            $out .= $colon === false ? '[^/]+' : '(?:' . substr($body, $colon + 1) . ')';
        }
        return '~^' . $out . '$~';
    }

    /**
     * Without this the sweep passes by reading an empty table — and it would, the day the
     * route file stops being a closure taking the app.
     */
    public function test_it_reads_the_table_that_actually_serves(): void
    {
        $paths = array_column(self::routes(), 'path');

        $this->assertGreaterThan(700, count($paths),
            'the route collector came back nearly empty, so this guards nothing');

        $this->assertContains('/admin/legal', $paths, 'a plain admin route');
        $this->assertContains('/admin/shop/orders', $paths, 'a route inside a nested group');
        // The two that defeated a source parser: the API tree shares its proxy variable
        // with /admin, and it is a closure mounted twice.
        $this->assertContains('/api/v1/registry', $paths, 'the versioned API mount');
        $this->assertContains('/api/registry', $paths, 'the unversioned API mount');
    }

    public function test_no_verb_and_pattern_is_registered_twice(): void
    {
        $seen = $dupes = [];
        foreach (self::routes() as $i => $r) {
            $k = $r['verb'] . ' ' . $r['path'];
            if (isset($seen[$k])) { $dupes[] = $k; continue; }
            $seen[$k] = $i;
        }

        $this->assertSame([], array_values(array_unique($dupes)),
            "Slim serves the FIRST match, so a second registration of the same verb and\n"
            . "pattern is dead code that reads as live — and any middleware on it never\n"
            . "runs, which is how a route comes to be permissioned in the source and open\n"
            . "in production.\n\n  " . implode("\n  ", array_unique($dupes)));
    }

    public function test_no_literal_path_is_shadowed_by_an_earlier_pattern(): void
    {
        $routes = self::routes();
        $found  = [];

        foreach ($routes as $i => $earlier) {
            if (!str_contains($earlier['path'], '{')) continue;
            $rx = self::matcher($earlier['path']);
            if ($rx === null) continue;

            foreach (array_slice($routes, $i + 1) as $later) {
                if ($later['verb'] !== $earlier['verb']) continue;
                if (str_contains($later['path'], '{')) continue;
                if (@preg_match($rx, $later['path']) !== 1) continue;

                $found[] = $later['verb'] . ' ' . $later['path']
                         . ' never runs — ' . $earlier['path'] . ' matches it first';
            }
        }

        $this->assertSame([], $found,
            "A literal path registered after a placeholder that matches it is served by the\n"
            . "wrong handler, with the literal arriving as the placeholder's value. It\n"
            . "answers 200 with the wrong page rather than 404, so it reads as a bug in the\n"
            . "handler. Register the literal FIRST.\n\n  " . implode("\n  ", $found));
    }

    /**
     * AND THE DETECTOR HAS TO FIND THE SHAPE IT LOOKS FOR.
     *
     * Every assertion here is a false finding this sweep would otherwise have produced or
     * a real one it would otherwise have missed.
     */
    public function test_the_matcher_is_neither_greedy_nor_blind(): void
    {
        $rx = self::matcher('/results/{id}');
        $this->assertNotNull($rx);
        $this->assertSame(1, preg_match($rx, '/results/late'),
            'an unconstrained placeholder must be seen as covering a one-segment literal');
        $this->assertSame(0, preg_match($rx, '/results/late/card.png'),
            'a placeholder matches ONE segment — a greedy one reports every sub-route of '
            . 'every detail page as shadowed');
        $this->assertSame(0, preg_match($rx, '/results'));

        // The constraint case, which is most of this route table.
        $rx = self::matcher('/admin/shop/{id:[0-9]+}');
        $this->assertNotNull($rx);
        $this->assertSame(1, preg_match($rx, '/admin/shop/41'));
        $this->assertSame(0, preg_match($rx, '/admin/shop/codes'),
            'a digits-only placeholder does not shadow a word — treating the constraint as '
            . '[^/]+ falsely condemns almost every admin sub-page here');

        // An alternation must not swallow the tail.
        $rx = self::matcher('/x/{k:a|b}');
        $this->assertSame(1, preg_match($rx, '/x/a'));
        $this->assertSame(0, preg_match($rx, '/x/bzzz'),
            'an ungrouped alternation matches `b` followed by anything');

        // An optional segment is declined rather than guessed at.
        $this->assertNull(self::matcher('/blog[/{page:[0-9]+}]'));
    }
}
