<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

/**
 * THE NIGHT PALETTE IS HELD TO THE DAY'S RULE, ROW FOR ROW.
 *
 * Dark mode is the same names with different values (Accent::NIGHT), so every stylesheet
 * that reads `--ag-ink` reads the night ink without knowing. That is what makes it cheap,
 * and it is also what makes it dangerous: a night value nobody measured is a word that
 * vanishes on every page at once, for exactly the readers who chose dark because light
 * text on a dark ground is what they can read.
 *
 * So the night palette is measured against the SAME table as the day — Accent::words(),
 * each word on each ground it is drawn on — at the same 4.5:1. There is no second, kinder
 * list for night, and adding a row to WORDS adds it to both schemes at once.
 */
final class AccentNightTest extends TestCase
{
    public function test_every_palette_name_has_a_night_value_and_nothing_else_does(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(Accent::palette()), array_keys(Accent::nightPalette()))),
            'a day token with no night value keeps its day colour on a dark page');
        $this->assertSame([], array_values(array_diff(array_keys(Accent::nightPalette()), array_keys(Accent::palette()))),
            'a night value under a name the day does not have');
    }

    public function test_every_word_reads_at_night_on_every_ground_it_is_drawn_on(): void
    {
        $n = Accent::nightPalette();
        $bad = [];
        foreach (Accent::words() as $word => $grounds) {
            foreach ($grounds as $g) {
                $r = Contrast::ratio($n[$word], $n[$g]);
                if ($r < Contrast::TEXT) $bad[] = sprintf('%s on %s: %.2f', $word, $g, $r);
            }
        }
        $this->assertSame([], $bad, "night words under 4.5:1:\n  " . implode("\n  ", $bad));
    }

    public function test_every_word_reads_in_sepia_on_every_ground_it_is_drawn_on(): void
    {
        $n = Accent::sepiaPalette();
        $this->assertSame([], array_values(array_diff(array_keys(Accent::palette()), array_keys($n))), 'a token with no sepia value');
        $bad = [];
        foreach (Accent::words() as $word => $grounds) {
            foreach ($grounds as $g) {
                $r = Contrast::ratio($n[$word], $n[$g]);
                if ($r < Contrast::TEXT) $bad[] = sprintf('%s on %s: %.2f', $word, $g, $r);
            }
        }
        $this->assertSame([], $bad, "sepia words under 4.5:1:\n  " . implode("\n  ", $bad));
    }

    public function test_the_night_scheme_is_actually_dark_and_the_day_actually_light(): void
    {
        // The direction is the claim: ink must be the LIGHT one at night and the ground the
        // dark one, or "dark mode" is the day palette with its contrast intact and inverted
        // nowhere — every ratio above would still pass.
        $this->assertGreaterThan(Contrast::luminance(Accent::night('ground')), Contrast::luminance(Accent::night('ink')));
        $this->assertLessThan(0.05, Contrast::luminance(Accent::night('ground')));
        $this->assertLessThan(Contrast::luminance(Accent::hex('ground')), Contrast::luminance(Accent::hex('ink')));
    }

    public function test_the_page_gets_night_behind_the_device_setting_and_the_readers_choice(): void
    {
        $css = Accent::css();
        $this->assertStringContainsString('@media (prefers-color-scheme:dark){:root:where(:not(.ag-light):not(.ag-sepia)){--ag-ink:' . Accent::night('ink') . ';', $css);
        $this->assertStringContainsString(':root:where(.ag-sepia){--ag-ink:' . Accent::sepia('ink') . ';', $css);
        $this->assertStringContainsString(':root:where(.ag-dark){--ag-ink:' . Accent::night('ink') . ';', $css);
        $this->assertStringContainsString('color-scheme:light;', $css);
        // `:where()` keeps the night selectors at `:root`'s specificity: shell.css's
        // `html.ag-hc` remaps these same names for high contrast and must still win at night.
        $this->assertStringNotContainsString(':root.ag-dark', $css);
        $this->assertStringNotContainsString(':root:not(.ag-light)', $css);
    }

    public function test_mail_and_drawn_images_keep_the_day_palette(): void
    {
        // An inbox and a printed ticket are not dark mode: hex() and mail() never return night.
        $this->assertSame(Accent::palette()['ink']['value'], Accent::hex('ink'));
        $this->assertSame(Accent::palette()['ground']['value'], Accent::mail()['ground']);
    }

    public function test_the_typed_cover_tones_have_night_values(): void
    {
        // A cover tone whose day value is a typed pale hex, with no night value, is a light
        // tile on a dark page — the info cover was exactly that.
        $css = Accent::css();
        foreach (Accent::coverTones() as $tone) {
            foreach (['bg', 'edge'] as $slot) {
                $day = Accent::coverHex($tone, $slot);
                if (Contrast::luminance($day) > 0.5) {
                    // Either a token reference (follows the scheme) or a night override.
                    $name = 'cover-' . $tone . '-' . $slot;
                    $byRef = (bool) preg_match('/--ag-' . $name . ':var\(/', substr($css, 0, (int) strpos($css, '@media')));
                    $this->assertTrue($byRef || isset(Accent::nightTones()[$name]), "$name is pale and has no night value");
                }
            }
        }
    }
}
