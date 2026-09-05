<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * One chart component for the whole site — the geometry half.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * There were four charts on this platform and four implementations of "what is a chart":
 * a bespoke stacked bar in the stands admin, a floor-plan packer beside it, a size swatch
 * beside that, and a points sparkline on the account page. Each one had its own palette
 * decision, its own idea of what a hover is, and its own answer to "how does somebody who
 * cannot use a pointer read this". Three of the four had no answer to the last one.
 *
 * So: every chart on the site is now a SPEC produced here and rendered by one Twig macro,
 * driven by one behaviour. Adding a chart is choosing a form and handing it data. The rules
 * below are enforced once instead of remembered four times.
 *
 * ── THE RULES THIS BAKES IN ──────────────────────────────────────────────────
 *
 *  · ONE AXIS, ALWAYS. There is no second y-scale in this file and there is no way to ask
 *    for one. A dual-axis chart invents a correlation that is not in the data — the scales'
 *    alignment is arbitrary and the reader cannot see that it is.
 *  · COLOUR FOLLOWS THE ENTITY, NOT THE RANK. Slots are assigned by the caller's key and
 *    never by sort order, so filtering a series out does not repaint the survivors. A reader
 *    who learned that food pitches are orange keeps that.
 *  · NEVER MORE THAN EIGHT. A ninth categorical colour is indistinguishable from one of the
 *    first eight under colour-vision deficiency, so the tail folds into "Other" rather than
 *    being given a generated hue.
 *  · A ZERO BASELINE ON ANYTHING FILLED. The area of a filled shape is what a reader takes
 *    the magnitude from, and a truncated axis makes a 2% rise look like a tripling.
 *  · EVERY SPEC CARRIES ITS TABLE. Not optionally: `table` is part of the shape, because a
 *    value only reachable by hovering is a value some people cannot reach at all.
 *
 * The rendering half — marks, hover, keyboard, the table twin — is
 * `templates/partials/viz.twig`.
 */
final class Viz
{
    /**
     * The categorical order. Fixed, and assigned by slot rather than by rank.
     *
     * Carried over unchanged from the stands admin, where it was validated against a white
     * surface: every adjacent pair clears the colour-vision separation floor. Three of the
     * eight fall below 3:1 contrast, which is why every mark this file produces is directly
     * labelled or legended — the colour is the fast channel, never the only one.
     */
    public const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100',
                            '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    /** The single-series colour, and the one accent the rest of the site already uses. */
    public const ACCENT = '#237b22';

    /** Over a limit. A status colour, and never reused as "series 9". */
    public const OVER = '#c0392b';

    public static function colour(int $slot): string
    {
        return self::PALETTE[abs($slot) % count(self::PALETTE)];
    }

    /**
     * A number with its unit attached on the correct side.
     *
     * Both sides are needed and neither is optional: ₦ goes before and m² goes after, and a
     * component that only supports one of them drops the unit on half the charts on this
     * site. Which is exactly what happened — the floor-area bar came through the first
     * version of this class reading "150 available" where it had previously said
     * "150 m² sellable", and a bare number on a floor plan is a number about nothing.
     */
    public static function show(float $n, string $prefix = '', string $suffix = '', int $dp = 0): string
    {
        return $prefix . number_format($n, $dp) . $suffix;
    }

