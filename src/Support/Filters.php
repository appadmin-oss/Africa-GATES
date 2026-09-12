<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Support\Carbon;

/**
 * Shared list-view helpers: a date-range filter (day/week/month presets +
 * custom from/to) and pagination math, so every admin list filters and paginates
 * the same way instead of re-implementing it per controller.
 */
final class Filters
{
    /** Preset key => human label, in display order. '' / 'all' means no bound. */
    public const RANGES = [
        'all'       => 'All time',
        'today'     => 'Today',
        'yesterday' => 'Yesterday',
        '7d'        => 'Last 7 days',
        'week'      => 'This week',
        '30d'       => 'Last 30 days',
        'month'     => 'This month',
        'year'      => 'This year',
        'custom'    => 'Custom range',
    ];

    /** Columns treated as the natural "time" column of a table, best first. */
    private const DATE_CANDIDATES = [
        'created_at', 'voted_at', 'ran_at', 'achieved_at', 'computed_at',
        'subscribed_at', 'last_seen', 'submitted_at', 'updated_at',
    ];

    /**
     * Resolve which column a date filter should apply to. Prefers $prefer (the
     * list's natural sort/time column) when present, then a known timestamp
     * name, then any *_at / *_date column, else null (no date filter possible).
     *
     * @param string[] $existing real column names on the table
     */
    public static function dateColumn(array $existing, ?string $prefer = null): ?string
    {
        if ($prefer !== null && $prefer !== '' && in_array($prefer, $existing, true)) {
            return $prefer;
        }
        foreach (self::DATE_CANDIDATES as $c) {
            if (in_array($c, $existing, true)) return $c;
        }
        foreach ($existing as $c) {
            if (str_ends_with($c, '_at') || str_ends_with($c, '_date')) return $c;
        }
        return null;
    }

    /**
     * Apply a date-range filter to a query builder from request params
     * (`range` preset and/or `from`/`to` = YYYY-MM-DD). Custom from/to overrides
     * the preset. Bounds are [start, nextDayAfterEnd) so `to` is inclusive.
     *
     * @param object $query    Illuminate query builder (mutated in place)
     * @param string[] $qp     request query params
     * @return array{range:string, from:string, to:string, active:bool, col:?string}
     */
    public static function applyDateRange(object $query, ?string $col, array $qp): array
    {
        $from  = trim((string) ($qp['from'] ?? ''));
        $to    = trim((string) ($qp['to'] ?? ''));
        $range = (string) ($qp['range'] ?? '');
        $isDate = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        $out = ['range' => ($range !== '' ? $range : 'all'), 'from' => $from, 'to' => $to, 'active' => false, 'col' => $col];
        if ($col === null) return $out;

        // Explicit from/to always means a custom range.
        if (($from !== '' && $isDate($from)) || ($to !== '' && $isDate($to))) {
            $range = 'custom';
        }

        $now = Carbon::now();
        $start = null; $end = null;
        switch ($range) {
            case 'custom':
                if ($from !== '' && $isDate($from)) $start = $from . ' 00:00:00';
                if ($to !== '' && $isDate($to))     $end   = Carbon::parse($to)->addDay()->format('Y-m-d') . ' 00:00:00';
                break;
            case 'today':     $start = $now->copy()->startOfDay()->toDateTimeString(); break;
            case 'yesterday': $start = $now->copy()->subDay()->startOfDay()->toDateTimeString();
                              $end   = $now->copy()->startOfDay()->toDateTimeString(); break;
            case '7d':        $start = $now->copy()->subDays(7)->toDateTimeString(); break;
            case 'week':      $start = $now->copy()->startOfWeek()->toDateTimeString(); break;
            case '30d':       $start = $now->copy()->subDays(30)->toDateTimeString(); break;
            case 'month':     $start = $now->copy()->startOfMonth()->toDateTimeString(); break;
            case 'year':      $start = $now->copy()->startOfYear()->toDateTimeString(); break;
            default:          $range = 'all';
        }
        if ($start !== null) $query->where($col, '>=', $start);
        if ($end !== null)   $query->where($col, '<', $end);

        $out['range']  = $range;
        $out['active'] = ($start !== null || $end !== null);
        return $out;
    }

    /** 1-based page clamped to [1, max(1,pages)]. */
    public static function clampPage(int $page, int $pages): int
    {
        return max(1, min($page, max(1, $pages)));
    }

    /**
     * Page numbers to render around the current page, with 0 marking an ellipsis
     * gap. Always includes page 1 and the last page so any page is reachable.
     *
     * @return int[]
     */
    public static function pageWindow(int $page, int $pages, int $around = 2): array
    {
        if ($pages <= 1) return [1];
        $set = [1, $pages];
        for ($i = $page - $around; $i <= $page + $around; $i++) {
            if ($i >= 1 && $i <= $pages) $set[] = $i;
        }
        $set = array_values(array_unique($set));
        sort($set);
        $out = []; $prev = 0;
        foreach ($set as $n) {
            if ($prev !== 0 && $n - $prev > 1) $out[] = 0; // ellipsis
            $out[] = $n; $prev = $n;
        }
        return $out;
    }

    /**
     * Build a query string of the given params minus empties and `page`, for
     * preserving filters across pager/sort links. Values are URL-encoded.
     */
    public static function qs(array $params): string
    {
        $keep = [];
        foreach ($params as $k => $v) {
            if ($k === 'page') continue;
            if ($v === null || $v === '' || $v === 'all') continue;
            $keep[$k] = $v;
        }
        return http_build_query($keep);
    }
}
