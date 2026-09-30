<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The small-text type scale is closed, and nothing here may leave it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SCALE NEEDS A GUARD AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A type scale is a convention, and a convention with no enforcement is a comment. This
 * one arrived as exactly that: the craft pass declared the half-sizes retired in a CSS
 * comment, and the tree it was handed to carried **1,579** declarations off the ladder —
 * 41% of every font size on the site. Nothing failed, because nothing asked.
 *
 * The failure mode is not ugliness, it is drift with no floor. Sizes here were set one
 * screen at a time, so `12.5px` and `13px` sit on adjacent cards doing the same job, and
 * the next person adds `12.75px` because it looked right beside the one they were copying.
 * Eight distinct sizes existed between 11px and 15px. None of that is visible from any
 * single file, which is why it went on for the life of the codebase.
 *
 * ── THE LADDER ───────────────────────────────────────────────────────────────
 *
 * `10 · 11 · 12 · 13 · 14 · 16 · 17`, and the gap at 15 is deliberate — 14 is the
 * reading size and 16 is the lede, and a rung between them is an invitation to split the
 * difference again. Above 17px is the display range, which is set with `clamp()` against
 * the viewport rather than off this ladder, so it is out of scope here.
 *
 * ── THE EXEMPTIONS ARE KINDS, WITH REASONS ───────────────────────────────────
 *
 * `CLAUDE.md` records that an enumeration of past failures is never a fix for the next
 * one, so this holds no list of forgiven selectors. It forgives two KINDS:
 *
 *  1. **Vendored stylesheets.** `public/assets/css/vendor/` is pinned third-party code.
 *     Restyling it is reverted by the next version bump, and the revert is invisible.
 *
 *  2. **The email preheader's `font-size:1px`.** That is not type. It is the collapse
 *     that keeps the hidden preview-text div from occupying a line in clients that
 *     ignore `display:none`, and raising it to the ladder puts a stray line of grey text
 *     at the top of four production emails.
 *
 * Anything else off the ladder is the drift this exists to stop.
 */
final class TypeScaleTest extends TestCase
{
    /**
     * The closed small-text ladder — REFERENCE §6.2.
     *
     * ── THIS LADDER REPLACED A DIFFERENT ONE, AND THE SWAP IS THE POINT ──────
     *
     * It used to be `10 · 11 · 12 · 13 · 14 · 16 · 17`, whole pixels only, with the
     * gap at 15 defended in as many words: "14 is the reading size, 16 is the lede,
     * and a rung between them is an invitation to split the difference again."
     *
     * That was a good rule for the type it governed and it is not the rule the
     * redesign is drawn on. Measured across the 32 design references: 790 of the
     * 1,617 `font-size` declarations sit off the old ladder — `15px` alone appears
     * 296 times, `14.5px` 164, `12.5px` 129, `13.5px` 104. Half the type in the
     * design. A guard cannot be kept AND the design shipped, so the guard moved.
     *
     * What did not change is that there IS a closed ladder. An unguarded scale is a
     * comment: the tree this repo inherited carried 1,579 declarations off its own
     * documented scale, 41% of every size on the site, because nothing asked.
     */
    private const LADDER = [11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 15.5, 16, 16.5, 17];

    /**
     * Rungs the legacy sheets still stand on, retired as the phases convert them.
     *
     * 10 and 11 are not on the redesign's ladder — its smallest micro size is 11.5 —
     * but 582 declarations across the unconverted templates use them today. Failing
     * on those would turn this guard into a wall of noise about pages no phase has
     * reached, which is how a guard gets switched off.
     *
     * Each phase deletes the ones its pages stop using. When this is empty the
     * migration is finished, and the constant goes with it.
     */
    private const MIGRATING = [10, 11];

    /** Above this, size is set with clamp() against the viewport, not off the ladder. */
    private const DISPLAY_FLOOR = 18;

