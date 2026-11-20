<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The canonical, share card and structured data come from the public layout, once, for
 * every page (10 Nov 2026). They were opt-in: 21 pages included partials/seo-head.twig from
 * their own head block, and every other public page — a registry profile among them, whose
 * controller built a Person record and a share image nothing printed — had a title and a
 * description and nothing else. A page that includes it as well would print every tag twice.
 */
final class SeoHeadEverywhereTest extends TestCase
{
    public function test_the_layout_prints_it_and_no_page_prints_it_again(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $this->assertStringContainsString("{% include 'partials/seo-head.twig' %}", (string) file_get_contents($root . '/layout/shell.twig'));

        $again = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/pages', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'twig' && preg_match("~\{%-?\s*include\s+'partials/seo-head\.twig'~", (string) file_get_contents($f->getPathname()))) {
                $again[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertSame([], $again, 'these pages print the SEO head a second time');
    }
}