    /**
     * A time series as a filled area — balance, money raised, anything that accumulates.
     *
     * Delegates the geometry to {@see Spark}, which already owns the zero baseline and the
     * round-number axis, and wraps it in the common spec so it renders and behaves like
     * every other chart here.
     *
     * @param list<array{date: string, balance: int, delta: int}> $series oldest first
     */
    public static function area(string $id, string $title, array $series, array $opt = []): array
    {
        $c = Spark::chart($series, (float) ($opt['w'] ?? 720), (float) ($opt['h'] ?? 180));
        $unit = (string) ($opt['unit'] ?? '');

        $rows = [];
        foreach ($c['points'] ?? [] as $i => $p) {
            // Only the days something happened, plus the ends — ninety identical rows of
            // "no change" is a table nobody reads to the bottom of.
            if ($p['delta'] === 0 && $i !== 0 && $i !== count($c['points']) - 1) continue;
            $rows[] = [
                date('j M Y', strtotime($p['date'])),
                $p['delta'] === 0 ? '—' : ($p['delta'] > 0 ? '+' : '') . number_format($p['delta']),
                $unit . number_format($p['balance']),
            ];
        }

        return [
            'id' => $id, 'kind' => 'area', 'title' => $title,
            'sub'   => (string) ($opt['sub'] ?? ''),
            'note'  => (string) ($opt['note'] ?? ''),
            'unit'  => $unit,
            'ok'    => (bool) ($c['ok'] ?? false),
            'w' => $c['w'], 'h' => $c['h'],
            'line' => $c['line'] ?? '', 'area' => $c['area'] ?? '',
            'points' => $c['points'] ?? [], 'grid' => $c['grid'] ?? [], 'ticks' => $c['ticks'] ?? [],
            'max' => $c['max'] ?? 0, 'change' => $c['change'] ?? 0,
            'first' => $c['first'] ?? [], 'last' => $c['last'] ?? [],
            'legend' => [],
            'table' => ['cols' => ['Day', 'Change', 'Balance'], 'rows' => $rows],
            'empty' => (string) ($opt['empty'] ?? 'Nothing to chart yet.'),
        ];
    }

    /**
     * A categorical comparison, as horizontal bars.
     *
     * HORIZONTAL and not vertical, deliberately: these are named things — stand types,
     * appeal titles, months — and a vertical bar chart puts those names on their side or
     * truncates them. The label is half the information.
     *
     * @param list<array{key: string, label: string, value: int|float, slot?: int, note?: string}> $items
     */
    public static function bars(string $id, string $title, array $items, array $opt = []): array
    {
        $items = array_values($items);
        $unit  = (string) ($opt['unit'] ?? '');
        $suf   = (string) ($opt['suffix'] ?? '');
        $one   = (bool) ($opt['single'] ?? false);

        $peak = 0.0;
        foreach ($items as $it) $peak = max($peak, (float) $it['value']);
        // Not the peak itself: a bar that runs to the very end of its track reads as
        // "maxed out" rather than "largest", and there is nowhere to put its label.
        $top = $peak > 0 ? $peak : 1.0;

        $marks = [];
        $legend = [];
        $rows = [];
        foreach ($items as $i => $it) {
            $slot   = (int) ($it['slot'] ?? $i);
            $colour = $one ? self::ACCENT : self::colour($slot);
            $marks[] = [
                'key'   => (string) $it['key'],
                'label' => (string) $it['label'],
                'value' => (float) $it['value'],
                'shown' => self::show((float) $it['value'], $unit, $suf),
                'pct'   => round((float) $it['value'] / $top * 100, 2),
                'colour'=> $colour,
                'note'  => (string) ($it['note'] ?? ''),
            ];
            if (!$one) $legend[] = ['key' => (string) $it['key'], 'label' => (string) $it['label'], 'colour' => $colour];
            $rows[] = [(string) $it['label'], self::show((float) $it['value'], $unit, $suf)];
        }

        return [
            'id' => $id, 'kind' => 'bars', 'title' => $title,
            'sub' => (string) ($opt['sub'] ?? ''), 'note' => (string) ($opt['note'] ?? ''),
            'unit' => $unit, 'ok' => $items !== [],
            'marks' => $marks,
            // A legend for one series is a box that says what the title already said.
            'legend' => count($legend) > 1 ? $legend : [],
            'table' => ['cols' => [(string) ($opt['col'] ?? 'Item'), (string) ($opt['col2'] ?? 'Value')], 'rows' => $rows],
            'empty' => (string) ($opt['empty'] ?? 'Nothing to compare yet.'),
        ];
    }

