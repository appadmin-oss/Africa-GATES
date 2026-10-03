<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The October favicon (handoff Part C), and the three things about it that are rules
 * rather than artwork.
 *
 * The browser behaviour — priority, the 30-second error, write rate, reduced motion,
 * the hidden-tab title — was measured in Chromium when this shipped and is described
 * in favicon.js. What can be held from the source is held here:
 *
 *   · the mark has COLOUR. The handoff SVG named two classes and styled neither, so it
 *     rendered a solid black disc in every browser and every scheme;
 *   · nothing but favicon.js touches the favicon, as the handoff requires;
 *   · the requests the states describe actually report to it — a busy ring nothing
 *     sets is a feature that exists only in a README.
 */
final class FaviconTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function test_every_class_the_mark_uses_has_a_fill_in_both_schemes(): void
    {
        $svg = (string) file_get_contents(self::ROOT . '/public/favicon.svg');

        preg_match_all('~class="([a-z]+)"~', $svg, $m);
        $classes = array_unique($m[1]);
        $this->assertNotEmpty($classes);

        [$light, $dark] = array_pad(preg_split('~@media\s*\(prefers-color-scheme:\s*dark\)~', $svg, 2), 2, '');
        foreach ($classes as $c) {
            $this->assertMatchesRegularExpression('~\.' . $c . '\{fill:#[0-9a-f]{6}\}~i', $light,
                ".{$c} has no fill, so it draws in default black");
            $this->assertMatchesRegularExpression('~\.' . $c . '\{fill:#[0-9a-f]{6}\}~i', $dark,
                ".{$c} does not follow the dark scheme");
        }
        $this->assertStringNotContainsString('c2pa:manifest', $svg,
            'a content credential hashed over bytes this file no longer has is an invalid credential');
    }

    /** The SVG's colours are the PNG set's colours, so every browser shows one mark. */
    public function test_the_svg_matches_its_rasterised_siblings(): void
    {
        $svg = strtolower((string) file_get_contents(self::ROOT . '/public/favicon.svg'));
        $im  = imagecreatefrompng(self::ROOT . '/public/assets/icons/favicon-512.png');
        $hex = static fn (int $x, int $y): string => sprintf('#%06x', imagecolorat($im, $x, $y) & 0xFFFFFF);

        $this->assertStringContainsString('.d{fill:' . $hex(60, 256) . '}', $svg, 'disc');
        $this->assertStringContainsString('.l{fill:' . $hex(174, 205) . '}', $svg, 'continent');
    }

    public function test_nothing_but_the_script_touches_the_favicon(): void
    {
        $bad = [];
        $files = array_merge(glob(self::ROOT . '/public/assets/js/*.js') ?: [],
            iterator_to_array(new \RegexIterator(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::ROOT . '/templates')), '~\.twig$~'), false));
        foreach ($files as $f) {
            $path = is_string($f) ? $f : $f->getPathname();
            if (str_ends_with($path, '/favicon.js')) continue;
            $src = (string) file_get_contents($path);
            // `#` as the delimiter: the attribute operators `*=`, `~=` and `^=` belong in
            // the class, and with `~` as the delimiter this pattern did not compile — so
            // preg_match returned false on every file and the sweep passed over nothing.
            $hit = preg_match('#getElementById\([\'"]agFavicon|link\[rel[*~^|]?=[\'"]?(shortcut )?icon|\.rel\s*=\s*[\'"](shortcut )?icon[\'"]#', $src);
            $this->assertNotFalse($hit, 'the sweep pattern does not compile');
            if ($hit) {
                $bad[] = substr($path, strlen(self::ROOT) + 1);
            }
        }
        $this->assertSame([], $bad, "something other than favicon.js writes the favicon:\n" . implode("\n", $bad));
    }

    public function test_its_colours_are_tokens_that_exist(): void
    {
        $js     = (string) file_get_contents(self::ROOT . '/public/assets/js/favicon.js');
        // Colour tokens are emitted by Support\Accent, the rest live in tokens.css.
        $tokens = \AfricaGates\Support\Accent::css() . (string) file_get_contents(self::ROOT . '/public/assets/css/tokens.css');

        preg_match_all("~token\('(--ag-[a-z0-9-]+)'~", $js, $m);
        $this->assertGreaterThanOrEqual(5, count($m[1]));
        foreach ($m[1] as $t) {
            $this->assertMatchesRegularExpression('~' . preg_quote($t, '~') . '\s*:~', $tokens, "{$t} is not a token");
        }
        // Outside `token()` fallbacks, no literal colour.
        $bare = preg_replace("~token\('--ag-[a-z0-9-]+',\s*'#[0-9a-f]{3,6}'\)~i", '', $js);
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) $bare);
        $this->assertDoesNotMatchRegularExpression('~#[0-9a-f]{6}\b~i', (string) $code, 'a typed colour');
    }

    public function test_the_manifest_names_the_new_icons(): void
    {
        $m = json_decode((string) file_get_contents(self::ROOT . '/public/site.webmanifest'), true);
        $srcs = array_column($m['icons'] ?? [], 'src');
        $this->assertContains('/assets/icons/favicon-192.png', $srcs);
        $this->assertContains('/assets/icons/favicon-512.png', $srcs);
    }
}
