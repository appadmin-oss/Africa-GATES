<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\GivingUrl;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Tests\TestCase;

/**
 * ONE NOUN FOR GIVING, AND EVERY OLDER PATH STILL ANSWERING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The donation surface had three names. `/donate` was the original and is what
 * twenty-two files linked to. `/gift` was added later and declared "the canonical path
 * from here" in the route file's own comment — and reached six files, so the rename was
 * announced and never finished: **both answered 200 with identical content**. Two
 * canonical URLs for one page splits search ranking, and receipts and emails disagreed
 * about where the giving page lives.
 *
 * And `giving` was already a noun INSIDE the flow: `/donate/giving/{token}` is one donor's
 * standing monthly gift and the button that stops it. The word meant two things.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS HOLDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Three claims, each of which has its own way of failing silently:
 *
 *   1. `/giving` is the only path that SERVES. An older one that quietly went on serving
 *      would be the same fault again, and nothing about it looks wrong.
 *   2. Every older path still ANSWERS, permanently. `/donate` is on receipts, in sent
 *      email, on other people's websites and in search results.
 *   3. A POST bounce is **308**, not 301 or 302. Those are downgraded to GET by every
 *      browser, which drops a donor's amount and their CSRF token and lands them on the
 *      giving page with an empty form and no idea why — the failure looks like the form
 *      being broken.
 *
 * Asked of the ROUTER rather than of the source: the paths are built by
 * {@see GivingUrl} and the patterns carry lookaheads, so a source sweep would be checking
 * its own reconstruction. See `RouteTableIntegrityTest` for why parsing this file does not
 * work.
 */
