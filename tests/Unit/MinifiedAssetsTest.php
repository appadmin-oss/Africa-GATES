<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Assets;
use AfricaGates\Support\MinifiedAssets;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Minified twins (owner, 2 Nov 2026: "optimize all assets"). The sources stay as written and
 * a twin named by the source's content hash is served in its place. What this holds:
 *   · a twin is served only for the EXACT bytes it was made from — edit the source and the
 *     source is served, so a twin can never be stale;
 *   · a stylesheet's relative url() still finds its file from the twin's directory;
 *   · twins of files that are gone are removed; vendored files are never touched;
 *   · the host keeps the CSS twins current itself (the maintenance run), and every committed
 *     twin is smaller than its source and was made from a source that still exists.
 */
final class MinifiedAssetsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/ag-min-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/assets/css/components', 0777, true);
        mkdir($this->root . '/assets/css/vendor', 0777, true);
        mkdir($this->root . '/assets/js', 0777, true);
        file_put_contents($this->root . '/assets/css/components/a.css', "/* a note */\n.a{ background:url(../../img/x.png) ;  color : red }\n");
        file_put_contents($this->root . '/assets/css/vendor/v.css', "/* vendored */ .v{ color:red }\n");
        file_put_contents($this->root . '/assets/js/s.js', "// a note\nvar s = 1;\n");
        Assets::configure($this->root);
        MinifiedAssets::enable(true);
    }

    protected function tearDown(): void
    {
        MinifiedAssets::enable(null);
        Assets::configure(dirname(__DIR__, 2) . '/public');
        parent::tearDown();
    }

    public function test_a_twin_is_served_for_exactly_the_bytes_it_was_made_from(): void
    {
        $this->assertStringStartsWith('/assets/css/components/a.css?v=', Assets::url('/assets/css/components/a.css'), 'no twin yet: the source');
        $r = MinifiedAssets::buildCss($this->root);
        $this->assertSame(1, $r['made'], 'vendored CSS was minified, or the source was not');
        Assets::configure($this->root);
        $twin = Assets::url('/assets/css/components/a.css');
        $this->assertMatchesRegularExpression('~^/assets/min/css/components/a\.[0-9a-f]{10}\.css$~', $twin);
        $css = (string) file_get_contents($this->root . $twin);
        $this->assertStringNotContainsString('a note', $css);
        $this->assertStringContainsString('url(/assets/img/x.png)', $css, 'a relative url() no longer finds its file from the twin');

        // Edited on the server: the twin no longer matches, so the source is served.
        file_put_contents($this->root . '/assets/css/components/a.css', ".a{ color:blue }\n");
        Assets::configure($this->root);
        $this->assertStringStartsWith('/assets/css/components/a.css?v=', Assets::url('/assets/css/components/a.css'), 'a stale twin was served');
        $r = MinifiedAssets::buildCss($this->root);
        $this->assertSame([1, 1], [$r['made'], $r['removed']], 'the old twin outlived its source');
        $this->assertStringStartsWith('/assets/css/vendor/v.css?v=', Assets::url('/assets/css/vendor/v.css'));
    }

    public function test_off_under_the_harness_unless_asked_and_a_failed_minify_serves_the_source(): void
    {
        MinifiedAssets::enable(null);
        $this->assertFalse(MinifiedAssets::enabled(), 'rendered pages in the suite would name twins');
        MinifiedAssets::enable(true);
        $r = MinifiedAssets::build($this->root, 'js', static fn () => null);
        $this->assertSame(['/assets/js/s.js'], $r['failed']);
        $r = MinifiedAssets::build($this->root, 'js', static fn (string $s) => $s . '/* bigger */');
        $this->assertSame(['/assets/js/s.js'], $r['failed'], 'a twin larger than its source helps nobody');
        $this->assertStringStartsWith('/assets/js/s.js?v=', Assets::url('/assets/js/s.js'));
    }

    public function test_every_committed_twin_is_of_a_source_that_exists_and_smaller(): void
    {
        $public = dirname(__DIR__, 2) . '/public';
        Assets::configure($public);
        $n = 0;
        foreach (['js', 'css'] as $kind) {
            foreach (MinifiedAssets::sources($public, $kind) as [$url, $abs]) {
                $twin = $public . '/' . MinifiedAssets::DIR . '/' . $kind . substr($url, strlen('/assets/' . $kind), -strlen($kind) - 1) . '.' . Assets::contentHash($url) . '.' . $kind;
                if (!is_file($twin)) continue;
                $n++;
                $this->assertLessThan(filesize($abs), filesize($twin), "$url's twin is not smaller");
            }
        }
        $this->assertGreaterThan(50, $n, 'the committed twins are gone or none matches its source');
        $m = ChromeRender::code('src/Support/Maintenance.php');
        $this->assertStringContainsString("MinifiedAssets::buildCss(dirname(__DIR__, 2) . '/public')", $m, 'nothing on the host keeps the CSS twins current');
    }
}
