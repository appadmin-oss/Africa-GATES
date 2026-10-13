<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

/**
 * Timestamps into bars, for the console's charts (owner's screenshots, 13 Oct 2026).
 *
 * Bucketed in PHP from the rows' own timestamps rather than with a SQL date function:
 * SQLite's strftime and MySQL's DATE_FORMAT disagree on every format specifier that
 * matters here, and a chart that buckets differently in the suite and on production is a
 * chart nobody can check. The callers bound the read (a range and a row cap), so this
 * never sees an unbounded table.
 *
 * The step is chosen so a window has at most `$max` bars — an hour for a day, six hours
 * for a week, a day for a month — and every bucket in the window is present, zero or not,
 * because a chart that silently skips empty hours draws a quiet afternoon as a busy one.
 */
final class TimeSeries
{
    private const STEPS = [3600, 3 * 3600, 6 * 3600, 12 * 3600, 86400, 2 * 86400, 7 * 86400];

    /**
     * @param iterable<string|int|null> $stamps
     * @return array{step:int, max:int, total:int, bars:list<array{from:int,to:int,n:int,label:string,q_from:string,q_to:string}>}
     */
    public static function bars(iterable $stamps, TimeRange $range, int $max = 56): array
    {
        $span = $range->seconds();
        $step = self::STEPS[count(self::STEPS) - 1];
        foreach (self::STEPS as $s) {
            if ($span / $s <= $max) { $step = $s; break; }
        }
        // Align to the step (in the display zone, via date offsets) so bars start on the
        // hour or at midnight rather than at whatever second the page was opened.
        $offset = (int) TimeRange::show($range->from, 'Z');
        $start  = (int) (floor(($range->from + $offset) / $step) * $step) - $offset;
        $n      = max(1, (int) ceil(($range->to - $start) / $step));

        $counts = array_fill(0, $n, 0);
        $total = 0;
        foreach ($stamps as $s) {
            if ($s === null || $s === '') continue;
            $t = is_int($s) ? $s : strtotime(str_replace('T', ' ', (string) $s));
            if ($t === false || $t < $range->from || $t > $range->to) continue;
            $i = (int) floor(($t - $start) / $step);
            if ($i < 0 || $i >= $n) continue;
            $counts[$i]++;
            $total++;
        }

        $fmt = $step >= 86400 ? 'j M' : 'j M, H:i';
        $bars = [];
        foreach ($counts as $i => $c) {
            $from = $start + $i * $step;
            $bars[] = ['from' => $from, 'to' => $from + $step, 'n' => $c, 'label' => TimeRange::show($from, $fmt),
                       // The bar's own window as a link carries it — in the display zone,
                       // as everything typed into the range is.
                       'q_from' => TimeRange::show($from, 'Y-m-d H:i'), 'q_to' => TimeRange::show($from + $step, 'Y-m-d H:i')];
        }

        return ['step' => $step, 'max' => $counts === [] ? 0 : max($counts), 'total' => $total, 'bars' => $bars];
    }
}
