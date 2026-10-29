<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A volunteer's level, COMPUTED from the verified record on every read (plan §7.3: "levels are
 * computed from evidence, never set by hand").
 *
 * Two things decide it, and each is shown to the person exactly as it is computed here:
 *   · verified hours (only `verified` rows: an hour still in review counts for nothing yet);
 *   · for Level 3, the team lead module passed and in date.
 * Shifts and the reliability counted from them left with shifts (owner, 29 Oct 2026). The
 * thresholds are settings (Rules), so "Level 3 at 50" on a screen is a read, not a typed 50.
 */
final class Level
{
    /** @return array{level:int, hours:float, next:?array{level:int, hours_to:float, needs:list<string>, pct:int}} */
    public static function of(object $v): array
    {
        $hours = (float) DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'verified')->sum('hours');
        $l2h = Rules::get('vol_level2_hours');
        $l3h = Rules::get('vol_level3_hours');
        $leadOk = Training::holds((int) $v->id, Rules::LEAD_MODULE);

        $level = 1;
        if ($hours >= $l2h) $level = 2;
        if ($level === 2 && $hours >= $l3h && $leadOk) $level = 3;

        $next = null;
        if ($level === 1) {
            $next = ['level' => 2, 'hours_to' => max(0.0, $l2h - $hours), 'needs' => [self::h($l2h - $hours) . ' verified'],
                     'pct' => $l2h > 0 ? (int) min(100, round($hours * 100 / $l2h)) : 100];
        } elseif ($level === 2) {
            $needs = [];
            if ($hours < $l3h) $needs[] = self::h($l3h - $hours);
            if (!$leadOk) $needs[] = 'Team lead training';
            $next = ['level' => 3, 'hours_to' => max(0.0, $l3h - $hours), 'needs' => $needs,
                     'pct' => $l3h > 0 ? (int) min(100, round($hours * 100 / $l3h)) : 100];
        }
        return ['level' => $level, 'hours' => round($hours, 2), 'next' => $next];
    }

    /** Hours as people say them: "3.5h", "12h". */
    public static function h(float $n): string
    {
        $n = round($n, 1);
        return (floor($n) == $n ? (string) (int) $n : number_format($n, 1)) . 'h';
    }
}
