<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\Support\ColourFields;
use Tests\TestCase;

/**
 * The palette is the handoff's, exactly — and the rules about how much of it a screen
 * may wear.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE VALUES ARE PINNED HERE A SECOND TIME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The owner's decision (GAPS §8 Q1/Q2, 2 Oct 2026) was "ship the handoff exactly": ±0
 * on colour, the handoff's names. Everywhere else a second copy of a value is the fault
 * this repo keeps paying for; here the second copy IS the contract, the one place that
 * says what REFERENCE §6.1 and `snippets/css/tokens.css` said. Somebody "improving" a
 * value in `Support\Accent` — lifting `soft` for margin, darkening the gold so it shows
 * as a line — fails this test by name, and the change has to be argued with the owner
 * rather than made in passing.
 *
 * Contrast floors live in `SlotFloorTest`. This file holds identity, shape, the
 * per-screen ceilings, and programme identity.
 */
final class AccentTest extends TestCase
{
    /** REFERENCE §6.1 + snippets/css/tokens.css, verbatim. */
    private const HANDOFF = [
        'ink' => '#10292c', 'ink-2' => '#3a4a4c', 'soft' => '#626a6e', 'mute' => '#8b9295',
        'ground' => '#f1efe9', 'surface' => '#ffffff', 'bar' => '#fbfbfa', 'line' => '#e8e5dd',
        'line-2' => '#d6d4cc', 'line-3' => '#eeece6', 'tint' => '#e8e5dd',
        'green' => '#237b22', 'green-deep' => '#1a6118', 'green-light' => '#7fc87c',
        'green-wash' => '#effaf0', 'green-edge' => '#cfe6ce',
        'gold' => '#f3b416', 'gold-ink' => '#7a5600', 'gold-wash' => '#fcf4de',
        'gold-wash-2' => '#fff8df', 'gold-edge' => '#f0dfae',
        'live' => '#e0245e', 'live-ink' => '#b0224f', 'live-wash' => '#fdecef',
        'info' => '#1f6fa3', 'info-wash' => '#e8f1f7', 'error' => '#b42318',
        'stock-low' => '#8a2020', 'stock-gone' => '#8a5a00',
        'scrim' => 'rgba(16,41,44,.32)', 'chevron' => '#b3b9ba', 'grabber' => '#c9c4b8',
    ];

    /** REFERENCE §6.5 + the snippet's `--ag-sh-gee`, verbatim. */
    private const HANDOFF_SHADOWS = [
        'sh-pop'   => '0 24px 48px -20px rgba(16,41,44,.30)',
        'sh-mega'  => '0 24px 48px -28px rgba(16,41,44,.25)',
        'sh-sheet' => '0 -12px 40px rgba(16,41,44,.18)',
        'sh-float' => '0 2px 10px rgba(16,41,44,.15)',
        'sh-dot'   => '0 3px 10px rgba(16,41,44,.18)',
        'sh-gee'   => '0 30px 70px -24px rgba(16,41,44,.45)',
    ];

    // ══ the palette is the handoff's ═════════════════════════════════════════

    public function test_the_palette_is_the_handoffs_name_for_name_and_value_for_value(): void
    {
        $got = array_map(static fn (array $v): string => $v['value'], Accent::palette());

        $this->assertSame(self::HANDOFF, $got,
            'the palette has drifted from the handoff. The owner decided ±0 on colour — a '
          . 'value that fails a floor is REPORTED, never adjusted here.');
        $this->assertSame(self::HANDOFF_SHADOWS, Accent::shadows());
    }

    public function test_every_token_states_what_it_is_for(): void
    {
        // A token with no stated use becomes whichever colour somebody liked that day.
        foreach (Accent::palette() as $name => $v) {
            $this->assertNotSame('', trim($v['use']), $name);
        }
    }

