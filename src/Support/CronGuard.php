<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Best-effort single-instance guard for cron entrypoints: stops a long run from
 * colliding with the next scheduled tick (double-sent reminders, double-drained
 * jobs, SQLite write contention). The OS releases the lock automatically when the
 * process exits, so a crashed run never wedges future ones.
 */
final class CronGuard
{
    /** @var array<string, resource> open handles held for the process lifetime to keep their locks. */
    private static array $handles = [];

    /**
     * Acquire an exclusive, non-blocking lock named $name. Returns true if this
     * process now holds it, false if another run already does. Fails OPEN (returns
     * true) if a lock file can't be created — better to run than to silently skip.
     */
    public static function acquire(string $name, ?string $dir = null): bool
    {
        $dir  = $dir ?: sys_get_temp_dir();
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        $file = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . '.gates-' . $safe . '.lock';

        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return true; // can't lock — don't block the cron
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false; // another run holds it
        }
        // Hold the handle (and thus the lock) for the rest of the process.
        self::$handles[$file] = $handle;
        return true;
    }

    /**
     * Release every lock this process holds.
     *
     * Not needed in production — under mod_php/PHP-FPM statics do not survive a request,
     * and a CLI cron run ends when its work does, so the OS reclaims the lock either way.
     *
     * It exists for the ONE context where "the rest of the process" is the wrong lifetime:
     * a test process that dispatches `/__cron/run` more than once. Without it the first
     * dispatch keeps the lock and every later one short-circuits to `{"skipped":"another
     * run in progress"}` — which is a 200 with `ok:true`, so a test asserting on a failed
     * task silently measured a run that never happened. That cost a debugging session,
     * and the misreading is quiet enough to be worth removing outright.
     */
    public static function releaseAll(): void
    {
        foreach (self::$handles as $handle) {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
        self::$handles = [];
    }
}
