<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\Support\AppTwig;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The tile: four parts, all of them required.
 *
 * An emoji pops for three reasons at once — fully saturated, small, and BOUNDED. Saturation
 * without a boundary is a wash; a boundary without saturation is a card. The tile is both,
 * at small size, rarely:
 *
 *     wash   the bounded ground, holding house ink above 12:1
 *     edge   a 1px hard boundary — what makes it an object rather than a tint
 *     fill   the saturated mark
 *     ink    the word, above 4.5:1 — saying what the colour says
 *
 * Remove any one and it stops being a tile, which is why this asserts the presence of all
 * four rather than the look of the result: no edge and it is a wash, no fill and it is a
 * card, no ink and it is decoration, no wash and it is a floating chip.
 */
final class TileTest extends TestCase
{
    private function render(array $vars): string
    {
        $t = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'strict_variables' => true, 'autoescape' => 'html',
        ]);
        AppTwig::equip($t);

        return $t->render('partials/tile.twig', $vars);
    }

    public function test_all_four_parts_reach_the_page(): void
    {
        $html = $this->render(['meaning' => 'award-decided', 'label' => 'Overall winner']);

        foreach (['--tile-wash', '--tile-edge', '--tile-fill', '--tile-ink'] as $part) {
            $this->assertStringContainsString($part, $html,
                "a tile without its {$part} is not a tile");
        }

        $this->assertStringContainsString('ag-tile__mark', $html);
        $this->assertStringContainsString('Overall winner', $html);
    }

    public function test_the_mark_is_decoration_and_the_word_is_the_fact(): void
    {
        // The colour is an accelerator on a word, never the fact itself — the rule that
        // keeps the palette honest for the ~1 in 12 men with a colour vision deficiency
        // and for every screen reader.
        $html = $this->render(['meaning' => 'withheld', 'label' => 'Withheld']);

        $this->assertMatchesRegularExpression(
            '/<span class="ag-tile__mark" aria-hidden="true">/', $html,
            'the mark is announced to a screen reader, which has nothing to say about it');
        $this->assertStringContainsString('<span>Withheld</span>', $html);
    }

    public function test_every_tile_labels_in_its_own_family_ink_and_it_holds(): void
    {
        // The previous palette's live ink was 4.44 on its own wash, so a live tile borrowed
        // house ink. The handoff's `live-ink` is 5.78 there, and the exception went with
        // its cause: every tile labels in its family's ink, measured here on the wash it
        // actually sits on, so a future value that breaks one fails by name.
        foreach (Accent::meanings() as $meaning => $family) {
            $c     = Accent::for($meaning);
            $style = Accent::tileStyle($meaning);

            $this->assertStringContainsString('--tile-ink:var(--ag-' . $c['ink'] . ')', $style, $meaning);
            $this->assertGreaterThanOrEqual(Contrast::TEXT,
                round(Contrast::ratio(Accent::hex($c['ink']), Accent::hex($c['wash'])), 2), $meaning);
        }
    }

    public function test_a_tile_points_at_the_palette_and_carries_no_literal(): void
    {
        foreach (array_keys(Accent::meanings()) as $meaning) {
            $this->assertMatchesRegularExpression(
                '/^(--tile-(wash|edge|fill|ink):var\(--ag-[a-z0-9-]+\);?){4}$/',
                Accent::tileStyle($meaning), $meaning);
        }
    }

    public function test_a_meaning_nobody_defined_stops_the_build(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Accent::tileStyle('gold');
    }

    public function test_the_tile_carries_no_literal_colour_of_its_own(): void
    {
        // Every value arrives inline from Accent. A fallback in the sheet would let a tile
        // render with three parts and a default, which is precisely the failure the
        // four-part rule exists to prevent — and it would look fine.
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/tile.css');
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        $this->assertStringNotContainsString('#', $css,
            'the tile sheet carries a literal colour, so a tile can be built from a hue '
            . 'somebody liked rather than from a meaning');

        foreach (['--tile-wash', '--tile-edge', '--tile-fill', '--tile-ink'] as $part) {
            $this->assertMatchesRegularExpression(
                '/var\(\s*' . preg_quote($part, '/') . '\s*\)/', $css,
                $part . ' is declared nowhere, so that part of the tile cannot be drawn');
        }
    }

    public function test_the_tile_has_no_lip(): void
    {
        // Depth that cannot be pressed is decoration. The lip collapses on press and that
        // collapse is what makes it an affordance; a tile is not pressed.
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/tile.css');

        $this->assertStringNotContainsString('border-bottom:', $css);
        $this->assertStringNotContainsString('box-shadow', $css);
    }

    public function test_the_live_pulse_is_opacity_only_and_stops_for_reduced_motion(): void
    {
        // The only motion any role is allowed, and only this: opacity, on the dot. Never
        // scale, never colour.
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/tile.css');

        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $css,
            'the pulse runs for somebody who asked for less motion');
        $this->assertMatchesRegularExpression('/@keyframes ag-tile-pulse\s*\{[^}]*opacity/s', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/@keyframes ag-tile-pulse\s*\{[^{]*\{[^}]*(transform|scale|background)/s', $css,
            'the pulse moves or recolours — it may only fade');
    }
}
