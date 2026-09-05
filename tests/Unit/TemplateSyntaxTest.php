<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Every template must at least PARSE. One did not, and it shipped.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE WAS WRITTEN FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `admin/payments-triage.twig` carried its confirmation script BELOW its
 * `{% endblock %}`. Twig refuses any content outside a block in a template that
 * extends another, so the file was not a template with a dead script in it — it
 * was a SyntaxError, and `/admin/payments` returned a 500 rather than a page.
 *
 * That page is the platform's only view of charges the gateway took and we never
 * recorded. It was written during an incident about missing money, and it had
 * been unopenable since the commit that last touched it. Nothing caught that: the
 * suite covers the SERVICES behind these screens thoroughly, and had no test that
 * so much as compiled the screens.
 *
 * ── WHY IT PARSES RATHER THAN RENDERS ────────────────────────────────────────
 *
 * Rendering needs the container, a database, a session and a plausible set of
 * variables per template — which is why per-page render tests exist for a handful
 * of pages and cannot exist for all 200. Parsing needs none of that and catches
 * the entire class of error that makes a page impossible to open at all:
 * unclosed tags, `{% endblock %}` in the wrong place, a mistyped block name, a
 * stray `{%`. A cheap check over everything beats an expensive check over a few.
 *
 * Unknown functions and filters are deliberately tolerated. This asserts nothing
 * about whether `asset()` exists — the container owns that question — only that
 * the file is well-formed Twig.
 */
final class TemplateSyntaxTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/templates';
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(self::root()), ['cache' => false]);

        // The app registers its own functions, filters and globals through the
        // container. Standing that up here would make a syntax check depend on a
        // database; instead anything unrecognised resolves to a no-op so that a
        // genuine STRUCTURAL error is the only thing that can fail this test.
        $twig->registerUndefinedFunctionCallback(
            static fn (string $name) => new TwigFunction($name, static fn (...$a) => '')
        );
        $twig->registerUndefinedFilterCallback(
            static fn (string $name) => new TwigFilter($name, static fn ($v, ...$a) => $v)
        );

        return $twig;
    }

    /** @return list<string> every .twig path, relative to templates/ */
    private static function templates(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root()));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'twig') {
                $out[] = substr($f->getPathname(), strlen(self::root()) + 1);
            }
        }
        sort($out);
        return $out;
    }

    public function test_every_template_parses(): void
    {
        $twig    = $this->twig();
        $broken  = [];
        $checked = 0;

        foreach (self::templates() as $rel) {
            $checked++;
            try {
                // load() compiles: it runs the lexer, the parser and every node
                // visitor, which is where "content outside a block" is raised.
                $twig->load($rel);
            } catch (\Twig\Error\SyntaxError $e) {
                $broken[] = $rel . ' — ' . $e->getRawMessage() . ' (line ' . $e->getTemplateLine() . ')';
            } catch (\Twig\Error\LoaderError $e) {
                // An {% extends %} or {% include %} pointing at a file that does
                // not exist is exactly as fatal as a syntax error at request time.
                $broken[] = $rel . ' — ' . $e->getRawMessage();
            } catch (\Throwable $e) {
                $broken[] = $rel . ' — ' . get_class($e) . ': ' . $e->getMessage();
            }
        }

        $this->assertGreaterThan(50, $checked, 'the template sweep found suspiciously few files');
        $this->assertSame([], $broken, "Templates that cannot be parsed:\n  " . implode("\n  ", $broken));
    }

    /**
     * The specific shape of the bug, named so a regression reads as itself rather
     * than as one line inside a list of two hundred.
     */
    public function test_no_child_template_puts_markup_after_its_last_endblock(): void
    {
        $offenders = [];

        foreach (self::templates() as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            if (!preg_match('/\{%-?\s*extends\s/', $src)) continue;

            $pos = strrpos($src, '{% endblock %}');
            if ($pos === false) $pos = strrpos($src, 'endblock');
            if ($pos === false) continue;

            $tail = trim(substr($src, $pos + strlen('{% endblock %}')));
            // Comments after the last block are harmless; markup is not.
            $tail = trim((string) preg_replace('/\{#.*?#\}/s', '', $tail));
            if ($tail !== '') $offenders[] = $rel . ' — trailing: ' . substr($tail, 0, 60);
        }

        $this->assertSame([], $offenders,
            "A child template's content must live inside a block; anything after the last\n"
            . "{% endblock %} makes the whole page a SyntaxError:\n  " . implode("\n  ", $offenders));
    }

    /**
     * A block the parent never outputs is silently discarded — no error, no page.
     *
     * The same triage template declared `{% block head %}`, and the admin layout's
     * style slot is `head_styles`. Twig does not object: an override of a block the
     * parent does not render is simply dropped. So the page's entire stylesheet
     * vanished, and the only symptom was an admin screen that looked like unstyled
     * HTML — which reads as a CSS problem and sends you looking in the wrong file.
     *
     * Parsing cannot catch this, and neither can a reviewer who has not memorised
     * which of `head`, `head_styles` and `styles` each of the four layouts uses.
     */
    public function test_no_template_overrides_a_block_its_parent_does_not_render(): void
    {
        $dead = [];

        foreach (self::templates() as $rel) {
            $src = (string) file_get_contents(self::root() . '/' . $rel);
            if (!preg_match('/\{%-?\s*extends\s+[\'"]([^\'"]+)[\'"]/', $src, $m)) continue;

            $mine  = self::blockNames($src);
            $theirs = [];
            // Walk the whole inheritance chain: a block may be declared by a
            // grandparent, and a two-level layout is normal here.
            $parent = $m[1];
            for ($hop = 0; $hop < 6 && $parent !== null; $hop++) {
                $path = self::root() . '/' . $parent;
                if (!is_file($path)) break;
                $psrc   = (string) file_get_contents($path);
                $theirs = array_merge($theirs, self::blockNames($psrc));
                $parent = preg_match('/\{%-?\s*extends\s+[\'"]([^\'"]+)[\'"]/', $psrc, $pm) ? $pm[1] : null;
            }

            foreach (array_diff($mine, $theirs) as $b) {
                $dead[] = $rel . ' — {% block ' . $b . ' %} is not rendered by ' . $m[1];
            }
        }

        $this->assertSame([], $dead,
            "These blocks are silently thrown away at render time:\n  " . implode("\n  ", $dead));
    }

    /** @return list<string> */
    private static function blockNames(string $src): array
    {
        preg_match_all('/\{%-?\s*block\s+([a-zA-Z0-9_]+)/', $src, $m);
        return array_values(array_unique($m[1]));
    }
}