    public function test_the_page_receives_exactly_the_palette_and_the_shadows(): void
    {
        $css = Accent::css();

        foreach (self::HANDOFF as $name => $value) {
            $this->assertStringContainsString('--ag-' . $name . ':' . $value . ';', $css);
        }
        foreach (self::HANDOFF_SHADOWS as $name => $value) {
            $this->assertStringContainsString('--ag-' . $name . ':' . $value . ';', $css);
        }

        // Nothing but custom properties, and no character that could close the <style>
        // it is printed into or open a rule.
        $this->assertMatchesRegularExpression('/^:root\{(--ag-[a-z0-9-]+:[#a-z0-9 ,.()-]+;)+\}$/', $css);
        $this->assertSame(count(self::HANDOFF) + count(self::HANDOFF_SHADOWS),
            substr_count($css, '--ag-'), 'Accent emits a name that is not the handoff\'s');
    }

    public function test_a_colour_asked_for_by_a_name_that_does_not_exist_throws(): void
    {
        // A blank would strip a mail's button of its colour on every send; a typo must
        // stop the build instead.
        $this->assertSame('#626a6e', Accent::hex('soft'));

        foreach (['ink-soft', 'honour', 'surface-2', ''] as $retired) {
            try {
                Accent::hex($retired);
                $this->fail("'{$retired}' resolved to a colour");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // And a translucent token is refused where a literal hex is required.
        $this->expectException(\InvalidArgumentException::class);
        Accent::hex('scrim');
    }

    public function test_every_layout_emits_the_palette_before_its_stylesheets(): void
    {
        // A layout that does not emit it renders every `var(--ag-ink)` as nothing — the
        // old shell layout got away with it only because tokens.css typed the same values
        // a second time.
        $root = dirname(__DIR__, 2) . '/templates/';
        // layout/gates.twig left this list when it was destroyed (docs/handoff/DESTROYED.md).
        foreach (['layout/shell.twig', 'admin/login.twig',
                  'admin/magic.twig', 'judge/login.twig'] as $file) {
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($root . $file));
            $at   = strpos($body, '{{ ag_accents()|raw }}');
            $this->assertNotFalse($at, "$file never emits the palette");
            $this->assertMatchesRegularExpression('/<style nonce="\{\{ csp_nonce \}\}">\{\{ ag_accents\(\)\|raw \}\}/', $body, $file);

            $sheet = strpos($body, "/assets/css/tokens.css");
            $this->assertNotFalse($sheet, "$file does not load tokens.css");
            $this->assertLessThan($sheet, $at, "$file emits the palette after its stylesheets");
        }
    }

    public function test_a_familys_ink_and_edge_are_recognisably_its_fill(): void
    {
        // Darkening for contrast is how an accessibility pass turns a palette to mud. The
        // handoff's families hold their hue; this is what notices if one stops.
        foreach (Accent::families() as $name => $f) {
            foreach (['edge', 'ink'] as $slot) {
                $d = $this->hueGap(Accent::hex($f['fill']), Accent::hex($f[$slot]));
                $this->assertLessThanOrEqual(18.0, $d, sprintf(
                    '%s.%s (%s) has drifted %.1f° from %s', $name, $slot, $f[$slot], $d, $f['fill']));
            }
        }
    }

    public function test_a_meaning_nobody_defined_stops_the_build(): void
    {
        $this->assertSame('gold', Accent::familyFor('overall-winner'));
        $this->assertSame('error', Accent::familyFor('withheld'));
        $this->assertSame('live', Accent::familyFor('counting'));

        $this->expectException(\InvalidArgumentException::class);
        Accent::for('danger');
    }

    public function test_every_meaning_and_family_points_at_tokens_that_exist(): void
    {
        foreach (Accent::families() + Accent::programmes() as $name => $f) {
            foreach ($f as $slot => $token) {
                $this->assertArrayHasKey($token, Accent::palette(), "$name.$slot");
            }
        }
        foreach (Accent::fields() as $name => $tokens) {
            $this->assertArrayHasKey($name, Accent::families());
            foreach ($tokens as $t) $this->assertArrayHasKey($t, Accent::palette(), $t);
        }
        foreach (Accent::meanings() as $meaning => $family) {
            $this->assertArrayHasKey($family, Accent::families(), $meaning);
        }
    }

    // ══ the ceilings, which are what keep a payoff rare ═══════════════════════

    /**
     * No screen wears more than two colour families AS FIELDS.
     *
     * Restraint is the setup and colour is the payoff, so the payoff must be rare — a
     * screen reaching for four accents has none. Fields only: an outline is a boundary
     * and a focus ring is a keyboard affordance, and forbidding them pushes a page to fill
     * a withheld chip or drop a focus ring. How MUCH colour a page spends per tier is
     * `ColourBudgetTest`'s question; this one is that no screen, at any tier, wears three.
     */
    public function test_no_screen_wears_more_than_two_families_at_once(): void
    {
        $offenders = [];

        foreach ($this->publicTemplates() as $path => $body) {
            $alt  = $this->exclusiveFamily($body);
            $seen = array_values(array_diff(ColourFields::families($this->seen($body)), [$alt]));

            if (count($seen) > 2) $offenders[] = basename($path) . ': ' . implode(', ', $seen);
        }

        $this->assertSame([], $offenders,
            "these screens wear more than two families as fields, so they wear none:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * And a screen naming every family it can reach — field, edge or word — is using
     * colour as a vocabulary. Three is honest (a live chip, a withheld outline, a gold
     * winner); four is not.
     */
    public function test_no_screen_names_every_family_it_can_reach_for(): void
    {
        $offenders = [];

        foreach ($this->publicTemplates() as $path => $body) {
            $alt  = $this->exclusiveFamily($body);
            $seen = array_values(array_diff($this->reached($this->seen($body)), [$alt]));

            if (count($seen) > 3) $offenders[] = basename($path) . ': ' . implode(', ', $seen);
        }

        $this->assertSame([], $offenders,
            "these screens reach for four or more families, which is colour as a vocabulary:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * NO FAMILY FIELD IN THE CHROME BUT THE ONE CALL TO ACTION AND THE LIVE DOT.
     *
     * Chrome is on every page, so a family spent there is spent on the privacy policy.
     * Gold least of all: on this platform it means an award has been decided for a named
     * person, and a menu row painted gold is the fastest way to make it stop meaning that.
     */
    public function test_the_chrome_spends_no_family_but_the_call_to_action_and_the_live_dot(): void
    {
        $bad = [];

        foreach (glob(dirname(__DIR__, 2) . '/templates/layout/*.twig') as $path) {
            foreach (ColourFields::families($this->seen((string) file_get_contents($path))) as $family) {
                if ($family === 'green' || $family === 'live') continue;
                $bad[] = basename($path) . ': ' . $family;
            }
        }

        $this->assertSame([], $bad,
            "the chrome is spending a colour family on every page of the site:\n  " . implode("\n  ", $bad));
    }

    /** Proving the sweeps can fail before trusting them to pass. */
    public function test_the_ceiling_sweeps_see_the_handoffs_names(): void
    {
        $three = '<style>.a{background:var(--ag-gold-wash)} .b{background:var(--ag-green)}'
               . ' .c{background-color:var(--ag-live)}</style>';
        $this->assertSame(['gold', 'green', 'live'], ColourFields::families($three));

        // A focus ring and a link in green are structure, not a field.
        $this->assertSame([], ColourFields::families(
            $this->seen('<style>a{color:var(--ag-green)} a:focus-visible{outline:3px solid var(--ag-green)}</style>')));
        // An SVG filled gold is a field.
        $this->assertSame(['gold'], ColourFields::families('<svg fill="var(--ag-gold)"></svg>'));
        // A comment reaches no reader.
        $this->assertSame([], ColourFields::families($this->seen('{# background:var(--ag-gold-wash) #}')));
        // And reach counts the words too.
        $this->assertSame(['gold', 'green', 'live', 'error'], $this->reached(
            '.a{color:var(--ag-gold-ink)} .b{outline-color:var(--ag-green)} .c{color:var(--ag-live-ink)} .d{color:var(--ag-error)}'));
    }

    // ══ programme identity ═══════════════════════════════════════════════════

    public function test_a_programme_keeps_its_identity_wherever_it_is_drawn(): void
    {
        // From the programme's own id, never its position in a list.
        $a = Accent::forProgramme(7);
        $this->assertSame($a, Accent::forProgramme(7));

        // The first two programmes separate; a third wraps, and its printed name is what
        // tells it apart — the palette has two families no meaning has claimed.
        $this->assertNotSame(Accent::forProgramme(1)['key'], Accent::forProgramme(2)['key']);
        $this->assertSame(Accent::forProgramme(1)['key'], Accent::forProgramme(3)['key']);
        $this->assertSame('info', Accent::forProgramme(1)['key'], 'the first programme is info');

        foreach ([0, -3, PHP_INT_MAX, PHP_INT_MIN] as $odd) {
            $this->assertNotSame('', trim(Accent::forProgramme($odd)['fill']), (string) $odd);
        }
    }

    public function test_no_programme_identity_is_a_colour_that_means_something(): void
    {
        // A programme wearing gold reads as a decided award, green as something to press,
        // live as happening now, error as a refusal. Measured on the family's tokens, so a
        // programme added in one of those families fails by name.
        $claimed = [];
        foreach (array_unique(Accent::meanings()) as $family) {
            foreach (Accent::families()[$family] as $token) $claimed[$token] = $family;
        }

        foreach (Accent::programmes() as $key => $p) {
            foreach (['fill', 'ink'] as $slot) {
                $this->assertArrayNotHasKey($p[$slot], $claimed, sprintf(
                    "programme '%s' wears %s, which means '%s'", $key, $p[$slot], $claimed[$p[$slot]] ?? ''));
            }
        }
    }

    public function test_the_style_attribute_can_carry_nothing_but_the_palette(): void
    {
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->assertMatchesRegularExpression(
                '/^(--pg-(fill|edge|ink|wash):var\(--ag-[a-z0-9-]+\);?){4}$/', Accent::programmeStyle($id));
        }
    }

    // ══ helpers ═════════════════════════════════════════════════════════════

    /** @return array<string,string> */
    private function publicTemplates(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates')) as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;
            // The admin console is a dense tool with its own palette; this ceiling is about
            // the public pages a visitor meets once.
            if (str_contains($f->getPathname(), '/admin/')) continue;
            $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
        }

        return $out;
    }

    /**
     * What a reader sees: no Twig comments, and no focus rules — a focus ring is the
     * green of §13 on every interactive element and is not an accent anybody points at.
     */
    private function seen(string $body): string
    {
        $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);
        $body = (string) preg_replace('!/\*.*?\*/!s', ' ', $body);

        return (string) preg_replace('/[^{}\n][^{}]*:focus[a-z-]*[^{}]*\{[^{}]*\}/i', ' ', $body);
    }

    /** @return list<string> every family any of whose tokens appears at all */
    private function reached(string $body): array
    {
        $out = [];
        foreach (Accent::families() as $name => $f) {
            $tokens = array_unique(array_merge(array_values($f), Accent::fields()[$name]));
            // `live-wash` is error's field too; it is counted under `live`, whose it is.
            if ($name === 'error') $tokens = ['error'];
            $alt = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tokens));
            if (preg_match('/var\(\s*--ag-(?:' . $alt . ')(?![a-z0-9-])/', $body)) $out[] = $name;
        }

        return $out;
    }

    /** The family a page draws only in a state excluding its others — see ColourBudgetTest. */
    private function exclusiveFamily(string $body): string
    {
        return preg_match('/\{%-?\s*set\s+colour_alt\s*=\s*[\'"]([a-z]+)[\'"]\s*-?%\}/', $body, $m) ? $m[1] : '';
    }

    private function hue(string $hex): float
    {
        $h = Contrast::hex($hex);
        $r = hexdec(substr($h, 0, 2)) / 255;
        $g = hexdec(substr($h, 2, 2)) / 255;
        $b = hexdec(substr($h, 4, 2)) / 255;
        $mx = max($r, $g, $b);
        $d  = $mx - min($r, $g, $b);
        if ($d == 0.0) return 0.0;

        return 60 * match (true) {
            $mx === $r => fmod((($g - $b) / $d) + ($g < $b ? 6 : 0), 6),
            $mx === $g => (($b - $r) / $d) + 2,
            default    => (($r - $g) / $d) + 4,
        };
    }

    private function hueGap(string $a, string $b): float
    {
        $d = abs($this->hue($a) - $this->hue($b));

        return min($d, 360 - $d);
    }
}
