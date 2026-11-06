<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\App;
use Tests\TestCase;

/**
 * Every internal link the site hardcodes must resolve to a real route.
 *
 * A crawl of the running site (tools/qa/links.js) found one dead link reachable
 * from 55 of 60 pages: the announcement bar's default call to action pointed at
 * `/africa-gates/nominate`, and no programme-scoped nominate route has ever
 * existed. The bar renders above the nav on every page, and the default applies
 * until an admin sets `announce_url` in Settings — so a fresh install shipped a
 * 404 as its most prominent button.
 *
 * The crawler can only see what a seeded, running site renders. These tests close
 * the gap by resolving link targets against the compiled route table directly, so
 * a dead link fails the suite whether or not any fixture happens to render it.
 *
 * Deliberately scoped to STATIC paths. A href built from a Twig expression
 * (`/registry/{{ n.slug }}`) depends on data, and asserting anything about it here
 * would be asserting about the fixture rather than about the link.
 */
final class SiteLinkIntegrityTest extends TestCase
{
    private static ?App $app = null;

    private function app(): App
    {
        if (self::$app !== null) {
            return self::$app;
        }
        // Real container and real route table, wired as public/index.php does —
        // the same approach as RouteCompileTest, for the same reason: a link can
        // only be checked against the router that will actually serve it.
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        return self::$app = $app;
    }

    /**
     * Does the router have a GET handler for $path?
     *
     * The status must be read. `computeRoutingResults()` returns a RoutingResults
     * object for an unknown path too — with `getRouteStatus() === NOT_FOUND` — so a
     * null check passes for every string you hand it. The negative control below
     * exists because that is exactly what the first version of this method did, and
     * the template scan passed while asserting nothing.
     */
    private function resolves(string $path): bool
    {
        try {
            $r = $this->app()->getRouteResolver()->computeRoutingResults($path, 'GET');
        } catch (\Throwable) {
            return false;
        }
        return $r->getRouteStatus() === \Slim\Routing\RoutingResults::FOUND;
    }

    public function test_the_announcement_bar_default_target_resolves(): void
    {
        // The regression. This value reaches nav.twig as a Twig global and is the
        // href of the only button in the announcement bar.
        $globals = $this->twigGlobals();

        $this->assertArrayHasKey('announcement_url', $globals);
        $url = (string) $globals['announcement_url'];

        $this->assertStringStartsWith('/', $url, 'the default must be an internal path');
        $this->assertTrue($this->resolves($url),
            "the announcement bar's default target ({$url}) must be a real GET route — "
            . 'it renders above the nav on every page');
    }

