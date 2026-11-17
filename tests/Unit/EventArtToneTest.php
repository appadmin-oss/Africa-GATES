<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventArtTone;
use AfricaGates\Services\EventTierPalette;
use AfricaGates\Support\Contrast;
use PHPUnit\Framework\TestCase;

/**
 * THE SPOTLIGHT PANEL TAKES THE ARTWORK'S COLOUR (owner, 17 Nov 2026: "the colour must match
 * the art"). What these try to break: a poster's colour averaged into mud; its white card
 * taken for its colour; a black-and-white photograph handed an invented tint; a pale artwork
 * producing a date nobody can read; a value that is not a hex reaching a style attribute.
 *
 * Contrast is SAMPLED across the hue wheel at several saturations and lightnesses, the way
 * EventFlierThemeTest samples accents: every fault this kind of derivation has had here was on
 * a colour nobody would have written down.
 */
final class EventArtToneTest extends TestCase
{
    /** A poster: a ground colour, scattered flecks of others, and a big white card. */
    private function poster(array $ground, array $flecks, bool $card = true): \GdImage
    {
        $im = imagecreatetruecolor(300, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$ground));
        mt_srand(11);
        $cs = array_map(fn ($c) => imagecolorallocate($im, ...$c), $flecks);
        for ($i = 0; $i < 900; $i++) {
            $x = mt_rand(0, 300); $y = mt_rand(0, 200);
            imagefilledrectangle($im, $x, $y, $x + 3, $y + 1, $cs[$i % count($cs)]);
        }
        if ($card) imagefilledrectangle($im, 40, 20, 260, 90, imagecolorallocate($im, 250, 248, 240));
        return $im;
    }

    private function hue(string $hex): float
    {
        return EventTierPalette::toHsl($hex)[0];
    }

    public function test_a_green_poster_with_red_and_yellow_flecks_and_a_white_card_is_green(): void
    {
        $d = EventArtTone::dominant($this->poster([30, 120, 40], [[200, 30, 40], [230, 190, 40], [40, 90, 200]]));
        $this->assertNotNull($d);
        $h = $this->hue($d);
        $this->assertTrue($h > 90 && $h < 160, "a green poster read as hue {$h} — averaged into mud, or the white card taken for its colour");
    }

    public function test_a_red_poster_is_red_and_not_an_average_with_its_accent(): void
    {
        $h = $this->hue((string) EventArtTone::dominant($this->poster([190, 25, 35], [[20, 120, 40]], false)));
        $this->assertTrue($h < 20 || $h > 340, "a red poster read as hue {$h}");
    }

    public function test_a_photograph_with_no_colour_gets_the_neutral_panel_not_an_invented_tint(): void
    {
        $this->assertNull(EventArtTone::dominant($this->poster([120, 120, 120], [[30, 30, 30], [230, 230, 230]])));
    }

    public function test_the_panel_is_the_artworks_own_colour_and_every_word_on_it_is_readable(): void
    {
        $body = \AfricaGates\Support\Accent::hex('ink-2');
        foreach (range(0, 345, 15) as $h) {
            foreach ([0.25, 0.6, 1.0] as $s) {
                foreach ([0.15, 0.3, 0.5, 0.7, 0.9] as $l) {
                    $seed = strtoupper(EventTierPalette::fromHsl((float) $h, $s, $l));
                    $t = EventArtTone::slots($seed);
                    $ctx = "seed {$seed} → {$t['mode']} {$t['wash']}";
                    $words = $t['mode'] === 'dark' ? '#FFFFFF' : $body;
                    $this->assertGreaterThanOrEqual(Contrast::TEXT, Contrast::ratio($words, $t['wash']), "{$ctx}: the title and details are unreadable on the panel");
                    $this->assertGreaterThanOrEqual(Contrast::TEXT, Contrast::ratio($t['ink'], $t['wash']), "{$ctx}: the date is unreadable on the panel");
                    $this->assertGreaterThanOrEqual(3.0, Contrast::ratio($t['dot'], $t['wash']), "{$ctx}: the type's dot vanishes into the panel");
                    // A colour one of the two inks can already be read on is used EXACTLY:
                    // "the colour must match the art" (owner, 17 Nov 2026).
                    if (Contrast::ratio('#FFFFFF', $seed) >= Contrast::TEXT || Contrast::ratio($body, $seed) >= Contrast::TEXT) {
                        $this->assertSame($seed, $t['wash'], "{$ctx}: a readable artwork colour was changed anyway");
                    }
                    $this->assertEqualsWithDelta(EventTierPalette::hue((float) $h), EventTierPalette::toHsl($t['wash'])[0], 3.0, "{$ctx}: the panel drifted off the artwork's hue");
                }
            }
        }
    }

    public function test_a_dark_panel_inverts_its_words_and_actions(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/events-index.css');
        foreach (['.ei-art--dark .ei-slide__t a', '.ei-art--dark .ei-btn--go{ background:var(--ag-surface); color:var(--ei-wash) }',
                  '.ei-art--dark .ei-btn--line{ border-color:var(--ag-surface); color:var(--ag-surface) }', '.ei-art--dark .ei-pill{ color:var(--ei-wash) }'] as $rule) {
            $this->assertStringContainsString($rule, $css, 'a word or action left in its light-panel colour on a dark panel');
        }
        // The artwork is a flyer: shown whole and never faded across its lettering.
        $this->assertStringContainsString('.ei-art--dark .ei-slide__fade, .ei-art--light .ei-slide__fade{ display:none }', $css, 'the fade runs across the poster');
        $this->assertMatchesRegularExpression('~\.ei-art--dark \.ag-photo__img, \.ei-art--light \.ag-photo__img\{[^}]*object-fit:contain~', $css, 'the poster is cropped to fill the box');
        $this->assertSame('dark', EventArtTone::slots('#257629')['mode'], 'a deep green poster drawn with dark text');
        $this->assertSame('light', EventArtTone::slots('#F2D16B')['mode'], 'a pale yellow poster drawn with white text');
    }

    public function test_only_validated_hexes_reach_a_style_attribute(): void
    {
        $this->assertSame('--ei-wash:#EAF5EB;--ei-ink:#257428;--ei-dot:#329F38;--ei-edge:#BCDCBE',
            EventArtTone::style(['wash' => '#eaf5eb', 'ink' => '#257428', 'dot' => '#329F38', 'edge' => '#BCDCBE']));
        $this->assertSame('', EventArtTone::mode(['mode' => 'neon']));
        $this->assertSame('', EventArtTone::style(['wash' => 'red;background:url(x)', 'ink' => '#000000', 'dot' => '#000000', 'edge' => '#000000']));
        $this->assertSame('', EventArtTone::style(null));
    }

    public function test_an_address_that_cannot_be_read_falls_back_quietly(): void
    {
        $this->assertNull(EventArtTone::of('/assets/img/no-such-artwork-anywhere.png'));
        $this->assertNull(EventArtTone::of('http://plain-http.example/x.png'), 'fetched over plain http');
        $this->assertNull(EventArtTone::of('/../../etc/passwd'));
        $this->assertNull(EventArtTone::of(''));
    }
}
