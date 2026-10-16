<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventTierPalette;
use AfricaGates\Services\EventTierTone;
use PHPUnit\Framework\TestCase;

/**
 * THE FIVE DC PROPERTIES PER TIER, DERIVED — AND THEIR FLOORS, SAMPLED (§8.10, GAPS Q6).
 *
 * `EventTierPalette::ramp()` turns one tier fill into `accent`, `light`, `wash`, `deep` and
 * `glow`. `deep` is every WORD drawn in the tier's colour and the CTA's ground under white,
 * so it owes 4.5:1 on white AND on the tier's own wash — for ANY accent an organiser can
 * pick, which is why the space is sampled (CLAUDE.md: "do not add a colour path here
 * without extending that sweep") rather than three colours somebody had in mind.
 */
final class EventTierGlowTest extends TestCase
{
    /** @return list<string> */
    private static function accents(): array
    {
        $out = ['#000000', '#FFFFFF', '#808080', '#333333', '#CCCCCC', '#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#10292C', '#F3B416'];
        for ($h = 0; $h < 360; $h += 20) {
            foreach ([0.45, 0.95] as $s) foreach ([0.25, 0.5, 0.8] as $l) $out[] = EventTierPalette::fromHsl($h, $s, $l);
        }
        return $out;
    }

    public function test_deep_is_a_word_on_white_and_on_the_wash_for_every_slot_of_every_accent(): void
    {
        $bad = [];
        foreach (self::accents() as $a) {
            foreach (array_keys(EventTierPalette::SLOTS) as $slot) {
                $c = EventTierTone::card(['colour' => $slot], ['ticket_accent' => $a]);
                $w = EventTierPalette::contrast($c['deep'], '#FFFFFF');
                $v = EventTierPalette::contrast($c['deep'], $c['wash']);
                if ($w < 4.5 || $v < 4.5) $bad[] = sprintf('%s/%s deep %s: %.2f on white, %.2f on wash', $a, $slot, $c['deep'], $w, $v);
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_the_wash_is_a_tint_and_the_pair_is_untouched(): void
    {
        foreach (self::accents() as $a) {
            $c = EventTierTone::card(['colour' => 'cool'], ['ticket_accent' => $a]);
            $this->assertSame(EventTierTone::hues(['colour' => 'cool'], ['ticket_accent' => $a]), ['hue' => $c['hue'], 'edge' => $c['edge']],
                'card() extends hues(); it never changes the pair the printed dot draws');
            $this->assertGreaterThan(0.85, EventTierPalette::toHsl($c['wash'])[2], "$a: the wash stays a light ground");
            foreach (['accent', 'light', 'wash', 'deep', 'glow'] as $k) $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $c[$k]);
        }
    }

    public function test_a_tier_with_no_colour_is_the_house_green_family(): void
    {
        $c = EventTierTone::card(['colour' => ''], null);
        $this->assertSame(EventTierTone::DEFAULT_HUE, $c['hue']);
        $this->assertSame(strtoupper(EventTierTone::DEFAULT_HUE), $c['accent']);
    }

    public function test_changing_the_events_accent_moves_every_property(): void
    {
        $a = EventTierTone::card(['colour' => 'warm'], ['ticket_accent' => '#1F6FA3']);
        $b = EventTierTone::card(['colour' => 'warm'], ['ticket_accent' => '#B4452F']);
        foreach (['accent', 'light', 'wash', 'deep', 'glow'] as $k) $this->assertNotSame($a[$k], $b[$k], $k);
    }
}
