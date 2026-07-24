<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Maintenance;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The shared maintenance orchestrator behind both the CLI hub and the webcron
 * endpoint: named tasks run, log to gates_cron_log, and have their real effect.
 * (Clock-based 'auto' selection isn't pinned here — it depends on the wall clock.)
 */
class MaintenanceTest extends TestCase
{
    public function test_cache_task_prunes_expired_rows_and_logs(): void
    {
        DB::table('gates_cache')->insert([
            ['cache_key' => 'stale', 'payload' => '{}', 'expires_at' => '2000-01-01 00:00:00'],
            ['cache_key' => 'fresh', 'payload' => '{}', 'expires_at' => '2999-01-01 00:00:00'],
        ]);

        $r = (new Maintenance(null, false))->run('cache');

        $this->assertNull(DB::table('gates_cache')->where('cache_key', 'stale')->first(), 'expired row pruned');
        $this->assertNotNull(DB::table('gates_cache')->where('cache_key', 'fresh')->first(), 'live row kept');
        $this->assertSame('cache', $r['task']);
        $this->assertContains('cache', array_column($r['ran'], 0));
        // Every run records to the cron log.
        $this->assertSame(1, (int) DB::table('gates_cron_log')->where('job_name', 'maintenance')->count());
    }

    public function test_digest_task_writes_a_daily_activity_row(): void
    {
        (new Maintenance(null, false))->run('digest');
        $row = DB::table('gates_activity')->where('target_label', 'Daily digest')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->is_public, 'digest is admin-only, not public');
    }

    public function test_unknown_task_is_handled_gracefully(): void
    {
        $r = (new Maintenance(null, false))->run('does-not-exist');
        $this->assertSame('does-not-exist', $r['task']);
        $this->assertSame([], $r['ran']);
        $this->assertNotEmpty($r['lines']);   // it logged "Unknown task" + "Done."
    }

    public function test_tick_is_gated_by_the_setting_then_runs(): void
    {
        $sentinel = dirname(__DIR__, 2) . '/var/data/.maintenance_tick';
        @unlink($sentinel);

        // Disabled (no setting) → skips BEFORE any lock/work.
        DB::table('gates_settings')->where('key_name', 'webcron_auto')->delete();
        $this->assertSame('disabled', (Maintenance::tick(null))['skipped'] ?? null);
        $this->assertFalse(Maintenance::autoEnabled());

        // Enabled + nothing run recently → it actually runs and logs.
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'webcron_auto'], ['value' => '1']);
        @unlink($sentinel);
        $this->assertTrue(Maintenance::autoEnabled());
        $r = Maintenance::tick(null);
        $this->assertArrayNotHasKey('skipped', $r, 'should run when enabled and due');
        $this->assertSame('auto', $r['task'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) DB::table('gates_cron_log')->where('job_name', 'maintenance')->count());
    }
}
