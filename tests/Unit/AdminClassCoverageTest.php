<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every `ad-…` class an admin template asks for must actually be styled somewhere.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS ENCODES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The event editor put its paired fields in `<div class="ad-form__row">`. The stylesheet
 * defines `.ad-form .row`. Nothing anywhere defines `.ad-form__row` — so the two-column
 * layout that markup was written for has NEVER ONCE RENDERED. Title and slug had been
 * stacking since the form was written, and so had four other pairs, and so had three pairs
 * on the blog-post editor.
 *
 * Nothing failed. The page is valid HTML, the CSS is valid CSS, and the browser silently
 * matches no rule. It was found by looking at a screenshot and asking why two fields that
 * were clearly meant to be side by side were not.
 *
 * The same shape had already appeared twice in the same week — `ad-hint` on the settings
 * screen where the real class is `st-hint`, and `ad-chip--ok` / `ad-chip--bad` on the
 * finance screen where the real names are `--green` and `--red`. Three instances of one
 * mistake: markup written against a stylesheet somebody remembered rather than read.
 *
 * ── WHAT THIS TEST DOES ──────────────────────────────────────────────────────
 *
 * Collects every literal `ad-…` class name used in an admin template, and every `.ad-…`
 * selector defined in the CSS or in any template's own `<style>` block, and fails on a name
 * that is used but never defined.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────────
 *
 * It does not check the reverse. An unused class in the stylesheet is dead weight, not a
 * broken page, and failing the build over it would mean deleting rules a future template
 * legitimately wants.
 *
 * It also skips interpolated names — `ad-chip--{{ tone }}` cannot be resolved from the
 * source, and pretending to check it would mean either false failures or a rule so loose it
 * catches nothing.
 */
final class AdminClassCoverageTest extends TestCase
{
    /**
     * Classes that are hooks rather than styling: JavaScript finds the element by them, or
     * they name it for a human reading the DOM, and the appearance comes from somewhere else.
     *
     * Each one needs a reason. "It looked fine" is not a reason — `ad-form__row` looked fine.
     */
    private const HOOKS = [
        // Styled entirely by an inline `style` attribute on the element itself; the class is
        // what the media-viewer script queries for.
        'ad-avatar-btn'  => 'JS hook; the button is styled inline on the element',
        'ad-photo-edit'  => 'JS hook on a <details>; styled inline on the element',
    ];

    public function testEveryAdminClassUsedIsAlsoDefined(): void
    {
        $used    = $this->classesUsedInAdminTemplates();
        $defined = $this->classesDefinedAnywhere();

        $missing = [];
        foreach ($used as $class => $where) {
            if (isset(self::HOOKS[$class])) {
                continue;
            }
            if (!isset($defined[$class])) {
                $missing[$class] = $where;
            }
        }

        $this->assertSame([], $missing, $this->explain($missing));
    }

    /**
     * The two names that started this, kept as their own case so a regression says WHICH rule
     * broke rather than only that something did.
     */
    public function testThePairedFieldRowIsTheDefinedOne(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/admin.css');

        $this->assertMatchesRegularExpression(
            '/\.ad-form\s+\.row\s*\{[^}]*grid-template-columns/',
            $css,
            '.ad-form .row is the selector the admin forms rely on for two-column rows.'
        );

        // border-box, without which `width:100%` plus padding overflows its grid column and
        // "side by side" fields overlap by thirty pixels. admin.css is the only stylesheet an
        // admin page loads, so it cannot inherit the reset from main.css.
        $this->assertMatchesRegularExpression(
            '/\*\s*,\s*\*::before\s*,\s*\*::after\s*\{[^}]*box-sizing:\s*border-box/',
            $css,
            'admin.css must carry its own border-box reset; it does not load main.css.'
        );
    }

    // ── collection ───────────────────────────────────────────────────────────

    /** @return array<string,string> class => the first file that used it */
    private function classesUsedInAdminTemplates(): array
    {
        $out = [];
        foreach ($this->twigFiles(__DIR__ . '/../../templates/admin') as $file) {
            $src = (string) file_get_contents($file);

            // Comments are bytes. A `{# … .ad-whatever … #}` note is not markup, and this
            // codebase has twice failed a source-scanning test on its own explanation of the
            // thing the test forbids.
            $src = (string) preg_replace('/\{#.*?#\}/s', '', $src);

            if (preg_match_all('/class="([^"]*)"/', $src, $m) === false) {
                continue;
            }
            foreach ($m[1] as $attr) {
                if (str_contains($attr, '{{') || str_contains($attr, '{%')) {
                    continue;               // interpolated; cannot be resolved from the source
                }
                foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                    if ($class !== '' && str_starts_with($class, 'ad-') && !isset($out[$class])) {
                        $out[$class] = str_replace(
                            (string) realpath(dirname(__DIR__, 2)) . '/',
                            '',
                            (string) realpath($file)
                        );
                    }
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,true> */
    private function classesDefinedAnywhere(): array
    {
        $sources = [];
        foreach ((array) glob(__DIR__ . '/../../public/assets/css/*.css') as $css) {
            $sources[] = (string) file_get_contents((string) $css);
        }
        // Templates carry their own <style> blocks, and a class defined in one of those is as
        // real as one in the stylesheet.
        foreach ($this->twigFiles(__DIR__ . '/../../templates') as $file) {
            $src = (string) file_get_contents($file);
            if (str_contains($src, '<style')) {
                $sources[] = $src;
            }
        }

        $out = [];
        foreach ($sources as $src) {
            if (preg_match_all('/\.(ad-[A-Za-z0-9_-]+)/', $src, $m)) {
                foreach ($m[1] as $class) {
                    $out[$class] = true;
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function twigFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.twig')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /** @param array<string,string> $missing */
    private function explain(array $missing): string
    {
        if ($missing === []) {
            return '';
        }
        $lines = ["These admin classes are used but never defined, so they style nothing:"];
        foreach ($missing as $class => $where) {
            $lines[] = sprintf('  .%s — used in %s', $class, $where);
        }
        $lines[] = '';
        $lines[] = 'Either define the rule, use the name the stylesheet actually has, or — if the';
        $lines[] = 'class is only a JavaScript or semantic hook — add it to self::HOOKS with a reason.';
        return implode("\n", $lines);
    }
}