final class GivingUrlTest extends TestCase
{
    /** @return array<string, array{status:int, location:string}> "VERB /path" => outcome */
    private static function table(): array
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
                $out[$verb . ' ' . $r->getPattern()] = true;
            }
        }
        return $memo = $out;
    }

    private static function declared(string $verb, string $pattern): bool
    {
        return isset(self::table()[$verb . ' ' . $pattern]);
    }

    // ══ the builder ══════════════════════════════════════════════════════════

    public function test_every_path_is_built_from_one_base(): void
    {
        $this->assertSame('/giving', GivingUrl::BASE);
        $this->assertSame('/giving', GivingUrl::page());
        $this->assertSame('/giving/apply', GivingUrl::apply());
        $this->assertSame('/giving/manage/abc', GivingUrl::manage('abc'));
        $this->assertSame('/giving/manage/abc/stop', GivingUrl::stop('abc'));
        $this->assertSame('/giving/borehole-trust', GivingUrl::org('borehole-trust'));
        $this->assertSame('/giving/borehole-trust/clean-water',
            GivingUrl::org('borehole-trust', 'clean-water'));
        // An empty campaign is the organisation's own page, not a trailing slash — which
        // would be a second URL for the same content, which is the whole fault here.
        $this->assertSame('/giving/borehole-trust', GivingUrl::org('borehole-trust', ''));
    }

    /**
     * A SLUG IS SOMEBODY'S TYPING AND REACHES A URL.
     *
     * Not theoretical for a campaign slug: an organisation names its own appeal.
     *
     * What is asserted is that the value stays ONE PATH SEGMENT — the separator and the
     * query delimiter are encoded, so nothing typed can add a segment, climb a directory
     * or start a query string. A first cut of this test asserted the absence of `..`,
     * which `rawurlencode` leaves alone and correctly so: `..%2Fadmin` is a slug that
     * happens to contain dots, and it resolves to a partner page that does not exist
     * rather than anywhere else. The property worth pinning is the slash.
     */
    public function test_a_slug_or_token_cannot_escape_its_own_segment(): void
    {
        $this->assertSame('/giving/..%2Fadmin', GivingUrl::org('../admin'));
        $this->assertStringNotContainsString('?', GivingUrl::manage('a?b=c'));
        $this->assertStringNotContainsString('#', GivingUrl::org('a#b'));
        $this->assertSame('/giving/a%20b', GivingUrl::org('a b'));
        $this->assertSame('/giving/x/a%2Fb', GivingUrl::org('x', 'a/b'));
    }

    // ══ what serves ══════════════════════════════════════════════════════════

    public function test_the_canonical_paths_are_the_ones_registered(): void
    {
        foreach ([['GET', GivingUrl::BASE], ['POST', GivingUrl::BASE],
                  ['GET', GivingUrl::apply()], ['POST', GivingUrl::apply()],
                  ['GET', GivingUrl::redirect()],
                  ['GET', GivingUrl::BASE . '/callback'],
                  ['GET', GivingUrl::BASE . '/success']] as [$verb, $path]) {
            $this->assertTrue(self::declared($verb, $path), "$verb $path is not registered");
        }

        $this->assertTrue(self::declared('GET',  '/giving/manage/{token:[a-f0-9]{32}}'));
        $this->assertTrue(self::declared('POST', '/giving/manage/{token:[a-f0-9]{32}}/stop'));
    }

    /**
     * AND NOTHING SPELLS THE PATH BY HAND.
     *
     * Eight files used to — the controller, the sitemap, the JSON-LD, the receipt mailer,
     * the campaign service, the recurring-gift service, the search index and the activity
     * feed. That is how it came to be three nouns: nothing forced a rename to reach every
     * one of them, and the ones it missed kept working.
     *
     * `routes.php` is excluded because it is where the routes are declared, and the alias
     * table below is checked separately.
     */
    public function test_no_service_spells_the_giving_path_by_hand(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2);

        foreach ([$root . '/src', $root . '/templates'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if (!$f->isFile() || !in_array($f->getExtension(), ['php', 'twig'], true)) continue;

                $rel = str_replace($root . '/', '', $f->getPathname());
                if ($rel === 'src/routes.php' || $rel === 'src/Support/GivingUrl.php') continue;

                // Comments stripped, PHP and Twig alike. Every fix here documents the fault
                // in the words of the fault — this file's own docblock names `/donate` a
                // dozen times — so a scan that reads comments finds what it just removed.
                $src = (string) preg_replace(
                    ['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~', '~\{#.*?#\}~s'], ' ',
                    (string) file_get_contents($f->getPathname()));

                // A quoted or href'd literal, not a bare mention in prose.
                if (preg_match('~["\'](/donate|/gift)(/|["\'])~', $src) === 1) {
                    $offenders[] = $rel;
                }
            }
        }

        $this->assertSame([], $offenders,
            "A hand-spelled giving path is how this surface came to have three names, each\n"
            . "reaching a different subset of the files. Build it with GivingUrl.\n\n  "
            . implode("\n  ", $offenders));
    }

    // ══ what still answers ═══════════════════════════════════════════════════

    /**
     * EVERY OLDER PATH IS STILL REGISTERED.
     *
     * Not a tidy-up. These are printed on receipts and in email that has already been sent.
     */
    public function test_the_old_prefixes_still_answer(): void
    {
        foreach (['/donate', '/gift'] as $old) {
            $this->assertTrue(self::declared('GET',  $old), "$old stopped answering");
            $this->assertTrue(self::declared('POST', $old));
            $this->assertTrue(self::declared('GET',  $old . '/apply'));
            $this->assertTrue(self::declared('GET',  $old . '/redirect'));
            $this->assertTrue(self::declared('GET',  $old . '/callback'));
            $this->assertTrue(self::declared('GET',  $old . '/success'));
        }
    }

    /**
     * AND THE ONE THAT MATTERS MOST: THE DONOR'S STOP BUTTON.
     *
     * `/donate/giving/{token}` is the link in receipts that have already gone out. A donor
     * who cannot easily stop is not a supporter, they are a dispute waiting for a quiet
     * month — so this has to answer for as long as those receipts exist.
     */
    public function test_the_stop_link_printed_in_sent_receipts_still_answers(): void
    {
        $this->assertTrue(self::declared('GET',  '/donate/giving/{token:[a-f0-9]{32}}'),
            'the cancellation link in every receipt already sent has stopped resolving');
        $this->assertTrue(self::declared('POST', '/donate/giving/{token:[a-f0-9]{32}}/stop'));
    }

    /**
     * A POST BOUNCE IS 308.
     *
     * 301 and 302 are downgraded to GET by every browser, so a stale page's checkout would
     * arrive at `/giving` as a GET with no amount, no email and no CSRF token — and the
     * donor sees an empty form, which reads as the form being broken rather than as a
     * redirect having eaten their submission.
     *
     * Read from the source here, deliberately: the status code lives inside the closure
     * and the router cannot be asked for it without dispatching a request.
     */
    public function test_a_post_bounce_preserves_the_method(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertMatchesRegularExpression(
            '~\$g->post\(\$old,\s*\$bounce\([^)]*\),?\s*308\)|\$g->post\(\$old, \$bounce\(.*?, 308\)\)~s',
            $src,
            'the POST bounce is not 308 — 301 and 302 drop the donor\'s amount and token');

        $this->assertStringNotContainsString('$g->post($old, $bounce(static fn (): string => $GU::page(), 301)', $src);
        $this->assertStringNotContainsString('$g->post($old, $bounce(static fn (): string => $GU::page(), 302)', $src);
    }

    /** The near-miss table points at the canonical path, not at a path that redirects. */
    public function test_the_alias_table_targets_the_canonical_path(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        foreach (['/donation', '/donations', '/give', '/donate-now'] as $alias) {
            $this->assertMatchesRegularExpression(
                "~'" . preg_quote($alias, '~') . "'\s*=>\s*'" . preg_quote(GivingUrl::BASE, '~') . "'~",
                $src,
                $alias . ' points somewhere other than the canonical giving path — a '
                . 'redirect chain costs a round trip and loses the ranking signal');
        }
    }

    // ══ the reserved words ═══════════════════════════════════════════════════

    /**
     * A FIXED WORD SHADOWS A PARTNER PERMANENTLY, AND NOTHING SAYS SO.
     *
     * An appeal lives at `/giving/{slug}` and the fixed paths are registered first. Slim
     * serves the first match, so an organisation whose name slugs to `apply` or `manage`
     * has an approved row, a dashboard showing their URL, and a URL that opens somebody
     * else's screen. "Apply" is an ordinary enough name for a foundation.
     *
     * The route pattern's lookahead is BUILT from this list, and so is the slug minting,
     * so the two cannot drift.
     */
    public function test_the_route_pattern_excludes_every_reserved_word(): void
    {
        $pattern = null;
        foreach (array_keys(self::table()) as $k) {
            if (str_starts_with($k, 'GET /giving/{slug')) { $pattern = $k; break; }
        }
        $this->assertNotNull($pattern, 'the partner appeal route is not registered');

        foreach (GivingUrl::RESERVED as $word) {
            $this->assertStringContainsString($word . '$', $pattern,
                "a partner whose name slugs to '{$word}' would be shadowed by a fixed route");
        }
    }

    public function test_the_fixed_words_are_the_ones_that_are_reserved(): void
    {
        // Every fixed segment under /giving must be in the list, or a partner can take it.
        foreach (self::table() as $k => $_) {
            if (!str_starts_with($k, 'GET /giving/') && !str_starts_with($k, 'POST /giving/')) continue;

            $tail = explode('/', substr($k, (int) strpos($k, '/giving/') + 8))[0];
            if (str_starts_with($tail, '{')) continue;   // the partner pattern itself

            $this->assertTrue(GivingUrl::isReserved($tail),
                "'{$tail}' is a fixed segment under /giving and is not reserved, so a "
                . 'partner organisation slugging to it would be permanently unreachable');
        }
    }

    public function test_reserved_is_case_and_space_insensitive(): void
    {
        $this->assertTrue(GivingUrl::isReserved('Apply'));
        $this->assertTrue(GivingUrl::isReserved('  manage '));
        $this->assertFalse(GivingUrl::isReserved('borehole-trust'));
    }
}
