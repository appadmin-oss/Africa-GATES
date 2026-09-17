<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A coloured area must say what it means in words. Colour is the accelerator, never the fact.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHO THIS IS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Roughly one man in twelve and one woman in two hundred cannot separate two of the
 * hues on this platform, and every screen-reader user gets none of them. A green pill
 * that means "open" and an amber one that means "withheld" are, to those readers, two
 * identical pills — and on this site the difference between them is whether somebody
 * has an award.
 *
 * So: anything painted with a role token either NAMES ITSELF, or is marked decorative
 * and has something beside it that names it. There is no third option, and the second
 * is the tile's shape — `partials/tile.twig` puts `aria-hidden` on the mark and leaves
 * the word alone.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS SWEEP READS CSS BEFORE IT READS MARKUP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Almost no coloured element on this platform says so in its own tag. The colour arrives
 * through a class — `.hf-chip`, `.hf-pg i` — declared in a style block somewhere else in
 * the file, so a sweep that greps markup for `var(--ag-honour-fill)` finds the two inline
 * cases and reports a clean pass over everything that actually carries colour. It has to
 * resolve which SELECTORS paint, then find the elements those selectors reach.
 *
 * Three things that made an earlier colour sweep of mine lie, all fixed here:
 *
 *   - A CSS COMMENT ABOVE A RULE BECOMES PART OF ITS SELECTOR when you split on `}`, so
 *     the rule is skipped — and a sweep goes quiet in exactly the files somebody troubled
 *     to document. Comments are stripped first.
 *   - A BARE TAG IN A DESCENDANT SELECTOR IS NOT A GLOBAL ONE. `.hf-pg i` paints one `<i>`
 *     inside one component; matching on the last compound alone condemns every `<i>` on
 *     the site. The ancestor compounds are matched against the real element stack.
 *   - `{{ name }}` IS CONTENT AND `{% if %}` IS NOT. Strip the second and the first
 *     disappears with it unless it is preserved deliberately, and then every Twig-driven
 *     label on the platform reads as an empty element.
 *
 * The scan is deliberately conservative: an element whose closing tag it cannot find is
 * skipped rather than guessed at. A sweep that invents findings gets switched off, and
 * this one is guarding an accessibility rule that has to survive being unpopular.
 */
final class ColourIsNeverAloneTest extends TestCase
{
    /** Painted by a role, by the tile's own slots, or by a programme's identity hue. */
    private const PAINTS = '/background(?:-color)?\s*:[^;{}]*var\(\s*--(?:ag-(?:honour|action|live|caution|fault)-(?:fill|wash)|tile-(?:fill|wash)|pg-fill)/i';

    /** Never pushed on the stack: they cannot contain anything. */
    private const VOID = ['img', 'br', 'hr', 'input', 'meta', 'link', 'source', 'track',
                          'area', 'base', 'col', 'embed', 'param', 'wbr', 'use', 'path',
                          'circle', 'rect', 'line', 'polygon', 'stop'];

