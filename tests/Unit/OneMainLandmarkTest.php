<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * One `<main>` per page.
 *
 * Both layouts own the landmark — `gates.twig` and `shell.twig` each render
 * `<main id="main">` around their content block — so a page that opens its own puts a
 * second main landmark inside the first, with a second `id="main"`. Four did: the
 * challenge page, the challenge list and both nominee-confirmation screens. A screen
 * reader's landmark list then offers two "main" regions, and the skip link's
 * `#main` has two targets, of which browsers pick the first: the outer one, which is
 * the one the skip link was meant to get past to.
 *
 * Nothing renders wrongly and no validator in the build would notice — which is why it
 * is a sweep and not a review comment.
 */
final class OneMainLandmarkTest extends TestCase
{
    public function test_no_page_inside_a_layout_opens_a_second_main(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $bad  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $src = (string) file_get_contents($f->getPathname());
            if (!preg_match('~\{%\s*extends\s+[\'"]layout/(gates|shell)\.twig[\'"]~', $src)) continue;
            // Comments are not markup.
            $src = (string) preg_replace('~\{#.*?#\}~s', '', $src);
            if (preg_match('~<main\b~i', $src)) {
                $bad[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertSame([], $bad, "a page opens its own <main> inside the layout's:\n" . implode("\n", $bad));
    }

    public function test_each_layout_has_exactly_one(): void
    {
        foreach (['gates', 'shell'] as $l) {
            $src = (string) preg_replace('~\{#.*?#\}~s', '',
                (string) file_get_contents(dirname(__DIR__, 2) . "/templates/layout/{$l}.twig"));
            $this->assertSame(1, preg_match_all('~<main\b[^>]*\bid="main"~', $src), "{$l}.twig");
        }
    }
}
