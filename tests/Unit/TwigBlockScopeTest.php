<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A `{% set %}` inside one Twig block is invisible to every other block.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO SHIPPED BUGS, THE SAME CAUSE, NEITHER VISIBLE FROM THE SERVER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Twig scopes a `set` to the block it is written in. Reading that variable from a different
 * block yields NULL — silently. No exception, no warning, a 200 with complete HTML, and
 * whatever the value was used for is quietly wrong.
 *
 * ── 1 · THE ACCOUNT PAGE'S ENTIRE NAVIGATION ────────────────────────────────
 *
 * `me_tabs` was set inside `{% block head_styles %}` and read by the foot script, which
 * therefore rendered `var IDS = null`. `paint()` threw on its first call — at module level,
 * before a single listener was bound — aborting the whole IIFE. Every rail tab dead, the
 * search box inert, the highlight frozen. On every account, for everybody.
 *
 * ── 2 · THE SHARE LINK ON EVERY VOTE PAGE ───────────────────────────────────
 *
 * `slug` was set inside `{% block content %}` and read by `autoShare()` in
 * `{% block foot_scripts %}`, so the share URL rendered as `/vote/<programme>/` — the
 * programme list, with the nominee silently missing. Every voter who used the native share
 * sheet sent their friends to a list of forty nominees instead of the one they had just
 * voted for. The symptom was votes that did not arrive from shares that did.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A TEST AND NOT A COMMENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The account template already carried a comment saying the list was "defined here and
 * rendered into both". It was defined once and rendered into one, and the comment had been
 * sitting above the bug for as long as the bug existed. A claim about where a variable is
 * visible is checkable; this checks it.
 */
final class TwigBlockScopeTest extends TestCase
{
    /** @return list<string> every .twig under templates/ */
    private function templates(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'twig') $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    /**
     * Strip Twig comments before scanning.
     *
     * Not cosmetic: the account template's own comment now QUOTES `{% block head_styles %}`
     * while explaining this very bug, and a scanner that reads comments would open a phantom
     * block there and mis-attribute every later `set`. The first version of this scan did
     * exactly that and reported the fixed file as still broken.
     */
    private function stripComments(string $src): string
    {
        // Replaced with equal-length whitespace so every offset below stays honest.
        return (string) preg_replace_callback(
            '~\{#.*?#\}~s',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $src
        );
    }

