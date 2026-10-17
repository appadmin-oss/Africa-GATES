<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A CLASS THAT CLAIMS TO HIDE SOMETHING FROM SIGHT MUST EXIST.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS KIND OF CLASS AND NOT EVERY CLASS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A misspelt class name fails in total silence — no error, no warning, no console
 * line — and for most classes the cost is a missing rounded corner. For THIS kind it is
 * two faults at once, and neither announces itself:
 *
 *   · The text renders. A label written to be read only by a screen reader appears on
 *     the page, in the middle of a control, in whatever the inherited type happens to be.
 *   · And it takes up space. Which is how it was found: `class="ag-sr-only"` — a name
 *     that exists nowhere in this codebase, the real one being `.sr-only` — put the words
 *     "Search awards" into a flex row on `/results`, ate eighty pixels of a 430px screen,
 *     and pushed the Search button off the right-hand edge.
 *
 * The push is the expensive half, because `html` and `body` carry `overflow-x: clip`.
 * Clipped is worse than scrolled: there is no scrollbar, no swipe, and no way for a
 * reader to tell that anything is missing at all. The control was simply gone, on the
 * platform's primary device.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT READS THE SHIPPED CSS, NOT A LIST OF APPROVED NAMES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A list here would be a second place to keep true, and the first thing it would do is
 * bless `ag-sr-only` the day somebody adds it to the list instead of to a stylesheet.
 * So the question asked is the only one that matters: does a rule for this class exist
 * anywhere a browser will load?
 */
final class SrOnlyClassTest extends TestCase
{
    /**
     * The shape of a WHOLE class name that promises to hide something.
     *
     * Anchored, because a class name is a complete token: an unanchored `\b` after
     * `sr-only` also matches inside `sr-only-ish-thing`, which is a different class with a
     * different job and reporting it would be an invented finding.
     */
    private const INTENT = '/^(?:[a-z0-9]+-)*(?:sr-only|screen-reader|visually-hidden|vh-only)$/i';

    /** Every class name used in a template, with the file that used it. */
    private function claimed(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates'));

        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;

            $rel  = str_replace($root . '/', '', $f->getPathname());

            // ── COMMENTS ARE STRIPPED, AND A CSS COMMENT COUNTS ──────────────
            //
            // A Twig comment reaches no reader, and neither does a `/* */` inside a
            // `<style>` block. Both are stripped, and the second one is not hypothetical:
            // the note explaining this very fault QUOTES the broken class name, and the
            // first run of this sweep reported the comment as the offence. This codebase
            // has the rule written down already — a comment explaining a removal must be
            // able to name what it removed — and the fix is always to sweep what a READER
            // sees rather than to make comments unwritable.
            $body = (string) preg_replace('/\{#.*?#\}/s', '',
                (string) file_get_contents($f->getPathname()));
            $body = (string) preg_replace('!/\*.*?\*/!s', ' ', $body);

            if (!preg_match_all('/\bclass\s*=\s*"([^"]*)"/i', $body, $m)) continue;

            foreach ($m[1] as $list) {
                // Twig expressions inside the attribute are stripped rather than parsed:
                // a conditional class is still a literal name on one of its branches, and
                // the names survive the strip.
                $list = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $list);

                foreach (preg_split('/\s+/', trim($list)) as $c) {
                    if ($c !== '' && preg_match(self::INTENT, $c)) $out[$c][$rel] = true;
                }
            }
        }

        return $out;
    }

    /** Every stylesheet a page can load, plus every inline block, as one body. */
    private function css(): string
    {
        $root = dirname(__DIR__, 2);
        $out  = '';

        foreach (['/public/assets/css', '/templates'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $dir));

            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $path = $f->getPathname();

                if (str_ends_with($path, '.css')) {
                    $out .= "\n" . file_get_contents($path);
                    continue;
                }

                // An inline <style> in a template is a stylesheet a browser loads.
                if (str_ends_with($path, '.twig')
                    && preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is',
                                      (string) file_get_contents($path), $m)) {
                    $out .= "\n" . implode("\n", $m[1]);
                }
            }
        }

        return $out;
    }

    public function test_every_visually_hidden_class_a_template_uses_actually_exists(): void
    {
        $css = $this->css();
        $bad = [];

        foreach ($this->claimed() as $class => $files) {
            if (preg_match('/\.' . preg_quote($class, '/') . '\b/', $css)) continue;

            $bad[] = sprintf('.%s is used by %s and defined in no stylesheet — the text '
                           . 'renders, and takes up room', $class,
                             implode(', ', array_keys($files)));
        }

        sort($bad);

        $this->assertSame([], $bad, implode("\n  ", $bad));
    }

    public function test_the_sweep_would_catch_the_one_that_shipped(): void
    {
        // Proving it fails before trusting it passing. `ag-sr-only` is the exact name that
        // shipped on two pages, and the regex has to recognise a PREFIXED spelling — the
        // obvious version matches the bare `sr-only` and misses every house-prefixed
        // variant, which is the only kind anybody invents.
        $this->assertMatchesRegularExpression(self::INTENT, 'ag-sr-only');
        $this->assertMatchesRegularExpression(self::INTENT, 'sr-only');
        $this->assertMatchesRegularExpression(self::INTENT, 'visually-hidden');

        // And it must not fire on ordinary names that merely contain the letters.
        $this->assertDoesNotMatchRegularExpression(self::INTENT, 'sr-only-ish-thing');
        $this->assertDoesNotMatchRegularExpression(self::INTENT, 'hf-sort');
    }

    public function test_the_one_that_exists_is_the_one_being_used(): void
    {
        // Not a list of approved names — this asserts the utility is real, so the sweep
        // above has something to resolve against. A rule deleted from the sheet while the
        // templates still name it is the same fault in the other direction.
        $this->assertMatchesRegularExpression('/\.sr-only\s*\{[^}]*position\s*:\s*absolute/',
            $this->css(),
            'the visually-hidden utility is gone, so every label using it is now visible');
    }
}
