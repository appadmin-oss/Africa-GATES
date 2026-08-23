<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Does the market you have published actually fit in the hall you have hired?
 *
 * ── THE FAILURE THIS EXISTS TO CATCH ─────────────────────────────────────────
 *
 * An organiser sets forty pitches at 3m × 3m and twelve at 6m × 3m, publishes the call, takes
 * a hundred applications, allocates every place — and discovers on build morning that 576m²
 * of stands do not fit in a 500m² hall with room to walk between them. There is no good move
 * left at that point. Every option is a broken promise: pitches shrunk without notice, vendors
 * turned away who hold an accepted offer, or a room the fire officer closes.
 *
 * The arithmetic that prevents it is trivial and nobody does it, because it lives in a
 * different head from the one setting quotas. So it is done here, on the same screen, at the
 * moment the quota is typed.
 *
 * ── WHAT THIS IS NOT ─────────────────────────────────────────────────────────
 *
 * It is NOT a site plan. It knows nothing about fire exits, columns, power drops, the servery,
 * the stage, or which wall the loading door is on. It packs equal-width rows into a rectangle,
 * which is a deliberately crude model of a hall.
 *
 * That crudeness is the honest part, and every surface that renders this says so in words. A
 * diagram that LOOKS like a fire-safety document while knowing nothing about fire is worse
 * than no diagram, because somebody will forward it to the venue. What it answers is one
 * question — "is this plausibly the right order of magnitude, or is it obviously impossible?"
 * — and that one question is where the money is.
 *
 * ── AND WHY THE AISLE ALLOWANCE IS GENEROUS BY DEFAULT ───────────────────────
 *
 * `aisle_pct` is the share of the floor no pitch can stand on: circulation, fire lanes, the
 * queue in front of a servery, the gap a wheelchair needs. It defaults to 35%. Planning
 * against 100% of a hall produces a market with nowhere to walk, and the cost of erring in
 * that direction — a closed event — is not symmetrical with the cost of erring the other way,
 * which is a few empty metres.
 */
final class StandFloorPlan
{
    /** Below this the arithmetic is meaningless — no venue has been measured. */
    public const NO_VENUE = 0;

    /** Gap drawn between pitches in the preview, in centimetres. Guy ropes and elbows. */
    private const GAP_CM = 50;

    /**
     * Everything the screens need to draw and to warn.
     *
     * @return array{
     *   measured:bool, gross_sqm:float, usable_sqm:float, aisle_pct:int,
     *   floor_w_cm:int, floor_d_cm:int, committed_sqm:float, free_sqm:float,
     *   used_pct:int, fits:bool, over_sqm:float, pitches:int,
     *   types:array<int,array<string,mixed>>, categories:array<int,array<string,mixed>>,
     *   blocks:array<int,array<string,mixed>>, placed:int, unplaced:int
     * }
     */
    public static function forEvent(int $eventId): array
    {
        $call  = StandCall::forEvent($eventId);
        $types = StandType::forEvent($eventId);

        $floorW = (int) ($call->floor_width_cm ?? 0);
        $floorD = (int) ($call->floor_depth_cm ?? 0);
        // Clamped rather than trusted. A 100% aisle allowance means no sellable floor at all,
        // which turns every subsequent percentage into a division by zero.
        $aisle  = max(0, min(80, (int) ($call->aisle_pct ?? 35)));

        $measured = $floorW > self::NO_VENUE && $floorD > self::NO_VENUE;
        $gross    = $measured ? round($floorW * $floorD / 10000, 1) : 0.0;
        $usable   = round($gross * (100 - $aisle) / 100, 1);

        // ── what has been committed ─────────────────────────────────────────
        $rows      = [];
        $committed = 0.0;
        $pitches   = 0;
        $byCategory = [];

        foreach (array_values($types) as $i => $t) {
            $each  = StandType::areaSqm($t);
            $quota = (int) $t->quota;
            $total = round($each * $quota, 2);

            $committed += $total;
            $pitches   += $quota;

            $rows[] = [
                'type'     => $t,
                'index'    => $i,                     // fixed slot, so colour follows the type
                'each_sqm' => $each,
                'quota'    => $quota,
                'total_sqm'=> $total,
                // The label follows the unit the pitch was created in; the ARITHMETIC above
                // is always centimetres. A plan captioned in metres beside a call advertised
                // in feet is two documents about the same hall that disagree.
                'size'     => StandPreset::labelForType($t),
            ];

            $cat = (string) $t->category;
            $byCategory[$cat] ??= ['slug' => $cat, 'quota' => 0, 'sqm' => 0.0];
            $byCategory[$cat]['quota'] += $quota;
            $byCategory[$cat]['sqm']   += $total;
        }

        $committed = round($committed, 2);

        // Categories in the fixed order of StandType::CATEGORIES, never in insertion order:
        // a colour that moves when a stand type is added is a colour that means nothing.
        $cats = [];
        $ci   = 0;
        foreach (StandType::CATEGORIES as $slug => $label) {
            if (!isset($byCategory[$slug])) continue;
            $c = $byCategory[$slug];
            $cats[] = [
                'slug'   => $slug,
                'label'  => $label,
                'index'  => $ci++,
                'quota'  => $c['quota'],
                'sqm'    => round($c['sqm'], 2),
                'pct'    => $pitches > 0 ? (int) round($c['quota'] / $pitches * 100) : 0,
            ];
        }

        // Blocks are coloured by CATEGORY, not by stand type, so this card carries ONE colour
        // scale. Two scales on one screen — orange meaning "double gazebo" in the plan and
        // "craft and handmade" in the mix bar 300px below it — is the reading error that
        // makes a legend worse than none. It is also the more useful grouping: an organiser
        // reading a plan wants to see whether the food is clustered, not which SKU it is.
        $catIndex = [];
        foreach ($cats as $c) $catIndex[$c['slug']] = $c['index'];

        $layout = self::pack($rows, $floorW, $floorD, $measured, $catIndex);

        return [
            'measured'      => $measured,
            'floor_w_cm'    => $floorW,
            'floor_d_cm'    => $floorD,
            'aisle_pct'     => $aisle,
            'gross_sqm'     => $gross,
            'usable_sqm'    => $usable,
            'committed_sqm' => $committed,
            'free_sqm'      => $measured ? round(max(0, $usable - $committed), 1) : 0.0,
            'used_pct'      => $usable > 0 ? (int) round($committed / $usable * 100) : 0,
            'fits'          => !$measured || $committed <= $usable,
            'over_sqm'      => $measured ? round(max(0, $committed - $usable), 1) : 0.0,
            'pitches'       => $pitches,
            'types'         => $rows,
            'categories'    => $cats,
            'blocks'        => $layout['blocks'],
            'placed'        => $layout['placed'],
            'unplaced'      => $layout['unplaced'],
            'note'          => trim((string) ($call->floor_note ?? '')),
        ];
    }