    public function test_every_static_internal_link_in_the_templates_resolves(): void
    {
        $offenders = [];
        foreach ($this->templateFiles() as $file => $raw) {
            foreach ($this->staticInternalHrefs($raw) as $href) {
                if (!$this->reachable($href)) {
                    $offenders[] = "{$file}: {$href}";
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders,
            'these hrefs are hardcoded in a template and neither resolve to a GET route '
            . 'nor exist as a file under public/ — a visitor clicking them gets a 404');
    }

    /**
     * Is $path served at all — by the router OR by the web server as a static file?
     *
     * Both halves are needed. `/assets/img/logo-mark.svg` has no route and never
     * will: `public/.htaccess` sends real files straight to disk
     * (`RewriteCond %{REQUEST_FILENAME} !-f`) and only rewrites what is left to
     * index.php. Checking the router alone reported four existing favicons as dead
     * links, which is how a scan loses its credibility.
     */
    private function reachable(string $path): bool
    {
        if ($this->resolves($path)) {
            return true;
        }
        // Static file under public/. Reject traversal outright rather than resolving
        // it — a template should never contain one, so it is a finding, not a lookup.
        if (str_contains($path, '..')) {
            return false;
        }
        return is_file(dirname(__DIR__, 2) . '/public' . rawurldecode($path));
    }

    public function test_the_scan_would_actually_catch_a_dead_link(): void
    {
        // A negative control. A scan that silently matches nothing passes forever,
        // which is how the announcement bar's 404 survived to begin with.
        $found = $this->staticInternalHrefs(
            '<a href="/nominate">go</a><a href="/no-such-page">x</a>'
            . '{% set href = \'/judge/ballot/\' ~ id %}'
        );

        $this->assertContains('/nominate', $found);
        $this->assertContains('/no-such-page', $found);
        $this->assertNotContains('/judge/ballot/', $found,
            'a href= inside a Twig statement block is an expression, not an attribute');

        $this->assertTrue($this->reachable('/nominate'));
        $this->assertFalse($this->reachable('/no-such-page'));

        // And the static-file half, both directions.
        $this->assertTrue($this->reachable('/assets/img/logo-mark.svg'),
            'a real file under public/ is served by the web server, not the router');
        $this->assertFalse($this->reachable('/assets/img/definitely-not-here.svg'));
        $this->assertFalse($this->reachable('/../.env'), 'traversal is a finding, not a lookup');
    }

    public function test_paths_needing_a_parameter_are_not_mistaken_for_dead(): void
    {
        // `/registry/{slug}` resolves for any concrete slug, so a template that
        // hardcodes a real sub-path must not be reported. Guards against the scan
        // becoming so strict that the real signal gets muted by noise.
        $this->assertTrue($this->resolves('/registry/anything'));
        $this->assertTrue($this->resolves('/legal/anything'));
    }

    /**
     * Static internal hrefs only.
     *
     * Excluded, each for a reason:
     *  - `{{ … }}` / `{% … %}` anywhere in the value — data-dependent, see the
     *    class docblock.
     *  - `#`, `mailto:`, `tel:`, `javascript:`, `sms:`, `whatsapp:`, `data:` —
     *    not page fetches.
     *  - anything with a scheme or `//` prefix — external, not ours to route.
     *  - query strings and fragments are trimmed before resolving; the router
     *    matches on path.
     *
     * @return list<string>
     */
    private function staticInternalHrefs(string $html): array
    {
        // Twig STATEMENT blocks are stripped first. `{% set href = '/judge/ballot/' ~
        // b.programme.id %}` contains the text `href = '/judge/ballot/'`, and the
        // interpolated id sits OUTSIDE the quotes — so an attribute-shaped regex
        // extracts a path that looks static, is not, and reads as a dead link.
        $html = (string) preg_replace('~\{%.*?%\}~s', '', $html);

        preg_match_all('~\bhref\s*=\s*["\']([^"\']*)["\']~i', $html, $m);
        $out = [];
        foreach ($m[1] as $href) {
            $h = trim($href);
            if ($h === '' || $h[0] === '#') continue;
            if (str_contains($h, '{{') || str_contains($h, '{%')) continue;
            if (preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $h)) continue;
            if ($h[0] !== '/') continue;    // relative — resolves against the current page, not checkable here
            $h = (string) preg_replace('~[?#].*$~', '', $h);
            if ($h === '' || $h === '/') continue;
            $out[$h] = true;
        }
        return array_keys($out);
    }

    /** @return array<string,string> relative path => contents */
    private function templateFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            // Admin, judge and email templates are excluded: admin/judge paths sit
            // behind auth middleware groups whose GET routes still resolve, but
            // email templates legitimately carry absolute URLs for another host.
            $rel = str_replace($root . '/', '', $f->getPathname());
            if (str_starts_with($rel, 'emails/')) continue;
            $out[$rel] = (string) file_get_contents($f->getPathname());
        }
        ksort($out);
        return $out;
    }

    /**
     * The Twig globals the container exposes to every page.
     *
     * Read off the built Twig environment rather than from a container key,
     * because the globals array is assembled inside the Twig factory. That is the
     * point: this reads the same values nav.twig will, with the Settings-table
     * lookups the factory performs, so a default that is dead only when Settings
     * is empty — which is every fresh install — is what gets checked.
     */
    private function twigGlobals(): array
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        return $builder->build()->get(\Slim\Views\Twig::class)->getEnvironment()->getGlobals();
    }
}
