<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The geometry of a small time-series chart, worked out in PHP.
 *
 * ── WHY THE ARITHMETIC IS NOT IN THE TEMPLATE ────────────────────────────────
 *
 * A chart is a scale, a baseline and a projection, and every one of those is a decision that
 * has to be the same for the line, the fill, the gridlines, the labels and the table beside
 * it. Spread across a Twig file they are five expressions that agree until somebody edits
 * one — and the failure mode is a chart that is subtly, unfalsifiably wrong rather than one
 * that breaks. So the shape is computed once, here, and the template only prints it.
 *
 * ── AND WHY THE BASELINE IS ALWAYS ZERO ──────────────────────────────────────
 *
 * This draws an AREA, and the area of a filled shape is what a reader takes the magnitude
 * from. Starting the y-axis at the series minimum would make 1,010 → 1,030 look like a
 * tripling, which is the oldest misleading chart there is. A zero baseline costs some
 * vertical resolution on a flat series and buys a picture that cannot lie.
 *
 * Rendered server-side, so it needs no script to appear, survives print, and is in the
 * document for a reader that does not run JavaScript.
 */
final class Spark
{
    /**
     * @param  list<array{date: string, balance: int, delta: int}> $series oldest first
     * @return array{
     *   ok: bool, w: float, h: float, line: string, area: string, last: array<string,mixed>,
     *   points: list<array{x: float, y: float, date: string, balance: int, delta: int}>,
     *   grid: list<array{y: float, label: string, value: int}>,
     *   ticks: list<array{x: float, label: string}>,
     *   max: int, first: array<string,mixed>, change: int
     * }
     */
    public static function chart(array $series, float $w = 720.0, float $h = 180.0): array
    {
        $n = count($series);
        // Two points is the minimum that is a LINE rather than a dot, and a dot is a stat
        // tile's job. The caller shows the tile alone below this.
        if ($n < 2) {
            return ['ok' => false, 'w' => $w, 'h' => $h, 'line' => '', 'area' => '',
                    'points' => [], 'grid' => [], 'ticks' => [], 'max' => 0,
                    'first' => [], 'last' => [], 'change' => 0];
        }

        $values = array_map(static fn (array $p): int => (int) $p['balance'], $series);
        $peak   = max($values);
        // A perfectly flat series at zero would divide by zero; one at any other value would
        // draw along the very top edge. Both are answered by giving the scale some headroom.
        $top    = $peak > 0 ? (int) self::niceCeil($peak) : 10;

        $x = static fn (int $i): float => round($i * ($w / ($n - 1)), 2);
        $y = static fn (int $v): float => round($h - ($v / $top) * $h, 2);

        $line   = '';
        $points = [];
        foreach ($series as $i => $p) {
            $px = $x($i);
            $py = $y((int) $p['balance']);
            $line .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py;
            $points[] = ['x' => $px, 'y' => $py, 'date' => (string) $p['date'],
                         'balance' => (int) $p['balance'], 'delta' => (int) $p['delta']];
        }
        // Closed down to the baseline and back, so the fill is the area under the line rather
        // than a shape whose bottom edge is the line's own mirror.
        $area = $line . 'L' . $x($n - 1) . ' ' . $h . 'L' . $x(0) . ' ' . $h . 'Z';

        // Three gridlines and no more: on a 180px plot a fourth is noise, and the value a
        // reader wants precisely is in the table view rather than off an axis.
        $grid = [];
        foreach ([0, 1, 2] as $k) {
            $v = (int) round($top * (1 - $k / 2));
            $grid[] = ['y' => $y($v), 'label' => self::compact($v), 'value' => $v];
        }

        // Ends and middle. More would collide at this width on a phone.
        $ticks = [];
        foreach ([0, intdiv($n - 1, 2), $n - 1] as $i) {
            $ticks[] = ['x' => $x($i), 'label' => date('j M', strtotime($series[$i]['date']))];
        }

        $first = $series[0];
        $last  = $series[$n - 1];

        return [
            'ok' => true, 'w' => $w, 'h' => $h,
            'line' => $line, 'area' => $area, 'points' => $points,
            'grid' => $grid, 'ticks' => $ticks, 'max' => $top,
            'first' => $first, 'last' => $last,
            'change' => (int) $last['balance'] - (int) $first['balance'],
        ];
    }

    /**
     * The next round number above a value, so the top gridline reads `1,500` and not `1,437`.
     *
     * An axis whose labels are arbitrary is an axis nobody reads — the number is there to be
     * a landmark, and a landmark has to be memorable.
     */
    private static function niceCeil(int $v): int
    {
        if ($v <= 10) return 10;
        $mag  = 10 ** (strlen((string) $v) - 1);
        $step = $mag / 2;
        return (int) (ceil($v / $step) * $step);
    }

    /** `1.2k` on an axis, because `1,240` at 9px collides with the line beside it. */
    public static function compact(int $v): string
    {
        if ($v >= 1000000) return rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'm';
        if ($v >= 1000)    return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k';
        return (string) $v;
    }
}
