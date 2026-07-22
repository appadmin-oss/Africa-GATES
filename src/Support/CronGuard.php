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
}
