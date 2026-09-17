<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * EVERY VARIABLE A CONTROLLER PASSES TO A TEMPLATE IS READ BY THAT TEMPLATE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY TWIG CANNOT FIND THIS AND A SWEEP HAS TO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `strict_variables` is on in the test harness, and it catches the opposite fault: a
 * template READING something the controller stopped passing. It has nothing to say about
 * a controller PASSING something no template reads — that renders perfectly, for ever.
 *
 * So a page's context is the one place in this codebase where a declaration could have no
 * reader and every existing sweep would pass over it. `docs/CODEBASE-INDEX.md` §17 is the
 * account of what that costs; this is the same question asked at the view layer, and the
 * audit that wrote it found 46 distinct keys across 150 render sites.
 *
 * ── THE ONE THAT MATTERED ───────────────────────────────────────────────────
 *
 * `HandbookController` passed `basis_ideal` and `admin/handbook.twig` never mentioned it.
 * The handbook's §5 meanwhile stated the `ideal` basis, the edition scope and the linear
 * judge scale as plain FACT, while `RuleEngine` still resolves four bases, two scopes and
 * two scales — so an operator on `judge_scale = curved` read "No floor, no curve" on the
 * one admin document every role can open and is told to trust. The unused key was the
 * tell: somebody meant to write the branch and did not, and nothing said so for months.
 *
 * ── AND THE ONES THAT COST QUERIES ──────────────────────────────────────────
 *
 * `LegacyController::event()` called `listComments()` and `cheerCount()` on every legacy
 * event page view — uncached, the event row beside them cached — and the template
 * rendered neither. `HomeController` cached four datasets the redesigned homepage stopped
 * drawing. A dead context key is usually weight; sometimes it is a query per view.
 *
 * ── WHAT THIS SWEEP HAD TO LEARN NOT TO LIE ABOUT ───────────────────────────
 *
 * TOP-LEVEL KEYS ONLY. A first cut read every `'key' =>` inside the array literal, so a
 * nested `'schema' => ['mainEntity' => …]` was reported as an unused page variable —
 * `HelpController` alone produced three such findings, each plausible enough to send
 * somebody deleting live code.
 *
 * AND THE TEMPLATE'S WHOLE FAMILY IS IN SCOPE. A variable belongs to the render, not to
 * the file: `page_title` is read by the layout the page extends, and a partial's variables
 * are read inside the partial. So `extends`, `include`, `embed`, `from` and `import` are
 * followed transitively before a key is called unread.
 */
final class TemplateContextTest extends TestCase
{
    /** Keys whose reader is legitimately not a template. */
    private const NOT_A_TEMPLATE_KEY = [
        // Twig globals declared in config/container.php are injected, not passed; a
        // controller that also passes one is shadowing rather than adding.
    ];

    public function test_no_controller_passes_a_variable_no_template_reads(): void
    {
        $dead = [];

        foreach ($this->renderCalls() as [$file, $line, $template, $keys]) {
            $text = $this->familyText($template);
            if ($text === null) continue;   // a template resolved at runtime; not ours to judge

            foreach ($keys as $key) {
                if (in_array($key, self::NOT_A_TEMPLATE_KEY, true)) continue;
                if (preg_match('~\b' . preg_quote($key, '~') . '\b~', $text) === 1) continue;
                $dead[] = sprintf('%s:%d  %s  passes $%s', $file, $line, $template, $key);
            }
        }

        sort($dead);
        $this->assertSame([], $dead, count($dead) . " template variables are passed and never read:\n  "
            . implode("\n  ", $dead)
            . "\n\nEach is either a reader that was never written — which is what `basis_ideal` was —"
            . "\nor weight left behind by a redesign. Delete it, or write the reader.");
    }

    // ── the sweep ────────────────────────────────────────────────────────────