    /**
     * Every font size the browser is actually given, as [file, line, value].
     *
     * Both spellings are read. The `font:` shorthand is the one a naive sweep misses,
     * and it is where most of this codebase's micro-labels live — `font:600 11px/1 mono`
     * is 214 declarations' worth of type that a `font-size:` grep never sees.
     *
     * Inside that shorthand the size is the FIRST px value: nothing else in `font` takes
     * a length except the line-height, which follows a `/` and is not type.
     */
    private function declaredSizes(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        foreach ([$root . '/templates', $root . '/public/assets/css'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                $path = $f->getPathname();
                if (!preg_match('~\.(twig|css)$~', $path)) continue;
                if (str_contains($path, '/vendor/')) continue;

                $rel = ltrim(str_replace($root, '', $path), '/');
                foreach (explode("\n", (string) file_get_contents($path)) as $n => $line) {
                    preg_match_all('~font-size\s*:\s*([0-9]+(?:\.[0-9]+)?)px~i', $line, $m);
                    foreach ($m[1] as $v) $out[] = [$rel, $n + 1, (float) $v, $line];

                    preg_match_all(
                        '~font\s*:\s*[^;{}"\'\n]*?(?<![0-9.])([0-9]+(?:\.[0-9]+)?)px~i',
                        $line, $m2
                    );
                    foreach ($m2[1] as $v) $out[] = [$rel, $n + 1, (float) $v, $line];
                }
            }
        }
        return $out;
    }

    public function test_no_shipped_size_leaves_the_closed_ladder(): void
    {
        $off = [];
        foreach ($this->declaredSizes() as [$file, $line, $v, $src]) {
            if ($v >= self::DISPLAY_FLOOR) continue;

            // The preheader collapse, recognised by what it DOES rather than by which
            // four files currently do it: a 1px size inside a hidden preview div.
            if ($v <= 1.0 && str_contains($src, 'mso-hide:all')) continue;

            // Compared as floats, because half-sizes are rungs now. `in_array` with
            // loose comparison would match '12' to 12.5 through PHP's numeric string
            // rules, so the haystack is built from floats and the test is strict.
            $allowed = array_map('floatval', array_merge(self::LADDER, self::MIGRATING));
            if (!in_array($v, $allowed, true)) {
                $off[] = sprintf('%s:%d  %spx', $file, $line, rtrim(rtrim((string) $v, '0'), '.'));
            }
        }

        $this->assertSame([], $off, sprintf(
            "%d font size(s) are off the closed ladder (%s):\n%s",
            count($off),
            implode(' · ', self::LADDER),
            implode("\n", array_slice($off, 0, 40))
        ));
    }

    /**
     * The rung the previous scale deliberately omitted, and that this one carries.
     *
     * Kept as its own test rather than left implicit in the array, because a reader
     * who knows the old rule will come looking for the gap at 15 and deserves to find
     * the reversal written down rather than infer it from a list.
     *
     * The old reasoning — 14 reads, 16 leads, and a rung between them invites the next
     * split — was sound about a ladder of whole pixels. The redesign's scale is not
     * that ladder: 15 is its button size (REFERENCE §6.2, "Buttons 15–16 / 700
     * primary"), with 14.5 for secondary text and 15.5 for a primary phone button. The
     * rung is load-bearing now, and the thing that stops the next split-the-difference
     * is the sweep above, not the absence of this number.
     */
    public function test_fifteen_is_a_rung_now(): void
    {
        $this->assertContains(15, self::LADDER,
            '15px is the redesign button size — REFERENCE §6.2');
        $this->assertNotContains(15.25, self::LADDER,
            'the ladder is still closed: a size not on it is a misread of the design');
    }

    /**
     * The migration allowance only ever shrinks.
     *
     * Pinned so that adding a rung to it is a deliberate act with a diff, rather than
     * the quiet way this guard stops meaning anything.
     */
    public function test_the_migration_allowance_is_only_the_two_legacy_rungs(): void
    {
        $this->assertSame([10, 11], self::MIGRATING,
            'nothing new may be added here; each phase removes what its pages stop using');
    }
}
