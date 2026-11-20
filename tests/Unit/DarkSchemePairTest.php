<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use PHPUnit\Framework\TestCase;

/**
 * A colour that FLIPS at night drawn on a fill that does NOT is a word that disappears in the
 * dark scheme — and nothing in the palette's own tests can see it, because each token passes
 * on the grounds it is listed against. Found 20 Nov 2026 (owner: "fix the dark mode contrast
 * issues"): Gee's "G" was ink on green-light, 1.71:1 at night on every page, and the earned
 * badge and the map's decided tick were ink on gold, 1.57:1.
 *
 * The fixed and flipping sets are READ from Accent (a token is fixed when its night value is
 * its day value), so a palette change moves this test with it rather than past it. A rule may
 * still pair them when the same file gives that selector a night colour of its own, in the
 * two-selector form Accent::css() uses — that is how the badge and the tick are answered.
 */
final class DarkSchemePairTest extends TestCase
{
    /** @return array{0:list<string>,1:list<string>} fixed fills, flipping word colours */
    private static function sets(): array
    {
        $r = new \ReflectionClass(Accent::class);
        $day = array_map(static fn ($v) => is_array($v) ? $v['value'] : $v, $r->getConstant('PALETTE'));
        $night = $r->getConstant('NIGHT');
        $fixed = $flip = [];
        foreach ($night as $k => $v) {
            if (!isset($day[$k]) || !str_starts_with((string) $v, '#')) continue;
            if (strtolower((string) $v) === strtolower((string) $day[$k])) $fixed[] = $k; else $flip[] = $k;
        }
        return [$fixed, $flip];
    }

    /** @return list<string> "file | selector | fill / word" for every unanswered pair in $css */
    private static function offenders(string $css, string $file, array $fixed, array $flip): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $out = [];
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER);
        foreach ($m as [, $sel, $body]) {
            if (!preg_match('~background(?:-color)?\s*:\s*var\(--ag-([a-z0-9-]+)\)~', $body, $bg)) continue;
            if (!preg_match('~(?<![-\w])color\s*:\s*var\(--ag-([a-z0-9-]+)\)~', $body, $fg)) continue;
            if (!in_array($bg[1], $fixed, true) || !in_array($fg[1], $flip, true)) continue;
            $sel = trim($sel);
            // Answered: the same selector re-coloured under the reader's Dark (and, by the
            // convention, under the device's dark too).
            if (str_contains($css, ':root:where(.ag-dark) ' . $sel . '{')) continue;
            $out[] = "$file | $sel | {$bg[1]} / {$fg[1]}";
        }
        return $out;
    }

    public function test_the_fixed_and_flipping_sets_are_what_the_finding_was_about(): void
    {
        [$fixed, $flip] = self::sets();
        $this->assertContains('green-light', $fixed);
        $this->assertContains('gold', $fixed);
        $this->assertContains('ink', $flip);
        $this->assertContains('surface', $flip);
    }

    public function test_no_word_that_flips_at_night_sits_on_a_fill_that_does_not(): void
    {
        [$fixed, $flip] = self::sets();
        $root = dirname(__DIR__, 2) . '/public/assets/css';
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
            $p = (string) $f;
            if (!str_ends_with($p, '.css') || str_contains($p, '/min/') || str_contains($p, '/vendor/')) continue;
            $bad = array_merge($bad, self::offenders((string) file_get_contents($p), substr($p, strlen($root) + 1), $fixed, $flip));
        }
        $this->assertSame([], $bad, "a word that turns light at night on a fill that stays light:\n" . implode("\n", $bad));
    }

    /** The sweep is evidence only once it has been seen to name a break. */
    public function test_the_sweep_names_the_shape_it_exists_for(): void
    {
        [$fixed, $flip] = self::sets();
        $this->assertCount(1, self::offenders('.g{ background:var(--ag-green-light); color:var(--ag-ink) }', 't.css', $fixed, $flip));
        $this->assertCount(0, self::offenders('.g{ background:var(--ag-green); color:var(--ag-surface) }', 't.css', $fixed, $flip),
            'the button pair flips together and is measured in every scheme');
        $answered = '.g{ background:var(--ag-gold); color:var(--ag-ink) }'
            . '@media (prefers-color-scheme:dark){ :root:where(:not(.ag-light):not(.ag-sepia)) .g{ color:var(--ag-surface) } }'
            . ':root:where(.ag-dark) .g{ color:var(--ag-surface) }';
        $this->assertCount(0, self::offenders($answered, 't.css', $fixed, $flip), 'a pair answered with a night colour');
    }
}
