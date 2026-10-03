<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\VerbatimAssets;
use Tests\TestCase;

/**
 * A colour is typed in exactly one file — `Support\Accent` — and reaches every other one
 * as a `var(--ag-…)`.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACED, AND THE TWO THINGS IT COULD NOT SEE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Its predecessor counted `#hex` in TEMPLATES. Two holes, both named in GAPS §6:
 *
 *   C8  It never opened a stylesheet. The redesign moves colour out of inline `<style>`
 *       blocks and into component CSS — so every conversion moved colour out of the
 *       guard's sight, and a sheet could carry a hundred literals while the sweep
 *       reported the page clean.
 *   —   It counted only hex. `rgba(16,41,44,.08)` is the house ink with an alpha, typed
 *       by hand, and it passed. A translucent palette colour is written
 *       `color-mix(in srgb, var(--ag-ink) 8%, transparent)` — the same colour, from
 *       the one source.
 *
 * So the subject is now every colour literal — hex, `rgb()`, `rgba()`, `hsl()`,
 * `hsla()` — in every public template AND every authored stylesheet.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE REDESIGN'S BASE IS ZERO, AND EVERYTHING ELSE IS A RATCHET
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `tokens.css`, `shell.css` and `components.css` may hold NONE, by name: they are the
 * redesign's foundation, and a literal there is a second palette. Any file not in the
 * baseline may hold none either — that is the rule for everything written from today,
 * and it is what holds every component sheet the redesign has already brought to zero.
 *
 * The rest of the site carries literals from before the palette existed, and they go
 * as each page is destroyed and rebuilt. The baseline is a COUNT PER FILE that may only
 * go down: above it fails, below it fails too (lower the line), and a deleted file's line
 * fails as a ghost — a silent exemption waiting for somebody to recreate the filename.
 *
 * ── WHAT IS NOT COUNTED, AS KINDS ───────────────────────────────────────────
 *
 * Comments (`{# #}` and `/* *\/`) reach no reader, so a comment may name the literal it
 * removed. The admin console (`templates/admin/`, `admin.css`) is a separate operator
 * tool with its own `--ad-*` palette and is not part of the handoff. Vendored and built
 * files are not authored here, and neither is the handoff's celebration sheet, which the
 * spec ships byte-identical — exempt only while its hash is the bundle's
 * (`Tests\Support\VerbatimAssets`, GAPS Q7).
 */
final class ColourLiteralTest extends TestCase
{
    private const BASELINE = '/tests/baselines/colour-literals.json';

    /** The redesign's base: zero by name, never by baseline. */
    private const MUST_BE_ZERO = [
        'public/assets/css/tokens.css',
        'public/assets/css/shell.css',
        'public/assets/css/components.css',
    ];

    private const LITERAL = '/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\(/';

    /** How many literals a reader of this text can see. */
    public static function literals(string $text): int
    {
        $text = (string) preg_replace('/\{#.*?#\}/s', '', $text);
        $text = (string) preg_replace('!/\*.*?\*/!s', ' ', $text);

        return (int) preg_match_all(self::LITERAL, $text);
    }

    /** @return array<string,int> every swept file, including the ones on zero */
    public static function counts(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        foreach (['templates' => '.twig', 'public/assets/css' => '.css'] as $dir => $ext) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir")) as $f) {
                if (!$f->isFile() || !str_ends_with($f->getPathname(), $ext)) continue;
                $rel = str_replace($root . '/', '', $f->getPathname());

                if (str_contains($rel, 'templates/admin/')) continue;          // a kind: the console
                if ($rel === 'public/assets/css/admin.css') continue;         // the console's sheet
                if (str_contains($rel, '/vendor/') || str_contains($rel, '/dist/')) continue;
                // A kind, pinned by name AND hash: the handoff's verbatim celebration sheet
                // (Tests\Support\VerbatimAssets — awaiting the owner, GAPS Q7). An edited
                // copy is no longer verbatim and is counted like any other file.
                if (VerbatimAssets::is($rel)) continue;

                $out[$rel] = self::literals((string) file_get_contents($f->getPathname()));
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string,int> */
    private function baseline(): array
    {
        $path = dirname(__DIR__, 2) . self::BASELINE;
        $this->assertFileExists($path, 'the colour-literal baseline is missing');
        $b = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($b, 'the baseline is not readable JSON');

        return $b;
    }

    public function test_the_redesigns_base_holds_no_colour_literal(): void
    {
        $counts = self::counts();
        foreach (self::MUST_BE_ZERO as $file) {
            $this->assertArrayHasKey($file, $counts, "$file is not being swept");
            $this->assertSame(0, $counts[$file],
                "$file holds a colour literal. Colour comes from Support\\Accent; a translucent "
              . 'palette colour is color-mix(in srgb, var(--ag-…) N%, transparent).');
            $this->assertArrayNotHasKey($file, $this->baseline(), "$file may never be baselined");
        }
    }

    public function test_no_file_gains_a_colour_literal(): void
    {
        $base = $this->baseline();
        $bad  = [];

        foreach (self::counts() as $file => $n) {
            $allowed = $base[$file] ?? 0;
            if ($n > $allowed) {
                $bad[] = $allowed === 0
                    ? sprintf('%s: %d colour literal%s in a file that may have none — use a var(--ag-*) from Support\\Accent',
                              $file, $n, $n === 1 ? '' : 's')
                    : sprintf('%s: %d literals, up from %d', $file, $n, $allowed);
            }
        }

        $this->assertSame([], $bad, "a colour literal was added:\n  " . implode("\n  ", $bad));
    }

    public function test_the_baseline_shrinks_when_a_file_is_converted(): void
    {
        $now   = self::counts();
        $stale = [];

        foreach ($this->baseline() as $file => $allowed) {
            if (!array_key_exists($file, $now)) continue;   // the ghost test's job
            $n = $now[$file];
            if ($n < $allowed) {
                $stale[] = $n === 0 ? "$file: converted — delete its line"
                                    : "$file: down to $n, baseline still says $allowed";
            }
        }

        $this->assertSame([], $stale, "the baseline is behind the code — lower it:\n  " . implode("\n  ", $stale));
    }

    public function test_the_baseline_names_no_file_that_has_gone(): void
    {
        $ghost = array_diff(array_keys($this->baseline()), array_keys(self::counts()));
        $this->assertSame([], array_values($ghost), 'the baseline names files that no longer exist');
    }

    public function test_the_sweep_sees_every_kind_of_literal_and_no_comment(): void
    {
        // Proving it fails before trusting it to pass.
        $this->assertSame(4, self::literals(
            ".a{color:#ff0000} .b{color:rgb(1,2,3)} .c{color:rgba(16,41,44,.08)} .d{color:hsl(0 0% 0%)}"));
        $this->assertSame(0, self::literals("{# #ff0000 #}/* rgba(0,0,0,.1) */ .a{color:var(--ag-ink)}"));
        $this->assertSame(0, self::literals('.a{background:color-mix(in srgb,var(--ag-bar) 90%,transparent)}'));
    }
}
