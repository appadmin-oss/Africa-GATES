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
}
