<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Whether an edition's result publishes itself when its results date passes, or waits
 * for a person to press Release.
 *
 * ── MANUAL IS THE DEFAULT ────────────────────────────────────────────────────
 *
 * A results date is a promise about WHEN; it is not evidence that the panel has finished,
 * that the votes have reconciled, or that anyone has looked at the standing it would
 * crown. Left automatic, the sweep published whatever the arithmetic said at that minute.
 * So by default the date passing does nothing but show the public a "result is late"
 * notice ({@see \AfricaGates\Services\PublicResults::delayed()}), which is derived and
 * therefore cannot be forgotten, until an operator releases from Admin → Result release.
 *
 * `results_release_mode = auto` in gates_settings (Admin → Settings) restores the old
 * behaviour: the computed phase reaches Results at the results date and the sweep
 * promotes and seals.
 *
 * Read once per process and cached, like {@see DisplayTime::zone()}. Any failure to read
 * resolves to MANUAL: the safe failure is a result that waits, not one that publishes.
 */
final class ResultReleaseMode
{
    public const SETTING = 'results_release_mode';
    public const MANUAL  = 'manual';
    public const AUTO    = 'auto';

    private static ?string $mode = null;

    public static function mode(): string
    {
        if (self::$mode !== null) return self::$mode;

        $v = '';
        try {
            $v = strtolower(trim((string) DB::table('gates_settings')
                ->where('key_name', self::SETTING)->value('value')));
        } catch (\Throwable) {
            // No table, no database: manual.
        }

        return self::$mode = $v === self::AUTO ? self::AUTO : self::MANUAL;
    }

    public static function isAuto(): bool
    {
        return self::mode() === self::AUTO;
    }

    /** Persist a choice (the settings screen) and drop the cache. */
    public static function save(string $mode): void
    {
        $mode = $mode === self::AUTO ? self::AUTO : self::MANUAL;
        $updated = DB::table('gates_settings')->where('key_name', self::SETTING)->update(['value' => $mode]);
        if ($updated === 0
            && !DB::table('gates_settings')->where('key_name', self::SETTING)->exists()) {
            DB::table('gates_settings')->insert(['key_name' => self::SETTING, 'value' => $mode]);
        }
        self::$mode = null;
    }

    /** Forget the cached mode. For tests, and for the settings screen after a save. */
    public static function forget(): void
    {
        self::$mode = null;
    }
}
