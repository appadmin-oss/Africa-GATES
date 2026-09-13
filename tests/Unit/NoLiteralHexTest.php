<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A colour reaches a page through `Accent`, never through a literal typed into a template.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS SHIPS AS A RATCHET AND NOT AS A CLEAN PASS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There are **3,585 literal hexes across 94 public templates** today. That is not a
 * discipline problem and it was never carelessness: it is what happens when the correct
 * value is harder to reach than a literal. `Accent::for()` and the emitted ramp fixed the
 * reaching; this is the part that stops the pile growing back while the ~60 templates are
 * converted one at a time.
 *
 * A flat allowlist — "these files are exempt" — would let an exempt file go from 90
 * literals to 300 and still pass, which is the failure mode of every allowlist ever
 * written. So the baseline is a COUNT PER FILE and the rule is that it may only ever go
 * down:
 *
 *   · a file above its recorded count fails, naming the file and both numbers;
 *   · a file not in the baseline at all may have NONE — that is the rule for anything
 *     written from today;
 *   · a file below its recorded count fails too, asking for the baseline to be lowered.
 *
 * That last one matters more than it looks. Without it the baseline never shrinks, the
 * numbers drift out of date, and in a year nobody trusts the file enough to delete
 * anything from it. Converting a template is meant to be two edits: the template, and its
 * line here going down.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS NOT COUNTED, AND WHY EACH EXCLUSION IS A KIND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A Twig comment reaches no reader, so a comment explaining a conversion may name the
 * literal it removed. Same lesson `GlobeBandTest` records: sweep what a READER sees.
 *
 * The admin console is excluded as a KIND, not as a list. It is a dense operator tool with
 * its own token namespace (`--ad-*`), the handoff exempts it from the budget, and folding
 * 2,036 admin literals into this number would bury the public ones this is actually for.
 */
final class NoLiteralHexTest extends TestCase
{
    private const BASELINE = '/tests/baselines/template-hex.json';

    /** @return array<string,int> path => how many literals a reader can see */
    private function counts(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates'));

        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;

            $rel = str_replace($root . '/', '', $f->getPathname());
            // A KIND, with a reason — see the class docblock.
            if (str_contains($rel, 'templates/admin/')) continue;

            $body = (string) preg_replace('/\{#.*?#\}/s', '',
                (string) file_get_contents($f->getPathname()));

            $n = preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $body);
            if ($n > 0) $out[$rel] = $n;
        }

        ksort($out);

        return $out;
    }

    /** @return array<string,int> */
    private function baseline(): array
    {
        $path = dirname(__DIR__, 2) . self::BASELINE;
        $this->assertFileExists($path, 'the literal-hex baseline is missing');

        /** @var array<string,int> $b */
        $b = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($b, 'the baseline is not readable JSON');

        return $b;
    }

    public function test_no_template_gains_a_literal_colour(): void
    {
        $now  = $this->counts();
        $base = $this->baseline();
        $bad  = [];

        foreach ($now as $file => $n) {
            $allowed = $base[$file] ?? 0;
            if ($n > $allowed) {
                $bad[] = $allowed === 0
                    ? sprintf('%s: %d literal colour%s in a file that may have none — '
                            . 'use Accent::for(\'<meaning>\') or a --ag-* custom property',
                              $file, $n, $n === 1 ? '' : 's')
                    : sprintf('%s: %d literals, up from %d', $file, $n, $allowed);
            }
        }

        $this->assertSame([], $bad,
            "a literal colour was added to a template:\n  " . implode("\n  ", $bad));
    }

    public function test_the_baseline_shrinks_when_a_template_is_converted(): void
    {
        // Without this the numbers drift, the file goes stale, and within a year nobody
        // trusts it enough to delete a line from it. Converting a template is two edits:
        // the template, and its line here going down.
        $now  = $this->counts();
        $base = $this->baseline();
        $stale = [];

        foreach ($base as $file => $allowed) {
            $n = $now[$file] ?? 0;
            if ($n < $allowed) {
                $stale[] = $n === 0
                    ? sprintf('%s: converted — delete its line', $file)
                    : sprintf('%s: down to %d, baseline still says %d', $file, $n, $allowed);
            }
        }

        $this->assertSame([], $stale,
            "the baseline is behind the code — lower it:\n  " . implode("\n  ", $stale));
    }

    public function test_the_baseline_names_no_file_that_has_gone(): void
    {
        // A deleted template leaving its line behind is a silent exemption waiting for
        // somebody to recreate the filename.
        $now   = $this->counts();
        $ghost = array_diff(array_keys($this->baseline()), array_keys($now));

        $this->assertSame([], array_values($ghost),
            'the baseline names templates that no longer exist');
    }

    public function test_the_sweep_would_catch_a_literal(): void
    {
        // Proving it fails before trusting it passing. A regex that quietly stopped
        // matching would leave a green test and no rule at all.
        $body = "{# a comment naming #ff0000 reaches no reader #}\n"
              . '<div style="color:#ff0000">x</div>';
        $seen = (string) preg_replace('/\{#.*?#\}/s', '', $body);

        $this->assertSame(1, preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $seen),
            'the matcher counts the comment, or misses the declaration');
    }
}
