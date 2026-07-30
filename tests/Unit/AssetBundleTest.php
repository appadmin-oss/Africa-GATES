<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\AssetBundle;
use Tests\TestCase;

/**
 * The CSS bundle: correctness first, bytes second.
 *
 * Bundling is a performance change that can only pay off if it is byte-for-byte
 * behaviourally identical to the fifteen stylesheets it replaces. Every test here exists
 * because getting one of these wrong produces a site that looks broken on production and
 * fine on any machine that has not run the build:
 *
 *   • MINIFIER CORRECTNESS. The first implementation dropped the space after `+`, which
 *     is harmless in a selector and INVALID inside `calc()` — it produced three
 *     occurrences of `calc(100% +0.5rem)` in the real bundle, each of which a browser
 *     discards, silently collapsing whatever it sized. Found by grepping the built file,
 *     not by reading the code.
 *   • CASCADE ORDER. `a11y.css` is last so its WCAG corrections win. Concatenating in a
 *     different order changes which rules apply.
 *   • THE TEMPLATE FALLBACK. It must list the same files in the same order, or the
 *     bundled and unbundled renderings differ.
 *   • STALENESS. An edited source must fall back rather than serve the old bundle.
 */
class AssetBundleTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/afg-assets-' . bin2hex(random_bytes(5));
        foreach (AssetBundle::STYLESHEETS as $rel) {
            @mkdir($this->root . '/' . dirname($rel), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** Write every declared source, each carrying a marker so order is observable. */
    private function seedSources(): void
    {
        foreach (AssetBundle::STYLESHEETS as $i => $rel) {
            file_put_contents(
                $this->root . '/' . $rel,
                "/* {$rel} */\n.marker-{$i} { content: \"{$i}\"; }\n"
            );
        }
    }

    private function built(): string
    {
        $url = AssetBundle::url($this->root);
        $this->assertNotNull($url, 'a bundle should be current right after a build');
        return (string) file_get_contents($this->root . '/' . ltrim($url, '/'));
    }

    // ── The minifier's one fatal mistake ────────────────────────────────────

    /**
     * `calc()` requires whitespace on BOTH sides of `+` and `-`. This is the regression
     * that shipped in the first draft and it is the reason this class's minifier is
     * described as conservative.
     */
    public function test_calc_expressions_keep_the_whitespace_the_spec_requires(): void
    {
        $cases = [
            'a{width:calc(100% + 0.5rem)}',
            'a{width:calc(100% - 2rem)}',
            'a{padding:calc(1.5rem + env(safe-area-inset-bottom, 0px))}',
            'a{width:calc(var(--x) + var(--y))}',
            'a{margin:calc(100vh - 2rem) calc(50% + 4px)}',
        ];
        foreach ($cases as $css) {
            $min = AssetBundle::minify($css);
            // Whitespace must survive on both sides of every + and - inside calc().
            $this->assertDoesNotMatchRegularExpression('~[0-9a-z%)] \+[0-9a-z.(]~i', $min,
                "lost the space AFTER + in: {$min}");
            $this->assertDoesNotMatchRegularExpression('~[0-9a-z%)]\+ [0-9a-z.(]~i', $min,
                "lost the space BEFORE + in: {$min}");
            $this->assertDoesNotMatchRegularExpression('~[0-9a-z%)] -[0-9a-z.(]~i', $min,
                "lost the space AFTER - in: {$min}");
        }
    }

    /** The real stylesheets, through the real minifier — the check that caught the bug. */
    public function test_the_projects_own_calc_expressions_survive_minification(): void
    {
        $root = dirname(__DIR__, 2) . '/public';
        $found = 0;
        foreach (AssetBundle::STYLESHEETS as $rel) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) continue;
            $min = AssetBundle::minify((string) file_get_contents($path));
            $found += substr_count($min, 'calc(');
            $this->assertDoesNotMatchRegularExpression('~calc\([^)]*[0-9a-z%)] \+[0-9a-z.(]~i', $min,
                "{$rel}: minification produced an invalid calc()");
        }
        $this->assertGreaterThan(0, $found, 'the project does use calc() — this test must have something to check');
    }

    // ── Structural equivalence ──────────────────────────────────────────────

    /**
     * Minification must not change the STRUCTURE of the CSS, only its whitespace.
     * Brace balance is the cheapest total check: an unbalanced bundle silently discards
     * every rule after the break.
     */
    public function test_minification_preserves_structure_of_the_real_stylesheets(): void
    {
        $root = dirname(__DIR__, 2) . '/public';
        foreach (AssetBundle::STYLESHEETS as $rel) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) continue;
            $raw = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($path));
            $min = AssetBundle::minify((string) file_get_contents($path));

            foreach (['{', '}', 'calc(', 'var(', '!important', 'url('] as $token) {
                $this->assertSame(substr_count($raw, $token), substr_count($min, $token),
                    "{$rel}: count of '{$token}' changed");
            }
            foreach (['@media', '@keyframes', '@supports', '@font-face'] as $at) {
                $this->assertSame(preg_match_all('~' . $at . '~', $raw), preg_match_all('~' . $at . '~', $min),
                    "{$rel}: count of '{$at}' changed");
            }
        }
    }

    public function test_a_comment_inside_a_string_is_not_treated_as_syntax(): void
    {
        // A `/*` inside a quoted value, and a `;` inside a data URI, are content — a
        // regex-based minifier eats both and truncates the declaration.
        $css = 'a{content:"/* not a comment */"}b{background:url("data:image/svg+xml;charset=utf8,<svg/>")}';
        $min = AssetBundle::minify($css);

        $this->assertStringContainsString('"/* not a comment */"', $min);
        $this->assertStringContainsString('data:image/svg+xml;charset=utf8', $min);
        $this->assertSame(2, substr_count($min, '{'));
    }

    public function test_comments_and_whitespace_are_actually_removed(): void
    {
        $min = AssetBundle::minify("/* a comment */\n.x {\n  color : red ;\n}\n\n\n.y { color: blue; }\n");

        $this->assertStringNotContainsString('a comment', $min);
        $this->assertStringNotContainsString("\n", $min);
        // The last semicolon in a block is optional.
        $this->assertStringNotContainsString(';}', $min);
    }

    public function test_a_bang_comment_is_kept_so_a_minified_stack_stays_navigable(): void
    {
        // The bundler writes `/*! assets/css/nav.css */` before each file. Losing those
        // makes 210 KiB of minified CSS unnavigable in devtools.
        $min = AssetBundle::minify("/*! keep me */\n/* drop me */\n.x{color:red}");

        $this->assertStringContainsString('/*! keep me */', $min);
        $this->assertStringNotContainsString('drop me', $min);
    }

    // ── Order, which is the cascade ─────────────────────────────────────────

    public function test_a11y_is_last_so_its_corrections_win(): void
    {
        $last = AssetBundle::STYLESHEETS[count(AssetBundle::STYLESHEETS) - 1];
        $this->assertSame('assets/css/a11y.css', $last,
            'a11y.css carries WCAG corrections that are meant to override everything');
    }

    public function test_sources_are_concatenated_in_the_declared_order(): void
    {
        $this->seedSources();
        $this->assertTrue(AssetBundle::build($this->root)['ok']);
        $bundle = $this->built();

        $previous = -1;
        foreach (AssetBundle::STYLESHEETS as $i => $rel) {
            $at = strpos($bundle, ".marker-{$i}");
            $this->assertIsInt($at, "{$rel} is missing from the bundle");
            $this->assertGreaterThan($previous, $at, "{$rel} is out of cascade order");
            $previous = $at;
        }
    }

    /**
     * The layout's fallback must list exactly the same files, in the same order.
     *
     * A divergence here is the worst bug this feature can have, because it appears ONLY
     * on deployments that have run the build — every developer machine looks fine.
     */
    public function test_the_layout_fallback_matches_the_bundle_list_exactly(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/gates.twig');

        preg_match_all('~<link rel="stylesheet" href="/(assets/css/[^"?]+)~', $layout, $m);
        $inLayout = $m[1] ?? [];

        $this->assertSame(AssetBundle::STYLESHEETS, $inLayout,
            'the {% else %} fallback in the layout and AssetBundle::STYLESHEETS must be '
            . 'the same files in the same order, or bundled and unbundled pages differ');
    }

    public function test_the_layout_prefers_the_bundle_when_one_exists(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/gates.twig');

        $this->assertMatchesRegularExpression('~\{%\s*if css_bundle\s*%\}~', $layout);
        $this->assertStringContainsString('href="{{ css_bundle }}"', $layout);
        $this->assertMatchesRegularExpression('~\{%\s*else\s*%\}~', $layout,
            'the fallback branch is what makes a missing build harmless');
    }

    // ── Staleness: never serve CSS that does not match the source ───────────

    public function test_an_edited_source_falls_back_instead_of_serving_a_stale_bundle(): void
    {
        $this->seedSources();
        $this->assertTrue(AssetBundle::build($this->root)['ok']);
        $this->assertNotNull(AssetBundle::url($this->root));

        // Edit one source with a later mtime — the situation every developer creates.
        $edited = $this->root . '/assets/css/components/nav.css';
        file_put_contents($edited, ".nav{color:hotpink}\n");
        touch($edited, time() + 30);
        clearstatcache();

        $this->assertNull(AssetBundle::url($this->root),
            'a source newer than the build must fall back — serving the old bundle is how '
            . 'a developer loses an hour to CSS that "does not apply"');
    }

    public function test_no_manifest_means_no_bundle(): void
    {
        $this->seedSources();
        $this->assertNull(AssetBundle::url($this->root), 'nothing built yet');
    }

    public function test_a_deleted_bundle_file_falls_back_even_with_a_manifest(): void
    {
        $this->seedSources();
        $r = AssetBundle::build($this->root);
        @unlink($this->root . '/' . ltrim((string) $r['file'], '/'));
        clearstatcache();

        $this->assertNull(AssetBundle::url($this->root));
    }

    // ── Rebuild behaviour ───────────────────────────────────────────────────

    public function test_an_unchanged_rebuild_keeps_the_same_url_so_caches_stay_valid(): void
    {
        $this->seedSources();
        $a = AssetBundle::build($this->root)['file'];
        $b = AssetBundle::build($this->root)['file'];

        $this->assertSame($a, $b,
            'the filename is a content hash — an unchanged rebuild must not invalidate '
            . 'every cached copy of a 210 KiB stylesheet');
    }

    public function test_changed_css_changes_the_url(): void
    {
        $this->seedSources();
        $a = AssetBundle::build($this->root)['file'];

        file_put_contents($this->root . '/assets/css/a11y.css', ".a11y{outline:3px solid red}\n");
        $b = AssetBundle::build($this->root)['file'];

        $this->assertNotSame($a, $b, 'changed CSS must produce a new URL, or nobody sees it');
    }

    public function test_superseded_bundles_are_pruned(): void
    {
        $this->seedSources();
        AssetBundle::build($this->root);
        file_put_contents($this->root . '/assets/css/a11y.css', ".x{color:red}\n");
        AssetBundle::build($this->root);

        $this->assertCount(1, glob($this->root . '/assets/dist/site.*.css') ?: [],
            'assets/dist must not accumulate one file per build forever');
    }

    public function test_a_missing_source_is_reported_but_does_not_fail_the_build(): void
    {
        $this->seedSources();
        @unlink($this->root . '/assets/css/aurora.css');

        $r = AssetBundle::build($this->root);

        $this->assertTrue($r['ok'], 'the remaining CSS is still worth bundling');
        $this->assertContains('assets/css/aurora.css', $r['missing'],
            'and the operator must be told, not left to notice');
    }

    // ── url() rebasing, so a future relative reference cannot break ─────────

    public function test_a_relative_url_is_rebased_to_survive_the_move_to_dist(): void
    {
        $this->seedSources();
        // A component sheet referencing an image the way a future edit might.
        file_put_contents(
            $this->root . '/assets/css/components/nav.css',
            ".n{background:url(../../img/logo.svg)}\n"
        );
        AssetBundle::build($this->root);
        $bundle = $this->built();

        // assets/css/components + ../../img/logo.svg  →  /assets/img/logo.svg
        $this->assertStringContainsString('url(/assets/img/logo.svg)', $bundle,
            'concatenation moves CSS to assets/dist/, so a relative url() must be rebased '
            . 'or it silently points at a file that is not there');
    }

    public function test_absolute_and_data_and_fragment_urls_are_left_alone(): void
    {
        $min = AssetBundle::minify('a{background:url("/assets/img/x.svg")}b{fill:url(#grad)}'
            . 'c{background:url(data:image/gif;base64,AA==)}d{background:url(https://cdn.test/x.png)}');

        foreach (['url("/assets/img/x.svg")', 'url(#grad)', 'data:image/gif;base64,AA==', 'https://cdn.test/x.png'] as $keep) {
            $this->assertStringContainsString($keep, $min);
        }
    }

    // ── It actually helps ───────────────────────────────────────────────────

    public function test_the_real_bundle_is_meaningfully_smaller_and_is_one_request(): void
    {
        $r = AssetBundle::build(dirname(__DIR__, 2) . '/public');

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertGreaterThan(1, $r['sources'], 'there must be several files to collapse');
        // Lighthouse estimated 13 KiB from minification alone; hand-written, generously
        // commented CSS gives far more than that. 15% is a floor, not a target.
        $this->assertGreaterThanOrEqual(15, $r['saved_pct'],
            'if minification is saving less than this, something has stopped working');
        $this->assertLessThan($r['raw'], $r['min']);
    }
}