    /**
     * Lay the pitches out in rows, largest first, and report what did not fit.
     *
     * ── SHELF PACKING, AND WHY THAT IS THE RIGHT CRUDENESS ───────────────────
     *
     * Rows across the width, each row as deep as its deepest pitch, a gap between neighbours
     * and between rows. It is the simplest algorithm that models how a market is actually
     * built — nobody tessellates a hall, they run aisles of stalls.
     *
     * Largest first, because that is also how a market is built: the anchor and the hot food
     * go in before the jewellery is fitted around them. Packing smallest-first would produce
     * a prettier number and a layout no build crew would recognise.
     *
     * `unplaced` is the number that ran off the end of the hall, and it is reported rather
     * than hidden. A preview that silently drops the pitches it could not fit is a preview
     * that says everything is fine.
     *
     * @return array{blocks:array<int,array<string,mixed>>,placed:int,unplaced:int}
     */
    private static function pack(
        array $rows, int $floorW, int $floorD, bool $measured, array $catIndex = []
    ): array {
        if (!$measured || $rows === []) return ['blocks' => [], 'placed' => 0, 'unplaced' => 0];

        $items = [];
        foreach ($rows as $r) {
            $t = $r['type'];
            for ($n = 0; $n < (int) $r['quota']; $n++) {
                $items[] = [
                    'w'     => (int) $t->width_cm,
                    'd'     => (int) $t->depth_cm,
                    'index' => $r['index'],
                    'name'  => (string) $t->name,
                    'cat'   => (string) $t->category,
                    'ci'    => $catIndex[(string) $t->category] ?? 0,
                ];
            }
            // A runaway quota must not render a hundred thousand rectangles into a page.
            if (count($items) > 600) break;
        }

        // Deepest first, then widest: rows come out even, which is what a build crew wants
        // and also what makes the picture legible.
        usort($items, static fn($a, $b) => [$b['d'], $b['w']] <=> [$a['d'], $a['w']]);

        $blocks = [];
        $x = 0; $y = 0; $rowDepth = 0; $unplaced = 0;

        foreach ($items as $i => $it) {
            // A pitch wider than the hall never fits, in any row. Counted, not wrapped.
            if ($it['w'] > $floorW) { $unplaced++; continue; }

            if ($x > 0 && $x + $it['w'] > $floorW) {           // start a new row
                $y += $rowDepth + self::GAP_CM;
                $x = 0;
                $rowDepth = 0;
            }
            if ($y + $it['d'] > $floorD) { $unplaced++; continue; }   // off the end of the hall

            $blocks[] = [
                'x' => $x, 'y' => $y, 'w' => $it['w'], 'd' => $it['d'],
                'index' => $it['index'], 'cat_index' => $it['ci'],
                'name' => $it['name'], 'cat' => $it['cat'],
                'label' => StandType::metres($it['w']) . '×' . StandType::metres($it['d']),
            ];

            $x += $it['w'] + self::GAP_CM;
            $rowDepth = max($rowDepth, $it['d']);
        }

        return ['blocks' => $blocks, 'placed' => count($blocks), 'unplaced' => $unplaced];
    }
}
