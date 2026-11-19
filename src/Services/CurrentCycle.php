<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/** Select an edition by its computed phase, so scheduler lag cannot hide an open window. */
final class CurrentCycle
{
    public static function forProgramme(int $programmeId, ?Carbon $now = null): ?object
    {
        $now ??= Carbon::now();
        $cycles = DB::table('gates_award_cycles')->where('programme_id', $programmeId)
            ->orderByDesc('year')->orderByDesc('id')->get();

        foreach ($cycles as $cycle) {
            $phase = CyclePolicy::phaseFor($cycle, $now);
            if ($phase !== CyclePhase::Upcoming && $phase !== CyclePhase::Archived) {
                return $cycle;
            }
        }

        return $cycles->first();
    }
}