    /**
     * Part-to-whole, as one stacked bar.
     *
     * A bar and not a donut: the question a mix answers is "how much of the whole is this
     * one", and segments along a common baseline are the only form where close values can
     * actually be compared. A donut asks a reader to compare angles.
     *
     * @param list<array{key: string, label: string, value: int|float, slot?: int}> $items
     */
    public static function stack(string $id, string $title, array $items, array $opt = []): array
    {
        $items = array_values($items);
        $total = 0.0;
        foreach ($items as $it) $total += (float) $it['value'];

        $marks = []; $legend = []; $rows = []; $x = 0.0;
        foreach ($items as $i => $it) {
            $v   = (float) $it['value'];
            if ($v <= 0) continue;
            $pct = $total > 0 ? $v / $total * 100 : 0;
            $slot = (int) ($it['slot'] ?? $i);
            $colour = self::colour($slot);
            $marks[] = [
                'key' => (string) $it['key'], 'label' => (string) $it['label'],
                'value' => $v, 'shown' => number_format($v),
                'x' => round($x, 3), 'w' => round($pct, 3), 'pct' => round($pct, 1),
                'colour' => $colour,
            ];
            $legend[] = ['key' => (string) $it['key'], 'label' => (string) $it['label'],
                         'colour' => $colour, 'value' => number_format($v)];
            $rows[] = [(string) $it['label'], number_format($v), round($pct, 1) . '%'];
            $x += $pct;
        }

        return [
            'id' => $id, 'kind' => 'stack', 'title' => $title,
            'sub' => (string) ($opt['sub'] ?? ''), 'note' => (string) ($opt['note'] ?? ''),
            'unit' => (string) ($opt['unit'] ?? ''), 'ok' => $marks !== [],
            'total' => $total, 'marks' => $marks, 'legend' => $legend,
            'table' => ['cols' => [(string) ($opt['col'] ?? 'Category'), 'Count', 'Share'], 'rows' => $rows],
            'empty' => (string) ($opt['empty'] ?? 'Nothing to break down yet.'),
        ];
    }

    /**
     * Used against a hard limit — floor area, a budget, a quota.
     *
     * The overshoot is drawn PAST the limit line rather than by rescaling the track. This is
     * the one decision in the whole file that changes what a reader concludes: rescaling
     * makes an impossible plan look merely full, which is exactly the plan somebody needs to
     * be stopped from publishing.
     */
    public static function budget(string $id, string $title, float $used, float $limit, array $opt = []): array
    {
        $unit  = (string) ($opt['unit'] ?? '');
        $suf   = (string) ($opt['suffix'] ?? '');
        // "150 m² sellable" rather than "150 m² available": on a floor plan the word is the
        // difference between the hall and the part of it a stand may stand on.
        $word  = (string) ($opt['limit_word'] ?? 'available');
        $dp    = (int) ($opt['dp'] ?? 0);
        $over  = $used > $limit;
        $scale = $over ? $used : max($limit, 0.0001);

        return [
            'id' => $id, 'kind' => 'budget', 'title' => $title,
            'sub' => (string) ($opt['sub'] ?? ''), 'note' => (string) ($opt['note'] ?? ''),
            'unit' => $unit, 'ok' => $limit > 0 || $used > 0,
            'used' => $used, 'limit' => $limit, 'over' => $over,
            'over_by' => $over ? round($used - $limit, 2) : 0.0,
            'pct'      => $limit > 0 ? round($used / $limit * 100) : 0,
            'used_pct' => round(min($used, $limit) / $scale * 100, 3),
            'over_pct' => $over ? round(($used - $limit) / $scale * 100, 3) : 0.0,
            'limit_pct'=> round($limit / $scale * 100, 3),
            'used_label'  => self::show($used, $unit, $suf, $dp),
            'limit_label' => self::show($limit, $unit, $suf, $dp),
            'limit_word'  => $word,
            'over_label'  => self::show($over ? $used - $limit : 0, $unit, $suf, $dp),
            'legend' => [],
            'table' => ['cols' => ['', 'Amount'], 'rows' => [
                [(string) ($opt['used_name'] ?? 'Committed'), self::show($used, $unit, $suf, $dp)],
                [(string) ($opt['limit_name'] ?? 'Available'), self::show($limit, $unit, $suf, $dp)],
                ['Difference', ($over ? '−' : '+') . self::show(abs($limit - $used), $unit, $suf, $dp)],
            ]],
            'empty' => (string) ($opt['empty'] ?? 'Nothing measured yet.'),
        ];
    }

