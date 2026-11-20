<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventTierPalette;
use AfricaGates\Services\OrgBrand;
use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

/**
 * AN ORGANISATION MAY PICK ANY COLOUR, AND NOTHING DRAWN FROM IT MAY BE UNREADABLE.
 *
 * The accent used to be REFUSED under 4.5:1 on white — a children's charity in sunflower
 * yellow was told to "try a darker version" of a brand it does not own a darker version of.
 * Now the choice is kept exactly, and OrgBrand::css() derives the shades that carry words
 * and edges, for the day ground and the night one.
 *
 * Asserted by SAMPLING THE COLOUR SPACE, never by listing colours: the hue wheel at three
 * saturations and five lightnesses, the greys, and the primaries. Every fault a palette
 * derivation has had here was on a colour nobody would have written down (EventFlierThemeTest:
 * pure #0000ff is "lightness 0.50" to HSL and dark to the eye).
 */
final class OrgAccentTest extends TestCase
{
    /** @return list<string> */
    private function samples(): array
    {
        $out = ['#ffffff', '#000000', '#ff0000', '#00ff00', '#0000ff', '#ffff00', '#00ffff', '#ff00ff', '#f2f2c0', '#808080', '#777777'];
        foreach ([0.35, 0.7, 1.0] as $s) {
            foreach ([0.15, 0.35, 0.5, 0.65, 0.85] as $l) {
                for ($h = 0; $h < 360; $h += 20) $out[] = strtolower(EventTierPalette::fromHsl($h, $s, $l));
            }
        }
        foreach ([0x11, 0x44, 0x88, 0xbb, 0xee] as $g) $out[] = sprintf('#%02x%02x%02x', $g, $g, $g);
        return $out;
    }

    private static function mix(string $a, string $b, float $alpha): string
    {
        $c = static fn (string $h, int $i): int => (int) hexdec(substr(ltrim($h, '#'), $i, 2));
        return sprintf('#%02x%02x%02x',
            (int) round($c($a, 0) * $alpha + $c($b, 0) * (1 - $alpha)),
            (int) round($c($a, 2) * $alpha + $c($b, 2) * (1 - $alpha)),
            (int) round($c($a, 4) * $alpha + $c($b, 4) * (1 - $alpha)));
    }

    public function test_any_colour_is_kept_exactly_as_chosen(): void
    {
        foreach (['#f2f2c0', '#ffd400', '#7fffd4', '#1a6118'] as $hex) {
            $this->assertSame($hex, OrgBrand::css(['accent' => $hex])['accent'], 'the fill is their colour, unchanged');
            $this->assertSame($hex, OrgBrand::of((object) ['brand_json' => json_encode(['accent' => $hex])])['accent'],
                'a stored pale accent is no longer swapped for the house green on the way out');
        }
    }

    public function test_every_word_and_edge_drawn_from_any_accent_reads_by_day_and_by_night(): void
    {
        $bad = [];
        $day   = ['surface' => Accent::hex('surface'), 'ground' => Accent::hex('ground')];
        $night = ['surface' => Accent::night('surface'), 'ground' => Accent::night('ground')];

        foreach ($this->samples() as $hex) {
            $c = OrgBrand::css(['accent' => $hex]);
            $checks = [
                ['ink on surface',        $c['accent_ink'],        $day['surface'],   Contrast::TEXT],
                ['ink on ground',         $c['accent_ink'],        $day['ground'],    Contrast::TEXT],
                ['ink on wash',           $c['accent_ink'],        self::mix($hex, $day['ground'], 0.10), Contrast::TEXT],
                ['edge on surface',       $c['accent_edge'],       $day['surface'],   Contrast::UI],
                ['night ink on surface',  $c['accent_ink_night'],  $night['surface'], Contrast::TEXT],
                ['night ink on ground',   $c['accent_ink_night'],  $night['ground'],  Contrast::TEXT],
                ['night ink on wash',     $c['accent_ink_night'],  self::mix($hex, $night['ground'], 0.10), Contrast::TEXT],
                ['night edge on surface', $c['accent_edge_night'], $night['surface'], Contrast::UI],
                // The label on their own fill is an ICON (the play button), so it owes the UI
                // floor. Ink-or-white by ratio always clears it: the worst mid-tone is 3.9:1.
                ['label on fill',         $c['accent_on'],         $hex,              Contrast::UI],
            ];
            foreach ($checks as [$what, $fg, $bg, $floor]) {
                $r = Contrast::ratio($fg, $bg);
                if ($r < $floor) $bad[] = sprintf('%s: %s %s on %s = %.2f', $hex, $what, $fg, $bg, $r);
            }
        }
        $this->assertSame([], $bad, implode("\n", array_slice($bad, 0, 20)));
    }

