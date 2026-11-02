<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Compiled templates are on in production unless somebody said no (2 Nov 2026).
 *
 * The cache used to need `TWIG_CACHE=true` in `.env`, on a host with no shell to check it, and
 * without it every request compiled every template it touched: 90–165ms of server time a page
 * against 22–37ms with it. What this holds: the default follows the environment (on outside
 * development), `TWIG_CACHE=false` still wins, and the cache is used only where its directory
 * can be written — Twig fails every page when it cannot write, which is worse than slow.
 */
final class TemplateCacheTest extends TestCase
{
    public function test_the_template_cache_is_on_by_default_in_production_and_only_where_it_can_write(): void
    {
        $src = ChromeRender::code('config/container.php');
        $this->assertStringContainsString("Env::bool('TWIG_CACHE', !\$isDev)", $src, 'the cache needs a line in .env again');
        $this->assertStringNotContainsString("'cache' => Env::bool('TWIG_CACHE') ?", $src);
        $this->assertMatchesRegularExpression('~is_writable\(\$twigCacheDir\)~', $src, 'an unwritable cache directory would fail every page');
        $this->assertMatchesRegularExpression("~'cache' => \\\$twigCache \? \\\$twigCacheDir : false~", $src);
    }

    /**
     * A deploy is a zip extracted onto the host, which restores each file's time from when
     * the zip was BUILT — older than a compiled copy made in between. Twig's own loader then
     * calls the old compiled page fresh and serves it; ours names a new compiled class.
     * The control case is asserted too, so this test is seen to name the fault it exists for.
     */
    public function test_a_template_replaced_by_an_older_file_is_compiled_again(): void
    {
        $base = sys_get_temp_dir() . '/ag-tpl-' . bin2hex(random_bytes(4));
        mkdir($base . '/t', 0777, true);
        $render = function (string $loaderClass) use ($base): string {
            $env = new \Twig\Environment(new $loaderClass($base . '/t'), ['cache' => $base . '/c-' . md5($loaderClass), 'auto_reload' => true]);
            return $env->render('p.twig');
        };
        foreach ([\Twig\Loader\FilesystemLoader::class => 'stale', \AfricaGates\Support\TemplateLoader::class => 'fresh'] as $cls => $want) {
            file_put_contents($base . '/t/p.twig', 'old page');
            touch($base . '/t/p.twig', time() - 3600);
            $this->assertSame('old page', $render($cls));
            // The deploy: new content, and a file time from BEFORE the compile above.
            file_put_contents($base . '/t/p.twig', 'new page!');
            touch($base . '/t/p.twig', time() - 1800);
            clearstatcache();
            $this->assertSame($want === 'fresh' ? 'new page!' : 'old page', $render($cls),
                $want === 'fresh' ? 'the deployed template is not the one being served' : 'the control no longer reproduces the fault');
        }
        $this->assertStringContainsString('new \\AfricaGates\\Support\\TemplateLoader(', ChromeRender::code('config/container.php'), 'the app is not using the loader');
    }
}
