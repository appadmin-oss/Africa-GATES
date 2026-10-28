<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The volunteer rules an operator can change, and the ONE reader of each (CLAUDE.md: one
 * resolver per setting). Every figure a volunteer screen prints about a threshold ("Level 3 at
 * 50", "your limit is 12", "minimum age 16") comes out of here, never typed into a template.
 *
 * The defaults are the review's recommendations (CONSOLES-V7-REVIEW.md D36, D12), pending the
 * owner's answer; each is a setting precisely so that answer needs no code.
 */
final class Rules
{
    public const DEFAULTS = [
        'vol_level2_hours'       => 10,   // verified hours to reach Level 2
        'vol_level2_shifts'      => 3,    // shifts kept to reach Level 2
        'vol_level3_hours'       => 50,   // verified hours to reach Level 3
        'vol_level3_reliability' => 90,   // per cent of shifts kept, for Level 3
        'vol_min_age'            => 16,   // youngest applicant accepted
        'vol_guardian_under'     => 18,   // a guardian's consent below this age
        'vol_late_drop_hours'    => 48,   // a drop inside this counts against reliability
        'vol_week_hours_default' => 12,   // the weekly guide before a person sets their own
    ];

    /** The module a Level 3 needs, besides the hours (the lead's training). */
    public const LEAD_MODULE = 'team-lead';

    /** @var array<string,int>|null */
    private static ?array $memo = null;

    public static function get(string $key): int
    {
        if (self::$memo === null) {
            self::$memo = [];
            try {
                foreach (DB::table('gates_settings')->whereIn('key_name', array_keys(self::DEFAULTS))->get(['key_name', 'value']) as $r) {
                    $v = trim((string) $r->value);
                    if ($v !== '' && is_numeric($v)) self::$memo[(string) $r->key_name] = max(0, (int) $v);
                }
            } catch (\Throwable) {
                // No settings table is the defaults, not an error a volunteer should see.
            }
        }
        return self::$memo[$key] ?? (int) (self::DEFAULTS[$key] ?? 0);
    }

    /** Tests and the settings writer reset it; the memo is per process. */
    public static function reset(): void
    {
        self::$memo = null;
    }
}