    /**
     * Which block, if any, encloses each byte offset.
     *
     * Tracks `{% endblock %}`, because `{% block head_styles %}{% endblock %}` in a LAYOUT
     * encloses nothing at all — and treating it as open would flag every `set` after it in
     * the file. That is the difference between a lint people keep and one they delete.
     *
     * @return list<array{name:string, from:int, to:int}>
     */
    private function blockSpans(string $src): array
    {
        preg_match_all('~\{%-?\s*(block\s+(\w+)|endblock)[^%]*-?%\}~', $src,
                       $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $spans = [];
        $stack = [];
        foreach ($m as $tok) {
            $at   = (int) $tok[0][1];
            $len  = strlen((string) $tok[0][0]);
            $kind = (string) $tok[1][0];

            if (str_starts_with($kind, 'block')) {
                $stack[] = ['name' => (string) $tok[2][0], 'from' => $at + $len];
                continue;
            }
            $open = array_pop($stack);
            if ($open === null) continue;
            $spans[] = ['name' => $open['name'], 'from' => $open['from'], 'to' => $at];
        }
        return $spans;
    }

    /** The innermost block containing $at, or '' for template scope. */
    private function blockAt(array $spans, int $at): string
    {
        $best = '';
        $width = PHP_INT_MAX;
        foreach ($spans as $s) {
            if ($at < $s['from'] || $at > $s['to']) continue;
            $w = $s['to'] - $s['from'];
            if ($w < $width) { $width = $w; $best = $s['name']; }
        }
        return $best;
    }

    public function test_no_variable_is_set_in_one_block_and_read_in_another(): void
    {
        $problems = [];

        foreach ($this->templates() as $file) {
            $src   = $this->stripComments((string) file_get_contents($file));
            $spans = $this->blockSpans($src);
            if ($spans === []) continue;

            // Where each name is set, and in which block.
            $setIn = [];
            preg_match_all('~\{%-?\s*set\s+(\w+)\s*=~', $src, $sm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($sm as $tok) {
                $name  = (string) $tok[1][0];
                $block = $this->blockAt($spans, (int) $tok[0][1]);
                // Template scope is visible everywhere — that is the fix, not the fault.
                if ($block === '') continue;
                // A name set in more than one block is set wherever it is used. Only a name
                // whose ONLY declarations are elsewhere can render null.
                $setIn[$name][$block] = true;
            }
            if ($setIn === []) continue;

            foreach ($setIn as $name => $blocks) {
                // `{{ … name … }}` and `{% if name %}`-style reads.
                $pattern = '~\{[\{%][^\}]*?(?<![\w.])' . preg_quote($name, '~') . '(?![\w])~s';
                preg_match_all($pattern, $src, $um, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

                foreach ($um as $use) {
                    $at = (int) $use[0][1];
                    // The declaration itself is not a read.
                    if (preg_match('~\{%-?\s*set\s+' . preg_quote($name, '~') . '~',
                                   substr($src, $at, 40))) continue;

                    $where = $this->blockAt($spans, $at);
                    if ($where === '' || isset($blocks[$where])) continue;

                    $problems[] = sprintf(
                        '%s: `%s` is set in block "%s" and read in block "%s" — it renders as null there',
                        str_replace(dirname(__DIR__, 2) . '/', '', $file),
                        $name, implode('/', array_keys($blocks)), $where
                    );
                }
            }
        }

        $problems = array_values(array_unique($problems));

        $this->assertSame([], $problems,
            "A Twig `set` inside a block is invisible to other blocks and yields null with no\n"
            . "error. Move the declaration above the first `{% block %}` so it is at template\n"
            . "scope. This shipped twice: the account page's whole navigation, and the share\n"
            . "URL on every vote page.\n\n  " . implode("\n  ", $problems));
    }

    /** The scanner must be looking at something, or it passes by doing nothing. */
    public function test_the_scan_actually_reads_the_templates(): void
    {
        $files = $this->templates();
        $this->assertGreaterThan(50, count($files));

        $withBlocks = 0;
        foreach ($files as $f) {
            if ($this->blockSpans($this->stripComments((string) file_get_contents($f))) !== []) {
                $withBlocks++;
            }
        }
        $this->assertGreaterThan(20, $withBlocks, 'the block scanner is matching nothing');
    }

    /** And it must find the bug when the bug is put back. */
    public function test_the_scan_catches_a_reintroduced_cross_block_read(): void
    {
        $broken = <<<'TWIG'
        {% extends "layout/gates.twig" %}
        {% block content %}
          {% set tabs = ['a','b'] %}
          <p>{{ tabs|length }}</p>
        {% endblock %}
        {% block foot_scripts %}
          <script>var IDS = {{ tabs|json_encode|raw }};</script>
        {% endblock %}
        TWIG;

        $spans = $this->blockSpans($broken);
        $this->assertNotSame([], $spans);

        $setAt = strpos($broken, '{% set tabs');
        $useAt = strpos($broken, '{{ tabs|json_encode');
        $this->assertNotFalse($setAt);
        $this->assertNotFalse($useAt);

        $this->assertSame('content', $this->blockAt($spans, $setAt));
        $this->assertSame('foot_scripts', $this->blockAt($spans, $useAt),
            'the scanner cannot tell the two blocks apart, so it would never catch this');
    }

    /** A self-closing block in a layout encloses nothing. */
    public function test_a_self_closing_block_does_not_swallow_the_rest_of_the_file(): void
    {
        $layout = '{% block head_styles %}{% endblock %}' . "\n"
                . '{% set help = {"a":"b"} %}' . "\n"
                . '<p>{{ help.a }}</p>';

        $spans = $this->blockSpans($layout);
        $at    = strpos($layout, '{% set help');

        $this->assertSame('', $this->blockAt($spans, (int) $at),
            'a self-closing block was treated as open, which would flag every later set');
    }
}