    public function test_a_shade_keeps_the_hue_the_organisation_chose(): void
    {
        // Readable is not enough: a yellow brand whose figures come out grey has lost its
        // colour. The derived ink keeps the seed's hue, within rounding.
        foreach (['#ffd400', '#7fffd4', '#ff69b4', '#87ceeb'] as $hex) {
            [$h]  = EventTierPalette::toHsl($hex);
            [$hi] = EventTierPalette::toHsl(OrgBrand::css(['accent' => $hex])['accent_ink']);
            $d = abs($h - $hi); $d = min($d, 360 - $d);
            $this->assertLessThan(6.0, $d, "$hex lost its hue");
        }
    }

    public function test_a_pale_accent_saves_and_an_unparseable_one_is_still_refused(): void
    {
        // The refusal for a colour that is too pale is gone; the refusal for something that
        // is not a colour at all stays — that one protects the CSS it is written into.
        $this->assertTrue(OrgBrand::readable('#1a6118'));
        $this->assertFalse(OrgBrand::readable('#f2f2c0'), 'readable() still answers honestly about the colour as picked');
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/OrgBrand.php');
        $this->assertStringNotContainsString('cannot be read on a white page', $src);
        $this->assertStringContainsString('That is not a colour we can read', $src);
    }

    public function test_reach_moves_away_from_the_ground_and_stops_at_the_floor(): void
    {
        $this->assertSame('#1a6118', Contrast::reach('#1a6118', ['#ffffff']), 'a colour that already passes is returned as it is');
        $dark = Contrast::reach('#ffd400', ['#ffffff']);
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($dark, '#ffffff'));
        $this->assertLessThan(Contrast::luminance('#ffd400'), Contrast::luminance($dark), 'on a light ground it darkens');
        $light = Contrast::reach('#1a3d8f', ['#0e1819']);
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($light, '#0e1819'));
        $this->assertGreaterThan(Contrast::luminance('#1a3d8f'), Contrast::luminance($light), 'on a dark ground it lightens');
    }

    public function test_the_page_paints_words_and_edges_from_the_measured_shades(): void
    {
        $root = dirname(__DIR__, 2);
        $tpl = (string) file_get_contents($root . '/templates/partials/org-page.twig');
        foreach (['accent_ink', 'accent_ink_night', 'accent_edge', 'accent_edge_night', 'accent_on'] as $k) {
            $this->assertStringContainsString('brand_css.' . $k, $tpl, "$k never reaches the page");
        }
        $css = (string) file_get_contents($root . '/public/assets/css/components/giving.css');
        $this->assertStringContainsString('.ob-fig__n{', $css);
        $this->assertMatchesRegularExpression('/\.ob-fig__n\{[^}]*color:var\(--ob-ink\)/', $css,
            'a figure drawn in the raw accent is unreadable for any pale brand colour');
        $this->assertMatchesRegularExpression('/:root:where\(\.ag-dark\) \.ob\{[^}]*--ob-accent-ink-night/', $css,
            'the night shade is chosen when the reader picks Dark');
    }
}