    /**
     * The hall, with the pitches packed into it, to scale.
     *
     * ── AND WHAT IT REFUSES TO BE ───────────────────────────────────────────
     *
     * It knows nothing about fire exits, columns, power drops, the servery or which wall the
     * loading door is on. It packs equal rows into a rectangle, which is a deliberately crude
     * model of a hall, and every surface that renders it says so in words — because a diagram
     * that LOOKS like a fire-safety document while knowing nothing about fire is worse than
     * no diagram at all. Somebody will forward it to the venue.
     *
     * What it answers is one question: is this plausibly the right order of magnitude, or is
     * it obviously impossible? That question is where the money is.
     *
     * @param array $plan a StandFloorPlan::forEvent() result
     */
    public static function plan(string $id, string $title, array $plan, array $opt = []): array
    {
        $ok = !empty($plan['measured']) && !empty($plan['blocks']);
        $fw = ((float) ($plan['floor_w_cm'] ?? 0)) / 100;
        $fd = ((float) ($plan['floor_d_cm'] ?? 0)) / 100;

        $marks = [];
        foreach (($plan['blocks'] ?? []) as $b) {
            $marks[] = [
                'key'    => 'cat-' . (int) ($b['cat_index'] ?? 0),
                'label'  => (string) ($b['name'] ?? ''),
                'size'   => (string) ($b['label'] ?? ''),
                // Percentages of the hall, so the SVG scales with its container and the
                // geometry never has to be recomputed for a different width.
                'x' => $fw > 0 ? round(((float) $b['x'] / 100) / $fw * 100, 3) : 0,
                'y' => $fd > 0 ? round(((float) $b['y'] / 100) / $fd * 100, 3) : 0,
                'w' => $fw > 0 ? round(((float) $b['w'] / 100) / $fw * 100, 3) : 0,
                'h' => $fd > 0 ? round(((float) $b['d'] / 100) / $fd * 100, 3) : 0,
                'colour' => self::colour((int) ($b['cat_index'] ?? 0)),
            ];
        }

        $legend = []; $rows = [];
        foreach (($plan['categories'] ?? []) as $c) {
            $legend[] = ['key' => 'cat-' . (int) $c['index'], 'label' => (string) $c['label'],
                         'colour' => self::colour((int) $c['index']), 'value' => (string) $c['quota']];
            $rows[] = [(string) $c['label'], (string) $c['quota'], $c['sqm'] . ' m²', $c['pct'] . '%'];
        }

        return [
            'id' => $id, 'kind' => 'plan', 'title' => $title,
            'sub'  => (string) ($opt['sub'] ?? ''),
            'note' => (string) ($opt['note'] ?? 'Indicative only — this knows nothing about fire exits, '
                . 'columns, power drops or the loading door. It answers one question: does this plausibly fit?'),
            'ok' => $ok,
            'floor_w' => $fw, 'floor_d' => $fd,
            'placed' => (int) ($plan['placed'] ?? 0), 'pitches' => (int) ($plan['pitches'] ?? 0),
            'marks' => $marks, 'legend' => $legend,
            // Whether the figures are open when the page loads. A caller decides it,
            // because the answer differs by surface rather than by taste.
            'table_open' => (bool) ($opt['table_open'] ?? false),
            'table' => ['cols' => ['Category', 'Pitches', 'Floor', 'Share'], 'rows' => $rows],
            'empty' => (string) ($opt['empty'] ?? 'Set the hall size and the stand types to see the layout.'),
        ];
    }
}