    /** @return list<array{0:string,1:int,2:string,3:list<string>}> */
    private function renderCalls(): array
    {
        $out = [];
        foreach ($this->phpFiles() as $path) {
            $body = (string) file_get_contents($path);
            $rel  = str_replace($this->root() . '/', '', $path);

            $offset = 0;
            while (preg_match('~render\(\s*\$?\w+\s*,\s*[\'"]([\w/.-]+\.twig)[\'"]\s*,\s*\[~',
                              $body, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $open   = (int) $m[0][1] + strlen($m[0][0]) - 1;
                $offset = $open + 1;

                $close = $this->matchBracket($body, $open);
                if ($close === null) continue;

                $out[] = [
                    $rel,
                    substr_count(substr($body, 0, (int) $m[0][1]), "\n") + 1,
                    $m[1][0],
                    $this->topLevelKeys(substr($body, $open, $close - $open + 1)),
                ];
            }
        }
        return $out;
    }

    /**
     * The matching `]`, skipping brackets inside string literals.
     *
     * A naive depth count trips on `$r['id']` and on any prose containing a bracket, and
     * it fails SILENTLY — it returns a truncated array and the keys after the truncation
     * are simply never checked, so the sweep reports a clean pass over the half it read.
     */
    private function matchBracket(string $s, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === "'" || $c === '"') { $i = $this->endOfString($s, $i); continue; }
            if ($c === '[') $depth++;
            elseif ($c === ']') { $depth--; if ($depth === 0) return $i; }
        }
        return null;
    }

    private function endOfString(string $s, int $i): int
    {
        $q = $s[$i];
        for ($j = $i + 1, $n = strlen($s); $j < $n; $j++) {
            if ($s[$j] === '\\') { $j++; continue; }
            if ($s[$j] === $q) return $j;
        }
        return $n - 1;
    }

    /**
     * Keys at depth 1 of a PHP array literal. See the class docblock on why depth matters.
     *
     * @return list<string>
     */
    private function topLevelKeys(string $arr): array
    {
        $keys = [];
        $depth = 0;
        for ($i = 0, $n = strlen($arr); $i < $n; $i++) {
            $c = $arr[$i];

            // Comments carry `=>` in prose and `[` in examples; skip them entirely.
            if ($c === '/' && $i + 1 < $n && $arr[$i + 1] === '/') { $i = strpos($arr, "\n", $i) ?: $n; continue; }
            if ($c === '/' && $i + 1 < $n && $arr[$i + 1] === '*') { $i = (int) strpos($arr, '*/', $i) + 1; continue; }
            if ($c === '#') { $i = strpos($arr, "\n", $i) ?: $n; continue; }

            if ($c === '[' || $c === '(') { $depth++; continue; }
            if ($c === ']' || $c === ')') { $depth--; continue; }

            if ($c === "'" || $c === '"') {
                $end = $this->endOfString($arr, $i);
                $lit = substr($arr, $i + 1, $end - $i - 1);
                $after = ltrim(substr($arr, $end + 1, 4));
                if ($depth === 1 && str_starts_with($after, '=>') && preg_match('~^\w+$~', $lit) === 1) {
                    $keys[] = $lit;
                }
                $i = $end;
            }
        }
        return array_values(array_unique($keys));
    }

    /** The template plus everything it extends, includes, embeds or imports — or null. */
    private function familyText(string $template, array &$seen = []): ?string
    {
        if (isset($seen[$template])) return '';
        $path = $this->root() . '/templates/' . $template;
        if (!is_file($path)) return $seen === [] ? null : '';

        $seen[$template] = true;
        $body = (string) file_get_contents($path);
        $text = $body;

        if (preg_match_all('~(?:extends|include|embed|from|import)\s*\(?\s*[\'"]([\w/.-]+\.twig)[\'"]~',
                           $body, $m) > 0) {
            foreach ($m[1] as $child) $text .= (string) $this->familyText($child, $seen);
        }
        return $text;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];
        foreach (['src', 'config'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
