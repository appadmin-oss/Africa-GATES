<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Contrast;
use Tests\TestCase;

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
 *
 * ── WHAT IS LEFT HERE, AND WHY ───────────────────────────────────────────────
 *
 * `partials/tile.twig` (with `components/tile.css`) was destroyed on 3 Oct 2026 as an orphan of the old public pages
 * (docs/handoff/DESTROYED.md). The methods that rendered or read it went with it, and
 * their rules are in docs/handoff/inventory/_partials.md and _stylesheets.md, which the rebuild re-asserts. What
 * remains tests code that survived.
 */
final class TileTest extends TestCase
{
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
}
