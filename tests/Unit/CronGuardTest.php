<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Support\CronGuard;

/**
 * CronGuard gives each cron entrypoint a single-instance lock so a long run can't
 * collide with the next scheduled tick (double-sent reminders, double-drained
 * jobs, SQLite write contention).
 */
class CronGuardTest extends TestCase
{
    public function test_second_acquire_of_same_lock_is_refused(): void
    {
        $dir  = sys_get_temp_dir();
        $name = 'gatesguard_' . getmypid() . '_same';

        $this->assertTrue(CronGuard::acquire($name, $dir), 'first run acquires the lock');
        $this->assertFalse(CronGuard::acquire($name, $dir), 'an overlapping run is refused');
    }

    public function test_distinct_locks_do_not_interfere(): void
    {
        $dir = sys_get_temp_dir();

        $this->assertTrue(CronGuard::acquire('gatesguard_' . getmypid() . '_a', $dir));
        $this->assertTrue(CronGuard::acquire('gatesguard_' . getmypid() . '_b', $dir));
    }
}