    /** @return list<string> absolute paths of every shipped template */
    private function templateFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates'));

        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getPathname(), '.twig')) {
                $out[] = $f->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /** Every stylesheet a public page loads, as one body. */
    private function siteCss(): string
    {
        $root = dirname(__DIR__, 2);
        $out  = '';

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/public/assets/css'));

        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getPathname(), '.css')) {
                $out .= "\n" . file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    /**
     * Selectors that paint, each as a list of compounds to match against an element stack.
     *
     * `.hf-pg i` becomes [['classes' => ['hf-pg'], 'tag' => null], ['classes' => [], 'tag' => 'i']]
     * — the last compound must match the element itself and the earlier ones must each match
     * some ancestor, in order. A child combinator is read as a descendant one: looser, so it
     * can over-report rather than miss, which is the right way round for this rule.
     *
     * @return list<list<array{classes:list<string>,tag:?string}>>
     */
    private function paintingSelectors(string $css): array
    {
        // Comments first. A comment left in place is absorbed into the NEXT rule's selector
        // and the rule then matches nothing at all.
        $css = (string) preg_replace('!/\*.*?\*/!s', ' ', $css);

        // At-rules carry a nested block; flattening them keeps their rules in scope, which
        // is right — a media query does not make a colour any less alone.
        $css = str_replace(['@media', '@supports'], '', $css);

        $out = [];

        foreach (explode('}', $css) as $chunk) {
            $at = strrpos($chunk, '{');
            if ($at === false) continue;

            $selectors = substr($chunk, 0, $at);
            $body      = substr($chunk, $at + 1);

            if (!preg_match(self::PAINTS, $body)) continue;

            foreach (explode(',', $selectors) as $sel) {
                // Pseudo-classes and pseudo-elements are dropped: a `::before` swatch is
                // painted inside its element, so the element is what has to name it.
                $sel = (string) preg_replace('/::?[a-z-]+(\([^)]*\))?/i', '', $sel);
                $sel = trim((string) preg_replace('/\s*[>+~]\s*/', ' ', $sel));

                if ($sel === '' || str_contains($sel, '@')) continue;

                $compounds = [];

                foreach (preg_split('/\s+/', $sel) as $part) {
                    preg_match_all('/\.([A-Za-z0-9_-]+)/', $part, $cm);
                    $tag = preg_match('/^([a-z][a-z0-9]*)/i', $part, $tm) ? strtolower($tm[1]) : null;

                    if ($cm[1] === [] && $tag === null) continue 2;   // an id or an attribute; skip
                    $compounds[] = ['classes' => $cm[1], 'tag' => $tag];
                }

                if ($compounds !== []) $out[] = $compounds;
            }
        }

        return $out;
    }

    /**
     * Every element in one template that a painting selector reaches, with what it holds.
     *
     * @return list<array{line:int,tag:string,attrs:string,inner:string,sibling:string}>
     */
    private function paintedElements(string $body, array $selectors): array
    {
        // `{{ x }}` is what a reader is given; `{% if %}` is not. The first becomes a
        // sentinel so it survives tag-stripping as content, the second and Twig comments go.
        // Line numbers have to survive, or the finding names a line nobody can look at —
        // so anything removed is replaced by its own newlines rather than by nothing.
        $blank = static fn (array $m): string
            => str_repeat("\n", substr_count($m[0], "\n"));

        $body = (string) preg_replace_callback('/\{#.*?#\}/s', $blank, $body);
        $body = (string) preg_replace_callback('/<(style|script)\b[^>]*>.*?<\/\1>/is', $blank, $body);
        $body = (string) preg_replace_callback('/\{\{.*?\}\}/s',
            static fn (array $m): string => "\x01" . $blank($m), $body);
        $body = (string) preg_replace_callback('/\{%.*?%\}/s',
            static fn (array $m): string => ' ' . $blank($m), $body);

        // An `alt` is an accessible name and vanishes with the tag it sits on, so it is
        // promoted to content before anything is stripped.
        $body = (string) preg_replace('/<img\b[^>]*\balt\s*=\s*"(?!\s*")[^"]*"[^>]*>/i', "\x01", $body);

        $found   = [];
        $stack   = [];
        $pending = [];          // painted elements waiting for their parent to close
        $next    = 0;

        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(\/?)>/s',
                       $body, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($tags as $t) {
            [$whole, $at] = $t[0];
            $closing = $t[1][0] !== '';
            $name    = strtolower($t[2][0]);
            $attrs   = $t[3][0];
            $self    = $t[4][0] !== '';

            if ($closing) {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] !== $name) continue;

                    $el = $stack[$i];
                    $stack = array_slice($stack, 0, $i);

                    $record = [
                        'line'    => substr_count(substr($body, 0, $el['start']), "\n") + 1,
                        'tag'     => $el['tag'],
                        'attrs'   => $el['attrs'],
                        'inner'   => substr($body, $el['end'], $at - $el['end']),
                        'sibling' => '',
                    ];

                    // A word usually sits AFTER the mark it explains, so the sibling text
                    // cannot be read until the parent closes — resolving it from what has
                    // been seen so far reports every tile on the platform as a bare dot.
                    if ($el['painted']) {
                        $parent = $stack === [] ? null : $stack[count($stack) - 1];

                        if ($parent === null) {
                            $found[] = $record;
                        } else {
                            $pending[$parent['id']][] =
                                [$record, $el['start'], $at + strlen($whole)];
                        }
                    }

                    // Anything waiting on THIS element now has its parent's full content,
                    // so the sibling text is that content with the child's span cut out.
                    foreach ($pending[$el['id']] ?? [] as [$rec, $from, $to]) {
                        $rec['sibling'] = substr($body, $el['end'], $from - $el['end'])
                                        . substr($body, $to, $at - $to);
                        $found[] = $rec;
                    }
                    unset($pending[$el['id']]);

                    break;
                }

                continue;
            }

            $classes = preg_match('/\bclass\s*=\s*"([^"]*)"/i', $attrs, $cm)
                     ? preg_split('/\s+/', trim((string) preg_replace('/\{\{.*?\}\}|\x01/s', ' ', $cm[1])))
                     : [];
            $classes = array_values(array_filter($classes));

            $el = ['tag' => $name, 'attrs' => $attrs, 'classes' => $classes,
                   'id' => $next++, 'start' => $at, 'end' => $at + strlen($whole)];

            // Inline `style` beats every selector: if the tag paints itself, it paints.
            $painted = preg_match(self::PAINTS, $attrs) === 1
                    || str_contains($attrs, 'tile_style')
                    || $this->reached($el, $stack, $selectors);

            if ($self || in_array($name, self::VOID, true)) continue;

            $el['painted'] = $painted;
            $stack[] = $el;
        }

        return $found;
    }

    /** Does any painting selector reach this element, given the ancestors above it? */
    private function reached(array $el, array $stack, array $selectors): bool
    {
        foreach ($selectors as $compounds) {
            $last = $compounds[count($compounds) - 1];
            if (!$this->reaches($el, $last)) continue;

            // Ancestors, in order, anywhere above. A descendant combinator says nothing
            // about depth, so walking the stack from the outside in is the whole test.
            $need = array_slice($compounds, 0, -1);
            $i    = 0;

            foreach ($stack as $up) {
                if ($i < count($need) && $this->reaches($up, $need[$i])) $i++;
            }

            if ($i === count($need)) return true;
        }

        return false;
    }

    private function reaches(array $el, array $compound): bool
    {
        if ($compound['tag'] !== null && $compound['tag'] !== $el['tag']) return false;

        foreach ($compound['classes'] as $c) {
            if (!in_array($c, $el['classes'], true)) return false;
        }

        return true;
    }

    /** Is there anything here a reader who cannot see the colour would receive? */
    private function saysSomething(string $markup): bool
    {
        $text = (string) preg_replace('/<[^>]*>/s', ' ', $markup);

        return preg_match('/[\x01\p{L}\p{N}]/u', $text) === 1;
    }

    private function named(array $el): bool
    {
        return preg_match('/\baria-label(?:ledby)?\s*=\s*"(?!\s*")/i', $el['attrs']) === 1
            || preg_match('/\btitle\s*=\s*"(?!\s*")/i', $el['attrs']) === 1
            || $this->saysSomething($el['inner']);
    }

    private function hidden(array $el): bool
    {
        return preg_match('/\baria-hidden\s*=\s*"true"/i', $el['attrs']) === 1;
    }

    /** @return list<string> every violation in one body, ready to print */
    private function violations(string $rel, string $body, array $selectors): array
    {
        $out = [];

        foreach ($this->paintedElements($body, $selectors) as $el) {
            if ($this->named($el)) continue;

            if (!$this->hidden($el)) {
                $out[] = sprintf(
                    '%s:%d <%s> carries colour and says nothing — give it a word, an '
                  . 'aria-label, or aria-hidden="true" plus something beside it that names it',
                    $rel, $el['line'], $el['tag']);
                continue;
            }

            if (!$this->saysSomething($el['sibling'])) {
                $out[] = sprintf(
                    '%s:%d <%s> is marked decorative and nothing beside it names it — '
                  . 'a colour-only signal for every reader who cannot see the hue',
                    $rel, $el['line'], $el['tag']);
            }
        }

        return $out;
    }

    public function test_nothing_on_this_platform_carries_colour_without_a_word(): void
    {
        $root = dirname(__DIR__, 2);
        $css  = $this->siteCss();
        $bad  = [];

        foreach ($this->templateFiles() as $path) {
            $body = (string) file_get_contents($path);
            $rel  = str_replace($root . '/', '', $path);

            // A template's own style block paints only that template, so the selectors are
            // resolved per file — site sheet plus this file's block.
            $local = $css;
            if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $body, $sm)) {
                $local .= "\n" . implode("\n", $sm[1]);
            }

            foreach ($this->violations($rel, $body, $this->paintingSelectors($local)) as $v) {
                $bad[] = $v;
            }
        }

        $this->assertSame([], $bad,
            "colour is carrying meaning on its own:\n  " . implode("\n  ", $bad));
    }

    public function test_the_tile_hides_its_mark_and_leaves_its_word_alone(): void
    {
        // The device that carries colour on this platform, and the shape every other
        // coloured element is measured against: the saturated square is decoration and is
        // hidden, the word beside it is the fact and is not.
        $tile = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/partials/tile.twig');

        $this->assertMatchesRegularExpression(
            '/<span class="ag-tile__mark" aria-hidden="true">\s*<\/span>/', $tile,
            'the tile mark must be hidden — a screen reader gets the sentence, and the '
          . 'colour adds nothing it needs');

        $this->assertStringContainsString('<span>{{ label }}</span>', $tile,
            'the word must not be hidden with the mark, or the tile becomes colour alone');

        $this->assertStringNotContainsString('aria-hidden="true"><span>{{ label }}', $tile);
    }

    public function test_a_coloured_element_with_no_word_is_reported(): void
    {
        // Proven against the sweep rather than assumed of it: a bar painted by a class,
        // holding nothing, unmarked.
        $css  = '.bar{ background:var(--ag-honour-fill); }';
        $bad  = $this->violations('x.twig',
            '<style>' . $css . '</style><div><span class="bar"></span></div>',
            $this->paintingSelectors($css));

        $this->assertCount(1, $bad);
        $this->assertStringContainsString('carries colour and says nothing', $bad[0]);
    }

    public function test_a_decorative_mark_beside_a_word_is_accepted_and_alone_is_not(): void
    {
        $css = '.dot{ background:var(--ag-live-fill); }';
        $sel = $this->paintingSelectors($css);

        // The tile's shape: hidden mark, word beside it.
        $this->assertSame([], $this->violations('x.twig',
            '<p class="pg"><i class="dot" aria-hidden="true"></i>{{ programme }}</p>', $sel));

        // The same mark with nothing beside it is the fault this rule exists for.
        $bad = $this->violations('x.twig',
            '<p class="pg"><i class="dot" aria-hidden="true"></i></p>', $sel);

        $this->assertCount(1, $bad);
        $this->assertStringContainsString('nothing beside it names it', $bad[0]);
    }

    public function test_a_bare_tag_in_a_descendant_selector_does_not_condemn_the_site(): void
    {
        // `.hf-pg i` paints one mark inside one component. Matching on the last compound
        // alone makes every <i> on the platform a finding — which is how a sweep produces
        // thirty-six results and gets switched off.
        $sel = $this->paintingSelectors('.hf-pg i{ background:var(--pg-fill); }');

        $this->assertSame([], $this->violations('x.twig',
            '<p class="other"><i></i></p>', $sel),
            'an <i> outside the component must not be reported');

        $this->assertCount(1, $this->violations('x.twig',
            '<p class="hf-pg"><i></i></p>', $sel),
            'an <i> inside it, holding nothing and not marked decorative, must be');
    }

    public function test_a_documented_rule_is_still_read(): void
    {
        // A comment left in place is absorbed into the next rule's selector when the sheet
        // is split on `}`, so the rule matches nothing — and the sweep goes quiet in
        // exactly the files somebody troubled to explain.
        $sel = $this->paintingSelectors(
            "/* the medal, and why it is a fill rather than an edge */\n"
          . '.medal{ background:var(--ag-honour-fill); }');

        $this->assertCount(1, $this->violations('x.twig', '<div><span class="medal"></span></div>', $sel));
    }
}
