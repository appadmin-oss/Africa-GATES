<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MigrationRunner;
use Tests\TestCase;

/**
 * The runner may RAISE the memory limit for a migration batch, and must never lower it.
 * `ini_set('memory_limit', '512M')` capped an unlimited process at 512M — including this
 * test suite, which then died of it at 98% with a fatal naming an unrelated test.
 */
final class MigrationRunnerMemoryTest extends TestCase
{
    private string $was = '';

    protected function setUp(): void { parent::setUp(); $this->was = (string) ini_get('memory_limit'); }
    protected function tearDown(): void { ini_set('memory_limit', $this->was); parent::tearDown(); }

    public function test_it_raises_a_low_limit_and_never_lowers_a_high_or_unlimited_one(): void
    {
        ini_set('memory_limit', '-1');
        MigrationRunner::raiseMemoryTo(512 * 1024 * 1024);
        $this->assertSame('-1', ini_get('memory_limit'), 'an unlimited process was capped');

        ini_set('memory_limit', '2G');
        MigrationRunner::raiseMemoryTo(512 * 1024 * 1024);
        $this->assertSame('2G', ini_get('memory_limit'), 'a larger limit was lowered');

        // PHP refuses a limit below what the process already uses, and late in the suite
        // that is several hundred MB — so "low" and the target are relative to now.
        $low  = memory_get_usage(true) + 32 * 1024 * 1024;
        $want = $low + 64 * 1024 * 1024;
        $this->assertNotFalse(ini_set('memory_limit', (string) $low));
        MigrationRunner::raiseMemoryTo($want);
        $this->assertSame($want, MigrationRunner::bytes((string) ini_get('memory_limit')));
    }

    public function test_run_leaves_an_unlimited_process_unlimited(): void
    {
        ini_set('memory_limit', '-1');
        MigrationRunner::run(0);
        $this->assertSame('-1', ini_get('memory_limit'));
    }
}
