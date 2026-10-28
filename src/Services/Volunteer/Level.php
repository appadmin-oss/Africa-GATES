<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A volunteer's level, COMPUTED from the verified record on every read (plan §7.3: "levels are
 * computed from evidence, never set by hand").
 *
 * Four numbers decide it, and each is shown to the person exactly as it is computed here:
 *   · verified hours (only `verified` rows: an hour still in review counts for nothing yet);
 *   · shifts kept;
 *   · reliability: kept ÷ (kept + missed + late drops), where a late drop is one inside
 *     `vol_late_drop_hours` of the start — an early drop is fine and costs nothing;
 *   · for Level 3, the team lead module passed and in date.
 * The thresholds are settings (Rules), so "Level 3 at 50" on a screen is a read, not a typed 50.
 */
final class Level
{
    /**
     * @return array{level:int, hours:float, kept:int, missed:int, reliability:?int,
     *               next:?array{level:int, hours_to:float, shifts_to:int, needs:list<string>, pct:int}}
     */
    public static function of(object $v): array
    {
        $hours = (float) DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'verified')->sum('hours');
        $kept = (int) DB::table('gates_vol_signups')->where('volunteer_id', $v->id)->where('status', 'kept')->count();
        $missed = (int) DB::table('gates_vol_signups')->where('volunteer_id', $v->id)->where('status', 'missed')->count();
        $late = self::lateDrops((int) $v->id);
        $den = $kept + $missed + $late;
        $rel = $den > 0 ? (int) round($kept * 100 / $den) : null;

        $l2h = Rules::get('vol_level2_hours');
        $l2s = Rules::get('vol_level2_shifts');
        $l3h = Rules::get('vol_level3_hours');
        $l3r = Rules::get('vol_level3_reliability');
        $leadOk = Training::holds((int) $v->id, Rules::LEAD_MODULE);

        $level = 1;
        if ($hours >= $l2h && $kept >= $l2s) $level = 2;
        if ($level === 2 && $hours >= $l3h && ($rel ?? 0) >= $l3r && $leadOk) $level = 3;

        $next = null;
        if ($level === 1) {
            $needs = [];
            if ($hours < $l2h) $needs[] = self::h($l2h - $hours) . ' verified';
            if ($kept < $l2s) $needs[] = ($l2s - $kept) . ' more ' . ($l2s - $kept === 1 ? 'shift' : 'shifts') . ' kept';
            $next = ['level' => 2, 'hours_to' => max(0.0, $l2h - $hours), 'shifts_to' => max(0, $l2s - $kept), 'needs' => $needs,
                     'pct' => $l2h > 0 ? (int) min(100, round($hours * 100 / $l2h)) : 100];
        } elseif ($level === 2) {
            $needs = [];
            if ($hours < $l3h) $needs[] = self::h($l3h - $hours);
            if (!$leadOk) $needs[] = 'Team lead training';
            if (($rel ?? 0) < $l3r) $needs[] = 'shifts kept at ' . $l3r . '%';
            $next = ['level' => 3, 'hours_to' => max(0.0, $l3h - $hours), 'shifts_to' => 0, 'needs' => $needs,
                     'pct' => $l3h > 0 ? (int) min(100, round($hours * 100 / $l3h)) : 100];
        }
        return ['level' => $level, 'hours' => round($hours, 2), 'kept' => $kept, 'missed' => $missed + $late,
                'reliability' => $rel, 'next' => $next];
    }

    /** Hours as people say them: "3.5h", "12h". */
    public static function h(float $n): string
    {
        $n = round($n, 1);
        return (floor($n) == $n ? (string) (int) $n : number_format($n, 1)) . 'h';
    }

    private static function lateDrops(int $volId): int
    {
        $win = Rules::get('vol_late_drop_hours') * 3600;
        $n = 0;
        foreach (DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
                     ->where('s.volunteer_id', $volId)->where('s.status', 'dropped')->whereNotNull('s.dropped_at')
                     ->get(['s.dropped_at', 'h.starts_at']) as $r) {
            $gap = (int) strtotime((string) $r->starts_at) - (int) strtotime((string) $r->dropped_at);
            if ($gap < $win) $n++;
        }
        return $n;
    }
}
